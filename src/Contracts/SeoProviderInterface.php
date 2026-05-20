<?php

namespace hexa_package_wordpress_seo\Contracts;

interface SeoProviderInterface
{
    public function key(): string;

    public function label(): string;

    public function inspect(array ): array;

    public function supportsFeature(string ): bool;

    public function inventoryPages(array , array  = []): array;

    public function readPage(array , int ): array;

    public function writePage(array , int , array ): array;
}
