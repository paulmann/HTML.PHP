<?php

declare(strict_types=1);

namespace HtmlPhp\Ssi;

use HtmlPhp\Config\Limits;
use HtmlPhp\Config\Security as SecurityConfig;
use HtmlPhp\Config\Ssi as SsiConfig;
use HtmlPhp\Diagnostics;
use HtmlPhp\Event\Dispatcher;
use HtmlPhp\Io\Files;
use HtmlPhp\RequestContext;
use HtmlPhp\Support\ClockInterface;
use HtmlPhp\Support\Str;

/**
 * Per-render SSI state: budget counters, `#config`/`#set` variables, the
 * include stack (cycle guard) and path resolution.
 *
 * Every value comes from the injected configuration, document info and
 * request context — the class reads no superglobals and no globals.
 */
final class SsiContext
{
    /** Reserved names: never writable via `#set`, never echo-able. */
    public const array RESERVED = [
        'GLOBALS' => true, '_GET' => true, '_POST' => true, '_COOKIE' => true,
        '_SESSION' => true, '_FILES' => true, '_SERVER' => true, '_ENV' => true,
        '_REQUEST' => true, 'HTTP_SERVER_VARS' => true, 'this' => true,
        'HTML_Prepare_Count' => true, 'TAGS' => true, 'html' => true,
        'parity' => true, 'entities' => true,
    ];

    /** Names computed on demand from the request + clock. */
    public const array BUILTIN_NAMES = [
        'DATE_LOCAL' => true, 'DATE_GMT' => true, 'DOCUMENT_URI' => true,
        'DOCUMENT_NAME' => true, 'DOCUMENT_ROOT' => true, 'QUERY_STRING' => true,
        'SERVER_NAME' => true, 'SERVER_SOFTWARE' => true, 'SERVER_PROTOCOL' => true,
        'REQUEST_METHOD' => true, 'REQUEST_URI' => true, 'SCRIPT_NAME' => true,
        'CONTENT_TYPE' => true, 'REMOTE_ADDR' => true, 'HTTP_USER_AGENT' => true,
        'LAST_MODIFIED' => true,
    ];

    public int $depth = 0;
    public int $processed = 0;

    /** @var array<string, true> realpath stack guarding include cycles */
    public array $stack = [];

    public string $timefmt;
    public string $sizefmt;
    public ?string $errmsg;

    /** @var array<string, string> variables defined through `#set` */
    public array $vars = [];

    /** @var array<string, string>|null computed on first use, reset by `#config timefmt` */
    private ?array $builtins = null;

    private readonly RequestContext $requestContext;

    public function __construct(
        public readonly DocumentInfo $document,
        private readonly SsiConfig $config,
        private readonly SecurityConfig $security,
        private readonly Limits $limits,
        private readonly Diagnostics $diagnostics,
        private readonly Dispatcher $events,
        private readonly ClockInterface $clock,
        private readonly int $now = 0,
    ) {
        $this->timefmt = $config->timeFormat !== '' ? $config->timeFormat : TimeFormat::DEFAULT_FORMAT;
        $this->sizefmt = $config->sizeFormat->value;
        $this->errmsg = $config->errorMessage;
        $this->requestContext = $document->request ?? RequestContext::cli();
    }

    public function config(): SsiConfig
    {
        return $this->config;
    }

    public function security(): SecurityConfig
    {
        return $this->security;
    }

    public function limits(): Limits
    {
        return $this->limits;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }

    public function events(): Dispatcher
    {
        return $this->events;
    }

    /** Current timestamp, stable for the whole render. */
    public function timestamp(): int
    {
        return $this->now !== 0 ? $this->now : $this->clock->now();
    }

    public function request(): RequestContext
    {
        return $this->requestContext;
    }

    /**
     * Child context for an included file: shares budgets and `#config` state
     * (by reference, exactly like mod_include where directives affect the
     * whole request), but has its own current-file anchor.
     */
    public function child(string $file): self
    {
        $child = new self(
            new DocumentInfo($file, $this->document->root, $this->requestContext, $this->document->vars),
            $this->config,
            $this->security,
            $this->limits,
            $this->diagnostics,
            $this->events,
            $this->clock,
            $this->timestamp(),
        );

        $child->depth = $this->depth + 1;
        $child->processed = &$this->processed;
        $child->stack = &$this->stack;
        $child->timefmt = &$this->timefmt;
        $child->sizefmt = &$this->sizefmt;
        $child->errmsg = &$this->errmsg;
        $child->vars = &$this->vars;
        $child->builtins = &$this->builtins;

        return $child;
    }

