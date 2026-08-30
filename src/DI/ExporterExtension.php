<?php

declare(strict_types=1);

namespace ADT\Exporter\DI;

use ADT\Exporter\Model\Service\DefaultExportMailFactory;
use ADT\Exporter\Model\Service\ExportFileGenerator;
use ADT\Exporter\Model\Service\ExportMailFactory;
use ADT\Exporter\Model\Service\Exporter;
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
			'fileDir' => Expect::string()->required(),
			'downloadLink' => Expect::string()->required(),
		]);
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig();

		$builder->addDefinition($this->prefix('exporter'))
			->setFactory(Exporter::class, [
				'syncRowLimit' => $config->syncRowLimit,
				'fileDir' => $config->fileDir,
				'downloadLink' => $config->downloadLink,
			]);
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

		$exporter = $builder->getDefinition($this->prefix('exporter'));
		foreach ($builder->findByType(ExportFileGenerator::class) as $def) {
			$exporter->addSetup('addGenerator', [$def]);
		}
	}
}
