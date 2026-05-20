<?php

namespace hexa_package_wordpress_seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoScan extends Model
{
    protected $table = 'wordpress_seo_scans';

    protected $fillable = [
        'scope_type',
        'scope_payload',
        'provider_key',
        'feature_set',
        'status',
        'summary',
        'meta',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'scope_payload' => 'array',
        'feature_set' => 'array',
        'summary' => 'array',
        'meta' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function targets(): HasMany
    {
        return $this->hasMany(SeoScanTarget::class, 'seo_scan_id');
    }

    public function executionRuns(): HasMany
    {
        return $this->hasMany(SeoExecutionRun::class, 'seo_scan_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(SeoActivityLog::class, 'seo_scan_id');
    }
}
