<?php
// Eurobike-regeln, engångsändringen av sparade inställningar och kolumnen Kollad.
require __DIR__ . '/test-import.php';
$fel = 0;
function ok( $villkor, $text ) {
	global $fel;
	echo ( $villkor ? 'OK   ' : 'FEL  ' ) . $text . "\n";
	if ( ! $villkor ) {
		$fel++;
	}
}

echo "\n--- Eurobike ---\n";
ok( CV_Bokningsval::is_eurofun( 'DE-MORTK-04A' ), 'DE-MORTK-04A är Eurobike' );
ok( CV_Bokningsval::is_eurofun( 'it-tos' ), 'it-tos är Eurobike (gemener)' );
ok( ! CV_Bokningsval::is_eurofun( 'RWR123' ), 'RWR123 är inte Eurobike' );
ok( ! CV_Bokningsval::is_eurofun( 'DEU-1' ), 'DEU-1 är inte Eurobike' );
ok( ! CV_Bokningsval::is_eurofun( '' ), 'tom kod är inte Eurobike' );

$GLOBALS['opts'] = array();
ok( 1 === CV_Bokningsval::settings_for( 'DE-NY', 0 )['sportig'], 'ny Eurobike-resa har sportig som standard' );
ok( 0 === CV_Bokningsval::settings_for( 'RWR1', 0 )['sportig'], 'ny resa från annan leverantör saknar sportig' );

$GLOBALS['opts']['cvb_settings'] = array(
	'AT-1' => array( 'typ' => 'cykel', 'sportig' => 0, 'klar' => 1 ),
	'XYZ'  => array( 'typ' => 'cykel', 'sportig' => 0, 'klar' => 1 ),
);
CV_Bokningsval::migrate();
ok( 1 === $GLOBALS['opts']['cvb_settings']['AT-1']['sportig'], 'sparad Eurobike-resa får sportig' );
ok( 0 === $GLOBALS['opts']['cvb_settings']['XYZ']['sportig'], 'annan sparad resa ändras inte' );
ok( 4 === $GLOBALS['opts']['cvb_schema'], 'engångsändringen är markerad som gjord' );
ok( 'nej' === $GLOBALS['opts']['cvb_settings']['AT-1']['hjalm'], 'sparad Eurofun-resa får Hjälm erbjuds inte' );
ok( ! isset( $GLOBALS['opts']['cvb_settings']['XYZ']['hjalm'] ), 'annan sparad resa får inget nytt hjälmval' );
$GLOBALS['opts']['cvb_settings']['AT-1']['sportig'] = 0;
$GLOBALS['opts']['cvb_settings']['AT-1']['hjalm']   = 'hyra';
CV_Bokningsval::migrate();
ok( 0 === $GLOBALS['opts']['cvb_settings']['AT-1']['sportig'], 'körs bara en gång (går att kryssa ur efteråt)' );
ok( 'hyra' === $GLOBALS['opts']['cvb_settings']['AT-1']['hjalm'], 'hjälmvalet går att ändra efteråt' );

$GLOBALS['opts'] = array( 'cvb_schema' => 2, 'cvb_settings' => array(
	'Trip 1' => array( 'typ' => 'cykel', 'hjalm' => 'hyra', 'leverantor' => 'Radweg-Reisen', 'sportig' => 0 ),
	'Trip 2' => array( 'typ' => 'cykel', 'hjalm' => 'hyra', 'leverantor' => 'Dutch Bike tours', 'sportig' => 0 ),
) );
CV_Bokningsval::migrate();
ok( 'nej' === $GLOBALS['opts']['cvb_settings']['Trip 1']['hjalm'], 'från version 2: Radweg-Reisen får Hjälm erbjuds inte' );
ok( 'hyra' === $GLOBALS['opts']['cvb_settings']['Trip 2']['hjalm'], 'från version 2: Dutch Bike tours behåller hyrhjälm' );
ok( 0 === $GLOBALS['opts']['cvb_settings']['Trip 1']['sportig'], 'från version 2: sportig ändras inte igen' );

