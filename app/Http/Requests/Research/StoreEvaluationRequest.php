<?php

namespace App\Http\Requests\Research;

use Illuminate\Foundation\Http\FormRequest;

class StoreEvaluationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $coverage = $this->input('fact_coverage');
        if (is_array($coverage)) {
            $this->merge(['fact_coverage' => array_map(static fn (mixed $value): mixed => match ($value) {
                true, 1, '1' => true,
                false, 0, '0' => false,
                default => $value,
            }, $coverage)]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'evaluation_id' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'correctness' => ['required', 'integer', 'between:0,2'],
            'fact_coverage' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'fact_coverage.*' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:8000'],
        ];
    }
}
