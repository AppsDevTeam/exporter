<?php

declare(strict_types=1);

use ADT\Exporter\Model\Service\ExportSection;
use Doctrine\ORM\QueryBuilder;
use Tester\Assert;

/**
 * ExportSection::isRawRows() rozlisuje zdroje sekce.
 *
 * Trida je readonly, takze se v ni na $source nesmi volat funkce, ktere berou pole referenci
 * (reset(), end(), array_shift(), ...) - PHP to zahlasi jako "Cannot indirectly modify readonly
 * property" a shodi kazdou sekci se zdrojem v poli, tedy raw radky i seznam ID.
 */

require __DIR__ . '/bootstrap.php';

// Radky ve tvaru, ktery vraci agregacni dotazy aplikace (sales summary apod.).
function summaryRows(): array
{
	return [
		['productId' => 63365, 'name' => 'Doplnkovy prodej 12%', 'quantity' => 656.0, 'price' => 26224.0],
		['productId' => 8492, 'name' => 'DP 12% JIDLO', 'quantity' => 297.0, 'price' => 10709.0],
	];
}


test('pole poli je rozpoznano jako raw radky', function () {
	Assert::true(new ExportSection('summary', summaryRows(), [])->isRawRows());
});


test('raw radky s nespojitymi klici jsou porad raw radky', function () {
	$rows = summaryRows();
	unset($rows[0]);

	Assert::true(new ExportSection('summary', $rows, [])->isRawRows());
});


test('pole skalaru je seznam ID, ne raw radky', function () {
	Assert::false(new ExportSection('items', [12, 34, 56], [], 'App\Entity\OrderItem')->isRawRows());
});


test('prazdne pole nejsou raw radky', function () {
	Assert::false(new ExportSection('items', [], [], 'App\Entity\OrderItem')->isRawRows());
});


test('QueryBuilder neni raw radky', function () {
	// QueryBuilder chce v konstruktoru EntityManager, ktery tady k nicemu nepotrebujeme -
	// isRawRows() se na nearray zdroji utne uz na is_array().
	$qb = new ReflectionClass(QueryBuilder::class)->newInstanceWithoutConstructor();

	Assert::false(new ExportSection('items', $qb, [])->isRawRows());
});
