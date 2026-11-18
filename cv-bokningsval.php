<?php
/**
 * Plugin Name: Cykla & Vandra – Bokningsval
 * Description: Styr vilka cykeltyper, hjälmval och försäkringar som visas i bokningsformuläret (Contact Form 7) för varje resa, utifrån reskoden (tripcode) i kortkoden.
 * Version: 1.0.0
 * Author: Cykla & Vandra
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: cv-bokningsval
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CV_Bokningsval {

	const OPTION = 'cvb_settings';
	const CAP    = 'edit_pages';
	const SLUG   = 'cv-bokningsval';

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

	/** Standardläge för resor som inte är ifyllda. */
	public static function defaults() {
		return array(
			'hjalm'     => 'hyra',
			'vanlig'    => 1,
			'el'        => 1,
			'sportig'   => 0,
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
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
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

	public static function settings_for( $code ) {
		$all = self::all_settings();
		if ( '' !== $code && isset( $all[ $code ] ) && is_array( $all[ $code ] ) ) {
			return wp_parse_args( $all[ $code ], self::defaults() );
		}
		return self::defaults();
	}

	public static function sanitize_row( $row ) {
		$row   = is_array( $row ) ? $row : array();
		$clean = self::defaults();
		$hjalm = isset( $row['hjalm'] ) ? sanitize_key( $row['hjalm'] ) : 'hyra';
		$clean['hjalm'] = array_key_exists( $hjalm, self::helmet_choices() ) ? $hjalm : 'hyra';
		foreach ( array_keys( self::bool_fields() ) as $key ) {
			$clean[ $key ] = empty( $row[ $key ] ) ? 0 : 1;
		}
		$clean['kommentar'] = isset( $row['kommentar'] ) ? sanitize_text_field( $row['kommentar'] ) : '';
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

	/** Reskoden för formuläret som visas eller skickas just nu. */
	public static function current_tripcode() {
		// Vid inskick: det dolda fältet [hidden tripcode default:shortcode_attr].
		if ( isset( $_POST['_wpcf7'], $_POST['tripcode'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return sanitize_text_field( wp_unslash( $_POST['tripcode'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		// Vid visning: kortkodens attribut.
		if ( class_exists( 'WPCF7_ContactForm' ) ) {
			$form = WPCF7_ContactForm::get_current();
			if ( $form ) {
				$code = $form->shortcode_attr( 'tripcode' );
				if ( $code ) {
					return sanitize_text_field( $code );
				}
			}
		}
		return '';
	}

	public static function filter_form_tag( $tag, $replace = false ) {
		$name = self::tag_get( $tag, 'name' );
		if ( ! is_string( $name ) || '' === $name ) {
			return $tag;
		}

		$is_bike   = (bool) preg_match( '/^cykel-\d+$/', $name );
		$is_helmet = (bool) preg_match( '/^hjalm-\d+$/', $name );
		$is_ins    = in_array( $name, array( 'cykelforsakring', 'assistansforsakring' ), true );
		if ( ! $is_bike && ! $is_helmet && ! $is_ins ) {
			return $tag;
		}

		$s = self::settings_for( self::current_tripcode() );

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
			$type     = (string) self::tag_get( $tag, 'type' );
			$basetype = (string) self::tag_get( $tag, 'basetype' );
			$tag      = self::tag_set( $tag, 'type', rtrim( $type, '*' ) );
			$tag      = self::tag_set( $tag, 'basetype', rtrim( $basetype, '*' ) );
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
		$s = self::settings_for( self::current_tripcode() );
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

	public static function extract_codes( $content ) {
		$hay = (string) $content;
		// Avadas kodblock sparas base64-kodade.
		if ( preg_match_all( '#\[fusion_code[^\]]*\](.*?)\[/fusion_code\]#s', $hay, $m ) ) {
			foreach ( $m[1] as $block ) {
				$decoded = base64_decode( trim( $block ), true );
				if ( false !== $decoded ) {
					$hay .= "\n" . $decoded;
				}
			}
		}
		$codes = array();
		if ( preg_match_all( '/tripcode\s*=\s*(?:&quot;|&#8221;|&#8243;|["\'\x{201C}\x{201D}])?([A-Za-z0-9_\-]+)/u', $hay, $m ) ) {
			$codes = array_values( array_unique( $m[1] ) );
		}
		return $codes;
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
			foreach ( $codes as $code ) {
				if ( ! isset( $tours[ $code ] ) ) {
					$tours[ $code ] = array( 'posts' => array() );
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
				'langs'  => $langs,
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

	public static function admin_menu() {
		add_menu_page(
			'Bokningsval per resa',
			'Bokningsval',
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-forms',
			31
		);
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
		$not_done = 0;
		foreach ( $tours as $code => $t ) {
			if ( empty( $all[ $code ]['klar'] ) ) {
				$not_done++;
			}
		}
		?>
		<div class="wrap cvb-wrap">
			<h1>Bokningsval per resa</h1>
			<?php self::render_notices(); ?>
			<p>
				Varje rad är en reskod som finns i en kortkod på sajten. Nya resor dyker upp här automatiskt.
				Rader som inte är markerade som <strong>Klar</strong> visas i gult och använder standardläget
				(vanlig cykel + elcykel, hyrhjälm, egen cykel tillåten, inga försäkringar) tills ni sparar dem.
			</p>
			<p>
				<strong><?php echo esc_html( count( $tours ) ); ?></strong> resor,
				<strong><?php echo esc_html( $not_done ); ?></strong> ej klara.
				&nbsp;
				<input type="search" id="cvb-search" placeholder="Sök resa eller reskod…" style="min-width:240px">
				<label style="margin-left:12px"><input type="checkbox" id="cvb-only-todo"> Visa bara ej klara</label>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cvb_save' ); ?>
				<input type="hidden" name="action" value="cvb_save">
				<table class="widefat striped cvb-table">
					<thead>
						<tr>
							<th>Resa</th>
							<th>Reskod</th>
							<th>Hjälm</th>
							<?php foreach ( $fields as $label ) : ?>
								<th class="cvb-c"><?php echo esc_html( $label ); ?></th>
							<?php endforeach; ?>
							<th>Kommentar</th>
							<th class="cvb-c">Klar</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $tours as $code => $tour ) :
						$s    = self::settings_for( $code );
						$done = ! empty( $all[ $code ]['klar'] );
						$n    = 'cvb[' . esc_attr( $code ) . ']';
						?>
						<tr class="<?php echo $done ? 'cvb-done' : 'cvb-todo'; ?>" data-search="<?php echo esc_attr( strtolower( $tour['title'] . ' ' . $code ) ); ?>">
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $tour['id'] ) ); ?>"><?php echo esc_html( $tour['title'] ); ?></a>
								<?php if ( 'publish' !== $tour['status'] ) : ?>
									<em>(<?php echo esc_html( $tour['status'] ); ?>)</em>
								<?php endif; ?>
								<?php if ( $tour['langs'] ) : ?>
									<span class="cvb-langs"><?php echo esc_html( strtoupper( implode( ' · ', $tour['langs'] ) ) ); ?></span>
								<?php endif; ?>
								<?php if ( ! $done ) : ?><span class="cvb-badge">Ej ifylld</span><?php endif; ?>
								<input type="hidden" name="<?php echo $n; ?>[present]" value="1">
							</td>
							<td><code><?php echo esc_html( $code ); ?></code></td>
							<td>
								<select name="<?php echo $n; ?>[hjalm]">
									<?php foreach ( $helmets as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['hjalm'], $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<?php foreach ( $fields as $key => $label ) : ?>
								<td class="cvb-c"><input type="checkbox" name="<?php echo $n; ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?> aria-label="<?php echo esc_attr( $label ); ?>"></td>
							<?php endforeach; ?>
							<td><input type="text" name="<?php echo $n; ?>[kommentar]" value="<?php echo esc_attr( $s['kommentar'] ); ?>" class="regular-text" style="width:100%"></td>
							<td class="cvb-c"><input type="checkbox" name="<?php echo $n; ?>[klar]" value="1" <?php checked( $done ); ?> aria-label="Klar"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
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
					CSV med rubrikerna <code>Resa, Hjälm, Vanlig cykel, Elcykel, Sportig cykel, Egen cykel, Stöldförsäkring, Assistansförsäkring, Kommentar</code>
					samt <code>Reskod</code> eller <code>Webbsida</code> (adressen till resan). Resor som matchas skrivs över.
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
			.cvb-langs { margin-left:6px; color:#787c82; font-size:11px; }
		</style>
		<script>
		( function () {
			var search = document.getElementById( 'cvb-search' );
			var todo = document.getElementById( 'cvb-only-todo' );
			function apply() {
				var q = ( search.value || '' ).toLowerCase();
				document.querySelectorAll( '.cvb-table tbody tr' ).forEach( function ( tr ) {
					var show = ( ! q || tr.dataset.search.indexOf( q ) !== -1 ) && ( ! todo.checked || tr.classList.contains( 'cvb-todo' ) );
					tr.style.display = show ? '' : 'none';
				} );
			}
			search.addEventListener( 'input', apply );
			todo.addEventListener( 'change', apply );
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

		$col = array(
			'resa'      => 'resa',
			'reskod'    => 'reskod',
			'webbsida'  => 'webbsida',
			'hjalm'     => 'hjalm',
			'vanlig'    => 'vanligcykel',
			'el'        => 'elcykel',
			'sportig'   => 'sportigcykel',
			'egen'      => 'egencykel',
			'stold'     => 'stoldforsakring',
			'assistans' => 'assistansforsakring',
			'kommentar' => 'kommentar',
			'klar'      => 'klar',
		);

		if ( ! isset( $map['reskod'] ) && ! isset( $map['webbsida'] ) && ! isset( $map['resa'] ) ) {
			return new WP_Error( 'cvb_cols', 'CSV-filen saknar kolumnen Reskod, Webbsida eller Resa.' );
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

			$code = $get( 'reskod' );
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
				$unmatched[] = $get( 'resa' ) ? $get( 'resa' ) : $get( 'webbsida' );
				continue;
			}

			$helmet = self::helmet_from_text( $get( 'hjalm' ) );
			$row    = array(
				'hjalm'     => $helmet ? $helmet : 'hyra',
				'kommentar' => $get( 'kommentar' ),
			);
			foreach ( array( 'vanlig', 'el', 'sportig', 'egen', 'stold', 'assistans' ) as $key ) {
				$row[ $key ] = self::truthy( $get( $key ) );
			}
			// Klar om kolumnen Klar säger det, annars om hjälmvalet är ifyllt.
			$row['klar'] = isset( $map['klar'] ) ? self::truthy( $get( 'klar' ) ) : ( '' !== $helmet );

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
		fputcsv( $out, array( 'Resa', 'Reskod', 'Hjälm', 'Vanlig cykel', 'Elcykel', 'Sportig cykel', 'Egen cykel', 'Stöldförsäkring', 'Assistansförsäkring', 'Kommentar', 'Klar' ) );
		foreach ( $tours as $code => $t ) {
			$s = self::settings_for( $code );
			fputcsv(
				$out,
				array(
					$t['title'],
					$code,
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
