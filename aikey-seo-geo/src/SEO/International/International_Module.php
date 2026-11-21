<?php
namespace AIKEY\SeoGeo\SEO\International;

use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\Core\Conflict_Detector;
use AIKEY\SeoGeo\GEO\Language;
use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;

defined( 'ABSPATH' ) || exit;

/** Explicit multilingual relationships; never guess translation URLs or fetch other sites on page load. */
final class International_Module {
    public const PAGE_LANGUAGE = '_aikey_page_language';
    public const ALTERNATES = '_aikey_hreflang_alternates';

    public function register(): void {
        add_action( 'wp_head', array( $this, 'output_hreflang' ), 2 );
        add_filter( 'language_attributes', array( $this, 'language_attributes' ), 20, 2 );
        if ( ! is_admin() ) { return; }
        add_action( 'admin_menu', array( $this, 'menu' ), 30 );
        add_action( 'admin_post_aikey_international_save', array( $this, 'save_settings' ) );
        add_action( 'add_meta_boxes', array( $this, 'meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_post' ), 200, 3 );
    }

    public static function enabled(): bool {
        $cfg = Settings::section( 'international' );
        return ! empty( $cfg['enabled'] ) || ! empty( $cfg['id_links_enabled'] );
    }

    public function menu(): void {
        add_submenu_page( 'aikey-seo-geo', '全球多语言 SEO', '全球多语言 SEO', 'manage_options',
            'aikey-seo-geo-international', array( $this, 'settings_page' ) );
    }

    public function settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', '', array( 'response'=>403 ) ); }
        $options = Settings::section( 'international' );
        ?>
        <div class="wrap">
          <h1>全球多语言 SEO</h1>
          <p>各 WordPress 站点独立设置，支持独立域名、子域名与子目录。不会翻译、复制、覆盖其他语言站的文章。</p>
          <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p>已保存。</p></div><?php endif; ?>
          <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
            <input type="hidden" name="action" value="aikey_international_save">
            <?php wp_nonce_field( 'aikey_international_save' ); ?>
            <table class="form-table">
              <tr><th>跨语言对应关系</th><td><label>
                <input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $options['enabled'] ) ); ?>>启用明确的 hreflang 对应关系
              </label><p class="description">新模式默认关闭，避免升级时更改中文站现有输出。</p></td></tr>
              <tr><th><label for="aikey-site-locale">本站公开语言</label></th><td>
                <input class="regular-text" id="aikey-site-locale" name="site_locale" placeholder="en / zh-CN / fr / ar" value="<?php echo esc_attr( (string)( $options['site_locale'] ?? '' ) ); ?>">
                <p class="description">留空则沿用 WordPress 站点语言；WordPress 后台中文、公开站英文时填写 en。</p>
              </td></tr>
            </table>
            <?php submit_button( '保存配置' ); ?>
          </form>
          <h2>配置对应页面</h2>
          <p>在 WordPress 页面/文章编辑器找到“Aikeyboost · 多语言 SEO”框，按每行“语言代码|完整 URL”填写各真实语言版本。例如：</p>
          <pre>zh-CN|https://example.com/about/
