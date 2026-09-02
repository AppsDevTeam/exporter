<?php

declare(strict_types=1);

namespace ADT\Exporter\Model\Service;

/**
 * Davkova varianta ExportFileGenerator pro exporty, ktere se nevejdou do pameti.
 *
 * Bezny ExportFileGenerator dostane vsechny entity naraz, takze spotreba pameti
 * roste s poctem radku - u velkych exportu proto padne na memory limitu. Tady
 * generator dostane misto pole entit iterator davek a zapisuje prubezne.
 *
 * Knihovna si mezi davkami cisti EntityManager, aby uvolnila nactene entity
 * i jejich navazany graf. Z toho plyne kontrakt:
 *
 *   Entity plati POUZE v ramci prave iterovane davky. Jakmile foreach postoupi
 *   na dalsi davku, predchozi entity jsou odpojene - generator si je tedy nesmi
 *   odkladat a musi si z nich rovnou vytahat skalarni data.
 *
 * POZOR: generator MUSI byt registrovatelny jako sluzba (background handler
 * ho ziska z DI podle class-string ulozeneho v Export.generator).
 */
interface BatchedExportFileGenerator
{
	/**
	 * @param array<string, array{batches: iterable<array>, columns: array}> $sections
	 *        nazev sekce => data; batches yielduje pole entit NEBO pole raw radku
	 *        (dle typu sekce), v poradi dle ulozene selekce
	 * @return string absolutni cesta k vytvorenemu souboru
	 */
	public function generateBatched(array $sections, string $identifier): string;
}
