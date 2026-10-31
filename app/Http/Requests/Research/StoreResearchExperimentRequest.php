<?php

namespace App\Http\Requests\Research;

use Illuminate\Foundation\Http\FormRequest;

class StoreResearchExperimentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['dataset_id' => ['required', 'integer', 'exists:research_datasets,id'],
            'repetitions' => ['required', 'integer', 'min:1', 'max:10']];
    }
}
