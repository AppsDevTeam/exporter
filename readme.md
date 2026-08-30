# ADT Exporter

Jednotne hrdlo vsech exportu dat: auditni zaznam (`export_log`) + synchronni
download nebo background zpracovani s dorucenim e-mailem.

## Proc

Auditni pozadavek "kdo, kdy a co presne exportoval" musi platit pro KAZDY
export - grid, formular i konzolovy prikaz. Misto per-misto logovani vola
vsechno jednu funkci.

Provozni a auditni data jsou ODDELENA (stejny vzor jako session vs. auth log):

- **export** (provozni): ridi background regeneraci, soubor, doruceni,
  download; soubor spravuje `ExportFileStorage` (aplikace typicky vlastni
  File ekosystem); po doruceni a retenci souboru muze zaznam casem zaniknout
- **export_log** (audit): append-only "kdo/kdy/co presne" BEZ vazby na
  soubor - odvazi ho mover do dlouhodobeho auditniho uloziste

Oba zaznamy i pripadny background job vznikaji v JEDNE transakci (outbox
garance adt/background-queue).

## Pouziti

Format (CSV/Excel/...) urcuje predany generator; pocet sekci urcuje obsah
(Excel: sheet per sekce, CSV: prave jedna sekce).

```php
// jednoducha tabulka (grid):
$export = $this->exporter->export(new ExportRequest(
    identifier: 'smart-cards',
    sections: new ExportSection('items', $queryObject, ['number' => 'Cislo', ...]),
    generator: $excelGenerator,       // vs. $csvGenerator = volba formatu
    email: $user->getEmail(),
    filters: $filterState,
));

// vicesheetovy report (ruzne zdroje, vcetne agregatu bez entit):
$export = $this->exporter->export(new ExportRequest(
    identifier: 'order-payments',
    sections: [
        new ExportSection('Items', $orderItemsQb, $itemColumns),
        new ExportSection('Payments', $paymentsQb, $paymentColumns),
        new ExportSection('Summary', $summaryRows, $summaryColumns), // pole poli = snapshot primo v auditu
    ],
    generator: $excelGenerator,
    email: $user->getEmail(),
    filters: ['from' => ..., 'to' => ..., 'companies' => ...],
));

if (!$export->isInBackground()) {
    $this->sendResponse(new FileResponse($this->exporter->getFilePath($export), $export->getFileName()));
}
// jinak flash "export prijde e-mailem"
```

## Instalace v aplikaci

1. Entity: `Export` (ExportTrait) a `ExportLog` (ExportLogTrait), obe
   + vlastni id/createdBy atributy; migrace. Volitelne vlastni
   `ExportFileStorage` nad aplikacnim File ekosystemem (jinak lokalni default).
2. Neon:
   ```neon
   extensions:
       exporter: ADT\Exporter\DI\ExporterExtension
   exporter:
       syncRowLimit: 500
       fileDir: %appDir%/../data/exports
       downloadLink: ':Portal:Export:download'
   backgroundQueue:
       callbacks:
           processExport: [@exporter.exporter, processExport]
   ```
3. Generatory souboru registrovat jako sluzby (implementuji `ExportFileGenerator`,
   extension je poskytne background handleru automaticky).

## E-mail

Obsah e-mailu vlastni projekt: implementuj `ExportMailFactory` jako sluzbu
(preklady, Latte sablona, branding) - extension ji pouzije automaticky misto
vestaveneho defaultu. Background job ji dostane z DI, nic se nepredava.

## Bezpecnost stahovani

Odkaz v e-mailu NEVEDE na soubor, ale na aplikacni routu (`downloadLink`).
Soubor lezi ve `fileDir` MIMO docroot - jedina cesta k nemu je pres presenter,
ktery MUSI overit prihlaseni a vlastnictvi:

```php
public function actionDownload(int $id): void
{
    $log = $this->exportLogQueryFactory->create()->byId($id)->fetchOneOrNull();
    if (!$log || !$log->getFile()) {
        $this->error();
    }
    // stahnout smi jen autor exportu (pripadne rozsirit o admin ACL)
    if ($log->getCreatedBy()?->getId() !== $this->securityUser->getId()) {
        $this->error('', \Nette\Http\IResponse::S403_Forbidden);
    }
    $this->sendResponse(new FileResponse($log->getFile(), basename($log->getFile())));
}
```

Neprihlaseneho uzivatele posle bezny auth mechanismus aplikace na login
a po prihlaseni zpet - odkaz z e-mailu tak funguje kdykoli behem retence
souboru, ale vzdy jen pro opravneneho.

## Retence souboru

Vygenerovane soubory nesmi na disku lezet dele, nez je nutne pro doruceni
(obsahuji exportovana, casto osobni data). Denni cron:

```
0 3 * * * php bin/console exporter:purge-files
```

maze soubory starsi nez `fileRetentionDays` (default 7). Auditni zaznam
zustava po celou svou retenci - jen prijde o soubor; download expirovaneho
exportu vrati chybu.

## Auditni vlastnosti zaznamu

- vznika VZDY, i pro maly synchronni download
- selekce KAZDE sekce se materializuje V OKAMZIKU volani: entity sekce nesou
  presny vycet ID + DQL s parametry, agregatove sekce primo snapshot radku
  (query nejde serializovat do jobu a pozdejsi prehrani by neodpovidalo
  dorucenemu souboru)
- `filters` + `identifier` = citelny kontext pro auditora
- zaznam se po vytvoreni needituje (jen doplneni file/processedAt); dlouhodobou
  retenci resi mover do auditniho uloziste (viz projektova infrastruktura)
