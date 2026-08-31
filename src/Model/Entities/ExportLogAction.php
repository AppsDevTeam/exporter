<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Entities;

/**
 * Typ auditni udalosti v export_log.
 *
 * Audit je jeden append-only stream udalosti s diskriminatorem, ne tabulka
 * per typ: udalosti maji spolecnou vetsinu sloupcu (cas, akter, identifier,
 * rowCount, korelacni export_id) a dalsi typ tak pribude bez migrace.
 * Odpovida to i tvaru, ktery ceka SIEM - viz ECS event.action.
 */
enum ExportLogAction: string
{
	/** export byl zadan - data byla vybrana a soubor vznikl */
	case EXPORT = 'export';

	/** soubor byl vydan ke stazeni - TEDY okamzik, kdy data opustila system */
	case DOWNLOAD = 'download';
}
