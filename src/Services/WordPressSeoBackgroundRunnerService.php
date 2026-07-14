<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_core\Services\DetachedArtisanCommandService;
use hexa_package_wordpress_seo\Models\SeoScan;

class WordPressSeoBackgroundRunnerService
{
    public function __construct(
        private readonly DetachedArtisanCommandService $commands,
    ) {
    }

    public function launch(SeoScan $scan): array
    {
        $scanId = (int) $scan->id;
        $logPath = storage_path("logs/wordpress-seo-scan-{$scanId}.log");
        $artisanCommand = "wordpress-seo:process-scan {$scanId}";

        try {
            $this->commands->start(
                'wordpress-seo:process-scan',
                [$scanId],
                [],
                $logPath,
            );
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'pid' => null,
                'log_path' => $logPath,
                'php_binary' => PHP_BINARY,
                'message' => $e->getMessage(),
            ];
        }

        $meta = (array) ($scan->meta ?? []);
        $meta['runner'] = [
            'pid' => null,
            'log_path' => $logPath,
            'php_binary' => PHP_BINARY,
            'command' => $artisanCommand,
            'launched_at' => now()->toDateTimeString(),
        ];
        $scan->meta = $meta;
        $scan->save();

        return [
            'success' => true,
            'pid' => null,
            'log_path' => $logPath,
            'php_binary' => PHP_BINARY,
        ];
    }
}
