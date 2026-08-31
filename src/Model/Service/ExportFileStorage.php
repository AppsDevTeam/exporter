<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

use ADT\Exporter\Model\Entities\Export;

/**
 * Ulozeni/vydej/mazani souboru exportu. Default LocalExportFileStorage uklada
 * do adresare mimo docroot; aplikace typicky dodava vlastni implementaci nad
 * svym file ekosystemem (napr. adt/files File entita) - extension pouzije
 * aplikacni sluzbu tohoto typu automaticky.
 */
interface ExportFileStorage
{
	/** prevezme docasny soubor a priradi ho exportu */
	public function attach(Export $export, string $tmpPath): void;

	/** lokalni cesta pro FileResponse (sync download); null = soubor neexistuje */
	public function getLocalPath(Export $export): ?string;

	/** smaze soubor exportu (retence souboru) */
	public function purge(Export $export): void;
}
