<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

/** Snapshot aktera pro auditni zaznam - hodnoty v okamziku akce. */
final readonly class ExportActor
{
	public function __construct(
		public int|string|null $id,
		public ?string $name,
		public ?string $email,
	) {}
}
