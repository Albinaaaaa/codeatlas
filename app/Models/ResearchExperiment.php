<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchExperiment extends Model
{
    /** @var list<string> */
    protected $hidden = ['model_provenance', 'execution_identity'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ResearchDataset, $this> */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(ResearchDataset::class, 'research_dataset_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision_fingerprints' => 'array',
            'model_provenance' => 'array',
            'started_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'batch_claimed_at' => 'immutable_datetime',
        ];
    }
}
