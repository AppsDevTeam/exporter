<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

use ADT\Exporter\Model\Entities\ExportLog;
use Nette\Mail\Message;

/**
 * Sestaveni e-mailu s hotovym exportem. Knihovna dodava jednoduchy default
 * (DefaultExportMailFactory); projekt ho prepise vlastni sluzbou tohoto typu
 * (preklady, Latte sablona, branding) - extension pouzije aplikacni
 * implementaci automaticky, kdyz existuje.
 *
 * $downloadLink je APLIKACNI routa (exporter.downloadLink config) - soubor
 * lezi mimo docroot a servituje ho az presenter po overeni prihlaseni
 * a vlastnictvi (viz README, sekce Bezpecnost).
 */
interface ExportMailFactory
{
	public function create(ExportLog $log, string $downloadLink): Message;
}
