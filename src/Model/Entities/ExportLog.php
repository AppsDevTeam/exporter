<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Entities;

use DateTimeImmutable;

/**
 * AUDITNI zaznam exportu dat - "co presne opustilo system, kdo a kdy".
 * Append-only, BEZ vazby na soubor a stav zpracovani (to je provozni entita
 * Export) - zaznam je tak pripraveny na odvoz moverem do dlouhodobeho
 * auditniho uloziste, nezavisle na zivotnim cyklu exportu.
 *
 * Vznika VZDY (i u synchronniho downloadu) a ve stejne transakci jako
 * provozni zaznam a pripadny background job. Po vytvoreni se NIKDY needituje.
 */
interface ExportLog
{
	public function getId(): ?int;

	public function getIdentifier(): string;
	public function setIdentifier(string $identifier): static;

	/**
	 * Sekce exportu: [{name, entityClass|null, ids|null, rows|null, columns,
	 * dql|null, parameters|null}, ...]. Entity sekce nesou presnou enumeraci
	 * ID (+ definici dotazu), raw sekce primo snapshot radku.
	 */
	public function getSections(): array;
	public function setSections(array $sections): static;

	/** Stav filtru v okamziku exportu - auditni kontext ("pouzite filtry") */
	public function getFilters(): ?array;
	public function setFilters(?array $filters): static;

	public function getRowCount(): int;
	public function setRowCount(int $rowCount): static;

	public function getEmail(): ?string;
	public function setEmail(?string $email): static;

	/** vazba na provozni zaznam (ten muze casem zaniknout, audit ne) */
	public function getExportId(): ?int;
	public function setExportId(?int $exportId): static;

	public function getMetadata(): ?array;
	public function setMetadata(?array $metadata): static;

	public function getCreatedAt(): DateTimeImmutable;
	public function setCreatedAt(DateTimeImmutable $createdAt): static;
}
