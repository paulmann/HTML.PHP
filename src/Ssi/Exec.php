<?php

declare(strict_types=1);

namespace HtmlPhp\Ssi;

use HtmlPhp\Config\ExecMode;
use HtmlPhp\Config\Limits;
use HtmlPhp\Config\Security as SecurityConfig;
use HtmlPhp\Config\Ssi as SsiConfig;
use HtmlPhp\Diagnostics;
use HtmlPhp\Io\Files;
use HtmlPhp\Support\Str;

/**
 * `<!--#exec cmd="…" -->` / `<!--#exec cgi="…" -->` execution.
 *
 * Deny-by-default. Three policies ({@see ExecMode}):
 *  - `Deny`      — refuse everything (default; a template is data, not a shell);
 *  - `AllowList` — `cmd=` binaries must resolve against `ssi.exec_cmd_allow`
 *                  entries (realpath-compared or basename-matched); `cgi=`
 *                  scripts must live under the document root and carry an
 *                  allowed extension;
 *  - `Delegate`  — a host callable decides per directive (`true`, `false` or
 *                  a string used as the output).
 *
 * Spawned processes get a minimal CGI environment, a hard timeout and an
 * output byte cap; both are enforced through `proc_open()` + `stream_select()`.
 */
final class Exec
{
    public function __construct(
        private readonly SsiConfig $config,
        private readonly Limits $limits,
        private readonly SecurityConfig $security,
        private readonly Diagnostics $diagnostics,
    ) {}

    /** Runs `#exec cmd="…"`. */
    public function runCmd(string $command, SsiContext $context): string
    {
        $decision = $this->decide('cmd', $command, $context);

        if ($decision['output'] !== null) {
            return $decision['output'];
        }

        if ($decision['mode'] === 'deny') {
            return $context->errorText();
        }

        $argv = self::tokenizeCommand($command);

        if ($argv === []) {
            $this->diagnostics->record(Diagnostics::SSI_EXEC_FAILED, 'SSI #exec cmd is empty.');

            return $context->errorText();
        }

        $skipAllowList = $decision['mode'] === 'delegate';

        if (!$skipAllowList) {
            $binary = $this->resolveAllowedBinary($argv[0]);

            if ($binary === null) {
                $this->diagnostics->record(
                    Diagnostics::SSI_EXEC_DENIED,
                    'SSI #exec cmd binary is not on ssi.exec_cmd_allow.',
                    ['command' => $argv[0]],
                );

                return $context->errorText();
            }

            $argv[0] = $binary;
        }

        $environment = [
            'PATH' => $this->environment($context, 'PATH', getenv('PATH') ?: '/usr/bin:/bin'),
            'DOCUMENT_ROOT' => $context->documentRoot(),
            'SERVER_NAME' => $this->environment($context, 'SERVER_NAME'),
            'GATEWAY_INTERFACE' => 'CGI/1.1',
        ];

        return $this->runProcess($argv, $environment, $context->documentRoot());
    }

    /** Runs `#exec cgi="…"`. */
    public function runCgi(string $virtual, SsiContext $context): string
    {
        $decision = $this->decide('cgi', $virtual, $context);

        if ($decision['output'] !== null) {
            return $decision['output'];
        }

        if ($decision['mode'] === 'deny') {
            return $context->errorText();
        }

        $script = $context->resolveExecutable($virtual);

        if ($script === null) {
            $this->diagnostics->record(
                Diagnostics::SSI_EXEC_DENIED,
                'SSI #exec cgi script did not resolve inside the document root.',
                ['virtual' => $virtual],
            );

            return $context->errorText();
        }

        $extension = strtolower(pathinfo($script, PATHINFO_EXTENSION));
        $allowedExtensions = array_map(
            static fn(string $value): string => strtolower($value),
            $this->config->execCgiExtensions,
        );

        if ($decision['mode'] !== 'delegate' && !\in_array($extension, $allowedExtensions, true)) {
            $this->diagnostics->record(
                Diagnostics::SSI_EXEC_DENIED,
                'SSI #exec cgi extension is not allowed.',
                ['script' => $script, 'extension' => $extension],
            );

            return $context->errorText();
        }

        if ($this->config->execCgiMode === 'include') {
            return $this->runInclude($script, $context);
        }

        $environment = [
            'PATH' => $this->environment($context, 'PATH', getenv('PATH') ?: '/usr/bin:/bin'),
            'DOCUMENT_ROOT' => $context->documentRoot(),
            'SCRIPT_NAME' => '/' . ltrim(str_replace('\\', '/', $virtual), '/'),
            'SCRIPT_FILENAME' => $script,
            'REQUEST_METHOD' => $this->environment($context, 'REQUEST_METHOD', 'GET'),
            'QUERY_STRING' => $this->environment($context, 'QUERY_STRING'),
            'SERVER_NAME' => $this->environment($context, 'SERVER_NAME', 'localhost'),
            'SERVER_PROTOCOL' => $this->environment($context, 'SERVER_PROTOCOL', 'HTTP/1.1'),
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'REDIRECT_STATUS' => '200',
            'HTTP_USER_AGENT' => $this->environment($context, 'HTTP_USER_AGENT'),
        ];

        $argv = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-f', $script];

        return $this->runProcess($argv, $environment, \dirname($script));
    }

