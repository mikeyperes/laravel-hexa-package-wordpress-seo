<?php

namespace hexa_package_wordpress_seo\Contracts;

interface SeoProviderInterface
{
    public function key(): string;

    public function label(): string;

    public function inspect(array $target): array;

    public function supportsFeature(string $feature): bool;

    public function inventoryPages(array $target, array $filters = []): array;

    public function readPage(array $target, int $pageId): array;

    public function writePage(array $target, int $pageId, array $payload): array;
}
