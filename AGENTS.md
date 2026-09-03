# CodeAtlas Repository Guidelines

## Project

CodeAtlas is a repository-analysis platform built with Laravel and an Inertia React + TypeScript frontend.

Follow the global Codex guidelines first. This file defines CodeAtlas-specific architecture and conventions.

## Project Structure

Backend:

- `app/Http/Controllers` — HTTP controllers
- `app/Http/Requests` — request validation
- `app/Actions` — application/domain workflows
- `app/RepositoryScanning` — repository analysis and scanning logic
- `app/ProjectSources` — repository source abstractions and implementations

Routes:

- `routes/web.php`
- `routes/settings.php`
- `routes/console.php`

Frontend:

- `resources/js` — pages, components, layouts, hooks, utilities, and TypeScript types
- `resources/css/app.css` — global styles

Database:

- `database/migrations`
- `database/factories`
- `database/seeders`

Tests:

- `tests/Feature` — HTTP, database, authentication, integrations, and workflows
- `tests/Unit` — isolated domain/application logic
- `tests/Fixtures` — repository samples used for scanner tests

## Core Architecture

Repository access must go through the `ProjectSource` abstraction.

Supported MVP source types:

- `LocalDirectorySource`
- `UploadedDirectorySource`
- `GitHubRepositorySource`

`LocalAgentSource` is not part of the MVP unless explicitly requested.

Do not couple repository-analysis logic directly to:

- GitHub;
- uploaded files;
- a host filesystem;
- a specific transport mechanism.

Repository scanning should work with the common `ProjectSource` abstraction whenever practical.

Source-specific responsibilities belong inside their corresponding `ProjectSource` implementation.

## Repository Revisions

CodeAtlas analyzes immutable repository revisions/snapshots.

Repository analysis should depend on a resolved revision/snapshot rather than continuously changing source state.

Do not introduce live/background synchronization behavior unless explicitly requested.

GitHub webhook or synchronization features must not bypass the repository revision/snapshot model.

## Backend Conventions

Follow Laravel conventions and existing project patterns.

- Keep controllers thin.
- Put HTTP validation in Form Requests when appropriate.
- Put non-trivial application workflows in Actions or the existing application layer.
- Keep repository-analysis logic out of controllers.
- Prefer dependency injection.
- Avoid service-location patterns when dependency injection is practical.
- Use transactions for operations that must succeed or fail atomically.
- Prevent N+1 queries when using Eloquent relationships.
- Avoid unnecessary database queries.
- Preserve clear separation between HTTP, application, domain, and repository-source concerns.

Before introducing a new Service, Action, Repository, DTO, Value Object, or abstraction, check whether an existing project abstraction already solves the problem.

Do not introduce architectural layers merely for consistency with generic design patterns.

## PHP

- Follow the PHP version declared by the project.
- Follow Laravel Pint formatting.
- Prefer strict, explicit code where it improves correctness.
- Use PHP types compatible with the project's PHP version.
- Prefer enums/value objects only when they provide clear domain value.
- Do not replace simple Laravel functionality with custom abstractions without a concrete reason.

## React / TypeScript

The frontend uses React + TypeScript through Inertia.

- Follow existing component and directory patterns.
- Prefer TypeScript over bypassing the type system.
- Avoid `any` unless there is a concrete reason.
- Keep types explicit at backend/frontend and external-data boundaries.
- Reuse existing components, hooks, layouts, and utilities before creating new ones.
- Keep components focused.
- Avoid unnecessary React state.
- Avoid `useEffect` when the value can be derived directly.
- Do not introduce another state-management, UI, form, or data-fetching library unless necessary and explicitly justified.
- Preserve Inertia conventions instead of rebuilding SPA behavior manually.

Component filenames should follow the project's existing naming convention.

Do not rename existing components purely for stylistic consistency unless requested.

## UI

CodeAtlas follows a GitHub-native developer-tool style.

Primary dark-theme direction:

- background: `#0D1117`
- surfaces: `#161B22`
- borders: `#30363D`
- primary text: `#F0F6FC`
- accent: `#2F81F7`

Support:

- Light
- Dark
- System

Use design tokens/CSS variables rather than duplicating hardcoded theme values across components.

Reuse existing UI components and design tokens before creating new styles.

Avoid unnecessary visual redesign when implementing functional tasks.

## Database

When changing database behavior:

- follow existing migration naming/style;
- add indexes for real query patterns where appropriate;
- preserve foreign-key and ownership relationships;
- avoid storing derived data unnecessarily;
- consider data migration/backward compatibility for existing records.

Do not edit an existing production migration to change already-released schema behavior unless the task explicitly requires it; prefer a new migration.

## Repository Analysis

Repository-analysis code may process large repositories.

When working on scanners:

- avoid reading the same file repeatedly;
- avoid loading an entire repository into memory when streaming or incremental processing is practical;
- skip irrelevant generated/dependency directories when appropriate;
- preserve deterministic analysis where possible;
- keep source-specific filesystem behavior outside core analysis logic.

Do not optimize prematurely, but avoid implementations that obviously require unnecessary full-repository rescans.

## Testing

Use the smallest relevant verification.

Backend examples:

- targeted PHPUnit/Pest test;
- specific test class;
- specific feature workflow;
- Pint on changed PHP files;
- PHPStan on relevant scope when appropriate.

Frontend examples:

- relevant lint command;
- TypeScript check;
- relevant frontend tests;
- production build when bundling/integration behavior changed.

Run broader tests when changing:

- `ProjectSource`;
- repository scanning;
- shared authentication/authorization;
- shared infrastructure;
- database schema;
- cross-module behavior.

Repository scanner tests may use `tests/Fixtures` when realistic filesystem structures are required.

Do not create large fixture repositories when a small representative fixture is sufficient.

## Common Commands

Use commands defined by the current repository configuration rather than assuming they exist.

Typical project commands include:

- `composer dev`
- `composer test`
- `npm run dev`
- `npm run build`
- `npm run lint`
- `npm run format`
- `./vendor/bin/pint`
- `./vendor/bin/phpstan analyse`

Do not automatically run all of these commands for every change.

Choose only the commands relevant to the task.

## Dependency Changes

Before adding a package:

1. check whether the existing stack already provides the required functionality;
2. confirm compatibility with installed Laravel/PHP/React versions;
3. prefer mature, actively maintained packages;
4. avoid adding a dependency for trivial functionality.

Do not upgrade Laravel, React, TypeScript, PHP requirements, or other major dependencies unless explicitly requested.

## Scope

CodeAtlas MVP should remain focused on:

- connecting repository sources;
- creating private repository snapshots/revisions;
- analyzing repository structure and code;
- answering questions about analyzed repositories;
- generating project descriptions;
- generating database schemas;
- generating architecture/project structure diagrams.

Do not introduce V2+ functionality such as Local Agent background/live synchronization unless explicitly requested.
