<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;

trait ExportLogTrait
{
	#[Column]
	protected string $identifier;

	#[Column]
	protected string $entityClass;

	#[Column(type: 'json')]
	protected array $ids = [];

	#[Column(type: 'json')]
	protected array $columns = [];

	#[Column(type: 'json', nullable: true)]
	protected ?array $filters = null;

	#[Column]
	protected int $rowCount = 0;

	#[Column(nullable: true)]
	protected ?string $email = null;

	#[Column]
	protected bool $inBackground = false;

	#[Column(nullable: true)]
	protected ?string $file = null;

	#[Column(nullable: true)]
	protected ?DateTimeImmutable $processedAt = null;

	#[Column(type: 'json', nullable: true)]
	protected ?array $metadata = null;

	public function getIdentifier(): string { return $this->identifier; }
	public function setIdentifier(string $identifier): static { $this->identifier = $identifier; return $this; }

	public function getEntityClass(): string { return $this->entityClass; }
	public function setEntityClass(string $entityClass): static { $this->entityClass = $entityClass; return $this; }

	public function getIds(): array { return $this->ids; }
	public function setIds(array $ids): static { $this->ids = $ids; return $this; }

	public function getColumns(): array { return $this->columns; }
	public function setColumns(array $columns): static { $this->columns = $columns; return $this; }

	public function getFilters(): ?array { return $this->filters; }
	public function setFilters(?array $filters): static { $this->filters = $filters; return $this; }

	public function getRowCount(): int { return $this->rowCount; }
	public function setRowCount(int $rowCount): static { $this->rowCount = $rowCount; return $this; }

	public function getEmail(): ?string { return $this->email; }
	public function setEmail(?string $email): static { $this->email = $email; return $this; }

	public function isInBackground(): bool { return $this->inBackground; }
	public function setInBackground(bool $inBackground): static { $this->inBackground = $inBackground; return $this; }

	public function getFile(): ?string { return $this->file; }
	public function setFile(?string $file): static { $this->file = $file; return $this; }

	public function getProcessedAt(): ?DateTimeImmutable { return $this->processedAt; }
	public function setProcessedAt(?DateTimeImmutable $processedAt): static { $this->processedAt = $processedAt; return $this; }

	public function getMetadata(): ?array { return $this->metadata; }
	public function setMetadata(?array $metadata): static { $this->metadata = $metadata; return $this; }
}
