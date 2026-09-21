<?php

namespace App\LaravelAnalysis;

use App\Models\CodeRelation;
use App\Models\ProjectRevision;
use App\PhpAnalysis\PhpFileAnalysis;
use App\PhpAnalysis\PhpFileInput;
use Illuminate\Support\Facades\DB;
use PhpParser\Node;

final class LaravelAsyncAnalyzer
{
    /** @var array<string, int> */
    private array $symbols = [];

    /** @var array<string, array<string, true>> */
    private array $roles = [];

    /** @var array<string, list<string>> */
    private array $ancestors = [];

    /** @var array<string, string> */
    private array $names = [];

    /** @var list<array{string, string, string, PhpFileInput, Node, string}> */
    private array $relations = [];

    /**
     * Runs inside revision persistence, using only the already parsed snapshot.
     *
     * @param  list<PhpFileAnalysis>  $analyses
     * @param  array<string, int>  $symbolIds
     */
    public function persist(ProjectRevision $revision, array $analyses, array $symbolIds): int
    {
        $this->symbols = [];
        $this->roles = [];
        $this->ancestors = [];
        $this->names = [];
        $this->relations = [];
        foreach ($symbolIds as $name => $id) {
            $key = strtolower($name);
            $this->symbols[$key] = $id;
            $this->names[$key] = $name;
        }
        foreach ($analyses as $analysis) {
            $local = [];
            foreach ($analysis->symbols as $symbol) {
                $local[$symbol->key] = strtolower($symbol->qualifiedName);
            }
            foreach ($analysis->relations as $relation) {
                if (in_array($relation->type, ['extends', 'implements', 'trait_use'], true)) {
                    $this->ancestors[$local[$relation->fromSymbolKey]][] = strtolower($relation->targetName);
                }
            }
        }

        foreach ($analyses as $analysis) {
            foreach ($analysis->modelCandidates as $class) {
                $name = (string) $class->namespacedName;
                if (str_contains(strtolower($name), '\\listeners\\')) {
                    $this->role($name, 'listener');
                }
                if (str_contains(strtolower($name), '\\events\\')
                    || $this->inherits($name, 'Illuminate\Foundation\Events\Dispatchable')) {
                    $this->role($name, 'event');
                }
                if (str_contains(strtolower($name), '\\jobs\\')
                    || $this->inherits($name, 'Illuminate\Foundation\Bus\Dispatchable')
                    || $this->inherits($name, 'Illuminate\Foundation\Queue\Queueable')) {
                    $this->role($name, 'job');
                }
            }
        }

        foreach ($analyses as $analysis) {
            $this->walk($analysis->statements, $analysis->file);
        }

        // Typed handlers describe potential discovery; they do not prove discovery is enabled.
        foreach ($analyses as $analysis) {
            foreach ($analysis->modelCandidates as $class) {
                $name = (string) $class->namespacedName;
                if (! isset($this->roles[strtolower($name)]['listener'])) {
                    continue;
                }
                foreach ($class->getMethods() as $method) {
                    if (! $method->isPublic() || $method->isStatic()
                        || (! str_starts_with($method->name->toString(), 'handle') && $method->name->toString() !== '__invoke')) {
                        continue;
                    }
                    $type = $method->params[0]->type ?? null;
                    $types = $type instanceof Node\UnionType ? $type->types : [$type];
                    foreach ($types as $event) {
                        if ($event instanceof Node\Name) {
                            $this->listen($name, $method->name->toString(), $event->toString(), $analysis->file, $method, 'typed_handler');
                        }
                    }
                }
            }
        }

        foreach ($this->roles as $name => $roles) {
            if ($this->queued($name)) {
                $roles['queued'] = true;
                if (isset($roles['job'])) {
                    $roles['queued_job'] = true;
                }
            }
            foreach (array_keys($roles) as $role) {
                DB::table('code_symbol_roles')->insert([
                    'project_id' => $revision->project_id,
                    'project_revision_id' => $revision->id,
                    'code_symbol_id' => $this->symbols[$name],
                    'role' => $role,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $count = 0;
        $seen = [];
        foreach ($this->relations as [$from, $type, $target, $file, $node, $evidence]) {
            $fromId = $this->symbols[strtolower($from)] ?? null;
            if ($fromId === null) {
                continue;
            }
            $key = implode(':', [$fromId, $type, strtolower($target), $file->codeFileId, $node->getStartLine(), $node->getEndLine(), $evidence]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $record = new CodeRelation([
                'type' => $type,
                'target_name' => $this->names[strtolower($target)] ?? $target,
                'start_line' => $node->getStartLine(),
                'end_line' => $node->getEndLine(),
                'metadata' => ['evidence' => $evidence],
            ]);
            $record->project()->associate($revision->project_id);
            $record->revision()->associate($revision);
            $record->codeFile()->associate($file->codeFileId);
            $record->fromSymbol()->associate($fromId);
            $record->toSymbol()->associate($this->symbols[strtolower($target)] ?? null);
            $record->save();
            $count++;
        }

        return $count;
    }

    private function role(string $name, string $role): void
    {
        $key = strtolower($name);
        if (isset($this->symbols[$key])) {
            $this->roles[$key][$role] = true;
        }
    }

    /** @param array<string, true> $seen */
    private function inherits(string $name, string $target, array $seen = []): bool
    {
        $name = strtolower($name);
        if ($name === strtolower($target)) {
            return true;
        }
        if (isset($seen[$name])) {
            return false;
        }
        $seen[$name] = true;
        foreach ($this->ancestors[$name] ?? [] as $parent) {
            if ($this->inherits($parent, $target, $seen)) {
                return true;
            }
        }

        return false;
    }

    private function queued(string $name): bool
    {
        return $this->inherits($name, 'Illuminate\Contracts\Queue\ShouldQueue')
            || $this->inherits($name, 'Illuminate\Contracts\Queue\ShouldQueueAfterCommit');
    }

    /** @param array<Node> $nodes */
    private function walk(array $nodes, PhpFileInput $file, ?string $source = null, ?string $class = null): void
    {
        foreach ($nodes as $node) {
            $nodeSource = $source;
            $nodeClass = $class;
            if ($node instanceof Node\Stmt\ClassLike) {
                if (! $node instanceof Node\Stmt\Class_ || $node->name === null) {
                    continue;
                }
                $nodeClass = (string) $node->namespacedName;
                $nodeSource = $nodeClass;
            } elseif ($node instanceof Node\Stmt\Namespace_) {
                $nodeSource = $node->name?->toString();
            } elseif ($node instanceof Node\Stmt\ClassMethod && $class !== null) {
                $nodeSource = $class.'::'.$node->name->toString();
            } elseif ($node instanceof Node\Stmt\Function_) {
                // The current PHP symbol index does not represent free functions.
                continue;
            }
            if ($node instanceof Node\Stmt\Property && $nodeClass !== null
                && $this->inherits($nodeClass, 'Illuminate\Foundation\Support\Providers\EventServiceProvider')) {
                foreach ($node->props as $property) {
                    if ($property->name->toString() !== 'listen' || ! $property->default instanceof Node\Expr\Array_) {
                        continue;
                    }
                    foreach ($property->default->items as $item) {
                        $event = $this->className($item->key, $nodeClass);
                        if ($event === null || ! $item->value instanceof Node\Expr\Array_) {
                            continue;
                        }
                        foreach ($item->value->items as $listener) {
                            if (! $listener->unpack) {
                                $this->registration($event, $listener->value, $file, $listener, $nodeClass);
                            }
                        }
                    }
                }
            }
            if ($node instanceof Node\Expr\CallLike && $nodeSource !== null && ! $node->isFirstClassCallable()) {
                $this->call($node, $nodeSource, $nodeClass, $file);
            }
            foreach ($node->getSubNodeNames() as $field) {
                $value = $node->$field;
                if ($value instanceof Node) {
                    $this->walk([$value], $file, $nodeSource, $nodeClass);
                } elseif (is_array($value)) {
                    $this->walk(array_values(array_filter($value, fn ($entry) => $entry instanceof Node)), $file, $nodeSource, $nodeClass);
                }
            }
        }
    }

    private function call(Node\Expr\CallLike $call, string $source, ?string $class, PhpFileInput $file): void
    {
        $method = null;
        $facade = null;
        if ($call instanceof Node\Expr\StaticCall && $call->name instanceof Node\Identifier && $call->class instanceof Node\Name) {
            $method = strtolower($call->name->toString());
            $facade = $this->resolveName($call->class, $class);
        } elseif ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name) {
            $method = strtolower($call->name->toString());
        }
        if ($method === null) {
            return;
        }
        $args = $call->getArgs();
        // Named/unpacked arguments require signature resolution; do not guess positions.
        foreach ($args as $arg) {
            if ($arg->unpack || $arg->name !== null) {
                return;
            }
        }
        $first = $args[0]->value ?? null;
        if (strtolower($facade ?? '') === 'illuminate\support\facades\event' && $method === 'listen') {
            $events = $first instanceof Node\Expr\Array_
                ? array_map(fn (Node\ArrayItem $item) => $item->unpack ? null : $this->className($item->value, $class), $first->items)
                : [$this->className($first, $class)];
            foreach ($events as $event) {
                if ($event !== null && isset($args[1])) {
                    $this->registration($event, $args[1]->value, $file, $call, $class);
                }
            }

            return;
        }

        $target = null;
        $kind = null;
        $sync = in_array($method, ['dispatchsync', 'dispatchnow', 'dispatch_sync'], true);
        if ($facade !== null && in_array($method, ['dispatch', 'dispatchif', 'dispatchunless', 'dispatchsync', 'dispatchafterresponse'], true)
            && ($this->inherits($facade, 'Illuminate\Foundation\Bus\Dispatchable')
                || $this->inherits($facade, 'Illuminate\Foundation\Queue\Queueable')
                || $this->inherits($facade, 'Illuminate\Foundation\Events\Dispatchable'))) {
            $target = $facade;
            $kind = $this->inherits($facade, 'Illuminate\Foundation\Events\Dispatchable') ? 'event' : 'job';
        } elseif (($facade === null && in_array($method, ['dispatch', 'dispatch_sync', 'event'], true))
            || (strtolower($facade ?? '') === 'illuminate\support\facades\bus' && in_array($method, ['dispatch', 'dispatchsync', 'dispatchnow'], true))
            || (strtolower($facade ?? '') === 'illuminate\support\facades\event' && in_array($method, ['dispatch', 'until'], true))) {
            $kind = $method === 'event' || strtolower($facade ?? '') === 'illuminate\support\facades\event' ? 'event' : 'job';
            $target = $first instanceof Node\Expr\New_ && $first->class instanceof Node\Name
                ? $this->resolveName($first->class, $class)
                : ($kind === 'event' ? $this->className($first, $class) : null);
        }
        if ($target === null || $kind === null) {
            return;
        }
        $this->role($target, $kind);
        $this->relations[] = [$source, 'dispatches', $target, $file, $call, $method];
        if ($kind === 'job' && ! $sync && $method !== 'dispatchafterresponse' && $this->queued($target)) {
            $this->relations[] = [$source, 'queues', $target, $file, $call, $method];
        }
    }

    private function registration(string $event, Node\Expr $expression, PhpFileInput $file, Node $node, ?string $class): void
    {
        $method = 'handle';
        if ($expression instanceof Node\Expr\Array_) {
            if (count($expression->items) !== 2 || $expression->items[0]->unpack || $expression->items[1]->unpack
                || ! $expression->items[1]->value instanceof Node\Scalar\String_) {
                return;
            }
            $listener = $this->className($expression->items[0]->value, $class);
            $method = $expression->items[1]->value->value;
        } else {
            $listener = $this->className($expression, $class);
            if ($listener !== null && str_contains($listener, '@')) {
                [$listener, $method] = explode('@', $listener, 2);
            }
        }
        if ($listener !== null && $method !== '') {
            $this->listen($listener, $method, $event, $file, $node, 'registration');
        }
    }

    private function listen(string $listener, string $method, string $event, PhpFileInput $file, Node $node, string $evidence): void
    {
        $this->role($listener, 'listener');
        $this->role($event, 'event');
        $this->relations[] = [$listener, 'listens_to', $event, $file, $node, $evidence];
        $this->relations[] = [$listener.'::'.$method, 'handles', $event, $file, $node, $evidence];
    }

    private function className(?Node\Expr $expression, ?string $class): ?string
    {
        if ($expression instanceof Node\Scalar\String_ && $expression->value !== '') {
            return ltrim($expression->value, '\\');
        }
        if ($expression instanceof Node\Expr\ClassConstFetch && $expression->class instanceof Node\Name
            && $expression->name instanceof Node\Identifier && strtolower($expression->name->toString()) === 'class') {
            return $this->resolveName($expression->class, $class);
        }

        return null;
    }

    private function resolveName(Node\Name $name, ?string $class): ?string
    {
        return match (strtolower($name->toString())) {
            'self' => $class,
            'parent', 'static' => null,
            default => $name->toString(),
        };
    }
}
