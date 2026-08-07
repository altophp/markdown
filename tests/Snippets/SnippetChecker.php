<?php

declare(strict_types=1);

/*
 * This file is part of the ALTO library.
 *
 * © 2026-present Simon André
 *
 * For full copyright and license information, please see
 * the LICENSE file distributed with this source code.
 */

namespace Alto\Markdown\Tests\Snippets;

/**
 * Type-checks fenced PHP examples found in documentation files.
 *
 * Each snippet becomes a standalone PHP file under var/snippets with a unique
 * namespace and generated imports, then the whole directory is analysed with
 * PHPStan at level max. Results are reported per snippet against the source
 * file and line the snippet came from.
 */
final class SnippetChecker
{
    private const string SOURCE_NAMESPACE = 'Alto\Markdown';

    private const string SNIPPET_NAMESPACE = 'Alto\Markdown\Snippets';

    private const array DEFAULT_TARGETS = ['README.md'];

    private readonly string $rootDir;

    private readonly string $srcDir;

    private readonly string $snippetsDir;

    private readonly string $phpstanBin;

    private readonly string $phpstanConfig;

    private readonly string $autoloadFile;

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        string $rootDir,
        private $stdout,
        private $stderr,
    ) {
        $this->rootDir = rtrim($rootDir, '/');
        $this->srcDir = $this->rootDir.'/src';
        $this->snippetsDir = $this->rootDir.'/var/snippets';
        $this->phpstanBin = $this->rootDir.'/vendor/bin/phpstan';
        $this->phpstanConfig = $this->rootDir.'/phpstan-snippets.neon.dist';
        $this->autoloadFile = $this->rootDir.'/vendor/autoload.php';
    }

    /**
     * @param list<string> $explicitPaths
     *
     * @return int process exit code: 0 green, 1 snippet failure, 2 usage error
     */
    public function run(array $explicitPaths): int
    {
        try {
            [$targets, $usageError] = $this->resolveTargets($explicitPaths);

            if ($usageError) {
                return 2;
            }

            $classMap = PublicApiClassMap::build($this->srcDir, self::SOURCE_NAMESPACE);
            $this->resetSnippetsDir();

            $generated = $this->generateAll($targets, $classMap);

            if ([] === $generated) {
                $this->line('note: no php snippets found');
                $this->line('0 snippets checked, 0 errors');

                return 0;
            }

            return $this->analyse($generated);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return 2;
        }
    }

    /**
     * @param list<string> $explicitPaths
     *
     * @return array{list<Target>, bool}
     */
    private function resolveTargets(array $explicitPaths): array
    {
        if ([] === $explicitPaths) {
            $targets = [];

            foreach ($this->defaultTargets() as $name) {
                $targets[] = new Target($this->rootDir.'/'.$name, $name, true);
            }

            return [$targets, false];
        }

        $targets = [];
        $usageError = false;

        foreach ($explicitPaths as $path) {
            $absolute = $this->absolutePath($path);

            if (!is_file($absolute)) {
                $this->error("file not found: {$path}");
                $usageError = true;

                continue;
            }

            $targets[] = new Target($absolute, $this->displayPath($absolute), false);
        }

        return [$targets, $usageError];
    }

    /**
     * @return list<string>
     */
    private function defaultTargets(): array
    {
        $targets = self::DEFAULT_TARGETS;
        $docs = glob($this->rootDir.'/docs/*.md');

        if (false === $docs) {
            throw new \RuntimeException('Unable to list public documentation files.');
        }

        sort($docs);

        foreach ($docs as $path) {
            $targets[] = $this->displayPath($path);
        }

        return $targets;
    }

    /**
     * @param list<Target>          $targets
     * @param array<string, string> $classMap
     *
     * @return list<GeneratedSnippet>
     */
    private function generateAll(array $targets, array $classMap): array
    {
        $extractor = new SnippetExtractor();
        $useGenerator = new UseStatementGenerator();

        $generated = [];
        $index = 0;

        foreach ($targets as $target) {
            if (!is_file($target->path)) {
                if ($target->isDefault) {
                    $this->line("skip {$target->display}: not found");

                    continue;
                }

                throw new \RuntimeException("file not found: {$target->path}");
            }

            $markdown = $this->read($target->path);

            foreach ($extractor->extract($markdown) as $snippet) {
                ++$index;
                $uses = $useGenerator->generate($snippet->code, $classMap);
                $generated[] = $this->writeSnippet($index, $target, $snippet, $uses);
            }
        }

        return $generated;
    }

    /**
     * @param list<string> $uses
     */
    private function writeSnippet(int $index, Target $target, Snippet $snippet, array $uses): GeneratedSnippet
    {
        [$body, $strippedLines] = $this->normalizeBody($snippet->code);

        $header = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            sprintf('namespace %s\\S%d;', self::SNIPPET_NAMESPACE, $index),
            '',
        ];

        foreach ($uses as $use) {
            $header[] = $use;
        }

        if ([] !== $uses) {
            $header[] = '';
        }

        $bodyStartLine = \count($header) + 1;
        $content = implode("\n", $header)."\n".$body;
        $path = sprintf('%s/S%d.php', $this->snippetsDir, $index);

        $this->write($path, $content);

        return new GeneratedSnippet($path, $target->display, $snippet->line, $bodyStartLine, $strippedLines);
    }

    /**
     * Removes a leading PHP open tag and strict_types declaration so the body
     * can be re-hosted under a generated namespace.
     *
     * @return array{string, int} normalized body and the number of lines removed
     */
    private function normalizeBody(string $code): array
    {
        $stripped = 0;

        if (1 === preg_match('/^\s*<\?php[ \t]*\r?\n/', $code, $matches)) {
            $stripped += substr_count($matches[0], "\n");
            $code = substr($code, \strlen($matches[0]));
        }

        if (1 === preg_match('/^[ \t]*declare\s*\(\s*strict_types\s*=\s*\d+\s*\)\s*;[ \t]*\r?\n/', $code, $matches)) {
            $stripped += substr_count($matches[0], "\n");
            $code = substr($code, \strlen($matches[0]));
        }

        if ('' !== $code && !str_ends_with($code, "\n")) {
            $code .= "\n";
        }

        return [$code, $stripped];
    }

    /**
     * @param list<GeneratedSnippet> $generated
     */
    private function analyse(array $generated): int
    {
        $result = $this->runPhpstan();
        $byPath = $this->parsePhpstanFiles($result['stdout'], $result['stderr']);

        $errorCount = 0;

        foreach ($generated as $snippet) {
            $messages = $byPath[$snippet->path] ?? [];

            if ([] === $messages) {
                $this->line(sprintf('%s:%d OK', $snippet->display, $snippet->fenceLine));

                continue;
            }

            $errorCount += \count($messages);
            $this->line(sprintf('%s:%d FAIL', $snippet->display, $snippet->fenceLine));

            foreach ($messages as $message) {
                $this->line(sprintf(
                    '  %s:%d %s',
                    $snippet->display,
                    $this->sourceLine($snippet, $message['line']),
                    $message['message'],
                ));
            }
        }

        $count = \count($generated);
        $this->line(sprintf('%d snippet%s checked, %d error%s', $count, 1 === $count ? '' : 's', $errorCount, 1 === $errorCount ? '' : 's'));

        return $errorCount > 0 ? 1 : 0;
    }

    private function sourceLine(GeneratedSnippet $snippet, int $generatedLine): int
    {
        if ($generatedLine < $snippet->bodyStartLine) {
            return $snippet->fenceLine;
        }

        $offset = $generatedLine - $snippet->bodyStartLine;

        return $snippet->fenceLine + 1 + $snippet->strippedLines + $offset;
    }

    /**
     * @return array{stdout: string, stderr: string}
     */
    private function runPhpstan(): array
    {
        $command = [
            $this->phpstanBin,
            'analyse',
            '-c',
            $this->phpstanConfig,
            '--autoload-file='.$this->autoloadFile,
            '--error-format=json',
            '--no-progress',
            '--no-ansi',
            '--memory-limit=-1',
            '--debug',
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $this->rootDir);

        if (!\is_resource($process)) {
            throw new \RuntimeException('Cannot start phpstan');
        }

        $out = $pipes[1];
        $err = $pipes[2];

        if (!\is_resource($out) || !\is_resource($err)) {
            proc_close($process);

            throw new \RuntimeException('Cannot read phpstan output');
        }

        $stdout = stream_get_contents($out);
        $stderr = stream_get_contents($err);
        fclose($out);
        fclose($err);
        proc_close($process);

        return [
            'stdout' => false === $stdout ? '' : $stdout,
            'stderr' => false === $stderr ? '' : $stderr,
        ];
    }

    /**
     * @return array<string, list<array{line: int, message: string}>>
     */
    private function parsePhpstanFiles(string $stdout, string $stderr): array
    {
        $start = strpos($stdout, '{');

        if (false === $start) {
            throw new \RuntimeException($this->phpstanFailureMessage($stdout, $stderr));
        }

        try {
            $decoded = json_decode(substr($stdout, $start), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Cannot parse phpstan output: '.$exception->getMessage());
        }

        if (!\is_array($decoded)) {
            throw new \RuntimeException('Unexpected phpstan output');
        }

        $topErrors = $decoded['errors'] ?? [];

        if (\is_array($topErrors) && [] !== $topErrors) {
            throw new \RuntimeException('phpstan reported: '.$this->stringifyErrors($topErrors));
        }

        $files = $decoded['files'] ?? [];

        if (!\is_array($files)) {
            return [];
        }

        $result = [];

        foreach ($files as $path => $data) {
            $result[(string) $path] = $this->extractMessages($data);
        }

        return $result;
    }

    /**
     * @return list<array{line: int, message: string}>
     */
    private function extractMessages(mixed $data): array
    {
        $rawMessages = \is_array($data) ? ($data['messages'] ?? null) : null;

        if (!\is_array($rawMessages)) {
            return [];
        }

        $messages = [];

        foreach ($rawMessages as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $line = $entry['line'] ?? 0;
            $message = $entry['message'] ?? '';

            $messages[] = [
                'line' => \is_int($line) ? $line : 0,
                'message' => \is_string($message) ? $message : '',
            ];
        }

        return $messages;
    }

    /**
     * @param array<array-key, mixed> $errors
     */
    private function stringifyErrors(array $errors): string
    {
        $parts = [];

        foreach ($errors as $error) {
            if (\is_string($error)) {
                $parts[] = $error;

                continue;
            }

            $encoded = json_encode($error);

            if (false !== $encoded) {
                $parts[] = $encoded;
            }
        }

        return implode('; ', $parts);
    }

    private function phpstanFailureMessage(string $stdout, string $stderr): string
    {
        $detail = trim('' !== $stderr ? $stderr : $stdout);

        return 'phpstan produced no analysable output'.('' !== $detail ? ":\n".$detail : '');
    }

    private function resetSnippetsDir(): void
    {
        $this->removeTree($this->snippetsDir);

        if (!is_dir($this->snippetsDir) && !mkdir($this->snippetsDir, 0o755, true) && !is_dir($this->snippetsDir)) {
            throw new \RuntimeException("Cannot create {$this->snippetsDir}");
        }
    }

    private function removeTree(string $path): void
    {
        if (is_file($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException("Cannot delete {$path}");
            }

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        foreach (false === $entries ? [] : $entries as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $this->removeTree($path.'/'.$entry);
            }
        }

        if (!rmdir($path)) {
            throw new \RuntimeException("Cannot delete {$path}");
        }
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        if (false === $contents) {
            throw new \RuntimeException("Cannot read {$path}");
        }

        return $contents;
    }

    private function write(string $path, string $content): void
    {
        if (false === file_put_contents($path, $content)) {
            throw new \RuntimeException("Cannot write {$path}");
        }
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $cwd = getcwd();

        return (false === $cwd ? $this->rootDir : $cwd).'/'.$path;
    }

    private function displayPath(string $absolute): string
    {
        $prefix = $this->rootDir.'/';

        if (str_starts_with($absolute, $prefix)) {
            return substr($absolute, \strlen($prefix));
        }

        return basename($absolute);
    }

    private function line(string $text): void
    {
        fwrite($this->stdout, $text."\n");
    }

    private function error(string $text): void
    {
        fwrite($this->stderr, 'error: '.$text."\n");
    }
}
