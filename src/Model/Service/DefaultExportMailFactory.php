<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

use ADT\Exporter\Model\Entities\Export;
use Nette\Mail\Message;

/** Fallback pro projekty bez vlastni sablony - prepis vlastni sluzbou. */
final class DefaultExportMailFactory implements ExportMailFactory
{
	public function create(Export $export, string $downloadLink): Message
	{
		$message = new Message();
		$message->addTo($export->getEmail());
		$message->setSubject('Export je pripraven / Your export is ready');
		$message->setBody("Soubor ke stazeni / download:\n" . $downloadLink);
		return $message;
	}
}
