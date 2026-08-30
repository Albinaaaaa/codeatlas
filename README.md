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
- Laravel Horizon
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
