<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\Exporter\Model\Entities\ExportLog;
use DateTimeImmutable;
use ADT\DoctrineComponents\EntityManager;
use Nette\Application\LinkGenerator;
use Nette\Mail\Mailer;
use Nette\Mail\Message;

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
		private readonly LinkGenerator $linkGenerator,
		private readonly int $syncRowLimit,
		private readonly string $fileDir,
		private readonly string $downloadLink,
	) {}

	public function export(ExportRequest $request): ExportLog
	{
		/** @var ExportLog $log */
		$log = new ($this->em->findEntityClassByInterface(ExportLog::class));
		$log->setIdentifier($request->identifier)
			->setEntityClass($request->entityClass)
			->setIds(array_values($request->ids))
			->setColumns($request->columns)
			->setFilters($request->filters)
			->setRowCount(count($request->ids))
			->setEmail($request->email)
			->setMetadata(($request->metadata ?? []) + ['generator' => get_class($request->generator)]);

		if (count($request->ids) > $this->syncRowLimit) {
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
			$message = new Message();
			$message->addTo($log->getEmail());
			$message->setSubject('Export je pripraven / Your export is ready');
			$message->setBody("Soubor ke stazeni / download: " . $this->linkGenerator->link($this->downloadLink, ['id' => $log->getId()]));
			$this->mailer->send($message);
		}
	}

	private function generateFile(ExportLog $log, ExportFileGenerator $generator): void
	{
		ini_set('memory_limit', '10G');

		// nacteni po davkach dle ulozenych ids - poradi dle ids zachovano
		$items = [];
		foreach (array_chunk($log->getIds(), 5000) as $chunk) {
			foreach ($this->em->getRepository($log->getEntityClass())
				->createQueryBuilder('e')->where('e.id IN (:ids)')->setParameter('ids', $chunk)
				->getQuery()->getResult() as $item) {
				$items[(string) $item->getId()] = $item;
			}
		}
		$ordered = [];
		foreach ($log->getIds() as $id) {
			if (isset($items[(string) $id])) {
				$ordered[] = $items[(string) $id];
			}
		}

		$path = $generator->generate($ordered, $log->getColumns(), $log->getIdentifier());

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
