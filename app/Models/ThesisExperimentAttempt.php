<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThesisExperimentAttempt extends Model
{
    /** @var list<string> */
    protected $hidden = ['payload'];

    /** @return BelongsTo<ProjectRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(ProjectRevision::class, 'project_revision_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'repetition_index' => 'integer'];
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        $payload = $this->getAttribute('payload');

        return is_array($payload) ? $payload : [];
    }
}
