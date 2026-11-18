<?php
/**
 * Uppdateringar via GitHub.
 *
 * Tillägget frågar GitHub efter den senaste releasen och visar den som en
 * vanlig uppdatering under Tillägg och Uppdateringar i WordPress. Releasen
 * måste ha filen bokningsformular.zip som bilaga (det sköter GitHub Actions).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CV_Bokningsval_Updater {

	const REPO      = 'cyklavandra-se/bokningsformular';
	const ASSET     = 'bokningsformular.zip';
	const TRANSIENT = 'cvb_github_release';
	const CACHE_TTL = 21600; // sex timmar

	/** @var string Absolut sökväg till huvudfilen. */
	private static $file;

	public static function init( $file ) {
		self::$file = $file;

		// Kopplas till "Update URI: https://github.com/..." i tilläggets huvud (WP 5.8+).
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 0 );

		// Länkar i listan under Tillägg.
		add_filter( 'plugin_action_links', array( __CLASS__, 'action_links' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_cvb_check_update', array( __CLASS__, 'handle_check_update' ) );
		add_action( 'admin_notices', array( __CLASS__, 'check_notice' ) );
	}

	/** "Sök efter uppdatering" under tilläggets namn. */
	public static function action_links( $links, $file ) {
		if ( self::basename() !== $file || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}
		$url     = wp_nonce_url( admin_url( 'admin-post.php?action=cvb_check_update' ), 'cvb_check_update' );
		$links[] = '<a href="' . esc_url( $url ) . '">Sök efter uppdatering</a>';
		return $links;
	}

	/** "Visa detaljer" efter versionsnumret (WordPress lägger bara till den själv när en uppdatering finns). */
	public static function row_meta( $meta, $file ) {
		if ( self::basename() !== $file ) {
			return $meta;
		}
		foreach ( $meta as $item ) {
			if ( false !== strpos( $item, 'open-plugin-details-modal' ) ) {
				return $meta;
			}
		}
		$url    = self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . dirname( self::basename() ) . '&TB_iframe=true&width=600&height=550' );
		$meta[] = '<a href="' . esc_url( $url ) . '" class="thickbox open-plugin-details-modal">Visa detaljer</a>';
		return $meta;
	}

	/** Frågar GitHub direkt och skickar tillbaka till listan över tillägg. */
	public static function handle_check_update() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Du har inte behörighet att uppdatera tillägg.' );
		}
		check_admin_referer( 'cvb_check_update' );

		self::clear_cache();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$updates = get_site_transient( 'update_plugins' );
		if ( ! self::release() ) {
			$result = 'fel';
		} elseif ( $updates && isset( $updates->response[ self::basename() ] ) ) {
			$result = 'ny';
		} else {
			$result = 'senaste';
		}
		wp_safe_redirect( admin_url( 'plugins.php?cvb_check=' . $result ) );
		exit;
	}

	public static function check_notice() {
		$result = isset( $_GET['cvb_check'] ) ? sanitize_key( $_GET['cvb_check'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$name   = 'Cykla & Vandra – Bokningsformulär';
		if ( 'ny' === $result ) {
			$release = self::release();
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $name . ': version ' . $release['version'] . ' finns. Uppdatera i listan nedan.' ) . '</p></div>';
		} elseif ( 'senaste' === $result ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $name . ': du har den senaste versionen.' ) . '</p></div>';
		} elseif ( 'fel' === $result ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $name . ': kunde inte hämta någon release från GitHub. Försök igen senare.' ) . '</p></div>';
		}
	}

	/** Tilläggets huvudfil relativt plugins-mappen, t.ex. bokningsformular/bokningsformular.php */
	private static function basename() {
		return plugin_basename( self::$file );
	}

	/** Ikoner som visas i listan över uppdateringar. */
	public static function icons() {
		$base = plugins_url( 'assets/', self::$file );
		return array(
			'svg' => $base . 'icon.svg',
			'1x'  => $base . 'icon-128x128.png',
			'2x'  => $base . 'icon-256x256.png',
		);
	}

	/**
	 * Hämtar senaste releasen från GitHub (sparas i sex timmar).
	 * "Sök igen" under Uppdateringar hämtar alltid på nytt.
	 *
	 * @return array|null
	 */
	private static function release() {
		$force = is_admin() && ! empty( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$cached = get_transient( self::TRANSIENT );
		if ( ! $force && is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; bokningsformular',
				),
			)
		);

		$release = array();
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) && ! empty( $data['tag_name'] ) && ! empty( $data['assets'] ) ) {
				foreach ( $data['assets'] as $asset ) {
					if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET === $asset['name'] ) {
						$release = array(
							'version'   => ltrim( $data['tag_name'], 'vV' ),
							'package'   => $asset['browser_download_url'],
							'url'       => $data['html_url'],
							'changelog' => isset( $data['body'] ) ? (string) $data['body'] : '',
							'published' => isset( $data['published_at'] ) ? $data['published_at'] : '',
						);
						break;
					}
				}
			}
		}

		// Misslyckade anrop sparas en kortare stund så att GitHub inte frågas vid varje sidladdning.
		set_transient( self::TRANSIENT, $release, $release ? self::CACHE_TTL : 3600 );
		return $release ? $release : null;
	}

	public static function check_update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$release = self::release();
		if ( ! $release ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => dirname( self::basename() ),
			'plugin'       => self::basename(),
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'icons'        => self::icons(),
			'requires'     => isset( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '',
			'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '',
		);
	}

	/** Innehållet i rutan "Visa detaljer". */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( self::basename() ) !== $args->slug ) {
			return $result;
		}
		$data    = get_plugin_data( self::$file, false, false );
		$release = self::release();
		if ( ! $release ) {
			// Visa den installerade versionen hellre än att fråga wordpress.org.
			$release = array(
				'version'   => $data['Version'],
				'package'   => '',
				'changelog' => '',
				'published' => '',
			);
		}

		return (object) array(
			'name'          => $data['Name'],
			'slug'          => $args->slug,
			'version'       => $release['version'],
			'author'        => $data['Author'],
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $data['RequiresWP'],
			'requires_php'  => $data['RequiresPHP'],
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'icons'         => self::icons(),
			'sections'      => array(
				'description' => wpautop( esc_html( $data['Description'] ) ),
				'changelog'   => self::markdown_to_html( $release['changelog'] ),
			),
		);
	}

	/** Enkel omvandling av releasetexten (rubriker, punktlistor, fetstil, kod). */
	private static function markdown_to_html( $md ) {
		if ( '' === trim( $md ) ) {
			return '<p>Inga ändringar beskrivna.</p>';
		}
		$html    = '';
		$in_list = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $md ) as $line ) {
			$text = esc_html( trim( $line ) );
			$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
			$text = preg_replace( '/`(.+?)`/', '<code>$1</code>', $text );
			if ( preg_match( '/^[-*]\s+(.*)$/', $text, $m ) ) {
				if ( ! $in_list ) {
					$html   .= '<ul>';
					$in_list = true;
				}
				$html .= '<li>' . $m[1] . '</li>';
				continue;
			}
			if ( $in_list ) {
				$html   .= '</ul>';
				$in_list = false;
			}
			if ( preg_match( '/^#{1,6}\s+(.*)$/', $text, $m ) ) {
				$html .= '<h4>' . $m[1] . '</h4>';
			} elseif ( '' !== $text ) {
				$html .= '<p>' . $text . '</p>';
			}
		}
		return $in_list ? $html . '</ul>' : $html;
	}

	/**
	 * Ser till att den uppackade mappen får samma namn som den installerade,
	 * även om tillägget ligger i en mapp med ett annat namn.
	 */
	public static function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . dirname( self::basename() ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return $source;
	}

	public static function clear_cache() {
		delete_transient( self::TRANSIENT );
	}
}
