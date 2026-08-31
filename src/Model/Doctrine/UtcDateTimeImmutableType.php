<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use DateTimeZone;

/**
 * DATETIME vzdy v UTC.
 *
 * MySQL DATETIME zadny offset neuklada, takze bezny datetime_immutable
 * zapisuje lokalni nastenny cas aplikace. Auditni zaznam se ale koreluje
 * s logy jinych systemu (a odjizdi do SIEM), kde je to dvakrat na skodu:
 * cas sedi o offset vedle a pri prechodu na zimni cas je jedna hodina
 * v roce nejednoznacna, protoze 2:30 nastane dvakrat.
 *
 * Tento typ prevadi na UTC pri zapisu a cte zpatky JAKO UTC - bez toho by
 * se ulozena hodnota pri cteni vylozila v lokalni zone a posunula se.
 *
 * Registrace (nettrine/dbal):
 *     dbal: connection: types: utc_datetime_immutable: ADT\Exporter\Model\Doctrine\UtcDateTimeImmutableType
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
	public const string NAME = 'utc_datetime_immutable';

	private static ?DateTimeZone $utc = null;

	public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
	{
		if ($value === null) {
			return null;
		}

		if ($value instanceof DateTimeImmutable) {
			return $value->setTimezone(self::utc())->format($platform->getDateTimeFormatString());
		}

		throw InvalidType::new($value, static::class, ['null', DateTimeImmutable::class]);
	}

	public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
	{
		if ($value === null || $value instanceof DateTimeImmutable) {
			return $value;
		}

		$converted = DateTimeImmutable::createFromFormat(
			$platform->getDateTimeFormatString(),
			(string) $value,
			self::utc(),
		);

		if ($converted === false) {
			throw InvalidFormat::new((string) $value, static::class, $platform->getDateTimeFormatString());
		}

		return $converted;
	}

	private static function utc(): DateTimeZone
	{
		return self::$utc ??= new DateTimeZone('UTC');
	}
}
