<?php
// Kontroll av formulärets översättningar: struktur och saknade språk.
require __DIR__ . '/test-import.php';
$fel = 0;
function ok( $villkor, $text ) {
	global $fel;
	echo ( $villkor ? 'OK   ' : 'FEL  ' ) . $text . "\n";
	if ( ! $villkor ) {
		$fel++;
	}
}
function get_post_meta( $id, $k, $single ) { return $GLOBALS['meta'][ $id ] ?? ''; }
function get_the_title( $id ) { return 'Formulär ' . $id; }

$sv = file_get_contents( __DIR__ . '/../formularmall-sv.txt' );
// Simulerad norsk översättning: bara texterna inom citattecken och etiketterna ändras.
$no = strtr(
	$sv,
	array(
		'"Vanlig cykel"'    => '"Vanlig sykkel"',
		'"Elcykel"'         => '"Elsykkel"',
		'"Sportig cykel"'   => '"Sportssykkel"',
		'"Hyr hjälm"'       => '"Lei hjelm"',
		'"Hjälm (gratis)"'  => '"Hjelm (gratis)"',
		'"BOKA RESA"'       => '"BESTILL REISE"',
		'Förnamn'           => 'Fornavn',
		'Efternamn'         => 'Etternavn',
		'Resenär'           => 'Reisende',
	)
);

echo "\n--- Struktur ---\n";
$st = CV_Bokningsval::form_structure( $sv );
ok( count( $st['tags'] ) > 80, 'mallen har ' . count( $st['tags'] ) . ' taggar' );
$h1 = null;
foreach ( $st['tags'] as $t ) {
	if ( 'hjalm-1' === $t['name'] ) {
		$h1 = $t;
	}
}
ok( $h1 && 3 === $h1['count'] && $h1['blank'] && 'select*' === $h1['type'], 'hjalm-1: select*, 3 alternativ, tomt val' );
ok( 8 === $st['markers']['cvb-cykel'] && 1 === $st['markers']['[hidden tripcode'], 'klasser och tripcode räknas' );
ok( array() === CV_Bokningsval::compare_structure( $sv, $sv ), 'samma formulär: inga skillnader' );
ok( array() === CV_Bokningsval::compare_structure( $sv, $no ), 'översatta texter: inga skillnader' );

$utan_hjalm = preg_replace( '/ "Hjelm \(gratis\)"/', '', $no, 1 );
$r          = CV_Bokningsval::compare_structure( $sv, $utan_hjalm );
ok( in_array( 'hjalm-1 har 2 alternativ i stället för 3', $r, true ), 'ett alternativ saknas: ' . implode( '; ', $r ) );

$nytt_falt = str_replace( '[text rabattkod]', '[text rabattkod] [text nyhetsbrev-kod]', $sv );
$r         = CV_Bokningsval::compare_structure( $nytt_falt, $no );
ok( in_array( 'saknar nyhetsbrev-kod', $r, true ), 'nytt fält i originalet saknas i översättningen: ' . implode( '; ', $r ) );

$utan_klass = str_replace( 'cvb-stold', 'stold', $no );
$r          = CV_Bokningsval::compare_structure( $sv, $utan_klass );
ok( in_array( 'cvb-stold finns 0 gånger i stället för 1', $r, true ), 'saknad klass: ' . implode( '; ', $r ) );

$valfritt = str_replace( '[tel* telefon', '[tel telefon', $no );
$r        = CV_Bokningsval::compare_structure( $sv, $valfritt );
ok( in_array( 'telefon är tel i stället för tel*', $r, true ), 'obligatoriskt fält blev frivilligt: ' . implode( '; ', $r ) );

$bytt = strtr( $no, array( '[text rabattkod]' => '[textarea ovrig-info]', '[textarea ovrig-info]' => '[text rabattkod]' ) );
$r    = CV_Bokningsval::compare_structure( $sv, $bytt );
ok( in_array( 'fälten ligger i en annan ordning', $r, true ), 'annan ordning: ' . implode( '; ', $r ) );

echo "\n--- Översättningar i WPML ---\n";
$forms = array(
	array( 'post_id' => 36488, 'body' => $sv, 'ids' => array( '36488', 'ca124e5' ), 'title' => 'Bokningsformulär självguidade v1.0', 'lang' => 'SV', 'shortcode' => '[contact-form-7 id="ca124e5" title="Bokningsformulär självguidade v1.0" tripcode="RESKOD"]', 'new_template' => true, 'old_helmet' => false ),
	array( 'post_id' => 36490, 'body' => $utan_hjalm, 'ids' => array( '36490', 'ca124e5' ), 'title' => 'Bokningsformulär självguidade v1.0', 'lang' => 'NO', 'shortcode' => '', 'new_template' => true, 'old_helmet' => false ),
	array( 'post_id' => 100, 'body' => '[hidden tripcode]', 'ids' => array( '100', '5a8fea7' ), 'title' => 'Gammalt', 'lang' => 'SV', 'shortcode' => '', 'new_template' => false, 'old_helmet' => false ),
);
$GLOBALS['filter_returns'] = array(
	'wpml_default_language'         => 'sv',
	'wpml_element_trid'             => 77,
	'wpml_get_element_translations' => array(
		'sv' => (object) array( 'element_id' => 36488, 'language_code' => 'sv' ),
		'no' => (object) array( 'element_id' => 36490, 'language_code' => 'no' ),
		'de' => (object) array( 'element_id' => 36500, 'language_code' => 'de' ),
	),
	'wpml_active_languages'         => array( 'sv' => array(), 'no' => array(), 'de' => array(), 'en' => array() ),
);
// Den tyska översättningen saknar tripcode helt (finns inte i listan över bokningsformulär).
$GLOBALS['meta'][36500] = str_replace( '[hidden tripcode default:shortcode_attr]', '', $sv );

