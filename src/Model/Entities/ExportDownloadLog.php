<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Entities;

use ADT\Exporter\Model\Service\ExportActor;
use DateTimeImmutable;
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
 * cas v UTC, vlastni uuid + source kvuli svezeni do spolecneho uloziste.
 *
 * Zaznam je SAMONOSNY - nese identifier a rowCount opsane z exportu, aby
 * SIEM videl "kolik ceho odeslo" bez joinovani na jinou tabulku (ta uz
 * navic muze byt odvezena moverem).
 */
#[Entity]
#[Table(name: 'export_download_log')]
// jeden export ma i mnoho stazeni - bez indexu by dotaz "kdo si tenhle
// export stahl" skenoval celou tabulku
#[Index(columns: ['export_uuid'])]
final class ExportDownloadLog
{
	use ActorSnapshotTrait;

	#[Id]
	#[Column]
	#[GeneratedValue]
	private ?int $id = null;

	public function __construct(
		/** identita udalosti nezavisla na databazi - viz ExportLog::$uuid */
		#[Column(length: 36, unique: true)]
		private readonly string $uuid,
		/** ze KTEREHO systemu zaznam pochazi - atribuce zdroje po svezeni */
		#[Column(nullable: true)]
		private readonly ?string $source,
		/** VZDY V UTC; getter zamerne neni - viz ExportLog::$createdAt */
		#[Column]
		private readonly DateTimeImmutable $createdAt,
		/**
		 * uuid auditni udalosti exportu, ze ktereho soubor pochazi - JEDINA
		 * vazba na zadani. Pres uuid, ne pres lokalni id: autoinkrement je
		 * unikatni jen v jedne DB, takze po svezeni logu do spolecneho
		 * uloziste by join nesedel. Na provozni radek se dostane pres
		 * export.audit_uuid, dokud existuje.
		 */
		#[Column(length: 36, nullable: true)]
		private readonly ?string $exportUuid,
		/** opsano z exportu, aby byl zaznam citelny samostatne */
		#[Column]
		private readonly string $identifier,
		/** kolik radku ten soubor obsahoval - objem dat, ktera prave odesla */
		#[Column]
		private readonly int $rowCount,
		/** ktery soubor byl vydan */
		#[Column(nullable: true)]
		private readonly ?string $fileName,
		?ExportActor $actor = null,
	) {
		$this->setActor($actor);
	}

	public function getId(): ?int { return $this->id; }
	public function getUuid(): string { return $this->uuid; }
	public function getSource(): ?string { return $this->source; }
	public function getExportUuid(): ?string { return $this->exportUuid; }
	public function getIdentifier(): string { return $this->identifier; }
	public function getRowCount(): int { return $this->rowCount; }
	public function getFileName(): ?string { return $this->fileName; }
}
