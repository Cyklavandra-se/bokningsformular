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
  - `leverantor`: fritext, t.ex. `Eurobike`, `S-Cape` (namnen kommer från arket Booking Codes).
  - `kommentar` (dold på adminsidan, `SHOW_COMMENT = false`, men sparas via ett dolt fält och följer med i exporten) och `klar` (visas som **Klar** i admin och CSV; importen läser kolumnen Klar eller Kollad).
- **Reskoden för det aktuella formuläret** hämtas vid visning från `WPCF7_ContactForm::get_current()->shortcode_attr('tripcode')`. Vid inskick hämtas den från `$_POST['tripcode']` (det dolda fältet). Sidans ID, som används för att gissa typen, kommer från `$_POST['_wpcf7_container_post']` eller `get_the_ID()`.
- **Filtret `wpcf7_form_tag`** körs både vid visning och vid validering:
  - `cykel-N` och `hjalm-N`: tar bort alternativ **efter position** (se nedan) och bygger om `WPCF7_Pipes`. När bara ett alternativ återstår tas `include_blank` bort, så att det alternativet blir förvalt.
  - Vandring: `langd-N`, `cykel-N` och `hjalm-N` blir frivilliga genom att `*` tas bort från type och basetype.
  - Försäkringar som resan saknar blir frivilliga.
- **Filtret `wpcf7_form_elements`** tar bort `<div class="… cvb-cykel …">` vid vandring, och `<div class="cvb-stold">` och `<div class="cvb-assistans">` när försäkringen saknas. Regexen förutsätter att de diven **inte har nästlade div**.
- **Adminsidan** heter *Bokningsformulär* och ligger under toppmenyn *Cykla&Vandra* (sidans slug `bokningsformular`, så `admin.php?page=bokningsformular` fungerar som förut). Den hittar resor genom att söka igenom `post_content` efter `tripcode=`, och avkodar även Avadas base64-kodade `[fusion_code]`-block. Där finns också CSV-import (matchar resor via Reskod, Webbsida med `url_to_postid` eller titel) och CSV-export.
- **Standardläge** för en cykelresa utan inställning: vanlig cykel, elcykel och egen cykel, hyrhjälm, inga försäkringar.

- **Eurofun:** reskoder som börjar med landskod och bindestreck (`is_eurofun()`, `/^[A-Z]{2}-/`) tillhör Eurofun-gruppen: Eurobike, Radweg-Reisen och Velociped (cykel) samt Eurohike (vandring). Det stämmer med arket Booking Codes. De ska ha `sportig` = 1 och `hjalm` = `nej`.
- **Ingen hjälmhyra:** Eurobike, Radweg-Reisen och Velociped (`NO_HELMET_SUPPLIERS`, `supplier_without_helmet()`, jämförs med `norm()`) ger `hjalm` = `nej`.
- **S-Cape i Frankrike:** hjälmen ingår (`is_scape_france()`: reskod `FRSB…`/`FRSW…`, eller leverantören S-Cape och reskod som börjar med `FR`).
- `helmet_rule( $code, $supplier )` samlar hjälmreglerna och ger `ingar`, `nej` eller `''`.
- Reglerna tillämpas i `defaults( $code )`, vid CSV-import och i engångsändringen `migrate()` (`cvb_schema`: 2 = sportig, 3 = ingen hjälmhyra, 4 = S-Cape Frankrike). Efteråt går valen att ändra manuellt; de tvingas inte vid sparning.
- **Import** skriver bara över kolumner som finns i filen (utgår från `settings_for()`). Rubrikerna `Tripcode`, `Vår hemsida` och `Resans namn` i arket Booking Codes fungerar som `Reskod`, `Webbsida` och `Resa`. En reskod som inte finns i någon kortkod sparas inte utan rapporteras.
- **Reskoder kan innehålla mellanslag och andra tecken** (`Trip 07-06`, `Trip: 08-08`, `Kattegattleden 4 nätter`, `Mjölkvägen & Rallarvägen`). `extract_codes()` läser hela värdet inom citattecken, och alla uppslag går via `normalize_code()` (avkodar `&amp;` och kör `sanitize_text_field`). Värdet som skickas i mailen ändras inte.
- **Adminsidan** filtrerar på typ (standard: Cykel) längst till vänster, före sökrutan. Resans namn går till redigeringssidan, och pilen ↗ öppnar resans sida i en ny flik. Filtret använder typen som var sparad när sidan laddades (`data-typ`).
- **Tabellens kolumner** (Pär, 2026-11-18): Typ · Leverantör/Reskod · Resa · Vanlig cykel · Elcykel · Sportig cykel · Egen cykel · Stöld · Assistans · (Kommentar) · Hjälm · Klar. Typ visas som en rund knapp med C (blå) eller V (grön). Klick öppnar en liten meny med Cykel och Vandring, och valet sparas i ett dolt fält `cvb[kod][typ]`. Leverantören är ett textfält utan ram ovanför reskoden.

