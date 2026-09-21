<?php

namespace App\LaravelAnalysis;

use App\Models\AnalysisIssue;
use App\Models\IndexRun;
use App\Models\ProjectRevision;
use App\PhpAnalysis\PhpFileAnalysis;
use App\PhpAnalysis\PhpFileInput;
use Illuminate\Support\Facades\DB;
use PhpParser\Node;

/**
 * Reads migration ASTs only. It deliberately never loads or executes the target application.
 */
final class LaravelMigrationAnalyzer
{
    /** @var array<string, int> */
    private array $tables = [];

    /**
     * @param  list<PhpFileAnalysis>  $analyses
     * @return array{tables:int, columns:int, indexes:int, foreign_keys:int, issues:int}
     */
    public function persist(ProjectRevision $revision, array $analyses, ?IndexRun $run): array
    {
        $this->tables = [];
        $scope = fn (string $table) => DB::table($table)->where('project_id', $revision->project_id)->where('project_revision_id', $revision->id);
        $scope('database_foreign_key_columns')->delete();
        $scope('database_index_columns')->delete();
        $scope('database_foreign_keys')->delete();
        $scope('database_indexes')->delete();
        $scope('database_columns')->delete();
        $scope('database_tables')->delete();
        $count = $this->emptyCount();
        /** @var array<string, int> $count */
        $migrationFiles = array_filter($analyses, fn (PhpFileAnalysis $analysis): bool => str_starts_with($analysis->file->path, 'database/migrations/'));

        foreach ($migrationFiles as $analysis) {
            $this->walk($analysis->statements, $analysis->file, $revision, $run, $count);
        }

        return $count;
    }

