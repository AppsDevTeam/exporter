<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

/**
 * Vytvoreni souboru z nactenych entit. Implementaci dodava volajici
 * (napr. adapter nad ExportExcel z adt/datagrid) - knihovna tak nezavisi
 * na konkretnim formatu ani na datagrid ekosystemu.
 *
 * POZOR: generator MUSI byt registrovatelny jako sluzba (background handler
 * ho ziska z DI podle class-string ulozeneho v Export.generator).
 */
interface ExportFileGenerator
{
	/**
	 * @param array<string, array{items: array, columns: array}> $sections
	 *        nazev sekce => data; items jsou entity NEBO raw radky (pole poli)
	 *        dle typu sekce, v poradi dle ulozene selekce
	 * @return string absolutni cesta k vytvorenemu souboru
	 */
	public function generate(array $sections, string $identifier): string;
}
