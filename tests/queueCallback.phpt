<?php

declare(strict_types=1);

use ADT\Exporter\Model\Service\Exporter;
use Tester\Assert;

/**
 * Podpis queue callbacku musi sedet na klice, pod kterymi se job publikuje.
 *
 * adt/background-queue na PHP 8 rozbaluje parametry POJMENOVANE:
 *
 *   $callback(...$entity->getParameters());
 *
 * Publikuje se `['exportId' => $id]`, takze se vola `processExport(exportId: $id)`. Kdyz se
 * parametr jmenuje jinak, PHP hodi "Unknown named parameter $exportId" - a fronta tuhle chybu
 * navic vyhodnoti jako opakovatelnou, takze job zustane viset ve stavu 4 a zkousi se donekonecna.
 * Presne to se stalo: export se nikdy nezpracoval a job nasbiral 29 pokusu.
 *
 * Test cte podpis reflexi, protoze nazev parametru nejde odvodit z konstanty - u pojmenovanych
 * argumentu je zdrojem pravdy doslovny nazev v signature.
 */

require __DIR__ . '/bootstrap.php';


test('processExport bere prave ty parametry, pod jakymi se job publikuje', function () {
	$parameters = new ReflectionMethod(Exporter::class, 'processExport')->getParameters();

	Assert::same(
		[Exporter::PARAM_EXPORT_ID],
		array_map(fn (ReflectionParameter $p): string => $p->getName(), $parameters),
	);
});


test('exportId je cislo, ne pole - fronta predava skalar', function () {
	$parameter = new ReflectionMethod(Exporter::class, 'processExport')->getParameters()[0];

	Assert::same('int', (string) $parameter->getType());
});
