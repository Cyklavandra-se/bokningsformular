<?php
// Fler språk (da, fi, en-us): svenska värden i mailet till oss, mailmallarnas
// taggar, CF7 Skins och felmeddelanden.
require __DIR__ . '/test-import.php';
$fel = 0;
function ok( $villkor, $text ) {
	global $fel;
	echo ( $villkor ? 'OK   ' : 'FEL  ' ) . $text . "\n";
	if ( ! $villkor ) {
		$fel++;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
}
class WPCF7_Mail {
	static $cur = 'mail';
	static function get_current() { return new self(); }
	function name() { return self::$cur; }
}
class MailTag {
	public $n;
	function __construct( $n ) { $this->n = $n; }
	function tag_name() { return $this->n; }
	function field_name() { return preg_replace( '/^_raw_/', '', $this->n ); }
}

$sv = file_get_contents( __DIR__ . '/../formularmall-sv.txt' );
$tr = function ( $map ) use ( $sv ) {
	return strtr( $sv, $map );
};
$da = $tr( array(
	'"Vanlig cykel" "Elcykel" "Sportig cykel" "Tar med egen" "Gravelcykel"' => '"Almindelig cykel" "Elcykel" "Sporty cykel" "Medbringer egen" "Gravelcykel"',
	'"Hyr hjälm" "Tar med egen" "Hjälm (gratis)"'                           => '"Lej hjelm" "Medbringer egen" "Hjelm (gratis)"',
	'"Ingen egen faktura" "Separat faktura"'                                => '"Ingen separat faktura" "Separat faktura"',
	'[select* cykelforsakring include_blank "Ja" "Nej"]'                    => '[select* cykelforsakring include_blank "Ja" "Nej"]',
	'"Kategori A" "Kategori B"'                                             => '"Kategori A" "Kategori B"',
) );
$fi = $tr( array(
	'"Vanlig cykel" "Elcykel" "Sportig cykel" "Tar med egen" "Gravelcykel"' => '"Tavallinen pyörä" "Sähköpyörä" "Urheilullinen pyörä" "Oma pyörä" "Gravel-pyörä"',
	'"Hyr hjälm" "Tar med egen" "Hjälm (gratis)"'                           => '"Vuokrakypärä" "Oma kypärä" "Kypärä (ilmainen)"',
	'"Ingen egen faktura" "Separat faktura"'                                => '"Ei omaa laskua" "Erillinen lasku"',
	'[select* cykelforsakring include_blank "Ja" "Nej"]'                    => '[select* cykelforsakring include_blank "Kyllä" "Ei"]',
	'"Standard" "Superior" "Havsutsikt"'                                    => '"Vakio" "Superior" "Merinäköala"',
) );
// Engelska med pipes (Etikett|svenskt värde) ska också fungera.
$en = $tr( array(
	'"Vanlig cykel" "Elcykel" "Sportig cykel" "Tar med egen" "Gravelcykel"' => '"Standard bike|Vanlig cykel" "E-bike|Elcykel" "Sporty bike|Sportig cykel" "Bringing my own|Tar med egen" "Gravel bike|Gravelcykel"',
) );
$GLOBALS['cf7_posts'] = array(
	36488 => array( 'title' => 'Bokningsformulär', '_form' => $sv ),
	40001 => array( 'title' => 'DA', '_form' => $da ),
	40002 => array( 'title' => 'FI', '_form' => $fi ),
	40003 => array( 'title' => 'EN', '_form' => $en ),
);
$GLOBALS['filter_returns'] = array( 'wpml_default_language' => 'sv', 'wpml_object_id' => 36488 );

$val = function ( $form, $tag, $submitted, $mail = 'mail' ) {
	WPCF7_Mail::$cur = $mail;
	$_POST = array( '_wpcf7' => (string) $form, 'tripcode' => 'FRSB191' );
	return CV_Bokningsval::swedish_mail_value( is_array( $submitted ) ? implode( ', ', $submitted ) : $submitted, $submitted, false, new MailTag( $tag ) );
};

echo "\n--- Mail (1): svenska värden ---\n";
ok( 'Elcykel' === $val( 40002, 'cykel-1', 'Sähköpyörä' ), 'finska: Sähköpyörä blir Elcykel' );
ok( 'Gravelcykel' === $val( 40002, 'cykel-3', 'Gravel-pyörä' ), 'finska: Gravel (plats 5)' );
ok( 'Tar med egen' === $val( 40002, 'hjalm-2', 'Oma kypärä' ), 'finska: Oma kypärä blir Tar med egen (även dolda hjälmfältet)' );
ok( 'Hjälm (gratis)' === $val( 40001, 'hjalm-1', 'Hjelm (gratis)' ), 'danska: Hjälm (gratis)' );
ok( 'Vanlig cykel' === $val( 40001, 'cykel-1', 'Almindelig cykel' ), 'danska: Vanlig cykel' );
ok( 'Ingen egen faktura' === $val( 40001, 'faktura-2', 'Ingen separat faktura' ), 'danska: Faktura (radio)' );
ok( 'Separat faktura' === $val( 40002, 'faktura-4', 'Erillinen lasku' ), 'finska: Separat faktura' );
ok( 'Ja' === $val( 40002, 'cykelforsakring', 'Kyllä' ), 'finska: Kyllä blir Ja' );
ok( 'Havsutsikt' === $val( 40002, 'rumskategori', 'Merinäköala' ), 'finska: boendekategori' );
ok( '8+' === $val( 40002, 'antal', '8+' ), 'antal oförändrat' );
ok( '172' === $val( 40002, 'langd-1', '172' ), 'längd (inte en lista) rörs inte' );
ok( 'Mikko' === $val( 40002, 'namn-2', 'Mikko' ), 'namn rörs inte' );
ok( 'Elcykel' === $val( 40003, 'cykel-1', 'Elcykel' ), 'engelska med pipes: värdet efter |' );
ok( 'Elcykel' === $val( 40003, 'cykel-1', 'E-bike' ), 'engelska med pipes: etiketten känns också igen' );
ok( 'Sähköpyörä' === $val( 40002, 'cykel-1', 'Sähköpyörä', 'mail_2' ), 'Mail (2) till kunden: kundens språk' );
ok( 'Elcykel' === $val( 36488, 'cykel-1', 'Elcykel' ), 'svenska formuläret: oförändrat' );
ok( 'Okänd' === $val( 40002, 'cykel-1', 'Okänd' ), 'okänt värde: skickas som det är' );
// Snippeten "alltid på svenska till oss" har redan gjort om värdet till svenska (wpcf7_posted_data).
ok( 'Elcykel' === $val( 40002, 'cykel-1', 'Elcykel' ), 'redan svenskt värde (snippeten): oförändrat, finska' );
ok( 'Elcykel' === $val( 40001, 'cykel-1', 'Elcykel' ), 'redan svenskt värde (snippeten): oförändrat, danska' );
ok( 'Tar med egen' === $val( 40002, 'hjalm-1', 'Tar med egen' ), 'redan svenskt hjälmvärde: oförändrat' );
ok( 'Ingen egen faktura' === $val( 40001, 'faktura-2', 'Ingen egen faktura' ), 'redan svenskt fakturaval: oförändrat' );
$_POST = array( '_wpcf7' => '40002', 'tripcode' => '' );
WPCF7_Mail::$cur = 'mail';
ok( 'Sähköpyörä' === CV_Bokningsval::swedish_mail_value( 'Sähköpyörä', 'Sähköpyörä', false, new MailTag( 'cykel-1' ) ), 'utan reskod: oförändrat' );
$GLOBALS['filter_returns'] = array();
ok( 'Sähköpyörä' === $val( 40002, 'cykel-1', 'Sähköpyörä' ), 'utan WPML: oförändrat' );
$GLOBALS['filter_returns'] = array( 'wpml_default_language' => 'sv', 'wpml_object_id' => 36488 );
ok( 'havsutsikt' === CV_Bokningsval::posted_room_key( $fi, 'Merinäköala' ), 'boendekategorins reskod ([cvb-reskod]) fungerar på finska' );

echo "\n--- Mail (2): taggarna i översättningen ---\n";
$m2 = array( 'subject' => 'Bokning [_post_title]', 'body' => file_get_contents( __DIR__ . '/../mailmall-bekraftelse-sv.txt' ) );
ok( array() === CV_Bokningsval::compare_mail( $m2, $m2, 'Mail (2)' ), 'oförändrade taggar: inga varningar' );
$bad = $m2;
$bad['body'] = str_replace( array( '[cvb-hjalm-nej]', '[cvb-rum]' ), array( '[cvb-hjelm-nei]', '' ), $bad['body'] );
$iss = CV_Bokningsval::compare_mail( $m2, $bad, 'Mail (2)' );
ok( (bool) array_filter( $iss, function ( $i ) { return false !== strpos( $i, 'saknar' ) && false !== strpos( $i, '[cvb-hjalm-nej]' ) && false !== strpos( $i, '[cvb-rum]' ); } ), 'översatt och borttagen blocktagg upptäcks' );
ok( (bool) array_filter( $iss, function ( $i ) { return false !== strpos( $i, '[cvb-hjelm-nei] stängs inte' ); } ), 'block som inte stängs upptäcks' );
$bad = $m2;
$bad['body'] = str_replace( '[hjalm-1]', '[kypärä-1]', $bad['body'] );
ok( (bool) CV_Bokningsval::compare_mail( $m2, $bad, 'Mail (2)' ), 'översatt fälttagg upptäcks' );
ok( array() === CV_Bokningsval::compare_mail( '', $bad, 'Mail (2)' ), 'original utan mall: ingen jämförelse' );

echo "\n--- CF7 Skins ---\n";
$GLOBALS['cf7_posts'][36488]['cf7s_style'] = 'default.css';
$GLOBALS['cf7_posts'][40001]['cf7s_style'] = 'default.css';
ok( array() === CV_Bokningsval::compare_skin( 36488, 40001 ), 'samma stil: ok' );
ok( 1 === count( CV_Bokningsval::compare_skin( 36488, 40002 ) ), 'stil saknas: varning' );
unset( $GLOBALS['cf7_posts'][36488]['cf7s_style'] );
ok( array() === CV_Bokningsval::compare_skin( 36488, 40002 ), 'originalet utan stil: ingen varning' );

echo "\n--- Felmeddelanden ---\n";
foreach ( array( 'sv_SE' => 'Kontrollera e-post', 'nb_NO' => 'Kontroller e-post', 'da_DK' => 'Kontrollér e-mail', 'fi' => 'Tarkista sähköposti', 'fi_FI' => 'Tarkista sähköposti', 'en_US' => 'Please check the email', 'de_DE' => 'Please check the email' ) as $loc => $start ) {
	$_POST = array( '_wpcf7_locale' => $loc );
	ok( 0 === strpos( CV_Bokningsval::message( 'email' ), $start ), "e-post, $loc" );
}
$_POST = array( '_wpcf7_locale' => 'da_DK' );
ok( 'Kontrollér postnummeret.' === CV_Bokningsval::message( 'postcode' ), 'postnummer, da_DK' );
$_POST = array();

echo $fel ? "\n$fel FEL\n" : "\nAlla OK\n";
exit( $fel ? 1 : 0 );
