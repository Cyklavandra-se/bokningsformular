# bokningsformular – Cykla & Vandra

WordPress-tillägg för cyklavandra.se. Det låter **ett** bokningsformulär i Contact Form 7 användas för alla självguidade cykel- och vandringsresor. Tidigare fanns ett formulär för varje kombination av cykel, hjälm och försäkring.

Ägare: Pär. Svara på svenska. Kod och kommentarer på svenska där det redan är så.

## Sajten

- WordPress med Avada (sidbyggare), Contact Form 7, **Flamingo** (sparar alla inskick) och **Conditional Fields for CF7** (används redan för resenär 2–8 och fakturaadresser via `[group]`).
- CF7 Dynamic Text Extension: `[dynamichidden your-subject "CF7_get_post_var key='title'"]`.
- **WPML**: svenska är standardspråket, och de norska sidorna ligger under `/no/`.
- Code Snippets används för egen PHP. Där finns troligen redan ett filter som släpper igenom `tripcode` i kortkoden. Tillägget lägger till samma filter, vilket är ofarligt.
- Resesidorna har kortkoder som `[contact-form-7 id="2b3b45c" title="…" tripcode="DE-MORTK-04A"]`.
- **Bokningsmailen startar hela bokningsprocessen.** Fältnamnen får inte ändras, och mailen och Flamingo måste fungera som förut.

## Så fungerar tillägget (`bokningsformular.php`)

- Inställningarna sparas i optionen `cvb_settings`, en array där nyckeln är **reskoden** (inte post-ID). Därför delar den svenska och den norska sidan samma inställning.
- Varje rad har:
  - `typ`: `cykel` eller `vandring`. Är den tom gissas typen utifrån adressen, där `/vandra/` ger vandring. Gissningen görs på den svenska originalsidan via `wpml_object_id`.
  - `hjalm`: `hyra`, `ingar` eller `nej`.
  - kryssrutor: `vanlig`, `el`, `sportig`, `egen`, `stold`, `assistans`.
  - `kommentar` och `klar` (visas som **Kollad** i admin och CSV; importen läser kolumnen Kollad eller Klar).
- **Reskoden för det aktuella formuläret** hämtas vid visning från `WPCF7_ContactForm::get_current()->shortcode_attr('tripcode')`. Vid inskick hämtas den från `$_POST['tripcode']` (det dolda fältet). Sidans ID, som används för att gissa typen, kommer från `$_POST['_wpcf7_container_post']` eller `get_the_ID()`.
- **Filtret `wpcf7_form_tag`** körs både vid visning och vid validering:
  - `cykel-N` och `hjalm-N`: tar bort alternativ **efter position** (se nedan) och bygger om `WPCF7_Pipes`. När bara ett alternativ återstår tas `include_blank` bort, så att det alternativet blir förvalt.
  - Vandring: `langd-N`, `cykel-N` och `hjalm-N` blir frivilliga genom att `*` tas bort från type och basetype.
  - Försäkringar som resan saknar blir frivilliga.
- **Filtret `wpcf7_form_elements`** tar bort `<div class="… cvb-cykel …">` vid vandring, och `<div class="cvb-stold">` och `<div class="cvb-assistans">` när försäkringen saknas. Regexen förutsätter att de diven **inte har nästlade div**.
- **Adminsidan** ligger i menyn *Bokningsval* (slug `bokningsformular`). Den hittar resor genom att söka igenom `post_content` efter `tripcode=`, och avkodar även Avadas base64-kodade `[fusion_code]`-block. Där finns också CSV-import (matchar resor via Reskod, Webbsida med `url_to_postid` eller titel) och CSV-export.
- **Standardläge** för en cykelresa utan inställning: vanlig cykel, elcykel och egen cykel, hyrhjälm, inga försäkringar.

- **Eurobike:** reskoder som börjar med landskod och bindestreck (`/^[A-Z]{2}-/`, t.ex. `DE-`, `IT-`) är Eurobike och ska ha `sportig` = 1. Det sker i `defaults( $code )`, vid CSV-import och i engångsändringen `migrate()` (optionen `cvb_schema` = 2). Efter det går rutan att kryssa ur manuellt.
- **Adminsidan** filtrerar på typ (standard: Cykel) bredvid sökrutan. Filtret använder typen som var sparad när sidan laddades (`data-typ`).

## Språk och mail (måste alltid fungera)

