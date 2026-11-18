<?php
/**
 * Plugin Name: Cykla & Vandra – Bokningsformulär
 * Description: Ett bokningsformulär (Contact Form 7) för både cykel- och vandringsresor. Styr vilka cykeltyper, hjälmval och försäkringar som visas för varje resa, utifrån reskoden (tripcode) i kortkoden.
 * Version: 1.6.0
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
	 * cykel-N: "Vanlig cykel" "Elcykel" "Sportig cykel" "Tar med egen"
	 * hjalm-N: "Hyr hjälm" "Tar med egen" "Hjälm ingår"
	 */
	const BIKE_INDEX   = array( 0 => 'vanlig', 1 => 'el', 2 => 'sportig', 3 => 'egen' );
	const HELMET_INDEX = array(
		'hyra'  => array( 0, 1 ),
		'nej'   => array( 1 ),
		'ingar' => array( 2 ),
	);

	public static function bool_fields() {
		return array(
			'vanlig'    => 'Vanlig cykel',
			'el'        => 'Elcykel',
			'sportig'   => 'Sportig cykel',
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
	 * reskoden där känns S-Capes koder igen på mönstret, t.ex. ITSB290 och FRSW260.
	 */
	public static function supplier_for( $code ) {
		static $map = null;
		if ( null === $map ) {
			$map = include __DIR__ . '/data/leverantorer.php';
			$map = is_array( $map ) ? $map : array();
		}
		$code = self::normalize_code( $code );
		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}
		if ( preg_match( '/^[A-Z]{2}S[BWM][A-Z]*\d/', strtoupper( $code ) ) ) {
			return 'S-Cape';
		}
		return '';
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
			'egen'      => 1,
			'stold'     => 0,
			'assistans' => 0,
			'kommentar' => '',
			'klar'      => 0,
		);
	}

	public static function init() {
		// Gör att tripcode="..." i kortkoden går att läsa (ofarligt om en snippet redan gör samma sak).
		add_filter( 'shortcode_atts_wpcf7', array( __CLASS__, 'allow_tripcode_attr' ), 10, 3 );
		add_filter( 'wpcf7_form_tag', array( __CLASS__, 'filter_form_tag' ), 20, 2 );
		add_filter( 'wpcf7_form_elements', array( __CLASS__, 'filter_form_html' ), 20 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'migrate' ) );
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
			add_filter( 'cyklavandra_verktyg', array( __CLASS__, 'tool_card' ) );
			add_filter( 'plugin_action_links', array( __CLASS__, 'settings_link' ), 5, 2 );
			add_action( 'admin_post_cvb_save', array( __CLASS__, 'handle_save' ) );
			add_action( 'admin_post_cvb_import', array( __CLASS__, 'handle_import' ) );
			add_action( 'admin_post_cvb_export', array( __CLASS__, 'handle_export' ) );
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
	 * 4: S-Capes resor i Frankrike får "Hjälm ingår".
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
		return $clean;
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
		if ( ! $is_bike && ! $is_helmet && ! $is_height && ! $is_ins ) {
			return $tag;
		}

		$s = self::current_settings();

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
			return self::reduce_options( $tag, $keep );
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

	public static function filter_form_html( $html ) {
		$s = self::current_settings();
		if ( 'vandring' === $s['typ'] ) {
			$html = preg_replace( '#<div class="[^"]*\bcvb-cykel\b[^"]*">.*?</div>#s', '', $html );
		}
		if ( empty( $s['stold'] ) ) {
			$html = preg_replace( '#<div class="cvb-stold">.*?</div>#s', '', $html );
		}
		if ( empty( $s['assistans'] ) ) {
			$html = preg_replace( '#<div class="cvb-assistans">.*?</div>#s', '', $html );
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
			$form = WPCF7_ContactForm::get_instance( $post );
			if ( ! $form ) {
				continue;
			}
			$body = (string) $form->prop( 'form' );
			if ( false === strpos( $body, 'tripcode' ) ) {
				continue;
			}
			$lang    = '';
			$details = apply_filters( 'wpml_post_language_details', null, (int) $post->ID );
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				$lang = strtoupper( $details['language_code'] );
			}
			$ids = array( (string) $form->id() );
			if ( preg_match( '/\bid="([^"]+)"/', $form->shortcode(), $idm ) ) {
				$ids[] = $idm[1];
			}
			$forms[] = array(
				'ids'          => $ids,
				'title'        => $form->title(),
				'lang'         => $lang,
				'shortcode'    => preg_replace( '/\]$/', ' tripcode="RESKOD"]', $form->shortcode() ),
				'new_template' => false !== strpos( $body, 'cvb-cykel' ),
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
	public static function official_form( $forms = null ) {
		$forms = null === $forms ? self::booking_forms() : $forms;
		$main  = null;
		foreach ( $forms as $f ) {
			if ( ! $f['new_template'] ) {
				continue;
			}
			if ( ! $main ) {
				$main          = $f;
				$main['langs'] = array();
			}
			$main['ids']     = array_values( array_unique( array_merge( $main['ids'], $f['ids'] ) ) );
			$main['langs'][] = $f['lang'];
		}
		if ( $main ) {
			$main['langs'] = array_values( array_unique( array_filter( $main['langs'] ) ) );
		}
		return $main;
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
					Kopiera kortkoden för en resa med knappen <strong>Kopiera</strong> längst till höger i tabellen, och klistra in den på resans sida.
					Resor vars sida använder ett annat formulär är markerade med <span class="cvb-badge cvb-oldform">Annat formulär</span>.
					Nya resor dyker upp i tabellen så fort sidan är sparad, även som utkast.
				</p>
				<?php if ( count( $official['langs'] ) < 2 && $old ) : ?>
					<p class="cvb-old">Formuläret finns bara på <?php echo esc_html( $official['langs'] ? implode( ', ', $official['langs'] ) : 'ett språk' ); ?>. Översätt det i WPML så att de norska sidorna får ett norskt formulär.</p>
				<?php endif; ?>
			<?php else : ?>
				<h2>Så lägger du in bokningsformuläret på en resa</h2>
				<p>
					Inget formulär i Contact Form 7 har den nya mallen än. Skapa ett formulär under <strong>Kontakt</strong>
					och klistra in mallen nedan under fliken Formulär. Därefter visas en knapp för att kopiera kortkoden för varje resa.
				</p>
			<?php endif; ?>
			<details>
				<summary>Visa formulärmallen (fliken Formulär i Contact Form 7)</summary>
				<p>Klistra in i ett nytt eller befintligt bokningsformulär. Fältnamnen är desamma som förut, så mailen och Flamingo fungerar som tidigare.</p>
				<textarea readonly rows="12" class="large-text code cvb-copy-src"><?php echo esc_textarea( $template ); ?></textarea>
				<p><button type="button" class="button cvb-copy">Kopiera mallen</button></p>
			</details>
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
		$not_done = 0;
		$rows     = array();
		foreach ( $tours as $code => $t ) {
			$s    = self::settings_for( $code, $t['id'] );
			$done = ! empty( $all[ $code ]['klar'] );
			// Vandringsresor behöver inga val, så de räknas inte som ej ifyllda.
			$todo = ! $done && 'cykel' === $s['typ'];
			if ( $todo ) {
				$not_done++;
			}
			$rows[ $code ] = array( 's' => $s, 'done' => $done, 'todo' => $todo );
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
				Cykelresor som inte är markerade som <strong>Klar</strong> visas i gult och använder standardläget
				(vanlig cykel + elcykel, hyrhjälm, egen cykel tillåten, inga försäkringar) tills ni sparar dem.
				Resor från Eurobike, Radweg-Reisen och Velociped (reskoder som börjar med landskod och bindestreck, t.ex. <code>DE-</code>)
				har sportig cykel och ingen hjälmhyra. På S-Capes resor i Frankrike (<code>FRSB…</code>) ingår hjälmen.
				Leverantören hämtas från arket Booking Codes (inbyggd lista) om den inte är ifylld.
				</p>
			</details>
			<p>
				<strong><?php echo esc_html( count( $tours ) ); ?></strong> resor,
				<strong><?php echo esc_html( $not_done ); ?></strong> cykelresor ej klara.
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
			<p class="cvb-filter">
				<label>Typ:
					<select id="cvb-filter-typ">
						<option value="cykel" selected>Cykel</option>
						<option value="vandring">Vandring</option>
						<option value="">Alla</option>
					</select>
				</label>
				<input type="search" id="cvb-search" placeholder="Sök resa, reskod eller leverantör…" style="min-width:260px">
				<label><input type="checkbox" id="cvb-only-todo"> Visa bara ej klara</label>
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
								<th class="cvb-c"><?php echo esc_html( $label ); ?></th>
							<?php endforeach; ?>
							<?php if ( self::SHOW_COMMENT ) : ?><th>Kommentar</th><?php endif; ?>
							<th>Hjälm</th>
							<th class="cvb-c">Klar</th>
							<?php if ( $official ) : ?><th class="cvb-c">Kortkod</th><?php endif; ?>
						</tr>
					</thead>
					<?php
					$columns = 5 + count( $fields ) + ( self::SHOW_COMMENT ? 1 : 0 ) + ( $official ? 1 : 0 );
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
						$cls  = ( $rows[ $code ]['todo'] ? 'cvb-todo' : 'cvb-done' ) . ( 'vandring' === $s['typ'] ? ' cvb-hike' : '' );
						?>
						<tr class="<?php echo esc_attr( $cls ); ?>" data-typ="<?php echo esc_attr( $s['typ'] ); ?>" data-search="<?php echo esc_attr( strtolower( $tour['title'] . ' ' . $code . ' ' . $s['leverantor'] ) ); ?>">
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
								<a href="<?php echo esc_url( get_edit_post_link( $tour['id'] ) ); ?>" title="Redigera sidan"><?php echo esc_html( $tour['title'] ); ?></a>
								<a href="<?php echo esc_url( get_permalink( $tour['id'] ) ); ?>" class="cvb-view" target="_blank" rel="noopener" title="Visa resans sida" aria-label="Visa resans sida (öppnas i ny flik)">&#8599;</a>
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
									<span class="cvb-badge cvb-oldform" title="<?php echo esc_attr( 'Sidan använder ' . implode( ', ', array_unique( $names ) ) . '. Byt till kortkoden från knappen Kopiera.' ); ?>">Annat formulär</span>
								<?php endif; ?>
								<?php if ( self::code_problem( $code ) ) : ?>
									<span class="cvb-badge cvb-bad" title="<?php echo esc_attr( self::code_problem( $code ) ); ?>">Felaktig reskod</span>
								<?php endif; ?>
								<?php if ( $tour['langs'] ) : ?>
									<span class="cvb-langs"><?php echo esc_html( strtoupper( implode( ' · ', $tour['langs'] ) ) ); ?></span>
								<?php endif; ?>
								<?php if ( $rows[ $code ]['todo'] ) : ?><span class="cvb-badge">Ej ifylld</span><?php endif; ?>
								<input type="hidden" name="<?php echo $n; ?>[present]" value="1">
								<?php if ( ! self::SHOW_COMMENT ) : ?>
									<input type="hidden" name="<?php echo $n; ?>[kommentar]" value="<?php echo esc_attr( $s['kommentar'] ); ?>">
								<?php endif; ?>
							</td>
							<?php foreach ( $fields as $key => $label ) : ?>
								<td class="cvb-c<?php echo in_array( $key, array( 'vanlig', 'el', 'sportig', 'egen' ), true ) ? ' cvb-bike' : ''; ?>"><input type="checkbox" name="<?php echo $n; ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?> aria-label="<?php echo esc_attr( $label ); ?>"></td>
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
							<td class="cvb-c"><input type="checkbox" name="<?php echo $n; ?>[klar]" value="1" <?php checked( $done ); ?> aria-label="Klar"></td>
							<?php if ( $official ) : ?>
								<td class="cvb-c">
									<?php if ( self::code_problem( $code ) ) : ?>
										<button type="button" class="button button-small" disabled title="Rätta reskoden på sidan först">Kopiera</button>
									<?php else : ?>
										<button type="button" class="button button-small cvb-copy" data-copy="<?php echo esc_attr( self::shortcode_for( $code, $official ) ); ?>" title="<?php echo esc_attr( self::shortcode_for( $code, $official ) ); ?>">Kopiera</button>
									<?php endif; ?>
								</td>
							<?php endif; ?>
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
					och någon av kolumnerna <code>Leverantör, Hjälm, Vanlig cykel, Elcykel, Sportig cykel, Egen cykel, Stöldförsäkring, Assistansförsäkring, Klar</code>.
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
			.cvb-help summary, .cvb-box summary { cursor:pointer; color:#2271b1; margin:6px 0; }
			.cvb-table tr.cvb-sep th { background:#f0f0f1; padding:18px 10px 8px; text-align:left; font-size:14px; font-weight:600; border-top:2px solid #c3c4c7; }
			.cvb-table tr.cvb-sep th span { font-weight:400; color:#646970; }
			.cvb-table tbody.cvb-drafts td { opacity:.8; }
			.cvb-status { color:#646970; }
			.cvb-badge.cvb-bad { background:#d63638; cursor:help; }
			.cvb-badge.cvb-oldform { background:#996800; cursor:help; }
			.cvb-table code.cvb-bad-code { background:#fcf0f1; color:#8a2424; }
			.cvb-bad-note { margin-left:8px; color:#8a2424; }
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
			.cvb-view { margin-left:4px; text-decoration:none; font-size:15px; }
		</style>
		<script>
		( function () {
			var search = document.getElementById( 'cvb-search' );
			var todo = document.getElementById( 'cvb-only-todo' );
			var typ = document.getElementById( 'cvb-filter-typ' );
			var shown = document.getElementById( 'cvb-shown' );
			// Typen som filtreras på är den som var sparad när sidan laddades,
			// så att en rad inte försvinner medan man ändrar den.
			function apply() {
				var q = ( search.value || '' ).toLowerCase();
				var n = 0;
				document.querySelectorAll( '.cvb-table tbody' ).forEach( function ( tbody ) {
					var visible = 0;
					tbody.querySelectorAll( 'tr:not(.cvb-sep)' ).forEach( function ( tr ) {
						var show = ( ! q || tr.dataset.search.indexOf( q ) !== -1 )
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
					var src = btn.dataset.copy ? { value: btn.dataset.copy, select: function () {} } : btn.closest( 'tr, details' ).querySelector( '.cvb-copy-src' );
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
		if ( false !== strpos( $n, 'ingar' ) || false !== strpos( $n, 'inkl' ) ) {
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
			foreach ( array( 'vanlig', 'el', 'sportig', 'egen', 'stold', 'assistans' ) as $key ) {
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
		fputcsv( $out, array( 'Resa', 'Typ', 'Reskod', 'Leverantör', 'Hjälm', 'Vanlig cykel', 'Elcykel', 'Sportig cykel', 'Egen cykel', 'Stöldförsäkring', 'Assistansförsäkring', 'Kommentar', 'Klar' ) );
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
