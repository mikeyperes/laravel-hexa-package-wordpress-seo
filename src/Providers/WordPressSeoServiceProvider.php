<?php

namespace hexa_package_wordpress_seo\Providers;

use hexa_package_wordpress_seo\Console\WordPressImageMetaCommand;
use hexa_package_wordpress_seo\Console\WordPressSeoSiteCommand;
use hexa_package_wordpress_seo\Services\Indexability\PublicUrlInspector;
use hexa_package_wordpress_seo\Services\Indexability\RobotsTxtPolicy;
use hexa_package_wordpress_seo\Services\Indexability\SitemapManifestBuilder;
use hexa_package_wordpress_seo\Services\RankMathPageService;
use hexa_package_wordpress_seo\Services\WordPressImageMetaService;
use hexa_package_wordpress_seo\Services\WordPressSeoDiscoveryService;
use hexa_package_wordpress_seo\Services\WordPressSeoInternalLinkService;
use hexa_package_wordpress_seo\Services\WordPressSiteIndexabilityService;
use Illuminate\Support\ServiceProvider;

class WordPressSeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . "/../../config/wordpress-seo.php", "wordpress-seo");

        foreach ([
            WordPressSeoDiscoveryService::class,
            RankMathPageService::class,
            WordPressSiteIndexabilityService::class,
            PublicUrlInspector::class,
            RobotsTxtPolicy::class,
            SitemapManifestBuilder::class,
            WordPressSeoInternalLinkService::class,
            WordPressImageMetaService::class,
        ] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . "/../../database/migrations");

        if ($this->app->runningInConsole()) {
            $this->commands([
                WordPressSeoSiteCommand::class,
                WordPressImageMetaCommand::class,
            ]);
        }
    }
}
