<?php
// Rumskategorier: inställningar, formuläret, reskod per kategori och mailen.
require __DIR__ . '/test-import.php';
$fel = 0;
function ok( $villkor, $text ) {
	global $fel;
	echo ( $villkor ? 'OK   ' : 'FEL  ' ) . $text . "\n";
	if ( ! $villkor ) {
		$fel++;
	}
}
$sv_form = file_get_contents( __DIR__ . '/../formularmall-sv.txt' );
$no_form = str_replace( '"Standard" "Superior" "Havsutsikt" "Kategori A" "Kategori B" "Charm" "Premium"', '"Standard" "Superior" "Havutsikt" "Kategori A" "Kategori B" "Sjarm" "Premium"', $sv_form );
$all     = array( 'Standard', 'Superior', 'Havsutsikt', 'Kategori A', 'Kategori B', 'Charm', 'Premium' );

echo "\n--- Inställningar ---\n";
$row = CV_Bokningsval::sanitize_row( array( 'typ' => 'cykel', 'rum' => array( 'standard' => '1', 'superior' => '1', 'hack' => '1' ), 'rum_kod' => array( 'standard' => ' FRSB660 ', 'superior' => 'FRSB661', 'charm' => '' ) ) );
ok( array( 'standard', 'superior' ) === $row['rum'], 'bara kända kategorier sparas' );
ok( array( 'standard' => 'FRSB660', 'superior' => 'FRSB661' ) === $row['rum_kod'], 'reskoder per kategori, tomma utelämnas' );
ok( array() === CV_Bokningsval::defaults( 'X' )['rum'], 'standard: inga kategorier' );

$GLOBALS['opts'] = array( 'cvb_settings' => array(
	'FRSB660'      => $row,
	'DE-BORKK-07A' => CV_Bokningsval::sanitize_row( array( 'typ' => 'cykel', 'hjalm' => 'nej', 'rum' => array( 'kat_a' => 1, 'kat_b' => 1, 'charm' => 1 ), 'rum_kod' => array( 'kat_b' => 'DE-BORKK-07B', 'charm' => 'DE-BORKK-07D' ) ) ),
	'ITSW250'      => CV_Bokningsval::sanitize_row( array( 'typ' => 'vandring', 'rum' => array( 'standard' => 1, 'havsutsikt' => 1 ) ) ),
	'EN'           => CV_Bokningsval::sanitize_row( array( 'typ' => 'cykel', 'rum' => array( 'superior' => 1 ) ) ),
	'INGA'         => CV_Bokningsval::sanitize_row( array( 'typ' => 'cykel' ) ),
) );
$GLOBALS['cur'] = 0;

echo "\n--- Formuläret ---\n";
$tag = function () use ( $all ) { return mk( 'select*', 'rumskategori', $all ); };
WPCF7_ContactForm::$attr = 'FRSB660';
$r = CV_Bokningsval::filter_form_tag( $tag() );
ok( array( 'Standard', 'Superior' ) === $r->values && 'select*' === $r->type, 'Canal du Midi: Standard och Superior, obligatoriskt' );
WPCF7_ContactForm::$attr = 'DE-BORKK-07A';
$r = CV_Bokningsval::filter_form_tag( $tag() );
ok( array( 'Kategori A', 'Kategori B', 'Charm' ) === $r->values, 'Bodensjön: A, B och Charm' );
WPCF7_ContactForm::$attr = 'ITSW250';
$r = CV_Bokningsval::filter_form_tag( $tag() );
ok( array( 'Standard', 'Havsutsikt' ) === $r->values, 'vandringsresa med havsutsikt: rutan finns' );
WPCF7_ContactForm::$attr = 'EN';
$r = CV_Bokningsval::filter_form_tag( $tag() );
ok( 'select' === $r->type && 7 === count( $r->values ), 'bara en kategori: inget val, fältet frivilligt' );
WPCF7_ContactForm::$attr = 'INGA';
$r = CV_Bokningsval::filter_form_tag( $tag() );
ok( 'select' === $r->type, 'inga kategorier: fältet frivilligt' );
$html = '<p>x</p><div class="cvb-rum"><label> Rumskategori <select name="rumskategori"></select></label></div><p>y</p>';
ok( '<p>x</p><p>y</p>' === CV_Bokningsval::filter_form_html( $html ), 'inga kategorier: rutan tas bort' );
WPCF7_ContactForm::$attr = 'FRSB660';
ok( $html === CV_Bokningsval::filter_form_html( $html ), 'med kategorier: rutan finns kvar' );
WPCF7_ContactForm::$attr = '';
ok( $tag()->values === CV_Bokningsval::filter_form_tag( $tag() )->values, 'utan reskod (valideringsschemat): alla kategorier' );

