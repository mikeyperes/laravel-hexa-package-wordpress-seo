<?php

namespace hexa_package_wordpress_seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoProposal extends Model
{
    protected $table = 'wordpress_seo_proposals';

    protected $fillable = [
        'seo_page_record_id',
        'provider_key',
        'feature_set',
        'before_payload',
        'proposed_payload',
        'review_payload',
        'status',
        'agent_driver',
        'agent_model',
        'extracted_urls',
        'proposed_at',
        'reviewed_at',
        'applied_at',
        'meta',
    ];

    protected $casts = [
        'feature_set' => 'array',
        'before_payload' => 'array',
        'proposed_payload' => 'array',
        'review_payload' => 'array',
        'extracted_urls' => 'array',
        'meta' => 'array',
        'proposed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function pageRecord(): BelongsTo
    {
        return $this->belongsTo(SeoPageRecord::class, 'seo_page_record_id');
    }

    public function executionRuns(): HasMany
    {
        return $this->hasMany(SeoExecutionRun::class, 'seo_proposal_id');
    }
}
