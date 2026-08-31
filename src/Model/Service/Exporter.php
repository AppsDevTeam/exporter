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

/**
 * Jednotne hrdlo vsech exportu dat. V JEDNE transakci vznika:
 *   - AUDITNI udalost pres ExportAuditLogger (kdo/kdy/co presne - enumerace
 *     ID, DQL, filtry - a kam to odeslo). Knihovna nevlastni tabulku ani
 *     entitu: zapisovac dodava aplikace nebo nadrazeny balicek, ktery
 *     vsechny auditni udalosti sbira do jednoho streamu.
 *   - Export: PROVOZNI zaznam - ridi background regeneraci, soubor
 *     (pres ExportFileStorage - typicky aplikacni File ekosystem),
 *     doruceni a download
 *   - pripadny background job (nad syncRowLimit nebo forceBackground)
 *
 * Registrace queue callbacku:
 *   backgroundQueue: callbacks: processExport: [@exporter.exporter, processExport]
 */
final class Exporter
{
	public const string QUEUE_CALLBACK = 'processExport';

	/** @var array<class-string, ExportFileGenerator> */
	private array $generators = [];

	public function __construct(
		private readonly EntityManager $em,
		private readonly BackgroundQueue $backgroundQueue,
		private readonly Mailer $mailer,
		private readonly ExportMailFactory $mailFactory,
		private readonly ExportFileStorage $fileStorage,
		private readonly ExportActorProvider $actorProvider,
		private readonly ExportAuditLogger $auditLogger,
		private readonly LinkGenerator $linkGenerator,
		private readonly int $syncRowLimit,
		private readonly string $downloadLink,
	) {}

	public function export(ExportRequest $request): Export
	{
		// materializovana sekce se rozpada na dve nepretinajici se poloviny:
		// provozni potrebuje k regeneraci sloupce vcetne rendrovaci vystroje,
		// audit naopak dql + parametry (ty provoz necte, regeneruje se z ids)
		$sections = [];
		$auditSections = [];
		foreach ($request->sections as $section) {
			$s = $this->materializeSection($section);
			$sections[] = self::operationalSection($s);
			$auditSections[] = self::auditSection($s);
		}
		$rowCount = self::countRows($sections);

		$now = new DateTimeImmutable();
		// audit jde v UTC, at ho lze korelovat s logy jinych systemu a at
		// neni pri prechodu na zimni cas jedna hodina v roce nejednoznacna;
		// provozni zaznam si nechava lokalni cas jako zbytek aplikace
		$nowUtc = $now->setTimezone(new DateTimeZone('UTC'));

		/** @var Export $export */
		$export = new ($this->em->findEntityClassByInterface(Export::class));
		$export->setCreatedAt($now)
			->setIdentifier($request->identifier)
			->setSections($sections)
			->setGenerator(get_class($request->generator))
			->setEmail($request->email)
			->setInBackground($request->forceBackground || $rowCount > $this->syncRowLimit);

		$actor = $this->actorProvider->getActor();

		// audit + provozni zaznam + job v JEDNE transakci: zadny export bez
		// auditu, zadny audit bez exportu, zadny job bez obojiho. Auditni
		// zaznam je nemenny (konstruktor) a aktera nese denormalizovane.
		$this->em->wrapInTransaction(function () use ($export, $request, $auditSections, $rowCount, $nowUtc, $actor) {
			$this->em->persist($export);
			$this->em->flush();
			$this->auditLogger->log(
				action: ExportAuditLogger::ACTION_EXPORT,
				createdAt: $nowUtc,
				correlationId: (string) $export->getId(),
				actor: $actor?->toArray() ?? ExportActor::emptyArray(),
				payload: [
					'identifier' => $request->identifier,
					'rowCount' => $rowCount,
					'sections' => $auditSections,
					'recipientEmail' => $request->email,
				],
			);
			if ($export->isInBackground()) {
				$this->backgroundQueue->publish(self::QUEUE_CALLBACK, ['exportId' => $export->getId()]);
			}
		});

		if (!$export->isInBackground()) {
			$this->generateFile($export, $request->generator);
		}

		return $export;
	}

	/**
	 * Zaznamena STAZENI souboru - vola presenter TESNE PRED vydanim souboru,
	 * az po overeni opravneni.
	 *
	 * Data opousteji system az tady, ne pri zadani exportu: odkaz chodi
	 * e-mailem, plati po celou retenci souboru a pouzit ho muze i nekdo jiny
	 * nez zadavatel. Bez tohoto zaznamu vypada deset stazeni v auditu stejne
	 * jako zadne.
	 *
	 * Zamerne NEODCHYTAVA vyjimky: nepovede-li se audit, soubor se nevyda -
	 * stejne pravidlo jako u zadani exportu.
	 */
	public function logDownload(Export $export): void
	{
		$actor = $this->actorProvider->getActor();

		$this->auditLogger->log(
			action: ExportAuditLogger::ACTION_DOWNLOAD,
			createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
			correlationId: (string) $export->getId(),
			actor: $actor?->toArray() ?? ExportActor::emptyArray(),
			payload: [
				'identifier' => $export->getIdentifier(),
				'rowCount' => self::countRows($export->getSections()),
				'fileName' => $export->getFileName(),
			],
		);
	}

	/** lokalni cesta souboru pro sync download (FileResponse) */
	public function getFilePath(Export $export): ?string
	{
		return $this->fileStorage->getLocalPath($export);
	}

	/** Queue callback - regenerace ze sekci + e-mail s odkazem */
	public function processExport(array $parameters): void
	{
		/** @var Export $export */
		$export = $this->em->getRepository($this->em->findEntityClassByInterface(Export::class))
			->find($parameters['exportId']);
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
	}

	/**
	 * Smaze SOUBORY exportu starsich nez $retentionDays (exportovana data
	 * nesmi lezet na disku dele, nez je nutne k doruceni). Auditni zaznamy
	 * se nedotyka; provozni zaznam dostane filesPurgedAt.
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
		foreach ($exports as $export) {
			$this->fileStorage->purge($export);
			$export->setFilesPurgedAt(new DateTimeImmutable());
			$purged++;
		}
		$this->em->flush();
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

	private function generateFile(Export $export, ExportFileGenerator $generator): void
	{
		ini_set('memory_limit', '10G');

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
		$export->setProcessedAt(new DateTimeImmutable());
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
	 * AUDITNI projekce sekce - "co presne odeslo a podle ceho":
	 * dql + parametry skutecne spustene query, vycet ID (u agregatovych
	 * sekci primo radky) a VYCET EXPORTOVANYCH POLI. Ze sloupcu se bere
	 * jen cesta k datum; nazev tridy a preklad popisku jsou rendrovaci
	 * vystroj, ktera o odeslanych datech nevypovida nic.
	 */
	private static function auditSection(array $section): array
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