echo "\n--- Vilken kategori valdes ---\n";
ok( 'superior' === CV_Bokningsval::posted_room_key( $sv_form, 'Superior' ), 'svenska: Superior' );
ok( 'charm' === CV_Bokningsval::posted_room_key( $no_form, 'Sjarm' ), 'norska: Sjarm är Charm (efter position)' );
ok( 'havsutsikt' === CV_Bokningsval::posted_room_key( '[select* rumskategori "Standard" "Superior|Superior" "Med havsutsikt|Havsutsikt"]', 'Med havsutsikt' ), 'formulär med pipes' );
ok( '' === CV_Bokningsval::posted_room_key( $sv_form, 'Okänt' ), 'okänt värde: ingen kategori' );
ok( '' === CV_Bokningsval::posted_room_key( '[text namn]', 'Superior' ), 'formulär utan rumskategori' );

ok( 'premium' === CV_Bokningsval::posted_room_key( $sv_form, 'Premium' ), 'Premium (plats 7)' );
ok( '' === CV_Bokningsval::posted_room_key( '[select* rumskategori "Standard" "Superior" "Havsutsikt" "Kategori A" "Kategori B" "Charm"]', 'Premium' ), 'formulär utan Premium: inget fel' );
$GLOBALS['opts']['cvb_settings']['PREM'] = CV_Bokningsval::sanitize_row( array( 'typ' => 'cykel', 'rum' => array( 'standard' => 1, 'premium' => 1 ), 'rum_kod' => array( 'premium' => 'PREM-P' ) ) );
WPCF7_ContactForm::$attr = 'PREM';
ok( array( 'Standard', 'Premium' ) === CV_Bokningsval::filter_form_tag( $tag() )->values, 'Standard och Premium' );
WPCF7_ContactForm::$attr = '';

echo "\n--- Mailen ---\n";
$GLOBALS['cf7_posts'][36488] = array( 'title' => 'Bokningsformulär', '_form' => $sv_form, '_hash' => 'ca124e5xx' );
$GLOBALS['cf7_posts'][36510] = array( 'title' => 'Bokningsformulär', '_form' => $no_form, '_hash' => 'ca124e5xx' );
$mail1 = file_get_contents( __DIR__ . '/../mailmall-till-oss-sv.txt' );
$send  = function ( $code, $form, $room ) use ( $mail1 ) {
	$_POST = array( '_wpcf7' => $form, 'tripcode' => $code, 'rumskategori' => $room );
	$c     = CV_Bokningsval::filter_mail_components( array( 'subject' => 'Bokning', 'body' => $mail1 ) );
	$_POST = array();
	return $c['body'];
};
$b = $send( 'FRSB660', 36488, 'Superior' );
ok( false !== strpos( $b, "Bokningskod: FRSB661\n" ) && false !== strpos( $b, "Rumskategori: [rumskategori]\n" ), 'Canal du Midi, Superior: bokningskod FRSB661 och raden Rumskategori' );
$b = $send( 'FRSB660', 36488, 'Standard' );
ok( false !== strpos( $b, "Bokningskod: FRSB660\n" ), 'Canal du Midi, Standard: FRSB660' );
$b = $send( 'DE-BORKK-07A', 36510, 'Sjarm' );
ok( false !== strpos( $b, "Bokningskod: DE-BORKK-07D\n" ), 'Bodensjön från norska formuläret, Charm: DE-BORKK-07D' );
$b = $send( 'DE-BORKK-07A', 36510, 'Kategori A' );
ok( false !== strpos( $b, "Bokningskod: DE-BORKK-07A\n" ), 'Bodensjön, Kategori A utan egen kod: resans reskod' );
$b = $send( 'INGA', 36488, '' );
ok( false !== strpos( $b, "Bokningskod: INGA\n" ) && false === strpos( $b, 'Rumskategori' ) && false === strpos( $b, '[cvb-' ), 'resa utan kategorier: reskoden, ingen rad om rum' );
$b = $send( 'EN', 36488, '' );
ok( false === strpos( $b, 'Rumskategori' ), 'bara en kategori: ingen rad om rum' );
$m2 = CV_Bokningsval::apply_mail_blocks( file_get_contents( __DIR__ . '/../mailmall-bekraftelse-sv.txt' ), CV_Bokningsval::settings_for( 'ITSW250', 0 ) );
ok( false !== strpos( $m2, "Antal resenärer: [antal]\nRumskategori: [rumskategori]\nRabattkod" ), 'bekräftelsen: raden Rumskategori på resa med val' );

echo "\n--- Översättningar ---\n";
$utan = str_replace( '<div class="cvb-rum">', '<div>', $no_form );
ok( in_array( 'cvb-rum finns 0 gånger i stället för 1', CV_Bokningsval::compare_structure( $sv_form, $utan ), true ), 'översättning utan cvb-rum varnas' );
ok( array() === CV_Bokningsval::compare_structure( $sv_form, $no_form ), 'översatta kategorinamn: inga skillnader' );

echo $fel ? "\n$fel FEL\n" : "\nAlla OK\n";
exit( $fel ? 1 : 0 );
