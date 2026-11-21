<?php
namespace AIKEY\SeoGeo\Core;

use AIKEY\SeoGeo\SEO\Meta\Meta_Module;
use AIKEY\SeoGeo\SEO\Sitemap\Sitemap_Module;
use AIKEY\SeoGeo\SEO\Schema\Schema_Module;
use AIKEY\SeoGeo\SEO\Redirects\Redirect_Module;
use AIKEY\SeoGeo\SEO\Audit\Audit_Module;
use AIKEY\SeoGeo\GEO\GEO_Module;
use AIKEY\SeoGeo\GEO\Llms_Module;
use AIKEY\SeoGeo\AI\AI_Module;
use AIKEY\SeoGeo\Analytics\Referral_Module;
use AIKEY\SeoGeo\Analytics\Crawler_Monitor_Module;
use AIKEY\SeoGeo\Analytics\Baidu_Analytics_Module;
use AIKEY\SeoGeo\Integrations\Baidu_Module;
use AIKEY\SeoGeo\Integrations\China_Engine_Module;
use AIKEY\SeoGeo\Integrations\IndexNow_Module;
use AIKEY\SeoGeo\Automation\Automation_Module;
use AIKEY\SeoGeo\SEO\InternalLinks\Internal_Links_Module;
use AIKEY\SeoGeo\Maps\Enterprise_Map_Module;

defined( 'ABSPATH' ) || exit;

final class Module_Manager {
    /** @var array<string, Module_Interface> */
    private array $modules = array();

    public function register(): void {
        $this->modules = array(
            'seo_meta'     => new Meta_Module(),
            'sitemap'      => new Sitemap_Module(),
            'schema'       => new Schema_Module(),
            'redirections' => new Redirect_Module(),
            'seo_audit'    => new Audit_Module(),
            'geo'          => new GEO_Module(),
            'llms'         => new Llms_Module(),
            'ai'           => new AI_Module(),
            'referrals'    => new Referral_Module(),
            'baidu'        => new Baidu_Module(),
            'china_engines'=> new China_Engine_Module(),
            'indexnow'     => new IndexNow_Module(),
            'crawler_monitor' => new Crawler_Monitor_Module(),
            'automation'    => new Automation_Module(),
            'internal_links'=> new Internal_Links_Module(),
            'enterprise_map' => new Enterprise_Map_Module(),
            'baidu_analytics' => new Baidu_Analytics_Module(),
        );

        foreach ( $this->modules as $id => $module ) {
            // Core capabilities are always active from v2.0.2 onward. Baidu
            // Analytics is the only third-party tracking integration that keeps
            // its own explicit enable switch in “站点验证与统计”.
            if ( 'baidu_analytics' === $id && ! Settings::module_enabled( 'baidu_analytics' ) ) { continue; }
            $module->register();
        }
        do_action( 'aikey_seo_geo_modules_registered', $this );
    }

    public function all(): array { return $this->modules; }
}
