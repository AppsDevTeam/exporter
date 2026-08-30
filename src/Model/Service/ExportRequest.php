<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

/**
 * Zadani exportu - jedine, co volajici (datagrid, formular, prikaz) sklada.
 */
final readonly class ExportRequest
{
	/**
	 * @param string $identifier lidsky citelny typ exportovanych dat (nazev
	 *        gridu / reportu) - klicove pole auditu
	 * @param class-string $entityClass
	 * @param int[]|string[] $ids presna enumerace radku k exportu
	 * @param array $columns ['sloupec' => 'Popisek', ...]
	 * @param ExportFileGenerator $generator vytvori soubor z nactenych entit
	 * @param string|null $email prijemce pri background zpracovani
	 * @param array|null $filters stav filtru v okamziku exportu (audit)
	 * @param array|null $metadata volitelny dalsi kontext (audit)
	 */
	public function __construct(
		public string $identifier,
		public string $entityClass,
		public array $ids,
		public array $columns,
		public ExportFileGenerator $generator,
		public ?string $email = null,
		public ?array $filters = null,
		public ?array $metadata = null,
	) {}
}
