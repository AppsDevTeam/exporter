<?php

declare(strict_types=1);

namespace ADT\Exporter\DI;

use ADT\Exporter\Model\Service\DefaultExportMailFactory;
use ADT\Exporter\Model\Service\ExportFileGenerator;
use ADT\Exporter\Model\Service\ExportMailFactory;
use ADT\Exporter\Console\PurgeExportFilesCommand;
use ADT\Exporter\Model\Service\ExportFileStorage;
use ADT\Exporter\Model\Service\Exporter;
use ADT\Exporter\Model\Service\LocalExportFileStorage;
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
			// callbacky udalosti, napr. onExport: [[@nejakaSluzba, exportProbehl]]
			// - viz Exporter::$onExport a $onDownload
			'onExport' => Expect::listOf('mixed'),
			'onDownload' => Expect::listOf('mixed'),
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
			]);

		$exporter = $builder->getDefinition($this->prefix('exporter'));
		foreach ($config->onExport as $callback) {
			$exporter->addSetup('$onExport[]', [$callback]);
		}
		foreach ($config->onDownload as $callback) {
			$exporter->addSetup('$onDownload[]', [$callback]);
		}

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
