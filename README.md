# CodeAtlas

CodeAtlas turns Laravel codebases into living architecture documentation.

It analyzes a Laravel repository and builds structured information about its architecture, including routes, models, database schema, jobs, events, dependencies, and other framework concepts.

The platform will also provide code search, architecture diagrams, and AI-powered questions grounded in verified source-code data.

## Core Idea

CodeAtlas follows a simple principle:

> Code Intelligence First. AI Second.

Static analysis and structured project data are the source of truth.

AI is used only to retrieve, explain, summarize, and reason over verified project information.

## Planned Features

- Laravel repository analysis
- PHP AST analysis
- Laravel routes discovery
- Eloquent models and relationships
- Database schema analysis
- Jobs, events, listeners, policies, and middleware analysis
- Architecture graph
- Database diagram
- Project-wide code search
- Semantic search with pgvector
- Ask Codebase with file and line citations
- GitHub repository integration
- Automatic reindexing through GitHub webhooks

## Tech Stack

### Backend

- PHP
- Laravel 13
- PostgreSQL
- pgvector
- Redis
- Laravel queues
- nikic/PHP-Parser
- Laravel AI SDK

### Frontend

- React 19
- TypeScript
- Inertia 3
- Tailwind CSS 4
- Vite

### AI

- Ollama
- qwen3-embedding

### Quality

- PHPUnit
- PHPStan / Larastan
- Laravel Pint
- ESLint
- Prettier

## Local Development

Local Directory Sources are repositories directly available to the CodeAtlas
runtime. They are intended for development and trusted self-hosted installations,
not as a browser upload mechanism. Enable the feature explicitly and expose one
dedicated host workspace at the fixed container path `/codeatlas/sources`.

```dotenv
CODEATLAS_LOCAL_SOURCE_ENABLED=true
CODEATLAS_LOCAL_SOURCE_ROOT=/codeatlas/sources
```

Set `CODEATLAS_LOCAL_REPOS_PATH` to the host directory that contains repositories:

| Host platform | `.env` example |
| --- | --- |
| Windows | `CODEATLAS_LOCAL_REPOS_PATH=D:/projects` |
| macOS | `CODEATLAS_LOCAL_REPOS_PATH=/Users/example/projects` |
| Linux | `CODEATLAS_LOCAL_REPOS_PATH=/home/example/projects` |

Then start the development stack:

```powershell
docker compose up -d
```

Repository scans run on the queue so large repositories do not hold an HTTP
request open. Docker Compose starts the `queue` service automatically. Native
self-hosted installations must keep a worker running with
`php artisan queue:work --sleep=1 --tries=1 --timeout=900`.

Compose mounts this workspace read-only into both `app` and `queue`, the
processes that may read repository files. The picker never browses outside this
workspace, and CodeAtlas stores paths relative to it (for example `project-a`)
rather than storing host or container-specific absolute paths. Docker cannot
access host directories that were not explicitly mounted when the container was
created.

Do not configure `/`, `C:\`, or `D:\` as the workspace. Expose only the parent
directory containing repositories intended for CodeAtlas. The Docker bind uses
`create_host_path: false`, so the host workspace must already exist, and it is
mounted read-only. CodeAtlas does not use the Docker socket or Docker Engine API.

For a native self-hosted installation, set `CODEATLAS_LOCAL_SOURCE_ROOT` to an
absolute repository workspace that the application and queue worker can read.
Protect that workspace with operating-system or container read-only permissions.
`CODEATLAS_LOCAL_REPOS_PATH` is only used by Docker Compose interpolation.

Set `CODEATLAS_LOCAL_SOURCE_ENABLED=false` when the installation must not expose
local directories, including SaaS deployments. When disabled, the UI does not
offer Local Directory Source and its HTTP endpoints return `404`. If enabled but
the runtime root is missing, unreadable, or not mounted, the project page shows a
configuration state and local source selection is disabled.

Local Directory Source uses one globally mounted workspace. `ProjectPolicy`
isolates CodeAtlas project and source records, but it does not isolate directories
inside that shared filesystem workspace: users allowed to connect a local source
can browse the workspace exposed to the runtime. Use this feature only where the
workspace and users are mutually trusted. Complex per-user filesystem roots are
intentionally outside the MVP; SaaS should disable this source type entirely.

Set `CODEATLAS_DOCKER_APP_URL` if the Docker web application is exposed at a URL
other than `http://localhost:8080`.

## Project Source Architecture

CodeAtlas separates obtaining repository files from analyzing them. The source
types planned for the product are:

- `LocalDirectorySource` — MVP support for development, Docker development, and
  trusted self-hosted installations where repositories are directly readable by
  the CodeAtlas runtime.
- `UploadedDirectorySource` — future MVP browser-based upload of a local project,
  independent of the user's Windows, macOS, or Linux filesystem paths.
- `GitHubRepositorySource` — future MVP GitHub App integration that fetches a
  repository server-side.
- `LocalAgentSource` — optional V2+ continuous/background synchronization for
  local or private repositories.

Only `LocalDirectorySource` is implemented today. The other entries describe
product boundaries, not existing classes or tables.

Future source implementations will prepare the same readable repository
snapshot/revision boundary before scanning. The Scanner should consume that
prepared input and must not branch on `local_directory`, `uploaded_directory`, or
`github_repository`. Snapshot creation and scanning belong to the next vertical
slice and are not implemented yet.

## Architecture

CodeAtlas is built as a modular Laravel monolith.

Main capabilities:

```text
Projects
    ↓
Project Sources
    ↓
Code Intelligence
    ↓
Indexing
    ↓
Retrieval
    ↓
AI
