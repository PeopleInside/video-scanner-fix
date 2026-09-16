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
 * IMPORTANTE: questa classe deve essere istanziata anche fuori da wp-admin
 * (wp-cron e WP-CLI), altrimenti gli aggiornamenti automatici non partono.
 * Vedi il commento esteso in video-scanner-fix.php.
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
     * Slug canonico del plugin, usato per plugins_api. La cartella REALE in
     * wp-content/plugins può essere diversa (es. "video-scanner-fix-main"
     * per chi ha installato lo zip di GitHub a mano): per rinominare il
     * pacchetto scaricato si usa sempre target_dir_name(), non questa costante.
     */
    const PLUGIN_SLUG = 'video-scanner-fix';

    /** Cache transient per non interrogare GitHub ad ogni caricamento admin. */
    const CACHE_KEY = 'vsf_github_latest_release';
    const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /**
     * TTL breve per ricordare un controllo FALLITO (rete giù, rate limit di
     * GitHub, risposta non valida). Senza cache negativa ogni caricamento di
     * wp-admin rifarebbe la chiamata HTTP, rallentando la bacheca e bruciando
     * il limite di 60 richieste/ora per IP delle API GitHub non autenticate.
     */
    const CACHE_FAIL_TTL = 15 * MINUTE_IN_SECONDS;

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
     * Nome REALE della cartella in cui il plugin è installato: è quello che
     * conta quando si rinomina il pacchetto scaricato. Rinominare sempre in
     * PLUGIN_SLUG romperebbe le installazioni in cui la cartella si chiama
     * diversamente (WordPress installerebbe una cartella nuova lasciando il
     * plugin disattivato).
     */
    private function target_dir_name(): string {
        $dir = dirname( $this->plugin_basename() );
        if ( '.' === $dir || '' === $dir || '/' === $dir ) {
            $dir = self::PLUGIN_SLUG;
        }
        return $dir;
    }

    /**
     * Interroga la API GitHub per l'ultima release pubblicata, con cache
     * transient (positiva e negativa). Restituisce solo i campi necessari,
     * già validati.
     *
     * @return array{version: string, package_url: string, html_url: string, body: string}|WP_Error
     */
    public function get_latest_release() {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        if ( 'skip' === $cached ) {
            return new WP_Error( 'vsf_github_check_deferred', __( 'Controllo aggiornamenti rinviato dopo un errore recente.', 'video-scanner-fix' ) );
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
            return $this->remember_failure( $response );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            if ( 404 === $code ) {
                return $this->remember_failure( new WP_Error( 'vsf_github_no_release', __( 'Nessuna release pubblicata su GitHub.', 'video-scanner-fix' ) ) );
            }
            return $this->remember_failure(
                new WP_Error(
                    'vsf_github_http_error',
                    sprintf(
                        /* translators: %d: codice di stato HTTP */
                        __( 'GitHub ha risposto con codice %d.', 'video-scanner-fix' ),
                        $code
                    )
                )
            );
        }

        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
            return $this->remember_failure( new WP_Error( 'vsf_github_bad_response', __( 'Risposta di GitHub non valida.', 'video-scanner-fix' ) ) );
        }

        // Il tag può essere prefissato da "v" (es. "v1.0.1"): normalizziamo.
        $version = preg_replace( '/^v/i', '', (string) $body['tag_name'] );
        if ( ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
            return $this->remember_failure( new WP_Error( 'vsf_github_invalid_version', __( 'Numero di versione della release non valido.', 'video-scanner-fix' ) ) );
        }

        $package_url = $this->resolve_package_url( $body );
        if ( is_wp_error( $package_url ) ) {
            return $this->remember_failure( $package_url );
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
     * Memorizza per un breve periodo il fatto che il controllo è fallito e
     * restituisce l'errore invariato.
     *
     * @param WP_Error $error
     * @return WP_Error
     */
    private function remember_failure( WP_Error $error ): WP_Error {
        set_transient( self::CACHE_KEY, 'skip', self::CACHE_FAIL_TTL );
        return $error;
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
     * Quando non c'è un aggiornamento disponibile viene comunque popolata la
     * voce in $transient->no_update, usata da WordPress per la colonna
     * "Aggiornamenti automatici" e per i controlli interni.
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

        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = array();
        }
        if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
            $transient->no_update = array();
        }

        if ( ! version_compare( $release['version'], VSF_VERSION, '>' ) ) {
            unset( $transient->response[ $basename ] );
            $transient->no_update[ $basename ] = $this->build_item( VSF_VERSION, '', $release['html_url'] );
            return $transient;
        }

        unset( $transient->no_update[ $basename ] );
        $transient->response[ $basename ] = $this->build_item( $release['version'], $release['package_url'], $release['html_url'] );

        return $transient;
    }

    /**
     * Costruisce l'oggetto atteso da WordPress dentro il transient
     * "update_plugins" (sia per ->response che per ->no_update).
     *
     * Nota: "requires_php" resta volutamente vuoto: se dichiarasse una
     * versione superiore a quella del server, WordPress scarterebbe
     * l'aggiornamento automatico in silenzio.
     *
     * @param string $version
     * @param string $package_url URL del pacchetto (vuoto per no_update).
     * @param string $html_url
     */
    private function build_item( string $version, string $package_url, string $html_url ): stdClass {
        $item = new stdClass();
        $item->id           = 'github.com/' . self::GITHUB_REPO;
        $item->slug         = self::PLUGIN_SLUG;
        $item->plugin       = $this->plugin_basename();
        $item->new_version  = $version;
        $item->url          = $html_url;
        $item->package      = $package_url;
        $item->tested       = '';
        $item->requires_php = '';
        $item->icons        = array();
        $item->banners      = array();
        $item->banners_rtl  = array();
        return $item;
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
        if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
            return $result;
        }
        if ( self::PLUGIN_SLUG !== $args->slug && $this->target_dir_name() !== $args->slug ) {
            return $result;
        }

        $release = $this->get_latest_release();
        if ( is_wp_error( $release ) ) {
            return $result;
        }

        $info                = new stdClass();
        $info->name          = 'Video Scanner Fix';
        $info->slug          = self::PLUGIN_SLUG;
        $info->version       = $release['version'];
        $info->author        = '<a href="https://github.com/PeopleInside">PeopleInside</a>';
        $info->homepage      = $release['html_url'];
        $info->download_link = $release['package_url'];
        $info->sections      = array(
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
     * Il riconoscimento usa prima $hook_extra['plugin'] (valorizzato anche
     * durante gli update automatici) e solo come fallback
     * $upgrader->skin->plugin, che esiste solo con la skin della pagina
     * Plugin e non con Automatic_Upgrader_Skin.
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

        $basename = $this->plugin_basename();

        $is_ours = ( ! empty( $hook_extra['plugin'] ) && $basename === (string) $hook_extra['plugin'] );

        if ( ! $is_ours && $upgrader instanceof Plugin_Upgrader && ! empty( $upgrader->skin->plugin ) ) {
            $is_ours = ( $basename === $upgrader->skin->plugin );
        }

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
     * formato "video-scanner-fix-<hash o tag>". La rinominiamo usando il nome
     * della cartella REALE in cui il plugin è installato.
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

        $target_dir     = $this->target_dir_name();
        $source_dirname = basename( untrailingslashit( $source ) );
        if ( $target_dir === $source_dirname ) {
            return $source; // Già nel nome corretto (caso dell'asset .zip ufficiale).
        }

        $desired_source = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $target_dir . '/';

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
