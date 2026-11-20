# Bokningsformulär – Cykla & Vandra

WordPress-tillägg för [cyklavandra.se](https://cyklavandra.se). Alla självguidade cykel- och vandringsresor använder **ett** bokningsformulär i Contact Form 7. Tillägget anpassar formuläret efter resan: vilka cyklar, hjälmval och försäkringar som visas, och vad som står i mailen.

Förr fanns ett formulär för varje kombination av cykel, hjälm och försäkring. Nu finns ett enda formulär, *Bokningsformulär självguidade v1.0*, och inställningarna för varje resa görs i en tabell.

---

## Så fungerar det

1. Varje resesida har kortkoden för bokningsformuläret med resans **reskod** (från arket *Booking Codes*):
   ```
   [contact-form-7 id="ca124e5" title="Bokningsformulär självguidade v1.0" tripcode="DE-MORTK-04A"]
   ```
2. När sidan visas läser tillägget reskoden och slår upp resans inställningar.
3. Formuläret visar bara det som gäller för resan, och mailen tar bara med det som är relevant.

Den svenska och den norska sidan för en resa har samma reskod, så de styrs av samma inställningar.

---

## Sidan Bokningsformulär

Finns under **Cykla&Vandra → Bokningsformulär** i vänstermenyn.

### Rutan överst
- Kortkoden för bokningsformuläret med en **Kopiera**-knapp. Byt `RESKOD` mot resans reskod.
- Varningar om något behöver göras, t.ex. att en översättning av formuläret inte stämmer med originalet.
- **Visa formulärmallen**: hela formulärtexten, färdig att kopiera.

### Tabellen
En rad per reskod. Nya resor dyker upp automatiskt så fort sidan med kortkoden är sparad, även som utkast.

| Kolumn | Betyder |
|---|---|
| **Typ** | **C** = cykel, **V** = vandring. Klicka för att byta. |
| **Leverantör / Reskod** | Leverantören fylls i automatiskt från *Booking Codes*, men går att skriva i. |
| **Resa** | Namnet öppnar resans sida i en ny flik. |
| **Vanlig cykel … Egen cykel** | Vilka cyklar kunden kan välja (Vanlig, El, Sportig, Gravel, Egen). |
| **Stöld- / Assistansförsäkring** | Visas bara i formuläret om rutan är ikryssad. |
| **Hjälm** | *Att hyra*, *Ingår* eller *Erbjuds inte*. |

**Rumskategorier** (länken *Kategorier* under resans namn): för de få resor där kunden väljer t.ex. Superior eller havsutsikt. Kryssa i minst två kategorier, annars visas ingen fråga. Om leverantören har en egen reskod för kategorin, t.ex. Canal du Midi `FRSB661` för Superior, skriver du in den bredvid. Mailet till er får då rätt bokningskod.

**Allt sparas direkt** när du klickar. En grön ✓ längst till höger visar att ändringen är sparad, och ett rött ! att något gick fel.

**Färger och märken**
- **Gul rad**: sidan använder ett annat formulär än bokningsformuläret, eller reskoden är felaktig. Filtret *Visa bara gula* visar bara dem.
- **Annat formulär**: sidan har ett gammalt formulär. Knappen **Byt** byter till bokningsformuläret.
- **Felaktig reskod**: reskoden innehåller formatering från Google Sheets eller har blivit ett datum. Rätta den på sidan och skriv den som vanlig text.
- **Ej publicerade sidor** ligger sist i tabellen.

### Visa en enda resa
Lägg till `&reskod=` i adressen för att bara visa en resa:
```
https://cyklavandra.se/wp-admin/admin.php?page=bokningsformular&reskod=IT-BZRBV-08
```
Flera reskoder skiljs med kommatecken. Stora och små bokstäver, mellanslag och kolon spelar ingen roll. **Visa alla resor** tar tillbaka hela tabellen.

I arket *Booking Codes* kan en kolumn länka direkt till resans inställningar (reskoden i kolumn B):
```
=HYPERLINK("https://cyklavandra.se/wp-admin/admin.php?page=bokningsformular&reskod="&ENCODEURL(B2); "Inställningar")
```

### Import och export
Längst ner går det att läsa in och ladda ner CSV. Flikarna BIKE och HIKE i *Booking Codes* kan läsas in direkt. Importen ändrar bara de kolumner som finns i filen.

---

## Vad kunden ser

| Inställning | I formuläret |
|---|---|
| Typ: Vandring | Ingen längd, cykel eller hjälm |
| Cykeltyper | Bara de ikryssade cyklarna i listan. Är bara en ikryssad blir den förvald. |
| Hjälm: Att hyra | *Hyr hjälm* och *Tar med egen* |
| Hjälm: Ingår | *Hjälm (gratis)* och *Tar med egen* |
| Hjälm: Erbjuds inte | Ingen hjälmruta alls. Mailen får *Tar med egen*. |
| Stöld-/assistansförsäkring | Frågan visas bara när rutan är ikryssad |
| Rumskategorier (minst två) | Frågan *Rumskategori* med resans kategorier, på både cykel- och vandringsresor |

### Regler som sätts automatiskt
Gäller nya resor och vid import. Efteråt går allt att ändra för hand.
- **Eurobike, Radweg-Reisen och Velociped** (reskoder som börjar med landskod och bindestreck, t.ex. `DE-`, `IT-`): *Sportig cykel* ikryssad och *Hjälm: Erbjuds inte*.
- **S-Cape i Frankrike** (`FRSB…`): *Hjälm: Ingår*.
- Övriga cykelresor: vanlig cykel, elcykel, egen cykel och hyrhjälm.

---

## Lägga in en ny resa

1. Skapa resans sida som vanligt.
2. Kopiera kortkoden från rutan överst på sidan Bokningsformulär och byt `RESKOD` mot resans reskod.
3. Klistra in kortkoden på sidan. **Skriv reskoden som vanlig text**, eller klistra in med Cmd+Shift+V, så att ingen formatering från Google Sheets följer med.
4. Gör samma sak på den norska sidan, med samma kortkod och samma reskod. WPML visar automatiskt det norska formuläret.
5. Öppna sidan Bokningsformulär. Resan finns nu i tabellen. Kontrollera typ, cyklar, hjälm och försäkringar.

---

## Formuläret

Formulärtexten finns i `formularmall-sv.txt` och under **Visa formulärmallen** på sidan Bokningsformulär.

Tillägget styr listorna efter **position**, inte text. Därför går formuläret att översätta. Det som måste vara likadant på alla språk:

- `cykel-1` … `cykel-8`, i denna ordning: Vanlig cykel, Elcykel, Sportig cykel, Tar med egen, Gravelcykel
- `hjalm-1` … `hjalm-8`, i denna ordning: Hyr hjälm, Tar med egen, Hjälm (gratis)
- `rumskategori`, i denna ordning: Standard, Superior, Havsutsikt, Kategori A, Kategori B, Charm, i rutan `cvb-rum`
- Klasserna `cvb-cykel` (raden med längd, cykel och hjälm), `cvb-stold` och `cvb-assistans` (försäkringarna) och `cvb-rum` (rumskategorin)
- Fältet `[hidden tripcode default:shortcode_attr]`
- Fältnamnen, eftersom mailen och Flamingo bygger på dem

Texterna får vara på valfritt språk. **Nya alternativ läggs sist** i listan, så att de gamla positionerna inte flyttas.

### Översättningar (WPML)
Översätt formuläret i WPML, även mailen. Ändras det svenska formuläret måste översättningarna uppdateras. Tillägget jämför varje översättning med originalet och **varnar** överst på sidan Bokningsformulär, i Contact Form 7 och på kontrollpanelen om något fält, alternativ eller språk saknas. Texterna jämförs inte.

Utseendet (CF7 Skins) väljs per formulär och följer inte med till översättningen. Välj samma stil på varje språk.

---

## Mailen

Mail (1) går till er och Mail (2) är bekräftelsen till kunden. Mailen kan innehålla **villkorliga stycken**, som bara tas med när de gäller för resan:

| Block | Tas med när |
|---|---|
| `[cvb-cykel]…[/cvb-cykel]` | Resan är en cykelresa |
| `[cvb-vandring]…[/cvb-vandring]` | Resan är en vandringsresa |
| `[cvb-stold]…[/cvb-stold]` | Cykelresan erbjuder stöldförsäkring |
| `[cvb-assistans]…[/cvb-assistans]` | Cykelresan erbjuder assistansförsäkring |
| `[cvb-hjalm-nej]…[/cvb-hjalm-nej]` | Hjälm: Erbjuds inte |
| `[cvb-hjalm-hyra]…[/cvb-hjalm-hyra]` | Hjälm: Att hyra |
| `[cvb-hjalm-ingar]…[/cvb-hjalm-ingar]` | Hjälm: Ingår |
| `[cvb-rum]…[/cvb-rum]` | Kunden kunde välja rumskategori |

Taggen **`[cvb-reskod]`** ger reskoden för den rumskategori kunden valde, eller resans reskod om kategorin inte har någon egen. Den används som `Bokningskod: [cvb-reskod]` i Mail (1).

Mallarna finns i `mailmall-till-oss-sv.txt` (Mail 1) och `mailmall-bekraftelse-sv.txt` (Mail 2). Där står t.ex. längd, cykel och hjälm inom `[cvb-cykel]`, så att vandringsbokningar bara visar namnen. När mailen översätts ska taggarna `[cvb-…]` och `[/cvb-…]` stå kvar oförändrade.

- **Mailet till er** är alltid på svenska. Det sköts av en Code Snippet, inte av tillägget.
- **Bekräftelsen till kunden** är på samma språk som formuläret skickades från.

---

## Uppdateringar

Tillägget uppdateras som andra tillägg under **Tillägg** i WordPress. Länken **Sök efter uppdatering** under tilläggets namn söker direkt. Nya versioner hämtas från GitHub ([cyklavandra-se/bokningsformular](https://github.com/cyklavandra-se/bokningsformular)), som måste vara **publikt**.

Om något går fel efter en uppdatering kan en äldre version installeras manuellt från [Releases](https://github.com/cyklavandra-se/bokningsformular/releases): ladda ner `bokningsformular.zip` och välj **Ersätt nuvarande med uppladdad**.

---

## Felsökning

| Problem | Orsak och åtgärd |
|---|---|
| "Odefinierat värde skickades via detta fält" | Rättat i 1.7.1. Ladda om sidan med Cmd+Shift+R. Kvarstår felet: kontrollera att formulärets alternativ ligger i rätt ordning. |
| Resan saknas i tabellen | Sidan har ingen kortkod med `tripcode=`, eller den är inte sparad. |
| Leverantör saknas | Reskoden finns inte i den inbyggda listan. Skriv in leverantören i tabellen. |
| Formuläret visar alla alternativ | Kortkoden saknar `tripcode`. |
| Formuläret visar standardläget i stället för resans val | Reskoden på sidan stämmer inte exakt med den i tabellen (stora/små bokstäver, mellanslag). |
| Det norska formuläret ser annorlunda ut | Välj samma CF7 Skins-stil på den norska översättningen. |
| En sida visar fortfarande det gamla formuläret | Töm cachen i WP Fastest Cache. |
| `[cvb-…]` syns i ett mail | Formuläret skickades utan reskod, mallen har ett stavfel i en tagg, eller tillägget är avaktiverat. |

---

## För utvecklare

| Fil | Innehåll |
|---|---|
| `bokningsformular.php` | Själva tillägget: formulärstyrning, adminsidan, import och export, mailblock |
| `updater.php` | Uppdateringar från GitHub |
| `cyklavandra-meny.php` | Den gemensamma menyn *Cykla&Vandra*. Kopieras oförändrad till andra tillägg, t.ex. AI-sök. |
| `data/leverantorer.php` | Leverantör per reskod från *Booking Codes* (uppdateras för hand) |
| `formularmall-sv.txt`, `mailmall-*.txt` | Mallar för formuläret och mailen |
| `tests/` | Tester med stubbar för WordPress (`php tests/test-*.php`) |
| `CLAUDE.md` | Detaljerade tekniska anteckningar och beslut |

**Ny version:** höj `Version:` överst i `bokningsformular.php` och pusha till `main`. GitHub Actions skapar då tagg och release med `bokningsformular.zip`, och WordPress hittar uppdateringen.
