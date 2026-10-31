<?php

namespace App\Http\Requests\Research;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class StoreDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && ($this->user()?->can('update', $project) ?? false);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'revision_id' => ['required', 'integer', 'min:1'],
            'dataset_id' => ['required_without:upload', 'nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/'],
            'dataset_version' => ['required_without:upload', 'nullable', 'string', 'max:32', 'regex:/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/'],
            'split' => ['required_without:upload', 'nullable', 'in:pilot,evaluation'],
            'upload' => ['nullable', 'file', 'max:2048'],
        ];
    }
}
