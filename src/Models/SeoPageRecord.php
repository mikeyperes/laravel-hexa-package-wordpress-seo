<?php

namespace hexa_package_wordpress_seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoPageRecord extends Model
{
    protected $table = 'wordpress_seo_page_records';

    protected $fillable = [
        'seo_scan_target_id',
        'external_page_id',
        'page_type',
        'page_status',
        'title',
        'slug',
        'permalink',
        'modified_gmt',
        'current_payload',
        'extracted_context',
        'review_state',
        'inventory_hash',
        'meta',
    ];

    protected $casts = [
        'external_page_id' => 'integer',
        'current_payload' => 'array',
        'extracted_context' => 'array',
        'meta' => 'array',
    ];

    public function target(): BelongsTo
    {
        return $this->belongsTo(SeoScanTarget::class, 'seo_scan_target_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(SeoProposal::class, 'seo_page_record_id');
    }

    public function executionRuns(): HasMany
    {
        return $this->hasMany(SeoExecutionRun::class, 'seo_page_record_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(SeoActivityLog::class, 'seo_page_record_id');
    }
}