## Språk och mail (måste alltid fungera)

- Allt ska fungera med **WPML**: inställningarna gäller per reskod, så den svenska och den norska sidan styrs av samma rad. Alternativen styrs efter position, så översatta formulär fungerar.
- **Mailet till oss ska alltid vara på svenska.** Det löser Code Snippet *Bokningsformulär - alltid på svenska till oss*, inte tillägget. Tillägget får inte ändra fältnamn, värden eller pipes (`Etikett|värde`) på ett sätt som bryter den snippeten. `reduce_options()` behåller råvärdena och bygger om `WPCF7_Pipes` av dem.
- **Bekräftelsemailet till kunden ska vara på samma språk som formuläret skickades från.**
- Tillägget rör inte mailen alls. Ändringar som påverkar inskick eller fältvärden måste provas med både svenskt och norskt formulär.

## Uppdateringar (`updater.php`)

- Huvudet har `Update URI: https://github.com/cyklavandra-se/bokningsformular`, och uppdateraren kopplar in sig på `update_plugins_github.com` (WP 5.8+). Den hämtar senaste versionen i tre steg och sparar svaret i transienten `cvb_github_release` i sex timmar (misslyckanden en timme): 1) GitHubs API `releases/latest` (ger även ändringslogg, men bara 60 anonyma anrop i timmen per IP, vilket ett delat webbhotell kan slå i), 2) omdirigeringen från `github.com/…/releases/latest` till `/releases/tag/vX.Y.Z`, 3) `Version:` i `bokningsformular.php` på `main` via raw.githubusercontent.com. Zip-filen hämtas från `github.com/…/releases/download/vX.Y.Z/bokningsformular.zip`. Misslyckas allt visar "Sök efter uppdatering" vad varje steg svarade. (Molnsessionen når bara API:et, så steg 2 och 3 är bara testade med stubbar.)
- Releasen måste ha bilagan `bokningsformular.zip` med mappen `bokningsformular/` i roten. `.github/workflows/release.yml` körs vid varje push till `main`: den läser `Version:`, och om releasen `vX.Y.Z` inte finns bygger den zip-filen med `git archive` och skapar tagg och release med `gh release create`. Finns releasen men saknar zip-filen laddas den upp. (Molnsessioner får inte pusha taggar, därför skapas de av Actions.)
- `plugins_api` ger rutan "Visa detaljer" (releasetexten blir ändringslogg). `upgrader_source_selection` döper om den uppackade mappen till den installerade mappens namn.
- Ikoner: `assets/icon.svg` och PNG i 128 och 256 px (uppdateringslistan). `icon.svg` används också på översiktssidan under Cykla&Vandra.
- **Ny version:** höj `Version:` och pusha till `main`. Inga taggar för hand.

## Menyn Cykla&Vandra (`cyklavandra-meny.php`)

