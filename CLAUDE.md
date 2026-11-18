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
  - `kommentar` och `klar`.
- **Reskoden för det aktuella formuläret** hämtas vid visning från `WPCF7_ContactForm::get_current()->shortcode_attr('tripcode')`. Vid inskick hämtas den från `$_POST['tripcode']` (det dolda fältet). Sidans ID, som används för att gissa typen, kommer från `$_POST['_wpcf7_container_post']` eller `get_the_ID()`.
- **Filtret `wpcf7_form_tag`** körs både vid visning och vid validering:
  - `cykel-N` och `hjalm-N`: tar bort alternativ **efter position** (se nedan) och bygger om `WPCF7_Pipes`. När bara ett alternativ återstår tas `include_blank` bort, så att det alternativet blir förvalt.
  - Vandring: `langd-N`, `cykel-N` och `hjalm-N` blir frivilliga genom att `*` tas bort från type och basetype.
  - Försäkringar som resan saknar blir frivilliga.
- **Filtret `wpcf7_form_elements`** tar bort `<div class="… cvb-cykel …">` vid vandring, och `<div class="cvb-stold">` och `<div class="cvb-assistans">` när försäkringen saknas. Regexen förutsätter att de diven **inte har nästlade div**.
- **Adminsidan** ligger i menyn *Bokningsval* (slug `bokningsformular`). Den hittar resor genom att söka igenom `post_content` efter `tripcode=`, och avkodar även Avadas base64-kodade `[fusion_code]`-block. Där finns också CSV-import (matchar resor via Reskod, Webbsida med `url_to_postid` eller titel) och CSV-export.
- **Standardläge** för en cykelresa utan inställning: vanlig cykel, elcykel och egen cykel, hyrhjälm, inga försäkringar.

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

`php tests/test-logik.php` och `php tests/test-import.php` testar logiken med stubbar för WordPress och CF7. Det finns ingen riktig WordPress-miljö i repot.

## Status (2026-11-18)

- v1.0.0 är skriven men **inte testad på sajten** än.
- Nästa steg för Pär:
  1. Installera tillägget och läs in CSV-filen från arket över cykelresorna.
  2. Klistra in mallen i ett cykelformulär och ett vandringsformulär och provboka.
  3. Lägg sedan in mallen i övriga formulär, inklusive de norska.
- Saker att kontrollera på sajten:
  - Att `wpcf7_form_tag` påverkar valideringen i den CF7-version som är installerad.
  - Att CF7:s autop inte lägger in `<div>` i `cvb-`-diven.
  - Att alla vandringsresor har en `tripcode`.
  - Mailmallen visar tomma rader för längd, cykel och hjälm på vandringsbokningar. Det går att dölja dem med `[group]` i mailen om det behövs.
