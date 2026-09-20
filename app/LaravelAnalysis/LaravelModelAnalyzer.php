<?php

namespace App\LaravelAnalysis;

use App\Models\AnalysisIssue;
use App\Models\IndexRun;
use App\Models\LaravelModel;
use App\Models\LaravelModelRelation;
use App\Models\ProjectRevision;
use App\PhpAnalysis\PhpFileAnalysis;
use App\PhpAnalysis\PhpFileInput;
use Illuminate\Support\Str;
use LogicException;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class LaravelModelAnalyzer
{
    /** @var array<string, list<string>> */
    private const RELATIONS = [
        'hasOne' => ['related', 'foreignKey', 'localKey'],
        'hasMany' => ['related', 'foreignKey', 'localKey'],
        'belongsTo' => ['related', 'foreignKey', 'ownerKey', 'relation'],
        'belongsToMany' => ['related', 'table', 'foreignPivotKey', 'relatedPivotKey', 'parentKey', 'relatedKey', 'relation'],
        'hasOneThrough' => ['related', 'through', 'firstKey', 'secondKey', 'localKey', 'secondLocalKey'],
        'hasManyThrough' => ['related', 'through', 'firstKey', 'secondKey', 'localKey', 'secondLocalKey'],
        'morphOne' => ['related', 'name', 'type', 'id', 'localKey'],
        'morphMany' => ['related', 'name', 'type', 'id', 'localKey'],
        'morphTo' => ['name', 'type', 'id', 'ownerKey'],
        'morphToMany' => ['related', 'name', 'table', 'foreignPivotKey', 'relatedPivotKey', 'parentKey', 'relatedKey', 'relation', 'inverse'],
        'morphedByMany' => ['related', 'name', 'table', 'foreignPivotKey', 'relatedPivotKey', 'parentKey', 'relatedKey', 'relation'],
    ];

    /** @var list<string> */
    private const ROOTS = [
        'illuminate\database\eloquent\model',
        'illuminate\foundation\auth\user',
        'illuminate\database\eloquent\relations\pivot',
        'illuminate\database\eloquent\relations\morphpivot',
    ];

    /** @var list<string> */
    private const KNOWN_TRAITS = [
        'Illuminate\Database\Eloquent\Factories\HasFactory',
        'Illuminate\Database\Eloquent\SoftDeletes',
        'Illuminate\Notifications\Notifiable',
        'Laravel\Fortify\TwoFactorAuthenticatable',
    ];

    /**
     * Called inside the PHP revision persistence transaction, after symbols exist.
     *
     * @param  list<PhpFileAnalysis>  $analyses
     * @param  array<string, int>  $symbolIds
     * @return array{models: int, relations: int, issues: int}
     */
    public function persist(ProjectRevision $revision, array $analyses, array $symbolIds, ?IndexRun $run): array
    {
        $classes = [];
        foreach ($analyses as $analysis) {
            foreach ($analysis->modelCandidates as $class) {
                $key = strtolower((string) $class->namespacedName);
                $classes[$key] = array_key_exists($key, $classes) ? null : [$class, $analysis->file];
            }
        }

        $models = [];
        $issues = 0;
        foreach ($classes as $key => $entry) {
            if ($entry === null) {
                continue;
            }
            [$class, $file] = $entry;
            $hierarchy = $this->hierarchy($key, $classes);
            if ($hierarchy === []) {
                continue;
            }

            $configuration = [];
            $methods = [];
            $traits = [];
            foreach ($hierarchy as [$ancestor, $ancestorFile]) {
                foreach ($ancestor->getProperties() as $property) {
                    foreach ($property->props as $prop) {
                        $name = $prop->name->toString();
                        if (! in_array($name, ['table', 'connection', 'primaryKey', 'keyType', 'incrementing', 'fillable', 'guarded', 'casts'], true)) {
                            continue;
                        }
                        try {
                            $configuration[$name] = $this->literal($prop->default, $ancestor);
                        } catch (LogicException) {
                            $configuration[$name] = null;
                        }
                    }
                }
                foreach ($ancestor->getMethods() as $method) {
                    $methods[strtolower($method->name->toString())] = [$method, $ancestor, $ancestorFile];
                }
                foreach ($ancestor->stmts as $statement) {
                    if ($statement instanceof Node\Stmt\TraitUse) {
                        foreach ($statement->traits as $trait) {
                            $traits[] = $trait->toString();
                        }
                    }
                }
            }
            if (isset($methods['casts'])) {
                [$method, $declaring] = $methods['casts'];
                try {
                    $casts = $this->literal($this->returnedExpression($method), $declaring);
                    $configuration['casts'] = is_array($casts) && is_array($configuration['casts'] ?? [])
                        ? array_merge($configuration['casts'] ?? [], $casts)
                        : null;
                } catch (LogicException) {
                    $configuration['casts'] = null;
                }
            }
            $unknownTraits = array_values(array_diff($traits, self::KNOWN_TRAITS));
            $table = $configuration['table'] ?? null;
            $tableSource = array_key_exists('table', $configuration) ? 'explicit' : 'convention';
            if (! array_key_exists('table', $configuration)) {
                $table = Str::snake(Str::pluralStudly($class->name?->toString() ?? ''));
            }
            if (isset($methods['gettable']) || isset($methods['__construct']) || $unknownTraits !== [] || $class->attrGroups !== []) {
                $table = null;
                $tableSource = 'unresolved';
            }
            $name = (string) $class->namespacedName;
            $record = new LaravelModel([
                'table_name' => is_string($table) ? $table : null,
                'connection' => is_string($configuration['connection'] ?? null) ? $configuration['connection'] : null,
                'traits' => array_values(array_unique($traits)),
                'metadata' => [
                    'class' => $name,
                    'code_file_id' => $file->codeFileId,
                    'source_path' => $file->path,
                    'start_line' => $class->getStartLine(),
                    'end_line' => $class->getEndLine(),
                    'abstract' => $class->isAbstract(),
                    'table_source' => $tableSource,
                    'configuration' => $configuration,
                ],
            ]);
            $record->project()->associate($revision->project_id);
            $record->revision()->associate($revision);
            $record->codeSymbol()->associate($symbolIds[$name]);
            $record->save();
            $id = (int) $record->id;
            $models[$key] = [$id, $methods, $unknownTraits];
            if ($unknownTraits !== []) {
                $this->issue($revision, $run, $file, $class, 'Trait composition is unsupported for '.$name.'.');
                $issues++;
            }
        }

        $relations = 0;
        foreach ($models as [$id, $methods, $unknownTraits]) {
            foreach ($methods as [$method, $declaring, $file]) {
                if (! $this->isRelationCandidate($method)) {
                    continue;
                }
                try {
                    if ($unknownTraits !== [] || ! $method->isPublic() || $method->isStatic() || $method->params !== []) {
                        throw new LogicException('Unsupported relationship method or trait composition.');
                    }
                    $expression = $this->returnedExpression($method);
                    if (! $expression instanceof Node\Expr\MethodCall || ! $expression->name instanceof Node\Identifier
                        || ! $expression->var instanceof Node\Expr\Variable || $expression->var->name !== 'this') {
                        throw new LogicException('Relationship must directly return an Eloquent builder call.');
                    }
                    $type = $expression->name->toString();
                    if (! isset(self::RELATIONS[$type]) || isset($methods[strtolower($type)])) {
                        throw new LogicException('Unknown or overridden relationship builder.');
                    }
                    $arguments = $this->arguments($expression, self::RELATIONS[$type], $declaring);
                    $related = $arguments['related'] ?? null;
                    if ($type !== 'morphTo' && (! is_string($related) || $related === '')) {
                        throw new LogicException('Related model is not a static class name.');
                    }
                    foreach (['related', 'through'] as $classArgument) {
                        if (isset($arguments[$classArgument]) && (! is_string($arguments[$classArgument])
                            || ! preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/', ltrim($arguments[$classArgument], '\\')))) {
                            throw new LogicException('Invalid model class argument.');
                        }
                    }
                    if (str_ends_with($type, 'Through') && ! is_string($arguments['through'] ?? null)) {
                        throw new LogicException('Through model is unresolved.');
                    }
                    if (in_array($type, ['morphOne', 'morphMany', 'morphToMany', 'morphedByMany'], true) && ! is_string($arguments['name'] ?? null)) {
                        throw new LogicException('Polymorphic name is unresolved.');
                    }
                    $related = is_string($related) ? ltrim($related, '\\') : '*';
                } catch (LogicException $exception) {
                    $this->issue($revision, $run, $file, $method, $exception->getMessage());
                    $issues++;

                    continue;
                }

                $record = new LaravelModelRelation([
                    'name' => $method->name->toString(),
                    'relation_type' => $type,
                    // morphTo has no single target; the existing column is non-nullable.
                    'related_model' => $related,
                    'foreign_key' => $arguments['foreignKey'] ?? $arguments['foreignPivotKey'] ?? $arguments['firstKey'] ?? $arguments['id'] ?? null,
                    'local_key' => $arguments['localKey'] ?? $arguments['parentKey'] ?? null,
                    'pivot_table' => isset($arguments['table']) && ! str_contains((string) $arguments['table'], '\\') ? $arguments['table'] : null,
                    'start_line' => $method->getStartLine(),
                    'end_line' => $method->getEndLine(),
                    'metadata' => [
                        'arguments' => $arguments,
                        'polymorphic' => str_starts_with($type, 'morph'),
                        'declaring_class' => (string) $declaring->namespacedName,
                        'method_symbol_id' => $symbolIds[(string) $declaring->namespacedName.'::'.$method->name->toString()] ?? null,
                        'source_path' => $file->path,
                    ],
                ]);
                $record->project()->associate($revision->project_id);
                $record->revision()->associate($revision);
                $record->model()->associate($id);
                $record->relatedModel()->associate($models[strtolower($related)][0] ?? null);
                $record->codeFile()->associate($file->codeFileId);
                $record->save();
                $relations++;
            }
        }

        return ['models' => count($models), 'relations' => $relations, 'issues' => $issues];
    }

    /**
     * @param  array<string, array{Node\Stmt\Class_, PhpFileInput}|null>  $classes
     * @return list<array{Node\Stmt\Class_, PhpFileInput}>
     */
    private function hierarchy(string $key, array $classes): array
    {
        $hierarchy = [];
        $seen = [];
        while (isset($classes[$key]) && ! isset($seen[$key])) {
            $seen[$key] = true;
            [$class, $file] = $classes[$key];
            $hierarchy[] = [$class, $file];
            $key = strtolower($class->extends?->toString() ?? '');
            if (! array_key_exists($key, $classes) && in_array($key, self::ROOTS, true)) {
                return array_reverse($hierarchy);
            }
        }

        return [];
    }

    private function returnedExpression(Node\Stmt\ClassMethod $method): Node\Expr
    {
        if (count($method->stmts ?? []) !== 1 || ! $method->stmts[0] instanceof Node\Stmt\Return_
            || $method->stmts[0]->expr === null) {
            throw new LogicException('Conditional, multi-statement or dynamic method body is unsupported.');
        }

        return $method->stmts[0]->expr;
    }

    private function isRelationCandidate(Node\Stmt\ClassMethod $method): bool
    {
        if ($method->returnType instanceof Node\Name && str_starts_with($method->returnType->toString(), 'Illuminate\Database\Eloquent\Relations\\')) {
            return true;
        }

        return (new NodeFinder)->findFirst($method->stmts ?? [], static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier && (isset(self::RELATIONS[$node->name->toString()]) || $node->name->toString() === 'through')) !== null;
    }

    /**
     * @param  list<string>  $parameters
     * @return array<string, string|bool|null>
     */
    private function arguments(Node\Expr\MethodCall $call, array $parameters, Node\Stmt\Class_ $class): array
    {
        $values = [];
        $named = false;
        foreach ($call->args as $index => $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack) {
                throw new LogicException('Unpacked or callable relationship arguments are unsupported.');
            }
            if ($named && $argument->name === null) {
                throw new LogicException('Positional argument after named argument.');
            }
            $named = $named || $argument->name !== null;
            $name = $argument->name?->toString() ?? $parameters[$index] ?? '';
            if (! in_array($name, $parameters, true) || array_key_exists($name, $values)) {
                throw new LogicException('Unknown or repeated relationship argument.');
            }
            $value = $this->literal($argument->value, $class);
            if (($name === 'inverse' && ! is_bool($value)) || ($name !== 'inverse' && $value !== null && ! is_string($value))) {
                throw new LogicException('Relationship argument is not a supported literal.');
            }
            // static::class depends on the eventual runtime subclass.
            if ($argument->value instanceof Node\Expr\ClassConstFetch && $argument->value->class instanceof Node\Name
                && strtolower($argument->value->class->toString()) === 'static') {
                throw new LogicException('Late static binding is unresolved.');
            }
            $values[$name] = $value;
        }

        return $values;
    }

    private function literal(?Node\Expr $value, Node\Stmt\Class_ $class): mixed
    {
        if ($value instanceof Node\Scalar\String_ || $value instanceof Node\Scalar\Int_ || $value instanceof Node\Scalar\Float_) {
            return $value->value;
        }
        if ($value instanceof Node\Expr\ConstFetch) {
            return match (strtolower($value->name->toString())) {
                'null' => null,
                'true' => true,
                'false' => false,
                default => throw new LogicException('Unresolved constant.'),
            };
        }
        if ($value instanceof Node\Expr\ClassConstFetch && $value->name instanceof Node\Identifier
            && strtolower($value->name->toString()) === 'class' && $value->class instanceof Node\Name) {
            return match (strtolower($value->class->toString())) {
                'self' => (string) $class->namespacedName,
                'parent' => $class->extends?->toString(),
                'static' => throw new LogicException('Late static binding is unresolved.'),
                default => $value->class->toString(),
            };
        }
        if ($value instanceof Node\Expr\Array_) {
            $result = [];
            foreach ($value->items as $item) {
                if ($item->unpack || $item->byRef) {
                    throw new LogicException('Unsupported array item.');
                }
                $entry = $this->literal($item->value, $class);
                if ($item->key === null) {
                    $result[] = $entry;
                } else {
                    $key = $this->literal($item->key, $class);
                    if (! is_string($key) && ! is_int($key)) {
                        throw new LogicException('Unsupported array key.');
                    }
                    $result[$key] = $entry;
                }
            }

            return $result;
        }

        throw new LogicException('Expression cannot be evaluated statically.');
    }

    private function issue(ProjectRevision $revision, ?IndexRun $run, PhpFileInput $file, Node $node, string $description): void
    {
        $issue = new AnalysisIssue([
            'severity' => 'info',
            'category' => 'laravel_model',
            'code' => 'laravel_model.unsupported_definition',
            'title' => 'Model definition could not be fully resolved',
            'description' => $description,
            'source_path' => $file->path,
            'start_line' => $node->getStartLine(),
            'end_line' => $node->getEndLine(),
        ]);
        $issue->project()->associate($revision->project_id);
        $issue->revision()->associate($revision);
        $issue->run()->associate($run);
        $issue->codeFile()->associate($file->codeFileId);
        $issue->save();
    }
}
