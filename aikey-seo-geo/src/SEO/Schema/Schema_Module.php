<?php
namespace AIKEY\SeoGeo\SEO\Schema;

use AIKEY\SeoGeo\Core\Conflict_Detector;
use AIKEY\SeoGeo\Core\Module_Interface;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\GEO\Language;
use AIKEY\SeoGeo\SEO\International\Language_Registry;
use AIKEY\SeoGeo\SEO\International\Translation_Groups;
use AIKEY\SeoGeo\GEO\Public_Content;
use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

final class Schema_Module implements Module_Interface {
    public function id(): string { return 'schema'; }
    public function register(): void { add_action( 'wp_head', array( $this, 'output' ), 30 ); }

    public function output(): void {
        if ( is_admin() || ! Conflict_Detector::frontend_allowed() || ! ( is_front_page() || is_singular() ) ) { return; }
        $post = get_queried_object();
        $language = $post instanceof \WP_Post
            ? Language::code( $post )
            : ( ! empty( Settings::section( 'international' )['id_links_enabled'] )
                && class_exists( Translation_Groups::class )
                ? Language_Registry::default_code() : Language::site_code() );
        $graph = $this->base_graph( $language );
        if ( is_singular() ) {
            $post = get_queried_object();
            if ( $post instanceof \WP_Post && ! post_password_required( $post ) ) {
                $graph = array_merge( $graph, $this->post_graph( $post ) );
            }
        }
        if ( empty( $graph ) ) { return; }
        $payload = array( '@context' => 'https://schema.org', '@graph' => array_values( $graph ) );
        $json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        if ( ! is_string( $json ) || '' === $json ) { return; }
        echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private function base_graph( string $language ): array {
        $home = home_url( '/' );
        $schema = Settings::section( 'schema' );
        $records = (array) ( $schema['localized_organizations'] ?? array() );
        $local = Localized_Entity::for_language( $records, $language );
        $org_name = trim( (string) ( $schema['organization_name'] ?? '' ) );
        $org_name = (string) ( $local['name'] ?? ( '' !== $org_name ? $org_name : get_bloginfo( 'name' ) ) );
        $org_type = (string) ( $schema['organization_type'] ?? 'Organization' );
        $org_type = in_array( $org_type, array( 'Organization','LocalBusiness','Corporation' ), true ) ? $org_type : 'Organization';
        $org = array(
            '@type' => $org_type,
            '@id' => $home . '#organization',
            'name' => $org_name,
            'url' => $home,
        );
        $logo = esc_url_raw( (string) ( $schema['logo_url'] ?? '' ) );
        if ( '' !== $logo ) { $org['logo'] = array( '@type'=>'ImageObject', 'url'=>$logo ); }
        foreach ( array( 'telephone','email','address' ) as $field ) {
            $value = trim( (string) ( ( 'address' === $field ? ( $local['address'] ?? null ) : null )
                ?? ( $schema[ $field ] ?? '' ) ) );
            if ( '' !== $value ) { $org[ $field ] = $value; }
        }
        $service_area = trim( (string) ( $local['areaServed'] ?? ( $schema['service_area'] ?? '' ) ) );
        if ( '' !== $service_area ) { $org['areaServed'] = $service_area; }
        if ( ! empty( $local['description'] ) ) {
            $org['description'] = (string) $local['description'];
        }
        $credit_code = trim( (string) ( $schema['unified_social_credit_code'] ?? '' ) );
        if ( '' !== $credit_code ) {
            $org['identifier'][] = array( '@type'=>'PropertyValue', 'propertyID'=>'统一社会信用代码', 'value'=>$credit_code );
        }
        $icp_number = trim( (string) ( $schema['icp_number'] ?? '' ) );
        if ( '' !== $icp_number ) {
            $org['identifier'][] = array( '@type'=>'PropertyValue', 'propertyID'=>'ICP备案号', 'value'=>$icp_number );
        }
        $same_as = array();
        foreach ( Utils::clean_lines( (string) ( $schema['same_as'] ?? '' ), 20 ) as $url ) {
            $clean = esc_url_raw( $url, array( 'http','https' ) );
            if ( '' !== $clean ) { $same_as[] = $clean; }
        }
        if ( $same_as ) { $org['sameAs'] = $same_as; }
        $website = array(
            '@type' => 'WebSite',
            '@id' => $home . '#website',
            'url' => $home,
            'name' => $local['name'] ?? get_bloginfo( 'name' ),
            'description' => $local['description'] ?? get_bloginfo( 'description' ),
            'publisher' => array( '@id' => $home . '#organization' ),
            'inLanguage' => $language,
        );
        return array( $org, $website );
    }

    private function post_graph( \WP_Post $post ): array {
        $url = Meta_Repository::resolved_canonical_url( $post );
        $type = Meta_Repository::schema_type( $post->ID );
        if ( '' === $type ) {
            if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) { $type = 'Product'; }
            elseif ( 'post' === $post->post_type ) { $type = 'Article'; }
            else { $type = 'WebPage'; }
        }
        $description = Meta_Repository::resolve_seo_description( (int) $post->ID, is_front_page() );
        $entity = array(
            '@type' => $type,
            '@id' => $url . '#primary',
            'url' => $url,
            'name' => Meta_Repository::preview_title( $post ),
            'description' => $description,
            'inLanguage' => Language::code( $post ),
            'isPartOf' => array( '@id' => home_url( '/' ) . '#website' ),
        );
        $image = get_the_post_thumbnail_url( $post, 'full' );
        if ( $image ) { $entity['image'] = esc_url_raw( $image ); }

        if ( in_array( $type, array( 'Article' ), true ) ) {
            $entity['headline'] = get_the_title( $post );
            $entity['datePublished'] = get_post_time( DATE_W3C, true, $post );
            $entity['dateModified'] = get_post_modified_time( DATE_W3C, true, $post );
            $entity['author'] = array( '@type'=>'Person', 'name'=>get_the_author_meta( 'display_name', (int) $post->post_author ) );
            $entity['publisher'] = array( '@id'=>home_url( '/' ) . '#organization' );
        } elseif ( 'Product' === $type ) {
            $this->enrich_product( $entity, $post );
        } elseif ( 'Service' === $type ) {
            $entity['provider'] = array( '@id'=>home_url( '/' ) . '#organization' );
        }

        $geo_name = trim( Meta_Repository::get( $post->ID, Meta_Repository::GEO_ENTITY ) );
        $geo_type = Meta_Repository::get( $post->ID, Meta_Repository::GEO_ENTITY_TYPE );
        if ( '' !== $geo_name && in_array( $type, array( 'WebPage','AboutPage','ContactPage','Article' ), true )
            && in_array( $geo_type, array( 'Organization','Product','Service','Person','Place' ), true ) ) {
            $entity['about'] = array( '@type'=>$geo_type, 'name'=>wp_strip_all_tags( $geo_name ) );
        }

        // Only visitor-visible facts are placed into JSON-LD.
        $facts = Public_Content::visible_facts( $post );
        if ( $facts ) {
            if ( 'Product' === $type ) {
                foreach ( $facts as $fact ) {
                    $label = 'Product Fact';
                    if ( preg_match( '/MOQ|minimum order|起订量/iu', $fact ) ) { $label = 'Minimum Order Quantity'; }
                    elseif ( preg_match( '/lead[\s-]*time|交期|交付周期/iu', $fact ) ) { $label = 'Lead Time'; }
                    $entity['additionalProperty'][] = array( '@type'=>'PropertyValue', 'name'=>$label, 'value'=>$fact );
                }
            } elseif ( in_array( $type, array( 'WebPage','AboutPage','ContactPage','Article' ), true ) ) {
                foreach ( $facts as $index => $fact ) {
                    $entity['hasPart'][] = array( '@type'=>'CreativeWork', 'name'=>'Key Fact ' . ( $index + 1 ), 'text'=>$fact );
                }
            } else {
                $entity['description'] = trim( $description . ' ' . implode( '; ', $facts ) );
            }
        }
        if ( in_array( $type, array( 'WebPage','AboutPage','ContactPage','Article' ), true ) ) {
            $sources = Public_Content::visible_sources( $post );
            if ( $sources ) { $entity['citation'] = $sources; }
        }
        // CPT owners may supply verified product specs without duplicating CPT registration.
        if ( 'Product' === $type ) {
            $extended = apply_filters( 'aikey_seo_geo_product_schema', $entity, $post );
            if ( is_array( $extended ) && 'Product' === ( $extended['@type'] ?? '' ) ) { $entity = $extended; }
        }

        $graph = array( $entity, $this->breadcrumbs( $post ) );
        $faq = $this->faq_schema( $post );
        if ( $faq ) { $graph[] = $faq; }
        return array_filter( $graph );
    }

