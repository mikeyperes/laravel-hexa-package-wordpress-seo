<?php

namespace hexa_package_wordpress_seo\Providers;

use hexa_package_wordpress_seo\Console\ProcessWordPressSeoScanCommand;
use hexa_package_wordpress_seo\Services\SeoProposalFrameService;
use hexa_package_wordpress_seo\Services\SeoProposalStoreService;
use hexa_package_wordpress_seo\Services\SeoProviderRegistry;
use hexa_package_wordpress_seo\Services\SeoScanStoreService;
use hexa_package_wordpress_seo\Services\SupplementalUrlContextService;
use hexa_package_wordpress_seo\Services\WordPressSeoApplyService;
use hexa_package_wordpress_seo\Services\WordPressSeoBackgroundRunnerService;
use hexa_package_wordpress_seo\Services\WordPressSeoDiscoveryService;
use hexa_package_wordpress_seo\Services\WordPressSeoScanProcessorService;
use hexa_package_wordpress_seo\Services\WordPressSeoScanService;
use Illuminate\Support\ServiceProvider;

class WordPressSeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . "/../../config/wordpress-seo.php", "wordpress-seo");

        $this->app->singleton(SeoProviderRegistry::class);
        $this->app->singleton(SupplementalUrlContextService::class);
        $this->app->singleton(SeoProposalFrameService::class);
        $this->app->singleton(SeoProposalStoreService::class);
        $this->app->singleton(SeoScanStoreService::class);
        $this->app->singleton(WordPressSeoDiscoveryService::class);
        $this->app->singleton(WordPressSeoScanService::class);
        $this->app->singleton(WordPressSeoApplyService::class);
        $this->app->singleton(WordPressSeoScanProcessorService::class);
        $this->app->singleton(WordPressSeoBackgroundRunnerService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . "/../../database/migrations");

        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessWordPressSeoScanCommand::class,
            ]);
        }
    }
}
