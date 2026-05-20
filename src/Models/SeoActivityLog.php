<?php

namespace hexa_package_wordpress_seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoActivityLog extends Model
{
    protected $table = 'wordpress_seo_activity_logs';

    public $timestamps = false;

    protected $fillable = [
        'seo_scan_id',
        'seo_scan_target_id',
        'seo_page_record_id',
        'seo_execution_run_id',
        'level',
        'event',
        'message',
        'context',
        'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(SeoScan::class, 'seo_scan_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(SeoScanTarget::class, 'seo_scan_target_id');
    }

    public function pageRecord(): BelongsTo
    {
        return $this->belongsTo(SeoPageRecord::class, 'seo_page_record_id');
    }

    public function executionRun(): BelongsTo
    {
        return $this->belongsTo(SeoExecutionRun::class, 'seo_execution_run_id');
    }
}
