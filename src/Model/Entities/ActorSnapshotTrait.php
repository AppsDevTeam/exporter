<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Entities;

use ADT\Exporter\Model\Service\ExportActor;
use Doctrine\ORM\Mapping\Column;

/**
 * Sloupce aktera auditni udalosti - snapshot v okamziku akce, zadna relace.
 *
 * Ploche je jen univerzalni minimum, ktere ma smysl v kazdem systemu:
 * createdById jako spojovaci klic (podle nej se v auditnim ulozisti joinuje
 * napric logy), createdByLabel jako citelne oznaceni a sitovy kontext, na
 * kterem stoji detekcni pravidla. CIM konkretne projekt aktera identifikuje
 * se lisi, proto je promenna cast volny JSON createdBy - viz ExportActor.
 *
 * Uplatni se na kazdou akci v ExportLog: kdo export zadal a kdo si soubor
 * stahl jsou ruzne udalosti a klidne ruzni lide.
 */
trait ActorSnapshotTrait
{
	#[Column(nullable: true)]
	private readonly ?string $createdById;

	#[Column(nullable: true)]
	private readonly ?string $createdByLabel;

	#[Column(type: 'json', nullable: true)]
	private readonly ?array $createdBy;

	#[Column(length: 45, nullable: true)]
	private readonly ?string $sourceIp;

	#[Column(type: 'text', nullable: true)]
	private readonly ?string $userAgent;

	private function setActor(?ExportActor $actor): void
	{
		$this->createdById = $actor?->id !== null ? (string) $actor->id : null;
		$this->createdByLabel = $actor?->label;
		$this->createdBy = $actor?->data ?: null;
		$this->sourceIp = $actor?->ip;
		$this->userAgent = $actor?->userAgent;
	}

	public function getCreatedById(): ?string { return $this->createdById; }
	public function getCreatedByLabel(): ?string { return $this->createdByLabel; }
	public function getCreatedBy(): ?array { return $this->createdBy; }
	public function getSourceIp(): ?string { return $this->sourceIp; }
	public function getUserAgent(): ?string { return $this->userAgent; }
}
