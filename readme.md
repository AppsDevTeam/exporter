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

1. Entita `Export` (ExportTrait + vlastni id/createdBy atributy) v aplikaci;
   `ExportLog` je FINALNI entita knihovny - staci pridat mapping
   (`vendor/adt/exporter/src/Model/Entities`) a migraci. Volitelne vlastni
   `ExportFileStorage` (soubory pres aplikacni File ekosystem) a
   `ExportActorProvider` (akter auditu ze SecurityUser) - obe sluzby si
   extension najde podle typu.
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

## Akter auditu

Kazdy projekt identifikuje uzivatele jinak - nekde jmeno a e-mail, jinde jen
prihlasovaci jmeno, servisni ucet nebo API klic. Knihovna proto nepredepisuje
zadna konkretni pole a uklada:

| sloupec | k cemu |
|---|---|
| `created_by_id` | klic aktera ve zdrojovem systemu - spojovaci klic auditu (podle nej se v auditnim ulozisti joinuje napric logy) |
| `created_by_label` | jedno lidsky citelne oznaceni - aby sel log cist bez znalosti tvaru `created_by` |
| `created_by` | JSON: cimkoliv dalsim projekt aktera identifikuje |

Ploche jsou tedy jen ty dve veci, ktere maji smysl v kazdem systemu; promenna
cast jde do JSONu. Aplikace dodava `ExportActorProvider`:

```php
public function getActor(): ?ExportActor
{
    if (!$this->securityUser->isLoggedIn()) {
        return null;    // cron, konzument fronty, CLI
    }
    $identity = $this->securityUser->getIdentity();
    return new ExportActor(
        id: $identity->getId(),
        label: $identity->getName() ?: $identity->getEmail(),
        data: ['name' => $identity->getName(), 'email' => $identity->getEmail()],
    );
}
```

## E-mail

Obsah e-mailu vlastni projekt: implementuj `ExportMailFactory` jako sluzbu
(preklady, Latte sablona, branding) - extension ji pouzije automaticky misto
vestaveneho defaultu. Background job ji dostane z DI, nic se nepredava.

## Bezpecnost stahovani

Odkaz v e-mailu NEVEDE na soubor, ale na aplikacni routu (`downloadLink`).
Soubor lezi ve `fileDir` MIMO docroot - jedina cesta k nemu je pres presenter,
ktery MUSI overit prihlaseni a vlastnictvi:

```php
public function actionExport(int $id): void
{
    $export = $this->exportQueryFactory->create()->byId($id)->fetchOneOrNull();
    if (!$export || !$export->getFile()) {
        $this->error();
    }
    // stahnout smi jen autor exportu (pripadne rozsirit o admin ACL)
    if ($export->getCreatedBy()?->getId() !== $this->securityUser->getId()) {
        $this->error('', \Nette\Http\IResponse::S403_Forbidden);
    }
    $this->sendResponse(new FileResponse($export->getFile()->getPath(), $export->getFileName()));
}
```

Overeni vlastnictvi je PROVOZNI vec, proto FK `created_by` na aplikacniho
uzivatele zustava na `Export` - narozdil od auditu, ktery zadnou relaci nema.

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
- zaznam je NEMENNY (final entita bez setteru) a aktera nese DENORMALIZOVANE
  (snapshot v okamziku akce, zadna FK relace) - nezavisi na zbytku databaze
  a prezije odvoz do externiho auditniho uloziste
- provozni beh na auditni zaznam NIKDY nesaha (po odvozu moverem tu neni);
  vse, co potrebuje background regenerace, je na `Export`
- selekce KAZDE sekce se materializuje V OKAMZIKU volani: entity sekce nesou
  presny vycet ID + DQL s parametry, agregatove sekce primo snapshot radku
  (query nejde serializovat do jobu a pozdejsi prehrani by neodpovidalo
  dorucenemu souboru)
- `filters` + `identifier` = citelny kontext pro auditora, `recipientEmail`
  odpovida na "kam data odesla" (jina otazka nez kdo export spustil)
- zaznam se po vytvoreni needituje (jen doplneni file/processedAt); dlouhodobou
  retenci resi mover do auditniho uloziste (viz projektova infrastruktura)
