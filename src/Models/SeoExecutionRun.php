<?php

namespace hexa_package_wordpress_seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoExecutionRun extends Model
{
    protected $table = 'wordpress_seo_execution_runs';

    protected $fillable = [
        'seo_scan_id',
        'seo_page_record_id',
        'seo_proposal_id',
        'action',
        'request_payload',
        'result_payload',
        'status',
        'started_at',
        'completed_at',
        'meta',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'result_payload' => 'array',
        'meta' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(SeoScan::class, 'seo_scan_id');
    }

    public function pageRecord(): BelongsTo
    {
        return $this->belongsTo(SeoPageRecord::class, 'seo_page_record_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(SeoProposal::class, 'seo_proposal_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(SeoActivityLog::class, 'seo_execution_run_id');
    }
}
