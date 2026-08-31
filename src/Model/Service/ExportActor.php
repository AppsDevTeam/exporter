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
	 * @param string|null $ip odkud pozadavek prisel; ploche pole, protoze
	 *        na nem stoji detekcni pravidla typu "stejny ucet, jina zeme"
	 * @param string|null $userAgent klient, ktery export vyzadal
	 */
	public function __construct(
		public int|string|null $id = null,
		public ?string $label = null,
		public array $data = [],
		public ?string $ip = null,
		public ?string $userAgent = null,
	) {}

	/**
	 * Zplosteni pro auditni callback, ktery je zamerne jen ze
	 * skalaru a poli - implementace pak nemusi znat typy teto knihovny.
	 *
	 * @return array{id: string|null, label: string|null, data: array, ip: string|null, userAgent: string|null}
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id !== null ? (string) $this->id : null,
			'label' => $this->label,
			'data' => $this->data,
			'ip' => $this->ip,
			'userAgent' => $this->userAgent,
		];
	}

	/** @return array{id: null, label: null, data: array, ip: null, userAgent: null} */
	public static function emptyArray(): array
	{
		return ['id' => null, 'label' => null, 'data' => [], 'ip' => null, 'userAgent' => null];
	}
}
