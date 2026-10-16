<?php

namespace hexa_package_wordpress_seo\Http\Controllers;

use hexa_core\Http\Controllers\Controller;
use hexa_package_google_drive\Services\GoogleDriveService;
use hexa_package_wordpress_seo\Settings\WordPressSeoSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(private readonly WordPressSeoSettings $settings) {}

    public function show(): View
    {
        $driveInstalled = class_exists(GoogleDriveService::class);

        return view('wordpress-seo::settings.index', [
            'featuredImagesParentUrl' => $this->settings->featuredImagesParentUrl(),
            'driveInstalled' => $driveInstalled,
            'driveCanWrite' => $driveInstalled && app(GoogleDriveService::class)->canAttemptWrite(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'featured_images_parent_url' => ['nullable', 'string', 'max:500', 'regex:~^(|https://drive\.google\.com/.+)$~'],
        ]);
        $this->settings->setFeaturedImagesParentUrl((string) ($validated['featured_images_parent_url'] ?? ''));

        return response()->json([
            'success' => true,
            'message' => 'Settings saved.',
            'featured_images_parent_url' => $this->settings->featuredImagesParentUrl(),
        ]);
    }

    public function testFolder(Request $request): JsonResponse
    {
        if (! class_exists(GoogleDriveService::class)) {
            return response()->json(['success' => false, 'error' => 'The Google Drive package is not installed.']);
        }
        $check = app(GoogleDriveService::class)->checkFolderWritable((string) $request->input('url', $this->settings->featuredImagesParentUrl()));

        return response()->json($check['success']
            ? ['success' => true, 'message' => $check['message']]
            : ['success' => false, 'error' => $check['error']]);
    }
}