- Allt ska fungera med **WPML**: inställningarna gäller per reskod, så den svenska och den norska sidan styrs av samma rad. Alternativen styrs efter position, så översatta formulär fungerar.
- **Mailet till oss ska alltid vara på svenska.** Det löser Code Snippet *Bokningsformulär - alltid på svenska till oss*, inte tillägget. Tillägget får inte ändra fältnamn, värden eller pipes (`Etikett|värde`) på ett sätt som bryter den snippeten. `reduce_options()` behåller råvärdena och bygger om `WPCF7_Pipes` av dem.
- **Bekräftelsemailet till kunden ska vara på samma språk som formuläret skickades från.**
- Tillägget rör inte mailen alls. Ändringar som påverkar inskick eller fältvärden måste provas med både svenskt och norskt formulär.

## Uppdateringar (`updater.php`)

- Huvudet har `Update URI: https://github.com/cyklavandra-se/bokningsformular`, och uppdateraren kopplar in sig på `update_plugins_github.com` (WP 5.8+). Den läser `releases/latest` från GitHubs API (publikt repo, ingen token) och sparar svaret i transienten `cvb_github_release` i sex timmar.
- Releasen måste ha bilagan `bokningsformular.zip` med mappen `bokningsformular/` i roten. `.github/workflows/release.yml` körs vid varje push till `main`: den läser `Version:`, och om releasen `vX.Y.Z` inte finns bygger den zip-filen med `git archive` och skapar tagg och release med `gh release create`. Finns releasen men saknar zip-filen laddas den upp. (Molnsessioner får inte pusha taggar, därför skapas de av Actions.)
- `plugins_api` ger rutan "Visa detaljer" (releasetexten blir ändringslogg). `upgrader_source_selection` döper om den uppackade mappen till den installerade mappens namn.
- Ikoner: `assets/icon.svg` och PNG i 128 och 256 px (uppdateringslistan), samt `assets/menu-icon.svg` (adminmenyn, som base64-data-URI).
- **Ny version:** höj `Version:` och pusha till `main`. Inga taggar för hand.

## Kontraktet med formuläret (`formularmall-sv.txt`)

Alternativen styrs efter **position**, inte text. Därför kan det norska formuläret ha norska texter.

- `cykel-N`: `"Vanlig cykel" "Elcykel" "Sportig cykel" "Tar med egen"` (position 0–3 = vanlig, el, sportig, egen)
- `hjalm-N`: `"Hyr hjälm" "Tar med egen" "Hjälm ingår"` (hyra = 0 och 1, nej = 1, ingar = 2)
- Raden med längd, cykel och hjälm ligger i `<div class="resenar-rad cvb-cykel">`
- Försäkringarna `cykelforsakring` och `assistansforsakring` ligger i `cvb-stold` respektive `cvb-assistans`
- `[hidden tripcode default:shortcode_attr]` måste finnas
- **Inga priser i formuläret.** Priserna står i prislistan.

Om ordningen ändras måste `BIKE_INDEX` och `HELMET_INDEX` uppdateras.

## Affärsregler

- Stöld- och assistansförsäkring finns bara som tillval på resorna i Holland och Belgien.
- Sportig cykel finns på de flesta resor från Eurobike/Eurofun och Radweg-Reisen.
- Eurobike hyr aldrig ut hjälm, vilket motsvarar `nej`. På vissa resor ingår hjälmen, och på andra hyrs den.
- Det finns resor där man bara kan välja elcykel.
- Vandringsformulären har inga egna fält. Det är samma formulär, men utan längd, cykel och hjälm.

## Tester

`php tests/test-logik.php`, `php tests/test-import.php` och `php tests/test-eurobike.php` testar logiken med stubbar för WordPress och CF7. Det finns ingen riktig WordPress-miljö i repot.

## Status (2026-11-18)

- v1.0.0 är skriven men **inte testad på sajten** än. v1.1.x lägger till uppdateringar via GitHub. Pär har installerat 1.1.0 manuellt; 1.1.1 lägger till länkarna Inställningar, Sök efter uppdatering och Visa detaljer i listan över tillägg. 1.2.0: typfilter (standard Cykel), Klar heter Kollad, Eurobike får sportig cykel.
- Nästa steg för Pär:
  1. Installera tillägget och läs in CSV-filen från arket över cykelresorna.
  2. Klistra in mallen i ett cykelformulär och ett vandringsformulär och provboka.
  3. Lägg sedan in mallen i övriga formulär, inklusive de norska.
- Saker att kontrollera på sajten:
  - Att `wpcf7_form_tag` påverkar valideringen i den CF7-version som är installerad.
  - Att CF7:s autop inte lägger in `<div>` i `cvb-`-diven.
  - Att alla vandringsresor har en `tripcode`.
  - Mailmallen visar tomma rader för längd, cykel och hjälm på vandringsbokningar. Det går att dölja dem med `[group]` i mailen om det behövs.
