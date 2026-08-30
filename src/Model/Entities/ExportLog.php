<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

/**
 * AUDITNI zaznam exportu dat - "co presne opustilo system, kdo a kdy".
 *
 * FINALNI, NEMENNA entita knihovny (zadne settery - vse pres konstruktor;
 * po vytvoreni se zaznam uz nikdy needituje). Aktera uklada DENORMALIZOVANE
 * (id + jmeno + e-mail v okamziku akce, zadna FK relace) - audit tak nezavisi
 * na zbytku databaze a mover ho odveze do dlouhodobeho auditniho uloziste
 * beze ztraty vyznamu, i kdyby uzivatel v aplikaci zanikl ci se prejmenoval.
 *
 * Aplikace entitu jen namapuje (nettrine mapping na vendor/adt/exporter/src/
 * Model/Entities) - zadna vlastni trida, zadny trait.
 */
#[Entity]
#[Table(name: 'export_log')]
final class ExportLog
{
	#[Id]
	#[Column]
	#[GeneratedValue]
	private ?int $id = null;

	public function __construct(
		#[Column]
		private readonly DateTimeImmutable $createdAt,
		#[Column]
		private readonly string $identifier,
		/** sekce: [{name, entityClass|null, ids|null, rows|null, columns, dql|null, parameters|null}] */
		#[Column(type: 'json')]
		private readonly array $sections,
		#[Column]
		private readonly int $rowCount,
		#[Column(type: 'json', nullable: true)]
		private readonly ?array $filters,
		#[Column(nullable: true)]
		private readonly ?string $email,
		/** vazba na provozni zaznam (ten muze casem zaniknout, audit ne) */
		#[Column(nullable: true)]
		private readonly ?int $exportId,
		#[Column(type: 'json', nullable: true)]
		private readonly ?array $metadata,
		/** akter DENORMALIZOVANE - snapshot v okamziku akce, zadna relace */
		#[Column(nullable: true)]
		private readonly ?string $createdById,
		#[Column(nullable: true)]
		private readonly ?string $createdByName,
		#[Column(nullable: true)]
		private readonly ?string $createdByEmail,
	) {}

	public function getId(): ?int { return $this->id; }
	public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
	public function getIdentifier(): string { return $this->identifier; }
	public function getSections(): array { return $this->sections; }
	public function getRowCount(): int { return $this->rowCount; }
	public function getFilters(): ?array { return $this->filters; }
	public function getEmail(): ?string { return $this->email; }
	public function getExportId(): ?int { return $this->exportId; }
	public function getMetadata(): ?array { return $this->metadata; }
	public function getCreatedById(): ?string { return $this->createdById; }
	public function getCreatedByName(): ?string { return $this->createdByName; }
	public function getCreatedByEmail(): ?string { return $this->createdByEmail; }
}
