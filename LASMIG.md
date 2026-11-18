# Cykla & Vandra – Bokningsformulär

Ett gemensamt bokningsformulär (Contact Form 7) för både cykel- och vandringsresor. För varje resa styr tillägget vilka cyklar, hjälmval och försäkringar som visas, utifrån reskoden (`tripcode`) i kortkoden.

## Installera

1. Gå till **Tillägg → Lägg till nytt → Ladda upp tillägg**, välj `bokningsformular.zip` och aktivera tillägget.
2. Det dyker upp en ny meny, **Bokningsval**. Där finns alla resor som har en kortkod med `tripcode`, en rad per reskod. Den svenska och den norska versionen av en resa delar samma rad.

## Sidan Bokningsval

Överst finns sökrutan och filtret **Typ**. Som standard visas bara cykelresorna. Välj **Vandring** eller **Alla** för att se fler. Kryssa i **Kollad** när en resa är genomgången.

## Eurobike

Reskoder som börjar med landskod och bindestreck, t.ex. `DE-` och `IT-`, är Eurobike-resor. De har alltid **Sportig cykel** ikryssad. Det gäller nya resor och vid import, och när version 1.2.0 installeras kryssas rutan i på alla Eurobike-resor som redan är sparade. Därefter går rutan att kryssa ur för enskilda resor.

## Typ: cykel eller vandring

Tillägget gissar typen utifrån adressen: adresser med `/vandra/` blir vandring och övriga blir cykel. Gissningen går att ändra i kolumnen **Typ**.

På vandringsresor försvinner raden med längd, cykel och hjälm för varje resenär, och fälten blir frivilliga. Vandringsresor räknas inte som "ej ifyllda", eftersom de inte behöver några val.

## Läs in arket

1. Ladda ner arket från Claude som CSV.
2. Gå till **Bokningsval → Importera CSV**.
3. Resorna matchas mot sajten via kolumnen *Webbsida*. Om det inte går används resans namn.
4. Rader där hjälmvalet är ifyllt markeras som **Kollad**, om filen inte har en egen kolumn **Kollad**.
5. Eurobike-resor får alltid **Sportig cykel**, även om filen säger något annat.

## Formuläret

Klistra in `formularmall-sv.txt` under fliken **Formulär** i bokningsformulären, både för cykel och vandring. Det enklaste är att klistra in mallen i de formulär som redan finns. Då behöver ingen resesida ändras, och mailen och Flamingo fungerar som förut eftersom fälten har samma namn.

Det viktiga i mallen:

- `cykel-N` har alternativen **i denna ordning**: Vanlig cykel, Elcykel, Sportig cykel, Tar med egen
- `hjalm-N` har alternativen **i denna ordning**: Hyr hjälm, Tar med egen, Hjälm ingår
- Raden med längd, cykel och hjälm ligger i `<div class="resenar-rad cvb-cykel">`
- Försäkringsfälten ligger i `<div class="cvb-stold">…</div>` och `<div class="cvb-assistans">…</div>`
- Raden `[hidden tripcode default:shortcode_attr]` måste finnas kvar

I det norska formuläret får texterna vara på norska, men ordningen, fältnamnen och klasserna måste vara desamma.

## Så styrs formuläret

| Inställning | I formuläret |
|---|---|
| Typ: Vandring | Ingen längd, cykel eller hjälm |
| Kryssrutor för cykeltyp och egen cykel | Bara de ikryssade typerna visas i listan *Cykel* |
| Hjälm: Att hyra | "Hyr hjälm" och "Tar med egen" |
| Hjälm: Erbjuds inte | Bara "Tar med egen", förvalt |
| Hjälm: Ingår | Bara "Hjälm ingår", förvalt |
| Stöld- och assistansförsäkring | Visas bara när rutan är ikryssad |

En cykelresa som saknar inställning får standardläget: vanlig cykel, elcykel och egen cykel, hyrhjälm och inga försäkringar.

## Uppdateringar

Tillägget uppdateras som vilket tillägg som helst under **Tillägg** och **Uppdateringar** i WordPress. Det hämtar den senaste releasen från GitHub (`cyklavandra-se/bokningsformular`) och söker efter nya versioner var sjätte timme. Knappen **Sök igen** under **Kontrollpanel → Uppdateringar** söker direkt.

Så här släpps en ny version:

1. Höj `Version:` överst i `bokningsformular.php`, t.ex. till `1.2.0`, och pusha till `main`.
2. Skapa och pusha en tagg med samma nummer:

   ```bash
   git tag v1.2.0
   git push origin v1.2.0
   ```

3. GitHub Actions bygger `bokningsformular.zip` och skapar en release. Om taggen och versionen i filen inte stämmer överens stoppas releasen.

Texten i releasen visas under **Visa detaljer → Ändringar** i WordPress. Den går att redigera på GitHub i efterhand.

Version 1.0.0 saknar uppdateringsfunktionen, så version 1.1.0 måste installeras manuellt en gång (ladda upp zip-filen och välj **Ersätt nuvarande med uppladdad**). Därefter kommer uppdateringarna automatiskt.
