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
$GLOBALS['cf7_posts'][36500]['_form'] = str_replace( '[hidden tripcode default:shortcode_attr]', '', $sv );

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
function current_user_can( $c ) { return true; }
function get_current_screen() { return (object) array( 'id' => $GLOBALS['screen'] ); }
function esc_html( $s ) { return htmlspecialchars( $s ); }
function esc_url( $s ) { return $s; }
function admin_url( $p ) { return '/wp-admin/' . $p; }
$GLOBALS['cf7_posts'] = array(
	36488 => array( 'title' => 'Bokningsformulär självguidade v1.0', '_form' => $sv, '_hash' => 'ca124e5aaaa' ),
	36490 => array( 'title' => 'Bokningsformulär självguidade v1.0', '_form' => $utan_hjalm, '_hash' => 'ca124e5aaaa' ),
);
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
$GLOBALS['cf7_posts'][36490]['_form'] = $no;
$GLOBALS['filter_returns']['wpml_active_languages'] = array( 'sv' => array(), 'no' => array() );
$GLOBALS['screen'] = 'dashboard';
ob_start();
CV_Bokningsval::translation_notice();
ok( '' === ob_get_clean(), 'ingen påminnelse när allt stämmer' );

ok( empty( $GLOBALS['get_instance_calls'] ), 'påminnelsen anropar inte WPCF7_ContactForm::get_instance()' );

echo "\n--- Byta formulär på sidorna ---\n";
$off = array(
	'ids'       => array( '36488', 'ca124e5', '36490' ),
	'title'     => 'Bokningsformulär självguidade v1.0',
	'shortcode' => '[contact-form-7 id="ca124e5" title="Bokningsformulär självguidade v1.0" tripcode="RESKOD"]',
);
list( $c, $n ) = CV_Bokningsval::swap_forms_in_content( '<p>Intro</p>[contact-form-7 id="cdee672" title="Självguidad cykling Active Spain Holland" tripcode="Trip 07-06"]<p>Slut</p>', $off );
ok( '<p>Intro</p>[contact-form-7 id="ca124e5" title="Bokningsformulär självguidade v1.0" tripcode="Trip 07-06"]<p>Slut</p>' === $c && 1 === $n, 'vanlig kortkod byts: ' . $c );
list( $c, $n ) = CV_Bokningsval::swap_forms_in_content( '[contact-form-7 id="ca124e5" title="Bokningsformulär självguidade v1.0" tripcode="DE-X"]', $off );
ok( 0 === $n, 'redan rätt formulär rörs inte' );
list( $c, $n ) = CV_Bokningsval::swap_forms_in_content( '[contact-form-7 id=&quot;2b3b45c&quot; title=&quot;Självguidad cykling EuroFun EJ HJÄLM&quot; tripcode=&quot;DE-MORTK-04A&quot;]', $off );
ok( '[contact-form-7 id=&quot;ca124e5&quot; title=&quot;Bokningsformulär självguidade v1.0&quot; tripcode=&quot;DE-MORTK-04A&quot;]' === $c, 'kortkod med &quot; byts: ' . $c );
list( $c, $n ) = CV_Bokningsval::swap_forms_in_content( '[contact-form-7 id="5a8fea7" tripcode="FRSB145"]', $off );
ok( '[contact-form-7 id="ca124e5" tripcode="FRSB145"]' === $c, 'kortkod utan titel: bara id byts' );
$broken = '[contact-form-7 id="cdee672" title="Holland" tripcode="<span data-sheets-root="1">Trip 07-06</span>"]';
list( $c, $n ) = CV_Bokningsval::swap_forms_in_content( $broken, $off );
ok( $broken === $c && 0 === $n, 'felaktig reskod hoppas över' );
$other = '[contact-form-7 id="999" title="Nyhetsbrev"] och [contact-form-7 id="5a8fea7" title="Gammalt" tripcode="ITSB290"]';
list( $c, $n ) = CV_Bokningsval::swap_forms_in_content( $other, $off );
ok( '[contact-form-7 id="999" title="Nyhetsbrev"] och [contact-form-7 id="ca124e5" title="Bokningsformulär självguidade v1.0" tripcode="ITSB290"]' === $c, 'formulär utan tripcode rörs inte: ' . $c );
$b64 = base64_encode( '<div>[contact-form-7 id="a96eb3d" title="Självguidad cykling HYRA HJÄLM" tripcode="Kattegattleden 4 nätter"]</div>' );
list( $c, $n ) = CV_Bokningsval::swap_forms_in_content( "före [fusion_code]{$b64}[/fusion_code] efter", $off );
preg_match( '#\[fusion_code\](.*?)\[/fusion_code\]#', $c, $mm );
ok( 1 === $n && '<div>[contact-form-7 id="ca124e5" title="Bokningsformulär självguidade v1.0" tripcode="Kattegattleden 4 nätter"]</div>' === base64_decode( $mm[1] ) && 0 === strpos( $c, 'före [fusion_code]' ) && ' efter' === substr( $c, -6 ), 'Avadas kodblock byts och kodas igen' );
list( $c, $n ) = CV_Bokningsval::swap_forms_in_content( '[contact-form-7 id="a" tripcode="X-1"][contact-form-7 id="b" tripcode="X-2"]', $off );
ok( 2 === $n, 'flera kortkoder på samma sida' );

