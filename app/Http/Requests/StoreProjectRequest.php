<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array{name: string, description: string|null}
     */
    public function projectData(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'description' => $this->filled('description')
                ? $this->string('description')->toString()
                : null,
        ];
    }
}
