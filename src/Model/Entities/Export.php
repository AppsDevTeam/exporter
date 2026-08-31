<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Entities;

use DateTimeImmutable;

/**
 * PROVOZNI zaznam exportu - ridi zpracovani (background regenerace ze sekci),
 * doruceni a download. Soubor spravuje ExportFileStorage (aplikace typicky
 * pres vlastni File ekosystem); po doruceni a uplynuti retence souboru muze
 * aplikace radek casem smazat.
 *
 * Auditni stopa je SAMOSTATNA entita ExportLog (append-only, bez vazby na
 * soubor) - tu odvazi mover do dlouhodobeho auditniho uloziste.
 */
interface Export
{
	public function getId(): ?int;

	public function getIdentifier(): string;
	public function setIdentifier(string $identifier): static;

	/** operacni kopie sekci (ids/rows/columns) pro background regeneraci */
	public function getSections(): array;
	public function setSections(array $sections): static;

	/**
	 * Sluzba, ktera soubor vyrobi (class-string ExportFileGenerator).
	 * Provozni udaj - background regenerace na nej nesmi potrebovat audit.
	 * @return class-string
	 */
	public function getGenerator(): string;
	public function setGenerator(string $generator): static;

	public function getEmail(): ?string;
	public function setEmail(?string $email): static;

	public function isInBackground(): bool;
	public function setInBackground(bool $inBackground): static;

	/** lidske jmeno souboru (download i priloha e-mailu) */
	public function getFileName(): ?string;
	public function setFileName(?string $fileName): static;

	public function getProcessedAt(): ?DateTimeImmutable;
	public function setProcessedAt(?DateTimeImmutable $processedAt): static;

	public function getFilesPurgedAt(): ?DateTimeImmutable;
	public function setFilesPurgedAt(?DateTimeImmutable $filesPurgedAt): static;

	public function getCreatedAt(): DateTimeImmutable;
	public function setCreatedAt(DateTimeImmutable $createdAt): static;
}
