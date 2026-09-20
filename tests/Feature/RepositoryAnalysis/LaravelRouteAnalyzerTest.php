<?php

namespace Tests\Feature\RepositoryAnalysis;

use App\Models\AnalysisIssue;
use App\Models\CodeFile;
use App\Models\LaravelRoute;
use App\Models\ProjectRevision;
use App\Models\User;
use App\PhpAnalysis\PhpFileAnalyzer;
use App\PhpAnalysis\PhpFileInput;
use App\PhpAnalysis\PhpRevisionAnalyzer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\RefreshDatabase;
use Tests\TestCase;

class LaravelRouteAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_fixture_is_extracted_without_executing_the_application(): void
    {
        $analysis = app(PhpFileAnalyzer::class)->analyze(new PhpFileInput(
            1,
            'routes/web.php',
            $this->fixture('Routes.php'),
        ));

        $this->assertCount(10, $analysis->routes);
        $this->assertSame(['GET', 'HEAD'], array_values(array_map(
            fn ($route): string => $route->method,
            array_filter($analysis->routes, fn ($route): bool => $route->uri === 'health'),
        )));
        $admin = collect($analysis->routes)->firstWhere('name', 'admin.users');
        $this->assertSame('admin/users', $admin->uri);
        $this->assertSame(['auth'], $admin->middleware);
        $this->assertSame('App\\Http\\Controllers\\AdminController', $admin->controller);
        $this->assertSame('index', $admin->controllerMethod);
        $this->assertSame('laravel_route.dynamic_definition', $analysis->routeIssues[0]->code);
        $this->assertSame('laravel_route.dynamic_group_attribute', $analysis->routeIssues[1]->code);
        $this->assertNotContains('skipped', array_column($analysis->routes, 'uri'));
    }

    public function test_routes_are_revision_scoped_and_controller_targets_are_resolved(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create([
            'name' => 'Laravel routes',
            'slug' => 'laravel-routes',
        ]);
        $source = $project->sources()->create([
            'type' => 'local',
            'name' => 'Fixture source',
        ]);
        $revision = new ProjectRevision(['identifier' => 'routes-fixture']);
        $revision->project()->associate($project);
        $source->revisions()->save($revision);
        $routesFile = $this->codeFile($revision, 'Routes.php');
        $this->codeFile($revision, 'Controllers.php');
        Event::fake(['eloquent.created: '.LaravelRoute::class, 'eloquent.created: '.AnalysisIssue::class]);

        $first = app(PhpRevisionAnalyzer::class)->analyze(
            $revision,
            base_path('tests/Fixtures/LaravelRoutes'),
        );
        $second = app(PhpRevisionAnalyzer::class)->analyze(
            $revision,
            base_path('tests/Fixtures/LaravelRoutes'),
        );

        $this->assertEquals($first, $second);
        $this->assertSame(10, $first->routesPersisted);
        Event::assertDispatchedTimes('eloquent.created: '.LaravelRoute::class, 20);
        Event::assertDispatchedTimes('eloquent.created: '.AnalysisIssue::class, $first->issuesPersisted * 2);
        $route = LaravelRoute::query()->where('name', 'admin.users')->firstOrFail();
        $this->assertSame(['auth'], $route->middleware);
        $this->assertSame('index', $route->metadata['controller_method']);
        $this->assertSame('App\Http\Controllers\AdminController', $route->controllerSymbol->qualified_name);
        $this->assertDatabaseCount('laravel_routes', 10);
        $this->assertSame(8, DB::table('laravel_routes')->whereNotNull('controller_symbol_id')->count());
        $resolvedMethodCount = DB::table('laravel_routes')
            ->pluck('metadata')
            ->filter(function (mixed $metadata): bool {
                $value = is_string($metadata) ? json_decode($metadata, true) : $metadata;

                return is_array($value) && isset($value['controller_method_symbol_id']);
            })
            ->count();
        $this->assertSame(8, $resolvedMethodCount);
        $this->assertDatabaseHas('laravel_routes', [
            'project_id' => $project->id,
            'project_revision_id' => $revision->id,
            'code_file_id' => $routesFile->id,
            'method' => 'GET',
            'uri' => 'admin/users',
            'name' => 'admin.users',
            'action' => 'App\\Http\\Controllers\\AdminController@index',
        ]);
        $this->assertDatabaseHas('analysis_issues', [
            'project_id' => $project->id,
            'project_revision_id' => $revision->id,
            'code_file_id' => $routesFile->id,
            'category' => 'laravel_route',
            'code' => 'laravel_route.dynamic_definition',
            'source_path' => 'Routes.php',
        ]);
        $this->assertSame(0, DB::table('laravel_routes')
            ->whereNull('start_line')
            ->orWhereNull('end_line')
            ->count());
    }

    private function codeFile(ProjectRevision $revision, string $path): CodeFile
    {
        $contents = $this->fixture($path);

        return CodeFile::forceCreate([
            'project_id' => $revision->project_id,
            'project_revision_id' => $revision->id,
            'path' => $path,
            'language' => 'PHP',
            'content_hash' => hash('sha256', $contents),
            'size_bytes' => strlen($contents),
            'line_count' => substr_count($contents, "\n"),
        ]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/LaravelRoutes/'.$name));
    }
}
