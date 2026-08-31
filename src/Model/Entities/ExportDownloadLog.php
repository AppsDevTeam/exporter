<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Entities;

use ADT\Exporter\Model\Service\ExportActor;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;

/**
 * AUDITNI zaznam STAZENI exportovaneho souboru.
 *
 * Samostatna udalost, ne priznak na ExportLog - data opousteji system az
 * pri vydeji souboru, muze se to stat OPAKOVANE a klidne nekym jinym, nez
 * kdo export zadal (odkaz chodi e-mailem a plati po celou retenci souboru).
 * Priznak "stazeno" by tohle vsechno slil do jedne informace.
 *
 * Stejna pravidla jako ExportLog: finalni a nemenna, bez jedine relace,
 * cas v UTC, identita udalosti je dvojice (zdroj, id) - zdroj stampuje
 * mover pri odvozu.
 *
 * Zaznam je SAMONOSNY - nese identifier a rowCount opsane z exportu, aby
 * SIEM videl "kolik ceho odeslo" bez joinovani na jinou tabulku (ta uz
 * navic muze byt odvezena moverem).
 */
#[Entity]
#[Table(name: 'export_download_log')]
// jeden export ma i mnoho stazeni - bez indexu by dotaz "kdo si tenhle
// export stahl" skenoval celou tabulku
#[Index(columns: ['export_id'])]
final class ExportDownloadLog
{
	use ActorSnapshotTrait;

	#[Id]
	#[Column(type: Types::BIGINT, options: ['unsigned' => true])]
	#[GeneratedValue]
	private ?int $id = null;

	public function __construct(
		/** VZDY V UTC; getter zamerne neni - viz ExportLog::$createdAt */
		#[Column]
		private readonly DateTimeImmutable $createdAt,
		/**
		 * Korelacni klic: id provozniho zaznamu exportu, ze ktereho soubor
		 * pochazi. Tataz hodnota je v ExportLog::$exportId, takze se obe
		 * auditni udalosti spoji v ramci jednoho zdroje.
		 *
		 * Zamerne NE id auditniho radku: ten uz mohl byt odvezen moverem,
		 * takze by se ho v okamziku stazeni nebylo kde dohledat.
		 */
		#[Column(type: Types::BIGINT, options: ['unsigned' => true], nullable: true)]
		private readonly ?int $exportId,
		/** opsano z exportu, aby byl zaznam citelny samostatne */
		#[Column]
		private readonly string $identifier,
		/** kolik radku ten soubor obsahoval - objem dat, ktera prave odesla */
		#[Column(type: Types::BIGINT, options: ['unsigned' => true])]
		private readonly int $rowCount,
		/** ktery soubor byl vydan */
		#[Column(nullable: true)]
		private readonly ?string $fileName,
		?ExportActor $actor = null,
	) {
		$this->setActor($actor);
	}

	public function getId(): ?int { return $this->id; }
	public function getExportId(): ?int { return $this->exportId; }
	public function getIdentifier(): string { return $this->identifier; }
	public function getRowCount(): int { return $this->rowCount; }
	public function getFileName(): ?string { return $this->fileName; }
}
