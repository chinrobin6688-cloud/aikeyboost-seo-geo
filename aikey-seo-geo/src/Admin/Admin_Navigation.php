<?php
namespace AIKEY\SeoGeo\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Stable-edition task navigation: presentation only.
 * Every destination uses an existing v2.3.2 page slug; no license checks,
 * new routes, data writes or dependencies on a Pro plugin.
 */
final class Admin_Navigation {
    public static function render( string $current = '' ): void {
        if ( '' === $current ) {
            $current = isset( $_GET['page'] ) && is_string( $_GET['page'] )
                ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        }
        $sections = array(
            'content' => array(
                'title' => '内容优化',
                'items' => array(
                    array( 'title'=>'优化工作台', 'slug'=>'aikey-seo-geo-optimizer' ),
                    array( 'title'=>'发文管理', 'slug'=>'aikey-seo-geo-articles' ),
                    array( 'title'=>'自动优化', 'slug'=>'aikey-seo-geo-automation' ),
                ),
            ),
            'languages' => array(
                'title' => '全球多语言',
                'items' => array(
                    array( 'title'=>'语言与页面关联', 'slug'=>'aikey-seo-geo-languages' ),
                ),
            ),
            'search' => array(
                'title' => '搜索收录',
                'items' => array(
                    array( 'title'=>'提交中心', 'slug'=>'aikey-seo-geo-submit' ),
                    array( 'title'=>'Sitemap / robots', 'slug'=>'aikey-seo-geo-sitemap' ),
                    array( 'title'=>'搜索 / AI 引擎', 'slug'=>'aikey-seo-geo-engines' ),
                    array( 'title'=>'站点验证与统计', 'slug'=>'aikey-seo-geo-verification' ),
                ),
            ),
            'monitor' => array(
                'title' => '监测与修复',
                'items' => array(
                    array( 'title'=>'404 / 重定向', 'slug'=>'aikey-seo-geo-redirects' ),
                    array( 'title'=>'来源监测', 'slug'=>'aikey-seo-geo-referrals' ),
                ),
            ),
            'forms' => array(
                'title' => '表单管理',
                'items' => array(
                    array( 'title'=>'表单信息', 'slug'=>'aikey-seo-geo-contacts' ),
                    array( 'title'=>'表单配置', 'slug'=>'aikey-seo-geo-forms' ),
                ),
            ),
            'settings' => array(
                'title' => '设置',
                'items' => array(
                    array( 'title'=>'基础设置', 'slug'=>'aikey-seo-geo-settings' ),
                ),
            ),
        );

        $active = 'content';
        foreach ( $sections as $key => $section ) {
            foreach ( $section['items'] as $item ) {
                if ( $item['slug'] === $current ) { $active = $key; break 2; }
            }
        }
        echo '<div class="aikey-navigation-shell">';
        echo '<nav class="aikey-primary-nav" aria-label="SEO/GEO 主要功能分区">';
        echo '<span class="aikey-nav-caption">功能分区</span><div class="aikey-primary-links">';
        foreach ( $sections as $key => $section ) {
            $first = (string) $section['items'][0]['slug'];
            $url = add_query_arg( 'page', $first, admin_url( 'admin.php' ) );
            echo '<a href="' . esc_url( $url ) . '" class="' .
                esc_attr( $active === $key ? 'is-active' : '' ) . '"' .
                ( $current === $first && 1 === count( $section['items'] ) ? ' aria-current="page"' : '' ) . '>' .
                esc_html( $section['title'] ) . '</a>';
        }
        echo '</div></nav><p class="aikey-nav-scroll-hint">左右滑动查看其它功能分区</p>';
        $section = $sections[ $active ];
        if ( count( $section['items'] ) > 1 ) {
            echo '<nav class="aikey-secondary-nav" aria-label="' . esc_attr( $section['title'] ) . '子功能">';
            echo '<span class="aikey-secondary-label">' . esc_html( $section['title'] ) . '</span>';
            echo '<div class="aikey-secondary-links">';
            foreach ( $section['items'] as $item ) {
                $selected = $current === $item['slug'];
                $url = add_query_arg( 'page', $item['slug'], admin_url( 'admin.php' ) );
                echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $selected ? 'is-active' : '' ) . '"' .
                    ( $selected ? ' aria-current="page"' : '' ) . '>' . esc_html( $item['title'] ) . '</a>';
            }
            echo '</div></nav>';
        }
        echo '</div>';
    }
}
