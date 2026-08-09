<?php

/**
 * Aggiornamento del plugin tramite le GitHub Releases del repository
 * ufficiale, integrato nel meccanismo nativo di WordPress (stessa
 * interfaccia "Aggiornamento disponibile" nella pagina Plugin, stesso
 * bottone "Aggiorna ora", compatibile con gli auto-update automatici se
 * l'utente li attiva per questo plugin).
 *
 * Adattato da PeopleInside/wp-moderneditor (class-mce-updater.php).
 *
 * Note di sicurezza:
 * - Tutte le richieste avvengono solo in HTTPS, verso api.github.com.
 * - L'URL del pacchetto proviene SEMPRE dalla risposta della API di GitHub
 *   per quella release (asset ufficiale o, in assenza, lo zipball generato
 *   da GitHub per il tag): non viene mai costruito a mano un URL con la
 *   versione.
 * - Il confronto versioni usa sempre version_compare(), mai confronto stringa.
 * - Download/installazione delegati interamente a Plugin_Upgrader / WP_Filesystem.
 * - L'host del pacchetto viene ri-validato subito prima del download reale.
 *
 * @package VideoScannerFix
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Video_Scanner_Fix_Updater {

    private static ?Video_Scanner_Fix_Updater $instance = null;

    /** Repository GitHub ufficiale, formato "owner/repo". */
    const GITHUB_REPO = 'PeopleInside/video-scanner-fix';

    const GITHUB_API_URL = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

    /**
     * Slug/cartella richiesta in wp-content/plugins. Deve combaciare col
     * nome della cartella del repository (video-scanner-fix), usato anche
     * per rinominare lo zipball generato da GitHub se necessario.
     */
    const PLUGIN_SLUG = 'video-scanner-fix';

    /** Cache transient per non interrogare GitHub ad ogni caricamento admin. */
    const CACHE_KEY = 'vsf_github_latest_release';
    const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    public static function instance(): Video_Scanner_Fix_Updater {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update_info' ) );
        add_filter( 'plugins_api', array( $this, 'inject_plugin_info' ), 20, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
        add_filter( 'upgrader_pre_download', array( $this, 'verify_package_host' ), 10, 4 );
        add_action( 'upgrader_process_complete', array( $this, 'clear_cache_after_update' ), 10, 2 );
    }

    private function plugin_basename(): string {
        return VSF_PLUGIN_BASENAME;
    }

    /**
     * Interroga la API GitHub per l'ultima release pubblicata, con cache
     * transient. Restituisce solo i campi necessari, già validati.
     *
     * @return array{version: string, package_url: string, html_url: string, body: string}|WP_Error
     */
    public function get_latest_release() {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $response = wp_remote_get(
            self::GITHUB_API_URL,
            array(
                'timeout' => 8,
                'headers' => array(
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'VideoScannerFix/' . VSF_VERSION . '; ' . home_url( '/' ),
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            if ( 404 === $code ) {
                return new WP_Error( 'vsf_github_no_release', __( 'Nessuna release pubblicata su GitHub.', 'video-scanner-fix' ) );
            }
            return new WP_Error(
                'vsf_github_http_error',
                sprintf(
                    /* translators: %d: codice di stato HTTP */
                    __( 'GitHub ha risposto con codice %d.', 'video-scanner-fix' ),
                    $code
                )
            );
        }

        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
            return new WP_Error( 'vsf_github_bad_response', __( 'Risposta di GitHub non valida.', 'video-scanner-fix' ) );
        }

        // Il tag può essere prefissato da "v" (es. "v1.0.1"): normalizziamo.
        $version = preg_replace( '/^v/i', '', (string) $body['tag_name'] );
        if ( ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
            return new WP_Error( 'vsf_github_invalid_version', __( 'Numero di versione della release non valido.', 'video-scanner-fix' ) );
        }

        $package_url = $this->resolve_package_url( $body );
        if ( is_wp_error( $package_url ) ) {
            return $package_url;
        }

        $result = array(
            'version'     => $version,
            'package_url' => $package_url,
            'html_url'    => isset( $body['html_url'] ) ? esc_url_raw( (string) $body['html_url'] ) : 'https://github.com/' . self::GITHUB_REPO . '/releases',
            'body'        => isset( $body['body'] ) ? (string) $body['body'] : '',
        );

        set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );

        return $result;
    }

    /**
     * Determina l'URL del pacchetto: preferisce un asset .zip ufficiale
     * allegato alla release; in assenza, usa lo zipball del sorgente del tag
     * (generato automaticamente da GitHub). L'URL proviene sempre dalla
     * risposta API, mai costruito a mano, e viene validato contro un host
     * GitHub legittimo.
     *
     * @param array $release Corpo JSON di "releases/latest".
     * @return string|WP_Error
     */
    private function resolve_package_url( array $release ) {
        $assets = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : array();

        foreach ( $assets as $asset ) {
            if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) || empty( $asset['name'] ) ) {
                continue;
            }
            $asset_name = strtolower( (string) $asset['name'] );
            if ( '.zip' !== substr( $asset_name, -4 ) ) {
                continue;
            }
            $url = (string) $asset['browser_download_url'];
            if ( $this->is_trusted_github_url( $url ) ) {
                return esc_url_raw( $url );
            }
        }

        if ( ! empty( $release['zipball_url'] ) && $this->is_trusted_github_url( (string) $release['zipball_url'] ) ) {
            return esc_url_raw( (string) $release['zipball_url'] );
        }

        return new WP_Error( 'vsf_github_no_package', __( 'Nessun pacchetto scaricabile trovato per questa release.', 'video-scanner-fix' ) );
    }

    /**
     * Verifica che un URL fornito da GitHub punti a un host GitHub
     * legittimo, in HTTPS.
     */
    private function is_trusted_github_url( string $url ): bool {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }
        if ( 'https' !== $parts['scheme'] ) {
            return false;
        }
        $host = strtolower( $parts['host'] );
        return in_array( $host, array( 'github.com', 'api.github.com', 'codeload.github.com', 'objects.githubusercontent.com' ), true );
    }

    /**
     * Inserisce le informazioni di aggiornamento nel transient
     * "update_plugins" usato dalla pagina Plugin, dagli auto-update e da WP-CLI.
     *
     * @param object|false $transient
     * @return object|false
     */
    public function inject_update_info( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if ( is_wp_error( $release ) ) {
            return $transient;
        }

        $basename = $this->plugin_basename();

        if ( ! version_compare( $release['version'], VSF_VERSION, '>' ) ) {
            if ( isset( $transient->response[ $basename ] ) ) {
                unset( $transient->response[ $basename ] );
            }
            return $transient;
        }

        $item = new stdClass();
        $item->id          = 'github.com/' . self::GITHUB_REPO;
        $item->slug        = self::PLUGIN_SLUG;
        $item->plugin      = $basename;
        $item->new_version = $release['version'];
        $item->url         = $release['html_url'];
        $item->package     = $release['package_url'];
        $item->tested      = '';
        $item->icons       = array();
        $item->banners     = array();

        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = array();
        }
        $transient->response[ $basename ] = $item;

        return $transient;
    }

    /**
     * Dettagli mostrati nel popup "Visualizza dettagli versione X".
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object|array
     */
    public function inject_plugin_info( $result, string $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
            return $result;
        }

        $release = $this->get_latest_release();
        if ( is_wp_error( $release ) ) {
            return $result;
        }

        $info                 = new stdClass();
        $info->name           = 'Video Scanner Fix';
        $info->slug           = self::PLUGIN_SLUG;
        $info->version        = $release['version'];
        $info->author         = '<a href="https://github.com/PeopleInside">PeopleInside</a>';
        $info->homepage       = $release['html_url'];
        $info->download_link  = $release['package_url'];
        $info->sections       = array(
            // Testo della release scritto dal maintainer su GitHub: comunque
            // contenuto remoto, quindi passato a wp_kses_post prima dell'output.
            'description' => wp_kses_post( wpautop( $release['body'] ) ),
        );

        return $info;
    }

    /**
     * Difesa in profondità: ri-verifica l'host del pacchetto subito prima
     * del download reale, indipendentemente dalla validazione già fatta
     * in resolve_package_url().
     *
     * @param false|WP_Error $reply
     * @param string          $package
     * @param object          $upgrader
     * @param array           $hook_extra
     * @return false|WP_Error
     */
    public function verify_package_host( $reply, $package, $upgrader, $hook_extra = array() ) {
        if ( is_wp_error( $reply ) ) {
            return $reply;
        }

        $is_ours = $upgrader instanceof Plugin_Upgrader
            && ! empty( $upgrader->skin->plugin )
            && $this->plugin_basename() === $upgrader->skin->plugin;

        if ( ! $is_ours ) {
            return $reply;
        }

        if ( ! $this->is_trusted_github_url( (string) $package ) ) {
            return new WP_Error(
                'vsf_untrusted_package_host',
                __( 'Il pacchetto di aggiornamento non proviene da un host GitHub attendibile: download bloccato.', 'video-scanner-fix' )
            );
        }

        return $reply;
    }

    /**
     * Se il pacchetto installato è lo zipball del sorgente (fallback senza
     * asset .zip dedicato), GitHub lo confeziona con una cartella radice nel
     * formato "video-scanner-fix-<hash o tag>", diversa dallo slug atteso.
     * Rinominiamo esplicitamente per evitare ambiguità.
     *
     * @param string|WP_Error $source
     * @param string          $remote_source
     * @param WP_Upgrader     $upgrader
     * @param array           $hook_extra
     * @return string|WP_Error
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
        if ( is_wp_error( $source ) ) {
            return $source;
        }

        $plugin = isset( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : '';
        if ( $this->plugin_basename() !== $plugin ) {
            return $source;
        }

        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            return $source;
        }

        $source_dirname = basename( untrailingslashit( $source ) );
        if ( self::PLUGIN_SLUG === $source_dirname ) {
            return $source; // Già nel nome corretto (caso dell'asset .zip ufficiale).
        }

        $desired_source = trailingslashit( dirname( untrailingslashit( $source ) ) ) . self::PLUGIN_SLUG . '/';

        if ( $wp_filesystem->exists( $desired_source ) ) {
            $wp_filesystem->delete( $desired_source, true );
        }

        $moved = $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired_source ) );
        if ( ! $moved ) {
            return new WP_Error( 'vsf_rename_failed', __( 'Impossibile rinominare la cartella del pacchetto scaricato.', 'video-scanner-fix' ) );
        }

        return $desired_source;
    }

    /**
     * Svuota la cache release subito dopo un aggiornamento riuscito di
     * questo plugin.
     *
     * @param WP_Upgrader $upgrader
     * @param array       $hook_extra
     */
    public function clear_cache_after_update( $upgrader, $hook_extra ): void {
        if ( empty( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] ) {
            return;
        }
        if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
            return;
        }

        $plugins = array();
        if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
            $plugins = $hook_extra['plugins'];
        } elseif ( ! empty( $hook_extra['plugin'] ) ) {
            $plugins = array( $hook_extra['plugin'] );
        }

        if ( in_array( $this->plugin_basename(), $plugins, true ) ) {
            delete_transient( self::CACHE_KEY );
        }
    }
}
