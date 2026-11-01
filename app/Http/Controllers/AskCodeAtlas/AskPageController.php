<?php

namespace App\Http\Controllers\AskCodeAtlas;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AskPageController extends Controller
{
    public function __invoke(Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/ask/index', [
            'project' => $project->only(['id', 'name']),
            'configuration' => [
                'models' => config('thesis.models'),
                'methods' => config('thesis.methods'),
            ],
            'endpoints' => [
                'history' => route('projects.ask.history', $project),
                'store' => route('projects.ask.store', $project),
                'detail' => route('projects.ask.show', [$project, '__QUESTION__']),
            ],
        ]);
    }
}
