# ADT Exporter

Jednotne hrdlo vsech exportu dat: auditni zaznam (`export_log`) + synchronni
download nebo background zpracovani s dorucenim e-mailem.

## Proc

Auditni pozadavek "kdo, kdy a co presne exportoval" musi platit pro KAZDY
export - grid, formular i konzolovy prikaz. Misto per-misto logovani vola
vsechno jednu funkci; zaznam vznika vzdy a ve stejne transakci jako pripadny
background job (outbox garance adt/background-queue).

## Pouziti

```php
$log = $this->exporter->export(new ExportRequest(
    identifier: 'smart-cards',        // typ exportovanych dat (audit)
    entityClass: SmartCard::class,
    ids: $ids,                        // presna enumerace radku (audit)
    columns: ['number' => 'Cislo', ...],
    generator: $excelGenerator,       // sluzba implementujici ExportFileGenerator
    email: $user->getEmail(),
    filters: $filterState,            // stav filtru (audit)
));

if (!$log->isInBackground()) {
    $this->sendResponse(new FileResponse($log->getFile()));
}
// jinak flash "export prijde e-mailem"
```

## Instalace v aplikaci

1. Entita: `class ExportLog extends BaseEntity implements ADT\Exporter\Model\Entities\ExportLog`
   s `ExportLogTrait` + vlastnimi id/createdAt/createdBy atributy; migrace.
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

## Auditni vlastnosti zaznamu

- vznika VZDY, i pro maly synchronni download
- `ids` = presny vycet exportovanych radku (silnejsi nez SQL/filtry)
- `filters` + `identifier` = citelny kontext pro auditora
- zaznam se po vytvoreni needituje (jen doplneni file/processedAt); dlouhodobou
  retenci resi mover do auditniho uloziste (viz projektova infrastruktura)
