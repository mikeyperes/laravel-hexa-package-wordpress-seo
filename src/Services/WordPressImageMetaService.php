<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_wordpress\Services\WordPressManagerService;

/**
 * Reads and writes one WordPress image's SEO fields (alt text, title, caption,
 * description) through WordPress's own functions, and optionally refreshes the
 * alt attribute of that image inside one post's content.
 */
class WordPressImageMetaService
{
    private const MARKER = 'HEXA_IMAGE_META:';

    public const FIELDS = ['alt', 'title', 'caption', 'description'];

    public function __construct(protected WordPressManagerService $wp)
    {
    }

    /**
     * @param int|string $image Attachment ID or image URL.
     * @return array<string, mixed>
     */
    public function read(array $target, int|string $image): array
    {
        return $this->run($target, $image, [], 0);
    }

    /**
     * @param int|string $image Attachment ID or image URL.
     * @param array<string, string> $fields Subset of FIELDS to set.
     * @param int $postId When > 0, also update this image's alt inside that post's content.
     * @return array<string, mixed>
     */
    public function write(array $target, int|string $image, array $fields, int $postId = 0): array
    {
        $fields = array_intersect_key($fields, array_flip(self::FIELDS));
        if ($fields === []) {
            return ['success' => false, 'message' => 'No image fields were provided (alt, title, caption, description).'];
        }

        return $this->run($target, $image, array_map(fn ($v): string => trim((string) $v), $fields), $postId);
    }

    /** @return array<string, mixed> */
    private function run(array $target, int|string $image, array $fields, int $postId): array
    {
        $php = implode('', [
            '$image=' . var_export(is_numeric($image) ? (int) $image : (string) $image, true) . ';',
            '$fields=' . var_export($fields, true) . ';',
            '$postId=' . var_export($postId, true) . ';',
            '$marker=' . var_export(self::MARKER, true) . ';',
            <<<'PHP'
$id = is_int($image) ? $image : attachment_url_to_postid(preg_replace('/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', strtok($image, '?')));
if (!$id && !is_int($image)) { $id = attachment_url_to_postid(strtok($image, '?')); }
$att = $id ? get_post($id) : null;
if (!$att || $att->post_type !== 'attachment') {
    echo $marker . wp_json_encode(['success'=>false,'message'=>'Image not found in the media library.']);
    return;
}
$changed = [];
if (array_key_exists('alt', $fields)) { update_post_meta($id, '_wp_attachment_image_alt', wp_strip_all_tags($fields['alt'])); $changed[] = 'alt'; }
$postUpdate = ['ID'=>$id];
if (array_key_exists('title', $fields)) { $postUpdate['post_title'] = $fields['title']; $changed[] = 'title'; }
if (array_key_exists('caption', $fields)) { $postUpdate['post_excerpt'] = $fields['caption']; $changed[] = 'caption'; }
if (array_key_exists('description', $fields)) { $postUpdate['post_content'] = $fields['description']; $changed[] = 'description'; }
if (count($postUpdate) > 1) { wp_update_post(wp_slash($postUpdate)); }
$contentUpdated = null;
if ($postId > 0 && array_key_exists('alt', $fields)) {
    $post = get_post($postId);
    $contentUpdated = 0;
    if ($post) {
        $alt = esc_attr(wp_strip_all_tags($fields['alt']));
        $html = preg_replace_callback('/<img\b[^>]*\bwp-image-' . $id . '\b[^>]*>/i', function ($m) use ($alt, &$contentUpdated) {
            $contentUpdated++;
            $tag = preg_replace('/\salt=("[^"]*"|\'[^\']*\')/i', '', $m[0]);
            return preg_replace('/^<img\b/i', '<img alt="' . $alt . '"', $tag);
        }, $post->post_content);
        if ($contentUpdated > 0) { wp_update_post(wp_slash(['ID'=>$postId, 'post_content'=>$html])); }
    }
}
clean_post_cache($id);
$file = get_attached_file($id);
$meta = wp_get_attachment_metadata($id);
$a = get_post($id);
echo $marker . wp_json_encode([
    'success'=>true,
    'message'=>$changed ? 'Image fields updated.' : 'Image fields read.',
    'changed'=>$changed,
    'post_content_images_updated'=>$contentUpdated,
    'image'=>[
        'id'=>$id,
        'url'=>wp_get_attachment_url($id),
        'file_name'=>$file ? basename($file) : '',
        'mime_type'=>get_post_mime_type($id),
        'file_size'=>$file && file_exists($file) ? filesize($file) : null,
        'width'=>$meta['width'] ?? null,
        'height'=>$meta['height'] ?? null,
        'alt'=>(string) get_post_meta($id, '_wp_attachment_image_alt', true),
        'title'=>$a->post_title,
        'caption'=>$a->post_excerpt,
        'description'=>$a->post_content,
        'attached_to'=>$a->post_parent ?: null,
    ],
]);
PHP
        ]);

        $result = $this->wp->evaluatePhp($target, $php);
        if (!($result['success'] ?? false)) {
            return ['success' => false, 'message' => (string) ($result['message'] ?? 'Image field command failed.')];
        }

        $stdout = (string) ($result['stdout'] ?? '');
        $position = strrpos($stdout, self::MARKER);
        $decoded = $position === false ? null : json_decode(trim(substr($stdout, $position + strlen(self::MARKER))), true);

        return is_array($decoded) ? $decoded : ['success' => false, 'message' => 'Failed to parse image field output.'];
    }
}
