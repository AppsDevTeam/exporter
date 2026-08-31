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
 * AUDITNI zaznam exportu dat - "co presne opustilo system, kdo, kdy a kam".
 *
 * Jeden append-only stream udalosti s diskriminatorem $action, ne tabulka
 * per typ udalosti: zadani exportu a vydani souboru maji spolecnou vetsinu
 * sloupcu (cas, akter, identifier, rowCount, korelacni exportId) a lisi se
 * jen ve trech. Dalsi typ udalosti tak pribude bez migrace a mover veze
 * jednu tabulku. Odpovida to i tvaru, ktery ceka SIEM - viz ECS event.action.
 *
 * VYDANI souboru je samostatna udalost, ne priznak "stazeno" na zadani:
 * data opousteji system az vydejem, muze se to stat OPAKOVANE a klidne
 * nekym jinym, nez kdo export zadal (odkaz chodi e-mailem a plati po celou
 * retenci souboru). Priznak by tohle vsechno slil do jedne informace.
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
 * IDENTITA UDALOSTI je dvojice (zdroj, id). Zdroj neni sloupec: mover vi,
 * ze ktere databaze cte, a stampuje ho pri odvozu - stejny vzor plati pro
 * celou rodinu logu (auth_log, change_log, transaction_log), takze je
 * v kazde tabulce zdarma. Vlastni uuid by muselo pribyt do vsech z nich,
 * tedy i do doctrine-authenticatoru a doctrine-loggable; jednotne
 * jednodussi schema je cennejsi nez robustnejsi schema nasazene jen tady.
 * Zbytkove riziko: po obnove databaze ze zalohy se autoinkrement preposadi
 * a id se muze vydat znovu.
 *
 * Aplikace entitu jen namapuje (nettrine mapping na vendor/adt/exporter/src/
 * Model/Entities) - zadna vlastni trida, zadny trait.
 */
#[Entity]
#[Table(name: 'export_log')]
// "co vsechno se s timhle exportem delo" je nejcastejsi dotaz nad tabulkou -
// jeden export ma jedno zadani a k nemu i mnoho stazeni
#[Index(columns: ['export_id'])]
final class ExportLog
{
	use ActorSnapshotTrait;

	#[Id]
	#[Column(type: Types::BIGINT, options: ['unsigned' => true])]
	#[GeneratedValue]
	private ?int $id = null;

	/**
	 * ZADANI exportu: data byla vybrana a soubor vznikl.
	 *
	 * @param array $sections viz $sections
	 */
	public static function exported(
		DateTimeImmutable $createdAt,
		string $identifier,
		array $sections,
		int $rowCount,
		?string $recipientEmail,
		?int $exportId,
		?ExportActor $actor,
	): self {
		return new self(
			action: ExportLogAction::EXPORT,
			createdAt: $createdAt,
			identifier: $identifier,
			rowCount: $rowCount,
			exportId: $exportId,
			sections: $sections,
			recipientEmail: $recipientEmail,
			fileName: null,
			actor: $actor,
		);
	}

	/** VYDANI souboru - okamzik, kdy data opustila system. */
	public static function downloaded(
		DateTimeImmutable $createdAt,
		string $identifier,
		int $rowCount,
		?int $exportId,
		?string $fileName,
		?ExportActor $actor,
	): self {
		return new self(
			action: ExportLogAction::DOWNLOAD,
			createdAt: $createdAt,
			identifier: $identifier,
			rowCount: $rowCount,
			exportId: $exportId,
			sections: null,
			recipientEmail: null,
			fileName: $fileName,
			actor: $actor,
		);
	}

	/**
	 * Konstruktor je PRIVATNI: kazda akce ma jinou sadu smysluplnych poli,
	 * takze se zaznamy vytvareji pojmenovanymi konstruktory vyse. Nullable
	 * sloupce tim nejsou volnou pozvankou vyplnit u stazeni sekce.
	 */
	private function __construct(
		#[Column(enumType: ExportLogAction::class)]
		private readonly ExportLogAction $action,
		/**
		 * VZDY V UTC - Exporter sem UTC cas zapisuje a nic ho pres Doctrinu
		 * necte (auditni zaznam je write-only, cte ho mover pres SQL).
		 *
		 * Getter zamerne NEEXISTUJE: obycejny datetime_immutable sice UTC
		 * spravne zapise (format() bere zonu z objektu), ale pri hydrataci
		 * dosadi defaultni zonu aplikace, takze precteny okamzik by byl
		 * o offset vedle - a tise, bez chyby. Kdyby cteni z PHP nekdy bylo
		 * potreba, musi se soucasne zavest UTC Doctrine typ.
		 */
		#[Column]
		private readonly DateTimeImmutable $createdAt,
		#[Column]
		private readonly string $identifier,
		#[Column(type: Types::BIGINT, options: ['unsigned' => true])]
		private readonly int $rowCount,
		/**
		 * Korelacni klic: id provozniho zaznamu, ke kteremu udalost patri.
		 * Podle nej se spoji zadani exportu se vsemi jeho stazenimi.
		 * Provozni radek muze casem zaniknout, jako korelacni token hodnota
		 * plati dal.
		 */
		#[Column(type: Types::BIGINT, options: ['unsigned' => true], nullable: true)]
		private readonly ?int $exportId,
		/**
		 * Sekce: [{name, entityClass|null, fields, ids|null, rows|null, dql|null, parameters|null}]
		 *
		 * JEDINY zdroj pravdy o tom, co odeslo a podle ceho: dql + parameters
		 * se vytahuji ze skutecne spustene query, ids jsou jeji vysledek,
		 * fields rikaji, ktera pole entity soubor obsahoval. Zadny popis
		 * selekce dodany volajicim se neuklada - nesel by overit.
		 *
		 * Rendrovaci vystroj sloupcu (tridy, preklady popisku) je PROVOZNI
		 * vec a zustava na Export - o odeslanych datech nevypovida nic.
		 *
		 * Vyplneno jen u akce EXPORT.
		 */
		#[Column(type: 'json', nullable: true)]
		private readonly ?array $sections,
		/**
		 * KAM data odesla (prijemce); jina informace nez akter, ktery export
		 * spustil. Vyplneno jen u akce EXPORT.
		 */
		#[Column(nullable: true)]
		private readonly ?string $recipientEmail,
		/** ktery soubor byl vydan; vyplneno jen u akce DOWNLOAD */
		#[Column(nullable: true)]
		private readonly ?string $fileName,
		?ExportActor $actor,
	) {
		$this->setActor($actor);
	}

	public function getId(): ?int { return $this->id; }
	// getCreatedAt() zamerne neni - viz komentar u vlastnosti
	public function getAction(): ExportLogAction { return $this->action; }
	public function getIdentifier(): string { return $this->identifier; }
	public function getSections(): ?array { return $this->sections; }
	public function getRowCount(): int { return $this->rowCount; }
	public function getRecipientEmail(): ?string { return $this->recipientEmail; }
	public function getExportId(): ?int { return $this->exportId; }
	public function getFileName(): ?string { return $this->fileName; }
}
