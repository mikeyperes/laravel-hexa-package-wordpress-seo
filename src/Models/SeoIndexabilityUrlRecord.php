<?php

namespace hexa_package_wordpress_seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoIndexabilityUrlRecord extends Model
{
    protected $table = 'wordpress_seo_indexability_url_records';

    protected $fillable = [
        'seo_scan_target_id',
        'url_hash',
        'url',
        'final_url',
        'status_code',
        'from_sitemap',
        'important',
        'robots_allowed',
        'canonical_self',
        'soft_404',
        'access_blocked',
        'indexable',
        'canonical_url',
        'response_sha256',
        'reasons',
        'payload',
        'fetched_at',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'from_sitemap' => 'boolean',
        'important' => 'boolean',
        'robots_allowed' => 'boolean',
        'canonical_self' => 'boolean',
        'soft_404' => 'boolean',
        'access_blocked' => 'boolean',
        'indexable' => 'boolean',
        'reasons' => 'array',
        'payload' => 'array',
        'fetched_at' => 'datetime',
    ];

    public function target(): BelongsTo
    {
        return $this->belongsTo(SeoScanTarget::class, 'seo_scan_target_id');
    }
}
