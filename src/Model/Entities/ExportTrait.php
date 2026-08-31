<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;

trait ExportTrait
{
	#[Column]
	protected string $identifier;


	#[Column(type: 'json')]
	protected array $sections = [];

	#[Column]
	protected string $generator;

	#[Column(nullable: true)]
	protected ?string $email = null;

	#[Column]
	protected bool $inBackground = false;

	#[Column(nullable: true)]
	protected ?string $fileName = null;

	#[Column(nullable: true)]
	protected ?DateTimeImmutable $processedAt = null;

	#[Column(nullable: true)]
	protected ?DateTimeImmutable $filesPurgedAt = null;

	#[Column]
	protected DateTimeImmutable $createdAt;

	public function getIdentifier(): string { return $this->identifier; }
	public function setIdentifier(string $identifier): static { $this->identifier = $identifier; return $this; }


	public function getSections(): array { return $this->sections; }
	public function setSections(array $sections): static { $this->sections = $sections; return $this; }

	public function getGenerator(): string { return $this->generator; }
	public function setGenerator(string $generator): static { $this->generator = $generator; return $this; }

	public function getEmail(): ?string { return $this->email; }
	public function setEmail(?string $email): static { $this->email = $email; return $this; }

	public function isInBackground(): bool { return $this->inBackground; }
	public function setInBackground(bool $inBackground): static { $this->inBackground = $inBackground; return $this; }

	public function getFileName(): ?string { return $this->fileName; }
	public function setFileName(?string $fileName): static { $this->fileName = $fileName; return $this; }

	public function getProcessedAt(): ?DateTimeImmutable { return $this->processedAt; }
	public function setProcessedAt(?DateTimeImmutable $processedAt): static { $this->processedAt = $processedAt; return $this; }

	public function getFilesPurgedAt(): ?DateTimeImmutable { return $this->filesPurgedAt; }
	public function setFilesPurgedAt(?DateTimeImmutable $filesPurgedAt): static { $this->filesPurgedAt = $filesPurgedAt; return $this; }

	public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
	public function setCreatedAt(DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
}
