<?php
namespace AIKEY\SeoGeo\SEO\Meta;

use AIKEY\SeoGeo\Core\Conflict_Detector;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\GEO\Scorer;
use AIKEY\SeoGeo\Support\Utils;

defined( 'ABSPATH' ) || exit;

final class Meta_Box {
    private const NONCE_ACTION = 'aikey_seo_geo_save_meta';
    private const NONCE_NAME = 'aikey_seo_geo_meta_nonce';

    public function register(): void {
        add_action( 'add_meta_boxes', array( $this, 'add' ) );
        add_action( 'save_post', array( $this, 'save' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
    }

    public function add(): void {
        $screen = get_current_screen();
        // The block editor uses the dedicated right sidebar. Keep the classic
        // meta box only as a fallback for Classic Editor / non-block editors.
        if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
            return;
        }
        foreach ( Utils::public_post_types() as $post_type ) {
            add_meta_box( 'aikey-seo-geo-meta', __( 'Aikeyboost SEO&GEO', 'aikey-seo-geo' ), array( $this, 'render' ), $post_type, 'normal', 'high' );
        }
    }

    public function assets( string $hook ): void {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, Utils::public_post_types(), true ) ) { return; }
        if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) { return; }
        wp_enqueue_style( 'aikey-seo-geo-admin', AIKEY_SEO_GEO_URL . 'assets/css/admin.css', array(), AIKEY_SEO_GEO_VERSION );
        wp_enqueue_script( 'aikey-seo-geo-meta', AIKEY_SEO_GEO_URL . 'assets/js/meta-box.js', array(), AIKEY_SEO_GEO_VERSION, true );
        wp_localize_script( 'aikey-seo-geo-meta', 'AIKEY_SEO_GEO', array(
            'restUrl' => esc_url_raw( rest_url( 'aikey-seo-geo/v1/' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'postId' => get_the_ID(),
        ) );
    }

    public function render( \WP_Post $post ): void {
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
        $values = array(
            'title' => Meta_Repository::title( $post->ID ),
            'description' => Meta_Repository::description( $post->ID ),
            'canonical' => Meta_Repository::canonical( $post->ID ),
            'index' => Meta_Repository::robots_index( $post->ID ),
            'follow' => Meta_Repository::robots_follow( $post->ID ),
            'keyword' => Meta_Repository::focus_keyword( $post->ID ),
            'core_keywords' => Meta_Repository::core_keywords( $post->ID ),
            'related_keywords' => Meta_Repository::get( $post->ID, Meta_Repository::RELATED_KEYWORDS ),
            'search_questions' => Meta_Repository::get( $post->ID, Meta_Repository::SEARCH_QUESTIONS ),
            'schema' => Meta_Repository::schema_type( $post->ID ),
            'entity' => Meta_Repository::get( $post->ID, Meta_Repository::GEO_ENTITY ),
            'entity_type' => Meta_Repository::get( $post->ID, Meta_Repository::GEO_ENTITY_TYPE ),
            'facts' => Meta_Repository::get( $post->ID, Meta_Repository::GEO_FACTS ),
            'faq' => Meta_Repository::get( $post->ID, Meta_Repository::GEO_FAQ ),
            'sources' => Meta_Repository::get( $post->ID, Meta_Repository::GEO_SOURCES ),
        );
        $geo = Scorer::score_post( $post );
        $ai = get_post_meta( $post->ID, Meta_Repository::AI_ANALYSIS, true );
        ?>
        <div class="aikey-meta-box" data-aikey-meta-box>
            <?php if ( Conflict_Detector::has_conflict() && ! Settings::get( 'force_takeover', false ) ) : ?>
                <div class="aikey-inline-notice aikey-inline-notice-warning"><strong>兼容保护：</strong>检测到 <?php echo esc_html( Conflict_Detector::summary() ); ?>。当前 Aikeyboost会保存数据，但不会重复输出前台 SEO 标签、Schema 或 Sitemap。</div>
            <?php endif; ?>
            <div class="aikey-tabs" data-aikey-tabs>
                <button type="button" class="aikey-tab is-active" data-tab="seo">SEO</button>
                <button type="button" class="aikey-tab" data-tab="geo">GEO</button>
                <button type="button" class="aikey-tab" data-tab="schema">Schema</button>
                <button type="button" class="aikey-tab" data-tab="ai">AI 分析</button>
            </div>
            <section class="aikey-tab-panel is-active" data-panel="seo">
                <div class="aikey-serp-preview"><span class="aikey-serp-label">搜索结果预览</span><div class="aikey-serp-url"><?php echo esc_html( (string) get_permalink( $post ) ); ?></div><div class="aikey-serp-title" data-aikey-preview-title data-fallback="<?php echo esc_attr( Meta_Repository::preview_title( $post ) ); ?>"><?php echo esc_html( Meta_Repository::preview_title( $post ) ); ?></div><div class="aikey-serp-description" data-aikey-preview-description data-fallback="<?php echo esc_attr( Meta_Repository::effective_description( $post ) ); ?>"><?php echo esc_html( Meta_Repository::effective_description( $post ) ); ?></div></div>
                <?php $this->text_field( 'aikey_seo_title', 'SEO 标题', $values['title'], '留空按全站模板生成。', 'text', 'data-aikey-title maxlength="300"' ); ?>
                <div class="aikey-field"><label><strong>Meta 描述</strong></label><textarea class="widefat" rows="4" name="aikey_seo_description" maxlength="500" data-aikey-description><?php echo esc_textarea( $values['description'] ); ?></textarea><p class="description">留空可从摘要/正文自动生成。<span class="aikey-counter"><b data-aikey-description-count>0</b> 字符</span></p></div>
                <?php $this->text_field( 'aikey_core_keywords', '核心关键词（1–3个）', $values['core_keywords'], '首个关键词作为主核心关键词参与诊断；仅用于内容规划与分析，不输出 meta keywords。' ); ?>
                <?php $this->text_field( 'aikey_related_keywords', '相关关键词（3–10个）', $values['related_keywords'], '覆盖同一主题下的相关表达，不自动堆入正文。' ); ?>
                <div class="aikey-field"><label><strong>用户搜索问题</strong></label><textarea class="widefat" rows="5" name="aikey_search_questions" placeholder="每行一个用户真实搜索/采购问题"><?php echo esc_textarea( $values['search_questions'] ); ?></textarea><p class="description">仅作为文章内容规划，不会在没有答案时生成 FAQ Schema。</p></div>
                <?php $this->text_field( 'aikey_seo_canonical', 'Canonical URL', $values['canonical'], '通常留空使用当前规范 URL。', 'url' ); ?>
                <div class="aikey-field-grid"><div><label><strong>索引</strong></label><select class="widefat" name="aikey_seo_robots_index"><option value="default" <?php selected( $values['index'], 'default' ); ?>>默认</option><option value="index" <?php selected( $values['index'], 'index' ); ?>>Index</option><option value="noindex" <?php selected( $values['index'], 'noindex' ); ?>>Noindex</option></select></div><div><label><strong>跟踪链接</strong></label><select class="widefat" name="aikey_seo_robots_follow"><option value="default" <?php selected( $values['follow'], 'default' ); ?>>默认</option><option value="follow" <?php selected( $values['follow'], 'follow' ); ?>>Follow</option><option value="nofollow" <?php selected( $values['follow'], 'nofollow' ); ?>>Nofollow</option></select></div></div>
            </section>
            <section class="aikey-tab-panel" data-panel="geo">
                <div class="aikey-score-row"><div class="aikey-score"><strong><?php echo esc_html( (string) $geo['score'] ); ?></strong><span>/100 GEO</span></div><div><?php foreach ( $geo['breakdown'] as $name => $score ) : ?><span class="aikey-chip"><?php echo esc_html( $name . ' ' . $score ); ?></span><?php endforeach; ?></div></div>
                <?php $this->text_field( 'aikey_geo_entity', '核心实体名称', $values['entity'], '例如：公司名、产品名、服务名。' ); ?>
                <div class="aikey-field"><label><strong>实体类型</strong></label><select class="widefat" name="aikey_geo_entity_type"><?php foreach ( array( ''=>'自动','Organization'=>'企业','Product'=>'产品','Service'=>'服务','Person'=>'人物','Place'=>'地点','Article'=>'文章' ) as $k=>$v ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $values['entity_type'], $k ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select></div>
                <div class="aikey-field"><label><strong>可验证核心事实</strong></label><textarea class="widefat" rows="5" name="aikey_geo_facts" placeholder="每行一条，例如：成立年份、资质、产能、服务区域、真实数据。"><?php echo esc_textarea( $values['facts'] ); ?></textarea><p class="description">填写后保存到当前页面Meta。若需公开展示，可在正文合适位置插入 <code>[aikey_geo_facts]</code>；不会自动重复附加。</p></div>
                <div class="aikey-field"><label><strong>FAQ</strong></label><textarea class="widefat" rows="6" name="aikey_geo_faq" placeholder="每行：问题 || 答案"><?php echo esc_textarea( $values['faq'] ); ?></textarea><p class="description">FAQ 可参与 GEO 规划；只有问题和答案真实出现在前台时才生成 FAQPage；如需自动展示后台填写的问答，可在正文插入 [aikey_geo_faq]。</p></div>
                <div class="aikey-field"><label><strong>来源 / 证据 URL</strong></label><textarea class="widefat" rows="4" name="aikey_geo_sources" placeholder="每行一个可公开验证的 URL"><?php echo esc_textarea( $values['sources'] ); ?></textarea><p class="description">需要公开展示证据链接时，可在正文插入 <code>[aikey_geo_sources]</code>。</p></div>
                <?php if ( ! empty( $geo['issues'] ) ) : ?><div class="aikey-issues"><strong>优先改进：</strong><ul><?php foreach ( array_slice( $geo['issues'], 0, 6 ) as $issue ) : ?><li><?php echo esc_html( $issue ); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            </section>
            <section class="aikey-tab-panel" data-panel="schema">
                <div class="aikey-field"><label><strong>页面 Schema 类型</strong></label><select class="widefat" name="aikey_schema_type"><?php foreach ( array( ''=>'自动判断','WebPage'=>'WebPage','Article'=>'Article','Product'=>'Product','Service'=>'Service','AboutPage'=>'AboutPage','ContactPage'=>'ContactPage' ) as $k=>$v ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $values['schema'], $k ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select><p class="description">WooCommerce 产品会优先自动识别为 Product。</p></div>
            </section>
            <section class="aikey-tab-panel" data-panel="ai">
                <p>使用 DeepSeek 对当前页面做 SEO + GEO 深度分析。需要先在「SEO&GEO → 基础设置 → DeepSeek AI」填写 API Key，或在 wp-config.php 定义 <code>AIKEY_DEEPSEEK_API_KEY</code>。</p>
                <button type="button" class="button button-primary" data-aikey-ai-analyze>开始 AI 深度分析</button><span class="spinner" data-aikey-ai-spinner></span>
                <div class="aikey-ai-result" data-aikey-ai-result><?php if ( is_array( $ai ) ) { echo '<pre>' . esc_html( wp_json_encode( $ai, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . '</pre>'; } ?></div>
            </section>
        </div>
        <?php
    }

    public function save( int $post_id, \WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }
        if ( ! in_array( $post->post_type, Utils::public_post_types(), true ) || ! isset( $_POST[ self::NONCE_NAME ] ) ) { return; }
        $nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) { return; }

        $map = array(
            'aikey_seo_title' => array( Meta_Repository::TITLE, 'text' ),
            'aikey_seo_description' => array( Meta_Repository::DESCRIPTION, 'textarea' ),
            'aikey_geo_entity' => array( Meta_Repository::GEO_ENTITY, 'text' ),
            'aikey_geo_facts' => array( Meta_Repository::GEO_FACTS, 'textarea' ),
            'aikey_geo_faq' => array( Meta_Repository::GEO_FAQ, 'textarea' ),
            'aikey_geo_sources' => array( Meta_Repository::GEO_SOURCES, 'textarea' ),
        );
        foreach ( $map as $field => $config ) {
            $raw = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
            $value = 'textarea' === $config[1] ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
            $this->save_or_delete( $post_id, $config[0], $value );
        }
        $core_raw = isset( $_POST['aikey_core_keywords'] ) && is_scalar( $_POST['aikey_core_keywords'] ) ? wp_unslash( (string) $_POST['aikey_core_keywords'] ) : '';
        $related_raw = isset( $_POST['aikey_related_keywords'] ) && is_scalar( $_POST['aikey_related_keywords'] ) ? wp_unslash( (string) $_POST['aikey_related_keywords'] ) : '';
        $questions_raw = isset( $_POST['aikey_search_questions'] ) && is_scalar( $_POST['aikey_search_questions'] ) ? wp_unslash( (string) $_POST['aikey_search_questions'] ) : '';
        $core = $this->sanitize_keywords( (string) $core_raw, 3 );
        $related = $this->sanitize_keywords( (string) $related_raw, 10 );
        $questions = $this->sanitize_lines( (string) $questions_raw, 10 );
        $this->save_or_delete( $post_id, Meta_Repository::CORE_KEYWORDS, implode( '，', $core ) );
        $this->save_or_delete( $post_id, Meta_Repository::FOCUS_KEYWORD, $core[0] ?? '' );
        $this->save_or_delete( $post_id, Meta_Repository::RELATED_KEYWORDS, implode( '，', $related ) );
        $this->save_or_delete( $post_id, Meta_Repository::SEARCH_QUESTIONS, implode( "\n", $questions ) );
        $canonical = isset( $_POST['aikey_seo_canonical'] ) && is_scalar( $_POST['aikey_seo_canonical'] ) ? esc_url_raw( wp_unslash( (string) $_POST['aikey_seo_canonical'] ), array( 'http','https' ) ) : '';
        $this->save_or_delete( $post_id, Meta_Repository::CANONICAL, $canonical );
        $this->allowed_value( $post_id, 'aikey_seo_robots_index', Meta_Repository::ROBOTS_INDEX, array( 'index','noindex' ) );
        $this->allowed_value( $post_id, 'aikey_seo_robots_follow', Meta_Repository::ROBOTS_FOLLOW, array( 'follow','nofollow' ) );
        $this->allowed_value( $post_id, 'aikey_schema_type', Meta_Repository::SCHEMA_TYPE, array( 'WebPage','Article','Product','Service','AboutPage','ContactPage' ) );
        $this->allowed_value( $post_id, 'aikey_geo_entity_type', Meta_Repository::GEO_ENTITY_TYPE, array( 'Organization','Product','Service','Person','Place','Article' ) );

    }

    private function text_field( string $name, string $label, string $value, string $help = '', string $type = 'text', string $attrs = '' ): void {
        echo '<div class="aikey-field"><label for="' . esc_attr( $name ) . '"><strong>' . esc_html( $label ) . '</strong></label><input class="widefat" type="' . esc_attr( $type ) . '" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        if ( '' !== $help ) { echo '<p class="description">' . esc_html( $help ) . '</p>'; }
        echo '</div>';
    }
    private function save_or_delete( int $post_id, string $key, string $value ): void { '' === trim( $value ) ? delete_post_meta( $post_id, $key ) : update_post_meta( $post_id, $key, $value ); }
    private function allowed_value( int $post_id, string $field, string $key, array $allowed ): void {
        $value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
        $this->save_or_delete( $post_id, $key, in_array( $value, $allowed, true ) ? $value : '' );
    }

    /** @return string[] */
    private function sanitize_keywords( string $value, int $limit ): array {
        $parts = preg_split( '/[\r\n,，;；、]+/u', $value, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
        $out = array();
        foreach ( $parts as $part ) {
            $item = trim( sanitize_text_field( (string) $part ) );
            if ( '' === $item ) { continue; }
            if ( function_exists( 'mb_substr' ) ) { $item = mb_substr( $item, 0, 80, 'UTF-8' ); } else { $item = substr( $item, 0, 240 ); }
            $out[ $item ] = $item;
            if ( count( $out ) >= $limit ) { break; }
        }
        return array_values( $out );
    }

    /** @return string[] */
    private function sanitize_lines( string $value, int $limit ): array {
        $parts = preg_split( '/\r\n|\r|\n/', $value ) ?: array();
        $out = array();
        foreach ( $parts as $part ) {
            $item = trim( sanitize_text_field( (string) $part ) );
            if ( '' === $item ) { continue; }
            if ( function_exists( 'mb_substr' ) ) { $item = mb_substr( $item, 0, 180, 'UTF-8' ); } else { $item = substr( $item, 0, 540 ); }
            $out[ $item ] = $item;
            if ( count( $out ) >= $limit ) { break; }
        }
        return array_values( $out );
    }
}
