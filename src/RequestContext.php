<?php

declare(strict_types=1);

namespace HtmlPhp;

/**
 * Immutable snapshot of the host request environment.
 *
 * This is the **only** sanctioned way request data reaches the engine: the
 * host builds it once at the edge (`RequestContext::fromServer($_SERVER)`)
 * and passes it explicitly. Nothing inside the library reads `$_SERVER`.
 */
final class RequestContext
{
    /**
     * @param array<string, string> $server sanitised string copy of the
     *        server variables that back `HTTP_*` SSI echo lookups
     */
    public function __construct(
        public readonly string $documentRoot = '',
        public readonly string $scriptName = '',
        public readonly string $requestUri = '',
        public readonly string $documentName = '',
        public readonly string $documentUri = '',
        public readonly string $queryString = '',
        public readonly string $method = 'GET',
        public readonly string $remoteAddr = '',
        public readonly string $userAgent = '',
        public readonly string $acceptLanguage = '',
        public readonly string $referer = '',
        public readonly array $server = [],
    ) {}

    /**
     * Builds a context from a `$_SERVER`-shaped array.
     *
     * @param array<string, mixed> $server
     */
    public static function fromServer(array $server): self
    {
        $string = static fn(string $key): string => isset($server[$key]) && \is_scalar($server[$key])
            ? (string) $server[$key]
            : '';

        $requestUri = $string('REQUEST_URI');
        $scriptName = $string('SCRIPT_NAME');
        $path = parse_url($requestUri, PHP_URL_PATH);

        $clean = [];
        foreach ($server as $key => $value) {
            if (\is_string($key) && \is_scalar($value)) {
                $clean[$key] = (string) $value;
            }
        }

        return new self(
            documentRoot: $string('DOCUMENT_ROOT'),
            scriptName: $scriptName,
            requestUri: $requestUri,
            documentName: \is_string($path) && $path !== ''
                ? basename($path)
                : ($scriptName !== '' ? basename($scriptName) : ''),
            documentUri: \is_string($path) && $path !== '' ? $path : ($scriptName !== '' ? $scriptName : '/'),
            queryString: $string('QUERY_STRING'),
            method: strtoupper($string('REQUEST_METHOD') ?: 'GET'),
            remoteAddr: $string('REMOTE_ADDR'),
            userAgent: $string('HTTP_USER_AGENT'),
            acceptLanguage: $string('HTTP_ACCEPT_LANGUAGE'),
            referer: $string('HTTP_REFERER'),
            server: $clean,
        );
    }

    /** A neutral context for CLI runs, queue workers and tests. */
    public static function cli(string $cwd = ''): self
    {
        $cwd = $cwd !== '' ? $cwd : (getcwd() ?: '');

        return new self(documentRoot: $cwd, scriptName: '', requestUri: '/', documentUri: '/', method: 'CLI');
    }

    /** HTTP_* lookup used by SSI `#echo`/`#printenv`. */
    public function http(string $name): ?string
    {
        $key = strtoupper($name);

        if (isset($this->server[$key])) {
            return $this->server[$key];
        }

        $key = str_starts_with($key, 'HTTP_') ? $key : 'HTTP_' . $key;

        return $this->server[$key] ?? null;
    }
}
