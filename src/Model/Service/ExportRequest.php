<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

use ADT\DoctrineComponents\QueryObject\QueryObjectInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Zadani exportu - jedine, co volajici (datagrid, formular, prikaz) sklada.
 *
 * Zdroj dat se predava PRIRODZENE tak, jak ho volajici ma: QueryObject
 * (datagrid), QueryBuilder (formulare/prikazy), nebo primo pole ID.
 * Exporter selekci VZDY zmaterializuje na seznam ID v okamziku volani -
 * query nejde serializovat do background jobu a hlavne: audit musi zachytit,
 * co bylo exportovano PRI KLIKNUTI, ne co by tentyz dotaz vratil o minuty
 * pozdeji. DQL + parametry dotazu se navic ulozi do auditniho kontextu
 * ("jak byla selekce definovana").
 */
final readonly class ExportRequest
{
	/**
	 * @param string $identifier lidsky citelny typ exportovanych dat (nazev
	 *        gridu / reportu) - klicove pole auditu
	 * @param QueryObjectInterface|QueryBuilder|array $source zdroj dat;
	 *        pole = primo seznam ID (pak je nutny $entityClass)
	 * @param array $columns ['sloupec' => 'Popisek', ...]
	 * @param ExportFileGenerator $generator vytvori soubor z nactenych entit
	 * @param string|null $email prijemce pri background zpracovani
	 * @param array|null $filters stav filtru v okamziku exportu (audit)
	 * @param class-string|null $entityClass povinne jen pro $source jako pole ID
	 * @param array|null $metadata volitelny dalsi kontext (audit)
	 */
	public function __construct(
		public string $identifier,
		public QueryObjectInterface|QueryBuilder|array $source,
		public array $columns,
		public ExportFileGenerator $generator,
		public ?string $email = null,
		public ?array $filters = null,
		public ?string $entityClass = null,
		public ?array $metadata = null,
	) {}
}