    // ---------------------------------------------------------------- policy

    /**
     * Applies the configured policy.
     *
     * @return array{mode: 'allow'|'deny'|'delegate', output: string|null}
     *         `output` non-null means "use this literal output"; mode
     *         `delegate` allows execution outright, `allow` still runs the
     *         allow-list checks.
     */
    private function decide(string $kind, string $target, SsiContext $context): array
    {
        if ($target === '' || str_contains($target, "\0")) {
            return ['mode' => 'deny', 'output' => null];
        }

        return match ($this->config->execMode) {
            ExecMode::Deny => $this->deny($kind, 'SSI #exec is disabled (ssi.exec_mode=deny).'),
            ExecMode::Delegate => $this->delegate($kind, $target, $context),
            ExecMode::AllowList => ['mode' => 'allow', 'output' => null],
        };
    }

    /** @return array{mode: 'allow'|'deny'|'delegate', output: string|null} */
    private function delegate(string $kind, string $target, SsiContext $context): array
    {
        $callback = $this->config->execDelegate;

        if (!\is_callable($callback)) {
            return $this->deny($kind, 'SSI #exec delegate is not callable.');
        }

        $result = $callback($kind, [
            'target' => $target,
            'document' => (string) $context->document->file,
            'root' => $context->documentRoot(),
        ]);

        if (\is_string($result)) {
            return ['mode' => 'deny', 'output' => $this->sanitize($result)];
        }

        if ($result === true) {
            return ['mode' => 'delegate', 'output' => null];
        }

        return $this->deny($kind, 'SSI #exec was refused by the host delegate.');
    }

    /** @return array{mode: 'deny', output: null} */
    private function deny(string $kind, string $reason): array
    {
        $this->diagnostics->record(Diagnostics::SSI_EXEC_DENIED, $reason, ['kind' => $kind]);

        return ['mode' => 'deny', 'output' => null];
    }

