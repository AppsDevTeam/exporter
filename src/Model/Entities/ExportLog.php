<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Entities;

use DateTimeImmutable;

/**
 * Auditni zaznam exportu dat - "co presne opustilo system, kdo a kdy".
 * Aplikace implementuje vlastni entitou (ExportLogTrait + vlastni id/createdAt/
 * createdBy atributy), stejny vzor jako GridExport ci AuthLog.
 *
 * Zaznam vznika VZDY (i u synchronniho downloadu) a ve stejne transakci jako
 * pripadny background job. Nikdy se needituje krome doplneni file/processedAt
 * po vygenerovani souboru.
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

	public function isInBackground(): bool;
	public function setInBackground(bool $inBackground): static;

	public function getFile(): ?string;
	public function setFile(?string $file): static;

	public function getProcessedAt(): ?DateTimeImmutable;
	public function setProcessedAt(?DateTimeImmutable $processedAt): static;

	public function getMetadata(): ?array;
	public function setMetadata(?array $metadata): static;

	public function getCreatedAt(): DateTimeImmutable;
	public function setCreatedAt(DateTimeImmutable $createdAt): static;
}
