<?php
namespace AIKEY\SeoGeo\Maps;

use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\Contacts\Contact_Repository;

defined( 'ABSPATH' ) || exit;

final class Enterprise_Map_Module implements Module_Interface {
    private const FORM_ACTION = 'aikey_enterprise_contact_submit';
    private const FORM_NONCE_ACTION = 'aikey_enterprise_contact';

    public function id(): string { return 'enterprise_map'; }

    public function register(): void {
        add_action( 'init', array( $this, 'register_block' ) );
        add_shortcode( 'aikey_enterprise_map', array( $this, 'shortcode' ) );
        add_shortcode( 'aikey_contact_form', array( $this, 'contact_shortcode' ) );
        add_action( 'admin_post_' . self::FORM_ACTION, array( $this, 'handle_contact_submission' ) );
        add_action( 'admin_post_nopriv_' . self::FORM_ACTION, array( $this, 'handle_contact_submission' ) );
    }

    public function register_block(): void {
        wp_register_style(
            'aikey-enterprise-map',
            AIKEY_SEO_GEO_URL . 'assets/css/enterprise-map.css',
            array(),
            AIKEY_SEO_GEO_VERSION
        );
        wp_register_script(
            'aikey-enterprise-map-block',
            AIKEY_SEO_GEO_URL . 'assets/js/enterprise-map-block.js',
            array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor' ),
            AIKEY_SEO_GEO_VERSION,
            true
        );
        wp_localize_script( 'aikey-enterprise-map-block', 'AIKEYEnterpriseMapBlock', $this->editor_config() );

        register_block_type(
            'aikey-seo-geo/enterprise-map',
            array(
                'api_version'     => 2,
                'editor_script'   => 'aikey-enterprise-map-block',
                'style'           => 'aikey-enterprise-map',
                'render_callback' => array( $this, 'render_block' ),
                'attributes'      => array(
                    'formId'         => array( 'type' => 'string' ),
                    'height'         => array( 'type' => 'number' ),
                    'showAddress'    => array( 'type' => 'boolean' ),
                    'showNavigation' => array( 'type' => 'boolean' ),
                ),
            )
        );
    }

    public function shortcode( array|string $atts = array() ): string {
        $atts = shortcode_atts(
            array(
                'form'            => '',
                'height'          => (string) Settings::section_get( 'map', 'height', 340 ),
                'show_address'    => Settings::section_get( 'map', 'show_address', true ) ? '1' : '0',
                'show_navigation' => Settings::section_get( 'map', 'show_navigation', true ) ? '1' : '0',
            ),
            is_array( $atts ) ? $atts : array(),
            'aikey_enterprise_map'
        );

        return $this->render_block(
            array(
                'formId'         => sanitize_key( (string) $atts['form'] ),
                'height'         => absint( $atts['height'] ),
                'showAddress'    => '0' !== (string) $atts['show_address'],
                'showNavigation' => '0' !== (string) $atts['show_navigation'],
            )
        );
    }

    /** Contact form without the map, using the same profile and submission endpoint. */
    public function contact_shortcode( array|string $atts = array() ): string {
        $atts = shortcode_atts( array( 'form'=>'' ), is_array( $atts ) ? $atts : array(), 'aikey_contact_form' );
        $id = sanitize_key( (string) $atts['form'] );
        $profile = Form_Profiles::get( $id );
        if ( null === $profile ) { return ''; }
        if ( ! wp_style_is( 'aikey-enterprise-map', 'registered' ) ) {
            wp_register_style( 'aikey-enterprise-map', AIKEY_SEO_GEO_URL . 'assets/css/enterprise-map.css', array(), AIKEY_SEO_GEO_VERSION );
        }
        wp_enqueue_style( 'aikey-enterprise-map' );
        $anchor = '' === $id ? 'aikey-contact-form' : 'aikey-contact-form-' . $id;
        return '<div id="' . esc_attr( $anchor ) . '">' . $this->render_contact_form( $id, $profile ) . '</div>';
    }