    /**
     * Splits a command line into argv tokens honouring single and double
     * quotes. No shell interpretation takes place (`proc_open` array form).
     *
     * @return list<string>
     */
    public static function tokenizeCommand(string $command): array
    {
        $tokens = [];
        $length = \strlen($command);
        $current = '';
        $quoted = false;
        $hasToken = false;

        for ($index = 0; $index < $length; ++$index) {
            $char = $command[$index];

            if ($quoted) {
                if ($char === '\\' && $index + 1 < $length && $command[$index + 1] === '"') {
                    $current .= '"';
                    ++$index;
                    continue;
                }

                if ($char === '"') {
                    $quoted = false;
                    continue;
                }

                $current .= $char;
                continue;
            }

            if ($char === '"') {
                $quoted = true;
                $hasToken = true;
                continue;
            }

            if ($char === "'") {
                $end = strpos($command, "'", $index + 1);
                $end = $end === false ? $length : $end;
                $current .= substr($command, $index + 1, $end - $index - 1);
                $index = $end;
                $hasToken = true;
                continue;
            }

            if ($char === ' ' || $char === "\t") {
                if ($hasToken || $current !== '') {
                    $tokens[] = $current;
                    $current = '';
                    $hasToken = false;
                }

                continue;
            }

            $current .= $char;
            $hasToken = true;
        }

        if ($hasToken || $current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /** Resolves the command's binary against the allow-list. */
    private function resolveAllowedBinary(string $binary): ?string
    {
        $allow = $this->config->execCmdAllow;

        if ($allow === []) {
            return null;
        }

        $allowStrings = array_map(static fn(mixed $value): string => Str::stringify($value), $allow);
        $candidates = [];

        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            $candidates[] = $binary;
        } else {
            foreach (explode(PATH_SEPARATOR, (string) (getenv('PATH') ?: '')) as $directory) {
                if ($directory !== '') {
                    $candidates[] = rtrim($directory, "/\\") . DIRECTORY_SEPARATOR . $binary;
                }
            }
        }

        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);

            if ($resolved === false || !is_file($resolved) || !is_executable($resolved)) {
                continue;
            }

            foreach ($allowStrings as $allowed) {
                if ($allowed === '') {
                    continue;
                }

                $allowedResolved = realpath($allowed);

                if ($allowedResolved !== false) {
                    if (Files::normalizeForCompare($resolved) === Files::normalizeForCompare($allowedResolved)) {
                        return $resolved;
                    }

                    continue;
                }

                if ($allowed === basename($resolved)) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    private function environment(SsiContext $context, string $key, string $default = ''): string
    {
        $value = $context->request()->server[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    // ------------------------------------------------------------- execution

    /**
     * Spawns a process, capturing at most `limits.maxExecOutputBytes` of
     * stdout within `limits.execTimeoutSeconds`.
     *
     * @param list<string> $argv
     * @param array<string, string> $environment
     */
    private function runProcess(array $argv, array $environment, string $cwd): string
    {
        $timeout = $this->limits->execTimeoutSeconds;
        $maxBytes = $this->limits->maxExecOutputBytes;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $filteredEnvironment = array_filter($environment, static fn(string $value): bool => $value !== '');

        $process = @proc_open($argv, $descriptors, $pipes, $cwd, $filteredEnvironment);

        if (!\is_resource($process)) {
            $this->diagnostics->record(
                Diagnostics::SSI_EXEC_FAILED,
                'SSI #exec could not spawn the process.',
                ['argv' => $argv],
            );

            return '';
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);

        $output = '';
        $started = microtime(true);
        $timedOut = false;
        $truncated = false;

        while (true) {
            $read = [$pipes[1]];
            $write = null;
            $except = null;
            $select = @stream_select($read, $write, $except, 0, 200000);

            if ($select === false) {
                break;
            }

            if ($select > 0) {
                $chunk = fread($pipes[1], 8192);

                if ($chunk === false || ($chunk === '' && feof($pipes[1]))) {
                    break;
                }

                if ($chunk !== '') {
                    $remaining = $maxBytes - \strlen($output);

                    if ($remaining <= 0) {
                        $truncated = true;
                        break;
                    }

                    if (\strlen($chunk) > $remaining) {
                        $chunk = substr($chunk, 0, $remaining);
                        $truncated = true;
                    }

                    $output .= $chunk;

                    if ($truncated) {
                        break;
                    }
                }
            } elseif (feof($pipes[1])) {
                break;
            }

            if (microtime(true) - $started > $timeout) {
                $timedOut = true;
                break;
            }
        }

        if ($timedOut || $truncated) {
            @proc_terminate($process, \defined('SIGKILL') ? SIGKILL : 9);
        }

        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        $status = proc_close($process);

        if ($timedOut) {
            $this->diagnostics->record(
                Diagnostics::SSI_EXEC_FAILED,
                \sprintf('SSI #exec timed out after %d second(s).', $timeout),
                ['argv' => $argv],
            );
        } elseif ($truncated) {
            $this->diagnostics->record(
                Diagnostics::SSI_EXEC_FAILED,
                \sprintf('SSI #exec output truncated at %d byte(s).', $maxBytes),
                ['argv' => $argv],
            );
        } elseif ($status !== 0) {
            $this->diagnostics->record(
                Diagnostics::SSI_EXEC_FAILED,
                \sprintf('SSI #exec exited with status %d.', $status),
                ['argv' => $argv],
            );
        }

        return $this->sanitize($output);
    }

    /** In-process CGI mode: include the script inside an output buffer. */
    private function runInclude(string $script, SsiContext $context): string
    {
        $maxBytes = $this->limits->maxExecOutputBytes;

        $runner = static function () use ($script): void {
            include $script;
        };

        ob_start();

        try {
            $runner();
        } catch (\Throwable $error) {
            ob_end_clean();
            $this->diagnostics->record(
                Diagnostics::SSI_EXEC_FAILED,
                'SSI #exec cgi include threw: ' . $error->getMessage(),
                ['script' => $script],
            );

            return $context->errorText();
        }

        $output = (string) ob_get_clean();

        if (\strlen($output) > $maxBytes) {
            $output = substr($output, 0, $maxBytes);
            $this->diagnostics->record(
                Diagnostics::SSI_EXEC_FAILED,
                \sprintf('SSI #exec output truncated at %d byte(s).', $maxBytes),
                ['script' => $script],
            );
        }

        return $this->sanitize($output);
    }

    /** Strips NUL and control bytes from captured output when configured. */
    private function sanitize(string $output): string
    {
        if (!$this->security->sanitizeExecOutput || $output === '') {
            return $output;
        }

        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $output);

        return $cleaned ?? $output;
    }
}