- Gemensam toppmeny för alla Cykla & Vandras egna tillägg (Pär, 2026-11-18). Filen kopieras **oförändrad** till varje tillägg, t.ex. AI-sök, och laddas med `require_once`.
- Klassen `Cyklavandra_Meny` (skyddad med `class_exists`) registrerar toppmenyn på `admin_menu` med prioritet 5, men bara om ingen annan redan gjort det (`$admin_page_hooks['cyklavandra']`). Slug `cyklavandra`, titel `Cykla&Vandra`, behörighet `edit_pages`, position 3 (under Kontrollpanelen), ikon med fjäll och sol (inbäddad SVG).
- Översiktssidan (undermenyn "Översikt") listar verktyg från filtret `cyklavandra_verktyg`: `titel`, `beskrivning`, `url`, `version`, `ikon`.
- Tilläggen lägger sig under med `add_submenu_page( Cyklavandra_Meny::SLUG, … )` på `admin_menu` (standardprioritet 10). Användningen står överst i filen.
- Ändras filen: höj `VERSION` och lägg in samma kopia i alla tillägg. Den kopia som laddas först vinner.

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
- Sportig cykel finns på alla Eurofun-cykelresor (reskod som börjar med landskod och bindestreck).
- Eurobike, Radweg-Reisen och Velociped hyr aldrig ut hjälm: alla deras resor har `nej` (Pär, 2026-11-18). Arket Booking Codes, fliken Leverantörer, har kolumnen "Ingen hjälmhyra".
- S-Capes resor i Frankrike har hjälm som ingår (Pär, 2026-11-18).
- Det finns resor där man bara kan välja elcykel.
- Vandringsformulären har inga egna fält. Det är samma formulär, men utan längd, cykel och hjälm.

## Tester

`php tests/test-logik.php`, `php tests/test-import.php` och `php tests/test-eurobike.php` (Eurofun-regler, leverantör, import av Booking Codes, reskoder med mellanslag) testar logiken med stubbar för WordPress och CF7. Det finns ingen riktig WordPress-miljö i repot.

## Status (2026-11-18)

- v1.0.0 är skriven men **inte testad på sajten** än. v1.1.x lägger till uppdateringar via GitHub. Pär har installerat 1.1.0 manuellt; 1.1.1 lägger till länkarna Inställningar, Sök efter uppdatering och Visa detaljer i listan över tillägg. 1.2.0: typfilter (standard Cykel), Klar hette Kollad, Eurobike får sportig cykel. 1.3.0: Leverantör, ingen hjälmhyra för Eurobike/Radweg-Reisen/Velociped, hjälm ingår för S-Cape i Frankrike, länk till resans sida, Kollad heter Klar igen, Kommentar dold, import av Booking Codes, rättad läsning av reskoder med mellanslag. Pär installerade 1.3.0 manuellt ("Uppdatera nu" fungerade inte). 1.3.1: tydligare felmeddelande. Med 1.3.0 gav "Sök efter uppdatering" fel trots att releasen fanns, troligen för att sajtens server inte når GitHubs API (begränsning eller blockering). 1.3.2: reservvägar via github.com och raw.githubusercontent.com. Den egentliga orsaken var att repot var **privat** (API:et svarade bara i molnsessionen, som är inloggad). Pär gjorde repot publikt 2026-11-18; uppdateraren förutsätter att det förblir publikt. 1.3.3 släpptes för att prova "Uppdatera nu", och **det fungerade**: uppdateringar via WordPress fungerar nu. 1.4.0: menyn Bokningsval heter Bokningsformulär och ligger under den gemensamma toppmenyn Cykla&Vandra. Menyn fungerar på sajten. 1.4.1: ny kolumnordning, Typ som C/V-knapp, leverantör ovanför reskoden.
- Nästa steg för Pär:
  1. Installera tillägget och läs in CSV-filen från arket över cykelresorna.
  2. Klistra in mallen i ett cykelformulär och ett vandringsformulär och provboka.
  3. Lägg sedan in mallen i övriga formulär, inklusive de norska.
- Saker att kontrollera på sajten:
  - Att `wpcf7_form_tag` påverkar valideringen i den CF7-version som är installerad.
  - Att CF7:s autop inte lägger in `<div>` i `cvb-`-diven.
  - Att alla vandringsresor har en `tripcode`.
  - Mailmallen visar tomma rader för längd, cykel och hjälm på vandringsbokningar. Det går att dölja dem med `[group]` i mailen om det behövs.