    /**
     * @param  list<Node>  $nodes
     * @param  array<string, int>  $count
     */
    private function walk(array $nodes, PhpFileInput $file, ProjectRevision $revision, ?IndexRun $run, array &$count): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Expr\StaticCall
                && $node->class instanceof Node\Name
                && $node->name instanceof Node\Identifier
                && str_ends_with(strtolower($node->class->toString()), '\\schema')
                && in_array(strtolower($node->name->toString()), ['create', 'table'], true)) {
                $this->schemaCall($node, $file, $revision, $run, $count);
            }
            foreach ($node->getSubNodeNames() as $field) {
                $value = $node->$field;
                if ($value instanceof Node) {
                    $this->walk([$value], $file, $revision, $run, $count);
                } elseif (is_array($value)) {
                    $this->walk(array_values(array_filter($value, fn ($entry): bool => $entry instanceof Node)), $file, $revision, $run, $count);
                }
            }
        }
    }

    /** @param array<string, int> $count */
    private function schemaCall(Node\Expr\StaticCall $call, PhpFileInput $file, ProjectRevision $revision, ?IndexRun $run, array &$count): void
    {
        $table = $this->string($call->args[0]->value ?? null);
        $callback = $call->args[1]->value ?? null;
        if ($table === null || ! $callback instanceof Node\Expr\Closure || $callback->params === []) {
            $this->issue($revision, $run, $file, $call, 'Migration table name or Blueprint callback is dynamic.');
            $count['issues']++;

            return;
        }

        $operation = strtolower($call->name->toString());
        $id = $operation === 'table'
            ? ($this->tables[$table] ?? DB::table('database_tables')->where('project_revision_id', $revision->id)->where('name', $table)->value('id'))
            : null;
        if ($operation === 'table' && $id === null) {
            $this->issue($revision, $run, $file, $call, 'Schema::table references a table that is not statically known.');
            $count['issues']++;

            return;
        }
        $id ??= DB::table('database_tables')->insertGetId([
            'project_id' => $revision->project_id,
            'project_revision_id' => $revision->id,
            'schema_name' => 'public',
            'name' => $table,
            'type' => 'table',
            'metadata' => json_encode($this->provenance($file, $call)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->tables[$table] = $id;
        if ($operation === 'create') {
            $count['tables']++;
        }
        $variable = $callback->params[0]->var instanceof Node\Expr\Variable ? $callback->params[0]->var->name : null;
        if (! is_string($variable)) {
            $this->issue($revision, $run, $file, $callback, 'Blueprint variable is not statically resolvable.');
            $count['issues']++;

            return;
        }
        foreach ($callback->stmts as $statement) {
            if ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\MethodCall
                && $this->baseVariable($statement->expr) === $variable) {
                $this->blueprintCall($statement->expr, $table, $id, $file, $revision, $run, $count);
            }
        }
    }

    /** @param array<string, int> $count */
    private function blueprintCall(Node\Expr\MethodCall $call, string $table, int $tableId, PhpFileInput $file, ProjectRevision $revision, ?IndexRun $run, array &$count): void
    {
        [$root, $modifiers] = $this->chain($call);
        if (! $root->name instanceof Node\Identifier) {
            return;
        }
        $method = strtolower($root->name->toString());
        $args = array_map(fn (Node\Arg $arg): mixed => $this->literal($arg->value), $root->args);
        if (in_array($method, ['primary', 'unique', 'index'], true)) {
            $columns = $this->strings($args[0] ?? null);
            if ($columns === []) {
                $this->unsupported($revision, $run, $file, $root, $count);

                return;
            }
            $name = is_string($args[1] ?? null) ? $args[1] : $table.'_'.$method.'_'.implode('_', $columns);
            $indexId = DB::table('database_indexes')->insertGetId([
                'project_id' => $revision->project_id, 'project_revision_id' => $revision->id, 'database_table_id' => $tableId,
                'name' => $name, 'method' => 'btree', 'is_unique' => $method === 'unique', 'is_primary' => $method === 'primary',
                'metadata' => json_encode($this->provenance($file, $root)), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($columns as $position => $column) {
                DB::table('database_index_columns')->insert([
                    'project_id' => $revision->project_id, 'project_revision_id' => $revision->id, 'database_index_id' => $indexId,
                    'database_column_id' => DB::table('database_columns')->where('database_table_id', $tableId)->where('name', $column)->value('id'),
                    'ordinal_position' => $position + 1, 'expression' => $column, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $count['indexes']++;

            return;
        }
        if ($method === 'foreign') {
            $columns = $this->strings($args[0] ?? null);
            $chainArgs = $this->chainArguments($modifiers);
            $references = $this->strings($chainArgs['references'][0] ?? null);
            $referencedTable = is_string($chainArgs['on'][0] ?? null) ? $chainArgs['on'][0] : null;
            if ($columns === [] || $references === [] || $referencedTable === null || count($columns) !== count($references)) {
                $this->unsupported($revision, $run, $file, $root, $count);

                return;
            }
            $fkId = DB::table('database_foreign_keys')->insertGetId([
                'project_id' => $revision->project_id, 'project_revision_id' => $revision->id, 'database_table_id' => $tableId,
                'referenced_database_table_id' => $this->tables[$referencedTable] ?? null,
                'name' => (is_string($args[1] ?? null) ? $args[1] : $table.'_'.implode('_', $columns).'_foreign'),
                'referenced_schema_name' => 'public', 'referenced_table_name' => $referencedTable,
                'on_update' => $this->chainValue($chainArgs, 'onupdate'), 'on_delete' => $this->chainValue($chainArgs, 'ondelete'),
                'metadata' => json_encode($this->provenance($file, $root)), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($columns as $position => $column) {
                DB::table('database_foreign_key_columns')->insert([
                    'project_id' => $revision->project_id, 'project_revision_id' => $revision->id, 'database_foreign_key_id' => $fkId,
                    'database_column_id' => DB::table('database_columns')->where('database_table_id', $tableId)->where('name', $column)->value('id'),
                    'ordinal_position' => $position + 1, 'referenced_column_name' => $references[$position], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $count['foreign_keys']++;

            return;
        }
        $types = ['id' => 'bigint', 'bigincrements' => 'bigint', 'increments' => 'integer', 'integer' => 'integer', 'biginteger' => 'bigint', 'string' => 'varchar', 'text' => 'text', 'boolean' => 'boolean', 'date' => 'date', 'datetime' => 'datetime', 'timestamp' => 'timestamp', 'uuid' => 'uuid', 'foreignid' => 'bigint'];
        if (! isset($types[$method]) || (! is_string($args[0] ?? null) && ! in_array($method, ['id', 'increments', 'bigincrements'], true))) {
            $this->unsupported($revision, $run, $file, $root, $count);

            return;
        }
        $column = is_string($args[0] ?? null) ? $args[0] : ($method === 'id' || str_ends_with($method, 'increments') ? 'id' : null);
        if ($column === null) {
            $this->unsupported($revision, $run, $file, $root, $count);

            return;
        }
        $metadata = ['source_path' => $file->path, 'start_line' => $root->getStartLine(), 'end_line' => $root->getEndLine()];
        $modifierNames = array_values(array_filter(array_map(fn (array $modifier): ?string => $modifier['name'] ?? null, $modifiers)));
        $nullable = in_array('nullable', $modifierNames, true);
        $default = null;
        foreach ($modifiers as $modifier) {
            if (($modifier['name'] ?? null) === 'default') {
                $default = $modifier['value'] ?? null;
            }
        }
        DB::table('database_columns')->insert([
            'project_id' => $revision->project_id, 'project_revision_id' => $revision->id, 'database_table_id' => $tableId,
            'name' => $column, 'ordinal_position' => DB::table('database_columns')->where('database_table_id', $tableId)->count() + 1,
            'data_type' => $types[$method], 'native_type' => $method, 'is_nullable' => $nullable,
            'default_value' => is_scalar($default) ? (string) $default : null, 'metadata' => json_encode($metadata), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $count['columns']++;
        $constrained = collect($modifiers)->first(fn (array $modifier): bool => ($modifier['name'] ?? null) === 'constrained');
        if ($constrained !== null) {
            $referencedTable = is_string($constrained['value'] ?? null) ? $constrained['value'] : rtrim($column, '_id').'s';
            $foreignKeyId = DB::table('database_foreign_keys')->insertGetId([
                'project_id' => $revision->project_id, 'project_revision_id' => $revision->id, 'database_table_id' => $tableId,
                'referenced_database_table_id' => $this->tables[$referencedTable] ?? null,
                'name' => $table.'_'.$column.'_foreign', 'referenced_schema_name' => 'public', 'referenced_table_name' => $referencedTable,
                'metadata' => json_encode($this->provenance($file, $root)), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('database_foreign_key_columns')->insert([
                'project_id' => $revision->project_id, 'project_revision_id' => $revision->id, 'database_foreign_key_id' => $foreignKeyId,
                'database_column_id' => DB::table('database_columns')->where('database_table_id', $tableId)->where('name', $column)->value('id'),
                'ordinal_position' => 1, 'referenced_column_name' => 'id', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $count['foreign_keys']++;
        }
        if ($method === 'id' || $method === 'increments' || $method === 'bigincrements' || in_array('primary', $modifierNames, true) || in_array('unique', $modifierNames, true) || in_array('index', $modifierNames, true)) {
            $kind = ($method === 'id' || $method === 'increments' || $method === 'bigincrements' || in_array('primary', $modifierNames, true)) ? 'primary' : (in_array('unique', $modifierNames, true) ? 'unique' : 'index');
            $this->blueprintCall(new Node\Expr\MethodCall(new Node\Expr\Variable('table'), new Node\Identifier($kind), [new Node\Arg(new Node\Scalar\String_($column))]), $table, $tableId, $file, $revision, $run, $count);
        }
    }

    /** @return array{Node\Expr\MethodCall, list<array<string, mixed>>} */
    private function chain(Node\Expr\MethodCall $call): array
    {
        $modifiers = [];
        while ($call->var instanceof Node\Expr\MethodCall) {
            $name = $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : '';
            $modifiers[] = ['name' => $name, 'value' => $this->literal($call->args[0]->value ?? null), 'args' => array_map(fn (Node\Arg $arg): mixed => $this->literal($arg->value), $call->args)];
            $call = $call->var;
        }

        return [$call, array_reverse($modifiers)];
    }

    /**
     * @param  list<array<string,mixed>>  $modifiers
     * @return array<string, list<mixed>>
     */
    private function chainArguments(array $modifiers): array
    {
        $arguments = [];
        foreach ($modifiers as $modifier) {
            if (isset($modifier['name'], $modifier['args'])) {
                $arguments[$modifier['name']] = $modifier['args'];
            }
        }

        return $arguments;
    }

    /** @param array<string, list<mixed>> $args */
    private function chainValue(array $args, string $name): ?string
    {
        return isset($args[$name][0]) && is_string($args[$name][0]) ? $args[$name][0] : null;
    }

    private function baseVariable(Node\Expr\MethodCall $call): ?string
    {
        while ($call->var instanceof Node\Expr\MethodCall) {
            $call = $call->var;
        }

        return $call->var instanceof Node\Expr\Variable && is_string($call->var->name) ? $call->var->name : null;
    }

    /** @return array{tables:int, columns:int, indexes:int, foreign_keys:int, issues:int} */
    private function emptyCount(): array
    {
        return ['tables' => 0, 'columns' => 0, 'indexes' => 0, 'foreign_keys' => 0, 'issues' => 0];
    }

    private function literal(?Node $node): mixed
    {
        if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_ || $node instanceof Node\Scalar\Float_) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\ConstFetch) {
            return match (strtolower($node->name->toString())) {
                'null' => null, 'true' => true, 'false' => false, default => null
            };
        }
        if ($node instanceof Node\Expr\Array_) {
            return array_map(fn (Node\ArrayItem $item): mixed => $this->literal($item->value), $node->items);
        }

        return null;
    }

    private function string(?Node $node): ?string
    {
        $value = $this->literal($node);

        return is_string($value) ? $value : null;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return is_string($value) ? [$value] : (is_array($value) && count(array_filter($value, 'is_string')) === count($value) ? array_values($value) : []);
    }

    /** @return array{source_path:string, start_line:int, end_line:int} */
    private function provenance(PhpFileInput $file, Node $node): array
    {
        return ['source_path' => $file->path, 'start_line' => $node->getStartLine(), 'end_line' => $node->getEndLine()];
    }

    /** @param array<string,int> $count */
    private function unsupported(ProjectRevision $revision, ?IndexRun $run, PhpFileInput $file, Node $node, array &$count): void
    {
        $this->issue($revision, $run, $file, $node, 'Migration construct is dynamic or unsupported.');
        $count['issues']++;
    }

    private function issue(ProjectRevision $revision, ?IndexRun $run, PhpFileInput $file, Node $node, string $description): void
    {
        $issue = new AnalysisIssue(['severity' => 'info', 'category' => 'laravel_migration', 'code' => 'laravel_migration.unsupported', 'title' => 'Migration schema could not be fully resolved', 'description' => $description, 'source_path' => $file->path, 'start_line' => $node->getStartLine(), 'end_line' => $node->getEndLine()]);
        $issue->project()->associate($revision->project_id);
        $issue->revision()->associate($revision);
        $issue->run()->associate($run);
        $issue->codeFile()->associate($file->codeFileId);
        $issue->save();
    }
}
