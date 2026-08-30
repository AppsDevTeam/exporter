<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

use ADT\Exporter\Model\Entities\Export;

/** Default uloziste: <fileDir>/<id>-<fileName>, deterministicka cesta bez sloupce. */
final readonly class LocalExportFileStorage implements ExportFileStorage
{
	public function __construct(private string $fileDir) {}

	public function attach(Export $export, string $tmpPath): void
	{
		if (!is_dir($this->fileDir)) {
			mkdir($this->fileDir, 0770, true);
		}
		rename($tmpPath, $this->path($export));
	}

	public function getLocalPath(Export $export): ?string
	{
		$path = $this->path($export);
		return is_file($path) ? $path : null;
	}

	public function purge(Export $export): void
	{
		@unlink($this->path($export));
	}

	private function path(Export $export): string
	{
		return $this->fileDir . '/' . $export->getId() . '-' . $export->getFileName();
	}
}
