<?php

namespace App\CodeChunking;

use App\Models\CodeSymbol;
use App\PhpAnalysis\PhpFileAnalyzer;
use App\PhpAnalysis\PhpFileInput;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;
use RuntimeException;

final class PhpCodeChunker
{
    public const VERSION = 'php-lines-v1';

    public const MAX_LINES = 120;

    public function __construct(private readonly PhpFileAnalyzer $fileAnalyzer) {}

    /**
     * @param  iterable<CodeSymbol>  $symbols
     * @return list<array{content: string, start_line: int, end_line: int, code_symbol_id: ?int, metadata: array<string, mixed>}>
     */
    public function chunks(PhpFileInput $file, iterable $symbols): array
    {
        if ($file->contents === '') {
            return [];
        }
        $lines = preg_split('/(?<=\n)|(?<=\r)(?!\n)/', $file->contents);
        if ($lines === false) {
            throw new RuntimeException('Cannot split PHP source lines.');
        }
        if (end($lines) === '') {
            array_pop($lines);
        }
        $ranges = [];
        foreach ($symbols as $symbol) {
            if (in_array($symbol->kind, ['class', 'interface', 'trait', 'enum', 'method', 'function'], true)) {
                $ranges[] = ['start' => (int) $symbol->start_line, 'end' => (int) $symbol->end_line,
                    'kind' => $symbol->kind, 'name' => $symbol->qualified_name, 'id' => (int) $symbol->id];
            }
        }
        // Older indexes do not contain standalone functions. Reuse the parser, never execute source.
        if (! in_array('function', array_column($ranges, 'kind'), true)) {
            $analysis = $this->fileAnalyzer->analyze($file);
            foreach ((new NodeFinder)->findInstanceOf($analysis->statements, Function_::class) as $function) {
                $ranges[] = ['start' => $function->getStartLine(), 'end' => $function->getEndLine(),
                    'kind' => 'function', 'name' => ($function->namespacedName ?? $function->name)->toString(), 'id' => null];
            }
        }
        // Narrower symbols own shared lines; ties use source attributes, never database IDs.
        usort($ranges, fn (array $a, array $b): int => [$a['end'] - $a['start'], $a['start'], in_array($a['kind'], ['method', 'function'], true) ? 0 : 1, $a['kind'], $a['name']]
            <=> [$b['end'] - $b['start'], $b['start'], in_array($b['kind'], ['method', 'function'], true) ? 0 : 1, $b['kind'], $b['name']]);
        $owners = array_fill(0, count($lines), null);
        foreach ($ranges as $key => $range) {
            if ($range['start'] < 1 || $range['end'] < $range['start'] || $range['end'] > count($lines)) {
                throw new RuntimeException('Indexed symbol has invalid source line ranges: '.$file->path);
            }
            for ($line = $range['start'] - 1; $line < $range['end']; $line++) {
                $owners[$line] ??= $key;
            }
        }
        $chunks = [];
        $fileHash = hash('sha256', $file->contents);
        for ($start = 0; $start < count($lines); $start = $end) {
            $owner = $owners[$start];
            $end = $start + 1;
            while ($end < count($lines) && $owners[$end] === $owner && $end - $start < self::MAX_LINES) {
                $end++;
            }
            $range = $owner === null ? null : $ranges[$owner];
            $content = implode('', array_slice($lines, $start, $end - $start));
            $metadata = ['algorithm_version' => self::VERSION, 'path' => $file->path,
                'file_hash' => $fileHash, 'content_hash' => hash('sha256', $content),
                'kind' => $range['kind'] ?? 'file', 'symbol_name' => $range['name'] ?? null];
            $metadata['stable_id'] = hash('sha256', json_encode([
                self::VERSION, $file->path, $fileHash, $start + 1, $end,
                $metadata['content_hash'], $metadata['kind'], $metadata['symbol_name'],
            ], JSON_THROW_ON_ERROR));
            $chunks[] = ['content' => $content, 'start_line' => $start + 1, 'end_line' => $end,
                'code_symbol_id' => $range['id'] ?? null, 'metadata' => $metadata];
        }

        return $chunks;
    }
}
