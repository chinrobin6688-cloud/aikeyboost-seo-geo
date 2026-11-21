<?php
namespace AIKEY\SeoGeo\SEO\International;

use AIKEY\SeoGeo\GEO\Language;

defined( 'ABSPATH' ) || exit;

/** Administrator-only UI. No frontend queries or assets are registered here. */
final class Translation_Admin {
    public static function register_hooks(): void {
        if ( ! is_admin() ) { return; }
        add_action( 'admin_menu', array( self::class, 'menu' ), 31 );
        add_action( 'add_meta_boxes', array( self::class, 'meta_boxes' ), 25 );
        foreach ( array(
            'language'=>'save_language', 'options'=>'save_options', 'remove_language'=>'remove_language',
            'bind'=>'bind', 'unlink'=>'unlink', 'draft'=>'draft', 'set_language'=>'set_language',
        ) as $action=>$method ) {
            add_action( 'admin_post_aikey_ml_' . $action, array( self::class, $method ) );
        }
    }

    public static function menu(): void {
        add_submenu_page( 'aikey-seo-geo', '多语言管理', '多语言管理',
            'manage_options', 'aikey-seo-geo-languages', array( self::class, 'page' ) );
    }

    public static function meta_boxes(): void {
        foreach ( array( 'page','post','product_cases' ) as $type ) {
            if ( Translation_Groups::supported_type( $type ) ) {
                add_meta_box( 'aikey-translation-group', 'SEO&GEO 多语言关联',
                    array( self::class, 'meta_box' ), $type, 'side', 'default' );
            }
        }
    }

    public static function meta_box( \WP_Post $post ): void {
        $lang = Translation_Groups::language_for( $post->ID ) ?: Language::code( $post );
        $related = Translation_Groups::admin_group( $post->ID );
        echo '<p>当前语言：<strong>' . esc_html( $lang ) . '</strong></p>';
        echo '<ul>';
        foreach ( Language_Registry::all() as $code=>$config ) {
            if ( empty( $config['enabled'] ) ) { continue; }
            $item = $related[ $code ] ?? null;
            $text = $item
                ? ( (int) $item['post_id'] === (int) $post->ID
                    ? '当前页面' : $item['title'] . ' [' . $item['status'] . ']' )
                : '尚未关联';
            echo '<li><strong>' . esc_html( (string) $config['name'] ) . '：</strong> ' . esc_html( $text ) . '</li>';
        }
        echo '</ul>';
        $url = add_query_arg( array( 'page'=>'aikey-seo-geo-languages', 'post'=>$post->ID ),
            admin_url( 'admin.php' ) );
        echo '<p><a class="button button-secondary" href="' . esc_url( $url ) . '">搜索、关联、创建翻译草稿</a></p>';
        echo '<p class="description">只关联内容 ID，不根据 Slug 自动绑定。不会覆盖各语言 SEO/GEO 字段。</p>';
    }

    private static function safe_post( int $id ): \WP_Post|\WP_Error {
        $post = get_post( $id );
        if ( ! $post || ! Translation_Groups::supported_type( (string) $post->post_type )
            || ! current_user_can( 'edit_post', $id ) ) {
            return new \WP_Error( 'forbidden_post', '页面不存在、类型不受支持或无编辑权限。' );
        }
        return $post;
    }

