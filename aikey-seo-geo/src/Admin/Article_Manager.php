<?php
namespace AIKEY\SeoGeo\Admin;

use AIKEY\SeoGeo\AI\DeepSeek_Client;
use AIKEY\SeoGeo\Core\Settings;
use AIKEY\SeoGeo\GEO\Scorer;
use AIKEY\SeoGeo\SEO\Audit\Audit_Module;
use AIKEY\SeoGeo\SEO\Meta\Meta_Repository;
use AIKEY\SeoGeo\SEO\Redirects\Redirect_Module;

 defined( 'ABSPATH' ) || exit;

/**
 * Article-focused publishing and editing workspace.
 *
 * Uses the native WordPress post record/post_content and the plugin's existing
 * SEO meta keys. It deliberately does not build a product-style front-end
 * template: the post title remains the page H1 and article body headings are
 * normalized to H2-H4 only.
 */
final class Article_Manager {
    private const PAGE_SLUG = 'aikey-seo-geo-articles';
    private const EDITOR_ID = 'aikey_article_content';
    private const HEADING_BACKUP = '_aikey_article_heading_backup';
    private const BLOCK_BACKUP = '_aikey_article_block_backup';
    private const BLOCK_CONVERTED = '_aikey_article_block_converted';

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'menu' ), 20 );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'admin_post_aikey_article_save', array( $this, 'handle_save' ) );
        add_action( 'admin_post_aikey_article_restore_blocks', array( $this, 'handle_restore_blocks' ) );
        add_action( 'wp_ajax_aikey_article_generate_slug', array( $this, 'ajax_generate_slug' ) );
        Article_Image_Importer::register_hooks();
        add_filter( 'tiny_mce_before_init', array( $this, 'tinymce_init' ), 30, 2 );
        add_filter( 'mce_external_plugins', array( $this, 'external_plugins' ), 30 );
    }

    public function menu(): void {
        add_submenu_page(
            'aikey-seo-geo',
            '发文管理',
            '发文管理',
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render' ),
            2
        );
    }

    public function assets( string $hook ): void {
        if ( false === strpos( $hook, self::PAGE_SLUG ) ) { return; }
        wp_enqueue_style( 'aikey-seo-geo-admin', AIKEY_SEO_GEO_URL . 'assets/css/admin.css', array(), AIKEY_SEO_GEO_VERSION );
        wp_enqueue_style( 'aikey-article-manager', AIKEY_SEO_GEO_URL . 'assets/css/article-manager.css', array( 'aikey-seo-geo-depth' ), AIKEY_SEO_GEO_VERSION );
        wp_enqueue_script( 'aikey-article-manager', AIKEY_SEO_GEO_URL . 'assets/js/article-manager.js', array( 'jquery' ), AIKEY_SEO_GEO_VERSION, true );
        wp_localize_script(
            'aikey-article-manager',
            'AIKEYArticleManager',
            array(
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'slugNonce'              => wp_create_nonce( 'aikey_article_generate_slug' ),
                'imageImportNonce'       => wp_create_nonce( Article_Image_Importer::NONCE_ACTION ),
                'imageImportAction'      => Article_Image_Importer::AJAX_ACTION,
                'imageImportMaxBytes'    => Article_Image_Importer::max_bytes(),
                'imageImportMaxPerPaste' => Article_Image_Importer::MAX_PER_PASTE,
                'imageImportMaxSession'  => Article_Image_Importer::MAX_PER_SESSION,
                'homeUrl'                => trailingslashit( home_url( '/' ) ),
                'permalinkStructure'     => (string) get_option( 'permalink_structure', '' ),
            )
        );
        wp_enqueue_media();
    }

    public function external_plugins( array $plugins ): array {
        if ( ! $this->is_editor_request() ) { return $plugins; }
        $plugins['aikey_article_heading_assistant'] = add_query_arg(
            'ver',
            rawurlencode( AIKEY_SEO_GEO_VERSION ),
            AIKEY_SEO_GEO_URL . 'assets/js/article-heading-assistant.js'
        );
        return $plugins;
    }

    public function tinymce_init( array $init, string $editor_id ): array {
        if ( self::EDITOR_ID !== $editor_id ) { return $init; }
        $init['paste_as_text'] = false;
        // Keep pasted clipboard images long enough for Article Manager JS to upload
        // them into Media Library and replace data/blob sources with local URLs.
        $init['paste_data_images'] = true;
        $init['paste_webkit_styles'] = 'all';
        $init['paste_retain_style_properties'] = 'all';
        $init['paste_remove_styles'] = false;
        $init['paste_remove_styles_if_webkit'] = false;
        $init['paste_strip_class_attributes'] = 'none';
        $init['paste_merge_formats'] = false;
        $init['paste_convert_word_fake_lists'] = true;
        $init['block_formats'] = '段落=p;标题 2=h2;标题 3=h3;标题 4=h4;预格式=pre';
        $init['paste_word_valid_elements'] = 'a[href|name|target|rel|style|class],p[style|class],div[style|class],span[style|class],h1[style|class],h2[style|class],h3[style|class],h4[style|class],h5[style|class],h6[style|class],strong/b,em/i,u,s,del,sub,sup,ul[style|class],ol[style|class|start|type],li[style|class],blockquote[style|class],table[style|class|width|border|cellpadding|cellspacing],thead,tbody,tfoot,tr[style|class],th[style|class|colspan|rowspan|width|height],td[style|class|colspan|rowspan|width|height],img[src|alt|width|height|style|class|title],br,hr';
        $init['font_formats'] = '系统默认字体=inherit;Arial=Arial,Helvetica,sans-serif;微软雅黑=Microsoft YaHei,PingFang SC,sans-serif;苹方=PingFang SC,Hiragino Sans GB,Microsoft YaHei,sans-serif;宋体=SimSun,serif;黑体=SimHei,sans-serif';
        return $init;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( '你没有权限管理文章。', 'aikey-seo-geo' ) );
        }
        $mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'list';
        if ( 'edit' === $mode ) {
            $this->render_editor();
            return;
        }
        $this->render_list();
    }

    private function render_list(): void {
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $status = isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( $_GET['post_status'] ) ) : 'all';
        $allowed_statuses = array( 'all', 'publish', 'draft', 'pending', 'private', 'future' );
        if ( ! in_array( $status, $allowed_statuses, true ) ) { $status = 'all'; }
        $paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $query = new \WP_Query(
            array(
                'post_type'      => 'post',
                'post_status'    => 'all' === $status ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : $status,
                'posts_per_page' => 20,
                'paged'          => $paged,
                's'              => $search,
                'orderby'        => 'modified',
                'order'          => 'DESC',
            )
        );
        $new_url = $this->editor_url();
        ?>
        <div class="wrap aikey-wrap aikey-article-list-wrap">
            <div class="aikey-page-head aikey-article-page-head">
                <div><span class="aikey-eyebrow">ARTICLE WORKSPACE</span><h1>发文管理</h1><p>用于 WordPress 文章的发布与编辑。文章标题作为页面唯一 H1；正文直接保存到原生 post_content，并与现有 Aikeyboost SEO/GEO 数据共用。</p></div>
                <a class="button button-primary button-large" href="<?php echo esc_url( $new_url ); ?>">发布新文章</a>
            </div>
            <?php Admin_Navigation::render( self::PAGE_SLUG ); ?>
            <?php $this->render_notice(); ?>
            <div class="aikey-panel aikey-article-filter-panel">
                <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="aikey-article-filter-form">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索文章标题或内容">
                    <select name="post_status">
                        <?php foreach ( array( 'all'=>'全部状态', 'publish'=>'已发布', 'draft'=>'草稿', 'pending'=>'待审', 'private'=>'私密', 'future'=>'定时' ) as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button">筛选</button>
                </form>
            </div>
            <div class="aikey-panel">
                <div class="aikey-panel-head"><div><h2>文章列表</h2><p>默认按最近修改时间排序；这里不会删除文章，避免误操作。</p></div><span class="aikey-version"><?php echo esc_html( (string) $query->found_posts ); ?> 篇</span></div>
                <table class="widefat fixed striped aikey-article-table">
                    <thead><tr><th>文章</th><th style="width:110px">状态</th><th style="width:150px">分类</th><th style="width:90px">SEO</th><th style="width:90px">GEO</th><th style="width:150px">最后修改</th><th style="width:220px">操作</th></tr></thead>
                    <tbody>
                    <?php if ( empty( $query->posts ) ) : ?>
                        <tr><td colspan="7">没有找到符合条件的文章。</td></tr>
                    <?php else : foreach ( $query->posts as $post ) :
                        $seo = get_post_meta( $post->ID, Meta_Repository::SEO_SCORE, true );
                        $geo = get_post_meta( $post->ID, Meta_Repository::GEO_SCORE, true );
                        $categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
                        $edit_url = $this->editor_url( (int) $post->ID );
                        $native_url = get_edit_post_link( $post->ID, '' );
                        $view_url = 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post );
                        ?>
                        <tr>
                            <td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( get_the_title( $post ) ?: '(无标题)' ); ?></a></strong><div class="aikey-table-sub">ID <?php echo esc_html( (string) $post->ID ); ?><?php if ( has_blocks( $post->post_content ) ) : ?> · 区块正文<?php endif; ?></div></td>
                            <td><?php echo esc_html( $this->status_label( $post->post_status ) ); ?></td>
                            <td><?php echo esc_html( $categories ? implode( '、', array_slice( $categories, 0, 3 ) ) : '—' ); ?></td>
                            <td><?php echo '' !== (string) $seo ? wp_kses_post( $this->score_badge( (int) $seo ) ) : '—'; ?></td>
                            <td><?php echo '' !== (string) $geo ? wp_kses_post( $this->score_badge( (int) $geo ) ) : '—'; ?></td>
                            <td><?php echo esc_html( get_the_modified_date( 'Y-m-d H:i', $post ) ); ?></td>
                            <td><a class="button button-small button-primary" href="<?php echo esc_url( $edit_url ); ?>">本工作台编辑</a> <?php if ( $native_url ) : ?><a class="button button-small" href="<?php echo esc_url( $native_url ); ?>">原生编辑器</a><?php endif; ?> <?php if ( $view_url ) : ?><a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( $view_url ); ?>">预览</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <?php $this->pagination( $query, $paged, $search, $status ); ?>
            </div>
        </div>
        <?php
        wp_reset_postdata();
    }

    private function render_editor(): void {
        $post_id = isset( $_GET['article_id'] ) ? absint( $_GET['article_id'] ) : 0;
        $post = $post_id ? get_post( $post_id ) : null;
        if ( $post && ( 'post' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) ) {
            wp_die( esc_html__( '无法编辑该文章。', 'aikey-seo-geo' ) );
        }
        if ( ! $post ) { $post_id = 0; }

        $title = $post ? (string) $post->post_title : '';
        $excerpt = $post ? (string) $post->post_excerpt : '';
        $featured_id = $post_id ? get_post_thumbnail_id( $post_id ) : 0;
        $selected_categories = $post_id ? wp_get_post_categories( $post_id ) : array();
        $tag_names = $post_id ? wp_get_post_tags( $post_id, array( 'fields' => 'names' ) ) : array();
        if ( is_wp_error( $tag_names ) ) { $tag_names = array(); }

        $block_backup = $post_id ? get_post_meta( $post_id, self::BLOCK_BACKUP, true ) : '';
        $block_protected = $post && has_blocks( (string) $post->post_content );
        $editor_content = $post ? (string) $post->post_content : '';
        if ( $block_protected ) {
            // Only a visual conversion preview. It is not written unless the user explicitly opts in.
            $editor_content = do_blocks( $editor_content );
        }
        $heading_backup = $post_id ? (string) get_post_meta( $post_id, self::HEADING_BACKUP, true ) : '';

        $seo_title = $post_id ? Meta_Repository::title( $post_id ) : '';
        $meta_description = $post_id ? Meta_Repository::description( $post_id ) : '';
        $core_keywords = $post_id ? Meta_Repository::core_keywords( $post_id ) : '';
        $related_keywords = $post_id ? Meta_Repository::get( $post_id, Meta_Repository::RELATED_KEYWORDS ) : '';
        $search_questions = $post_id ? Meta_Repository::get( $post_id, Meta_Repository::SEARCH_QUESTIONS ) : '';
        $canonical = $post_id ? Meta_Repository::canonical( $post_id ) : '';
        $article_slug = $post ? (string) $post->post_name : '';
        $published_url_locked = $post && in_array( (string) $post->post_status, array( 'publish', 'private' ), true );
        $redirections_enabled = Settings::module_enabled( 'redirections' );
        $ai_slug_available = Settings::module_enabled( 'ai' ) && '' !== trim( Settings::api_key() );
        $slug_needs_upgrade = '' !== $article_slug && ! self::is_clean_ascii_slug( $article_slug );
        // Never hide the stored post_name on an existing article. A published legacy
        // Chinese/encoded slug must remain visible while URL protection is locked.
        $slug_input_value = $article_slug;
        // Readonly attribute is emitted as a fixed literal, never interpolated.
        // Disabled attribute is emitted as a fixed literal, never interpolated.

        $categories = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
        if ( is_wp_error( $categories ) ) { $categories = array(); }
        ?>
        <div class="wrap aikey-wrap aikey-article-editor-wrap">
            <div class="aikey-page-head aikey-article-page-head">
                <div><span class="aikey-eyebrow">ARTICLE EDITOR</span><h1><?php echo esc_html( $post_id ? '编辑文章' : '发布新文章' ); ?></h1><p>面向文章页的简化发布工作台：标题为唯一 H1，正文使用 H2/H3/H4，SEO/GEO 规划数据不作为传统 Meta Keywords 输出。</p></div>
                <a class="button" href="<?php echo esc_url( $this->list_url() ); ?>">返回文章列表</a>
            </div>
            <?php Admin_Navigation::render( self::PAGE_SLUG ); ?>
            <?php $this->render_notice(); ?>
            <?php if ( $block_protected ) : ?>
                <div class="notice notice-warning aikey-article-block-warning"><p><strong>检测到该文章使用 Gutenberg 区块结构。</strong> 为避免破坏现有布局，本页默认不会覆盖正文；你仍可安全编辑标题、摘要、分类、标签、主图和 SEO/GEO 设置。若确实要改用下方富文本正文，请在右侧勾选“转换并接管区块正文”。系统会先备份原始区块源码，再保存为经典 HTML。</p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="aikey-article-form" class="aikey-article-form">
                <input type="hidden" name="action" value="aikey_article_save">
                <input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
                <?php wp_nonce_field( 'aikey_article_save_' . $post_id, 'aikey_article_nonce' ); ?>
                <input type="hidden" id="aikey_article_imported_media_ids" name="imported_media_ids" value="">
                <div class="aikey-article-grid">
                    <main>
                        <section class="aikey-panel aikey-article-basics">
                            <h2>文章基本信息</h2>
                            <div class="aikey-field"><label for="aikey_article_title"><strong>文章标题</strong> <span class="required">*</span></label><input required maxlength="200" class="large-text" type="text" id="aikey_article_title" name="article_title" value="<?php echo esc_attr( $title ); ?>" placeholder="请输入清晰、准确的文章标题"><p class="description">文章标题通常由主题作为页面唯一 H1 输出；正文不要再写 H1。</p></div>
                            <div class="aikey-field aikey-article-slug-field" data-aikey-slug-locked="<?php echo $published_url_locked ? '1' : '0'; ?>" data-aikey-slug-auto="<?php echo ( ! $article_slug || $slug_needs_upgrade ) ? '1' : '0'; ?>">
                                <label for="aikey_article_slug"><strong>文章链接（英文 Slug）</strong></label>
                                <div class="aikey-article-slug-row">
                                    <input type="text" id="aikey_article_slug" name="article_slug" maxlength="80" autocomplete="off" spellcheck="false" value="<?php echo esc_attr( $slug_input_value ); ?>" placeholder="例如：enterprise-website-value-ai-era"<?php if ( $published_url_locked ) : ?> readonly="readonly"<?php endif; ?>>
                                    <button type="button" class="button" id="aikey_article_generate_slug"<?php if ( $published_url_locked ) : ?> disabled="disabled"<?php endif; ?>>根据标题智能生成</button>
                                </div>
                                <p class="description">推荐使用简短、有意义的英文单词，小写并以短横线分隔。新文章可根据标题自动准备候选，也可以点击按钮重新生成或手工修改。智能生成失败不会影响本页编辑和文章保存。</p>
                                <p class="aikey-article-slug-status<?php echo $slug_needs_upgrade ? ' is-warning' : ''; ?>" id="aikey_article_slug_status"><?php if ( $slug_needs_upgrade ) : ?>当前 Slug 含中文/编码字符，建议在发布前重新生成英文链接。<?php elseif ( ! $ai_slug_available ) : ?>DeepSeek AI 未启用或未配置 API Key，将使用本地语义规则兜底，也可手工输入英文 Slug。<?php endif; ?></p>
                                <p class="aikey-article-slug-preview" id="aikey_article_slug_preview"<?php echo $slug_input_value ? '' : ' hidden'; ?>>链接预览：<code><?php echo esc_html( $slug_input_value ? trailingslashit( home_url( '/' . $slug_input_value ) ) : '' ); ?></code></p>
                                <?php if ( $post_id ) : ?><p class="description">当前地址：<a href="<?php echo esc_url( (string) get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) get_permalink( $post_id ) ); ?></a></p><?php endif; ?>
                                <?php if ( $published_url_locked ) : ?>
                                    <?php if ( $redirections_enabled ) : ?>
                                        <label class="aikey-article-slug-unlock"><input type="checkbox" id="aikey_article_unlock_slug" name="confirm_published_slug_change" value="1"> <strong>我确认修改已发布文章 URL，并自动创建旧地址 → 新地址的 301 重定向</strong></label>
                                        <p class="description">已发布 URL 默认锁定。只有确有必要时再修改；保存成功后插件会为旧地址建立 301，降低历史收录和外链失效风险。</p>
                                    <?php else : ?>
                                        <p class="description"><strong>已发布文章 URL 已锁定。</strong>如确需修改，请先启用“404 / 重定向”模块，以便旧地址能安全 301 到新地址。</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="aikey-field"><label for="aikey_article_excerpt"><strong>文章摘要</strong></label><textarea id="aikey_article_excerpt" name="article_excerpt" rows="4" maxlength="1200" placeholder="可填写文章摘要；留空时主题/搜索插件可按正文生成。"><?php echo esc_textarea( $excerpt ); ?></textarea></div>
                        </section>

                        <section class="aikey-panel aikey-article-editor-card">
                            <h2>文章详细内容</h2>
                            <p class="description">可从 Word、网页或其他富文本编辑器复制粘贴。系统尽量保留安全的 H2/H3/H4、段落、列表、表格、颜色、字号、对齐和图片格式；<strong>粘贴的剪贴板图片会自动上传到 WordPress 媒体库，外部网页图片会在通过安全校验后下载到媒体库，并自动替换为本站图片地址</strong>。粘贴时移除来源字体族。点击“智能标题”时采用保守识别：先确定正文实际最高标题层级，并保证正文标题从 H2 开始；Word Heading 2 若是粘贴正文中的最高层，会按 H2 处理。编号、加粗、字号和上下文只作为组合证据；连续编号列表、步骤、表格/引用内容及不确定短句会自动跳过；不会生成 H1。</p>
                            <?php $this->render_rich_editor( $editor_content, $heading_backup ); ?>
                        </section>

                        <section class="aikey-panel aikey-article-seogeo-card">
                            <h2>SEO/GEO内容设置（后台专用）</h2>
                            <p class="description">针对文章选题、搜索意图与后续 SEO/GEO 检测使用。核心关键词建议 1–3 个，相关关键词建议 3–10 个，用户搜索问题每行一个。它们不会自动输出传统 Meta Keywords，也不会自动堆入正文；“用户搜索问题”只是内容规划，不会在没有答案时生成 FAQ Schema。</p>
                            <div class="aikey-field"><label for="aikey_article_seo_title"><strong>SEO 标题</strong></label><input maxlength="300" type="text" id="aikey_article_seo_title" name="seo_title" value="<?php echo esc_attr( $seo_title ); ?>" placeholder="留空则继续使用插件全站标题模板"><p class="description">建议准确概括文章主题，不要为了关键词密度牺牲可读性。<span class="aikey-article-counter"><b data-aikey-count="seo-title">0</b> 字符</span></p></div>
                            <div class="aikey-field"><label for="aikey_article_meta_description"><strong>Meta 描述</strong></label><textarea maxlength="500" id="aikey_article_meta_description" name="meta_description" rows="4" placeholder="概括文章能解决什么问题、适合谁阅读、包含哪些关键信息"><?php echo esc_textarea( $meta_description ); ?></textarea><p class="description">留空时 Aikeyboost SEO&GEO 可按摘要/正文自动生成。<span class="aikey-article-counter"><b data-aikey-count="meta-description">0</b> 字符</span></p></div>
                            <div class="aikey-field-grid">
                                <div class="aikey-field"><label for="aikey_article_core_keywords"><strong>核心关键词（1–3个）</strong></label><input maxlength="500" type="text" id="aikey_article_core_keywords" name="core_keywords" value="<?php echo esc_attr( $core_keywords ); ?>" placeholder="例如：软糖代加工，软糖OEM，功能性软糖"><p class="description">首个关键词同步作为插件“主核心关键词”参与评分。当前 <b data-aikey-count="core-keywords">0</b> 个。</p></div>
                                <div class="aikey-field"><label for="aikey_article_related_keywords"><strong>相关关键词（3–10个）</strong></label><input maxlength="800" type="text" id="aikey_article_related_keywords" name="related_keywords" value="<?php echo esc_attr( $related_keywords ); ?>" placeholder="例如：果胶软糖，凝胶糖果，小批量定制"><p class="description">用于覆盖同一主题下的相关表达。当前 <b data-aikey-count="related-keywords">0</b> 个。</p></div>
                            </div>
                            <div class="aikey-field"><label for="aikey_article_search_questions"><strong>用户搜索问题（最多10个）</strong></label><textarea id="aikey_article_search_questions" name="search_questions" rows="7" placeholder="每行一个真实问题，例如：&#10;软糖代加工一般最低起订量是多少？&#10;果胶软糖和明胶软糖有什么区别？&#10;软糖OEM打样需要多长时间？"><?php echo esc_textarea( $search_questions ); ?></textarea><p class="description">建议优先记录采购、比较、决策阶段的真实问题。当前 <b data-aikey-count="search-questions">0</b> 个。</p></div>
                            <div class="aikey-field"><label for="aikey_article_canonical"><strong>Canonical URL（高级，可选）</strong></label><input maxlength="2048" type="url" id="aikey_article_canonical" name="canonical" value="<?php echo esc_attr( $canonical ); ?>" placeholder="通常留空使用当前文章 URL"><p class="description">只有确实存在重复/主版本关系时再填写，不建议普通文章随意修改。</p></div>
                        </section>
                    </main>

                    <aside>
                        <section class="aikey-panel aikey-article-publish-panel">
                            <h2>发布设置</h2>
                            <div class="aikey-field"><label for="aikey_article_status"><strong>保存状态</strong></label><select id="aikey_article_status" name="post_status">
                                <?php if ( $post_id && $post ) : ?><option value="keep" selected>保持当前状态（<?php echo esc_html( $this->status_label( $post->post_status ) ); ?>）</option><?php endif; ?>
                                <option value="draft">草稿</option><option value="pending">待审</option>
                                <?php if ( current_user_can( 'publish_posts' ) ) : ?><option value="publish">发布</option><option value="private">私密发布</option><?php endif; ?>
                            </select></div>
                            <div class="aikey-field"><label><strong>文章分类</strong></label><div class="aikey-article-tax-box">
                                <?php foreach ( $categories as $category ) : ?><label><input type="checkbox" name="category_ids[]" value="<?php echo esc_attr( (string) $category->term_id ); ?>" <?php checked( in_array( (int) $category->term_id, array_map( 'intval', $selected_categories ), true ) ); ?>> <?php echo esc_html( $category->name ); ?></label><?php endforeach; ?>
                            </div></div>
                            <div class="aikey-field"><label for="aikey_article_tags"><strong>文章标签</strong></label><textarea id="aikey_article_tags" name="article_tags" rows="4" placeholder="多个标签可用逗号、顿号或换行分隔"><?php echo esc_textarea( implode( '，', array_map( 'strval', $tag_names ) ) ); ?></textarea><p class="description">只设置与文章主题真实相关的标签，不建议堆叠。</p></div>
                            <div class="aikey-field"><label><strong>文章主图</strong></label><input type="hidden" name="featured_id" id="aikey_article_featured_id" value="<?php echo esc_attr( (string) $featured_id ); ?>"><div id="aikey_article_featured_preview" class="aikey-article-featured-preview"><?php if ( $featured_id ) { echo wp_kses_post( wp_get_attachment_image( $featured_id, 'medium' ) ); } ?></div><div class="aikey-article-media-actions"><button type="button" class="button" id="aikey_article_featured_pick">选择主图</button><button type="button" class="button" id="aikey_article_featured_clear">清除</button></div></div>
                            <?php if ( $block_protected ) : ?><div class="aikey-article-takeover"><label><input type="checkbox" name="confirm_block_conversion" value="1"> <strong>转换并接管区块正文</strong></label><p>勾选后才会把左侧富文本正文保存进文章；系统会先备份原 Gutenberg 区块源码。</p></div><?php endif; ?>
                            <p><button type="submit" class="button button-primary button-large aikey-article-save-button"><?php echo esc_html( $post_id ? '保存文章' : '创建文章' ); ?></button></p>
                            <?php if ( $post_id && $post ) : ?>
                                <div class="aikey-article-secondary-actions">
                                    <?php $view_url = 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post ); ?>
                                    <?php if ( $view_url ) : ?><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $view_url ); ?>">预览前台</a><?php endif; ?>
                                    <?php $native = get_edit_post_link( $post_id, '' ); if ( $native ) : ?><a class="button" href="<?php echo esc_url( $native ); ?>">原生编辑器</a><?php endif; ?>
                                </div>
                                <?php if ( is_array( $block_backup ) && ! empty( $block_backup['post_content'] ) ) :
                                    $restore = wp_nonce_url( admin_url( 'admin-post.php?action=aikey_article_restore_blocks&post_id=' . $post_id ), 'aikey_article_restore_blocks_' . $post_id ); ?>
                                    <p><a class="button button-secondary" href="<?php echo esc_url( $restore ); ?>" onclick="return confirm('确认恢复接管前备份的 Gutenberg 区块正文？当前文章正文将被替换。');">恢复原区块正文</a></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </section>
                    </aside>
                </div>
            </form>
        </div>
        <?php
    }

    private function render_rich_editor( string $content, string $saved_backup ): void {
        echo '<div class="aikey-article-heading-tools">';
        echo '<button type="button" class="button button-secondary" id="aikey_article_auto_headings">一键识别并设置 H2/H3/H4</button>';
        echo '<button type="button" class="button" id="aikey_article_restore_headings"' . ( '' === $saved_backup ? ' disabled' : '' ) . '>恢复整理前内容</button>';
        echo '<span class="description">文章标题是 H1；正文只使用 H2/H3/H4，并确保正文最高标题从 H2 开始。智能整理遵循“宁可漏判、不误判”，不会改写正文文字，也不会把连续编号列表/步骤强制变成标题。</span>';
        echo '</div>';
        echo '<textarea id="aikey_article_heading_backup" name="heading_backup" hidden></textarea>';
        echo '<textarea id="aikey_article_heading_saved_backup" hidden>' . esc_textarea( $saved_backup ) . '</textarea>';
        echo '<p id="aikey_article_image_import_status" class="aikey-article-image-import-status" aria-live="polite">粘贴图片后会自动保存到媒体库并替换为本站图片地址；单图最大 ' . esc_html( size_format( Article_Image_Importer::max_bytes() ) ) . '。</p>';
        wp_editor(
            $content,
            self::EDITOR_ID,
            array(
                'textarea_name' => 'article_content',
                'media_buttons' => true,
                'teeny'         => false,
                'quicktags'     => true,
                'textarea_rows' => 26,
                'editor_height' => 620,
                'drag_drop_upload' => true,
                'tinymce'       => array(
                    'toolbar1' => 'formatselect,aikey_article_heading_assistant,bold,italic,underline,strikethrough,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,forecolor,removeformat,undo,redo',
                    'toolbar2' => 'styleselect,fontselect,fontsizeselect,backcolor,outdent,indent,hr,charmap,pastetext',
                    'wpautop' => true,
                    'paste_as_text' => false,
                ),
            )
        );
    }

    public function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( '你没有权限保存文章。', 'aikey-seo-geo' ) );
        }
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $nonce = isset( $_POST['aikey_article_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['aikey_article_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'aikey_article_save_' . $post_id ) ) {
            wp_die( esc_html__( '安全校验失败，请刷新页面后重试。', 'aikey-seo-geo' ) );
        }
        $existing = $post_id ? get_post( $post_id ) : null;
        if ( $existing && ( 'post' !== $existing->post_type || ! current_user_can( 'edit_post', $post_id ) ) ) {
            wp_die( esc_html__( '无法编辑该文章。', 'aikey-seo-geo' ) );
        }
        if ( ! $existing ) { $post_id = 0; }

        $title = isset( $_POST['article_title'] ) && is_scalar( $_POST['article_title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['article_title'] ) ) : '';
        $title = self::limit_string( $title, 200 );
        if ( '' === $title ) { wp_die( esc_html__( '文章标题不能为空。', 'aikey-seo-geo' ) ); }

        $slug_raw = isset( $_POST['article_slug'] ) && is_scalar( $_POST['article_slug'] ) ? wp_unslash( (string) $_POST['article_slug'] ) : '';
        $slug = self::sanitize_article_slug( $slug_raw );
        $published_url_locked = $existing && in_array( (string) $existing->post_status, array( 'publish', 'private' ), true );
        $confirm_published_slug_change = ! empty( $_POST['confirm_published_slug_change'] );
        if ( $published_url_locked && ! $confirm_published_slug_change ) {
            $slug = (string) $existing->post_name;
        } elseif ( $published_url_locked && $confirm_published_slug_change && ! Settings::module_enabled( 'redirections' ) ) {
            wp_die( esc_html__( '为了保护已收录 URL，修改已发布文章链接前必须先启用 404 / 重定向模块。', 'aikey-seo-geo' ) );
        }
        $raw_is_existing_slug = $existing && $slug_raw === (string) $existing->post_name;
        if ( '' !== trim( $slug_raw ) && '' === $slug && ! $raw_is_existing_slug ) {
            wp_die( esc_html__( '文章 Slug 只能使用英文字母、数字和短横线，例如 enterprise-website-value-ai-era。', 'aikey-seo-geo' ) );
        }

        $requested_status = isset( $_POST['post_status'] ) && is_scalar( $_POST['post_status'] ) ? sanitize_key( wp_unslash( (string) $_POST['post_status'] ) ) : ( $post_id ? 'keep' : 'draft' );
        if ( ! in_array( $requested_status, array( 'keep', 'draft', 'pending', 'publish', 'private' ), true ) ) { $requested_status = $post_id ? 'keep' : 'draft'; }
        if ( ! $post_id && 'keep' === $requested_status ) { $requested_status = 'draft'; }
        if ( in_array( $requested_status, array( 'publish', 'private' ), true ) && ! current_user_can( 'publish_posts' ) ) { $requested_status = $post_id ? 'keep' : 'draft'; }

        $can_generate_slug = ! $published_url_locked || $confirm_published_slug_change;
        $effective_status = 'keep' === $requested_status && $existing ? (string) $existing->post_status : $requested_status;
        if ( $can_generate_slug && '' !== $slug ) { $slug = self::unique_article_slug( $slug, $post_id ); }

        // Never call a remote AI service from the article save/publish transaction.
        // A slow, unavailable or incompatible provider must not make wp-admin publishing
        // unstable. The AJAX button may use AI, while the save path only uses a local,
        // deterministic fallback and validates the final value server-side.
        if ( $can_generate_slug && '' === $slug && in_array( $effective_status, array( 'publish', 'private' ), true ) ) {
            $slug = self::fallback_slug_from_title( $title, $post_id );
        }
        if ( $can_generate_slug && in_array( $effective_status, array( 'publish', 'private' ), true ) && '' === $slug ) {
            wp_die( esc_html__( '发布前未能生成有效的英文文章链接。请点击“根据标题智能生成”，或手工填写英文 Slug 后再保存。', 'aikey-seo-geo' ) );
        }

        $old_permalink = $existing ? (string) get_permalink( $existing ) : '';
        $old_slug = $existing ? (string) $existing->post_name : '';
        $published_slug_changed = $published_url_locked && $confirm_published_slug_change && '' !== $slug && $slug !== $old_slug;

        $excerpt_raw = isset( $_POST['article_excerpt'] ) && is_scalar( $_POST['article_excerpt'] ) ? wp_unslash( (string) $_POST['article_excerpt'] ) : '';
        $excerpt = self::limit_string( sanitize_textarea_field( $excerpt_raw ), 1200 );
        $content_raw = isset( $_POST['article_content'] ) && is_scalar( $_POST['article_content'] ) ? wp_unslash( (string) $_POST['article_content'] ) : '';
        // Never trust a client-side flag for Gutenberg protection. Inspect the stored article source server-side.
        $block_protected = $existing && has_blocks( (string) $existing->post_content );
        $confirm_conversion = ! empty( $_POST['confirm_block_conversion'] );
        $save_content = ! $block_protected || $confirm_conversion;
        $content = $save_content ? self::sanitize_article_content( $content_raw ) : ( $existing ? (string) $existing->post_content : '' );

        if ( ! $post_id ) {
            $insert = array(
                'post_type'    => 'post',
                'post_status'  => 'draft',
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_excerpt' => $excerpt,
                'post_content' => $content,
                'post_author'  => get_current_user_id(),
            );
            $result = wp_insert_post( wp_slash( $insert ), true );
            if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
            $post_id = (int) $result;
        } else {
            if ( $block_protected && $confirm_conversion && ! metadata_exists( 'post', $post_id, self::BLOCK_BACKUP ) ) {
                update_post_meta(
                    $post_id,
                    self::BLOCK_BACKUP,
                    array(
                        'post_content'   => (string) $existing->post_content,
                        'saved_at'       => current_time( 'mysql' ),
                        'plugin_version' => AIKEY_SEO_GEO_VERSION,
                    )
                );
            }
            $update = array( 'ID' => $post_id, 'post_title' => $title, 'post_excerpt' => $excerpt );
            if ( $can_generate_slug && '' !== $slug ) { $update['post_name'] = $slug; }
            if ( $save_content ) { $update['post_content'] = $content; }
            $result = wp_update_post( wp_slash( $update ), true );
            if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
            if ( $block_protected && $confirm_conversion ) { update_post_meta( $post_id, self::BLOCK_CONVERTED, '1' ); }

        }

        $heading_backup_raw = isset( $_POST['heading_backup'] ) && is_scalar( $_POST['heading_backup'] ) ? wp_unslash( (string) $_POST['heading_backup'] ) : '';
        $heading_backup = '' !== trim( $heading_backup_raw ) ? self::sanitize_article_content( $heading_backup_raw ) : '';
        if ( $save_content && '' !== $heading_backup ) { update_post_meta( $post_id, self::HEADING_BACKUP, $heading_backup ); }

        $imported_media_ids = isset( $_POST['imported_media_ids'] ) && is_scalar( $_POST['imported_media_ids'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['imported_media_ids'] ) )
            : '';
        Article_Image_Importer::attach_pending_to_post( $post_id, $imported_media_ids );

        $this->save_taxonomies( $post_id );
        $this->save_featured_image( $post_id );
        $this->save_seo_geo( $post_id );

        // Apply an explicit status change only after taxonomy, media and SEO/GEO metadata are saved.
        // This ensures publish-time hooks see the final article metadata rather than stale values.
        if ( 'keep' !== $requested_status ) {
            $current_status = get_post_status( $post_id );
            if ( $current_status !== $requested_status ) {
                $status_result = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_status' => $requested_status ) ), true );
                if ( is_wp_error( $status_result ) ) { wp_die( esc_html( $status_result->get_error_message() ) ); }
            }
        }

        // Build the old -> new redirect only after taxonomy/status saves, so the
        // target reflects the final permalink even on sites using %category%.
        $url_change_failed = false;
        if ( $published_slug_changed ) {
            $new_permalink = (string) get_permalink( $post_id );
            $source_path = (string) wp_parse_url( $old_permalink, PHP_URL_PATH );
            if ( '' === $source_path || '' === $new_permalink || $new_permalink === $old_permalink ) {
                $revert = wp_update_post( wp_slash( array( 'ID'=>$post_id, 'post_name'=>$old_slug ) ), true );
                if ( is_wp_error( $revert ) ) {
                    wp_die( esc_html__( '新旧固定链接无法安全区分，并且自动恢复原 Slug 失败。为避免 URL 数据不一致，请立即检查该文章。', 'aikey-seo-geo' ) );
                }
                $published_slug_changed = false;
                $url_change_failed = true;
            } else {
                try {
                    $redirect = Redirect_Module::add( $source_path, $new_permalink, 301 );
                } catch ( \Throwable $error ) {
                    $redirect = new \WP_Error( 'redirect_runtime_error', '重定向模块运行异常。' );
                }
                if ( is_wp_error( $redirect ) || false === $redirect ) {
                    $revert = wp_update_post( wp_slash( array( 'ID'=>$post_id, 'post_name'=>$old_slug ) ), true );
                    if ( is_wp_error( $revert ) ) {
                        wp_die( esc_html__( '301 重定向创建失败，并且自动恢复原 Slug 失败。为避免 URL 数据不一致，请立即检查该文章。', 'aikey-seo-geo' ) );
                    }
                    $published_slug_changed = false;
                    $url_change_failed = true;
                }
            }
        }

        $fresh = get_post( $post_id );
        if ( $fresh instanceof \WP_Post && 'publish' === $fresh->post_status ) {
            Audit_Module::persist( $fresh );
            Scorer::persist( $fresh );
        }

        $redirect_args = array( 'page'=>self::PAGE_SLUG, 'mode'=>'edit', 'article_id'=>$post_id, 'saved'=>'1' );
        if ( $published_slug_changed ) { $redirect_args['url_changed'] = '1'; }
        if ( $url_change_failed ) { $redirect_args['url_change_failed'] = '1'; }
        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public function ajax_generate_slug(): void {
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message'=>'权限不足。' ), 403 );
        }
        check_ajax_referer( 'aikey_article_generate_slug', 'nonce' );
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( $post_id && ( 'post' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) ) {
            wp_send_json_error( array( 'message'=>'无法编辑该文章。' ), 403 );
        }
        $title = isset( $_POST['title'] ) && is_scalar( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '';
        $title = self::limit_string( trim( $title ), 200 );
        if ( '' === $title ) { wp_send_json_error( array( 'message'=>'请先填写文章标题。' ), 400 ); }

        // The permalink helper is an optional enhancement. Isolate every provider/runtime
        // failure from wp-admin so a bad API response, HTTP stack issue or unexpected
        // Throwable can never turn the Article Manager screen into a WordPress critical error.
        $slug = '';
        $source = 'local';
        try {
            $generated = self::generate_smart_slug( $title, $post_id );
            if ( ! is_wp_error( $generated ) && '' !== $generated ) {
                $slug = $generated;
                $source = 'ai';
            }
        } catch ( \Throwable $error ) {
            // Do not expose provider internals, paths, stack traces or credentials to the browser.
            // The local fallback below keeps the feature usable when AI integration fails.
            $slug = '';
        }

        if ( '' === $slug ) {
            try {
                $slug = self::fallback_slug_from_title( $title, $post_id );
            } catch ( \Throwable $error ) {
                $slug = '';
            }
            $source = 'local';
        }

        if ( '' === $slug ) {
            $message = Settings::module_enabled( 'ai' ) && '' !== trim( Settings::api_key() )
                ? '智能生成暂时不可用，但不会影响文章编辑。请稍后重试，或直接手工填写英文 Slug。'
                : '本地规则无法可靠翻译该标题。请手工填写英文 Slug，或在 AI 设置中配置 DeepSeek 后再试。';
            wp_send_json_error( array( 'message'=>$message ), 422 );
        }

        wp_send_json_success(
            array(
                'slug'    => $slug,
                'source'  => $source,
                'message' => 'ai' === $source ? '已根据文章标题生成英文语义链接。' : '已使用本地安全规则生成英文链接，请人工确认。',
            )
        );
    }

    /** @return string|\WP_Error */
    private static function generate_smart_slug( string $title, int $post_id = 0 ): string|\WP_Error {
        $direct = self::sanitize_article_slug( $title );
        if ( ! preg_match( '/[\x{3400}-\x{9fff}]/u', $title ) && self::slug_has_meaningful_shape( $direct ) ) {
            return self::unique_article_slug( $direct, $post_id );
        }
        if ( ! Settings::module_enabled( 'ai' ) || '' === trim( Settings::api_key() ) ) { return new \WP_Error( 'ai_unavailable', 'DeepSeek AI 未启用或 API Key 未配置。' ); }
        $system = '你是 WordPress 企业官网的 URL Slug 编辑器。任务只是把中文或中英混合文章标题转换成简洁、准确、可读的英文 URL slug，不新增标题中不存在的主题或事实。只输出严格 JSON：{"slug":"..."}。规则：1) 只能使用小写英文字母、数字和短横线；2) 使用 3-8 个有实际语义的词，通常不超过 72 个字符；3) 删除 what/why/how/the/a/of/for 等不必要虚词，保留能表达主题的名词和限定词；4) GEO、SEO、AI、DeepSeek、Doubao、Qwen 等行业缩写/品牌使用其常见英文写法；5) 优先自然英文，不用中文拼音代替已有通用英文概念；6) 不加入标题没有的年份、地区、公司名、效果承诺或关键词；7) 词之间只用一个短横线。示例：企业网站建设价格受哪些因素影响？ -> enterprise-website-building-price-factors；AI时代企业官网还有价值吗？ -> enterprise-website-value-ai-era；GEO优化是什么？企业为什么重视AI搜索流量？ -> geo-optimization-ai-search-traffic；GEO优化是什么？企业为什么重视DeepSeek、豆包AI搜索流量？ -> geo-optimization-deepseek-doubao-ai-search-traffic。';
        $user = '文章标题：' . $title;
        $result = ( new DeepSeek_Client() )->analyze( $system, $user, array( 'thinking'=>'disabled', 'max_tokens'=>256, 'timeout'=>15, 'retry'=>false ) );
        if ( is_wp_error( $result ) ) { return $result; }
        $candidate = isset( $result['slug'] ) && is_scalar( $result['slug'] ) ? (string) $result['slug'] : '';
        $candidate = self::sanitize_article_slug( $candidate );
        if ( '' === $candidate || ! self::slug_has_meaningful_shape( $candidate ) ) {
            return new \WP_Error( 'invalid_slug', 'AI 未返回有效的英文 Slug。' );
        }
        return self::unique_article_slug( $candidate, $post_id );
    }

    private static function fallback_slug_from_title( string $title, int $post_id = 0 ): string {
        $title_lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title, 'UTF-8' ) : strtolower( $title );
        $candidates = array();
        $order = 0;

        if ( preg_match_all( '/[A-Za-z][A-Za-z0-9]{1,24}/', $title, $matches, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches[0] as $match ) {
                $normalized = strtolower( (string) $match[0] );
                if ( in_array( $normalized, array( 'and','or','the','with','what','why','how' ), true ) ) { continue; }
                $candidates[] = array( 'pos'=>(int) $match[1], 'order'=>$order++, 'tokens'=>array( $normalized ) );
            }
        }

        $phrases = array(
            '生成式人工智能'=>'generative-ai', '人工智能'=>'ai', '搜索引擎优化'=>'seo', '生成式引擎优化'=>'geo',
            '企业官网'=>'enterprise-website', '企业网站'=>'enterprise-website', '网站建设'=>'website-building', '官网建设'=>'website-building',
            '搜索流量'=>'search-traffic', '搜索结果'=>'search-results', '人工智能搜索'=>'ai-search', 'ai搜索'=>'ai-search',
            '内容营销'=>'content-marketing', '内容优化'=>'content-optimization', '关键词研究'=>'keyword-research', '关键词'=>'keywords',
            '品牌建设'=>'brand-building', '品牌'=>'brand', '获客'=>'customer-acquisition', '流量'=>'traffic', '搜索'=>'search',
            '网站'=>'website', '官网'=>'website', '企业'=>'enterprise', '公司'=>'company', '服务'=>'services', '价格'=>'price',
            '成本'=>'cost', '价值'=>'value', '因素'=>'factors', '案例'=>'case-study', '策略'=>'strategy', '方案'=>'solution',
            '优化'=>'optimization', '排名'=>'ranking', '收录'=>'indexing', '推荐'=>'recommendations', '内容'=>'content',
            '时代'=>'era', '豆包'=>'doubao', '通义千问'=>'qwen', '千问'=>'qwen', '元宝'=>'yuanbao', '文心'=>'ernie',
        );
        foreach ( $phrases as $phrase => $english ) {
            $pos = strpos( $title_lower, $phrase );
            if ( false === $pos ) { continue; }
            // In question-style titles such as “企业为什么重视…”, 企业 is the
            // audience/subject rather than a useful URL topic token. Avoid bloating a
            // fallback slug with this generic word while keeping enterprise in real
            // topic phrases such as 企业官网/企业网站.
            if ( '企业' === $phrase && false !== strpos( $title_lower, '企业为什么' ) ) { continue; }
            $candidates[] = array( 'pos'=>(int) $pos, 'order'=>$order++, 'tokens'=>explode( '-', $english ) );
        }
        usort(
            $candidates,
            static fn( array $a, array $b ): int => $a['pos'] === $b['pos'] ? $a['order'] <=> $b['order'] : $a['pos'] <=> $b['pos']
        );

        $tokens = array();
        $seen = array();
        foreach ( $candidates as $candidate ) {
            foreach ( (array) $candidate['tokens'] as $token ) {
                $token = trim( strtolower( (string) $token ), '-' );
                if ( '' === $token || isset( $seen[ $token ] ) ) { continue; }
                $seen[ $token ] = true;
                $tokens[] = $token;
                if ( count( $tokens ) >= 8 ) { break 2; }
            }
        }

        if ( count( $tokens ) < 2 ) { return ''; }
        $candidate = self::sanitize_article_slug( implode( '-', $tokens ) );
        if ( '' === $candidate || ! self::slug_has_meaningful_shape( $candidate ) ) { return ''; }
        return self::unique_article_slug( $candidate, $post_id );
    }

    private static function sanitize_article_slug( string $slug ): string {
        $slug = trim( wp_strip_all_tags( $slug ) );
        if ( preg_match( '#^https?://#i', $slug ) ) {
            $path = (string) wp_parse_url( $slug, PHP_URL_PATH );
            $slug = basename( untrailingslashit( $path ) );
        }
        $slug = strtolower( remove_accents( rawurldecode( $slug ) ) );
        $slug = preg_replace( '/[^a-z0-9]+/', '-', $slug ) ?? '';
        $slug = trim( preg_replace( '/-+/', '-', $slug ) ?? '', '-' );
        if ( strlen( $slug ) > 80 ) {
            $short = substr( $slug, 0, 80 );
            if ( str_contains( $short, '-' ) ) { $short = preg_replace( '/-[^-]*$/', '', $short ) ?: $short; }
            $slug = trim( $short, '-' );
        }
        return $slug;
    }

    private static function is_clean_ascii_slug( string $slug ): bool {
        $slug = rawurldecode( trim( $slug ) );
        return (bool) preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug );
    }

    private static function slug_has_meaningful_shape( string $slug ): bool {
        if ( strlen( $slug ) < 5 || strlen( $slug ) > 80 ) { return false; }
        $parts = array_values( array_filter( explode( '-', $slug ) ) );
        if ( ! $parts || count( $parts ) > 10 ) { return false; }
        $generic = array( 'post','article','news','blog','page','content' );
        if ( 1 === count( $parts ) ) { return strlen( $parts[0] ) >= 5 && ! in_array( $parts[0], $generic, true ); }
        return count( array_diff( $parts, $generic ) ) >= 2;
    }

    private static function unique_article_slug( string $slug, int $post_id = 0 ): string {
        if ( '' === $slug ) { return ''; }
        return (string) wp_unique_post_slug( $slug, $post_id, 'publish', 'post', 0 );
    }

    public function handle_restore_blocks(): void {
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_posts' ) ) { wp_die( esc_html__( '权限不足。', 'aikey-seo-geo' ) ); }
        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || 'post' !== get_post_type( $post_id ) ) { wp_die( esc_html__( '无法恢复该文章。', 'aikey-seo-geo' ) ); }
        check_admin_referer( 'aikey_article_restore_blocks_' . $post_id );
        $backup = get_post_meta( $post_id, self::BLOCK_BACKUP, true );
        if ( ! is_array( $backup ) || ! isset( $backup['post_content'] ) ) { wp_die( esc_html__( '没有可恢复的区块正文备份。', 'aikey-seo-geo' ) ); }
        $result = wp_update_post( wp_slash( array( 'ID'=>$post_id, 'post_content'=>(string) $backup['post_content'] ) ), true );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
        delete_post_meta( $post_id, self::BLOCK_CONVERTED );
        delete_post_meta( $post_id, self::BLOCK_BACKUP );
        delete_post_meta( $post_id, self::HEADING_BACKUP );
        wp_safe_redirect( add_query_arg( array( 'page'=>self::PAGE_SLUG, 'mode'=>'edit', 'article_id'=>$post_id, 'restored'=>'1' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private function save_taxonomies( int $post_id ): void {
        $raw_categories = isset( $_POST['category_ids'] ) && is_array( $_POST['category_ids'] ) ? wp_unslash( $_POST['category_ids'] ) : array();
        $category_ids = array_values( array_unique( array_filter( array_map( 'absint', array_slice( $raw_categories, 0, 100 ) ) ) ) );
        $category_ids = array_values( array_filter( $category_ids, static fn( int $id ): bool => (bool) term_exists( $id, 'category' ) ) );
        wp_set_post_categories( $post_id, $category_ids, false );

        $tags_raw = isset( $_POST['article_tags'] ) && is_scalar( $_POST['article_tags'] ) ? wp_unslash( (string) $_POST['article_tags'] ) : '';
        $tags = self::sanitize_keywords_array( $tags_raw, 30 );
        wp_set_post_tags( $post_id, $tags, false );
    }

    private function save_featured_image( int $post_id ): void {
        $featured = isset( $_POST['featured_id'] ) && is_scalar( $_POST['featured_id'] ) ? absint( $_POST['featured_id'] ) : 0;
        if ( $featured && self::is_safe_image( $featured ) ) { set_post_thumbnail( $post_id, $featured ); }
        elseif ( ! $featured ) { delete_post_thumbnail( $post_id ); }
    }

    private function save_seo_geo( int $post_id ): void {
        $seo_title = isset( $_POST['seo_title'] ) && is_scalar( $_POST['seo_title'] ) ? self::limit_string( sanitize_text_field( wp_unslash( (string) $_POST['seo_title'] ) ), 300 ) : '';
        $description = isset( $_POST['meta_description'] ) && is_scalar( $_POST['meta_description'] ) ? self::limit_string( sanitize_textarea_field( wp_unslash( (string) $_POST['meta_description'] ) ), 500 ) : '';
        $core_raw = isset( $_POST['core_keywords'] ) && is_scalar( $_POST['core_keywords'] ) ? wp_unslash( (string) $_POST['core_keywords'] ) : '';
        $related_raw = isset( $_POST['related_keywords'] ) && is_scalar( $_POST['related_keywords'] ) ? wp_unslash( (string) $_POST['related_keywords'] ) : '';
        $questions_raw = isset( $_POST['search_questions'] ) && is_scalar( $_POST['search_questions'] ) ? wp_unslash( (string) $_POST['search_questions'] ) : '';
        $canonical = isset( $_POST['canonical'] ) && is_scalar( $_POST['canonical'] ) ? esc_url_raw( wp_unslash( (string) $_POST['canonical'] ), array( 'http', 'https' ) ) : '';

        $core = self::sanitize_keywords_array( $core_raw, 3 );
        $related = self::sanitize_keywords_array( $related_raw, 10 );
        $questions = self::sanitize_lines_array( $questions_raw, 10 );
        self::save_or_delete_meta( $post_id, Meta_Repository::TITLE, $seo_title );
        self::save_or_delete_meta( $post_id, Meta_Repository::DESCRIPTION, $description );
        self::save_or_delete_meta( $post_id, Meta_Repository::CORE_KEYWORDS, implode( '，', $core ) );
        self::save_or_delete_meta( $post_id, Meta_Repository::FOCUS_KEYWORD, $core[0] ?? '' );
        self::save_or_delete_meta( $post_id, Meta_Repository::RELATED_KEYWORDS, implode( '，', $related ) );
        self::save_or_delete_meta( $post_id, Meta_Repository::SEARCH_QUESTIONS, implode( "\n", $questions ) );
        self::save_or_delete_meta( $post_id, Meta_Repository::CANONICAL, $canonical );

        if ( '' === Meta_Repository::schema_type( $post_id ) ) { update_post_meta( $post_id, Meta_Repository::SCHEMA_TYPE, 'Article' ); }
        if ( '' === Meta_Repository::get( $post_id, Meta_Repository::GEO_ENTITY_TYPE ) ) { update_post_meta( $post_id, Meta_Repository::GEO_ENTITY_TYPE, 'Article' ); }
    }

    private static function sanitize_article_content( string $content ): string {
        $clean = wp_kses_post( $content );
        // Article title is the page H1. Prevent duplicate H1 and overly deep heading levels.
        $clean = preg_replace( '/<h1\b([^>]*)>/i', '<h2$1>', $clean ) ?? $clean;
        $clean = preg_replace( '/<\/h1>/i', '</h2>', $clean ) ?? $clean;
        $clean = preg_replace( '/<h[56]\b([^>]*)>/i', '<h4$1>', $clean ) ?? $clean;
        $clean = preg_replace( '/<\/h[56]>/i', '</h4>', $clean ) ?? $clean;

        // The post title is the page H1, so if pasted/edited body content contains
        // headings but no H2, shift the whole body heading spectrum upward. This
        // preserves sibling relationships (H3/H3 -> H2/H2; H3/H4 -> H2/H3)
        // instead of promoting only the first heading and creating a false tree.
        if ( ! preg_match( '/<h2\b/i', $clean ) && preg_match_all( '/<h([34])\b/i', $clean, $matches ) ) {
            $levels = array_map( 'intval', $matches[1] ?? array() );
            $minimum = $levels ? min( $levels ) : 0;
            $shift = $minimum > 2 ? $minimum - 2 : 0;
            if ( $shift > 0 ) {
                $clean = preg_replace_callback(
                    '/<h([2-4])\b([^>]*)>/i',
                    static function ( array $match ) use ( $shift ): string {
                        $level = max( 2, min( 4, (int) $match[1] - $shift ) );
                        return '<h' . $level . $match[2] . '>';
                    },
                    $clean
                ) ?? $clean;
                $clean = preg_replace_callback(
                    '/<\/h([2-4])>/i',
                    static function ( array $match ) use ( $shift ): string {
                        $level = max( 2, min( 4, (int) $match[1] - $shift ) );
                        return '</h' . $level . '>';
                    },
                    $clean
                ) ?? $clean;
            }
        }
        return $clean;
    }

    /** @return string[] */
    private static function sanitize_keywords_array( string $value, int $limit ): array {
        $parts = preg_split( '/[\r\n,，;；、]+/u', $value, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
        $out = array();
        foreach ( $parts as $part ) {
            $item = self::limit_string( trim( sanitize_text_field( (string) $part ) ), 80 );
            if ( '' === $item ) { continue; }
            $out[ $item ] = $item;
            if ( count( $out ) >= max( 1, $limit ) ) { break; }
        }
        return array_values( $out );
    }

    /** @return string[] */
    private static function sanitize_lines_array( string $value, int $limit ): array {
        $parts = preg_split( '/\r\n|\r|\n/', $value ) ?: array();
        $out = array();
        foreach ( $parts as $part ) {
            $item = self::limit_string( trim( sanitize_text_field( (string) $part ) ), 180 );
            if ( '' === $item ) { continue; }
            $out[ $item ] = $item;
            if ( count( $out ) >= max( 1, $limit ) ) { break; }
        }
        return array_values( $out );
    }

    private static function save_or_delete_meta( int $post_id, string $key, string $value ): void {
        '' === trim( $value ) ? delete_post_meta( $post_id, $key ) : update_post_meta( $post_id, $key, $value );
    }

    private static function is_safe_image( int $attachment_id ): bool {
        return 'attachment' === get_post_type( $attachment_id ) && 0 === strpos( (string) get_post_mime_type( $attachment_id ), 'image/' );
    }

    private static function limit_string( string $value, int $max ): string {
        if ( function_exists( 'mb_substr' ) ) { return mb_substr( $value, 0, $max, 'UTF-8' ); }
        return substr( $value, 0, max( $max, $max * 3 ) );
    }

    private function status_label( string $status ): string {
        $obj = get_post_status_object( $status );
        return $obj && ! empty( $obj->label ) ? (string) $obj->label : $status;
    }

    private function score_badge( int $score ): string {
        $class = $score >= 80 ? 'good' : ( $score >= 60 ? 'mid' : 'bad' );
        return '<span class="aikey-score-badge ' . esc_attr( $class ) . '">' . esc_html( (string) $score ) . '</span>';
    }

    private function list_url( array $extra = array() ): string {
        return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $extra ), admin_url( 'admin.php' ) );
    }

    private function editor_url( int $post_id = 0 ): string {
        $args = array( 'mode' => 'edit' );
        if ( $post_id ) { $args['article_id'] = $post_id; }
        return $this->list_url( $args );
    }

    private function is_editor_request(): bool {
        if ( ! is_admin() ) { return false; }
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
        return self::PAGE_SLUG === $page && 'edit' === $mode;
    }

    private function pagination( \WP_Query $query, int $paged, string $search, string $status ): void {
        if ( $query->max_num_pages <= 1 ) { return; }
        $base = add_query_arg(
            array( 'page'=>self::PAGE_SLUG, 's'=>$search, 'post_status'=>$status, 'paged'=>'%#%' ),
            admin_url( 'admin.php' )
        );
        $links = paginate_links( array( 'base'=>$base, 'format'=>'', 'current'=>$paged, 'total'=>(int) $query->max_num_pages, 'type'=>'list', 'prev_text'=>'‹', 'next_text'=>'›' ) );
        if ( $links ) { echo '<nav class="aikey-pagination" aria-label="文章分页">' . wp_kses_post( $links ) . '</nav>'; }
    }

    private function render_notice(): void {
        if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>文章及 SEO/GEO 设置已保存。</p></div>'; }
        if ( isset( $_GET['restored'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>已恢复接管前的 Gutenberg 区块正文。</p></div>'; }
        if ( isset( $_GET['url_changed'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>已更新文章固定链接，并为旧地址建立 301 重定向。</p></div>'; }
        if ( isset( $_GET['url_change_failed'] ) ) { echo '<div class="notice notice-warning is-dismissible"><p>文章其他内容已保存，但 URL 修改未能安全完成，系统已恢复原 Slug。请检查“404 / 重定向”模块后再试。</p></div>'; }
    }
}
