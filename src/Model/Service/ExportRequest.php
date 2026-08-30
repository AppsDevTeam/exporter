<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

/**
 * Zadani exportu - jedine, co volajici (datagrid, formular, prikaz) sklada.
 * Sklada se z jedne ci vice sekci (Excel: sheet per sekce; CSV: prave jedna).
 * Selekce kazde sekce se materializuje V OKAMZIKU volani - viz ExportSection.
 */
final readonly class ExportRequest
{
	/** @var ExportSection[] */
	public array $sections;

	/**
	 * @param string $identifier lidsky citelny typ exportovanych dat - klicove pole auditu
	 * @param ExportSection[]|ExportSection $sections
	 * @param ExportFileGenerator $generator vytvori soubor (sluzba, viz README)
	 * @param string|null $email prijemce pri background zpracovani
	 * @param array|null $filters stav filtru/formulare v okamziku exportu (audit)
	 * @param array|null $metadata volitelny dalsi kontext (audit)
	 */
	public function __construct(
		public string $identifier,
		array|ExportSection $sections,
		public ExportFileGenerator $generator,
		public ?string $email = null,
		public ?array $filters = null,
		public ?array $metadata = null,
	) {
		$this->sections = is_array($sections) ? array_values($sections) : [$sections];
	}
}