    public function render_block( array $attributes = array(), string $content = '', mixed $block = null ): string {
        $map     = Settings::section( 'map' );
        $schema  = Settings::section( 'schema' );
        $form_id = sanitize_key( (string) ( $attributes['formId'] ?? '' ) );
        $profile = Form_Profiles::get( $form_id );
        if ( null === $profile ) { return ''; }
        $lang = (string) ( $profile['language'] ?? 'zh' );
        $map_labels = Form_Profiles::map_labels( $lang );
        $company = trim( (string) ( $schema['organization_name'] ?? '' ) );
        $company = '' !== $company ? $company : get_bloginfo( 'name' );
        if ( ! empty( $profile['company'] ) ) { $company = (string) $profile['company']; }
        $address = ! empty( $profile['address'] ) ? (string) $profile['address'] : self::display_address( $map, $schema );
        $height  = min( 720, max( 220, absint( $attributes['height'] ?? $map['height'] ?? 340 ) ) );
        $show_address = isset( $attributes['showAddress'] ) ? (bool) $attributes['showAddress'] : ! empty( $map['show_address'] );
        $show_navigation = isset( $attributes['showNavigation'] ) ? (bool) $attributes['showNavigation'] : ! empty( $map['show_navigation'] );

        if ( ! wp_style_is( 'aikey-enterprise-map', 'registered' ) ) {
            wp_register_style( 'aikey-enterprise-map', AIKEY_SEO_GEO_URL . 'assets/css/enterprise-map.css', array(), AIKEY_SEO_GEO_VERSION );
        }
        wp_enqueue_style( 'aikey-enterprise-map' );

        $anchor = '' === $form_id ? 'aikey-contact-form' : 'aikey-contact-form-' . $form_id;
        $html  = '<section class="aikey-enterprise-contact" id="' . esc_attr( $anchor ) . '" lang="' . esc_attr( $lang ) . '"' . ( 'ar' === $lang ? ' dir="rtl"' : '' ) . '>';
        $html .= '<div class="aikey-enterprise-contact__grid">';
        $html .= '<div class="aikey-enterprise-map aikey-enterprise-map--light">';
        $html .= '<div class="aikey-enterprise-map__light" style="min-height:' . esc_attr( (string) $height ) . 'px">';
        $html .= '<div class="aikey-enterprise-map__light-grid" aria-hidden="true"></div>';
        $html .= '<div class="aikey-enterprise-map__light-content">';
        $html .= '<span class="aikey-enterprise-map__pin" aria-hidden="true"><span></span></span>';
        $html .= '<span class="aikey-enterprise-map__eyebrow">' . esc_html( $map_labels['location'] ) . '</span>';
        $html .= '<strong class="aikey-enterprise-map__light-name">' . esc_html( $company ) . '</strong>';
        if ( '' !== $address ) {
            $html .= '<span class="aikey-enterprise-map__light-address">' . esc_html( $address ) . '</span>';
        } else {
            $html .= '<small class="aikey-enterprise-map__coordinate">' . esc_html( $map_labels['missing'] ) . '</small>';
        }
        $html .= '</div></div>';

        if ( $show_address || $show_navigation ) {
            $html .= '<div class="aikey-enterprise-map__meta">';
            if ( $show_address && '' !== $address ) {
                $html .= '<div class="aikey-enterprise-map__address"><strong>' . esc_html( $company ) . '</strong><span>' . esc_html( $address ) . '</span></div>';
            }
            if ( $show_navigation ) {
                $buttons = $this->map_buttons( $company, $address, $lang );
                if ( '' !== $buttons ) {
                    $html .= '<div class="aikey-enterprise-map__nav">' . $buttons . '</div>';
                }
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= $this->render_contact_form( $form_id, $profile );
        $html .= '</div></section>';

        return $html;
    }

    public function handle_contact_submission(): void {
        if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
            $this->redirect_form_status( 'error' );
        }

        $nonce = sanitize_text_field( self::raw_string( $_POST, 'aikey_contact_nonce' ) );
        if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::FORM_NONCE_ACTION ) ) {
            $this->redirect_form_status( 'security' );
        }

        $payload = self::validate_contact_payload( $_POST );
        if ( ! $payload['valid'] ) {
            $this->redirect_form_status( (string) $payload['status'] );
        }

        if ( self::submission_rate_limited() ) {
            $this->redirect_form_status( 'rate' );
        }
        $fields = $payload['fields'];
        $form_id = sanitize_key( self::raw_string( $_POST, 'aikey_form_id' ) );
        if ( null === Form_Profiles::get( $form_id ) ) {
            $this->redirect_form_status( 'error' );
        }
        $source_url = esc_url_raw( wp_get_referer() ?: home_url( '/' ) );
        $message_id = Contact_Repository::add( $fields, $source_url, $form_id );
        if ( $message_id < 1 ) {
            $this->redirect_form_status( 'storage' );
        }