    /**
     * Built-in SSI variables, computed once per render.
     *
     * @return array<string, string>
     */
    public function builtins(): array
    {
        if ($this->builtins !== null) {
            return $this->builtins;
        }

        $map = Builtins::map($this);
        $filtered = $this->events->filter('htmlphp.ssi.builtins', $map);

        return $this->builtins = \is_array($filtered) ? $filtered : $map;
    }

    public function resetBuiltins(): void
    {
        $this->builtins = null;
    }

    /**
     * Value of a variable name for `#echo`, `#if` and `$VAR` expansion.
     *
     * Order (mod_include-like): built-ins first, then `#set` variables, then
     * the injected document/scope variables. Reserved names never resolve.
     */
    public function variable(string $name): string
    {
        return $this->resolve($name) ?? '';
    }

    /**
     * Same chain as {@see self::variable()} but distinguishes "unknown"
     * (`null`) from "known and empty" — `#set` expansion keeps unknown
     * `$NAME` references verbatim, exactly like mod_include.
     */
    public function resolve(string $name): ?string
    {
        $name = trim($name);

        if ($name === '' || str_contains($name, "\0") || isset(self::RESERVED[$name])) {
            return null;
        }

        if (isset(self::BUILTIN_NAMES[$name])) {
            if (!$this->echoAllowed($name)) {
                return '';
            }

            return $this->builtins()[$name] ?? '';
        }

        if (\array_key_exists($name, $this->vars)) {
            return $this->vars[$name];
        }

        if (str_starts_with($name, 'HTTP_')) {
            $value = $this->request()->http($name);

            return $value === null ? null : $value;
        }

        if (\array_key_exists($name, $this->document->vars)) {
            return Str::stringify($this->document->vars[$name]);
        }

        return null;
    }

    /** True when the name passes the `ssi.echo_allow` gate (empty = allow all). */
    public function echoAllowed(string $name): bool
    {
        $allow = $this->config->echoAllow;

        return $allow === [] || isset($allow[$name]) || \in_array($name, $allow, true);
    }

    /** True when `#set` may define this name. */
    public function isWritableName(string $name): bool
    {
        return Str::isValidVariableName($name)
            && !isset(self::RESERVED[$name])
            && !isset(self::BUILTIN_NAMES[$name]);
    }

    /** The document root includes resolve against. */
    public function documentRoot(): string
    {
        $root = $this->document->root;

        if ($root === null || $root === '') {
            $root = $this->config->documentRoot ?? '';
        }

        if ($root === '') {
            $root = $this->request()->documentRoot;
        }

        if ($root === '') {
            $root = getcwd() ?: '.';
        }

        $resolved = realpath($root);

        if ($resolved !== false && is_dir($resolved)) {
            return $resolved;
        }

        $trimmed = rtrim($root, "/\\");

        return $trimmed !== '' ? $trimmed : DIRECTORY_SEPARATOR;
    }

    /** Directory that relative (`file=`) includes resolve against. */
    public function relativeDir(): ?string
    {
        $current = $this->document->file;

        if ($current === null || $current === '') {
            return null;
        }

        if (is_dir($current)) {
            return $current;
        }

        $directory = \dirname($current);

        return $directory === '' || $directory === '.' ? null : $directory;
    }

    /** Error replacement text for failed directives (`#config errmsg`). */
    public function errorText(): string
    {
        return $this->errmsg ?? '';
    }

    /** True while another include/directive budget remains. */
    public function budgetAllows(): bool
    {
        if ($this->depth >= $this->limits->maxSsiDepth) {
            $this->diagnostics->record(
                Diagnostics::SSI_LIMIT_REACHED,
                'SSI include depth limit reached.',
                ['depth' => $this->depth],
            );

            return false;
        }

        if ($this->processed >= $this->limits->maxSsiTags) {
            $this->diagnostics->record(
                Diagnostics::SSI_LIMIT_REACHED,
                'SSI directive budget exhausted.',
                ['processed' => $this->processed],
            );

            return false;
        }

        return true;
    }

