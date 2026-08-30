<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\DoctrineComponents\QueryObject\QueryObjectInterface;
use ADT\Exporter\Model\Entities\ExportLog;
use Doctrine\ORM\QueryBuilder;
use DateTimeImmutable;
use ADT\DoctrineComponents\EntityManager;
use Nette\Application\LinkGenerator;
use Nette\Mail\Mailer;

/**
 * Jednotne hrdlo vsech exportu dat:
 *   1. VZDY zapise auditni ExportLog (kdo/kdy resi entita aplikace pres
 *      createdBy/createdAt atributy, co presne = enumerace ids + filtry)
 *   2. do syncRowLimit radku vygeneruje soubor hned (volajici vrati download)
 *   3. nad limit zalozi background job (ve STEJNE transakci jako log -
 *      outbox garance background-queue) - soubor vznikne na pozadi
 *      a prijemci odejde e-mail s odkazem
 *
 * Registrace queue callbacku v aplikaci:
 *   backgroundQueue: callbacks: processExport: [@ADT\Exporter\Model\Service\Exporter, processExport]
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
		private readonly LinkGenerator $linkGenerator,
		private readonly int $syncRowLimit,
		private readonly string $fileDir,
		private readonly string $downloadLink,
	) {}

	public function export(ExportRequest $request): ExportLog
	{
		$sections = [];
		$rowCount = 0;
		foreach ($request->sections as $section) {
			$sections[] = $s = $this->materializeSection($section);
			$rowCount += $s['ids'] !== null ? count($s['ids']) : count($s['rows']);
		}

		/** @var ExportLog $log */
		$log = new ($this->em->findEntityClassByInterface(ExportLog::class));
		$log->setIdentifier($request->identifier)
			->setSections($sections)
			->setFilters($request->filters)
			->setRowCount($rowCount)
			->setEmail($request->email)
			->setMetadata(($request->metadata ?? []) + ['generator' => get_class($request->generator)]);

		if ($rowCount > $this->syncRowLimit) {
			$log->setInBackground(true);
			// log i job v jedne transakci - zadny export bez auditu, zadny audit bez jobu
			$this->em->wrapInTransaction(function () use ($log) {
				$this->em->persist($log);
				$this->em->flush();
				$this->backgroundQueue->publish(self::QUEUE_CALLBACK, ['exportLogId' => $log->getId()]);
			});
			return $log;
		}

		$this->em->persist($log);
		$this->em->flush();
		$this->generateFile($log, $request->generator);
		return $log;
	}

	/**
	 * Materializace sekce v okamziku volani. Entity zdroje -> seznam ID
	 * (+ DQL s parametry jako auditni kontext); query se do jobu NEserializuje
	 * a nikdy se neprehravava pozdeji (audit = stav pri kliknuti). Raw radky
	 * (agregaty) se ukladaji primo do zaznamu.
	 */
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

	/** Queue callback - generovani na pozadi + e-mail s odkazem */
	public function processExport(array $parameters): void
	{
		/** @var ExportLog $log */
		$log = $this->em->getRepository($this->em->findEntityClassByInterface(ExportLog::class))
			->find($parameters['exportLogId']);
		if (!$log || $log->getFile() !== null) {
			return; // idempotence pri retry
		}

		$generatorClass = $log->getMetadata()['generator'];
		$this->generateFile($log, $this->resolveGenerator($generatorClass));

		if ($log->getEmail()) {
			// obsah e-mailu vlastni projekt (ExportMailFactory) - odkaz vede na
			// aplikacni routu, ktera soubor vyda az po overeni prihlaseni/vlastnictvi
			$this->mailer->send($this->mailFactory->create(
				$log,
				$this->linkGenerator->link($this->downloadLink, ['id' => $log->getId()]),
			));
		}
	}

	private function generateFile(ExportLog $log, ExportFileGenerator $generator): void
	{
		ini_set('memory_limit', '10G');

		$sections = [];
		foreach ($log->getSections() as $section) {
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

		$path = $generator->generate($sections, $log->getIdentifier());

		if (!is_dir($this->fileDir)) {
			mkdir($this->fileDir, 0770, true);
		}
		$target = $this->fileDir . '/' . $log->getId() . '-' . basename($path);
		rename($path, $target);

		$log->setFile($target)->setProcessedAt(new DateTimeImmutable());
		$this->em->flush();
	}

	/** @internal registrace generatoru z DI (viz ExporterExtension) */
	public function addGenerator(ExportFileGenerator $generator): void
	{
		$this->generators[get_class($generator)] = $generator;
	}

	private function resolveGenerator(string $class): ExportFileGenerator
	{
		return $this->generators[$class]
			?? throw new \RuntimeException("Export generator '$class' neni registrovany jako sluzba (tag exporter.generator).");
	}
}
