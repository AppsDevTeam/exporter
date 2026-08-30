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

	public function getEntityClass(): string;
	public function setEntityClass(string $entityClass): static;

	/** @return int[]|string[] presna enumerace exportovanych radku */
	public function getIds(): array;
	public function setIds(array $ids): static;

	public function getColumns(): array;
	public function setColumns(array $columns): static;

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
}
