<?php
// Kontroller vid inskick: e-postdomän och postnummer.
require __DIR__ . '/test-import.php';
$fel = 0;
function ok( $villkor, $text ) {
	global $fel;
	echo ( $villkor ? 'OK   ' : 'FEL  ' ) . $text . "\n";
	if ( ! $villkor ) {
		$fel++;
	}
}
class Res {
	public $fel = array();
	function invalidate( $tag, $msg ) { $this->fel[ $tag->name ] = $msg; }
	function is_valid( $name = null ) { return null === $name ? ! $this->fel : ! isset( $this->fel[ $name ] ); }
}
// Låtsas-DNS: bara dessa domäner finns.
$GLOBALS['dns_ok'] = array( 'gmail.com.' => 1, 'telia.com.' => 1, 'xn--rksmrgs-5wao1o.se.' => 1, 'bara-a.se.' => 'A' );
CV_Bokningsval::$dns_lookup = function ( $host, $type ) {
	$v = $GLOBALS['dns_ok'][ $host ] ?? 0;
	return 1 === $v || $type === $v;
};
$check = function ( $name, $value, $extra = array() ) {
	$_POST = array_merge( array( '_wpcf7' => '36488', 'tripcode' => 'FRSB191', '_wpcf7_locale' => 'sv_SE', $name => $value ), $extra );
	$t       = new Tag();
	$t->name = $name;
	return CV_Bokningsval::validate_field( new Res(), $t )->fel[ $name ] ?? '';
};

echo "\n--- E-post ---\n";
ok( '' === $check( 'your-email', 'anna@gmail.com' ), 'gmail.com godkänns' );
ok( '' !== $check( 'your-email', 'kfkfk@kfkfkfk.fd' ), 'kfkfkfk.fd stoppas (bokningen 2026-11-20)' );
ok( false !== strpos( $check( 'your-email', 'kfkfk@kfkfkfk.fd' ), 'Kontrollera e-postadressen' ), 'svenskt felmeddelande' );
ok( false !== strpos( $check( 'your-email', 'x@nope.zz', array( '_wpcf7_locale' => 'nb_NO' ) ), 'Kontroller e-postadressen' ), 'norskt felmeddelande' );
ok( '' === $check( 'your-email', 'per@telia.com.' ), 'punkt sist godkänns' );
ok( '' === $check( 'your-email', 'per@bara-a.se' ), 'domän utan MX men med A godkänns' );
ok( '' === $check( 'epost-3', 'ekonomi@telia.com' ), 'fakturamail godkänns' );
ok( '' !== $check( 'epost-3', 'ekonomi@telia.con' ), 'fakturamail med fel domän stoppas' );
ok( '' === $check( 'your-email', '' ), 'tomt fält: lämnas åt CF7' );
if ( function_exists( 'idn_to_ascii' ) ) {
	ok( '' === $check( 'your-email', 'a@räksmörgås.se' ), 'domän med åäö godkänns' );
}
ok( '' === $check( 'your-email', 'x@nope.zz', array( 'tripcode' => '' ) ), 'utan reskod: ingen kontroll' );
$GLOBALS['dns_ok'] = array();
ok( '' === $check( 'your-email', 'kfkfk@kfkfkfk.fd' ), 'DNS fungerar inte alls: släpps igenom' );
$GLOBALS['dns_ok'] = array( 'gmail.com.' => 1 );

echo "\n--- Postnummer ---\n";
ok( '' === $check( 'postnummer', '123 45' ), 'svenskt postnummer' );
ok( '' === $check( 'postnummer', '0150' ), 'norskt postnummer' );
ok( '' === $check( 'postnummer', 'SW1A 1AA' ), 'brittiskt postnummer' );
ok( '' !== $check( 'postnummer', 'Ll' ), '"Ll" stoppas (bokningen 2026-11-20)' );
ok( '' !== $check( 'postnummer-2', 'abc' ), 'fakturaadressens postnummer utan siffror stoppas' );
ok( '' === $check( 'ort', 'Kl' ), 'andra fält rörs inte' );

$r = new Res();
$r->fel['your-email'] = 'Fältet är obligatoriskt.';
$_POST = array( '_wpcf7' => '1', 'tripcode' => 'X', 'your-email' => 'x@nope.zz' );
$t = new Tag(); $t->name = 'your-email';
ok( 'Fältet är obligatoriskt.' === CV_Bokningsval::validate_field( $r, $t )->fel['your-email'], 'redan ogiltigt fält: CF7:s meddelande behålls' );
$_POST = array();

echo $fel ? "\n$fel FEL\n" : "\nAlla OK\n";
exit( $fel ? 1 : 0 );
