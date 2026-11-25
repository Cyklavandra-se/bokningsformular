<?php
/**
 * KOPIA för dokumentation – laddas inte av tillägget. Originalet ligger i Code Snippets
 * på sajten ("Bokningsformulär - alltid på svenska till oss"). Inklistrad av Pär 2026-11-25;
 * del 2 borttagen samma dag (Pär provbokade på norska: kunden får norska värden, vi svenska).
 *
 * Cykla & Vandra – bokningsmejlet till oss är alltid på svenska
 *
 * Gäller alla CF7-formulär som är översättningar (WPML) av ett svenskt formulär:
 * 1. Mejlet till oss (fliken Mail) byggs från det SVENSKA originalformulärets mall,
 *    oavsett vad som står i det översatta formulärets Mail-flik.
 * 2. (Borttagen. Kundens val blir svenska i mailet till oss genom tillägget
 *    Bokningsformulär, swedish_mail_value(), sedan 1.20.0.)
 * 3. Raden "Språk: sv/no/…" läggs in under BOKNING, så att automationen vet vilket språk
 *    kunden bokade på.
 *
 * Mejlet till kunden (Mail 2) rörs inte – det är kvar på kundens språk.
 *
 * Krav: fältnamnen (mail-taggarna, t.ex. faktura-1) och ordningen på alternativen
 * ska vara desamma i alla språk. WPML översätter bara texterna, så det håller normalt.
 *
 * Läggs in med Code Snippets: Lägg till ny, klistra in, välj "Kör överallt", aktivera.
 */

if ( ! defined( 'CV_SOURCE_LANG' ) ) {
	define( 'CV_SOURCE_LANG', 'sv' );   // originalspråket i WPML
}

// Formulärets språk enligt WPML, t.ex. 'sv' eller 'no'
function cv_form_lang( $form_id ) {
	$lang = apply_filters( 'wpml_element_language_code', null, array(
		'element_id'   => $form_id,
		'element_type' => 'post_wpcf7_contact_form',
	) );
	return $lang ? $lang : CV_SOURCE_LANG;
}

// Det svenska originalformuläret, eller null om formuläret redan är svenskt / saknar original
function cv_original_form( $form ) {
	if ( ! $form ) {
		return null;
	}
	$orig_id = (int) apply_filters( 'wpml_object_id', $form->id(), 'wpcf7_contact_form', false, CV_SOURCE_LANG );
	if ( ! $orig_id || $orig_id === (int) $form->id() ) {
		return null;
	}
	$orig = wpcf7_contact_form( $orig_id );
	return $orig ? $orig : null;
}

// 2. (Borttagen 2026-11-25: gjorde om listvärdena till svenska i hela inskicket, även i
//    Mail (2) till kunden. Tillägget gör det sedan 1.20.0 bara i mailet till oss.)

// 1 och 3. Svensk mejlmall + "Språk:"-raden (bara mejlet till oss, inte Mail 2)
add_action( 'wpcf7_before_send_mail', function ( $form ) {
	$lang = cv_form_lang( $form->id() );
	$mail = $form->prop( 'mail' );

	$orig = cv_original_form( $form );
	if ( $orig ) {
		$mail = $orig->prop( 'mail' );
	}

	if ( is_array( $mail ) && isset( $mail['body'] ) && false === strpos( $mail['body'], 'Språk:' ) ) {
		$count        = 0;
		$mail['body'] = preg_replace( '/^(BOKNING[ \t]*\R)/mu', '${1}Språk: ' . $lang . "\n", $mail['body'], 1, $count );
		if ( ! $count ) {
			$mail['body'] = 'Språk: ' . $lang . "\n" . $mail['body'];
		}
	}

	// Ändrar bara mejlet i den här inskickningen – formuläret sparas inte om
	$form->set_properties( array( 'mail' => $mail ) );
}, 1, 1 );
