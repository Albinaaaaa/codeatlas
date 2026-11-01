<?php

namespace App\Http\Requests\AskCodeAtlas;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $project = $this->route('project');
        $projectId = $project instanceof Project ? $project->id : null;

        return [
            'revision_id' => ['required', 'integer', Rule::exists('project_revisions', 'id')->where('project_id', $projectId)],
            'question' => ['required', 'string', 'max:4000'],
            'model' => ['required', 'string', Rule::in(config('thesis.models', []))],
            'retrieval_method' => ['required', 'string', Rule::in(config('thesis.methods', []))],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
