<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<array<string, mixed>> $questions
 * @property array<string, mixed>|null $validation_report
 */
class ResearchDataset extends Model
{
    /** @var list<string> */
    protected $hidden = ['questions', 'original_path', 'frozen_path'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(ProjectRevision::class, 'project_revision_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['questions' => 'encrypted:array', 'validation_report' => 'array',
            'lock_version' => 'integer', 'frozen_at' => 'immutable_datetime'];
    }

    /** @return list<array<string, mixed>> */
    public function questionRows(): array
    {
        $rows = $this->getAttribute('questions');

        return is_array($rows) ? array_values($rows) : [];
    }
}
