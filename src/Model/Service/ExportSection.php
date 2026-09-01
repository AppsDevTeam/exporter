<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

use ADT\DoctrineComponents\QueryObject\QueryObjectInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Jedna sekce exportu (v Excelu = jeden sheet). Export ma jednu ci vice
 * sekci; CSV povoluje prave jednu.
 *
 * Zdroj:
 *  - QueryObject / QueryBuilder / pole ID -> entity (materializuje se na ID
 *    v okamziku volani, background je docte)
 *  - pole poli (radky) -> vypoctena/agregovana data bez entit; radky se ulozi
 *    PRIMO do provozniho zaznamu (a background je uz nedopocitava) - urceno
 *    pro male souhrny (sales summary apod.), ne pro tisice radku
 */
final readonly class ExportSection
{
	/**
	 * @param string $name nazev sekce (nazev sheetu i klic v udalostech)
	 * @param QueryObjectInterface|QueryBuilder|array $source
	 * @param array $columns ['sloupec' => 'Popisek', ...] pro entity;
	 *        pro raw radky poradi/nazvy sloupcu vystupu
	 * @param class-string|null $entityClass povinne jen pro pole ID
	 */
	public function __construct(
		public string $name,
		public QueryObjectInterface|QueryBuilder|array $source,
		public array $columns,
		public ?string $entityClass = null,
	) {}

	/** Pole poli = raw radky; pole skalaru = seznam ID */
	public function isRawRows(): bool
	{
		if (!is_array($this->source) || $this->source === []) {
			return false;
		}

		// Zamerne bez reset() - to bere pole referenci a na readonly property
		// spadne na "Cannot indirectly modify readonly property".
		return is_array($this->source[array_key_first($this->source)]);
	}
}
