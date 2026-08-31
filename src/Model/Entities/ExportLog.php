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
 * AUDITNI zaznam exportu dat - "co presne opustilo system, kdo, kdy a kam".
 *
 * FINALNI, NEMENNA entita knihovny (zadne settery - vse pres konstruktor;
 * po vytvoreni se zaznam uz nikdy needituje) a bez jedine relace: audit
 * nezavisi na zbytku databaze a mover ho odveze do dlouhodobeho auditniho
 * uloziste beze ztraty vyznamu, i kdyby uzivatel v aplikaci zanikl.
 *
 * Akter je snapshot v okamziku akce. Ploche je jen univerzalni minimum
 * (createdById = spojovaci klic napric logy, createdByLabel = lidsky
 * citelne oznaceni); CIM konkretne projekt aktera identifikuje se lisi,
 * a proto je promenna cast volny JSON createdBy - viz ExportActor.
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
		/**
		 * Sekce: [{name, entityClass|null, ids|null, rows|null, columns, dql|null, parameters|null}]
		 *
		 * JEDINY zdroj pravdy o tom, co a podle ceho odeslo: dql + parameters
		 * se vytahuji ze skutecne spustene query, ids jsou jeji vysledek.
		 * Zadny popis selekce dodany volajicim se neuklada - nesel by overit.
		 */
		#[Column(type: 'json')]
		private readonly array $sections,
		#[Column]
		private readonly int $rowCount,
		/** KAM data odesla (prijemce); jina informace nez akter, ktery export spustil */
		#[Column(nullable: true)]
		private readonly ?string $recipientEmail,
		/** vazba na provozni zaznam (ten muze casem zaniknout, audit ne) */
		#[Column(nullable: true)]
		private readonly ?int $exportId,
		/** spojovaci klic aktera ve zdrojovem systemu */
		#[Column(nullable: true)]
		private readonly ?string $createdById,
		/** lidsky citelne oznaceni aktera - aby sel log cist bez znalosti tvaru createdBy */
		#[Column(nullable: true)]
		private readonly ?string $createdByLabel,
		/** cim dalsim projekt aktera identifikuje (jmeno, e-mail, role, ucet...) */
		#[Column(type: 'json', nullable: true)]
		private readonly ?array $createdBy,
	) {}

	public function getId(): ?int { return $this->id; }
	public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
	public function getIdentifier(): string { return $this->identifier; }
	public function getSections(): array { return $this->sections; }
	public function getRowCount(): int { return $this->rowCount; }
	public function getRecipientEmail(): ?string { return $this->recipientEmail; }
	public function getExportId(): ?int { return $this->exportId; }
	public function getCreatedById(): ?string { return $this->createdById; }
	public function getCreatedByLabel(): ?string { return $this->createdByLabel; }
	public function getCreatedBy(): ?array { return $this->createdBy; }
}
