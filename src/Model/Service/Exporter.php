<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\DoctrineComponents\EntityManager;
use ADT\DoctrineComponents\QueryObject\QueryObjectInterface;
use ADT\Exporter\Model\Entities\Export;


use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\QueryBuilder;
use Nette\Application\LinkGenerator;
use Nette\Mail\Mailer;
use Nette\Utils\Arrays;

/**
 * Jednotne hrdlo vsech exportu dat. V JEDNE transakci vznika:
 *   - Export: PROVOZNI zaznam - ridi background regeneraci, soubor
 *     (pres ExportFileStorage - typicky aplikacni File ekosystem),
 *     doruceni a download
 *   - pripadny background job (nad syncRowLimit nebo forceBackground)
 *   - a zavolaji se callbacky $onExport
 *
 * Knihovna sama nic nedokumentuje ani nelogovuje: jen ohlasi udalostmi
 * $onExport a $onDownload, ze se neco stalo, a co s tim - audit, notifikace,
 * statistika - rozhoduje projekt tim, co do nich zaregistruje.
 *
 * Registrace queue callbacku:
 *   backgroundQueue: callbacks: processExport: [@exporter.exporter, processExport]
 */
final class Exporter
{
	public const string QUEUE_CALLBACK = 'processExport';

	/**
	 * Klic parametru jobu. Musi se doslova shodovat s nazvem parametru processExport(),
	 * protoze fronta ho rozbaluje jako pojmenovany argument - hlida to test queueCallback.
	 */
	public const string PARAM_EXPORT_ID = 'exportId';

	/**
	 * Export byl zadan - data jsou vybrana a soubor vznikl. Vola se ve STEJNE
	 * transakci jako provozni zaznam, takze co v callbacku zapises, vznikne
	 * atomicky s exportem (a naopak: vyjimka export zrusi).
	 *
	 * $sections nese popis toho, co presne odeslo a podle ceho - vycet ID,
	 * dql s parametry a vycet exportovanych poli. V provoznim zaznamu
	 * ($export->getSections()) tyhle udaje nejsou, ten nese jen to, cim se
	 * soubor znovu vyrobi.
	 *
	 * @var array<callable(Export $export, ExportRequest $request, array $sections, int $rowCount): void>
	 */
	public array $onExport = [];

	/**
	 * Soubor byl vydan ke stazeni - okamzik, kdy data opustila system.
	 * Vola se opakovane, kdykoli si nekdo soubor stahne.
	 *
	 * @var array<callable(Export $export): void>
	 */
	public array $onDownload = [];

	/** @var array<class-string, ExportFileGenerator> */
	private array $generators = [];

	public function __construct(
		private readonly EntityManager $em,
		private readonly BackgroundQueue $backgroundQueue,
		private readonly Mailer $mailer,
		private readonly ExportMailFactory $mailFactory,
		private readonly ExportFileStorage $fileStorage,
		private readonly LinkGenerator $linkGenerator,
		private readonly int $syncRowLimit,
		private readonly string $downloadLink,
		private readonly ?string $memoryLimit = null,
	) {}

	public function export(ExportRequest $request): Export
	{
		// materializovana sekce se rozpada na dve nepretinajici se poloviny:
		// provozni potrebuje k regeneraci sloupce vcetne rendrovaci vystroje,
		// $onExport naopak dql + parametry (ty provoz necte, regeneruje se z ids)
		$sections = [];
		$eventSections = [];
		foreach ($request->sections as $section) {
			$s = $this->materializeSection($section);
			$sections[] = self::operationalSection($s);
			$eventSections[] = self::eventSection($s);
		}
		$rowCount = self::countRows($sections);

		/** @var Export $export */
		$export = new ($this->em->findEntityClassByInterface(Export::class));
		$export->setCreatedAt(new DateTimeImmutable())
			->setIdentifier($request->identifier)
			->setSections($sections)
			->setGenerator(get_class($request->generator))
			->setEmail($request->email)
			->setInBackground($request->forceBackground || $rowCount > $this->syncRowLimit);

		// Provozni zaznam, $onExport a pripadny job v JEDNE transakci: co si
		// projekt v callbacku zapise, vznikne atomicky s exportem - a naopak,
		// vyjimka z callbacku export zrusi.
		$this->em->wrapInTransaction(function () use ($export, $request, $eventSections, $rowCount) {
			$this->em->persist($export);
			$this->em->flush();
			Arrays::invoke($this->onExport, $export, $request, $eventSections, $rowCount);
			if ($export->isInBackground()) {
				$this->backgroundQueue->publish(self::QUEUE_CALLBACK, [self::PARAM_EXPORT_ID => $export->getId()]);
			}
		});

		if (!$export->isInBackground()) {
			$this->generateFile($export, $request->generator);
			$this->markProcessed($export);
		}

		return $export;
	}

