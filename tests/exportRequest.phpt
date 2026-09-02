<?php

declare(strict_types=1);

use ADT\Exporter\Model\Service\BatchedExportFileGenerator;
use ADT\Exporter\Model\Service\ExportFileGenerator;
use ADT\Exporter\Model\Service\ExportRequest;
use ADT\Exporter\Model\Service\ExportSection;
use Tester\Assert;

/**
 * ExportRequest je jediny vstupni bod, kterym volajici zadava export, takze musi
 * prijmout kazdy typ generatoru, se kterym knihovna umi pracovat.
 *
 * Kdyz pribyl BatchedExportFileGenerator, tohle misto se zapomnelo rozsirit -
 * Exporter davkovy generator prijal, ale ExportRequest ho odmitl uz v konstruktoru
 * ("Argument #3 ($generator) must be of type ExportFileGenerator"). Projevilo se to
 * teprve na produkci: PHPStan na urovni 1 typy argumentu nekontroluje.
 */

require __DIR__ . '/bootstrap.php';

function sections(): ExportSection
{
	return new ExportSection('items', [['a' => 1]], []);
}


test('prijme bezny ExportFileGenerator', function () {
	$generator = new class implements ExportFileGenerator {
		public function generate(array $sections, string $identifier): string
		{
			return '/tmp/export.csv';
		}
	};

	$request = new ExportRequest('order-payments', sections(), $generator);

	Assert::same($generator, $request->generator);
});


test('prijme i BatchedExportFileGenerator', function () {
	$generator = new class implements BatchedExportFileGenerator {
		public function generateBatched(array $sections, string $identifier): string
		{
			return '/tmp/export.csv';
		}
	};

	$request = new ExportRequest('order-payments', sections(), $generator);

	Assert::same($generator, $request->generator);
});


test('prijme generator implementujici oba interfacy', function () {
	$generator = new class implements ExportFileGenerator, BatchedExportFileGenerator {
		public function generate(array $sections, string $identifier): string
		{
			return '/tmp/export.csv';
		}

		public function generateBatched(array $sections, string $identifier): string
		{
			return '/tmp/export.csv';
		}
	};

	Assert::same($generator, new ExportRequest('order-payments', sections(), $generator)->generator);
});


test('jedna sekce i pole sekci se normalizuji na pole', function () {
	$generator = new class implements BatchedExportFileGenerator {
		public function generateBatched(array $sections, string $identifier): string
		{
			return '/tmp/export.csv';
		}
	};

	Assert::count(1, new ExportRequest('items', sections(), $generator)->sections);
	Assert::count(2, new ExportRequest('items', [sections(), sections()], $generator)->sections);
});
