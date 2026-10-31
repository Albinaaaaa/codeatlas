<?php

namespace App\Http\Requests\Research;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && ($this->user()?->can('update', $project) ?? false);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = ['lock_version' => ['required', 'integer', 'min:1']];
        if ($this->routeIs('projects.research.datasets.verify')) {
            $rules['confirmed'] = ['required', 'accepted'];
        }
        if ($this->routeIs('projects.research.datasets.updateQuestion')) {
            $rules += [
                'question_id' => ['present', 'nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/'],
                'category' => ['present', 'nullable', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_]*\z/'],
                'question' => ['present', 'nullable', 'string', 'max:16000'],
                'reference_answer' => ['present', 'nullable', 'string', 'max:32000'],
                'required_facts' => ['present', 'array', 'list', 'max:100'],
                'required_facts.*' => ['nullable', 'string', 'max:8000'],
                'evidence' => ['present', 'array', 'list', 'max:100'],
                'evidence.*' => ['array:path,start_line,end_line'],
                'evidence.*.path' => ['present', 'nullable', 'string', 'max:1024'],
                'evidence.*.start_line' => ['required', 'integer', 'min:1'],
                'evidence.*.end_line' => ['required', 'integer', 'min:1'],
            ];
        }

        return $rules;
    }
}
