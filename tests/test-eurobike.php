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
ok( CV_Bokningsval::is_eurobike( 'DE-MORTK-04A' ), 'DE-MORTK-04A är Eurobike' );
ok( CV_Bokningsval::is_eurobike( 'it-tos' ), 'it-tos är Eurobike (gemener)' );
ok( ! CV_Bokningsval::is_eurobike( 'RWR123' ), 'RWR123 är inte Eurobike' );
ok( ! CV_Bokningsval::is_eurobike( 'DEU-1' ), 'DEU-1 är inte Eurobike' );
ok( ! CV_Bokningsval::is_eurobike( '' ), 'tom kod är inte Eurobike' );

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
ok( 2 === $GLOBALS['opts']['cvb_schema'], 'engångsändringen är markerad som gjord' );
$GLOBALS['opts']['cvb_settings']['AT-1']['sportig'] = 0;
CV_Bokningsval::migrate();
ok( 0 === $GLOBALS['opts']['cvb_settings']['AT-1']['sportig'], 'körs bara en gång (går att kryssa ur efteråt)' );

echo "\n--- Import med kolumnen Kollad ---\n";
$GLOBALS['opts'] = array();
file_put_contents( '/tmp/c2.csv', "Reskod,Hjälm,Vanlig cykel,Elcykel,Sportig cykel,Egen cykel,Kollad\nDE-DON,Att hyra,TRUE,TRUE,FALSE,TRUE,FALSE\n" );
CV_Bokningsval::import_csv_file( '/tmp/c2.csv' );
$r = $GLOBALS['opts']['cvb_settings']['DE-DON'];
ok( 1 === $r['sportig'], 'import: Eurobike får sportig även om filen säger FALSE' );
ok( 0 === $r['klar'], 'import: kolumnen Kollad läses' );

echo $fel ? "\n$fel FEL\n" : "\nAlla OK\n";
exit( $fel ? 1 : 0 );
