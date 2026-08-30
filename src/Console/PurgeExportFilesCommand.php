<?php

declare(strict_types=1);

namespace ADT\Exporter\Console;

use ADT\Exporter\Model\Service\Exporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Uklid vygenerovanych souboru exportu (cron, typicky denne):
 *   php bin/console exporter:purge-files [dny]
 * Auditni zaznamy zustavaji - maze se jen soubor na disku.
 */
#[AsCommand(name: 'exporter:purge-files')]
class PurgeExportFilesCommand extends Command
{
	public function __construct(
		private readonly Exporter $exporter,
		private readonly int $defaultRetentionDays,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addArgument('days', InputArgument::OPTIONAL, 'Smazat soubory starsi nez N dni');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$days = (int) ($input->getArgument('days') ?? $this->defaultRetentionDays);
		$purged = $this->exporter->purgeFiles($days);
		$output->writeln("Smazano souboru: $purged (starsi nez $days dni)");
		return Command::SUCCESS;
	}
}
