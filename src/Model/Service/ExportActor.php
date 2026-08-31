<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

/**
 * Snapshot aktera pro auditni zaznam - hodnoty v okamziku akce.
 *
 * Zpusob identifikace aktera se projekt od projektu lisi (nekde jmeno a
 * e-mail, jinde jen prihlasovaci jmeno, servisni ucet, API klic, IC...),
 * proto je promenna cast volny $data. Ploche zustava jen univerzalni
 * minimum, ktere ma smysl v KAZDEM systemu:
 *
 *   - $id    ... klic aktera ve zdrojovem systemu; spojovaci klic auditu
 *                (podle nej se v auditnim ulozisti joinuje napric logy)
 *   - $label ... jedno lidsky citelne oznaceni, at uz ho projekt sklada
 *                z cehokoliv - aby sel log cist bez znalosti tvaru $data
 */
final readonly class ExportActor
{
	/**
	 * @param int|string|null $id klic aktera ve zdrojovem systemu
	 * @param string|null $label lidsky citelne oznaceni pro cteni logu
	 * @param array $data cokoliv dalsiho, cim projekt aktera identifikuje
	 *        (jmeno, e-mail, role, prihlasovaci jmeno, tenant...)
	 */
	public function __construct(
		public int|string|null $id = null,
		public ?string $label = null,
		public array $data = [],
	) {}
}
