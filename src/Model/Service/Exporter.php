<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\DoctrineComponents\EntityManager;
use ADT\DoctrineComponents\QueryObject\QueryObjectInterface;
use ADT\Exporter\Model\Entities\Export;
use ADT\Exporter\Model\Entities\ExportLog;
use DateTimeImmutable;
use Doctrine\ORM\QueryBuilder;
use Nette\Application\LinkGenerator;
use Nette\Mail\Mailer;

/**
 * Jednotne hrdlo vsech exportu dat. V JEDNE transakci vznika:
 *   - ExportLog: AUDITNI zaznam (kdo/kdy/co presne - enumerace ID, DQL,
 *     filtry - a kam to odeslo) - append-only, bez jedine relace a bez
 *     vazby na soubor; odvazi ho mover do dlouhodobeho auditniho uloziste.
 *     Provozni beh na nej NIKDY nesmi sahat - po odvezeni tu neni.
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
		private readonly LinkGenerator $linkGenerator,
		private readonly int $syncRowLimit,
		private readonly string $downloadLink,
	) {}

	public function export(ExportRequest $request): Export
	{
		$sections = [];
		$rowCount = 0;
		foreach ($request->sections as $section) {
			$sections[] = $s = $this->materializeSection($section);
			$rowCount += $s['ids'] !== null ? count($s['ids']) : count($s['rows']);
		}

		$now = new DateTimeImmutable();

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
		$this->em->wrapInTransaction(function () use ($export, $request, $sections, $rowCount, $now, $actor) {
			$this->em->persist($export);
			$this->em->flush();
			$this->em->persist(new ExportLog(
				createdAt: $now,
				identifier: $request->identifier,
				sections: $sections,
				rowCount: $rowCount,
				recipientEmail: $request->email,
				exportId: $export->getId(),
				createdById: $actor?->id !== null ? (string) $actor->id : null,
				createdByLabel: $actor?->label,
				createdBy: $actor?->data ?: null,
			));
			$this->em->flush();
			if ($export->isInBackground()) {
				$this->backgroundQueue->publish(self::QUEUE_CALLBACK, ['exportId' => $export->getId()]);
			}
		});

		if (!$export->isInBackground()) {
			$this->generateFile($export, $request->generator);
		}

		return $export;
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