    private function enrich_product( array &$entity, \WP_Post $post ): void {
        if ( ! function_exists( 'wc_get_product' ) ) { return; }
        $product = wc_get_product( $post->ID );
        if ( ! $product ) { return; }
        $sku = $product->get_sku();
        if ( $sku ) { $entity['sku'] = $sku; }
        $price = $product->get_price();
        if ( '' !== $price ) {
            $entity['offers'] = array(
                '@type'=>'Offer',
                'url'=>(string) get_permalink( $post ),
                'priceCurrency'=>get_woocommerce_currency(),
                'price'=>(string) $price,
                'availability'=>$product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            );
        }
    }

    private function breadcrumbs( \WP_Post $post ): array {
        $locale = Language::code( $post );
        $id_mode = ! empty( Settings::section( 'international' )['id_links_enabled'] )
            && class_exists( Translation_Groups::class );
        $lang_home = $id_mode ? Language_Registry::home_url( $locale ) : '';
        $home = '' !== $lang_home ? $lang_home : home_url( '/' );
        $home_label = get_bloginfo( 'name' );
        if ( '' !== $lang_home ) {
            $config = Language_Registry::all()[ $locale ] ?? array();
            $id = (int) ( $config['home_id'] ?? 0 );
            if ( $id ) { $home_label = get_the_title( $id ); }
        }
        $items = array( array(
            '@type'=>'ListItem', 'position'=>1, 'name'=>$home_label, 'item'=>$home,
        ) );
        $position = 2;
        // Use actual WordPress hierarchy for pages, never infer parents from slugs.
        // Under ID mode, skip ancestors belonging to a different language.
        if ( 'page' === $post->post_type ) {
            $parents = array_reverse( get_post_ancestors( $post ) );
            foreach ( $parents as $parent_id ) {
                $parent = get_post( (int) $parent_id );
                if ( ! $parent || 'publish' !== $parent->post_status ) { continue; }
                if ( $id_mode && Language::code( $parent ) !== $locale ) { continue; }
                $parent_url = (string) get_permalink( $parent );
                if ( '' === $parent_url || $parent_url === $home ) { continue; }
                $items[] = array( '@type'=>'ListItem','position'=>$position++,
                    'name'=>get_the_title( $parent ), 'item'=>$parent_url );
            }
        } else {
            $type = get_post_type_object( $post->post_type );
            if ( $type && $type->has_archive && ( ! $id_mode
                || $locale === Language_Registry::default_code() ) ) {
                $archive = get_post_type_archive_link( $post->post_type );
                if ( $archive ) {
                    $items[] = array( '@type'=>'ListItem', 'position'=>$position++,
                        'name'=>$type->labels->name,'item'=>$archive );
                }
            }
        }
        $current = (string) get_permalink( $post );
        if ( $current !== $home ) {
            $items[] = array( '@type'=>'ListItem','position'=>$position,
                'name'=>get_the_title( $post ),'item'=>$current );
        }
        return array( '@type'=>'BreadcrumbList',
            '@id'=>$current . '#breadcrumb','itemListElement'=>$items );
    }

    private function faq_schema( \WP_Post $post ): array {
        $entities = array();
        foreach ( Public_Content::visible_faq( $post ) as $pair ) {
            $entities[] = array(
                '@type'=>'Question',
                'name'=>wp_strip_all_tags( $pair['q'] ),
                'acceptedAnswer'=>array( '@type'=>'Answer','text'=>wp_strip_all_tags( $pair['a'] ) ),
            );
        }
        return $entities ? array( '@type'=>'FAQPage','@id'=>(string) get_permalink( $post ) . '#faq','inLanguage'=>Language::code( $post ),'mainEntity'=>$entities ) : array();
    }

}
