<?php
namespace AIKEY\SeoGeo\SEO\Meta;

defined( 'ABSPATH' ) || exit;

final class Term_Meta {
    public const TITLE = '_aikey_term_seo_title';
    public const DESCRIPTION = '_aikey_term_seo_description';
    public const ROBOTS_INDEX = '_aikey_term_robots_index';
    private const NONCE = 'aikey_term_seo_nonce';

    public function register(): void {
        add_action( 'init', array( $this, 'register_fields' ), 30 );
        add_action( 'created_term', array( $this, 'save' ), 10, 3 );
        add_action( 'edited_term', array( $this, 'save' ), 10, 3 );
    }

    public function register_fields(): void {
        $taxonomies = get_taxonomies( array( 'public'=>true ), 'names' );
        foreach ( $taxonomies as $taxonomy ) {
            if ( 'post_format' === $taxonomy ) { continue; }
            add_action( $taxonomy . '_add_form_fields', array( $this, 'add_fields' ) );
            add_action( $taxonomy . '_edit_form_fields', array( $this, 'edit_fields' ) );
        }
    }

    public function add_fields( string $taxonomy ): void {
        wp_nonce_field( 'aikey_term_seo_save', self::NONCE );
        ?>
        <div class="form-field"><label for="aikey_term_title">Aikeyboost SEO 标题</label><input type="text" name="aikey_term_title" id="aikey_term_title" maxlength="300"><p>留空使用分类/标签默认标题。</p></div>
        <div class="form-field"><label for="aikey_term_description">Aikeyboost Meta 描述</label><textarea name="aikey_term_description" id="aikey_term_description" rows="4"></textarea></div>
        <div class="form-field"><label for="aikey_term_index">索引</label><select name="aikey_term_index" id="aikey_term_index"><option value="default">默认</option><option value="index">Index</option><option value="noindex">Noindex</option></select></div>
        <?php
    }

    public function edit_fields( \WP_Term $term ): void {
        wp_nonce_field( 'aikey_term_seo_save', self::NONCE );
        $title = (string) get_term_meta( $term->term_id, self::TITLE, true );
        $desc = (string) get_term_meta( $term->term_id, self::DESCRIPTION, true );
        $index = (string) get_term_meta( $term->term_id, self::ROBOTS_INDEX, true );
        ?>
        <tr class="form-field"><th><label for="aikey_term_title">Aikeyboost SEO 标题</label></th><td><input type="text" name="aikey_term_title" id="aikey_term_title" value="<?php echo esc_attr( $title ); ?>" maxlength="300"></td></tr>
        <tr class="form-field"><th><label for="aikey_term_description">Aikeyboost Meta 描述</label></th><td><textarea name="aikey_term_description" id="aikey_term_description" rows="4"><?php echo esc_textarea( $desc ); ?></textarea></td></tr>
        <tr class="form-field"><th><label for="aikey_term_index">索引</label></th><td><select name="aikey_term_index" id="aikey_term_index"><option value="default" <?php selected( $index, '' ); ?>>默认</option><option value="index" <?php selected( $index, 'index' ); ?>>Index</option><option value="noindex" <?php selected( $index, 'noindex' ); ?>>Noindex</option></select></td></tr>
        <?php
    }

    public function save( int $term_id, int $tt_id, string $taxonomy ): void {
        if ( ! isset( $_POST[ self::NONCE ] ) ) { return; }
        $nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) );
        if ( ! wp_verify_nonce( $nonce, 'aikey_term_seo_save' ) ) { return; }
        $tax = get_taxonomy( $taxonomy );
        if ( ! $tax || ! current_user_can( $tax->cap->edit_terms ) ) { return; }
        $title = isset( $_POST['aikey_term_title'] ) ? sanitize_text_field( wp_unslash( $_POST['aikey_term_title'] ) ) : '';
        $desc = isset( $_POST['aikey_term_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['aikey_term_description'] ) ) : '';
        $index = isset( $_POST['aikey_term_index'] ) ? sanitize_key( wp_unslash( $_POST['aikey_term_index'] ) ) : 'default';
        $this->save_or_delete( $term_id, self::TITLE, $title );
        $this->save_or_delete( $term_id, self::DESCRIPTION, $desc );
        $this->save_or_delete( $term_id, self::ROBOTS_INDEX, in_array( $index, array( 'index','noindex' ), true ) ? $index : '' );
    }

    private function save_or_delete( int $term_id, string $key, string $value ): void {
        '' === trim( $value ) ? delete_term_meta( $term_id, $key ) : update_term_meta( $term_id, $key, $value );
    }
}