    /** One candidate include path: containment-, extension- and size-checked. */
    public function resolveCandidate(string $candidate): ?string
    {
        $resolved = realpath($candidate);

        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            return null;
        }

        if (!$this->config->allowParentPaths) {
            $root = realpath($this->documentRoot());

            if ($root === false || !is_dir($root) || !Files::isInside($resolved, $root)) {
                return null;
            }
        }

        if (!$this->extensionAllowed($resolved)) {
            return null;
        }

        $size = @filesize($resolved);

        if ($size === false || $size > $this->limits->maxIncludeBytes) {
            return null;
        }

        return $resolved;
    }

    /**
     * Resolves an SSI path (from `#include`/`#fsize`/`#flastmod`/file tests).
     *
     * Absolute paths (`/ssi/x.htm`) resolve from the document root, relative
     * ones (`ssi/x.htm`, `./ssi/x.htm`) from the directory of the file
     * containing the directive first, then the document root.
     */
    public function resolvePath(string $virtual): ?string
    {
        if ($virtual === '' || str_contains($virtual, "\0")) {
            return null;
        }

        $absolute = str_starts_with($virtual, '/') || str_starts_with($virtual, '\\');

        $virtual = str_replace('\\', '/', $virtual);
        $virtual = ltrim($virtual, '/');
        $virtual = (string) preg_replace('~/+~', '/', $virtual);

        // Reject URLs and (unless explicitly allowed) parent traversal.
        if ($virtual === '' || preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*://~', $virtual) === 1) {
            return null;
        }

        if (!$this->config->allowParentPaths && preg_match('~(?:^|/)\.\.(?:/|$)~', $virtual) === 1) {
            return null;
        }

        $relative = str_replace('/', DIRECTORY_SEPARATOR, $virtual);
        $candidates = [];

        if (!$absolute) {
            $relativeDir = $this->relativeDir();

            if ($relativeDir !== null && $relativeDir !== '') {
                $candidates[] = rtrim($relativeDir, "/\\") . DIRECTORY_SEPARATOR . $relative;
            }
        }

        $candidates[] = $this->documentRoot() . DIRECTORY_SEPARATOR . $relative;

        foreach ($candidates as $candidate) {
            $resolved = $this->resolveCandidate($candidate);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Resolves a `#exec cgi=` target: document-root containment like
     * {@see self::resolvePath()}, but the extension gate is *inverted* —
     * only the configured `exec_cgi_extensions` may execute. This keeps
     * `#include` from ever serving script sources while `#exec cgi` can
     * still run them under an explicit allow-list policy.
     */
    public function resolveExecutable(string $virtual): ?string
    {
        if ($virtual === '' || str_contains($virtual, "\0")) {
            return null;
        }

        $normalized = str_replace('\\', '/', $virtual);
        $normalized = ltrim($normalized, '/');
        $normalized = (string) preg_replace('~/+~', '/', $normalized);

        if ($normalized === ''
            || preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*://~', $normalized) === 1
            || (!$this->config->allowParentPaths && preg_match('~(?:^|/)\.\.(?:/|$)~', $normalized) === 1)
        ) {
            return null;
        }

        $candidate = $this->documentRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        $resolved = realpath($candidate);

        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            return null;
        }

        if (!$this->config->allowParentPaths && !Files::isInside($resolved, $this->documentRoot())) {
            return null;
        }

        $size = @filesize($resolved);

        if ($size === false || $size > $this->limits->maxIncludeBytes) {
            return null;
        }

        return $resolved;
    }

    /** Deny-list check shared with {@see \HtmlPhp\Io\PathGuard}. */
    private function extensionAllowed(string $path): bool
    {
        $base = basename(str_replace('\\', '/', $path));

        if (str_starts_with($base, '.') && $base !== '.' && $base !== '..') {
            return false;
        }

        $extension = Files::extension($base);

        if ($extension === '') {
            return true;
        }

        return !\in_array($extension, $this->security->deniedExtensions, true);
    }
}
