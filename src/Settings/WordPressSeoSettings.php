<?php

namespace hexa_package_wordpress_seo\Settings;

use hexa_core\Models\Setting;

/**
 * Site-level settings, stored through Hexa Core's Setting model.
 * Google Drive credentials stay with the Google Drive package.
 */
final class WordPressSeoSettings
{
    private const PREFIX = 'wordpress_seo.';

    /** Drive folder that holds one "<domain>" folder of featured images per site. */
    public function featuredImagesParentUrl(): string
    {
        return trim((string) Setting::getValue(self::PREFIX.'featured_images_parent_url', ''));
    }

    public function setFeaturedImagesParentUrl(string $url): void
    {
        Setting::setValue(self::PREFIX.'featured_images_parent_url', trim($url));
    }
}
