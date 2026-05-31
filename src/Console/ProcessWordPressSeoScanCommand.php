<?php

namespace hexa_package_wordpress_seo\Console;

use hexa_package_wordpress_seo\Models\SeoScan;
use hexa_package_wordpress_seo\Services\WordPressSeoScanProcessorService;
use Illuminate\Console\Command;

class ProcessWordPressSeoScanCommand extends Command
{
    protected $signature = "wordpress-seo:process-scan {scan : The SEO scan ID to process}";

    protected $description = "Process a stored WordPress SEO scan in the background.";

    public function handle(WordPressSeoScanProcessorService $processor): int
    {
        $scanId = (int) $this->argument("scan");
        $scan = SeoScan::query()->find($scanId);

        if (!$scan) {
            $this->error("SEO scan not found.");
            return self::FAILURE;
        }

        $processor->process($scan);
        $this->info("SEO scan processed.");

        return self::SUCCESS;
    }
}
