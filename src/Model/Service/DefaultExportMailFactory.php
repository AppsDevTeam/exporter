<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

use ADT\Exporter\Model\Entities\ExportLog;
use Nette\Mail\Message;

/** Fallback pro projekty bez vlastni sablony - prepis vlastni sluzbou. */
final class DefaultExportMailFactory implements ExportMailFactory
{
	public function create(ExportLog $log, string $downloadLink): Message
	{
		$message = new Message();
		$message->addTo($log->getEmail());
		$message->setSubject('Export je pripraven / Your export is ready');
		$message->setBody("Soubor ke stazeni / download:\n" . $downloadLink);
		return $message;
	}
}
