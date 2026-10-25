<?php

namespace App\Inference;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class OllamaInferenceException extends RuntimeException implements ShouldntReport {}
