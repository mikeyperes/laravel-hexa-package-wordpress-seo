<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_google_drive\Services\GoogleDriveService;
use hexa_package_wordpress_seo\Settings\WordPressSeoSettings;
use RuntimeException;

/**
 * Featured images live in Drive at "<featured images parent>/<domain>". The
 * site folder is found by that name, so its link is never stored.
 */
final class FeaturedImageFolders
{
    public function __construct(private readonly WordPressSeoSettings $settings) {}

    public static function normalizeDomain(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $value);
        $value = (string) preg_replace('~^www\.~', '', $value);
        $value = explode('?', explode('/', $value)[0])[0];
        if (preg_match('/\A[a-z0-9-]+(\.[a-z0-9-]+)+\z/', $value) !== 1) {
            throw new RuntimeException('Give the site domain, for example michaelperes.com.');
        }

        return $value;
    }

    /**
     * Find the site's folder, creating it only when $create is true.
     *
     * @return array{domain: string, parent_url: string, folder_id: string|null, folder_url: string|null, created: bool}
     */
    public function forDomain(string $domain, bool $create = false): array
    {
        if (! class_exists(GoogleDriveService::class)) {
            throw new RuntimeException('The Google Drive package is not installed.');
        }
        $domain = self::normalizeDomain($domain);
        $parentUrl = $this->settings->featuredImagesParentUrl();
        $drive = app(GoogleDriveService::class);
        $parentId = $parentUrl !== '' ? $drive->extractFolderIdFromUrl($parentUrl) : null;
        if ($parentId === null) {
            throw new RuntimeException('Set the Featured images folder on the WordPress SEO settings page first.');
        }
        $parentKey = $drive->extractResourceKeyFromUrl($parentUrl);

        $result = $create
            ? $drive->findOrCreateFolder($domain, $parentId, $parentKey)
            : $drive->findFoldersByName($domain, $parentId, $parentKey, true, 1);
        if (! ($result['success'] ?? false)) {
            throw new RuntimeException('The "'.$domain.'" folder could not be '.($create ? 'created' : 'looked up').': '.($result['error'] ?? 'unknown error'));
        }
        $folderId = $create
            ? (string) ($result['folder_id'] ?? $result['folder']['id'] ?? '')
            : (string) ($result['folders'][0]['id'] ?? '');

        return [
            'domain' => $domain,
            'parent_url' => $parentUrl,
            'folder_id' => $folderId !== '' ? $folderId : null,
            'folder_url' => $folderId !== '' ? 'https://drive.google.com/drive/folders/'.$folderId : null,
            'created' => (bool) ($result['created'] ?? false),
        ];
    }
}