	/**
	 * Ohlasi, ze soubor byl vydan ke stazeni - vola presenter TESNE PRED
	 * vydanim souboru, az po overeni opravneni.
	 *
	 * Data opousteji system az tady, ne pri zadani exportu: odkaz chodi
	 * e-mailem, plati po celou retenci souboru a pouzit ho muze i nekdo jiny
	 * nez zadavatel - a stahnout se da opakovane.
	 *
	 * Zamerne NEODCHYTAVA vyjimky: kdyz callback selze, soubor se nevyda.
	 * Jestli je to zadouci, rozhoduje projekt tim, co do callbacku da.
	 */
	public function fileDownloaded(Export $export): void
	{
		Arrays::invoke($this->onDownload, $export);
	}

	/** lokalni cesta souboru pro sync download (FileResponse) */
	public function getFilePath(Export $export): ?string
	{
		return $this->fileStorage->getLocalPath($export);
	}

	/**
	 * Queue callback - regenerace ze sekci + e-mail s odkazem.
	 *
	 * Parametr se jmenuje stejne jako klic, pod kterym se job publikuje: fronta na PHP 8
	 * rozbaluje parametry pojmenovane (`$callback(...$job->getParameters())`), takze cokoli
	 * jineho skonci na "Unknown named parameter".
	 */
	public function processExport(int $exportId): void
	{
		/** @var Export $export */
		$export = $this->em->getRepository($this->em->findEntityClassByInterface(Export::class))
			->find($exportId);
		if (!$export || $export->getProcessedAt() !== null) {
			return; // idempotence pri retry
		}

		$this->generateFile($export, $this->resolveGenerator($export->getGenerator()));

		if ($export->getEmail()) {
			// obsah e-mailu vlastni projekt (ExportMailFactory); odkaz vede na
			// aplikacni routu - soubor se vydava az po overeni prihlaseni/vlastnictvi
			$this->mailer->send($this->mailFactory->create(
				$export,
				$this->linkGenerator->link($this->downloadLink, ['id' => $export->getId()]),
			));
		}

		// AZ TED je export hotovy. Kdyby se `processedAt` zapsalo uz pri generovani souboru,
		// selhani odeslani by se pri retry umlcelo tou strazi nahore: soubor by existoval,
		// job by se tvaril jako dokonceny a odkaz by uzivateli nikdy nedosel. Cena za to je,
		// ze se soubor pri nedorucenem e-mailu vyrobi znovu - attach() prepisuje, takze se
		// nic nehromadi.
		$this->markProcessed($export);
	}

	/**
	 * Smaze SOUBORY exportu starsich nez $retentionDays (exportovana data
	 * nesmi lezet na disku dele, nez je nutne k doruceni). Provozni zaznam
	 * dostane filesPurgedAt, jinak se nemeni nic.
	 */
	public function purgeFiles(int $retentionDays): int
	{
		$threshold = new DateTimeImmutable("-{$retentionDays} days");
		$purged = 0;
		$exports = $this->em->getRepository($this->em->findEntityClassByInterface(Export::class))
			->createQueryBuilder('e')
			->where('e.processedAt < :t')->andWhere('e.filesPurgedAt IS NULL')
			->setParameter('t', $threshold)
			->getQuery()->getResult();

		// Flush po KAZDEM zaznamu, ne az na konci: soubor mizi hned, takze pad uprostred
		// smycky by jinak nechal vsechny dosud smazane exporty tvrdit, ze soubor maji,
		// a stazeni by u nich skoncilo zahadnou chybou. Takhle je rozjety nejvys jeden.
		foreach ($exports as $export) {
			$this->fileStorage->purge($export);
			$export->setFilesPurgedAt(new DateTimeImmutable());
			$this->em->flush();
			$purged++;
		}

		return $purged;
	}


	/** pocet radku napric sekcemi (entitni nesou ids, agregatove rovnou rows) */
	private static function countRows(array $sections): int
	{
		$count = 0;
		foreach ($sections as $section) {
			$count += $section['ids'] !== null ? count($section['ids']) : count($section['rows']);
		}

		return $count;
	}

	/** @internal registrace generatoru z DI (viz ExporterExtension) */
	public function addGenerator(ExportFileGenerator $generator): void
	{
		$this->generators[get_class($generator)] = $generator;
	}

	/**
	 * Export je hotovy az vcetne doruceni - viz processExport().
	 */
	private function markProcessed(Export $export): void
	{
		$export->setProcessedAt(new DateTimeImmutable());
		$this->em->flush();
	}

