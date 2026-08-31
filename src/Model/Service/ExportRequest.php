<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

/**
 * Zadani exportu - jedine, co volajici (datagrid, formular, prikaz) sklada.
 * Sklada se z jedne ci vice sekci (Excel: sheet per sekce; CSV: prave jedna).
 * Selekce kazde sekce se materializuje V OKAMZIKU volani - viz ExportSection.
 *
 * Zadny popis selekce se sem NEPREDAVA: cim se vybiralo, si audit vytahne
 * ze skutecne spustene query (dql + parameters + vycet ID v sekci). Udaj
 * dodany volajicim by byl neoveritelny a mohl by se s realnym dotazem
 * rozejit - v auditu je pole, ktere muze lhat, horsi nez zadne.
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
	 * @param bool $forceBackground vynuti background zpracovani + e-mail
	 *        i pod syncRowLimit (automaticke reporty z cronu, kde neni
	 *        nikdo, kdo by si soubor stahl synchronne)
	 */
	public function __construct(
		public string $identifier,
		array|ExportSection $sections,
		public ExportFileGenerator $generator,
		public ?string $email = null,
		public bool $forceBackground = false,
	) {
		$this->sections = is_array($sections) ? array_values($sections) : [$sections];
	}
}
