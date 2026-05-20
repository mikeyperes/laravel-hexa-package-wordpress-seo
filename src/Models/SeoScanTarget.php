<?php

namespace hexa_package_wordpress_seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoScanTarget extends Model
{
    protected $table = 'wordpress_seo_scan_targets';

    protected $fillable = [
        'seo_scan_id',
        'scope_type',
        'target_key',
        'target_payload',
        'provider_key',
        'site_name',
        'site_url',
        'server_name',
        'cpanel_username',
        'plugin_state',
        'status',
        'summary',
        'meta',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'target_payload' => 'array',
        'plugin_state' => 'array',
        'summary' => 'array',
        'meta' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(SeoScan::class, 'seo_scan_id');
    }

    public function pageRecords(): HasMany
    {
        return $this->hasMany(SeoPageRecord::class, 'seo_scan_target_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(SeoActivityLog::class, 'seo_scan_target_id');
    }
}