ok( CV_Bokningsval::supplier_without_helmet( 'Radweg Reisen' ), 'Radweg Reisen (utan bindestreck) hyr inte ut hjälm' );
ok( CV_Bokningsval::supplier_without_helmet( 'VELOCIPED' ), 'Velociped hyr inte ut hjälm' );
ok( ! CV_Bokningsval::supplier_without_helmet( 'S-Cape' ), 'S-Cape hyr ut hjälm' );
ok( 'nej' === CV_Bokningsval::settings_for( 'DE-NY', 0 )['hjalm'], 'ny Eurofun-resa har Hjälm erbjuds inte som standard' );

echo "\n--- S-Cape i Frankrike ---\n";
ok( CV_Bokningsval::is_scape_france( 'FRSB145' ), 'FRSB145 är S-Cape Frankrike' );
ok( CV_Bokningsval::is_scape_france( 'FR-ANNAN', 'S-Cape' ), 'FR-kod med leverantören S-Cape' );
ok( ! CV_Bokningsval::is_scape_france( 'FR-ANNAN', 'Walk Inn' ), 'FR-kod från annan leverantör' );
ok( ! CV_Bokningsval::is_scape_france( 'ITSB290', 'S-Cape' ), 'S-Cape i Italien' );
ok( 'ingar' === CV_Bokningsval::settings_for( 'FRSB660', 0 )['hjalm'], 'ny S-Cape-resa i Frankrike har Hjälm ingår' );
ok( 'hyra' === CV_Bokningsval::settings_for( 'ITSB290', 0 )['hjalm'], 'S-Cape i Italien har fortfarande hyrhjälm' );
$GLOBALS['opts'] = array( 'cvb_schema' => 3, 'cvb_settings' => array(
	'FRSB191' => array( 'typ' => 'cykel', 'hjalm' => 'hyra' ),
	'AT-X'    => array( 'typ' => 'cykel', 'hjalm' => 'hyra' ),
) );
CV_Bokningsval::migrate();
ok( 'ingar' === $GLOBALS['opts']['cvb_settings']['FRSB191']['hjalm'], 'från version 3: sparad S-Cape-resa i Frankrike får Hjälm ingår' );
ok( 'hyra' === $GLOBALS['opts']['cvb_settings']['AT-X']['hjalm'], 'från version 3: Eurofun-regeln körs inte igen' );

echo "\n--- Import med kolumnen Kollad ---\n";
$GLOBALS['opts'] = array();
file_put_contents( '/tmp/c2.csv', "Reskod,Hjälm,Vanlig cykel,Elcykel,Sportig cykel,Egen cykel,Kollad\nDE-DON,Att hyra,TRUE,TRUE,FALSE,TRUE,FALSE\n" );
CV_Bokningsval::import_csv_file( '/tmp/c2.csv' );
$r = $GLOBALS['opts']['cvb_settings']['DE-DON'];
ok( 1 === $r['sportig'], 'import: Eurobike får sportig även om filen säger FALSE' );
ok( 0 === $r['klar'], 'import: kolumnen Kollad läses' );

echo "\n--- Reskoder i kortkoder ---\n";
$c = CV_Bokningsval::extract_codes( '[contact-form-7 id="a" tripcode="Trip 07-06"] [contact-form-7 id="b" tripcode=&quot;Trip: 08-08&quot;] [cf tripcode="Mjölkvägen &amp; Rallarvägen"] [cf tripcode=DE-MORTK-04A] [cf tripcode=\'ITSB290\']' );
ok( array( 'Trip 07-06', 'Trip: 08-08', 'Mjölkvägen & Rallarvägen', 'DE-MORTK-04A', 'ITSB290' ) === $c, 'hela reskoden läses, även med mellanslag: ' . implode( ' | ', $c ) );
$b64 = base64_encode( '[contact-form-7 id="x" tripcode="Kattegattleden 4 nätter"]' );
ok( array( 'Kattegattleden 4 nätter' ) === CV_Bokningsval::extract_codes( "[fusion_code]{$b64}[/fusion_code]" ), 'reskod med mellanslag i Avadas kodblock' );

