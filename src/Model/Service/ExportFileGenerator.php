<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

/**
 * Vytvoreni souboru z nactenych entit. Implementaci dodava volajici
 * (napr. adapter nad ExportExcel z adt/datagrid) - knihovna tak nezavisi
 * na konkretnim formatu ani na datagrid ekosystemu.
 *
 * POZOR: generator MUSI byt registrovatelny jako sluzba (background handler
 * ho ziska z DI podle class-string ulozeneho v ExportLog.metadata).
 */
interface ExportFileGenerator
{
	/**
	 * @param object[] $items nactene entity v poradi dle ExportLog.ids
	 * @param array $columns ['sloupec' => 'Popisek', ...]
	 * @return string absolutni cesta k vytvorenemu souboru
	 */
	public function generate(array $items, array $columns, string $identifier): string;
}
