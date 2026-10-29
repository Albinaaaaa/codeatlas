<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThesisAnswerEvaluation extends Model
{
    /** @var list<string> */
    protected $hidden = ['payload', 'thesis_experiment_attempt_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'correctness' => 'integer'];
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        $value = $this->getAttribute('payload');

        return is_array($value) ? $value : [];
    }
}
