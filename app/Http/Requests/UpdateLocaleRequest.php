<?php

namespace App\Http\Requests;

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'locale' => [
                'required',
                'string',
                Rule::in(SetLocale::SUPPORTED_LOCALES),
            ],
        ];
    }

    public function locale(): string
    {
        return $this->string('locale')->toString();
    }
}
