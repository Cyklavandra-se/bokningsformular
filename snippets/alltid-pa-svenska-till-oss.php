<?php
/**
 * KOPIA för dokumentation – laddas inte av tillägget. Originalet ligger i Code Snippets
 * på sajten ("Bokningsformulär - alltid på svenska till oss"). Inklistrad av Pär 2026-11-25.
 *
 * Cykla & Vandra – bokningsmejlet till oss är alltid på svenska
 *
 * Gäller alla CF7-formulär som är översättningar (WPML) av ett svenskt formulär:
 * 1. Mejlet till oss (fliken Mail) byggs från det SVENSKA originalformulärets mall,
 *    oavsett vad som står i det översatta formulärets Mail-flik.
 * 2. Kundens val i rullistor, radioknappar och kryssrutor (fakturaval, cykeltyp, hjälm …)
 *    översätts tillbaka till svenska utifrån placeringen i listan:
 *    det andra alternativet på norska blir det andra alternativet i det svenska formuläret.
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

// 2. Kundens val -> svenska alternativ (samma placering i listan)
add_filter( 'wpcf7_posted_data', function ( $posted ) {
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		return $posted;
	}
	$form = WPCF7_ContactForm::get_current();
	$orig = cv_original_form( $form );
	if ( ! $orig ) {
		return $posted;
	}

	$orig_tags = array();
	foreach ( $orig->scan_form_tags() as $t ) {
		if ( ! empty( $t->name ) ) {
			$orig_tags[ $t->name ] = $t;
		}
	}

	$tags = $form->scan_form_tags( array( 'basetype' => array( 'select', 'radio', 'checkbox' ) ) );
	foreach ( $tags as $tag ) {
		$name = $tag->name;
		if ( empty( $name ) || ! isset( $posted[ $name ], $orig_tags[ $name ] ) ) {
			continue;
		}
		$from = array_values( (array) $tag->values );
		$to   = array_values( (array) $orig_tags[ $name ]->values );
		$map  = function ( $v ) use ( $from, $to ) {
			$i = array_search( $v, $from, true );
			return ( false !== $i && isset( $to[ $i ] ) ) ? $to[ $i ] : $v;   // okänt värde lämnas som det är
		};
		$posted[ $name ] = is_array( $posted[ $name ] )
			? array_map( $map, $posted[ $name ] )
			: $map( $posted[ $name ] );
	}
	return $posted;
} );

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
