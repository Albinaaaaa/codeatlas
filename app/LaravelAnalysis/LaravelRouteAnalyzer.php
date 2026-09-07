<?php

namespace App\LaravelAnalysis;

use App\PhpAnalysis\PhpAnalysisIssue;
use App\PhpAnalysis\PhpFileInput;
use PhpParser\Node;

final class LaravelRouteAnalyzer
{
    /** @var list<LaravelRouteDefinition> */
    private array $routes = [];

    /** @var list<PhpAnalysisIssue> */
    private array $issues = [];

    /**
     * @param  array<Node>  $statements
     */
    public function analyze(PhpFileInput $file, array $statements): LaravelRouteFileAnalysis
    {
        $this->routes = [];
        $this->issues = [];
        $this->analyzeStatements($statements, new LaravelRouteGroupContext);

        return new LaravelRouteFileAnalysis($this->routes, $this->issues);
    }

    /**
     * @param  array<Node>  $statements
     */
    private function analyzeStatements(array $statements, LaravelRouteGroupContext $context): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_) {
                $this->analyzeStatements($statement->stmts, $context);

                continue;
            }

            if (! $statement instanceof Node\Stmt\Expression) {
                if ($this->containsRouteCall($statement)) {
                    $this->issues[] = $this->issue(
                        'laravel_route.dynamic_context',
                        'Route declaration in a dynamic control-flow context was skipped',
                        $statement,
                    );
                }

                continue;
            }

            $chain = $this->callChain($statement->expr);

            if ($chain === null || $chain === []) {
                continue;
            }

            $lastCall = $chain[array_key_last($chain)];

            if ($lastCall['name'] === 'group') {
                $this->analyzeGroup($chain, $context);
            } else {
                $this->analyzeRoute($chain, $context);
            }
        }
    }

    /**
     * @param  list<array{name: string, args: array<Node\Arg>, node: Node}>  $chain
     */
    private function analyzeGroup(array $chain, LaravelRouteGroupContext $context): void
    {
        $groupCall = array_pop($chain);

        if ($groupCall === null) {
            return;
        }

        $groupContext = $context;

        if ($chain === [] && isset($groupCall['args'][0], $groupCall['args'][1])) {
            $groupContext = $this->applyGroupOptions($groupContext, $groupCall['args'][0]->value, $groupCall['node']);
            $closure = $groupCall['args'][1]->value;
        } else {
            foreach ($chain as $modifier) {
                $groupContext = $this->applyGroupModifier($groupContext, $modifier);
            }

            $closure = $groupCall['args'][0]->value ?? null;
        }

        if (! $closure instanceof Node\Expr\Closure) {
            $this->issues[] = $this->issue(
                'laravel_route.dynamic_group',
                'Route group callback could not be resolved statically',
                $groupCall['node'],
            );

            return;
        }

        if (! $groupContext->resolvable) {
            return;
        }

        $this->analyzeStatements($closure->stmts, $groupContext);
    }

    /**
     * @param  list<array{name: string, args: array<Node\Arg>, node: Node}>  $chain
     */
    private function analyzeRoute(array $chain, LaravelRouteGroupContext $context): void
    {
        $registration = array_shift($chain);

        if ($registration === null) {
            return;
        }

        $methods = $this->httpMethods($registration);

        if ($methods === null) {
            return;
        }

        $uriArgument = $registration['args'][$registration['name'] === 'match' ? 1 : 0]->value ?? null;
        $actionArgument = $registration['args'][$registration['name'] === 'match' ? 2 : 1]->value ?? null;
        $uri = $this->stringValue($uriArgument);

        if ($uri === null || $actionArgument === null) {
            $this->issues[] = $this->issue(
                'laravel_route.dynamic_definition',
                'Route URI or action could not be resolved statically',
                $registration['node'],
            );

            return;
        }

        $action = $this->action($actionArgument, $context->controller);

        if ($action === null) {
            $this->issues[] = $this->issue(
                'laravel_route.dynamic_action',
                'Route action could not be resolved statically',
                $registration['node'],
            );

            return;
        }

        $name = null;
        $middleware = $context->middleware;
        $resolvable = true;

        foreach ($chain as $modifier) {
            if ($modifier['name'] === 'name') {
                $resolvedName = $this->stringValue($modifier['args'][0]->value ?? null);

                if ($resolvedName === null) {
                    $this->issues[] = $this->issue(
                        'laravel_route.dynamic_name',
                        'Route name could not be resolved statically',
                        $modifier['node'],
                    );
                    $resolvable = false;
                } else {
                    $name = $context->namePrefix.$resolvedName;
                }
            } elseif ($modifier['name'] === 'middleware') {
                $resolvedMiddleware = $this->stringList($modifier['args'][0]->value ?? null);

                if ($resolvedMiddleware === null) {
                    $this->issues[] = $this->issue(
                        'laravel_route.dynamic_middleware',
                        'Route middleware could not be resolved statically',
                        $modifier['node'],
                    );
                    $resolvable = false;
                } else {
                    $middleware = $this->mergeMiddleware($middleware, $resolvedMiddleware);
                }
            }
        }

        if (! $resolvable) {
            return;
        }

        $endNode = $chain === [] ? $registration['node'] : $chain[array_key_last($chain)]['node'];

        foreach ($methods as $method) {
            $this->routes[] = new LaravelRouteDefinition(
                method: $method,
                uri: $this->joinUri($context->uriPrefix, $uri),
                name: $name,
                controller: $action['controller'],
                controllerMethod: $action['method'],
                middleware: $middleware,
                startLine: $registration['node']->getStartLine(),
                endLine: $endNode->getEndLine(),
            );
        }
    }

    /**
     * @param  array{name: string, args: array<Node\Arg>, node: Node}  $call
     * @return list<string>|null
     */
    private function httpMethods(array $call): ?array
    {
        $method = $call['name'];

        if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
            return [strtoupper($method)];
        }

        if ($method !== 'match') {
            return null;
        }

        $methods = $this->stringList($call['args'][0]->value ?? null);

        if ($methods === null || $methods === []) {
            $this->issues[] = $this->issue(
                'laravel_route.dynamic_methods',
                'Route HTTP methods could not be resolved statically',
                $call['node'],
            );

            return null;
        }

        return array_map(strtoupper(...), $methods);
    }

    /**
     * @return array{controller: string|null, method: string|null}|null
     */
    private function action(Node\Expr $action, ?string $groupController): ?array
    {
        if ($action instanceof Node\Expr\Closure || $action instanceof Node\Expr\ArrowFunction) {
            return ['controller' => null, 'method' => null];
        }

        if ($action instanceof Node\Expr\Array_ && count($action->items) === 2) {
            $controller = $this->className($action->items[0]->value);
            $method = $this->stringValue($action->items[1]->value);

            return $controller !== null && $method !== null
                ? ['controller' => $controller, 'method' => $method]
                : null;
        }

        $controller = $this->className($action);

        if ($controller !== null) {
            return ['controller' => $controller, 'method' => '__invoke'];
        }

        $value = $this->stringValue($action);

        if ($value === null) {
            return null;
        }

        if ($groupController !== null && ! str_contains($value, '@')) {
            return ['controller' => $groupController, 'method' => $value];
        }

        if (str_contains($value, '@')) {
            [$stringController, $method] = explode('@', $value, 2);

            return $stringController !== '' && $method !== ''
                ? ['controller' => ltrim($stringController, '\\'), 'method' => $method]
                : null;
        }

        return null;
    }

    /**
     * @param  array{name: string, args: array<Node\Arg>, node: Node}  $modifier
     */
    private function applyGroupModifier(LaravelRouteGroupContext $context, array $modifier): LaravelRouteGroupContext
    {
        if (! $context->resolvable) {
            return $context;
        }

        $value = $modifier['args'][0]->value ?? null;

        if ($modifier['name'] === 'prefix' || $modifier['name'] === 'name' || $modifier['name'] === 'as') {
            $resolved = $this->stringValue($value);

            if ($resolved === null) {
                $this->issues[] = $this->issue(
                    'laravel_route.dynamic_group_attribute',
                    'Route group attribute could not be resolved statically',
                    $modifier['node'],
                );

                return $this->unresolvable($context);
            }

            return $modifier['name'] === 'prefix'
                ? new LaravelRouteGroupContext(
                    $this->joinUri($context->uriPrefix, $resolved),
                    $context->namePrefix,
                    $context->middleware,
                    $context->controller,
                )
                : new LaravelRouteGroupContext(
                    $context->uriPrefix,
                    $context->namePrefix.$resolved,
                    $context->middleware,
                    $context->controller,
                );
        }

        if ($modifier['name'] === 'middleware') {
            $resolved = $this->stringList($value);

            if ($resolved === null) {
                $this->issues[] = $this->issue(
                    'laravel_route.dynamic_group_middleware',
                    'Route group middleware could not be resolved statically',
                    $modifier['node'],
                );

                return $this->unresolvable($context);
            }

            return new LaravelRouteGroupContext(
                $context->uriPrefix,
                $context->namePrefix,
                $this->mergeMiddleware($context->middleware, $resolved),
                $context->controller,
            );
        }

        if ($modifier['name'] === 'controller') {
            $resolved = $this->className($value);

            if ($resolved === null) {
                $this->issues[] = $this->issue(
                    'laravel_route.dynamic_group_controller',
                    'Route group controller could not be resolved statically',
                    $modifier['node'],
                );

                return $this->unresolvable($context);
            }

            return new LaravelRouteGroupContext(
                $context->uriPrefix,
                $context->namePrefix,
                $context->middleware,
                $resolved,
            );
        }

        return $context;
    }

    private function applyGroupOptions(LaravelRouteGroupContext $context, Node\Expr $options, Node $location): LaravelRouteGroupContext
    {
        if (! $options instanceof Node\Expr\Array_) {
            $this->issues[] = $this->issue(
                'laravel_route.dynamic_group_attributes',
                'Route group attributes could not be resolved statically',
                $location,
            );

            return $this->unresolvable($context);
        }

        foreach ($options->items as $item) {
            $key = $this->stringValue($item->key);

            if ($key === null) {
                continue;
            }

            $modifier = [
                'name' => $key,
                'args' => [new Node\Arg($item->value)],
                'node' => $item,
            ];
            $context = $this->applyGroupModifier($context, $modifier);
        }

        return $context;
    }

    private function unresolvable(LaravelRouteGroupContext $context): LaravelRouteGroupContext
    {
        return new LaravelRouteGroupContext(
            $context->uriPrefix,
            $context->namePrefix,
            $context->middleware,
            $context->controller,
            false,
        );
    }

    /**
     * @return list<array{name: string, args: array<Node\Arg>, node: Node}>|null
     */
    private function callChain(Node\Expr $expression): ?array
    {
        if ($expression instanceof Node\Expr\StaticCall) {
            if (
                ! $expression->class instanceof Node\Name
                || ! $expression->name instanceof Node\Identifier
                || ! $this->isRouteFacade($expression->class)
            ) {
                return null;
            }

            return [[
                'name' => strtolower($expression->name->toString()),
                'args' => $expression->args,
                'node' => $expression,
            ]];
        }

        if (
            ! $expression instanceof Node\Expr\MethodCall
            || ! $expression->name instanceof Node\Identifier
        ) {
            return null;
        }

        $chain = $this->callChain($expression->var);

        if ($chain === null) {
            return null;
        }

        $chain[] = [
            'name' => strtolower($expression->name->toString()),
            'args' => $expression->args,
            'node' => $expression,
        ];

        return $chain;
    }

    private function isRouteFacade(Node\Name $name): bool
    {
        $resolved = ltrim($name->toString(), '\\');

        return $resolved === 'Route' || $resolved === 'Illuminate\\Support\\Facades\\Route';
    }

    private function containsRouteCall(Node $node): bool
    {
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $this->isRouteFacade($node->class)) {
            return true;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node && $this->containsRouteCall($value)) {
                return true;
            }

            if (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node && $this->containsRouteCall($child)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function stringValue(?Node\Expr $expression): ?string
    {
        return $expression instanceof Node\Scalar\String_ ? $expression->value : null;
    }

    /** @return list<string>|null */
    private function stringList(?Node\Expr $expression): ?array
    {
        $single = $this->stringValue($expression);

        if ($single !== null) {
            return [$single];
        }

        if (! $expression instanceof Node\Expr\Array_) {
            return null;
        }

        $values = [];

        foreach ($expression->items as $item) {
            $value = $this->stringValue($item->value);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        return $values;
    }

    private function className(?Node\Expr $expression): ?string
    {
        if (
            ! $expression instanceof Node\Expr\ClassConstFetch
            || ! $expression->class instanceof Node\Name
            || ! $expression->name instanceof Node\Identifier
            || strtolower($expression->name->toString()) !== 'class'
        ) {
            return null;
        }

        return ltrim($expression->class->toString(), '\\');
    }

    /**
     * @param  list<string>  $first
     * @param  list<string>  $second
     * @return list<string>
     */
    private function mergeMiddleware(array $first, array $second): array
    {
        return array_values(array_unique([...$first, ...$second]));
    }

    private function joinUri(string $prefix, string $uri): string
    {
        $joined = trim($prefix, '/').'/'.trim($uri, '/');
        $joined = trim($joined, '/');

        return $joined === '' ? '/' : $joined;
    }

    private function issue(string $code, string $title, Node $node): PhpAnalysisIssue
    {
        return new PhpAnalysisIssue(
            code: $code,
            title: $title,
            startLine: $node->getStartLine(),
            endLine: $node->getEndLine(),
            severity: 'info',
        );
    }
}
