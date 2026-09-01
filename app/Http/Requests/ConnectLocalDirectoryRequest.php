<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\ProjectSources\LocalSourceWorkspace;
use App\Rules\AccessibleLocalDirectory;
use Illuminate\Foundation\Http\FormRequest;

class ConnectLocalDirectoryRequest extends FormRequest
{
    public function authorize(LocalSourceWorkspace $workspace): bool
    {
        if (! $workspace->isEnabled()) {
            abort(404);
        }

        $project = $this->route('project');

        return $project instanceof Project
            && ($this->user()?->can('update', $project) ?? false);
    }

    /**
     * @return array<string, array<int, AccessibleLocalDirectory|string>>
     */
    public function rules(LocalSourceWorkspace $workspace): array
    {
        return [
            'path' => [
                'bail',
                'required',
                'string',
                new AccessibleLocalDirectory($workspace),
            ],
        ];
    }

    public function path(): string
    {
        return $this->string('path')->trim()->toString();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('path'))) {
            $this->merge(['path' => trim($this->input('path'))]);
        }
    }
}