$off = CV_Bokningsval::official_form( $forms );
ok( 36488 === $off['post_id'], 'originalet är det svenska formuläret' );
ok( array( 'SV', 'NO', 'DE' ) === $off['langs'], 'språk: ' . implode( ', ', $off['langs'] ) );
ok( ! in_array( '5a8fea7', $off['ids'], true ), 'gamla formulär räknas inte in' );
$iss = CV_Bokningsval::translation_issues( $off );
ok( array( 'EN' ) === $iss['missing'], 'saknar översättning: ' . implode( ', ', $iss['missing'] ) );
ok( isset( $iss['differs']['NO'] ) && in_array( 'hjalm-1 har 2 alternativ i stället för 3', $iss['differs']['NO'], true ), 'NO skiljer sig: ' . implode( '; ', $iss['differs']['NO'] ?? array() ) );
ok( isset( $iss['differs']['DE'] ) && in_array( 'saknar tripcode', $iss['differs']['DE'], true ), 'DE saknar tripcode: ' . implode( '; ', $iss['differs']['DE'] ?? array() ) );
ok( ! isset( $iss['differs']['SV'] ), 'originalet jämförs inte med sig självt' );

$forms[1]['body']          = $no;
$GLOBALS['filter_returns'] = array(
	'wpml_default_language'         => 'sv',
	'wpml_element_trid'             => 77,
	'wpml_get_element_translations' => array(
		'sv' => (object) array( 'element_id' => 36488, 'language_code' => 'sv' ),
		'no' => (object) array( 'element_id' => 36490, 'language_code' => 'no' ),
	),
	'wpml_active_languages'         => array( 'sv' => array(), 'no' => array() ),
);
$iss = CV_Bokningsval::translation_issues( CV_Bokningsval::official_form( $forms ) );
ok( ! $iss['missing'] && ! $iss['differs'], 'allt stämmer: ingen varning' );

$GLOBALS['filter_returns'] = array();
$iss = CV_Bokningsval::translation_issues( CV_Bokningsval::official_form( $forms ) );
ok( ! $iss['missing'] && ! $iss['differs'], 'utan WPML: ingen varning' );

echo "\n--- Påminnelse i admin ---\n";
class Fake_Form {
	public $id;
	function __construct( $id ) { $this->id = $id; }
	function prop( $k ) { return $GLOBALS['bodies'][ $this->id ]; }
	function title() { return 'Bokningsformulär självguidade v1.0'; }
	function id() { return $this->id; }
	function shortcode() { return '[contact-form-7 id="ca124e5" title="Bokningsformulär självguidade v1.0"]'; }
}
function get_posts( $a ) { return array_map( function ( $id ) { return (object) array( 'ID' => $id ); }, array_keys( $GLOBALS['bodies'] ) ); }
function current_user_can( $c ) { return true; }
function get_current_screen() { return (object) array( 'id' => $GLOBALS['screen'] ); }
function esc_html( $s ) { return htmlspecialchars( $s ); }
function esc_url( $s ) { return $s; }
function admin_url( $p ) { return '/wp-admin/' . $p; }
$GLOBALS['bodies']         = array( 36488 => $sv, 36490 => $utan_hjalm );
$GLOBALS['filter_returns'] = array(
	'wpml_default_language'         => 'sv',
	'wpml_post_language_details'    => null,
	'wpml_element_trid'             => 77,
	'wpml_get_element_translations' => array(
		'sv' => (object) array( 'element_id' => 36488, 'language_code' => 'sv' ),
		'no' => (object) array( 'element_id' => 36490, 'language_code' => 'no' ),
	),
	'wpml_active_languages'         => array( 'sv' => array(), 'no' => array(), 'en' => array() ),
);
foreach ( array( 'toplevel_page_wpcf7' => true, 'dashboard' => true, 'edit-page' => false ) as $scr => $visas ) {
	$GLOBALS['screen'] = $scr;
	ob_start();
	CV_Bokningsval::translation_notice();
	$out = ob_get_clean();
	ok( $visas === ( '' !== $out ), "påminnelse på $scr: " . ( $out ? strip_tags( $out ) : 'ingen' ) );
}
$GLOBALS['bodies'][36490]  = $no;
$GLOBALS['filter_returns']['wpml_active_languages'] = array( 'sv' => array(), 'no' => array() );
$GLOBALS['screen'] = 'dashboard';
ob_start();
CV_Bokningsval::translation_notice();
ok( '' === ob_get_clean(), 'ingen påminnelse när allt stämmer' );

echo $fel ? "\n$fel FEL\n" : "\nAlla OK\n";
exit( $fel ? 1 : 0 );
