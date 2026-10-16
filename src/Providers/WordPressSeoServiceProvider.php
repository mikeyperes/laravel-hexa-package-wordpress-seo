<?php

namespace hexa_package_wordpress_seo\Providers;

use hexa_package_wordpress_seo\Console\WordPressImageMetaCommand;
use hexa_package_wordpress_seo\Console\WordPressSeoConfigureCommand;
use hexa_package_wordpress_seo\Console\WordPressSeoFeaturedFolderCommand;
use hexa_package_wordpress_seo\Console\WordPressSeoSiteCommand;
use hexa_package_wordpress_seo\Services\FeaturedImageFolders;
use hexa_package_wordpress_seo\Services\Indexability\PublicUrlInspector;
use hexa_package_wordpress_seo\Services\Indexability\RobotsTxtPolicy;
use hexa_package_wordpress_seo\Services\Indexability\SitemapManifestBuilder;
use hexa_package_wordpress_seo\Services\PageStructureCheck;
use hexa_package_wordpress_seo\Services\RankMathPageService;
use hexa_package_wordpress_seo\Services\WordPressImageMetaService;
use hexa_package_wordpress_seo\Services\WordPressSeoDiscoveryService;
use hexa_package_wordpress_seo\Services\WordPressSeoInternalLinkService;
use hexa_package_wordpress_seo\Services\WordPressSiteIndexabilityService;
use hexa_package_wordpress_seo\Settings\WordPressSeoSettings;
use Illuminate\Support\ServiceProvider;

class WordPressSeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . "/../../config/wordpress-seo.php", "wordpress-seo");

        foreach ([
            WordPressSeoDiscoveryService::class,
            RankMathPageService::class,
            PageStructureCheck::class,
            WordPressSiteIndexabilityService::class,
            PublicUrlInspector::class,
            RobotsTxtPolicy::class,
            SitemapManifestBuilder::class,
            WordPressSeoInternalLinkService::class,
            WordPressImageMetaService::class,
            WordPressSeoSettings::class,
            FeaturedImageFolders::class,
        ] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . "/../../database/migrations");
        $this->loadRoutesFrom(__DIR__ . "/../../routes/wordpress-seo.php");
        $this->loadViewsFrom(__DIR__ . "/../../resources/views", "wordpress-seo");

        if ($this->app->runningInConsole()) {
            $this->commands([
                WordPressSeoSiteCommand::class,
                WordPressImageMetaCommand::class,
                WordPressSeoConfigureCommand::class,
                WordPressSeoFeaturedFolderCommand::class,
            ]);
        }

        $this->registerWithPackageRegistry();
    }

    private function registerWithPackageRegistry(): void
    {
        if (! class_exists(\hexa_core\Services\PackageRegistryService::class)) {
            return;
        }
        $registry = app(\hexa_core\Services\PackageRegistryService::class);
        if (method_exists($registry, 'registerPackage')) {
            $registry->registerPackage('wordpress-seo', 'hexawebsystems/laravel-hexa-package-wordpress-seo', [
                'title' => 'WordPress SEO',
                'color' => 'blue',
                'description' => 'Live SEO view of one WordPress site for the HWS SEO skills, and the Drive folder for featured images.',
                'settingsRoute' => 'wordpress-seo.settings',
                'settingsShellClass' => 'max-w-4xl',
                'instructions' => [
                    'Paste the Featured images Google Drive folder link and click Test Folder.',
                    'Save. Each site gets a folder named after its domain inside it on its first featured image.',
                ],
            ]);
        }
    }
}