	private function generateFile(Export $export, ExportFileGenerator $generator): void
	{
		// Limit rozhoduje projekt, ne knihovna: generovani bezi i synchronne v HTTP requestu
		// a zvednuti na nekolik GB tam znamena, ze jeden velky export sundá stroj misto toho,
		// aby cisté selhal. Zustava v platnosti do konce procesu, u workeru tedy i pro dalsi joby.
		if ($this->memoryLimit !== null) {
			ini_set('memory_limit', $this->memoryLimit);
		}

		$sections = [];
		foreach ($export->getSections() as $section) {
			if ($section['rows'] !== null) {
				$sections[$section['name']] = ['items' => $section['rows'], 'columns' => $section['columns']];
				continue;
			}
			// nacteni po davkach dle ulozenych ids - poradi dle ids zachovano
			$items = [];
			foreach (array_chunk($section['ids'], 5000) as $chunk) {
				foreach ($this->em->getRepository($section['entityClass'])
					->createQueryBuilder('e')->where('e.id IN (:ids)')->setParameter('ids', $chunk)
					->getQuery()->getResult() as $item) {
					$items[(string) $item->getId()] = $item;
				}
			}
			$ordered = [];
			foreach ($section['ids'] as $id) {
				if (isset($items[(string) $id])) {
					$ordered[] = $items[(string) $id];
				}
			}
			$sections[$section['name']] = ['items' => $ordered, 'columns' => $section['columns']];
		}

		$tmpPath = $generator->generate($sections, $export->getIdentifier());

		$export->setFileName(basename($tmpPath));
		$this->fileStorage->attach($export, $tmpPath);
		$this->em->flush();
	}

	private function resolveGenerator(string $class): ExportFileGenerator
	{
		return $this->generators[$class]
			?? throw new \RuntimeException("Export generator '$class' neni registrovany jako sluzba.");
	}

	/**
	 * PROVOZNI projekce sekce - presne to, co potrebuje generateFile():
	 * nacist radky (ids/rows + entityClass) a zrekonstruovat sloupce vcetne
	 * jejich trid a popisku. Dql ani parametry sem nepatri, regeneruje se
	 * z ulozenych ids - jinak by soubor neodpovidal dorucenemu originalu.
	 */
	private static function operationalSection(array $section): array
	{
		return [
			'name' => $section['name'],
			'entityClass' => $section['entityClass'],
			'ids' => $section['ids'],
			'rows' => $section['rows'],
			'columns' => $section['columns'],
		];
	}

	/**
	 * Projekce sekce pro $onExport - "co presne odeslo a podle ceho":
	 * dql + parametry skutecne spustene query, vycet ID (u agregatovych
	 * sekci primo radky) a VYCET EXPORTOVANYCH POLI. Ze sloupcu se bere
	 * jen cesta k datum; nazev tridy a preklad popisku jsou rendrovaci
	 * vystroj, ktera o odeslanych datech nevypovida nic.
	 */
	private static function eventSection(array $section): array
	{
		$fields = [];
		foreach ($section['columns'] as $key => $def) {
			$fields[] = is_array($def) ? ($def['column'] ?? $key) : $key;
		}

		return [
			'name' => $section['name'],
			'entityClass' => $section['entityClass'],
			'fields' => $fields,
			'ids' => $section['ids'],
			'rows' => $section['rows'],
			'dql' => $section['dql'],
			'parameters' => $section['parameters'],
		];
	}

	private function materializeSection(ExportSection $section): array
	{
		$out = ['name' => $section->name, 'columns' => $section->columns,
			'entityClass' => null, 'ids' => null, 'rows' => null, 'dql' => null, 'parameters' => null];

		$source = $section->source;

		if ($section->isRawRows()) {
			$out['rows'] = array_values($source);
			return $out;
		}

		if (is_array($source)) {
			$out['entityClass'] = $section->entityClass
				?? throw new \InvalidArgumentException("Sekce '{$section->name}': pro pole ID je nutne predat entityClass.");
			$out['ids'] = array_values($source);
			return $out;
		}

		if ($source instanceof QueryObjectInterface) {
			$query = $source->getQuery();
			$out['entityClass'] = $source->getEntityClass();
			$out['ids'] = array_values($source->fetchField('id'));
			$out['dql'] = $query->getDQL();
			$out['parameters'] = self::serializeParameters($query->getParameters());
			return $out;
		}

		/** @var QueryBuilder $source */
		$out['entityClass'] = $source->getRootEntities()[0];
		$idQb = (clone $source)->select(sprintf('%s.id', $source->getRootAliases()[0]))->distinct();
		$out['ids'] = array_map('current', $idQb->getQuery()->getScalarResult());
		$out['dql'] = $source->getDQL();
		$out['parameters'] = self::serializeParameters($source->getParameters());
		return $out;
	}

	private static function serializeParameters(iterable $parameters): array
	{
		$out = [];
		foreach ($parameters as $p) {
			$v = $p->getValue();
			$out[$p->getName()] = match (true) {
				$v instanceof \DateTimeInterface => $v->format(DATE_ATOM),
				is_object($v) && method_exists($v, 'getId') => get_class($v) . '#' . $v->getId(),
				is_object($v) => get_class($v),
				is_array($v) => array_slice(array_map(fn ($i) => is_object($i) && method_exists($i, 'getId') ? $i->getId() : $i, $v), 0, 1000),
				default => $v,
			};
		}
		return $out;
	}
}
