<?php
namespace AIKEY\SeoGeo\SEO\Meta;

use AIKEY\SeoGeo\Core\Module_Interface;

defined( 'ABSPATH' ) || exit;

final class Meta_Module implements Module_Interface {
    public function id(): string {
        return 'seo_meta';
    }

    public function register(): void {
        ( new Meta_Box() )->register();
        ( new Editor_Sidebar() )->register();
        ( new Term_Meta() )->register();
        ( new Frontend() )->register();
    }
}