WPCF7_ContactForm::$attr = 'Mjölkvägen &amp; Rallarvägen';
ok( 'Mjölkvägen & Rallarvägen' === CV_Bokningsval::current_tripcode(), 'visning: &amp; i kortkoden blir &' );
$_POST = array( '_wpcf7' => 1, 'tripcode' => 'Mjölkvägen & Rallarvägen' );
ok( 'Mjölkvägen & Rallarvägen' === CV_Bokningsval::current_tripcode(), 'inskick: samma reskod' );
$_POST = array();
WPCF7_ContactForm::$attr = '';

echo "\n--- Import av Booking Codes (bara vissa kolumner) ---\n";
$GLOBALS['opts'] = array( 'cvb_settings' => array(
	'Trip 07-06' => array( 'typ' => 'cykel', 'hjalm' => 'ingar', 'vanlig' => 0, 'el' => 1, 'sportig' => 0, 'egen' => 0, 'stold' => 1, 'assistans' => 1, 'kommentar' => 'Viktig', 'klar' => 1 ),
) );
file_put_contents( '/tmp/c3.csv', "Land,Tripcode,Resans namn,Övrig info,Vår hemsida,Leverantör\n"
	. "Holland,Trip 07-06,Hollands pärlor,x,https://cyklavandra.se/cykla/cykla-pa-egen-hand/holland/,Dutch Bike tours\n"
	. "Tyskland,DE-DORDP-08X,Donau genom Bayern,,https://cyklavandra.se/cykla/cykla-pa-egen-hand/donau-bayern/,Radweg-Reisen\n"
	. ",,,,,\n"
	. "Italien,ITSB290,Dolomiterna,,https://cyklavandra.se/cykla/cykla-pa-egen-hand/dolomiterna/,S-Cape\n" );
$res = CV_Bokningsval::import_csv_file( '/tmp/c3.csv' );
$h   = $GLOBALS['opts']['cvb_settings']['Trip 07-06'];
ok( 'Dutch Bike tours' === $h['leverantor'], 'leverantören läses från kolumnen Leverantör via Tripcode' );
ok( 'ingar' === $h['hjalm'] && 0 === $h['vanlig'] && 1 === $h['stold'] && 1 === $h['klar'], 'övriga inställningar ändras inte' );
ok( 'Viktig' === $h['kommentar'], 'kommentaren finns kvar' );
$d = $GLOBALS['opts']['cvb_settings']['DE-DON'] ?? null;
ok( $d && 'Radweg-Reisen' === $d['leverantor'], 'okänd Tripcode matchas via Vår hemsida (DE-DON)' );
ok( $d && 'nej' === $d['hjalm'], 'Radweg-Reisen får Hjälm erbjuds inte' );
ok( ! isset( $GLOBALS['opts']['cvb_settings']['DE-DORDP-08X'] ), 'reskoder som inte finns på sajten sparas inte' );
ok( 2 === $res['imported'] && 1 === count( $res['unmatched'] ) && false !== strpos( $res['unmatched'][0], 'ITSB290' ), 'resor som inte finns på sajten rapporteras' );

echo "\n--- Leverantör från Booking Codes ---\n";
$GLOBALS['opts'] = array();
ok( 'Megasport' === CV_Bokningsval::supplier_for( 'Algarve Coastal Biking' ), 'Algarve Coastal Biking: Megasport' );
ok( 'Dutch Bike tours' === CV_Bokningsval::supplier_for( 'Trip: 05-11' ), 'Trip: 05-11: Dutch Bike tours' );
ok( 'Jotunheimen Travel' === CV_Bokningsval::supplier_for( 'Mjölkvägen &amp; Rallarvägen' ), 'reskod med &amp; hittas' );
ok( 'S-Cape' === CV_Bokningsval::supplier_for( 'ITSB999' ), 'okänd S-Cape-kod känns igen på mönstret' );
ok( '' === CV_Bokningsval::supplier_for( 'BE999999' ), 'okänd kod utan mönster: tom' );
ok( 'Megasport' === CV_Bokningsval::settings_for( 'Algarve Coastal Biking', 0 )['leverantor'], 'ny resa får leverantören från listan' );
$GLOBALS['opts']['cvb_settings']['Algarve Coastal Biking'] = array( 'typ' => 'cykel', 'leverantor' => '' );
ok( 'Megasport' === CV_Bokningsval::settings_for( 'Algarve Coastal Biking', 0 )['leverantor'], 'sparad tom leverantör fylls i från listan' );
$GLOBALS['opts']['cvb_settings']['Algarve Coastal Biking']['leverantor'] = 'Annan';
ok( 'Annan' === CV_Bokningsval::settings_for( 'Algarve Coastal Biking', 0 )['leverantor'], 'ifylld leverantör har företräde' );
ok( 'nej' === CV_Bokningsval::settings_for( 'DE-MORWT-06', 0 )['hjalm'], 'Velociped-resa: Hjälm erbjuds inte' );

