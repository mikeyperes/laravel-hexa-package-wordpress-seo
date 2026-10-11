<?php

namespace hexa_package_wordpress_seo\Providers;

use hexa_package_wordpress_seo\Console\ProcessWordPressSeoScanCommand;
use hexa_package_wordpress_seo\Console\WordPressSeoSiteCommand;
use hexa_package_wordpress_seo\Services\SeoProposalFrameService;
use hexa_package_wordpress_seo\Services\SeoProposalStoreService;
use hexa_package_wordpress_seo\Services\SeoProviderRegistry;
use hexa_package_wordpress_seo\Services\SeoScanStoreService;
use hexa_package_wordpress_seo\Services\SupplementalUrlContextService;
use hexa_package_wordpress_seo\Services\Indexability\PublicUrlInspector;
use hexa_package_wordpress_seo\Services\Indexability\RobotsTxtPolicy;
use hexa_package_wordpress_seo\Services\Indexability\SitemapManifestBuilder;
use hexa_package_wordpress_seo\Services\WordPressSeoApplyService;
use hexa_package_wordpress_seo\Services\WordPressSeoInternalLinkService;
use hexa_package_wordpress_seo\Services\WordPressSeoBackgroundRunnerService;
use hexa_package_wordpress_seo\Services\WordPressSeoDiscoveryService;
use hexa_package_wordpress_seo\Services\WordPressSeoProposalService;
use hexa_package_wordpress_seo\Services\WordPressSeoScanProcessorService;
use hexa_package_wordpress_seo\Services\WordPressSeoScanService;
use hexa_package_wordpress_seo\Services\WordPressSeoScorePreviewService;
use hexa_package_wordpress_seo\Services\WordPressSiteIndexabilityService;
use Illuminate\Support\ServiceProvider;

class WordPressSeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . "/../../config/wordpress-seo.php", "wordpress-seo");

        $this->app->singleton(SeoProviderRegistry::class);
        $this->app->singleton(SupplementalUrlContextService::class);
        $this->app->singleton(WordPressSeoProposalService::class);
        $this->app->singleton(WordPressSeoInternalLinkService::class);
        $this->app->singleton(SeoProposalFrameService::class);
        $this->app->singleton(SeoProposalStoreService::class);
        $this->app->singleton(SeoScanStoreService::class);
        $this->app->singleton(PublicUrlInspector::class);
        $this->app->singleton(RobotsTxtPolicy::class);
        $this->app->singleton(SitemapManifestBuilder::class);
        $this->app->singleton(WordPressSeoDiscoveryService::class);
        $this->app->singleton(WordPressSeoScanService::class);
        $this->app->singleton(WordPressSeoApplyService::class);
        $this->app->singleton(WordPressSeoScanProcessorService::class);
        $this->app->singleton(WordPressSeoBackgroundRunnerService::class);
        $this->app->singleton(WordPressSeoScorePreviewService::class);
        $this->app->singleton(WordPressSiteIndexabilityService::class);
        $this->app->singleton(\hexa_package_wordpress_seo\Services\WordPressImageMetaService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . "/../../database/migrations");

        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessWordPressSeoScanCommand::class,
                WordPressSeoSiteCommand::class,
                \hexa_package_wordpress_seo\Console\WordPressImageMetaCommand::class,
            ]);
        }
    }
}
