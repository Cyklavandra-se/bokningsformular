# Cykla & Vandra – Bokningsformulär cykel

Styr vilka cyklar, hjälmval och försäkringar som visas i bokningsformuläret (Contact Form 7) för varje resa. Styrningen utgår från reskoden (`tripcode`) i kortkoden.

## Installera

1. Gå till **Tillägg → Lägg till nytt → Ladda upp tillägg**, välj `bokningsformular-cykel.zip` och aktivera tillägget.
2. Det dyker upp en ny meny, **Bokningsval**. Där finns alla resor som har en kortkod med `tripcode`, en rad per reskod. Den svenska och den norska versionen av en resa delar samma rad.

## Läs in arket

1. Ladda ner arket från Claude som CSV.
2. Gå till **Bokningsval → Importera CSV**.
3. Resorna matchas mot sajten via kolumnen *Webbsida*. Om det inte går används resans namn.
4. Rader där hjälmvalet är ifyllt markeras som **Klar**. Övriga visas i gult.

## Formuläret

Klistra in `formularmall-sv.txt` under fliken **Formulär** i bokningsformulären. Det enklaste är att klistra in den i alla fyra befintliga cykelformulär. Då behöver ingen resesida ändras, och mailen och Flamingo fungerar som förut eftersom fälten har samma namn.

Det viktiga i mallen:

- `cykel-N` har alternativen **i denna ordning**: Vanlig cykel, Elcykel, Sportig cykel, Tar med egen
- `hjalm-N` har alternativen **i denna ordning**: Hyr hjälm, Tar med egen, Hjälm ingår
- Försäkringsfälten ligger i `<div class="cvb-stold">…</div>` och `<div class="cvb-assistans">…</div>`
- Raden `[hidden tripcode default:shortcode_attr]` måste finnas kvar

I det norska formuläret får texterna vara på norska, men ordningen och namnen på fälten måste vara desamma.

## Så styrs formuläret

| Inställning | I formuläret |
|---|---|
| Kryssrutor för cykeltyp och egen cykel | Bara de ikryssade typerna visas i listan *Cykel* |
| Hjälm: Att hyra | "Hyr hjälm" och "Tar med egen" |
| Hjälm: Erbjuds inte | Bara "Tar med egen", förvalt |
| Hjälm: Ingår | Bara "Hjälm ingår", förvalt |
| Stöld- och assistansförsäkring | Visas bara när rutan är ikryssad |

En resa som saknar inställning, eller ett formulär utan reskod, får standardläget: vanlig cykel, elcykel och egen cykel, hyrhjälm och inga försäkringar.
