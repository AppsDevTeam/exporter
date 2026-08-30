<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

final class NullExportActorProvider implements ExportActorProvider
{
	public function getActor(): ?ExportActor
	{
		return null;
	}
}