echo "\n--- Kortkod för formulär ---\n";
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $a ) { return array( (object) array( 'ID' => 1 ), (object) array( 'ID' => 2 ), (object) array( 'ID' => 3 ) ); }
}
class Fake_Form {
	public $id;
	function __construct( $id ) { $this->id = $id; }
	function prop( $k ) { return array( 1 => 'utan reskod', 2 => '[hidden tripcode default:shortcode_attr]', 3 => '<div class="resenar-rad cvb-cykel">[hidden tripcode default:shortcode_attr]' )[ $this->id ]; }
	function title() { return 'Form ' . $this->id; }
	function id() { return $this->id; }
	function shortcode() { return '[contact-form-7 id="abc' . $this->id . '" title="Form ' . $this->id . '"]'; }
}
$forms = CV_Bokningsval::booking_forms();
ok( 2 === count( $forms ), 'bara formulär med tripcode listas' );
ok( 'Form 3' === $forms[0]['title'] && $forms[0]['new_template'], 'formulär med nya mallen först' );
ok( '[contact-form-7 id="abc3" title="Form 3" tripcode="RESKOD"]' === $forms[0]['shortcode'], 'kortkoden får tripcode: ' . $forms[0]['shortcode'] );

$off = CV_Bokningsval::official_form( $forms );
ok( $off && 'Form 3' === $off['title'] && in_array( 'abc3', $off['ids'], true ) && in_array( '3', $off['ids'], true ), 'det officiella formuläret är det med nya mallen' );
ok( '[contact-form-7 id="abc3" title="Form 3" tripcode="Trip: 05-11"]' === CV_Bokningsval::shortcode_for( 'Trip: 05-11', $off ), 'kortkod per resa' );
ok( '' === CV_Bokningsval::shortcode_for( 'X', null ), 'ingen kortkod utan nytt formulär' );
$ids = CV_Bokningsval::extract_form_ids( '<p>[contact-form-7 id="cdee672" title="Holland" tripcode="Trip 07-06"]</p> [contact-form-7 id="ca124e5" title="Ny" tripcode="DE-X"] [contact-form-7 id=&quot;99&quot; tripcode=&quot;IT-Y&quot;]' );
ok( array( 'Trip 07-06' => array( 'cdee672' ), 'DE-X' => array( 'ca124e5' ), 'IT-Y' => array( '99' ) ) === $ids, 'formulär-id per reskod: ' . json_encode( $ids ) );
ok( array( 'DE-Z' => array( 'ca124e5' ) ) === CV_Bokningsval::extract_form_ids( '[contact-form-7 id="nyhetsbrev"] text [contact-form-7 id="ca124e5" tripcode="DE-Z"]' ), 'formulär utan tripcode före bokningsformuläret ger inte fel id' );
ok( array( '<span data-sheets-root=' => array( 'cdee672' ) ) === CV_Bokningsval::extract_form_ids( '[contact-form-7 id="cdee672" tripcode="<span data-sheets-root="1">Trip 07-06</span>"]' ), 'trasig kod med HTML ger ändå formulär-id' );
$b64 = base64_encode( '[contact-form-7 id="ca124e5" title="Ny" tripcode="Kattegattleden 4 nätter"]' );
ok( array( 'Kattegattleden 4 nätter' => array( 'ca124e5' ) ) === CV_Bokningsval::extract_form_ids( "[fusion_code]{$b64}[/fusion_code]" ), 'formulär-id i Avadas kodblock' );

