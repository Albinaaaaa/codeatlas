<?php

namespace App\Http\Controllers;

use App\Actions\Projects\ScanProjectRepository;
use App\Jobs\ScanProjectRepositoryJob;
use App\Models\Project;
use App\RepositoryScanning\Exceptions\RepositoryScanException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectScanController extends Controller
{
    public function __invoke(
        Project $project,
        ScanProjectRepository $scanRepository,
    ): RedirectResponse {
        Gate::authorize('update', $project);

        try {
            $scanRepository->assertSourceAvailable($project);
            ScanProjectRepositoryJob::dispatch($project->getKey());
        } catch (RepositoryScanException) {
            throw ValidationException::withMessages([
                'scan' => __('ui.projects.scan.failed'),
            ]);
        }

        return back();
    }
}
