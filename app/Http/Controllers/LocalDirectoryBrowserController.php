<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\ProjectSources\LocalDirectoryBrowser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class LocalDirectoryBrowserController extends Controller
{
    public function __invoke(
        Request $request,
        Project $project,
        LocalDirectoryBrowser $directories,
    ): JsonResponse {
        if (! $directories->isEnabled()) {
            abort(404);
        }

        Gate::authorize('update', $project);

        if (! $directories->isConfigured()) {
            return response()->json([
                'message' => __('ui.projects.sources.configuration.description'),
            ], 503);
        }

        $validated = $request->validate([
            'path' => ['nullable', 'string'],
        ]);

        try {
            $listing = $directories->browse($validated['path'] ?? null);
        } catch (InvalidArgumentException) {
            return response()->json([
                'message' => __('ui.projects.sources.browser.unavailable'),
            ], 422);
        }

        return response()->json($listing);
    }
}
