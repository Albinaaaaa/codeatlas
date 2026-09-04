<?php

namespace App\PhpAnalysis;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class PhpAstVisitor extends NodeVisitorAbstract
{
    /** @var list<PhpSymbol> */
    private array $symbols = [];

    /** @var list<PhpRelation> */
    private array $relations = [];

    /** @var list<PhpAnalysisIssue> */
    private array $issues = [];

    private string $namespace = '';

    private ?string $namespaceKey = null;

    /** @var list<string|null> */
    private array $classKeys = [];

    /** @var array<string, PhpSymbol> */
    private array $symbolsByKey = [];

    private int $nextKey = 1;

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = $node->name?->toString() ?? '';
            $this->namespaceKey = null;

            if ($node->name !== null) {
                $this->namespaceKey = $this->addSymbol(
                    kind: 'namespace',
                    name: $this->namespace,
                    qualifiedName: $this->namespace,
                    parentKey: null,
                    node: $node,
                );
            }

            return null;
        }

        if ($node instanceof Node\Stmt\ClassLike) {
            $this->enterClassLike($node);

            return null;
        }

        $classKey = $this->currentClassKey();

        if ($classKey === null) {
            return null;
        }

        $class = $this->symbolsByKey[$classKey];

        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->addSymbol(
                kind: 'method',
                name: $node->name->toString(),
                qualifiedName: $class->qualifiedName.'::'.$node->name->toString(),
                parentKey: $classKey,
                node: $node,
                visibility: $this->visibility($node->flags),
            );
        } elseif ($node instanceof Node\Stmt\Property) {
            foreach ($node->props as $property) {
                $name = $property->name->toString();
                $this->addSymbol(
                    kind: 'property',
                    name: $name,
                    qualifiedName: $class->qualifiedName.'::$'.$name,
                    parentKey: $classKey,
                    node: $property,
                    visibility: $this->visibility($node->flags),
                );
            }
        } elseif ($node instanceof Node\Stmt\ClassConst) {
            foreach ($node->consts as $constant) {
                $name = $constant->name->toString();
                $this->addSymbol(
                    kind: 'class_constant',
                    name: $name,
                    qualifiedName: $class->qualifiedName.'::'.$name,
                    parentKey: $classKey,
                    node: $constant,
                    visibility: $this->visibility($node->flags),
                );
            }
        } elseif ($node instanceof Node\Param && $node->flags !== 0 && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            $this->addSymbol(
                kind: 'property',
                name: $node->var->name,
                qualifiedName: $class->qualifiedName.'::$'.$node->var->name,
                parentKey: $classKey,
                node: $node,
                visibility: $this->visibility($node->flags),
            );
        } elseif ($node instanceof Node\Stmt\TraitUse) {
            foreach ($node->traits as $trait) {
                $this->addRelation($classKey, 'trait_use', $trait, $node);
            }
        }

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classKeys);
        } elseif ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = '';
            $this->namespaceKey = null;
        }

        return null;
    }

    /** @return list<PhpSymbol> */
    public function symbols(): array
    {
        return $this->symbols;
    }

    /** @return list<PhpRelation> */
    public function relations(): array
    {
        return $this->relations;
    }

    /** @return list<PhpAnalysisIssue> */
    public function issues(): array
    {
        return $this->issues;
    }

    private function enterClassLike(Node\Stmt\ClassLike $node): void
    {
        if ($node->name === null) {
            $this->classKeys[] = null;
            $this->issues[] = new PhpAnalysisIssue(
                code: 'php.unsupported_anonymous_class',
                title: 'Anonymous class was not indexed',
                startLine: $node->getStartLine(),
                endLine: $node->getEndLine(),
                severity: 'info',
            );

            return;
        }

        $name = $node->name->toString();
        $qualifiedName = $this->namespace === '' ? $name : $this->namespace.'\\'.$name;
        $kind = match (true) {
            $node instanceof Node\Stmt\Interface_ => 'interface',
            $node instanceof Node\Stmt\Trait_ => 'trait',
            $node instanceof Node\Stmt\Enum_ => 'enum',
            default => 'class',
        };
        $key = $this->addSymbol(
            kind: $kind,
            name: $name,
            qualifiedName: $qualifiedName,
            parentKey: $this->namespaceKey,
            node: $node,
        );
        $this->classKeys[] = $key;

        if ($node instanceof Node\Stmt\Class_ && $node->extends !== null) {
            $this->addRelation($key, 'extends', $node->extends, $node->extends);
        }

        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Enum_) {
            foreach ($node->implements as $interface) {
                $this->addRelation($key, 'implements', $interface, $interface);
            }
        }

        if ($node instanceof Node\Stmt\Interface_) {
            foreach ($node->extends as $interface) {
                $this->addRelation($key, 'extends', $interface, $interface);
            }
        }
    }

    private function addSymbol(
        string $kind,
        string $name,
        string $qualifiedName,
        ?string $parentKey,
        Node $node,
        ?string $visibility = null,
    ): string {
        $key = (string) $this->nextKey++;
        $symbol = new PhpSymbol(
            key: $key,
            kind: $kind,
            name: $name,
            qualifiedName: $qualifiedName,
            parentKey: $parentKey,
            startLine: $node->getStartLine(),
            endLine: $node->getEndLine(),
            visibility: $visibility,
        );
        $this->symbols[] = $symbol;
        $this->symbolsByKey[$key] = $symbol;

        return $key;
    }

    private function addRelation(string $fromKey, string $type, Node\Name $target, Node $location): void
    {
        $this->relations[] = new PhpRelation(
            fromSymbolKey: $fromKey,
            type: $type,
            targetName: $target->toString(),
            startLine: $location->getStartLine(),
            endLine: $location->getEndLine(),
        );
    }

    private function currentClassKey(): ?string
    {
        if ($this->classKeys === []) {
            return null;
        }

        return $this->classKeys[array_key_last($this->classKeys)];
    }

    private function visibility(int $flags): string
    {
        return match (true) {
            ($flags & Modifiers::PRIVATE) !== 0 => 'private',
            ($flags & Modifiers::PROTECTED) !== 0 => 'protected',
            default => 'public',
        };
    }
}