echo "\n--- Knappen Byt formulär ---\n";
class Redirected extends Exception {}
function check_admin_referer( $a ) { return true; }
function wp_slash( $v ) { return $v; }
function get_current_user_id() { return 1; }
function set_transient( $k, $v, $t ) { $GLOBALS['transients'][ $k ] = $v; }
function wp_safe_redirect( $u ) { throw new Redirected( $u ); }
function wp_update_post( $a, $e ) { $GLOBALS['updated'][ $a['ID'] ] = $a['post_content']; foreach ( $GLOBALS['posts'] as $p ) { if ( $p->ID === $a['ID'] ) { $p->post_content = $a['post_content']; } } return $a['ID']; }
$GLOBALS['posts'] = array(
	(object) array( 'ID' => 10, 'post_title' => 'Hollands pärlor', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[contact-form-7 id="cdee672" title="Holland" tripcode="Trip 07-06"]' ),
	(object) array( 'ID' => 11, 'post_title' => 'Hollands pärlor NO', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[contact-form-7 id="cdee672" title="Holland NO" tripcode="Trip 07-06"]' ),
	(object) array( 'ID' => 12, 'post_title' => 'Mosel', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[contact-form-7 id="2b3b45c" title="EuroFun" tripcode="DE-MORWT-06"]' ),
	(object) array( 'ID' => 13, 'post_title' => 'Trasig', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[contact-form-7 id="2b3b45c" tripcode="<span data-sheets-root="1">X</span>"]' ),
	(object) array( 'ID' => 14, 'post_title' => 'Redan klar', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[contact-form-7 id="ca124e5" title="Bokningsformulär självguidade v1.0" tripcode="ITSB290"]' ),
);
$GLOBALS['cf7_posts'][36488]['_hash'] = 'ca124e5aaaa';
$GLOBALS['cf7_posts'][36490]['_form'] = $sv;
// En resa i taget.
$GLOBALS['updated'] = array();
$_POST              = array( 'codes' => array( 'DE-MORWT-06' ) );
try { CV_Bokningsval::handle_swap_forms(); } catch ( Redirected $e ) {}
ok( array( 12 ) === array_keys( $GLOBALS['updated'] ), 'Byt på en resa ändrar bara den resans sida' );
ok( false !== strpos( $GLOBALS['updated'][12], 'id="ca124e5"' ) && false !== strpos( $GLOBALS['updated'][12], 'tripcode="DE-MORWT-06"' ), 'sidan har nya formuläret och samma reskod' );
ok( ! isset( $GLOBALS['opts']['cvb_settings']['DE-MORWT-06'] ), 'Byt ändrar inte resans inställningar (Klar används inte längre)' );
// Alla.
$GLOBALS['updated'] = array();
$_POST              = array( 'all' => '1' );
try { CV_Bokningsval::handle_swap_forms(); } catch ( Redirected $e ) {}
ok( array( 10, 11 ) === array_keys( $GLOBALS['updated'] ), 'Byt alla: båda språken av Holland, inte den trasiga eller den som redan är klar' );

$msg = $GLOBALS['transients']['cvb_notice_1']['message'] ?? '';
ok( false !== strpos( $msg, 'på 2 sidor (1 resa)' ), 'meddelandet räknar sidor och resor: ' . strip_tags( $msg ) );
$_POST = array();

echo "\n--- Spara en rad direkt (AJAX) ---\n";
class Json_Response extends Exception { public $data; public $ok; }
function check_ajax_referer( $a ) { return true; }
function wp_send_json_success( $d ) { $e = new Json_Response(); $e->ok = true; $e->data = $d; throw $e; }
function wp_send_json_error( $d, $code = 0 ) { $e = new Json_Response(); $e->ok = false; $e->data = $d; throw $e; }
$GLOBALS['opts']['cvb_settings'] = array( 'ANNAN' => array( 'typ' => 'cykel', 'hjalm' => 'hyra', 'klar' => 1 ) );
$_POST = array( 'cvb' => array( 'Trip 07-06' => array( 'present' => '1', 'typ' => 'cykel', 'leverantor' => 'Dutch Bike tours', 'hjalm' => 'hyra', 'vanlig' => '1', 'el' => '1', 'stold' => '1', 'kommentar' => 'Hej', 'klar' => '1' ) ) );
$res = null;
try { CV_Bokningsval::ajax_save_row(); } catch ( Json_Response $e ) { $res = $e; }
$saved = $GLOBALS['opts']['cvb_settings']['Trip 07-06'] ?? array();
ok( $res && $res->ok && 1 === $saved['klar'] && 1 === $saved['stold'] && 0 === $saved['sportig'] && 'Hej' === $saved['kommentar'], 'raden sparas, urkryssade rutor blir 0' );
ok( isset( $GLOBALS['opts']['cvb_settings']['ANNAN'] ), 'andra resor rörs inte' );
$_POST = array( 'cvb' => array( 'A' => array( 'present' => '1' ), 'B' => array( 'present' => '1' ) ) );
$res = null;
try { CV_Bokningsval::ajax_save_row(); } catch ( Json_Response $e ) { $res = $e; }
ok( $res && ! $res->ok, 'mer än en resa i samma anrop nekas' );
$_POST = array();


echo $fel ? "\n$fel FEL\n" : "\nAlla OK\n";
exit( $fel ? 1 : 0 );