echo "\n--- Hjälm (gratis) ---\n";
$GLOBALS['opts'] = array( 'cvb_settings' => array( 'FRSB191' => array( 'typ' => 'cykel', 'hjalm' => 'ingar' ) ) );
WPCF7_ContactForm::$attr = 'FRSB191';
$old = mk( 'select*', 'hjalm-1', array( 'Hyr hjälm', 'Tar med egen', 'Hjälm ingår' ) );
$res = CV_Bokningsval::filter_form_tag( $old );
ok( array( 'Hjälm (gratis)', 'Tar med egen' ) === $res->labels, 'gammal text visas som Hjälm (gratis): ' . implode( '/', $res->labels ) );
ok( array( 'Hjälm ingår', 'Tar med egen' ) === $res->values, 'värdet i mailet är oförändrat' );
$new = CV_Bokningsval::filter_form_tag( mk( 'select*', 'hjalm-1', array( 'Hyr hjälm', 'Tar med egen', 'Hjälm (gratis)' ) ) );
ok( array( 'Hjälm (gratis)', 'Tar med egen' ) === $new->labels, 'ny text oförändrad' );
$no = CV_Bokningsval::filter_form_tag( mk( 'select*', 'hjalm-1', array( 'Leie hjelm', 'Tar med egen', 'Hjelm inkludert' ) ) );
ok( array( 'Hjelm inkludert', 'Tar med egen' ) === $no->labels, 'norsk text rörs inte' );
WPCF7_ContactForm::$attr = '';

echo "\n--- Utan reskod (valideringsschemat i webbläsaren) ---\n";
WPCF7_ContactForm::$attr = '';
$_POST = array();
$h = mk( 'select*', 'hjalm-1', array( 'Hyr hjälm', 'Tar med egen', 'Hjälm (gratis)' ) );
ok( $h === CV_Bokningsval::filter_form_tag( $h ), 'hjälm: alla alternativ kvar utan reskod' );
$c = mk( 'select*', 'cykel-1', array( 'Vanlig cykel', 'Elcykel', 'Sportig cykel', 'Tar med egen' ) );
ok( $c === CV_Bokningsval::filter_form_tag( $c ), 'cykel: alla alternativ kvar utan reskod' );
$html = '<div class="cvb-stold">x</div><div class="resenar-rad cvb-cykel">y</div>';
ok( $html === CV_Bokningsval::filter_form_html( $html ), 'HTML orörd utan reskod' );
$GLOBALS['cur'] = 0; // Ingen vandringssida från tidigare tester.
$_POST = array( '_wpcf7' => 1, 'tripcode' => 'DE-MORTK-04A' );
$r = CV_Bokningsval::filter_form_tag( mk( 'select*', 'hjalm-1', array( 'Hyr hjälm', 'Tar med egen', 'Hjälm (gratis)' ) ) );
ok( array( 'Tar med egen' ) === $r->values, 'vid inskick med reskod begränsas valen som förut: ' . implode( '/', $r->values ) );
$_POST = array();

echo "\n--- Felaktiga reskoder ---\n";
ok( '' !== CV_Bokningsval::code_problem( '<span data-sheets-root=' ), 'HTML från Google Sheets upptäcks' );
ok( '' !== CV_Bokningsval::code_problem( 'Mon May 11 2026 00:00:00 GMT+0200 (Central European Summer Time)' ), 'datum upptäcks' );
foreach ( array( 'Trip: 05-11', 'DE-MORTK-04A', 'Mjölkvägen & Rallarvägen', 'Paquete Via Verde + senderismo', 'Tour de Dovre 5 days' ) as $okcode ) {
	ok( '' === CV_Bokningsval::code_problem( $okcode ), "giltig kod: $okcode" );
}

echo $fel ? "\n$fel FEL\n" : "\nAlla OK\n";
exit( $fel ? 1 : 0 );
