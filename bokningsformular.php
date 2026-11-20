<?php
/**
 * Plugin Name: Cykla & Vandra – Bokningsformulär
 * Description: Ett bokningsformulär (Contact Form 7) för både cykel- och vandringsresor. Styr vilka cykeltyper, hjälmval och försäkringar som visas för varje resa, utifrån reskoden (tripcode) i kortkoden.
 * Version: 1.18.1
 * Author: Cykla & Vandra
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: bokningsformular
 * Update URI: https://github.com/cyklavandra-se/bokningsformular
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/cyklavandra-meny.php';
require_once __DIR__ . '/updater.php';
CV_Bokningsval_Updater::init( __FILE__ );

final class CV_Bokningsval {

	const OPTION = 'cvb_settings';
	const CAP    = 'edit_pages';
	const SLUG   = 'bokningsformular';

	/**
	 * Ordningen på alternativen i formulärets listor. Formuläret måste ha
	 * alternativen i exakt denna ordning (texterna får vara på valfritt språk).
	 *
	 * cykel-N: "Vanlig cykel" "Elcykel" "Sportig cykel" "Tar med egen" "Gravelcykel"
	 * hjalm-N: "Hyr hjälm" "Tar med egen" "Hjälm (gratis)"
	 *
	 * Gravel lades till sist i formuläret (position 4, Pär 2026-11-18), så att de
	 * äldre positionerna inte flyttades. BIKE_INDEX räknar upp positionerna i den
	 * ordning de visas för kunden: Gravel före "Tar med egen".
	 *
	 * Listorna i HELMET_INDEX visas i den ordning de står. När hjälmen ingår
	 * väljer kunden mellan "Hjälm (gratis)" och "Tar med egen" (Pär, 2026-11-18).
	 */
	const BIKE_INDEX   = array( 0 => 'vanlig', 1 => 'el', 2 => 'sportig', 4 => 'gravel', 3 => 'egen' );
	/**
	 * Boendekategorier i listan rumskategori, efter position (Pär, 2026-11-19).
	 * Nya kategorier läggs till sist, både här och i formuläret, så att de
	 * befintliga positionerna inte flyttas.
	 *
	 * rumskategori: "Standard" "Superior" "Havsutsikt" "Kategori A" "Kategori B" "Charm" "Premium" "Nivå A" "Nivå B"
	 * (Premium, Nivå A och Nivå B lades till 2026-11-20.)
	 */
	const ROOM_INDEX = array(
		0 => 'standard',
		1 => 'superior',
		2 => 'havsutsikt',
		3 => 'kat_a',
		4 => 'kat_b',
		5 => 'charm',
		6 => 'premium',
		7 => 'niva_a',
		8 => 'niva_b',
	);

	public static function room_choices() {
		return array(
			'standard'   => 'Standard',
			'superior'   => 'Superior',
			'havsutsikt' => 'Havsutsikt',
			'kat_a'      => 'Kategori A',
			'kat_b'      => 'Kategori B',
			'charm'      => 'Charm',
			'premium'    => 'Premium',
			'niva_a'     => 'Nivå A',
			'niva_b'     => 'Nivå B',
		);
	}

	const HELMET_INDEX = array(
		'hyra'  => array( 0, 1 ),
		'nej'   => array( 1 ),
		'ingar' => array( 2, 1 ),
	);

	public static function bool_fields() {
		return array(
			'vanlig'    => 'Vanlig cykel',
			'el'        => 'Elcykel',
			'sportig'   => 'Sportig cykel',
			'gravel'    => 'Gravel',
			'egen'      => 'Egen cykel',
			'stold'     => 'Stöldförsäkring',
			'assistans' => 'Assistansförsäkring',
		);
	}

	public static function helmet_choices() {
		return array(
			'hyra'  => 'Att hyra',
			'ingar' => 'Ingår',
			'nej'   => 'Erbjuds inte',
		);
	}

	public static function type_choices() {
		return array(
			'cykel'    => 'Cykel',
			'vandring' => 'Vandring',
		);
	}

	/** Gissar resans typ utifrån adressen (svenska originalet om WPML finns). */
	public static function guess_type( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return 'cykel';
		}
		$default_lang = apply_filters( 'wpml_default_language', null );
		if ( $default_lang ) {
			$original = apply_filters( 'wpml_object_id', $post_id, get_post_type( $post_id ), true, $default_lang );
			if ( $original ) {
				$post_id = (int) $original;
			}
		}
		$url = (string) get_permalink( $post_id );
		return preg_match( '#/(vandra|vandring|vandre|fottur)#i', $url ) ? 'vandring' : 'cykel';
	}

	/** Visa kolumnen Kommentar på adminsidan. Dold tills vidare; sparade kommentarer finns kvar. */
	const SHOW_COMMENT = false;

	/** Leverantörer som aldrig hyr ut hjälm (jämförs med norm()). */
	const NO_HELMET_SUPPLIERS = array( 'eurobike', 'radwegreisen', 'velociped' );

	/**
	 * Resor från Eurofun-gruppen (Eurobike, Radweg-Reisen, Velociped och
	 * Eurohike) har reskoder som börjar med landskod och bindestreck, t.ex.
	 * DE-MORTK-04A. Cykelresorna har alltid sportig cykel och ingen hjälmhyra.
	 */
	public static function is_eurofun( $code ) {
		return (bool) preg_match( '/^[A-Z]{2}-/', strtoupper( (string) $code ) );
	}

	/** Leverantörer som aldrig hyr ut hjälm: Eurobike, Radweg-Reisen och Velociped. */
	public static function supplier_without_helmet( $supplier ) {
		return in_array( self::norm( $supplier ), self::NO_HELMET_SUPPLIERS, true );
	}

	/**
	 * S-Capes resor i Frankrike har hjälm som ingår. Deras franska reskoder
	 * börjar med FRSB (cykel) eller FRSW (vandring), t.ex. FRSB145.
	 */
	public static function is_scape_france( $code, $supplier = '' ) {
		$code = strtoupper( (string) $code );
		return (bool) preg_match( '/^FRS[BW]/', $code )
			|| ( 'scape' === self::norm( $supplier ) && 0 === strpos( $code, 'FR' ) );
	}

	/**
	 * Hjälmvalet som leverantören bestämmer, eller '' om det är fritt.
	 * S-Cape i Frankrike: ingår. Eurobike, Radweg-Reisen och Velociped: erbjuds inte.
	 */
	public static function helmet_rule( $code, $supplier = '' ) {
		if ( self::is_scape_france( $code, $supplier ) ) {
			return 'ingar';
		}
		if ( self::is_eurofun( $code ) || self::supplier_without_helmet( $supplier ) ) {
			return 'nej';
		}
		return '';
	}

	/**
	 * Leverantören enligt arket Booking Codes (data/leverantorer.php). Saknas
	 * reskoden där känns S-Capes koder igen på mönstret, t.ex. ITSB290 och FRSW260,
	 * och Eurofuns koder (landskod och bindestreck) blir "Eurofun".
	 */
	public static function supplier_for( $code ) {
		static $map = null;
		if ( null === $map ) {
			$map  = array();
			$list = include __DIR__ . '/data/leverantorer.php';
			foreach ( is_array( $list ) ? $list : array() as $list_code => $supplier ) {
				$map[ self::code_key( $list_code ) ] = $supplier;
			}
		}
		$code = self::normalize_code( $code );
		$key  = self::code_key( $code );
		if ( '' !== $key && isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}
		if ( preg_match( '/^[A-Z]{2}S[BWM][A-Z]*\d/', strtoupper( $code ) ) ) {
			return 'S-Cape';
		}
		// Nya Eurofun-resor (Eurobike, Radweg-Reisen, Velociped, Eurohike) som ännu
		// inte finns i listan känns igen på reskoden (Pär, 2026-11-19).
		if ( self::is_eurofun( $code ) ) {
			return 'Eurofun';
		}
		return '';
	}

	/**
	 * Jämförelsenyckel för reskoder i leverantörslistan: gemener, bara bokstäver och
	 * siffror. "Trip 08-08", "Trip: 08-08" och "trip 0808" ger samma nyckel.
	 */
	public static function code_key( $code ) {
		return preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( (string) $code, 'UTF-8' ) );
	}

	/** Standardläge för resor som inte är ifyllda. */
	public static function defaults( $code = '' ) {
		$supplier = '' === $code ? '' : self::supplier_for( $code );
		$helmet   = self::helmet_rule( $code, $supplier );
		return array(
			'typ'       => '',
			'leverantor' => $supplier,
			'hjalm'     => $helmet ? $helmet : 'hyra',
			'vanlig'    => 1,
			'el'        => 1,
			'sportig'   => self::is_eurofun( $code ) ? 1 : 0,
			'gravel'    => 0,
			'egen'      => 1,
			'stold'     => 0,
			'assistans' => 0,
			'kommentar' => '',
			'klar'      => 0,
			'rum'       => array(),
			'rum_kod'   => array(),
		);
	}

	public static function init() {
		// Gör att tripcode="..." i kortkoden går att läsa (ofarligt om en snippet redan gör samma sak).
		add_filter( 'shortcode_atts_wpcf7', array( __CLASS__, 'allow_tripcode_attr' ), 10, 3 );
		add_filter( 'wpcf7_form_tag', array( __CLASS__, 'filter_form_tag' ), 20, 2 );
		add_filter( 'wpcf7_form_elements', array( __CLASS__, 'filter_form_html' ), 20 );
		add_filter( 'wpcf7_form_elements', array( __CLASS__, 'add_form_css' ), 30 );
		add_filter( 'wpcf7_mail_components', array( __CLASS__, 'filter_mail_components' ), 20 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'migrate' ) );
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
			add_filter( 'cyklavandra_verktyg', array( __CLASS__, 'tool_card' ) );
			add_action( 'admin_notices', array( __CLASS__, 'translation_notice' ) );
			add_filter( 'plugin_action_links', array( __CLASS__, 'settings_link' ), 5, 2 );
			add_action( 'admin_post_cvb_save', array( __CLASS__, 'handle_save' ) );
			add_action( 'wp_ajax_cvb_save_row', array( __CLASS__, 'ajax_save_row' ) );
			add_action( 'admin_post_cvb_import', array( __CLASS__, 'handle_import' ) );
			add_action( 'admin_post_cvb_export', array( __CLASS__, 'handle_export' ) );
			add_action( 'admin_post_cvb_swap_forms', array( __CLASS__, 'handle_swap_forms' ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Inställningar
	 * ------------------------------------------------------------------ */

	public static function all_settings() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Inställningarna för en reskod. Saknas sparad typ gissas den utifrån sidan.
	 */
	public static function settings_for( $code, $post_id = 0 ) {
		$all = self::all_settings();
		if ( '' !== $code && isset( $all[ $code ] ) && is_array( $all[ $code ] ) ) {
			$s = wp_parse_args( $all[ $code ], self::defaults( $code ) );
		} else {
			$s = self::defaults( $code );
		}
		if ( ! array_key_exists( $s['typ'], self::type_choices() ) ) {
			$s['typ'] = self::guess_type( $post_id );
		}
		if ( '' === trim( (string) $s['leverantor'] ) ) {
			$s['leverantor'] = self::supplier_for( $code );
		}
		return $s;
	}

	/**
	 * Engångsändringar av sparade inställningar.
	 * 2: Alla Eurofun-resor får sportig cykel.
	 * 3: Eurofun-resor och resor från Eurobike, Radweg-Reisen och Velociped får "Hjälm erbjuds inte".
	 * 4: S-Capes resor i Frankrike får "Hjälm ingår" (i formuläret "Hjälm (gratis)" eller "Tar med egen").
	 */
	public static function migrate() {
		$version = (int) get_option( 'cvb_schema', 1 );
		if ( $version >= 4 ) {
			return;
		}
		$all = self::all_settings();
		foreach ( $all as $code => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( $version < 2 && self::is_eurofun( $code ) ) {
				$all[ $code ]['sportig'] = 1;
			}
			$supplier = isset( $row['leverantor'] ) ? $row['leverantor'] : '';
			$rule     = self::helmet_rule( $code, $supplier );
			if ( ( 'nej' === $rule && $version < 3 ) || 'ingar' === $rule ) {
				$all[ $code ]['hjalm'] = $rule;
			}
		}
		update_option( self::OPTION, $all, false );
		update_option( 'cvb_schema', 4 );
	}

	public static function sanitize_row( $row ) {
		$row   = is_array( $row ) ? $row : array();
		$clean = self::defaults();
		$typ   = isset( $row['typ'] ) ? sanitize_key( $row['typ'] ) : 'cykel';
		$clean['typ'] = array_key_exists( $typ, self::type_choices() ) ? $typ : 'cykel';
		$hjalm = isset( $row['hjalm'] ) ? sanitize_key( $row['hjalm'] ) : 'hyra';
		$clean['hjalm'] = array_key_exists( $hjalm, self::helmet_choices() ) ? $hjalm : 'hyra';
		foreach ( array_keys( self::bool_fields() ) as $key ) {
			$clean[ $key ] = empty( $row[ $key ] ) ? 0 : 1;
		}
		$clean['leverantor'] = isset( $row['leverantor'] ) ? sanitize_text_field( $row['leverantor'] ) : '';
		$clean['kommentar']  = isset( $row['kommentar'] ) ? sanitize_text_field( $row['kommentar'] ) : '';
		$clean['klar']      = empty( $row['klar'] ) ? 0 : 1;
		$clean['rum']       = array();
		$clean['rum_kod']   = array();
		foreach ( array_keys( self::room_choices() ) as $key ) {
			if ( ! empty( $row['rum'][ $key ] ) ) {
				$clean['rum'][] = $key;
			}
			$kod = isset( $row['rum_kod'][ $key ] ) ? self::normalize_code( $row['rum_kod'][ $key ] ) : '';
			if ( '' !== $kod ) {
				$clean['rum_kod'][ $key ] = $kod;
			}
		}
		return $clean;
	}

	/** Boendekategorierna resan erbjuder. Rutan visas bara när det finns minst två att välja mellan. */
	public static function room_options( $s ) {
		$rum = isset( $s['rum'] ) && is_array( $s['rum'] ) ? $s['rum'] : array();
		return array_values( array_intersect( self::ROOM_INDEX, $rum ) );
	}

	public static function has_room_choice( $s ) {
		return count( self::room_options( $s ) ) >= 2;
	}

	/**
	 * Reskoden för den boendekategori kunden valde (nyckel som i ROOM_INDEX), eller
	 * resans egen reskod när kategorin saknar egen kod.
	 */
	public static function code_for_room( $code, $s, $room_key ) {
		if ( $room_key && ! empty( $s['rum_kod'][ $room_key ] ) ) {
			return $s['rum_kod'][ $room_key ];
		}
		return $code;
	}

	/**
	 * Vilken kategori (nyckel) ett inskickat värde i rumskategori motsvarar. Värdet
	 * jämförs med alternativen i det formulär som skickades (rätt språk), efter position.
	 */
	public static function posted_room_key( $form_body, $posted ) {
		$posted = trim( (string) $posted );
		if ( '' === $posted || ! preg_match( '/\[select\*?\s+rumskategori\b([^\]]*)\]/', (string) $form_body, $m ) ) {
			return '';
		}
		preg_match_all( '/"([^"]*)"/', $m[1], $opts );
		foreach ( $opts[1] as $i => $opt ) {
			$parts = explode( '|', $opt );
			if ( trim( $parts[0] ) === $posted || ( isset( $parts[1] ) && trim( $parts[1] ) === $posted ) ) {
				return isset( self::ROOM_INDEX[ $i ] ) ? self::ROOM_INDEX[ $i ] : '';
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------
	 * Formulärstyrning
	 * ------------------------------------------------------------------ */

	public static function allow_tripcode_attr( $out, $pairs, $atts ) {
		if ( isset( $atts['tripcode'] ) ) {
			$out['tripcode'] = sanitize_text_field( $atts['tripcode'] );
		}
		return $out;
	}

	/**
	 * Gör en reskod jämförbar oavsett var den kommer ifrån: sidans innehåll
	 * kan ha "&amp;" där formuläret skickar "&". Används bara för att slå upp
	 * inställningar; värdet som skickas med i mailen ändras inte.
	 */
	public static function normalize_code( $code ) {
		return sanitize_text_field( html_entity_decode( (string) $code, ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Reskoder som har blivit fel när de klistrades in, t.ex. med HTML från
	 * Google Sheets (<span data-sheets-root=…) eller som ett datum ("Mon May 11 2026 …").
	 */
	public static function code_problem( $code ) {
		if ( preg_match( '/[<>="]/', $code ) ) {
			return 'Reskoden innehåller HTML, troligen inklistrad med formatering från Google Sheets. Öppna sidan och skriv in reskoden som vanlig text.';
		}
		if ( preg_match( '/\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun) [A-Z][a-z]{2} \d{1,2} \d{4}|GMT[+-]\d{4}/', $code ) ) {
			return 'Reskoden har blivit ett datum, troligen i Google Sheets. Öppna sidan och skriv in rätt reskod.';
		}
		return '';
	}

	/** Sidans status på svenska. */
	public static function status_label( $status ) {
		$labels = array(
			'draft'   => 'utkast',
			'pending' => 'väntar på granskning',
			'private' => 'privat',
			'future'  => 'schemalagd',
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/** Reskoden för formuläret som visas eller skickas just nu. */
	public static function current_tripcode() {
		// Vid inskick: det dolda fältet [hidden tripcode default:shortcode_attr].
		if ( isset( $_POST['_wpcf7'], $_POST['tripcode'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return self::normalize_code( wp_unslash( $_POST['tripcode'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		// Vid visning: kortkodens attribut.
		if ( class_exists( 'WPCF7_ContactForm' ) ) {
			$form = WPCF7_ContactForm::get_current();
			if ( $form ) {
				$code = $form->shortcode_attr( 'tripcode' );
				if ( $code ) {
					return self::normalize_code( $code );
				}
			}
		}
		return '';
	}

	/** Sidan som formuläret ligger på (används för att gissa typ). */
	public static function current_post_id() {
		if ( isset( $_POST['_wpcf7'], $_POST['_wpcf7_container_post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return absint( $_POST['_wpcf7_container_post'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		return function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0;
	}

	public static function current_settings() {
		return self::settings_for( self::current_tripcode(), self::current_post_id() );
	}

	private static function make_optional( $tag ) {
		$tag = self::tag_set( $tag, 'type', rtrim( (string) self::tag_get( $tag, 'type' ), '*' ) );
		return self::tag_set( $tag, 'basetype', rtrim( (string) self::tag_get( $tag, 'basetype' ), '*' ) );
	}

	public static function filter_form_tag( $tag, $replace = false ) {
		$name = self::tag_get( $tag, 'name' );
		if ( ! is_string( $name ) || '' === $name ) {
			return $tag;
		}

		$is_bike   = (bool) preg_match( '/^cykel-\d+$/', $name );
		$is_helmet = (bool) preg_match( '/^hjalm-\d+$/', $name );
		$is_height = (bool) preg_match( '/^langd-\d+$/', $name );
		$is_ins    = in_array( $name, array( 'cykelforsakring', 'assistansforsakring' ), true );
		$is_room   = 'rumskategori' === $name;
		if ( ! $is_bike && ! $is_helmet && ! $is_height && ! $is_ins && ! $is_room ) {
			return $tag;
		}

		// Utan reskod lämnas formuläret orört. Det gäller bl.a. när Contact Form 7
		// bygger valideringsschemat som webbläsaren hämtar separat (REST, utan
		// kortkod och utan inskick). Annars godkänner webbläsaren bara standardlägets
		// alternativ och visar "Odefinierat värde skickades via detta fält" för t.ex.
		// "Hjälm (gratis)" och "Sportig cykel". Vid visning och inskick finns reskoden.
		if ( '' === self::current_tripcode() ) {
			return $tag;
		}

		$s = self::current_settings();

		// Boendekategori: bara resans kategorier, och bara när det finns ett val (gäller även vandring).
		if ( $is_room ) {
			if ( ! self::has_room_choice( $s ) ) {
				return self::make_optional( $tag );
			}
			$keep = array_keys( array_intersect( self::ROOM_INDEX, self::room_options( $s ) ) );
			return self::reduce_options( $tag, $keep );
		}

		// Vandring: längd, cykel och hjälm tas bort ur HTML:en och görs frivilliga.
		if ( 'vandring' === $s['typ'] && ( $is_bike || $is_helmet || $is_height ) ) {
			return self::make_optional( $tag );
		}
		if ( $is_height ) {
			return $tag;
		}

		if ( $is_bike ) {
			$keep = array();
			foreach ( self::BIKE_INDEX as $i => $key ) {
				if ( ! empty( $s[ $key ] ) ) {
					$keep[] = $i;
				}
			}
			return self::reduce_options( $tag, $keep );
		}

		if ( $is_helmet ) {
			$keep = isset( self::HELMET_INDEX[ $s['hjalm'] ] ) ? self::HELMET_INDEX[ $s['hjalm'] ] : self::HELMET_INDEX['hyra'];
			return self::rename_old_helmet_label( self::reduce_options( $tag, $keep ) );
		}

		// Försäkringar: gör fältet frivilligt när resan saknar försäkringen,
		// så att valideringen går igenom. Själva fältet tas bort ur HTML:en
		// i filter_form_html().
		$key = ( 'cykelforsakring' === $name ) ? 'stold' : 'assistans';
		if ( empty( $s[ $key ] ) ) {
			$tag = self::make_optional( $tag );
		}
		return $tag;
	}

	/** Behåller bara alternativen på de angivna platserna (0-baserade). */
	/**
	 * Formulär med den äldre texten "Hjälm ingår" visar "Hjälm (gratis)" för kunden
	 * (Pär, 2026-11-18). Bara etiketten ändras; värdet i mailet är detsamma som i formuläret.
	 */
	public static function rename_old_helmet_label( $tag ) {
		$labels = (array) self::tag_get( $tag, 'labels' );
		foreach ( $labels as $i => $label ) {
			if ( 'Hjälm ingår' === trim( (string) $label ) ) {
				$labels[ $i ] = 'Hjälm (gratis)';
			}
		}
		return self::tag_set( $tag, 'labels', $labels );
	}

	public static function reduce_options( $tag, $keep ) {
		$raw    = (array) self::tag_get( $tag, 'raw_values' );
		$values = (array) self::tag_get( $tag, 'values' );
		$labels = (array) self::tag_get( $tag, 'labels' );

		$new_raw = $new_values = $new_labels = array();
		foreach ( $keep as $i ) {
			if ( array_key_exists( $i, $raw ) ) {
				$new_raw[]    = $raw[ $i ];
				$new_values[] = isset( $values[ $i ] ) ? $values[ $i ] : $raw[ $i ];
				$new_labels[] = isset( $labels[ $i ] ) ? $labels[ $i ] : $raw[ $i ];
			}
		}
		if ( empty( $new_raw ) ) {
			return $tag; // Felinställning: visa hellre alla alternativ än inga.
		}

		$tag = self::tag_set( $tag, 'raw_values', $new_raw );
		$tag = self::tag_set( $tag, 'values', $new_values );
		$tag = self::tag_set( $tag, 'labels', $new_labels );
		if ( class_exists( 'WPCF7_Pipes' ) ) {
			$tag = self::tag_set( $tag, 'pipes', new WPCF7_Pipes( $new_raw ) );
		}

		// Ett enda alternativ: förvälj det i stället för en tom rad.
		if ( 1 === count( $new_raw ) ) {
			$options = array_values( array_diff( (array) self::tag_get( $tag, 'options' ), array( 'include_blank' ) ) );
			$tag     = self::tag_set( $tag, 'options', $options );
		}
		return $tag;
	}

	/**
	 * Resor utan hjälmhyra: hela hjälmrutan (etiketten med listan) tas bort, och
	 * listans enda värde ("Tar med egen" på formulärets språk) skickas i ett dolt
	 * fält. Mailen får då samma värde som förut (Pär, 2026-11-18).
	 */
	public static function hide_helmet_fields( $html ) {
		return preg_replace_callback(
			'#<label[^>]*>(?:(?!</label>).)*?<select[^>]*\bname="(hjalm-\d+)"[^>]*>(.*?)</select>(?:(?!</label>).)*?</label>#s',
			function ( $m ) {
				$value = preg_match( '#<option value="([^"]+)"#', $m[2], $o ) ? $o[1] : '';
				return '<input type="hidden" name="' . $m[1] . '" value="' . $value . '" class="cvb-hjalm-dold" />';
			},
			$html
		);
	}

	/**
	 * Villkorliga stycken i mailen: [cvb-hjalm-nej]…[/cvb-hjalm-nej] tas med bara
	 * när villkoret gäller för resan. Texten står i mailmallen i Contact Form 7, så
	 * den kan översättas i WPML.
	 */
	public static function mail_blocks( $s ) {
		$bike = 'vandring' !== $s['typ'];
		return array(
			'cvb-hjalm-nej'   => $bike && 'nej' === $s['hjalm'],
			'cvb-hjalm-hyra'  => $bike && 'hyra' === $s['hjalm'],
			'cvb-hjalm-ingar' => $bike && 'ingar' === $s['hjalm'],
			'cvb-cykel'       => $bike,
			'cvb-vandring'    => ! $bike,
			// Försäkringarna visas bara på cykelresor som erbjuder dem (Pär, 2026-11-18).
			'cvb-stold'       => $bike && ! empty( $s['stold'] ),
			'cvb-assistans'   => $bike && ! empty( $s['assistans'] ),
			// Boendekategori: bara på resor där kunden kan välja (Pär, 2026-11-19).
			'cvb-rum'         => self::has_room_choice( $s ),
		);
	}

	public static function apply_mail_blocks( $text, $s ) {
		$text = (string) $text;
		if ( false === strpos( $text, '[cvb-' ) ) {
			return $text;
		}
		foreach ( self::mail_blocks( $s ) as $name => $show ) {
			$text = preg_replace_callback(
				'#\[' . preg_quote( $name, '#' ) . '\](.*?)\[/' . preg_quote( $name, '#' ) . '\]#s',
				function ( $m ) use ( $show ) {
					return $show ? $m[1] : '';
				},
				$text
			);
		}
		// Ett borttaget stycke på egen rad ska inte lämna dubbla tomrader efter sig.
		return preg_replace( "/\n{3,}/", "\n\n", $text );
	}

	public static function filter_mail_components( $components ) {
		if ( '' === self::current_tripcode() || ! is_array( $components ) ) {
			return $components;
		}
		$s = self::current_settings();
		// [cvb-reskod]: reskoden för den boendekategori kunden valde, annars resans reskod.
		$form_id  = isset( $_POST['_wpcf7'] ) ? (int) $_POST['_wpcf7'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$posted   = isset( $_POST['rumskategori'] ) ? sanitize_text_field( wp_unslash( $_POST['rumskategori'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$room_key = $form_id ? self::posted_room_key( get_post_meta( $form_id, '_form', true ), $posted ) : '';
		$reskod   = self::code_for_room( self::current_tripcode(), $s, self::has_room_choice( $s ) ? $room_key : '' );
		foreach ( array( 'subject', 'body' ) as $key ) {
			if ( isset( $components[ $key ] ) ) {
				$text               = str_replace( '[cvb-reskod]', $reskod, (string) $components[ $key ] );
				$components[ $key ] = self::apply_mail_blocks( $text, $s );
			}
		}
		return $components;
	}

	/**
	 * Luft mellan formulärets delar (Pär, 2026-11-20): kunden, resan, resenärerna
	 * och villkoren. Bara i bokningsformuläret (det med fältet tripcode), och
	 * styrt av fältnamnen, så formuläret och översättningarna behöver inte ändras.
	 */
	public static function form_css() {
		$f = 'form.wpcf7-form:has(input[name="tripcode"])';
		return $f . ' label:has([name="datum"]) { display: block; margin-top: 2em; }'
			. $f . ' hr { margin: 2em 0 0; }'
			. $f . ' label:has([name="antal"]) { display: block; margin-top: 1em; }'
			. $f . ' strong { display: block; margin-top: 1.8em; margin-bottom: .2em; }'
			. $f . ' p + strong { margin-top: .5em; }'
			. $f . ' .faktura-hjalp { margin-top: 1.8em; }'
			. $f . ' .faktura-hjalp + strong { margin-top: .8em; }';
	}

	/** Lägger in CSS:en en gång per sida, före bokningsformuläret. */
	public static function add_form_css( $html ) {
		static $done = false;
		if ( $done || false === strpos( $html, 'name="tripcode"' ) ) {
			return $html;
		}
		$done = true;
		return '<style id="cvb-form-css">' . self::form_css() . '</style>' . $html;
	}

	public static function filter_form_html( $html ) {
		if ( '' === self::current_tripcode() ) {
			return $html;
		}
		$s = self::current_settings();
		if ( 'vandring' === $s['typ'] ) {
			$html = preg_replace( '#<div class="[^"]*\bcvb-cykel\b[^"]*">.*?</div>#s', '', $html );
		}
		if ( 'vandring' !== $s['typ'] && 'nej' === $s['hjalm'] ) {
			$html = self::hide_helmet_fields( $html );
		}
		if ( empty( $s['stold'] ) ) {
			$html = preg_replace( '#<div class="cvb-stold">.*?</div>#s', '', $html );
		}
		if ( empty( $s['assistans'] ) ) {
			$html = preg_replace( '#<div class="cvb-assistans">.*?</div>#s', '', $html );
		}
		if ( ! self::has_room_choice( $s ) ) {
			$html = preg_replace( '#<div class="cvb-rum">.*?</div>#s', '', $html );
		}
		return $html;
	}

	private static function tag_get( $tag, $key ) {
		if ( is_object( $tag ) ) {
			return isset( $tag->$key ) ? $tag->$key : null;
		}
		if ( is_array( $tag ) ) {
			return isset( $tag[ $key ] ) ? $tag[ $key ] : null;
		}
		return null;
	}

	private static function tag_set( $tag, $key, $value ) {
		if ( is_object( $tag ) ) {
			$tag->$key = $value;
		} elseif ( is_array( $tag ) ) {
			$tag[ $key ] = $value;
		}
		return $tag;
	}

	/* ------------------------------------------------------------------
	 * Hitta resor (sidor som har en kortkod med tripcode)
	 * ------------------------------------------------------------------ */

	/** Sidans innehåll plus Avadas kodblock, som sparas base64-kodade. */
	private static function content_haystack( $content ) {
		$hay = (string) $content;
		if ( preg_match_all( '#\[fusion_code[^\]]*\](.*?)\[/fusion_code\]#s', $hay, $m ) ) {
			foreach ( $m[1] as $block ) {
				$decoded = base64_decode( trim( $block ), true );
				if ( false !== $decoded ) {
					$hay .= "\n" . $decoded;
				}
			}
		}
		return $hay;
	}

	/**
	 * Vilket formulär (id i kortkoden) som används för varje reskod på sidan.
	 *
	 * @return array reskod => array av formulär-id
	 */
	public static function extract_form_ids( $content ) {
		$hay   = self::content_haystack( $content );
		$found = array();
		if ( preg_match_all( '/\[contact-form-7\b[^\[\]]*?tripcode[^\[]*?\]/s', $hay, $m ) ) {
			foreach ( $m[0] as $shortcode ) {
				$id = preg_match( '/\bid\s*=\s*(?:&quot;|["\'\x{201C}\x{201D}])?([A-Za-z0-9]+)/u', $shortcode, $idm ) ? $idm[1] : '';
				foreach ( self::extract_codes( $shortcode ) as $code ) {
					if ( '' !== $id ) {
						$found[ $code ][] = $id;
					}
				}
			}
		}
		return $found;
	}

	public static function extract_codes( $content ) {
		$hay = self::content_haystack( $content );
		// Reskoder kan innehålla mellanslag och andra tecken, t.ex. "Trip 07-06" eller
		// "Mjölkvägen & Rallarvägen". Värdet inom citattecken läses därför i sin helhet.
		$q     = '(?:&quot;|&#8220;|&#8221;|&#8243;|"|\x{201C}|\x{201D}|\x{2033})';
		$codes = array();
		if ( preg_match_all( '/tripcode\s*=\s*(?:' . $q . '(.+?)' . $q . '|\'([^\']+)\'|([^\s\]"\']+))/u', $hay, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$value = '';
				for ( $i = 1; $i <= 3; $i++ ) {
					if ( isset( $match[ $i ] ) && '' !== $match[ $i ] ) {
						$value = $match[ $i ];
						break;
					}
				}
				$value = self::normalize_code( $value );
				if ( '' !== $value ) {
					$codes[] = $value;
				}
			}
		}
		return array_values( array_unique( $codes ) );
	}

	/** @return array reskod => array( 'title', 'edit', 'view', 'langs', 'status' ) */
	public static function find_tours() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT ID, post_title, post_type, post_status, post_content FROM {$wpdb->posts}
			 WHERE post_status IN ('publish','draft','pending','private','future')
			   AND post_type NOT IN ('revision','nav_menu_item','wpcf7_contact_form','flamingo_inbound','flamingo_contact')
			   AND ( post_content LIKE '%tripcode%' OR post_content LIKE '%fusion_code%' )"
		);

		$default_lang = apply_filters( 'wpml_default_language', null );
		$tours        = array();

		foreach ( (array) $rows as $row ) {
			$codes = self::extract_codes( $row->post_content );
			if ( empty( $codes ) ) {
				continue;
			}
			$lang    = '';
			$details = apply_filters( 'wpml_post_language_details', null, (int) $row->ID );
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				$lang = $details['language_code'];
			}
			$form_ids = self::extract_form_ids( $row->post_content );
			foreach ( $codes as $code ) {
				if ( ! isset( $tours[ $code ] ) ) {
					$tours[ $code ] = array( 'posts' => array(), 'forms' => array() );
				}
				if ( isset( $form_ids[ $code ] ) ) {
					$tours[ $code ]['forms'] = array_merge( $tours[ $code ]['forms'], $form_ids[ $code ] );
				}
				$tours[ $code ]['posts'][] = array(
					'id'      => (int) $row->ID,
					'title'   => $row->post_title,
					'status'  => $row->post_status,
					'lang'    => $lang,
					'primary' => ( $default_lang && $lang === $default_lang ) || ( ! $default_lang ),
				);
			}
		}

		foreach ( $tours as $code => $tour ) {
			$posts = $tour['posts'];
			usort(
				$posts,
				function ( $a, $b ) {
					$score = function ( $p ) {
						return ( $p['primary'] ? 0 : 2 ) + ( 'publish' === $p['status'] ? 0 : 1 );
					};
					return $score( $a ) <=> $score( $b );
				}
			);
			$main  = $posts[0];
			$langs = array_unique( array_filter( wp_list_pluck( $posts, 'lang' ) ) );
			$tours[ $code ] = array(
				'title'  => $main['title'],
				'id'     => $main['id'],
				'status' => $main['status'],
				'published' => in_array( 'publish', wp_list_pluck( $posts, 'status' ), true ),
				'langs'  => $langs,
				'forms'  => array_values( array_unique( $tour['forms'] ) ),
				'count'  => count( $posts ),
				'post_ids' => array_values( array_unique( wp_list_pluck( $posts, 'id' ) ) ),
			);
		}

		uasort(
			$tours,
			function ( $a, $b ) {
				return strnatcasecmp( $a['title'], $b['title'] );
			}
		);
		return $tours;
	}

	/* ------------------------------------------------------------------
	 * Adminsida
	 * ------------------------------------------------------------------ */

	/** "Inställningar" först under tilläggets namn i listan över tillägg. */
	public static function settings_link( $links, $file ) {
		if ( plugin_basename( __FILE__ ) === $file && current_user_can( self::CAP ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">Inställningar</a>' );
		}
		return $links;
	}

	public static function admin_menu() {
		add_submenu_page(
			Cyklavandra_Meny::SLUG,
			'Bokningsformulär',
			'Bokningsformulär',
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Påminnelse i Contact Form 7 och på kontrollpanelen när formulärets
	 * översättningar inte stämmer med originalet, så att den syns där formuläret ändras.
	 */
	public static function translation_notice() {
		if ( ! current_user_can( self::CAP ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		$id     = $screen ? (string) $screen->id : '';
		if ( 'dashboard' !== $id && false === strpos( $id, 'wpcf7' ) ) {
			return;
		}
		$issues = self::translation_issues( self::official_form() );
		if ( ! $issues['missing'] && ! $issues['differs'] ) {
			return;
		}
		$parts = array();
		if ( $issues['missing'] ) {
			$parts[] = 'saknar översättning till ' . implode( ', ', $issues['missing'] );
		}
		if ( $issues['differs'] ) {
			$parts[] = 'översättningen till ' . implode( ', ', array_keys( $issues['differs'] ) ) . ' stämmer inte med originalet';
		}
		printf(
			'<div class="notice notice-warning"><p><strong>Bokningsformuläret:</strong> %s. <a href="%s">Visa detaljer</a></p></div>',
			esc_html( implode( ', och ', $parts ) ),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) )
		);
	}

	/** Kortet på översiktssidan under Cykla&Vandra. */
	public static function tool_card( $verktyg ) {
		if ( ! current_user_can( self::CAP ) ) {
			return $verktyg;
		}
		$data      = function_exists( 'get_plugin_data' ) ? get_plugin_data( __FILE__, false, false ) : array();
		$verktyg[] = array(
			'titel'       => 'Bokningsformulär',
			'beskrivning' => 'Styr vilka cyklar, hjälmval och försäkringar som visas i bokningsformuläret för varje resa.',
			'url'         => admin_url( 'admin.php?page=' . self::SLUG ),
			'version'     => isset( $data['Version'] ) ? $data['Version'] : '',
			'ikon'        => plugins_url( 'assets/icon.svg', __FILE__ ),
		);
		return $verktyg;
	}

	/**
	 * Formulär i Contact Form 7 som har det dolda fältet tripcode, med kortkoden
	 * färdig att klistra in. new_template = formuläret har mallens cvb-klasser.
	 */
	public static function booking_forms() {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return array();
		}
		$forms = array();
		$posts = get_posts(
			array(
				'post_type'        => 'wpcf7_contact_form',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'suppress_filters' => true, // Alla språk i WPML.
			)
		);
		foreach ( $posts as $post ) {
			// Läs formuläret direkt i stället för med WPCF7_ContactForm::get_instance().
			// get_instance() gör formuläret till CF7:s "aktuella formulär", och då visar
			// CF7:s listsida det formuläret för redigering i stället för listan.
			$body = (string) get_post_meta( $post->ID, '_form', true );
			if ( false === strpos( $body, 'tripcode' ) ) {
				continue;
			}
			$lang    = '';
			$details = apply_filters( 'wpml_post_language_details', null, (int) $post->ID );
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				$lang = strtoupper( $details['language_code'] );
			}
			// CF7 använder de första sju tecknen i formulärets hash som id i kortkoden.
			$hash      = (string) get_post_meta( $post->ID, '_hash', true );
			$short_id  = '' !== $hash ? substr( $hash, 0, 7 ) : (string) $post->ID;
			$title     = (string) $post->post_title;
			$shortcode = sprintf( '[contact-form-7 id="%s" title="%s"]', $short_id, str_replace( '"', '', $title ) );
			$ids       = array_values( array_unique( array( (string) $post->ID, $short_id ) ) );
			$forms[] = array(
				'post_id'      => (int) $post->ID,
				'body'         => $body,
				'ids'          => $ids,
				'title'        => $title,
				'lang'         => $lang,
				'shortcode'    => preg_replace( '/\]$/', ' tripcode="RESKOD"]', $shortcode ),
				'new_template' => false !== strpos( $body, 'cvb-cykel' ),
				'old_helmet'   => false !== strpos( $body, '"Hjälm ingår"' ),
			);
		}
		// Formulär med den nya mallen först.
		usort(
			$forms,
			function ( $a, $b ) {
				return (int) $b['new_template'] - (int) $a['new_template'];
			}
		);
		return $forms;
	}

	/**
	 * Formuläret som ska användas: det med den nya mallen. Har det översättningar
	 * i WPML räknas alla deras id som samma formulär.
	 *
	 * @return array|null array( 'title', 'shortcode', 'ids', 'langs' )
	 */
	/** Id och titel i det officiella formulärets kortkod, t.ex. array( 'ca124e5', 'Bokningsformulär självguidade v1.0' ). */
	public static function official_shortcode_parts( $official ) {
		$id    = preg_match( '/\bid="([^"]+)"/', (string) $official['shortcode'], $m ) ? $m[1] : '';
		$title = preg_match( '/\btitle="([^"]*)"/', (string) $official['shortcode'], $m ) ? $m[1] : '';
		return array( $id, $title );
	}

	/**
	 * Byter formulär i sidans innehåll: i varje kortkod med tripcode som använder ett
	 * annat formulär än det officiella byts id och titel. Reskoden och resten av sidan
	 * lämnas orörda. Avadas base64-kodade kodblock hanteras. Kortkoder med felaktig
	 * reskod hoppas över.
	 *
	 * @return array( nytt innehåll, antal bytta kortkoder )
	 */
	public static function swap_forms_in_content( $content, $official ) {
		list( $new_id, $new_title ) = self::official_shortcode_parts( $official );
		$count = 0;
		if ( '' === $new_id ) {
			return array( $content, 0 );
		}
		$swap = function ( $text ) use ( $official, $new_id, $new_title, &$count ) {
			return preg_replace_callback(
				'/\[contact-form-7\b[^\[\]]*?tripcode[^\[]*?\]/s',
				function ( $m ) use ( $official, $new_id, $new_title, &$count ) {
					$sc = $m[0];
					if ( ! preg_match( '/\bid\s*=\s*(?:&quot;|["\'\x{201C}\x{201D}])?([A-Za-z0-9]+)/u', $sc, $idm ) ) {
						return $sc;
					}
					if ( in_array( $idm[1], $official['ids'], true ) ) {
						return $sc; // Redan rätt formulär.
					}
					foreach ( self::extract_codes( $sc ) as $code ) {
						if ( self::code_problem( $code ) ) {
							return $sc; // Rätta reskoden först.
						}
					}
					$sc = preg_replace( '/(\bid\s*=\s*(?:&quot;|["\'\x{201C}\x{201D}])?)[A-Za-z0-9]+/u', '${1}' . $new_id, $sc, 1 );
					$sc = preg_replace_callback(
						'/(\btitle\s*=\s*)(&quot;|"|\')(.*?)\2/u',
						function ( $t ) use ( $new_title ) {
							return $t[1] . $t[2] . $new_title . $t[2];
						},
						$sc,
						1
					);
					$count++;
					return $sc;
				},
				$text
			);
		};
		// Avadas kodblock: avkoda, byt och koda igen.
		$content = preg_replace_callback(
			'#(\[fusion_code[^\]]*\])(.*?)(\[/fusion_code\])#s',
			function ( $m ) use ( $swap ) {
				$decoded = base64_decode( trim( $m[2] ), true );
				if ( false === $decoded ) {
					return $m[0];
				}
				$new = $swap( $decoded );
				return $new === $decoded ? $m[0] : $m[1] . base64_encode( $new ) . $m[3];
			},
			(string) $content
		);
		$content = $swap( $content );
		return array( $content, $count );
	}

	/** Byter till det officiella formuläret på de valda resornas sidor (alla språk). */
	public static function handle_swap_forms() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Behörighet saknas.' );
		}
		check_admin_referer( 'cvb_swap_forms' );
		$official = self::official_form();
		if ( ! $official ) {
			self::notice_and_redirect( 'Inget formulär har den nya mallen, så det finns inget att byta till.', 'error' );
		}
		$wanted = isset( $_POST['codes'] ) ? array_map( array( __CLASS__, 'normalize_code' ), (array) wp_unslash( $_POST['codes'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$all    = ! empty( $_POST['all'] );

		$pages    = 0;
		$tours    = 0;
		$failed   = array();
		foreach ( self::find_tours() as $code => $tour ) {
			if ( ! $all && ! in_array( $code, $wanted, true ) ) {
				continue;
			}
			if ( self::code_problem( $code ) || ! array_diff( $tour['forms'], $official['ids'] ) ) {
				continue;
			}
			$changed = false;
			foreach ( $tour['post_ids'] as $post_id ) {
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					$failed[] = get_the_title( $post_id );
					continue;
				}
				list( $content, $count ) = self::swap_forms_in_content( get_post_field( 'post_content', $post_id ), $official );
				if ( ! $count ) {
					continue;
				}
				$result = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $content ) ), true );
				if ( is_wp_error( $result ) ) {
					$failed[] = get_the_title( $post_id );
					continue;
				}
				$pages++;
				$changed = true;
			}
			if ( $changed ) {
				$tours++;
			}
		}

		$msg = sprintf(
			'Bytte till %s på %d %s (%d %s). Varje sida har en revision, så ändringen kan ångras under Revisioner när sidan redigeras.',
			$official['title'],
			$pages,
			1 === $pages ? 'sida' : 'sidor',
			$tours,
			1 === $tours ? 'resa' : 'resor'
		);
		if ( $failed ) {
			$msg .= '<br>Kunde inte ändra: ' . esc_html( implode( ', ', array_unique( $failed ) ) ) . '.';
		}
		self::notice_and_redirect( $msg, $failed ? 'error' : 'success' );
	}

	public static function official_form( $forms = null ) {
		$forms    = null === $forms ? self::booking_forms() : $forms;
		$new      = array_values( array_filter( $forms, function ( $f ) { return $f['new_template']; } ) );
		if ( ! $new ) {
			return null;
		}
		// Originalet är formuläret på standardspråket (svenska), annars det första.
		$default = strtoupper( (string) apply_filters( 'wpml_default_language', null ) );
		$main    = $new[0];
		foreach ( $new as $f ) {
			if ( $default && $f['lang'] === $default ) {
				$main = $f;
				break;
			}
		}

		// Översättningarna enligt WPML. Utan WPML räknas alla formulär med nya mallen.
		$members = array();
		$by_post = array();
		foreach ( $forms as $f ) {
			if ( ! empty( $f['post_id'] ) ) {
				$by_post[ $f['post_id'] ] = $f;
			}
		}
		$trid         = empty( $main['post_id'] ) ? null : apply_filters( 'wpml_element_trid', null, $main['post_id'], 'post_wpcf7_contact_form' );
		$translations = $trid ? apply_filters( 'wpml_get_element_translations', null, $trid, 'post_wpcf7_contact_form' ) : null;
		if ( is_array( $translations ) && $translations ) {
			foreach ( $translations as $code => $t ) {
				$post_id = (int) ( is_object( $t ) ? $t->element_id : 0 );
				if ( ! $post_id ) {
					continue;
				}
				if ( isset( $by_post[ $post_id ] ) ) {
					$member = $by_post[ $post_id ];
				} else {
					// Översättningen saknar tripcode och kom därför inte med i listan.
					$member = array(
						'post_id' => $post_id,
						'body'    => (string) get_post_meta( $post_id, '_form', true ),
						'ids'     => array( (string) $post_id ),
						'title'   => get_the_title( $post_id ),
					);
				}
				$member['lang'] = strtoupper( is_object( $t ) && ! empty( $t->language_code ) ? $t->language_code : (string) $code );
				$members[]      = $member;
			}
		} else {
			$members = $new;
		}

		$main['members']    = $members;
		$main['langs']      = array();
		$main['old_helmet'] = false;
		foreach ( $members as $m ) {
			$main['ids']        = array_values( array_unique( array_merge( $main['ids'], $m['ids'] ) ) );
			$main['langs'][]    = isset( $m['lang'] ) ? $m['lang'] : '';
			$main['old_helmet'] = $main['old_helmet'] || false !== strpos( (string) $m['body'], '"Hjälm ingår"' );
		}
		$main['langs'] = array_values( array_unique( array_filter( $main['langs'] ) ) );
		return $main;
	}

	/**
	 * Formulärets struktur: taggarna i ordning (typ, namn och antal alternativ i
	 * listor) och mallens klasser. Texterna ingår inte, eftersom de ska vara olika
	 * på olika språk.
	 */
	public static function form_structure( $body ) {
		$body  = (string) $body;
		$tags  = array();
		if ( preg_match_all( '/\[(?!\/)([a-z_]+\*?)((?:\s+[^\]"\s]+|\s+"[^"]*")*)\s*\]/', $body, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $tag ) {
				$type  = $tag[1];
				$rest  = trim( $tag[2] );
				$name  = preg_match( '/^([A-Za-z][A-Za-z0-9_-]*)/', $rest, $nm ) ? $nm[1] : '';
				$base  = rtrim( $type, '*' );
				$entry = array( 'type' => $type, 'name' => $name );
				if ( in_array( $base, array( 'select', 'radio', 'checkbox' ), true ) ) {
					$entry['count'] = preg_match_all( '/"[^"]*"/', $rest );
					$entry['blank'] = false !== strpos( $rest, 'include_blank' );
				}
				$tags[] = $entry;
			}
		}
		$markers = array();
		foreach ( array( 'cvb-cykel', 'cvb-stold', 'cvb-assistans', 'cvb-rum', 'resenar-rad', '[hidden tripcode' ) as $marker ) {
			$markers[ $marker ] = substr_count( $body, $marker );
		}
		return array( 'tags' => $tags, 'markers' => $markers );
	}

	/**
	 * Skillnader i struktur mellan originalet och en översättning, som texter.
	 *
	 * @return string[]
	 */
	public static function compare_structure( $original_body, $translated_body ) {
		$a      = self::form_structure( $original_body );
		$b      = self::form_structure( $translated_body );
		$issues = array();
		$label  = function ( $t ) {
			return '' !== $t['name'] ? $t['name'] : '[' . $t['type'] . ']';
		};
		$a_names = array_map( $label, $a['tags'] );
		$b_names = array_map( $label, $b['tags'] );

		$missing = array_diff( $a_names, $b_names );
		$extra   = array_diff( $b_names, $a_names );
		if ( $missing ) {
			$issues[] = 'saknar ' . implode( ', ', array_unique( $missing ) );
		}
		if ( $extra ) {
			$issues[] = 'har fält som inte finns i originalet: ' . implode( ', ', array_unique( $extra ) );
		}
		if ( ! $missing && ! $extra && $a_names !== $b_names ) {
			$issues[] = 'fälten ligger i en annan ordning';
		}
		$b_by_name = array();
		foreach ( $b['tags'] as $t ) {
			$b_by_name[ $label( $t ) ] = $t;
		}
		foreach ( $a['tags'] as $t ) {
			$key = $label( $t );
			if ( ! isset( $b_by_name[ $key ] ) ) {
				continue;
			}
			$u = $b_by_name[ $key ];
			if ( $t['type'] !== $u['type'] ) {
				$issues[] = sprintf( '%s är %s i stället för %s', $key, $u['type'], $t['type'] );
			}
			if ( isset( $t['count'] ) && isset( $u['count'] ) && $t['count'] !== $u['count'] ) {
				$issues[] = sprintf( '%s har %d alternativ i stället för %d', $key, $u['count'], $t['count'] );
			}
		}
		foreach ( $a['markers'] as $marker => $count ) {
			if ( $b['markers'][ $marker ] !== $count ) {
				$issues[] = sprintf( '%s finns %d gånger i stället för %d', trim( $marker, '[' ), $b['markers'][ $marker ], $count );
			}
		}
		return $issues;
	}

	/**
	 * Problem med det officiella formulärets översättningar: språk som saknar
	 * översättning och översättningar vars struktur skiljer sig från originalet.
	 *
	 * @return array( 'missing' => string[], 'differs' => array( språk => string[] ) )
	 */
	public static function translation_issues( $official ) {
		$result = array( 'missing' => array(), 'differs' => array() );
		if ( ! $official || empty( $official['members'] ) ) {
			return $result;
		}
		$active = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( is_array( $active ) ) {
			$have              = array_map( 'strtoupper', $official['langs'] );
			$result['missing'] = array_values( array_diff( array_map( 'strtoupper', array_keys( $active ) ), $have ) );
		}
		$original = null;
		foreach ( $official['members'] as $m ) {
			if ( (int) $m['post_id'] === (int) $official['post_id'] ) {
				$original = $m;
			}
		}
		if ( ! $original ) {
			return $result;
		}
		foreach ( $official['members'] as $m ) {
			if ( (int) $m['post_id'] === (int) $original['post_id'] ) {
				continue;
			}
			$issues = self::compare_structure( $original['body'], $m['body'] );
			if ( $issues ) {
				$result['differs'][ isset( $m['lang'] ) && $m['lang'] ? $m['lang'] : '#' . $m['post_id'] ] = $issues;
			}
		}
		return $result;
	}

	/** Kortkoden för en resa, eller '' om inget formulär har den nya mallen. */
	public static function shortcode_for( $code, $official ) {
		if ( ! $official ) {
			return '';
		}
		return str_replace( 'tripcode="RESKOD"', 'tripcode="' . str_replace( '"', '', $code ) . '"', $official['shortcode'] );
	}

	private static function render_shortcode_box( $forms, $official ) {
		$template = (string) file_get_contents( __DIR__ . '/formularmall-sv.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$old      = array_filter( $forms, function ( $f ) { return ! $f['new_template']; } );
		?>
		<div class="cvb-box">
			<?php if ( $official ) : ?>
				<h2>Bokningsformuläret: <?php echo esc_html( $official['title'] ); ?>
					<?php if ( $official['langs'] ) : ?><span class="cvb-langs"><?php echo esc_html( implode( ' · ', $official['langs'] ) ); ?></span><?php endif; ?>
				</h2>
				<p>
					Klistra in kortkoden på resans sida och byt <code>RESKOD</code> mot resans reskod.
					Resor vars sida använder ett annat formulär är gula och markerade med <span class="cvb-badge cvb-oldform">Annat formulär</span>.
					Nya resor dyker upp i tabellen så fort sidan är sparad, även som utkast.
				</p>
				<p class="cvb-shortcode-row">
					<input type="text" readonly value="<?php echo esc_attr( $official['shortcode'] ); ?>" class="cvb-copy-src code" onfocus="this.select()">
					<button type="button" class="button cvb-copy">Kopiera</button>
				</p>
				<?php if ( ! empty( $official['old_helmet'] ) ) : ?>
					<p class="cvb-old">Formuläret har fortfarande texten <code>"Hjälm ingår"</code>. Kunderna ser ändå "Hjälm (gratis)", men byt gärna texten till <code>"Hjälm (gratis)"</code> på alla 8 ställen under fliken Formulär, så att även mailet säger samma sak.</p>
				<?php endif; ?>
				<?php
				$issues = self::translation_issues( $official );
				if ( $issues['missing'] || $issues['differs'] ) :
					?>
					<div class="cvb-warn">
						<strong>Översättningarna av formuläret behöver uppdateras.</strong>
						<?php if ( $issues['missing'] ) : ?>
							<p>Formuläret saknar översättning till <strong><?php echo esc_html( implode( ', ', $issues['missing'] ) ); ?></strong>. Sidor på det språket kan inte visa formuläret förrän det är översatt i WPML.</p>
						<?php endif; ?>
						<?php foreach ( $issues['differs'] as $lang => $list ) : ?>
							<p><strong><?php echo esc_html( $lang ); ?></strong> skiljer sig från originalet: <?php echo esc_html( implode( '; ', $list ) ); ?>.</p>
						<?php endforeach; ?>
						<p>Uppdatera översättningen i WPML så att den har samma fält, ordning och alternativ som originalet (texterna ska vara översatta). Varningen försvinner när strukturen stämmer.</p>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<h2>Så lägger du in bokningsformuläret på en resa</h2>
				<p>
					Inget formulär i Contact Form 7 har den nya mallen än. Skapa ett formulär under <strong>Kontakt</strong>
					och klistra in mallen nedan under fliken Formulär. Därefter visas en knapp för att kopiera kortkoden för varje resa.
				</p>
			<?php endif; ?>
			<?php if ( $official && ! empty( $official['post_id'] ) ) : ?>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpcf7&post=' . (int) $official['post_id'] . '&action=edit' ) ); ?>">Länk till bokningsformuläret</a></p>
			<?php else : ?>
			<details>
				<summary>Visa formulärmallen (fliken Formulär i Contact Form 7)</summary>
				<p>Klistra in i ett nytt eller befintligt bokningsformulär. Fältnamnen är desamma som förut, så mailen och Flamingo fungerar som tidigare.</p>
				<textarea readonly rows="12" class="large-text code cvb-copy-src"><?php echo esc_textarea( $template ); ?></textarea>
				<p><button type="button" class="button cvb-copy">Kopiera mallen</button></p>
			</details>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tours    = self::find_tours();
		$all      = self::all_settings();
		$orphans  = array_diff_key( $all, $tours );
		$fields   = self::bool_fields();
		$helmets  = self::helmet_choices();
		$types    = self::type_choices();
		$rows     = array();
		foreach ( $tours as $code => $t ) {
			$s             = self::settings_for( $code, $t['id'] );
			$rows[ $code ] = array( 's' => $s, 'done' => ! empty( $all[ $code ]['klar'] ) );
		}
		$suppliers = array_unique( array_filter( wp_list_pluck( wp_list_pluck( $rows, 's' ), 'leverantor' ) ) );
		$forms     = self::booking_forms();
		$official  = self::official_form( $forms );
		$titles    = array();
		foreach ( $forms as $f ) {
			foreach ( $f['ids'] as $form_id ) {
				$titles[ $form_id ] = $f['title'];
			}
		}
		sort( $suppliers );
		?>
		<div class="wrap cvb-wrap">
			<h1>Bokningsformulär</h1>
			<?php self::render_notices(); ?>
			<?php self::render_shortcode_box( $forms, $official ); ?>
			<details class="cvb-help">
				<summary>Så fungerar tabellen</summary>
				<p>
				Varje rad är en reskod som finns i en kortkod på sajten. Nya resor dyker upp här automatiskt.
				Typen gissas utifrån adressen (<code>/vandra/</code> = vandring). På vandringsresor visas inte längd, cykel och hjälm.
				Resor vars sida använder ett annat formulär än bokningsformuläret, eller har en felaktig reskod, visas i gult.
				Resor som inte är sparade använder standardläget (vanlig cykel + elcykel, hyrhjälm, egen cykel tillåten, inga försäkringar).
				Resor från Eurobike, Radweg-Reisen och Velociped (reskoder som börjar med landskod och bindestreck, t.ex. <code>DE-</code>)
				har sportig cykel och ingen hjälmhyra. På S-Capes resor i Frankrike (<code>FRSB…</code>) ingår hjälmen.
				Leverantören hämtas från arket Booking Codes (inbyggd lista) om den inte är ifylld.
				</p>
			</details>
			<p>
				<strong><?php echo esc_html( count( $tours ) ); ?></strong> resor.
				<?php
				$other_form = $official ? count( array_filter( $tours, function ( $t ) use ( $official ) { return (bool) array_diff( $t['forms'], $official['ids'] ); } ) ) : 0;
				if ( $other_form ) :
					?>
					<strong><?php echo (int) $other_form; ?></strong> använder ett annat formulär.
				<?php endif; ?>
				<?php
				$bad = array_filter( array_keys( $tours ), array( __CLASS__, 'code_problem' ) );
				if ( $bad ) :
					?>
					<span class="cvb-bad-note"><strong><?php echo count( $bad ); ?></strong> med felaktig reskod, se de röda markeringarna. Formuläret fungerar inte på de sidorna förrän koden är rättad.</span>
				<?php endif; ?>
			</p>
			<?php
			// Formulär för att byta till det officiella formuläret. Knapparna i tabellen
			// hör till det via form="cvb-swap", eftersom formulär inte kan ligga i varandra.
			$swappable = array();
			$old_names = array();
			if ( $official ) {
				foreach ( $tours as $code => $t ) {
					$other = array_diff( $t['forms'], $official['ids'] );
					if ( $other && ! self::code_problem( $code ) ) {
						$swappable[] = $code;
						foreach ( $other as $form_id ) {
							$name               = isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : 'id ' . $form_id;
							$old_names[ $name ] = isset( $old_names[ $name ] ) ? $old_names[ $name ] + 1 : 1;
						}
					}
				}
			}
			?>
			<form id="cvb-swap" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm( 'Byta till <?php echo esc_js( $official ? $official['title'] : '' ); ?> på de valda sidorna? Varje sida får en revision, så det går att ångra.' );">
				<?php wp_nonce_field( 'cvb_swap_forms' ); ?>
				<input type="hidden" name="action" value="cvb_swap_forms">
				<?php if ( $swappable ) : ?>
					<div class="cvb-swapbox">
						<p>
							<strong><?php echo count( $swappable ); ?> resor</strong> kan bytas till <strong><?php echo esc_html( $official['title'] ); ?></strong>.
							Gamla formulär som ersätts:
							<?php
							$parts = array();
							foreach ( $old_names as $name => $n ) {
								$parts[] = $name . ' (' . $n . ')';
							}
							echo esc_html( implode( ', ', $parts ) );
							?>.
						</p>
						<p>Bara formulärets id och titel i kortkoden ändras, på alla språkversioner av sidorna. Reskoden och resten av sidan lämnas orörda. Resor med felaktig reskod hoppas över.</p>
						<button type="submit" name="all" value="1" class="button button-primary">Byt formulär på alla <?php echo count( $swappable ); ?> resor</button>
						<span class="cvb-hint">eller använd knappen <strong>Byt</strong> på en resa i taget.</span>
					</div>
				<?php endif; ?>
			</form>
			<?php
			// ?reskod=IT-BZRBV-08 (eller flera, kommaseparerade) visar bara de resorna (Pär, 2026-11-19).
			$only_raw  = isset( $_GET['reskod'] ) ? sanitize_text_field( wp_unslash( $_GET['reskod'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$only_keys = array_values( array_filter( array_map( array( __CLASS__, 'code_key' ), explode( ',', $only_raw ) ) ) );
			if ( $only_keys ) :
				$found = array_filter( array_keys( $tours ), function ( $c ) use ( $only_keys ) { return in_array( self::code_key( $c ), $only_keys, true ); } );
				?>
				<div class="cvb-only <?php echo $found ? '' : 'cvb-only-none'; ?>">
					<?php if ( $found ) : ?>
						Visar bara reskod <strong><?php echo esc_html( $only_raw ); ?></strong>.
					<?php else : ?>
						Ingen resa på sajten har reskoden <strong><?php echo esc_html( $only_raw ); ?></strong>. Kontrollera att kortkoden på resans sida har samma reskod.
					<?php endif; ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>">Visa alla resor</a>
				</div>
			<?php endif; ?>
			<p class="cvb-filter"<?php echo $only_keys ? ' hidden' : ''; ?>>
				<label>Typ:
					<select id="cvb-filter-typ">
						<option value="cykel" selected>Cykel</option>
						<option value="vandring">Vandring</option>
						<option value="">Alla</option>
					</select>
				</label>
				<input type="search" id="cvb-search" placeholder="Sök resa, reskod eller leverantör…" style="min-width:260px">
				<label><input type="checkbox" id="cvb-only-todo"> Visa bara gula (fel formulär eller reskod)</label>
				<span id="cvb-shown" class="cvb-shown"></span>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cvb_save' ); ?>
				<input type="hidden" name="action" value="cvb_save">
				<table class="widefat striped cvb-table">
					<thead>
						<tr>
							<th class="cvb-c" title="C = cykel, V = vandring">Typ</th>
							<th>Leverantör<br>Reskod</th>
							<th>Resa</th>
							<?php foreach ( $fields as $label ) : ?>
								<?php // Långa rubriker bryts efter förleden: "Stöld-" / "försäkring". ?>
								<th class="cvb-c"><?php echo preg_replace( '/(\p{L})(försäkring)$/u', '$1-<br>$2', esc_html( $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
							<?php endforeach; ?>
							<?php if ( self::SHOW_COMMENT ) : ?><th>Kommentar</th><?php endif; ?>
							<th>Hjälm</th>
							<th class="cvb-c cvb-status-col"><span class="screen-reader-text">Sparat</span></th>
						</tr>
					</thead>
					<?php
					$columns = 5 + count( $fields ) + ( self::SHOW_COMMENT ? 1 : 0 );
					$groups  = array(
						'published' => array_filter( $tours, function ( $t ) { return ! empty( $t['published'] ); } ),
						'drafts'    => array_filter( $tours, function ( $t ) { return empty( $t['published'] ); } ),
					);
					foreach ( $groups as $group => $group_tours ) :
						if ( ! $group_tours ) {
							continue;
						}
						if ( 'drafts' === $group ) :
							?>
							<tbody class="cvb-drafts">
							<tr class="cvb-sep"><th colspan="<?php echo (int) $columns; ?>">Ej publicerade sidor <span>(utkast, schemalagda och privata)</span></th></tr>
						<?php else : ?>
							<tbody>
						<?php endif; ?>
					<?php foreach ( $group_tours as $code => $tour ) :
						$s    = $rows[ $code ]['s'];
						$done = $rows[ $code ]['done'];
						$n    = 'cvb[' . esc_attr( $code ) . ']';
						// Rätt formulär: sidan har bara det officiella formuläret och reskoden är korrekt.
						$right_form = $official && $tour['forms'] && ! array_diff( $tour['forms'], $official['ids'] ) && ! self::code_problem( $code );
						// Gul rad: sidan använder ett annat formulär eller har en felaktig reskod (Pär, 2026-11-18).
						$cls        = ( $official && ! $right_form ? 'cvb-todo' : 'cvb-done' )
							. ( 'vandring' === $s['typ'] ? ' cvb-hike' : '' );
						?>
						<tr class="<?php echo esc_attr( $cls ); ?>" data-right-form="<?php echo $right_form ? '1' : '0'; ?>" data-key="<?php echo esc_attr( self::code_key( $code ) ); ?>" data-typ="<?php echo esc_attr( $s['typ'] ); ?>" data-search="<?php echo esc_attr( strtolower( $tour['title'] . ' ' . $code . ' ' . $s['leverantor'] ) ); ?>">
							<td class="cvb-c cvb-typcell">
								<input type="hidden" name="<?php echo $n; ?>[typ]" value="<?php echo esc_attr( $s['typ'] ); ?>" class="cvb-typ-input">
								<button type="button" class="cvb-typ-btn cvb-typ-<?php echo esc_attr( $s['typ'] ); ?>" aria-haspopup="true" aria-expanded="false" title="<?php echo esc_attr( $types[ $s['typ'] ] ); ?> – klicka för att byta"><?php echo esc_html( 'vandring' === $s['typ'] ? 'V' : 'C' ); ?></button>
								<div class="cvb-typ-menu" hidden>
									<?php foreach ( $types as $value => $label ) : ?>
										<button type="button" data-value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></button>
									<?php endforeach; ?>
								</div>
							</td>
							<td class="cvb-code">
								<input type="text" name="<?php echo $n; ?>[leverantor]" value="<?php echo esc_attr( $s['leverantor'] ); ?>" list="cvb-suppliers" class="cvb-supplier" placeholder="Leverantör saknas" aria-label="Leverantör">
								<code<?php echo self::code_problem( $code ) ? ' class="cvb-bad-code"' : ''; ?>><?php echo esc_html( $code ); ?></code>
							</td>
							<td>
								<?php // Namnet öppnar resans sida i en ny flik (Pär, 2026-11-20). ?>
								<a href="<?php echo esc_url( get_permalink( $tour['id'] ) ); ?>" target="_blank" rel="noopener" title="Visa resans sida (öppnas i ny flik)"><?php echo esc_html( $tour['title'] ); ?></a>
								<?php if ( 'publish' !== $tour['status'] ) : ?>
									<em class="cvb-status">(<?php echo esc_html( self::status_label( $tour['status'] ) ); ?>)</em>
								<?php endif; ?>
								<?php
								$other = $official ? array_diff( $tour['forms'], $official['ids'] ) : array();
								if ( $other ) :
									$names = array();
									foreach ( $other as $form_id ) {
										$names[] = isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : 'id ' . $form_id;
									}
									?>
									<span class="cvb-badge cvb-oldform" title="<?php echo esc_attr( 'Sidan använder ' . implode( ', ', array_unique( $names ) ) . '.' ); ?>">Annat formulär</span>
									<?php if ( ! self::code_problem( $code ) ) : ?>
										<button type="submit" form="cvb-swap" name="codes[]" value="<?php echo esc_attr( $code ); ?>" class="button-link cvb-swap-one" title="<?php echo esc_attr( 'Byt till ' . $official['title'] . ' på sidorna för den här resan' ); ?>">Byt</button>
									<?php endif; ?>
								<?php endif; ?>
								<?php if ( self::code_problem( $code ) ) : ?>
									<span class="cvb-badge cvb-bad" title="<?php echo esc_attr( self::code_problem( $code ) ); ?>">Felaktig reskod</span>
								<?php endif; ?>
								<?php if ( $tour['langs'] ) : ?>
									<span class="cvb-langs"><?php echo esc_html( strtoupper( implode( ' · ', $tour['langs'] ) ) ); ?></span>
								<?php endif; ?>
								<?php
								// Boendekategorier: en panel per resa, bara för de få resor som har val (Pär, 2026-11-19).
								$rooms     = self::room_choices();
								$room_sel  = self::room_options( $s );
								$room_text = array();
								foreach ( $room_sel as $rk ) {
									$room_text[] = $rooms[ $rk ] . ( ! empty( $s['rum_kod'][ $rk ] ) ? ' (' . $s['rum_kod'][ $rk ] . ')' : '' );
								}
								?>
								<div class="cvb-rum-cell">
									<button type="button" class="button-link cvb-rum-toggle" aria-expanded="false">Kategorier<?php echo $room_sel ? ': ' . esc_html( implode( ', ', $room_text ) ) : ''; ?></button>
									<?php if ( 1 === count( $room_sel ) ) : ?><span class="cvb-rum-warn" title="Rutan visas bara när det finns minst två kategorier">bara en kategori, visas inte</span><?php endif; ?>
									<div class="cvb-rum-panel" hidden>
										<p class="cvb-rum-help">Kryssa i de boendekategorier kunden kan välja mellan (minst två). Fyll i en reskod om kategorin bokas med en annan kod än resans <code><?php echo esc_html( $code ); ?></code>.</p>
										<?php foreach ( $rooms as $rk => $rlabel ) : ?>
											<label class="cvb-rum-row">
												<input type="checkbox" name="<?php echo $n; ?>[rum][<?php echo esc_attr( $rk ); ?>]" value="1" <?php checked( in_array( $rk, $room_sel, true ) ); ?>>
												<span><?php echo esc_html( $rlabel ); ?></span>
												<input type="text" name="<?php echo $n; ?>[rum_kod][<?php echo esc_attr( $rk ); ?>]" value="<?php echo esc_attr( isset( $s['rum_kod'][ $rk ] ) ? $s['rum_kod'][ $rk ] : '' ); ?>" placeholder="<?php echo esc_attr( $code ); ?>" class="cvb-rum-kod" aria-label="<?php echo esc_attr( 'Reskod för ' . $rlabel ); ?>">
											</label>
										<?php endforeach; ?>
									</div>
								</div>
								<?php // Klar visas inte längre men behålls i inställningarna (och i CSV). ?>
								<?php if ( $done ) : ?><input type="hidden" name="<?php echo $n; ?>[klar]" value="1"><?php endif; ?>
								<input type="hidden" name="<?php echo $n; ?>[present]" value="1">
								<?php if ( ! self::SHOW_COMMENT ) : ?>
									<input type="hidden" name="<?php echo $n; ?>[kommentar]" value="<?php echo esc_attr( $s['kommentar'] ); ?>">
								<?php endif; ?>
							</td>
							<?php foreach ( $fields as $key => $label ) : ?>
								<td class="cvb-c<?php echo in_array( $key, array( 'vanlig', 'el', 'sportig', 'gravel', 'egen' ), true ) ? ' cvb-bike' : ''; ?>"><input type="checkbox" name="<?php echo $n; ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?> aria-label="<?php echo esc_attr( $label ); ?>"></td>
							<?php endforeach; ?>
							<?php if ( self::SHOW_COMMENT ) : ?>
								<td><input type="text" name="<?php echo $n; ?>[kommentar]" value="<?php echo esc_attr( $s['kommentar'] ); ?>" class="regular-text" style="width:100%"></td>
							<?php endif; ?>
							<td class="cvb-bike">
								<select name="<?php echo $n; ?>[hjalm]">
									<?php foreach ( $helmets as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['hjalm'], $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td class="cvb-c cvb-status-col"><span class="cvb-save-status" aria-live="polite"></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<?php endforeach; ?>
				</table>
				<datalist id="cvb-suppliers">
					<?php foreach ( $suppliers as $supplier ) : ?>
						<option value="<?php echo esc_attr( $supplier ); ?>">
					<?php endforeach; ?>
				</datalist>
				<p class="cvb-autosave-note">Ändringar sparas direkt när du klickar i tabellen (✓ längst till höger). Knappen behövs bara om något inte gick att spara.</p>
				<?php submit_button( 'Spara alla' ); ?>
			</form>

			<?php if ( $orphans ) : ?>
				<h2>Sparade reskoder som inte hittas på någon sida</h2>
				<p>De här finns kvar i inställningarna men används inte i någon kortkod just nu (t.ex. en avpublicerad resa). De påverkar ingenting.</p>
				<p><?php echo esc_html( implode( ', ', array_keys( $orphans ) ) ); ?></p>
			<?php endif; ?>

			<hr>
			<h2>Importera och exportera</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-bottom:12px">
				<?php wp_nonce_field( 'cvb_import' ); ?>
				<input type="hidden" name="action" value="cvb_import">
				<p>
					CSV med <code>Reskod</code> (eller <code>Tripcode</code>), <code>Webbsida</code> (eller <code>Vår hemsida</code>) eller <code>Resa</code>,
					och någon av kolumnerna <code>Leverantör, Hjälm, Vanlig cykel, Elcykel, Sportig cykel, Gravel, Egen cykel, Stöldförsäkring, Assistansförsäkring, Klar</code>.
					Bara kolumnerna som finns i filen ändras, så det går att läsa in flikarna BIKE och HIKE i Booking Codes direkt för att fylla i leverantörerna.
				</p>
				<input type="file" name="cvb_csv" accept=".csv,text/csv" required>
				<?php submit_button( 'Importera CSV', 'secondary', 'submit', false ); ?>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cvb_export' ), 'cvb_export' ) ); ?>">Ladda ner som CSV</a>
		</div>

		<style>
			.cvb-table th, .cvb-table td { vertical-align: middle; }
			.cvb-table .cvb-c { text-align: center; }
			.cvb-table tr.cvb-todo td { background: #fff4ce; }
			.cvb-badge { display:inline-block; margin-left:6px; padding:1px 6px; border-radius:3px; background:#dba617; color:#fff; font-size:11px; }
			.cvb-table tr.cvb-hike td.cvb-bike { opacity: .3; }
			.cvb-langs { margin-left:6px; color:#787c82; font-size:11px; }
			.cvb-box { background:#fff; border:1px solid #c3c4c7; border-left:4px solid #2271b1; padding:4px 16px 12px; margin:12px 0 16px; max-width:1100px; }
			.cvb-box h2 { margin:12px 0 4px; font-size:15px; }
			.cvb-old { color:#996800; font-size:12px; }
			.cvb-warn { background:#fcf9e8; border:1px solid #dba617; border-radius:4px; padding:8px 12px; margin:8px 0; }
			.cvb-warn p { margin:6px 0; }
			.cvb-help summary, .cvb-box summary { cursor:pointer; color:#2271b1; margin:6px 0; }
			.cvb-table tr.cvb-sep th { background:#f0f0f1; padding:18px 10px 8px; text-align:left; font-size:14px; font-weight:600; border-top:2px solid #c3c4c7; }
			.cvb-table tr.cvb-sep th span { font-weight:400; color:#646970; }
			.cvb-table tbody.cvb-drafts td { opacity:.8; }
			.cvb-status { color:#646970; }
			.cvb-badge.cvb-bad { background:#d63638; cursor:help; }
			.cvb-badge.cvb-oldform { background:#996800; cursor:help; }
			.cvb-swapbox { background:#fff; border:1px solid #c3c4c7; border-left:4px solid #996800; padding:4px 16px 12px; margin:0 0 16px; max-width:1100px; }
			.cvb-swapbox p { margin:8px 0; }
			.cvb-hint { margin-left:8px; color:#646970; }
			.cvb-swap-one { margin-left:4px; font-size:12px; }
			.cvb-rum-cell { margin-top:2px; }
			.cvb-rum-toggle { font-size:12px; color:#646970; text-decoration:none; }
			.cvb-rum-toggle:hover, .cvb-rum-toggle[aria-expanded="true"] { color:#2271b1; text-decoration:underline; }
			.cvb-rum-warn { font-size:11px; color:#996800; margin-left:6px; }
			.cvb-rum-warn[hidden] { display:none; }
			.cvb-rum-panel { margin:6px 0 4px; padding:8px 10px; background:#fff; border:1px solid #c3c4c7; border-radius:4px; max-width:420px; }
			.cvb-rum-panel[hidden] { display:none; }
			.cvb-rum-help { margin:0 0 6px; font-size:12px; color:#50575e; }
			.cvb-rum-row { display:grid; grid-template-columns:auto 7.5em 1fr; gap:6px; align-items:center; margin:3px 0; font-size:13px; }
			.cvb-rum-kod { width:100%; font-family:monospace; font-size:12px; }
			.cvb-badge[hidden] { display:none; }
			.cvb-save-status { display:inline-block; width:1em; margin-left:4px; font-weight:700; }
			.cvb-table .cvb-status-col { width:1.5em; padding-left:0; padding-right:0; }
			.cvb-save-status { font-size:16px; }
			.cvb-shortcode-row { display:flex; gap:8px; align-items:center; }
			.cvb-shortcode-row input { width:min(620px, 70vw); }
			.cvb-save-saving { color:#646970; }
			.cvb-save-ok { color:#00a32a; }
			.cvb-save-err { color:#d63638; cursor:help; }
			.cvb-autosave-note { color:#646970; margin:12px 0 0; }
			.cvb-table code.cvb-bad-code { background:#fcf0f1; color:#8a2424; }
			.cvb-bad-note { margin-left:8px; color:#8a2424; }
			.cvb-only { background:#f0f6fc; border:1px solid #72aee6; border-radius:4px; padding:8px 12px; margin:8px 0 12px; max-width:1100px; }
			.cvb-only a { margin-left:12px; }
			.cvb-only.cvb-only-none { background:#fcf0f1; border-color:#d63638; }
			.cvb-filter[hidden] { display:none; }
			.cvb-filter { display:flex; flex-wrap:wrap; gap:8px 16px; align-items:center; }
			.cvb-shown { color:#646970; }
			.cvb-table .cvb-code { white-space: nowrap; }
			.cvb-table .cvb-supplier { display:block; width:13em; min-height:26px; line-height:1.4; padding:0 6px; margin:0 0 3px -7px; font-weight:600; }
			.cvb-table .cvb-supplier:not(:focus) { border-color:transparent; background:transparent; box-shadow:none; }
			.cvb-table .cvb-supplier:not(:focus):hover { border-color:#c3c4c7; }
			.cvb-table .cvb-code code { font-size:12px; }
			.cvb-table th.cvb-c:first-child, .cvb-typcell { width:34px; }
			.cvb-typcell { position:relative; }
			.cvb-typ-btn { width:28px; height:28px; border-radius:50%; border:0; color:#fff; font-weight:700; cursor:pointer; }
			.cvb-typ-btn.cvb-typ-cykel { background:#2271b1; }
			.cvb-typ-btn.cvb-typ-vandring { background:#00a32a; }
			.cvb-typ-btn:focus-visible { outline:2px solid #1d2327; outline-offset:2px; }
			.cvb-typ-menu { position:absolute; z-index:20; left:6px; top:calc(50% + 16px); display:flex; flex-direction:column; min-width:110px; padding:4px; background:#fff; border:1px solid #c3c4c7; border-radius:6px; box-shadow:0 4px 12px rgba(0,0,0,.15); }
			.cvb-typ-menu[hidden] { display:none; }
			.cvb-typ-menu button { text-align:left; padding:6px 10px; border:0; background:none; border-radius:4px; cursor:pointer; font-size:13px; }
			.cvb-typ-menu button:hover, .cvb-typ-menu button:focus { background:#f0f0f1; }
		</style>
		<script>
		( function () {
			var search = document.getElementById( 'cvb-search' );
			var todo = document.getElementById( 'cvb-only-todo' );
			var typ = document.getElementById( 'cvb-filter-typ' );
			var shown = document.getElementById( 'cvb-shown' );
			// Typen som filtreras på är den som var sparad när sidan laddades,
			// så att en rad inte försvinner medan man ändrar den.
			var only = <?php echo wp_json_encode( $only_keys ); ?>;
			function apply() {
				var q = ( search.value || '' ).toLowerCase();
				var n = 0;
				document.querySelectorAll( '.cvb-table tbody' ).forEach( function ( tbody ) {
					var visible = 0;
					tbody.querySelectorAll( 'tr:not(.cvb-sep)' ).forEach( function ( tr ) {
						var show = only.length
							? only.indexOf( tr.dataset.key ) !== -1
							: ( ! q || tr.dataset.search.indexOf( q ) !== -1 )
								&& ( ! typ.value || tr.dataset.typ === typ.value )
								&& ( ! todo.checked || tr.classList.contains( 'cvb-todo' ) );
						tr.style.display = show ? '' : 'none';
						if ( show ) {
							visible++;
						}
					} );
					var sep = tbody.querySelector( 'tr.cvb-sep' );
					if ( sep ) {
						sep.style.display = visible ? '' : 'none';
					}
					n += visible;
				} );
				shown.textContent = 'Visar ' + n + ' resor';
			}
			// Kopiera kortkod eller mall.
			document.querySelectorAll( '.cvb-copy' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var src = btn.dataset.copy ? { value: btn.dataset.copy, select: function () {} } : btn.closest( '.cvb-shortcode-row, tr, details' ).querySelector( '.cvb-copy-src' );
					var done = function () {
						var old = btn.textContent;
						btn.textContent = 'Kopierat!';
						setTimeout( function () { btn.textContent = old; }, 1500 );
					};
					if ( navigator.clipboard && window.isSecureContext ) {
						navigator.clipboard.writeText( src.value ).then( done );
					} else {
						var tmp = document.createElement( 'textarea' );
						tmp.value = src.value;
						document.body.appendChild( tmp );
						tmp.select();
						document.execCommand( 'copy' );
						tmp.remove();
						done();
					}
				} );
			} );

			// Spara en rad direkt (AJAX). Anropen körs ett i taget, så att två snabba
			// ändringar på olika rader inte skriver över varandra.
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var ajaxNonce = <?php echo wp_json_encode( wp_create_nonce( 'cvb_save_row' ) ); ?>;
			var queue = Promise.resolve();
			var pending = 0;
			function setStatus( tr, state, message ) {
				var el = tr.querySelector( '.cvb-save-status' );
				if ( ! el ) {
					return;
				}
				el.className = 'cvb-save-status cvb-save-' + state;
				el.textContent = state === 'saving' ? '…' : ( state === 'ok' ? '✓' : '!' );
				el.title = message || ( state === 'ok' ? 'Sparat' : ( state === 'saving' ? 'Sparar…' : 'Kunde inte spara' ) );
				if ( state === 'ok' ) {
					clearTimeout( el._t );
					el._t = setTimeout( function () { el.textContent = ''; el.className = 'cvb-save-status'; }, 2500 );
				}
			}
			function saveRow( tr ) {
				var data = new FormData();
				data.append( 'action', 'cvb_save_row' );
				data.append( '_ajax_nonce', ajaxNonce );
				tr.querySelectorAll( 'input[name^="cvb["], select[name^="cvb["]' ).forEach( function ( el ) {
					if ( el.type === 'checkbox' && ! el.checked ) {
						return;
					}
					data.append( el.name, el.value );
				} );
				setStatus( tr, 'saving' );
				pending++;
				queue = queue.then( function () {
					return fetch( ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( j ) {
							if ( j && j.success ) {
								setStatus( tr, 'ok' );
							} else {
								setStatus( tr, 'err', ( j && j.data && j.data.message ) || 'Kunde inte spara. Klicka Spara alla.' );
							}
						} )
						.catch( function () {
							setStatus( tr, 'err', 'Kunde inte spara (nätverksfel). Klicka Spara alla.' );
						} )
						.then( function () { pending--; } );
				} );
			}
			function updateRoomLabel( cell ) {
				var picked = [];
				cell.querySelectorAll( '.cvb-rum-row' ).forEach( function ( row ) {
					if ( row.querySelector( 'input[type=checkbox]' ).checked ) {
						var kod = row.querySelector( '.cvb-rum-kod' ).value.trim();
						picked.push( row.querySelector( 'span' ).textContent + ( kod ? ' (' + kod + ')' : '' ) );
					}
				} );
				cell.querySelector( '.cvb-rum-toggle' ).textContent = 'Kategorier' + ( picked.length ? ': ' + picked.join( ', ' ) : '' );
				var warn = cell.querySelector( '.cvb-rum-warn' );
				if ( warn ) {
					warn.hidden = picked.length !== 1;
				} else if ( picked.length === 1 ) {
					warn = document.createElement( 'span' );
					warn.className = 'cvb-rum-warn';
					warn.textContent = 'bara en kategori, visas inte';
					cell.insertBefore( warn, cell.querySelector( '.cvb-rum-panel' ) );
				}
			}
			document.querySelector( '.cvb-table' ).addEventListener( 'change', function ( e ) {
				var tr = e.target.closest( 'tbody tr' );
				if ( ! tr || ! e.target.name || e.target.name.indexOf( 'cvb[' ) !== 0 ) {
					return;
				}
				if ( e.target.closest( '.cvb-rum-cell' ) ) {
					updateRoomLabel( e.target.closest( '.cvb-rum-cell' ) );
				}
				saveRow( tr );
			} );
			window.addEventListener( 'beforeunload', function ( e ) {
				if ( pending > 0 ) {
					e.preventDefault();
					e.returnValue = '';
				}
			} );

			// Kategorier: panelen öppnas och stängs med länken på raden.
			document.querySelectorAll( '.cvb-rum-toggle' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var panel = btn.parentNode.querySelector( '.cvb-rum-panel' );
					panel.hidden = ! panel.hidden;
					btn.setAttribute( 'aria-expanded', panel.hidden ? 'false' : 'true' );
				} );
			} );

			// Typ: knappen C/V öppnar en liten meny med Cykel och Vandring.
			var labels = { cykel: 'Cykel', vandring: 'Vandring' };
			function closeMenus( except ) {
				document.querySelectorAll( '.cvb-typ-menu' ).forEach( function ( m ) {
					if ( m !== except && ! m.hidden ) {
						m.hidden = true;
						m.parentNode.querySelector( '.cvb-typ-btn' ).setAttribute( 'aria-expanded', 'false' );
					}
				} );
			}
			document.querySelectorAll( '.cvb-typcell' ).forEach( function ( cell ) {
				var btn = cell.querySelector( '.cvb-typ-btn' );
				var menu = cell.querySelector( '.cvb-typ-menu' );
				var input = cell.querySelector( '.cvb-typ-input' );
				btn.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					closeMenus( menu );
					menu.hidden = ! menu.hidden;
					btn.setAttribute( 'aria-expanded', menu.hidden ? 'false' : 'true' );
					if ( ! menu.hidden ) {
						menu.querySelector( '[data-value="' + input.value + '"]' ).focus();
					}
				} );
				menu.addEventListener( 'click', function ( e ) {
					var choice = e.target.closest( 'button' );
					if ( ! choice ) {
						return;
					}
					e.stopPropagation();
					var value = choice.dataset.value;
					input.value = value;
					btn.textContent = value === 'vandring' ? 'V' : 'C';
					btn.className = 'cvb-typ-btn cvb-typ-' + value;
					btn.title = labels[ value ] + ' – klicka för att byta';
					cell.closest( 'tr' ).classList.toggle( 'cvb-hike', value === 'vandring' );
					saveRow( cell.closest( 'tr' ) );
					closeMenus();
					btn.focus();
				} );
			} );
			document.addEventListener( 'click', function () {
				closeMenus();
			} );
			document.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' ) {
					closeMenus();
				}
			} );
			search.addEventListener( 'input', apply );
			todo.addEventListener( 'change', apply );
			typ.addEventListener( 'change', apply );
			apply();
		} )();
		</script>
		<?php
	}

	private static function render_notices() {
		$notice = get_transient( 'cvb_notice_' . get_current_user_id() );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'cvb_notice_' . get_current_user_id() );
		$class = ( 'error' === $notice['type'] ) ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . wp_kses_post( $notice['message'] ) . '</p></div>';
	}

	private static function notice_and_redirect( $message, $type = 'success' ) {
		set_transient( 'cvb_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Sparar en rad direkt när något ändras på den (AJAX), utan att sidan laddas om.
	 * Webbläsaren skickar samma fält som formuläret Spara alla, men bara för en resa.
	 */
	public static function ajax_save_row() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Behörighet saknas.' ), 403 );
		}
		check_ajax_referer( 'cvb_save_row' );
		$posted = isset( $_POST['cvb'] ) && is_array( $_POST['cvb'] ) ? wp_unslash( $_POST['cvb'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 1 !== count( $posted ) ) {
			wp_send_json_error( array( 'message' => 'En resa i taget.' ), 400 );
		}
		$code = sanitize_text_field( (string) key( $posted ) );
		$row  = reset( $posted );
		if ( '' === $code || ! is_array( $row ) || empty( $row['present'] ) ) {
			wp_send_json_error( array( 'message' => 'Reskod saknas.' ), 400 );
		}
		$all          = self::all_settings();
		$all[ $code ] = self::sanitize_row( $row );
		update_option( self::OPTION, $all, false );
		wp_send_json_success( array( 'code' => $code, 'settings' => $all[ $code ] ) );
	}

	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Behörighet saknas.' );
		}
		check_admin_referer( 'cvb_save' );

		$posted = isset( $_POST['cvb'] ) && is_array( $_POST['cvb'] ) ? wp_unslash( $_POST['cvb'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$all    = self::all_settings();
		$count  = 0;
		foreach ( $posted as $code => $row ) {
			$code = sanitize_text_field( $code );
			if ( '' === $code || empty( $row['present'] ) ) {
				continue;
			}
			$all[ $code ] = self::sanitize_row( $row );
			$count++;
		}
		update_option( self::OPTION, $all, false );
		self::notice_and_redirect( sprintf( 'Sparat %d resor.', $count ) );
	}

	/* ------------------------------------------------------------------
	 * CSV
	 * ------------------------------------------------------------------ */

	private static function norm( $s ) {
		$s = strtolower( remove_accents( trim( (string) $s ) ) );
		return preg_replace( '/[^a-z0-9]+/', '', $s );
	}

	private static function truthy( $v ) {
		return in_array( strtolower( trim( (string) $v ) ), array( 'true', 'sant', '1', 'ja', 'x', 'yes', '✓', '✔' ), true );
	}

	private static function helmet_from_text( $v ) {
		$n = self::norm( $v );
		if ( '' === $n ) {
			return '';
		}
		if ( false !== strpos( $n, 'hyr' ) ) {
			return 'hyra';
		}
		if ( false !== strpos( $n, 'ingar' ) || false !== strpos( $n, 'inkl' ) || false !== strpos( $n, 'gratis' ) ) {
			return 'ingar';
		}
		if ( false !== strpos( $n, 'erbjudsinte' ) || false !== strpos( $n, 'ingen' ) || 'nej' === $n ) {
			return 'nej';
		}
		return '';
	}

	public static function handle_import() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Behörighet saknas.' );
		}
		check_admin_referer( 'cvb_import' );

		if ( empty( $_FILES['cvb_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['cvb_csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::notice_and_redirect( 'Ingen fil valdes.', 'error' );
		}

		$result = self::import_csv_file( $_FILES['cvb_csv']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $result ) ) {
			self::notice_and_redirect( esc_html( $result->get_error_message() ), 'error' );
		}

		$msg = sprintf( 'Importerade %d resor.', $result['imported'] );
		if ( $result['unmatched'] ) {
			$msg .= '<br>Hittade ingen reskod för: ' . esc_html( implode( '; ', $result['unmatched'] ) );
		}
		self::notice_and_redirect( $msg, $result['unmatched'] ? 'error' : 'success' );
	}

	/** @return array|WP_Error */
	public static function import_csv_file( $path ) {
		$text = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $text || '' === trim( $text ) ) {
			return new WP_Error( 'cvb_empty', 'Filen är tom.' );
		}
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}

		$first_line = strtok( $text, "\n" );
		$delimiter  = ( substr_count( $first_line, ';' ) > substr_count( $first_line, ',' ) ) ? ';' : ',';

		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $fh, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rewind( $fh );

		$header = fgetcsv( $fh, 0, $delimiter );
		if ( ! $header ) {
			return new WP_Error( 'cvb_header', 'Kunde inte läsa rubrikraden.' );
		}
		$map = array();
		foreach ( $header as $i => $h ) {
			$map[ self::norm( $h ) ] = $i;
		}

		// Arket Booking Codes använder andra rubriker för samma sak.
		$aliases = array(
			'tripcode'   => 'reskod',
			'varhemsida' => 'webbsida',
			'resansnamn' => 'resa',
		);
		foreach ( $aliases as $alias => $name ) {
			if ( isset( $map[ $alias ] ) && ! isset( $map[ $name ] ) ) {
				$map[ $name ] = $map[ $alias ];
			}
		}

		$col = array(
			'resa'      => 'resa',
			'typ'       => 'typ',
			'reskod'    => 'reskod',
			'webbsida'  => 'webbsida',
			'leverantor' => 'leverantor',
			'hjalm'     => 'hjalm',
			'vanlig'    => 'vanligcykel',
			'el'        => 'elcykel',
			'sportig'   => 'sportigcykel',
			'gravel'    => isset( $map['gravelcykel'] ) ? 'gravelcykel' : 'gravel',
			'egen'      => 'egencykel',
			'stold'     => 'stoldforsakring',
			'assistans' => 'assistansforsakring',
			'kommentar' => 'kommentar',
			'klar'      => isset( $map['kollad'] ) ? 'kollad' : 'klar',
		);

		if ( ! isset( $map['reskod'] ) && ! isset( $map['webbsida'] ) && ! isset( $map['resa'] ) ) {
			return new WP_Error( 'cvb_cols', 'CSV-filen saknar kolumnen Reskod (Tripcode), Webbsida (Vår hemsida) eller Resa.' );
		}

		$tours    = self::find_tours();
		$by_title = array();
		foreach ( $tours as $code => $t ) {
			$by_title[ self::norm( $t['title'] ) ] = $code;
		}

		$all       = self::all_settings();
		$imported  = 0;
		$unmatched = array();

		while ( ( $cells = fgetcsv( $fh, 0, $delimiter ) ) !== false ) {
			if ( ! array_filter( $cells, 'strlen' ) ) {
				continue;
			}
			$get = function ( $key ) use ( $cells, $map, $col ) {
				$h = $col[ $key ];
				return ( isset( $map[ $h ] ) && isset( $cells[ $map[ $h ] ] ) ) ? trim( $cells[ $map[ $h ] ] ) : '';
			};
			$has = function ( $key ) use ( $map, $col ) {
				return isset( $map[ $col[ $key ] ] );
			};

			// Reskoden måste finnas i en kortkod på sajten. Annars provas adressen och sedan namnet.
			$code = self::normalize_code( $get( 'reskod' ) );
			if ( '' !== $code && ! isset( $tours[ $code ] ) ) {
				$code = '';
			}
			if ( '' === $code && '' !== $get( 'webbsida' ) ) {
				$post_id = url_to_postid( $get( 'webbsida' ) );
				if ( $post_id ) {
					$codes = self::extract_codes( get_post_field( 'post_content', $post_id ) );
					$code  = $codes ? $codes[0] : '';
				}
			}
			if ( '' === $code && '' !== $get( 'resa' ) && isset( $by_title[ self::norm( $get( 'resa' ) ) ] ) ) {
				$code = $by_title[ self::norm( $get( 'resa' ) ) ];
			}
			if ( '' === $code ) {
				$label       = $get( 'resa' ) ? $get( 'resa' ) : $get( 'webbsida' );
				$unmatched[] = $get( 'reskod' ) ? $get( 'reskod' ) . ( $label ? ' (' . $label . ')' : '' ) : $label;
				continue;
			}

			// Utgå från det som redan gäller och skriv bara över kolumnerna som finns i filen.
			$row = self::settings_for( $code, isset( $tours[ $code ] ) ? $tours[ $code ]['id'] : 0 );

			if ( $has( 'typ' ) ) {
				$typ = self::norm( $get( 'typ' ) );
				if ( 0 === strpos( $typ, 'vand' ) ) {
					$row['typ'] = 'vandring';
				} elseif ( 0 === strpos( $typ, 'cyk' ) || 0 === strpos( $typ, 'syk' ) ) {
					$row['typ'] = 'cykel';
				}
			}
			$helmet = $has( 'hjalm' ) ? self::helmet_from_text( $get( 'hjalm' ) ) : '';
			if ( '' !== $helmet ) {
				$row['hjalm'] = $helmet;
			}
			foreach ( array( 'vanlig', 'el', 'sportig', 'gravel', 'egen', 'stold', 'assistans' ) as $key ) {
				if ( $has( $key ) ) {
					$row[ $key ] = self::truthy( $get( $key ) );
				}
			}
			foreach ( array( 'leverantor', 'kommentar' ) as $key ) {
				if ( $has( $key ) && '' !== $get( $key ) ) {
					$row[ $key ] = $get( $key );
				}
			}

			// Leverantörernas regler gäller oavsett vad filen säger.
			if ( self::is_eurofun( $code ) ) {
				$row['sportig'] = 1;
			}
			$rule = self::helmet_rule( $code, $row['leverantor'] );
			if ( '' !== $rule ) {
				$row['hjalm'] = $rule;
			}

			// Klar om kolumnen Klar (eller Kollad) säger det, annars om hjälmvalet är ifyllt.
			if ( $has( 'klar' ) ) {
				$row['klar'] = self::truthy( $get( 'klar' ) );
			} elseif ( '' !== $helmet ) {
				$row['klar'] = 1;
			}

			$all[ sanitize_text_field( $code ) ] = self::sanitize_row( $row );
			$imported++;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		update_option( self::OPTION, $all, false );
		return array( 'imported' => $imported, 'unmatched' => array_values( array_filter( $unmatched ) ) );
	}

	public static function handle_export() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Behörighet saknas.' );
		}
		check_admin_referer( 'cvb_export' );

		$tours   = self::find_tours();
		$all     = self::all_settings();
		$helmets = self::helmet_choices();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bokningsval-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$types   = self::type_choices();
		fputcsv( $out, array( 'Resa', 'Typ', 'Reskod', 'Leverantör', 'Hjälm', 'Vanlig cykel', 'Elcykel', 'Sportig cykel', 'Gravel', 'Egen cykel', 'Stöldförsäkring', 'Assistansförsäkring', 'Kommentar', 'Klar' ) );
		foreach ( $tours as $code => $t ) {
			$s = self::settings_for( $code, $t['id'] );
			fputcsv(
				$out,
				array(
					$t['title'],
					$types[ $s['typ'] ],
					$code,
					$s['leverantor'],
					$helmets[ $s['hjalm'] ],
					$s['vanlig'] ? 'TRUE' : 'FALSE',
					$s['el'] ? 'TRUE' : 'FALSE',
					$s['sportig'] ? 'TRUE' : 'FALSE',
					$s['gravel'] ? 'TRUE' : 'FALSE',
					$s['egen'] ? 'TRUE' : 'FALSE',
					$s['stold'] ? 'TRUE' : 'FALSE',
					$s['assistans'] ? 'TRUE' : 'FALSE',
					$s['kommentar'],
					! empty( $all[ $code ]['klar'] ) ? 'TRUE' : 'FALSE',
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}

CV_Bokningsval::init();