        $this->redirect_form_status( 'success' );
    }

    public static function map_view_url( string $provider, string $company, string $address = '' ): string {
        $keyword = trim( $company . ' ' . $address );
        if ( '' === $keyword ) { return ''; }

        if ( 'google' === $provider ) {
            return add_query_arg(
                array( 'api'=>'1', 'query'=>'' !== trim( $address ) ? trim( $address ) : $company ),
                'https://www.google.com/maps/search/'
            );
        }

        if ( 'baidu' === $provider ) {
            return add_query_arg(
                array(
                    'query'  => $keyword,
                    'region' => '全国',
                    'output' => 'html',
                    'src'    => 'webapp.aikey.seogeo',
                ),
                'https://api.map.baidu.com/place/search'
            );
        }

        return add_query_arg(
            array(
                'keyword'    => $keyword,
                'view'       => 'map',
                'src'        => 'aikey-seo-geo',
                'callnative' => '1',
            ),
            'https://uri.amap.com/search'
        );
    }

    private function render_contact_form( string $form_id = '', array $profile = array() ): string {
        $language = (string) ( $profile['language'] ?? 'zh' );
        $labels = Form_Profiles::messages( $language );
        $heading = trim( (string) ( $profile['heading'] ?? '' ) );
        $submit = trim( (string) ( $profile['submit'] ?? '' ) );
        if ( '' !== $heading ) { $labels['heading'] = $heading; }
        if ( '' !== $submit ) { $labels['submit'] = $submit; }

        $selected_form = sanitize_key( self::raw_string( $_GET, 'aikey_contact_form' ) );
        $status = $selected_form === $form_id ? sanitize_key( self::raw_string( $_GET, 'aikey_contact_status' ) ) : '';
        $notice = '';
        if ( 'success' === $status ) { $notice = $labels['success']; }
        elseif ( in_array( $status, array( 'required', 'email', 'phone', 'spam' ), true ) ) { $notice = $labels['invalid']; }
        elseif ( 'required_email' === $status ) { $notice = $labels['email_required']; }
        elseif ( 'required_message' === $status ) { $notice = $labels['message_required']; }
        elseif ( 'rate' === $status ) { $notice = $labels['rate']; }
        elseif ( 'storage' === $status ) { $notice = $labels['storage']; }
        elseif ( in_array( $status, array( 'security', 'error' ), true ) ) { $notice = $labels['security']; }

        $id_prefix = wp_unique_id( 'aikey-contact-' );
        $html = '<div class="aikey-contact-form" lang="' . esc_attr( $language ) . '"' . ( 'ar' === $language ? ' dir="rtl"' : '' ) . '>';
        $html .= '<h2 class="aikey-contact-form__title">' . esc_html( $labels['heading'] ) . '</h2>';
        if ( '' !== $notice ) {
            $kind = 'success' === $status ? 'success' : 'error';
            $html .= '<div class="aikey-contact-form__notice aikey-contact-form__notice--' . $kind . '" role="' . ( 'success' === $status ? 'status' : 'alert' ) . '">' . esc_html( $notice ) . '</div>';
        }
        $html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aikey-contact-form__form">';
        $html .= '<input type="hidden" name="action" value="' . esc_attr( self::FORM_ACTION ) . '">';
        $html .= '<input type="hidden" name="aikey_form_id" value="' . esc_attr( $form_id ) . '">';
        $html .= wp_nonce_field( self::FORM_NONCE_ACTION, 'aikey_contact_nonce', true, false );
        $html .= '<div class="aikey-contact-form__hp" aria-hidden="true"><label>Website<input type="text" name="aikey_website" tabindex="-1" autocomplete="off"></label></div>';
        $html .= '<div class="aikey-contact-form__top-row">';
        $html .= $this->text_field( 'aikey_name', $labels['name'], $labels['name_help'], 'text', false, 'name', 80, $id_prefix );
        $html .= $this->text_field( 'aikey_phone', $labels['phone'], $labels['phone_help'], 'tel', true, 'tel', 40, $id_prefix );
        $html .= $this->text_field( 'aikey_email', $labels['email'], $labels['email_help'], 'email', true, 'email', 120, $id_prefix );
        $html .= '</div>';
        $html .= '<div class="aikey-contact-form__field aikey-contact-form__field--message">';
        $html .= '<label for="' . esc_attr( $id_prefix . '-message' ) . '">' . esc_html( $labels['message'] ) . ' <span class="aikey-contact-form__required" aria-hidden="true">*</span></label>';
        $html .= '<span class="aikey-contact-form__help">' . esc_html( $labels['message_help'] ) . '</span>';
        $html .= '<textarea id="' . esc_attr( $id_prefix . '-message' ) . '" name="aikey_message" rows="5" maxlength="3000" required></textarea>';
        $html .= '</div>';
        $html .= '<button type="submit" class="aikey-contact-form__submit">' . esc_html( $labels['submit'] ) . '</button>';
        $html .= '</form></div>';
        return $html;
    }

    private function text_field( string $name, string $label, string $help, string $type, bool $required, string $autocomplete, int $maxlength, string $prefix ): string {
        $id = $prefix . '-' . str_replace( 'aikey_', '', $name );
        $html  = '<div class="aikey-contact-form__field">';
        $html .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $label );
        if ( $required ) { $html .= ' <span class="aikey-contact-form__required" aria-hidden="true">*</span>'; }
        $html .= '</label>';
        $html .= '<span class="aikey-contact-form__help">' . esc_html( $help ) . '</span>';
        $html .= '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" maxlength="' . esc_attr( (string) $maxlength ) . '" autocomplete="' . esc_attr( $autocomplete ) . '"' . ( $required ? ' required' : '' ) . '>';
        $html .= '</div>';
        return $html;
    }

    private static function validate_contact_payload( array $raw ): array {
        $honeypot = sanitize_text_field( self::raw_string( $raw, 'aikey_website' ) );
        if ( '' !== $honeypot ) {
            return array( 'valid' => false, 'status' => 'spam', 'fields' => array() );
        }

        $name = self::limit_text( sanitize_text_field( self::raw_string( $raw, 'aikey_name' ) ), 80 );
        $phone = self::limit_text( sanitize_text_field( self::raw_string( $raw, 'aikey_phone' ) ), 40 );
        $email = self::limit_text( sanitize_email( self::raw_string( $raw, 'aikey_email' ) ), 120 );
        $message = self::limit_text( sanitize_textarea_field( self::raw_string( $raw, 'aikey_message' ) ), 3000 );

        if ( '' === $phone ) {
            return array( 'valid' => false, 'status' => 'required', 'fields' => array() );
        }
        if ( ! preg_match( '/^[0-9+()\-\s]{5,40}$/u', $phone ) ) {
            return array( 'valid' => false, 'status' => 'phone', 'fields' => array() );
        }
        if ( '' === $email ) {
            return array( 'valid'=>false, 'status'=>'required_email', 'fields'=>array() );
        }
        if ( '' === $message ) {
            return array( 'valid'=>false, 'status'=>'required_message', 'fields'=>array() );
        }
        if ( ! is_email( $email ) ) {
            return array( 'valid' => false, 'status' => 'email', 'fields' => array() );
        }

        return array(
            'valid' => true,
            'status' => 'ok',
            'fields' => array(
                'name'    => $name,
                'phone'   => $phone,
                'email'   => $email,
                'message' => $message,
            ),
        );
    }


    /**
     * Five submissions per ten-minute window and client IP. Only REMOTE_ADDR is
     * trusted; forged forwarding headers must not bypass the counter.
     */
    private static function submission_rate_limited(): bool {
        $ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
        if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) { return false; }
        $bucket = (string) floor( time() / ( 10 * MINUTE_IN_SECONDS ) );
        $key = 'aikey_contact_rate_' . substr( wp_hash( $ip . '|' . $bucket ), 0, 24 );
        $count = (int) get_transient( $key );
        if ( $count >= 5 ) { return true; }
        set_transient( $key, $count + 1, 11 * MINUTE_IN_SECONDS );
        return false;
    }

    private static function raw_string( array $source, string $key ): string {
        if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) {
            return '';
        }
        return (string) wp_unslash( (string) $source[ $key ] );
    }

    private static function limit_text( string $value, int $length ): string {
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $value, 0, $length );
        }
        return substr( $value, 0, $length );
    }

    private function redirect_form_status( string $status ): void {
        $referer = wp_get_referer();
        $target = wp_validate_redirect( $referer ?: home_url( '/' ), home_url( '/' ) );
        $target = (string) preg_replace( '/#.*$/', '', $target );
        $target = remove_query_arg( array( 'aikey_contact_status', 'aikey_contact_form' ), $target );
        $form_id = sanitize_key( self::raw_string( $_POST, 'aikey_form_id' ) );
        $target = add_query_arg( array( 'aikey_contact_status'=>sanitize_key( $status ), 'aikey_contact_form'=>$form_id ), $target );
        $anchor = '' === $form_id ? 'aikey-contact-form' : 'aikey-contact-form-' . $form_id;
        wp_safe_redirect( $target . '#' . $anchor );
        exit;
    }

    private function map_buttons( string $company, string $address, string $lang ): string {
        $labels = Form_Profiles::map_labels( $lang );
        if ( 'zh' !== $lang ) {
            $url = self::map_view_url( 'google', $company, $address );
            return '' === $url ? '' : '<a class="aikey-enterprise-map__button" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $labels['google'] ) . '</a>';
        }

        $map = Settings::section( 'map' );
        $mode = (string) ( $map['navigation_provider'] ?? 'both' );
        $providers = 'both' === $mode ? array( 'amap', 'baidu' ) : array( $mode );
        $buttons = array();
        foreach ( $providers as $provider ) {
            if ( ! in_array( $provider, array( 'amap', 'baidu' ), true ) ) { continue; }
            $url = self::map_view_url( $provider, $company, $address );
            if ( '' === $url ) { continue; }
            $buttons[] = '<a class="aikey-enterprise-map__button" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $labels[ $provider ] ) . '</a>';
        }
        return implode( '', $buttons );
    }

    /** A map-specific address overrides Schema without changing structured data. */
    public static function display_address( array $map, array $schema ): string {
        $address = trim( (string) ( $map['address'] ?? '' ) );
        return '' !== $address ? $address : trim( (string) ( $schema['address'] ?? '' ) );
    }

    private function editor_config(): array {
        $map = Settings::section( 'map' );
        $schema = Settings::section( 'schema' );
        $profiles = array( array(
            'value'=>'', 'label'=>'默认表单', 'company'=>(string) ( $schema['organization_name'] ?? get_bloginfo( 'name' ) ),
            'address'=>self::display_address( $map, $schema ), 'heading'=>'留下您的联系方式',
            'mapLocation'=>Form_Profiles::map_labels( 'zh' )['location'],
            'mapMissing'=>Form_Profiles::map_labels( 'zh' )['missing'],
            'mapButton'=>Form_Profiles::map_labels( 'zh' )['amap'],
        ) );
        foreach ( Form_Profiles::all() as $id=>$profile ) {
            if ( ! is_array( $profile ) ) { continue; }
            $lang = (string) ( $profile['language'] ?? 'en' );
            $words = Form_Profiles::messages( $lang );
            $profiles[] = array(
                'value'=>(string) $id, 'label'=>(string) ( $profile['label'] ?? $id ),
                'company'=>'' !== (string) ( $profile['company'] ?? '' ) ? (string) $profile['company'] : (string) ( $schema['organization_name'] ?? get_bloginfo( 'name' ) ),
                'address'=>'' !== (string) ( $profile['address'] ?? '' ) ? (string) $profile['address'] : self::display_address( $map, $schema ),
                'heading'=>'' !== (string) ( $profile['heading'] ?? '' ) ? (string) $profile['heading'] : $words['heading'],
                'mapLocation'=>Form_Profiles::map_labels( $lang )['location'],
                'mapMissing'=>Form_Profiles::map_labels( $lang )['missing'],
                'mapButton'=>Form_Profiles::map_labels( $lang )['google'] ?? Form_Profiles::map_labels( $lang )['amap'],
            );
        }
        return array(
            'profiles'                => $profiles,
            'formsUrl'                => add_query_arg( 'page', 'aikey-seo-geo-forms', admin_url( 'admin.php' ) ),
            'company'                 => (string) ( $schema['organization_name'] ?? get_bloginfo( 'name' ) ),
            'address'                 => self::display_address( $map, $schema ),
            'defaultHeight'           => min( 720, max( 220, absint( $map['height'] ?? 340 ) ) ),
            'defaultShowAddress'      => ! empty( $map['show_address'] ),
            'defaultShowNavigation'   => ! empty( $map['show_navigation'] ),
            'settingsUrl'             => add_query_arg( 'page', 'aikey-seo-geo-settings', admin_url( 'admin.php' ) ) . '#aikey-enterprise-map-settings',
        );
    }
}
