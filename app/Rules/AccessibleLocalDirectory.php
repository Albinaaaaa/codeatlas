<?php

namespace App\Rules;

use App\ProjectSources\LocalSourceWorkspace;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class AccessibleLocalDirectory implements ValidationRule
{
    public function __construct(
        private LocalSourceWorkspace $workspace,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $this->workspace->canonicalRelativeDirectory($value) === null) {
            $fail(__('ui.projects.sources.validation.not_allowed'));
        }
    }
}
