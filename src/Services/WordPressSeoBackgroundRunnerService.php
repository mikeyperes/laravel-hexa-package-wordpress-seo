<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_wordpress_seo\Models\SeoScan;

class WordPressSeoBackgroundRunnerService
{
    public function launch(SeoScan $scan): array
    {
        $scanId = (int) $scan->id;
        $logPath = storage_path("logs/wordpress-seo-scan-{$scanId}.log");
        $basePath = escapeshellarg(base_path());
        $artisan = escapeshellarg(base_path("artisan"));
        $cliPhp = trim((string) @shell_exec("command -v php 2>/dev/null"));
        $phpBinary = $cliPhp !== "" ? $cliPhp : "/usr/local/bin/php";
        $binary = escapeshellarg($phpBinary);
        $log = escapeshellarg($logPath);
        $command = "cd {$basePath} && nohup {$binary} {$artisan} wordpress-seo:process-scan {$scanId} > {$log} 2>&1 < /dev/null &";

        @exec($command);

        $meta = (array) ($scan->meta ?? []);
        $meta["runner"] = [
            "pid" => null,
            "log_path" => $logPath,
            "php_binary" => $phpBinary,
            "command" => $command,
            "launched_at" => now()->toDateTimeString(),
        ];
        $scan->meta = $meta;
        $scan->save();

        return [
            "success" => true,
            "pid" => null,
            "log_path" => $logPath,
            "php_binary" => $phpBinary,
        ];
    }
}
