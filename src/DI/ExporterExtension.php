<?php

declare(strict_types=1);

namespace ADT\Exporter\DI;

use ADT\Exporter\Model\Service\DefaultExportMailFactory;
use ADT\Exporter\Model\Service\ExportFileGenerator;
use ADT\Exporter\Model\Service\ExportMailFactory;
use ADT\Exporter\Console\PurgeExportFilesCommand;
use ADT\Exporter\Model\Service\ExportActorProvider;
use ADT\Exporter\Model\Service\ExportFileStorage;
use ADT\Exporter\Model\Service\Exporter;
use ADT\Exporter\Model\Service\LocalExportFileStorage;
use ADT\Exporter\Model\Service\NullExportActorProvider;
use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * extensions:
 *     exporter: ADT\Exporter\DI\ExporterExtension
 * exporter:
 *     syncRowLimit: 500          # nad limit -> background + e-mail
 *     fileDir: %appDir%/../data/exports
 *     downloadLink: ':Portal:Export:download'
 *     source: %project%          # oznaceni systemu v auditu
 *
 * Queue callback (background-queue-nette):
 *     backgroundQueue: callbacks: processExport: [@exporter.exporter, processExport]
 *
 * Generatory souboru se registruji jako sluzby s tagem `exporter.generator`.
 */
class ExporterExtension extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'syncRowLimit' => Expect::int(500),
			'fileRetentionDays' => Expect::int(7),
			// pouziva jen default LocalExportFileStorage; s vlastnim storage
			// (napr. nad aplikacnim File ekosystemem) neni potreba
			'fileDir' => Expect::string('/tmp/exports'),
			'downloadLink' => Expect::string()->required(),
			// oznaceni systemu v auditnim zaznamu; nutne vsude, kde tychze
			// tabulek existuje vic (projekt na projekt) a svazi se do jednoho
			// auditniho uloziste - jinak nejde rict, odkud zaznam je
			'source' => Expect::string()->nullable(),
		]);
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig();

		$builder->addDefinition($this->prefix('exporter'))
			->setFactory(Exporter::class, [
				'syncRowLimit' => $config->syncRowLimit,
				'downloadLink' => $config->downloadLink,
				'source' => $config->source,
			]);

		$builder->addDefinition($this->prefix('purgeExportFilesCommand'))
			->setFactory(PurgeExportFilesCommand::class, ['defaultRetentionDays' => $config->fileRetentionDays])
			->setAutowired(false);
	}

	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();

		// e-mail sablonu vlastni projekt - default jen kdyz zadna sluzba
		// ExportMailFactory neexistuje
		if (!$builder->findByType(ExportMailFactory::class)) {
			$builder->addDefinition($this->prefix('mailFactory'))
				->setType(DefaultExportMailFactory::class);
		}

		// aktera dodava aplikace (SecurityUser) - bez vlastni sluzby se
		// audituje bez aktera (cron/CLI kontexty)
		if (!$builder->findByType(ExportActorProvider::class)) {
			$builder->addDefinition($this->prefix('actorProvider'))
				->setType(NullExportActorProvider::class);
		}

		// uloziste souboru vlastni projekt (napr. adt/files) - default lokalni
		if (!$builder->findByType(ExportFileStorage::class)) {
			$builder->addDefinition($this->prefix('fileStorage'))
				->setFactory(LocalExportFileStorage::class, [$this->getConfig()->fileDir]);
		}

		$exporter = $builder->getDefinition($this->prefix('exporter'));
		foreach ($builder->findByType(ExportFileGenerator::class) as $def) {
			$exporter->addSetup('addGenerator', [$def]);
		}
	}
}