fr|https://fr.example.com/a-propos/
ar|https://ar.example.com/about/
x-default|https://example.com/</pre>
          <p>当前页面自身语言与网址自动输出。其他语言站仍需反向登记；插件不在前台探测外部网站，也不保证对方 URL 已被索引。</p>
          <p>每个站点 Sitemap 继续保持本站 URL-only，Canonical、robots 与原有 SEO 字段不变。</p>
          <?php ( new Link_Verifier() )->render(); ?>
        </div>
        <?php
    }

    public function save_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', '', array( 'response'=>403 ) ); }
        check_admin_referer( 'aikey_international_save' );
        if ( isset( $_POST['site_locale'] ) && ! is_scalar( $_POST['site_locale'] ) ) { wp_die( 'Invalid language', '', array( 'response'=>400 ) ); }
        $raw = sanitize_text_field( wp_unslash( (string) ( $_POST['site_locale'] ?? '' ) ) );
        $locale = Language::normalize( $raw );
        if ( '' !== trim( $raw ) && '' === $locale ) { wp_die( 'Invalid language', '', array( 'response'=>400 ) ); }
        $settings = Settings::all();
        $settings['international']['enabled'] = ! empty( $_POST['enabled'] );
        $settings['international']['site_locale'] = $locale;
        if ( ! Settings::update( $settings ) ) { wp_die( 'Database save failed', '', array( 'response'=>500 ) ); }
        wp_safe_redirect( admin_url( 'admin.php?page=aikey-seo-geo-international&updated=1' ) );
        exit;
    }

    public function meta_boxes(): void {
        foreach ( get_post_types( array( 'public'=>true ), 'names' ) as $type ) {
            if ( 'attachment' !== $type ) {
                add_meta_box( 'aikey-international', 'Aikeyboost · 多语言 SEO', array( $this, 'meta_box' ), $type );
            }
        }
    }

    public function meta_box( \WP_Post $post ): void {
        $lang = (string) get_post_meta( $post->ID, self::PAGE_LANGUAGE, true );
        $pairs = get_post_meta( $post->ID, self::ALTERNATES, true );
        $lines = array();
        foreach ( is_array( $pairs ) ? $pairs : array() as $k=>$v ) { $lines[] = $k . '|' . $v; }
        wp_nonce_field( 'aikey_international_post', 'aikey_international_nonce' );
        echo '<p><label>本页语言（留空使用本站语言）</label></p>';
        echo '<input class="widefat" name="aikey_page_locale" value="' . esc_attr( $lang ) . '" placeholder="en / fr / ar">';
        echo '<p><label>对应页面完整 URL，每行语言代码|URL；可选 x-default</label></p>';
        echo '<textarea class="widefat" name="aikey_alternates" rows="5">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea>';
        echo '<p class="description">只配置真实对应页面；各语言站需设置双向对应关系，不自动根据 Slug 猜测。</p>';
    }

    public function save_post( int $id, \WP_Post $post, bool $update ): void {
        if ( ! isset( $_POST['aikey_international_nonce'], $_POST['aikey_page_locale'], $_POST['aikey_alternates'] )
            || ! is_scalar( $_POST['aikey_international_nonce'] )
            || ! is_scalar( $_POST['aikey_page_locale'] )
            || ! is_scalar( $_POST['aikey_alternates'] ) ) { return; }
        $nonce = sanitize_text_field( wp_unslash( (string) $_POST['aikey_international_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce, 'aikey_international_post' ) ||
            ! current_user_can( 'edit_post', $id ) || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) ) { return; }
        $type = get_post_type_object( $post->post_type );
        if ( ! $type || empty( $type->public ) ) { return; }
        $raw = sanitize_text_field( wp_unslash( (string) $_POST['aikey_page_locale'] ) );
        $lang = Language::normalize( $raw );
        if ( '' !== trim( $raw ) && '' === $lang ) { return; }
        $raw_pairs = wp_unslash( (string) $_POST['aikey_alternates'] );
        if ( strlen( $raw_pairs ) > 45000 ) { wp_die( '多语言 URL 过长。', '', array( 'response'=>400 ) ); }
        $pairs = self::parse_alternates( $raw_pairs );
        $submitted_lines = array_filter( array_map( 'trim', preg_split( '/\R/u', $raw_pairs ) ?: array() ), 'strlen' );
        if ( count( $pairs ) !== count( $submitted_lines ) ) {
            wp_die( '存在重复、不合法或超过上限的语言 URL，未保存。', '', array( 'response'=>400 ) );
        }
        $old_lang = get_post_meta( $id, self::PAGE_LANGUAGE, true );
        $old_pairs = get_post_meta( $id, self::ALTERNATES, true );
        if ( ! self::persist_meta( $id, self::PAGE_LANGUAGE, $lang )
            || ! self::persist_meta( $id, self::ALTERNATES, $pairs ) ) {
            self::persist_meta( $id, self::PAGE_LANGUAGE, $old_lang );
            self::persist_meta( $id, self::ALTERNATES, $old_pairs );
            wp_die( '多语言 SEO 保存失败：数据库未确认写入。', '', array( 'response'=>500 ) );
        }
        clean_post_cache( $id );
    }

    private static function persist_meta( int $id, string $key, mixed $value ): bool {
        if ( '' === $value || array() === $value ) {
            delete_post_meta( $id, $key );
            return '' === get_post_meta( $id, $key, true );
        }
        update_post_meta( $id, $key, $value );
        return get_post_meta( $id, $key, true ) === $value;
    }

    public static function parse_alternates( string $raw ): array {
        $out = array();
        $seen_urls = array();
        foreach ( array_slice( preg_split( '/\R/u', $raw ) ?: array(), 0, 60 ) as $line ) {
            if ( ! str_contains( $line, '|' ) ) { continue; }
            [ $lang, $url ] = array_map( 'trim', explode( '|', $line, 2 ) );
            $lang = Language::normalize( $lang, true );
            $url = self::safe_url( $url );
            $fingerprint = self::url_identity( $url );
            if ( '' !== $lang && '' !== $url && ! isset( $out[ $lang ] )
                && ( 'x-default' === $lang || ! isset( $seen_urls[ $fingerprint ] ) ) ) {
                $out[ $lang ] = $url;
                if ( 'x-default' !== $lang ) { $seen_urls[ $fingerprint ] = true; }
            }
            if ( count( $out ) >= 30 ) { break; }
        }
        return $out;
    }

    /** Preserve query-based language routes, ports and path case when comparing URLs. */
    public static function url_identity( string $url ): string {
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $port = wp_parse_url( $url, PHP_URL_PORT );
        $authority = $host . ( null !== $port && ! ( 'https' === $scheme && 443 === $port )
            && ! ( 'http' === $scheme && 80 === $port ) ? ':' . $port : '' );
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $query = wp_parse_url( $url, PHP_URL_QUERY );
        return $scheme . '://' . $authority . ( '' === $path ? '/' : $path )
            . ( null !== $query ? '?' . $query : '' );
    }

    private static function safe_url( string $raw ): string {
        if ( strlen( $raw ) > 2048 ) { return ''; }
        $url = esc_url_raw( trim( $raw ), array( 'http','https' ) );
        if ( '' === $url || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http','https' ), true ) ) { return ''; }
        if ( ! wp_parse_url( $url, PHP_URL_HOST ) || wp_parse_url( $url, PHP_URL_USER ) || wp_parse_url( $url, PHP_URL_PASS ) ) { return ''; }
        if ( null !== wp_parse_url( $url, PHP_URL_FRAGMENT ) ) { return ''; }
        return $url;
    }

    public static function alternate_links( \WP_Post $post ): array {
        if ( ! self::enabled() || 'publish' !== $post->post_status || '' !== $post->post_password
            || ! (bool) get_option( 'blog_public', true ) ) { return array(); }
        $type = get_post_type_object( $post->post_type );
        if ( ! $type || empty( $type->public ) || 'noindex' === Meta_Repository::robots_index( $post->ID ) ) { return array(); }
        $self = (string) get_permalink( $post );
        if ( '' === $self || self::url_identity( $self ) !== self::url_identity( Meta_Repository::resolved_canonical_url( $post ) ) ) { return array(); }
        // New ID-based groups supersede manual URL guesses for linked content.
        // Legacy URL mappings remain active for unlinked pages and remote sites.
        if ( class_exists( Translation_Groups::class ) && Translation_Groups::output_enabled()
            && Translation_Groups::members( (int) $post->ID ) ) {
            return Translation_Groups::alternate_links( $post );
        }
        $pairs = get_post_meta( $post->ID, self::ALTERNATES, true );
        if ( ! is_array( $pairs ) ) { return array(); }
        $lang = Language::code( $post );
        $out = array( $lang => $self );
        $others = 0;
        foreach ( $pairs as $k => $v ) {
            if ( ! is_string( $k ) || ! is_string( $v ) ) { continue; }
            $k = Language::normalize( $k, true ); $v = self::safe_url( $v );
            if ( '' === $k || '' === $v || $k === $lang || ( 'x-default' !== $k && self::url_identity( $v ) === self::url_identity( $self ) ) ) { continue; }
            $out[ $k ] = $v;
            if ( 'x-default' !== $k ) { ++$others; }
        }
        return $others ? $out : array();
    }

    /**
     * A configured posts index is not singular, but page_for_posts is still a
     * real WordPress page ID and can be explicitly associated with translations.
     * Do not alter legacy archive behavior before the ID-output opt-in.
     */
    private static function queried_content(): ?\WP_Post {
        if ( is_singular() ) {
            $post = get_queried_object();
            return $post instanceof \WP_Post ? $post : null;
        }
        if ( Translation_Groups::output_enabled() && is_home() && ! is_front_page() ) {
            $post_id = (int) get_option( 'page_for_posts' );
            $post = $post_id > 0 ? get_post( $post_id ) : null;
            return $post instanceof \WP_Post && 'page' === $post->post_type ? $post : null;
        }
        return null;
    }

    /** Keep public <html lang> and text direction aligned with the SEO language. */
    public function language_attributes( string $output, string $doctype = 'html' ): string {
        if ( is_admin() ) { return $output; }
        $options = Settings::section( 'international' );
        $post = self::queried_content();
        $page_locale = $post ? (string) get_post_meta( $post->ID, self::PAGE_LANGUAGE, true ) : '';
        if ( empty( $options['enabled'] ) && empty( $options['site_locale'] )
            && empty( $options['id_links_enabled'] ) && '' === $page_locale ) { return $output; }

        $lang = Language::code( $post );
        if ( '' === $lang ) { return $output; }
        $lang_attr = 'lang="' . esc_attr( $lang ) . '"';
        $output = preg_match( '/(?<![\\w:-])lang=(["\x27])[^"\x27]*\1/i', $output )
            ? (string) preg_replace( '/(?<![\\w:-])lang=(["\x27])[^"\x27]*\1/i', $lang_attr, $output, 1 )
            : trim( $output . ' ' . $lang_attr );

        if ( 'xhtml' === $doctype || preg_match( '/(?<![\\w:-])xml:lang=/i', $output ) ) {
            $xml_attr = 'xml:lang="' . esc_attr( $lang ) . '"';
            $output = preg_match( '/(?<![\\w:-])xml:lang=(["\\x27])[^"\\x27]*\\1/i', $output )
                ? (string) preg_replace( '/(?<![\\w:-])xml:lang=(["\\x27])[^"\\x27]*\\1/i', $xml_attr, $output, 1 )
                : trim( $output . ' ' . $xml_attr );
        }

        $dir = preg_match( '/^(?:ar|he|fa|ur)(?:-|$)/i', $lang ) ? 'rtl' : 'ltr';
        $dir_attr = 'dir="' . $dir . '"';
        return preg_match( '/(?<![\w:-])dir=(["\x27])[^"\x27]*\1/i', $output )
            ? (string) preg_replace( '/(?<![\w:-])dir=(["\x27])[^"\x27]*\1/i', $dir_attr, $output, 1 )
            : trim( $output . ' ' . $dir_attr );
    }

    public function output_hreflang(): void {
        if ( ! self::enabled() || is_admin() || ! Conflict_Detector::frontend_allowed() ) { return; }
        $post = self::queried_content();
        if ( ! $post ) { return; }
        foreach ( self::alternate_links( $post ) as $lang=>$url ) {
            echo '<link rel="alternate" hreflang="' . esc_attr( $lang ) . '" href="' . esc_url( $url ) . '">' . "\n";
        }
    }
}
