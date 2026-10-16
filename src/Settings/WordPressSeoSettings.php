<?php

namespace hexa_package_wordpress_seo\Settings;

use hexa_core\Models\Setting;
use hexa_package_google_drive\Services\GoogleDriveService;

/**
 * Site-level settings, stored through Hexa Core's Setting model and edited on
 * the WordPress SEO settings page. Google Drive credentials stay with the
 * Google Drive package.
 */
final class WordPressSeoSettings
{
    private const PREFIX = 'wordpress_seo.';

    /** Drive folder that holds one "<domain>" folder of featured images per site. */
    public function featuredImagesParentUrl(): string
    {
        return trim((string) Setting::getValue(self::PREFIX.'featured_images_parent_url', ''));
    }

    /** Stored in the Drive package's canonical form ("" clears it). */
    public function setFeaturedImagesParentUrl(string $url): void
    {
        $url = class_exists(GoogleDriveService::class)
            ? app(GoogleDriveService::class)->canonicalFolderUrl($url)
            : trim($url);
        Setting::setValue(self::PREFIX.'featured_images_parent_url', $url, 'wordpress-seo');
    }
}
