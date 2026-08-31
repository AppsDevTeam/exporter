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
	 * @param array|null $filters CO si volajici vyzadal - stav filtru/formulare
	 *        v okamziku exportu; citelny protejsek strojove selekce v sekcich
	 *        (a u agregatovych sekci bez DQL jediny zaznam o zadani)
	 * @param bool $forceBackground vynuti background zpracovani + e-mail
	 *        i pod syncRowLimit (automaticke reporty z cronu, kde neni
	 *        nikdo, kdo by si soubor stahl synchronne)
	 */
	public function __construct(
		public string $identifier,
		array|ExportSection $sections,
		public ExportFileGenerator $generator,
		public ?string $email = null,
		public ?array $filters = null,
		public bool $forceBackground = false,
	) {
		$this->sections = is_array($sections) ? array_values($sections) : [$sections];
	}
}
