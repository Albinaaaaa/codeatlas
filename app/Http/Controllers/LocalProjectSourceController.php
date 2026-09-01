<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConnectLocalDirectoryRequest;
use App\Models\Project;
use App\ProjectSources\LocalDirectorySource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class LocalProjectSourceController extends Controller
{
    public function update(
        ConnectLocalDirectoryRequest $request,
        Project $project,
        LocalDirectorySource $localDirectory,
    ): RedirectResponse {
        $localDirectory->connect($project, $request->path());

        return back();
    }

    public function destroy(
        Project $project,
        LocalDirectorySource $localDirectory,
    ): RedirectResponse {
        if (! $localDirectory->isEnabled()) {
            abort(404);
        }

        Gate::authorize('update', $project);

        $localDirectory->disconnect($project);

        return back();
    }
}
