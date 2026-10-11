<?php

declare(strict_types=1);

namespace HtmlPhp\Ssi;

use HtmlPhp\RequestContext;
use HtmlPhp\Support\Str;
use HtmlPhp\Version;

/**
 * Computes the mod_include built-in variables from the injected request
 * context and clock — never from `$_SERVER`.
 */
final class Builtins
{
    private function __construct() {}

    public static function isBuiltin(string $name): bool
    {
        return isset(SsiContext::BUILTIN_NAMES[$name]);
    }

    /**
     * The whole built-in map for one render context.
     *
     * @return array<string, string>
     */
    public static function map(SsiContext $context): array
    {
        $timefmt = $context->timefmt;
        $now = $context->timestamp();
        $request = $context->request();
        $documentUri = self::documentUri($request, $context->document->file, $context->documentRoot());
        $documentRoot = $context->documentRoot();

        $lastModified = '';
        $currentFile = $context->document->file;

        if (\is_string($currentFile) && $currentFile !== '' && is_file($currentFile)) {
            $modified = @filemtime($currentFile);

            if ($modified !== false) {
                $lastModified = TimeFormat::format($modified, $timefmt);
            }
        }

        return [
            'DATE_LOCAL' => TimeFormat::format($now, $timefmt),
            'DATE_GMT' => TimeFormat::format($now, $timefmt, true),
            'DOCUMENT_URI' => $documentUri,
            'DOCUMENT_NAME' => $documentUri === '' ? '' : basename($documentUri),
            'DOCUMENT_ROOT' => $documentRoot,
            'QUERY_STRING' => $request->queryString,
            'SERVER_NAME' => self::server($request, 'SERVER_NAME'),
            'SERVER_SOFTWARE' => self::server($request, 'SERVER_SOFTWARE', 'HTML.PHP/' . Version::VERSION),
            'SERVER_PROTOCOL' => self::server($request, 'SERVER_PROTOCOL', 'HTTP/1.1'),
            'REQUEST_METHOD' => $request->method !== '' ? $request->method : 'GET',
            'REQUEST_URI' => self::server($request, 'REQUEST_URI', $documentUri),
            'SCRIPT_NAME' => $request->scriptName,
            'CONTENT_TYPE' => self::server($request, 'CONTENT_TYPE', self::server($request, 'HTTP_CONTENT_TYPE')),
            'REMOTE_ADDR' => $request->remoteAddr,
            'HTTP_USER_AGENT' => $request->userAgent,
            'LAST_MODIFIED' => $lastModified,
        ];
    }

    /**
     * DOCUMENT_URI: the request path when known, otherwise the current file
     * expressed relative to the document root (CLI renders, string renders).
     */
    private static function documentUri(RequestContext $request, ?string $file, string $root): string
    {
        $uri = $request->documentUri;

        if ($uri === '' || $uri === '/') {
            $uri = self::fromFile($request->requestUri !== '' ? $request->requestUri : $request->scriptName);
        }

        if ($uri === '' && \is_string($file) && $file !== '') {
            $real = realpath($file);

            if ($real !== false && $root !== '') {
                $normalized = str_replace('\\', '/', $real);
                $rootNormalized = rtrim(str_replace('\\', '/', $root), '/');

                if (str_starts_with($normalized, $rootNormalized . '/')) {
                    return '/' . substr($normalized, \strlen($rootNormalized) + 1);
                }
            }
        }

        return $uri;
    }

    private static function fromFile(string $uri): string
    {
        if ($uri === '') {
            return '';
        }

        $uri = (string) preg_replace('~\?.*$~s', '', $uri);
        $uri = str_replace('\\', '/', $uri);

        return str_starts_with($uri, '/') ? $uri : '/' . $uri;
    }

    private static function server(RequestContext $request, string $key, string $default = ''): string
    {
        return Str::stringify($request->server[$key] ?? $default);
    }
}
