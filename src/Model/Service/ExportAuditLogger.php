<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

use DateTimeImmutable;

/**
 * Zapisovac auditni stopy exportu. Implementaci dodava aplikace nebo
 * nadrazeny balicek (typicky adt/fancyadmin, ktery vlastni jednotnou
 * tabulku audit_log) - knihovna tak nemusi mit vlastni entitu ani vedet,
 * kam se audit uklada.
 *
 * SLUZBA JE POVINNA. Nema nulovou implementaci zamerne: cely invariant
 * exportu je "zadny export bez auditu", takze tichy no-op by ho zrusil
 * a nikdo by si toho nevsiml. Bez implementace neprojde kompilace
 * kontejneru - hlasite selhani ve spravny okamzik.
 *
 * Rozhrani je zamerne jen ze SKALARU A POLI: implementace nemusi znat
 * typy teto knihovny, takze na ni nemusi zaviset.
 */
interface ExportAuditLogger
{
	/** zadani exportu - data byla vybrana a soubor vznikl */
	public const string ACTION_EXPORT = 'export';

	/** vydej souboru - okamzik, kdy data opustila system */
	public const string ACTION_DOWNLOAD = 'download';

	/**
	 * Zapise auditni udalost.
	 *
	 * @param string $action ACTION_* konstanta
	 * @param DateTimeImmutable $createdAt v UTC
	 * @param string|null $correlationId spojuje udalosti tehoz exportu
	 * @param array{id: string|null, label: string|null, data: array, ip: string|null, userAgent: string|null} $actor
	 *        snapshot aktera; prazdne hodnoty pro kontext bez uzivatele (cron)
	 * @param array $payload obsah dle akce - identifier, rowCount, a dale
	 *        sections/recipientEmail u zadani, fileName u vydeje
	 * @param bool $detached TRUE = zapsat vlastnim spojenim mimo probihajici
	 *        transakci (zaznam prezije rollback). Export potrebuje NAOPAK
	 *        atomicitu s vlastni transakci, takze necha FALSE.
	 */
	public function log(
		string $action,
		DateTimeImmutable $createdAt,
		?string $correlationId,
		array $actor,
		array $payload,
		bool $detached = false,
	): void;
}
