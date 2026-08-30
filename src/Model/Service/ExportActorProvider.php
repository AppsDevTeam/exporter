<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

/**
 * Dodava aktera prave probihajiciho exportu pro auditni zaznam (typicky
 * z aplikacniho SecurityUser). V kontextu bez uzivatele (cron, konzument
 * fronty) vraci null. Aplikace registruje vlastni sluzbu tohoto typu;
 * bez ni plati NullExportActorProvider.
 */
interface ExportActorProvider
{
	public function getActor(): ?ExportActor;
}