    private static function authorize( string $action, int $post_id = 0 ): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( '无权限。', '', array( 'response'=>403 ) ); }
        check_admin_referer( 'aikey_ml_' . $action );
        if ( $post_id && is_wp_error( self::safe_post( $post_id ) ) ) {
            wp_die( '没有编辑该内容的权限。', '', array( 'response'=>403 ) );
        }
    }

    private static function scalar( string $key ): string {
        $value = $_POST[ $key ] ?? '';
        return is_scalar( $value ) ? trim( (string) wp_unslash( (string) $value ) ) : '';
    }

    private static function home( int $post_id = 0, array $notice = array() ): void {
        $query = array( 'page'=>'aikey-seo-geo-languages' );
        if ( $post_id > 0 ) { $query['post'] = $post_id; }
        if ( $notice ) {
            $query['aikey_ml_result'] = $notice['ok'] ? 'ok' : 'error';
            $query['aikey_ml_msg'] = sanitize_text_field( $notice['message'] );
        }
        wp_safe_redirect( add_query_arg( $query, admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function outcome( bool|\WP_Error $result, string $success, int $post_id = 0 ): void {
        $ok = ! is_wp_error( $result ) && true === $result;
        self::home( $post_id, array( 'ok'=>$ok,
            'message'=>$ok ? $success : ( is_wp_error( $result ) ? $result->get_error_message() : '保存失败，请检查数据库。' ),
        ) );
    }

    public static function save_language(): void {
        self::authorize( 'language' );
        $code = self::scalar( 'code' );
        $label = self::scalar( 'label' );
        $home_id = absint( self::scalar( 'home_id' ) );
        self::outcome( Language_Registry::save( $code, $label, $home_id,
            '1' === self::scalar( 'enabled' ) ), '语言配置已保存。' );
    }

    public static function remove_language(): void {
        self::authorize( 'remove_language' );
        self::outcome( Language_Registry::delete( self::scalar( 'code' ) ), '语言配置已删除。' );
    }

    public static function save_options(): void {
        self::authorize( 'options' );
        self::outcome( Language_Registry::set_options(
            self::scalar( 'default' ), self::scalar( 'x_default' ),
            '1' === self::scalar( 'activate' )
        ), '多语言输出选项已保存。' );
    }

    public static function bind(): void {
        $source_id = absint( self::scalar( 'source_id' ) );
        $target_id = absint( self::scalar( 'target_id' ) );
        self::authorize( 'bind', $source_id );
        if ( is_wp_error( self::safe_post( $target_id ) ) ) {
            wp_die( '目标页面没有编辑权限。', '', array( 'response'=>403 ) );
        }
        self::outcome( Translation_Groups::bind( $source_id, $target_id,
            self::scalar( 'language' ), self::scalar( 'source_language' ) ),
            '语言页面已按 ID 关联。', $source_id );
    }

    public static function unlink(): void {
        $source_id = absint( self::scalar( 'source_id' ) );
        $target_id = absint( self::scalar( 'target_id' ) );
        self::authorize( 'unlink', $source_id );
        if ( is_wp_error( self::safe_post( $target_id ) )
            || ! in_array( $target_id, array_column( Translation_Groups::members( $source_id ), 'post_id' ), true ) ) {
            wp_die( '目标不属于当前翻译组或无权限。', '', array( 'response'=>403 ) );
        }
        self::outcome( Translation_Groups::unlink( $target_id ), '已解除该页面关联。', $source_id );
    }

    public static function set_language(): void {
        $source_id = absint( self::scalar( 'source_id' ) );
        self::authorize( 'set_language', $source_id );
        $locale = Language::normalize( self::scalar( 'language' ) );
        if ( ! Language_Registry::registered( $locale ) ) {
            self::outcome( new \WP_Error( 'unknown_locale', '语言尚未注册。' ), '', $source_id );
        }
        if ( Translation_Groups::members( $source_id ) ) {
            self::outcome( Translation_Groups::change_language( $source_id, $locale ),
                '当前成员语言已修改。', $source_id );
        }
        // An ungrouped page may define its language before binding a translation.
        update_post_meta( $source_id, International_Module::PAGE_LANGUAGE, $locale );
        self::outcome( get_post_meta( $source_id, International_Module::PAGE_LANGUAGE, true ) === $locale,
            '页面语言已保存；尚未创建翻译组。', $source_id );
    }

    public static function draft(): void {
        $source_id = absint( self::scalar( 'source_id' ) );
        self::authorize( 'draft', $source_id );
        $new = Translation_Groups::create_draft( $source_id, self::scalar( 'language' ) );
        if ( is_wp_error( $new ) ) { self::outcome( $new, '', $source_id ); }
        // New draft remains unpublished; send the editor to fill independent content.
        wp_safe_redirect( get_edit_post_link( (int) $new, 'raw' ) );
        exit;
    }

    public static function page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( '无权限。', '', array( 'response'=>403 ) ); }
        $registry = Language_Registry::all();
        $counts = Language_Registry::counts();
        $unlinked = Language_Registry::unlinked_counts();
        $default = Language_Registry::default_code();
        $settings = \AIKEY\SeoGeo\Core\Settings::section( 'international' );
        $post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        echo '<div class="wrap aikey-wrap aikey-language-stable"><div class="aikey-page-head"><h1>SEO&GEO · 多语言管理</h1>';
        echo '<p>通过 WordPress 页面 ID 绑定翻译；旧 URL 关联和 WPCode 不会自动关闭。请先建立关系，验证后再启用新版 hreflang 输出。</p></div>';
        \AIKEY\SeoGeo\Admin\Admin_Navigation::render( 'aikey-seo-geo-languages' );
        if ( isset( $_GET['aikey_ml_msg'] ) && is_scalar( $_GET['aikey_ml_msg'] ) ) {
            $color = ( $_GET['aikey_ml_result'] ?? '' ) === 'ok' ? 'success' : 'error';
            echo '<div class="notice notice-' . esc_attr( $color ) . '"><p>' .
                esc_html( sanitize_text_field( wp_unslash( (string) $_GET['aikey_ml_msg'] ) ) ) . '</p></div>';
        }
        echo '<h2>启用的语言和关联数量</h2><table class="widefat striped"><thead><tr>'
            . '<th>代码</th><th>语言名称</th><th>首页 ID</th><th>已关联内容</th><th>未关联内容</th><th>状态</th><th>操作</th>'
            . '</tr></thead><tbody>';
        foreach ( $registry as $code=>$row ) {
            $title = $code === $default ? '（默认）' : '';
            echo '<tr><td><code>' . esc_html( $code ) . '</code>' . esc_html( $title ) . '</td>'
                . '<td>' . esc_html( (string) ( $row['name'] ?? $code ) ) . '</td>'
                . '<td>' . esc_html( (string) (int) ( $row['home_id'] ?? 0 ) ) . '</td>'
                . '<td>' . (int) ( $counts[ $code ] ?? 0 ) . '</td>'
                . '<td>' . (int) ( $unlinked[ $code ] ?? 0 ) . '</td>'
                . '<td>' . ( ! empty( $row['enabled'] ) ? '启用' : '停用' ) . '</td>'
                . '<td><a href="' . esc_url( add_query_arg( array( 'page'=>'aikey-seo-geo-languages',
                    'edit_locale'=>$code ), admin_url( 'admin.php' ) ) ) . '">修改</a></td></tr>';
        }
        echo '</tbody></table>';
        $edit_locale = isset( $_GET['edit_locale'] ) && is_string( $_GET['edit_locale'] )
            ? Language::normalize( (string) wp_unslash( $_GET['edit_locale'] ) ) : '';
        $editing = $registry[ $edit_locale ] ?? array();
        echo '<h2>' . ( $editing ? '修改语言' : '增加任意 BCP 47 语言' ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'aikey_ml_language' );
        echo '<input type="hidden" name="action" value="aikey_ml_language">';
        echo '<p>语言代码 <input name="code" required maxlength="55" placeholder="zh-CN / en-US / fr / ar / ja"'
            . ' value="' . esc_attr( $edit_locale ) . '">　显示名称 <input name="label" required maxlength="80"'
            . ' value="' . esc_attr( (string) ( $editing['name'] ?? '' ) ) . '"></p>';
        echo '<p>对应语言首页页面 ID <input type="number" min="0" name="home_id" value="' .
            esc_attr( (string)(int)( $editing['home_id'] ?? 0 ) ) . '">　'
            . '<label><input type="checkbox" name="enabled" value="1" ' .
            checked( (bool) ( $editing['enabled'] ?? true ), true, false ) . '> 启用</label></p>';
        submit_button( '保存语言', 'primary', 'submit', false );
        echo '</form>';
        if ( $editing && $edit_locale !== $default ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'aikey_ml_remove_language' );
            echo '<input type="hidden" name="action" value="aikey_ml_remove_language"><input type="hidden" name="code" value="' . esc_attr( $edit_locale ) . '">';
            submit_button( '删除此语言（必须无关联页面）', 'delete', 'submit', false );
            echo '</form>';
        }
        echo '<h2>默认语言及 SEO 输出控制</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'aikey_ml_options' );
        echo '<input type="hidden" name="action" value="aikey_ml_options">';
        echo '<p><label>默认语言 <select name="default">';
        foreach ( $registry as $code=>$config ) {
            if ( ! empty( $config['enabled'] ) ) {
                echo '<option value="' . esc_attr( $code ) . '" ' . selected( $default, $code, false ) . '>'
                    . esc_html( (string) $config['name'] ) . '</option>';
            }
        }
        echo '</select></label>　<label>x-default <select name="x_default"><option value="">不启用</option>';
        foreach ( $registry as $code=>$config ) {
            if ( empty( $config['enabled'] ) ) { continue; }
            echo '<option value="' . esc_attr( $code ) . '" ' .
                selected( (string)( $settings['x_default_language'] ?? '' ), $code, false ) . '>'
                . esc_html( (string) $config['name'] ) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label><input type="checkbox" name="activate" value="1" ' .
            checked( ! empty( $settings['id_links_enabled'] ), true, false ) .
            '> 使用 ID 翻译组输出 hreflang / HTML lang / Schema 语言</label></p>';
        echo '<p class="description"><strong>迁移开关默认关闭。</strong>启用前必须核查并迁移原 WPCode #2313 / #2314 的相同 hreflang 输出。'
            . '不会自动修改 WPCode、菜单、旧 URL 或任何 SEO/GEO 字段。x-default 只引用组中真实可索引的已配置语言页面。</p>';
        submit_button( '保存语言输出设置', 'secondary', 'submit', false );
        echo '</form>';

        if ( $post_id && ! is_wp_error( self::safe_post( $post_id ) ) ) {
            self::post_management( $post_id, $registry );
        } else {
            echo '<h2>按内容 ID 管理翻译组</h2><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">'
                . '<input type="hidden" name="page" value="aikey-seo-geo-languages">'
                . '<label>WordPress 页面、文章或产品案例 ID <input type="number" min="1" name="post" required></label> '
                . '<button class="button button-primary">查看关联</button></form>';
        }
        echo '</div>';
    }

    private static function post_management( int $source_id, array $registry ): void {
        $source = get_post( $source_id );
        $members = Translation_Groups::admin_group( $source_id );
        $source_language = Translation_Groups::language_for( $source_id ) ?: Language::code( $source );
        echo '<hr><h2>翻译组：' . esc_html( get_the_title( $source ) ) . '（ID ' . esc_html( (string) $source_id ) . '）</h2>';
        echo '<p>内容类型：' . esc_html( $source->post_type )
            . '；源语言：<strong>' . esc_html( $source_language ) . '</strong></p>';
        echo '<table class="widefat striped"><thead><tr><th>语言</th><th>页面 ID / 标题</th><th>状态</th><th>操作</th></tr></thead><tbody>';
        foreach ( $registry as $lang=>$info ) {
            $member = $members[ $lang ] ?? null;
            echo '<tr><td>' . esc_html( (string) ( $info['name'] ?? $lang ) ) . ' (' . esc_html( $lang ) . ')</td>';
            if ( $member ) {
                $target_id = (int) $member['post_id'];
                echo '<td>' . esc_html( (string) $target_id ) . ' · <a href="' . esc_url( get_edit_post_link( $target_id, 'raw' ) )
                    . '">' . esc_html( (string) $member['title'] ) . '</a></td><td>'
                    . esc_html( (string) $member['status'] ) . '</td><td>';
                if ( $target_id !== $source_id && current_user_can( 'edit_post', $target_id ) ) {
                    self::action_form( 'unlink', $source_id, $target_id, '', '解除关联' );
                } else { echo '当前页面'; }
                echo '</td>';
            } else {
                echo '<td>未创建或未关联</td><td>—</td><td>';
                if ( ! empty( $info['enabled'] ) && $lang !== $source_language ) {
                    self::action_form( 'draft', $source_id, 0, $lang, '创建草稿' );
                }
                $url = add_query_arg( array( 'page'=>'aikey-seo-geo-languages',
                    'post'=>$source_id, 'lang'=>$lang ), admin_url( 'admin.php' ) );
                echo ' <a href="' . esc_url( $url ) . '">搜索现有页面</a>';
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        {
            echo '<h3>设置当前页面的语言（关联后也可修改）</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'aikey_ml_set_language' );
            echo '<input type="hidden" name="action" value="aikey_ml_set_language"><input type="hidden" name="source_id" value="' . esc_attr( (string) $source_id ) . '"><select name="language">';
            foreach ( $registry as $lang=>$info ) {
                echo '<option value="' . esc_attr( $lang ) . '" ' . selected( $source_language, $lang, false ) . '>' .
                    esc_html( (string) $info['name'] ) . '</option>';
            }
            echo '</select> <button class="button">保存成员语言</button></form>';
        }
        $lang = isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ?
            Language::normalize( wp_unslash( $_GET['lang'] ) ) : '';
        if ( ! isset( $registry[ $lang ] ) || $lang === $source_language ) { return; }
        echo '<h3>查找现有 ' . esc_html( $lang ) . ' 页面（仅同一文章类型）</h3>';
        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">'
            . '<input type="hidden" name="page" value="aikey-seo-geo-languages">'
            . '<input type="hidden" name="post" value="' . esc_attr( (string) $source_id ) . '">'
            . '<input type="hidden" name="lang" value="' . esc_attr( $lang ) . '">'
            . '<label>标题搜索 <input type="search" name="s" value="' .
            esc_attr( is_scalar( $_GET['s'] ?? '' ) ? wp_unslash( (string) $_GET['s'] ) : '' ) .
            '" placeholder="标题关键词"></label> <button class="button">搜索</button></form>';
        $term = isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ?
            sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
        $candidates = self::candidates( $source, $lang, $term );
        if ( ! $candidates ) { echo '<p>没有找到候选。可搜索其他标题或先创建新草稿。</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>目标 ID</th><th>标题 / 状态</th><th>操作</th></tr></thead><tbody>';
        foreach ( $candidates as $target ) {
            echo '<tr><td>' . (int) $target->ID . '</td><td>' . esc_html( get_the_title( $target ) )
                . ' (' . esc_html( $target->post_status ) . ')</td><td>';
            self::action_form( 'bind', $source_id, (int) $target->ID, $lang, '确认关联', $source_language );
            echo '</td></tr>';
        }
        echo '</tbody></table><p class="description">候选仅供人工核对，确认前不会修改任何页面或翻译组。</p>';
    }

    private static function action_form( string $action, int $source_id, int $target_id,
        string $lang, string $button, string $source_lang = '' ): void {
        echo '<form style="display:inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'aikey_ml_' . $action );
        echo '<input type="hidden" name="action" value="aikey_ml_' . esc_attr( $action ) . '">'
            . '<input type="hidden" name="source_id" value="' . esc_attr( (string) $source_id ) . '">'
            . '<input type="hidden" name="target_id" value="' . esc_attr( (string) $target_id ) . '">'
            . '<input type="hidden" name="language" value="' . esc_attr( $lang ) . '">'
            . '<input type="hidden" name="source_language" value="' . esc_attr( $source_lang ) . '">'
            . '<button class="button button-small">' . esc_html( $button ) . '</button></form>';
    }

    /** Admin-only title query; also proposes explicit legacy URL and old WPCode pairs. */
    private static function candidates( \WP_Post $source, string $lang, string $term ): array {
        $items = get_posts( array(
            'post_type'=>$source->post_type, 'post_status'=>array( 'publish','draft','pending','private' ),
            'posts_per_page'=>20, 's'=>$term, 'exclude'=>array( $source->ID ),
            'orderby'=>'date', 'order'=>'DESC',
        ) );
        $by_id = array();
        foreach ( $items as $item ) { $by_id[ $item->ID ] = $item; }

        // Existing explicit hreflang URLs can identify pages even when slugs differ.
        $alternates = get_post_meta( $source->ID, International_Module::ALTERNATES, true );
        if ( is_array( $alternates ) && ! empty( $alternates[ $lang ] ) ) {
            $id = url_to_postid( (string) $alternates[ $lang ] );
            $post = $id ? get_post( $id ) : null;
            if ( $post && $post->post_type === $source->post_type && $post->ID !== $source->ID ) {
                $by_id[ $id ] = $post;
            }
        }
        // Detect WPCode #2313 candidates if the existing live snippet provides them.
        if ( function_exists( 'tm_ml_page_map' ) && 'page' === $source->post_type ) {
            $legacy = tm_ml_page_map( $source->ID );
            if ( is_array( $legacy ) && isset( $legacy[ $lang ] ) ) {
                $id = (int) $legacy[ $lang ];
                $post = $id ? get_post( $id ) : null;
                if ( $post && 'page' === $post->post_type && $post->ID !== $source->ID ) {
                    $by_id[ $id ] = $post;
                }
            }
        }
        return array_values( $by_id );
    }
}
