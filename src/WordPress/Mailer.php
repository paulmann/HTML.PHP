<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress;

use HtmlPhp\Engine;

/**
 * Templated email helper: render subject + body through the engine and
 * hand them to `wp_mail()`.
 *
 * ```php
 * Mailer::send('user@example.com', 'emails/welcome-subject.html', 'emails/welcome.html', [
 *     'name' => $user->display_name,
 * ]);
 * ```
 *
 * Both subject and body are templates; the body is rendered with SSI
 * enabled but auto-escaping appropriate for the `html` content type that
 * is set automatically (pass `$headers` to override).
 */
final class Mailer
{
    private function __construct() {}

    /**
     * @param string|list<string> $to
     * @param array<string, mixed> $vars
     * @param string|list<string> $headers extra headers (Content-Type is added unless present)
     * @param string|list<string> $attachments
     */
    public static function send(
        Engine $engine,
        string|array $to,
        string $subjectTemplate,
        string $bodyTemplate,
        array $vars = [],
        string|array $headers = [],
        string|array $attachments = [],
    ): bool {
        if (!\function_exists('wp_mail')) {
            return false;
        }

        // Subjects are plain text: render without HTML auto-escaping.
        $subject = trim(self::render($engine, $subjectTemplate, $vars, false));
        $body = self::render($engine, $bodyTemplate, $vars, true);

        // wp_mail accepts "string | string[]" for both; normalise once, here.
        $headerList = \is_string($headers) ? ($headers !== '' ? [$headers] : []) : array_values($headers);
        $attachmentList = \is_string($attachments)
            ? ($attachments !== '' ? [$attachments] : [])
            : array_values($attachments);

        $hasContentType = false;

        foreach ($headerList as $header) {
            if (\is_string($header) && stripos($header, 'content-type:') === 0) {
                $hasContentType = true;
                break;
            }
        }

        if (!$hasContentType) {
            $headerList[] = 'Content-Type: text/html; charset=UTF-8';
        }

        return (bool) wp_mail($to, $subject, $body, $headerList, $attachmentList);
    }

    /**
     * Renders a template by locator name, file path or inline string
     * (`@{key}` subjects often need no file at all).
     *
     * @param array<string, mixed> $vars
     */
    public static function render(Engine $engine, string $template, array $vars = [], bool $autoEscape = true): string
    {
        $options = new \HtmlPhp\Template\RenderOptions(autoEscape: $autoEscape);

        if (strpbrk($template, "\n") !== false || str_contains($template, '%{') || str_contains($template, '@{')) {
            return $engine->renderString($template, $vars, $options);
        }

        $path = TemplateLoader::locate($template) ?? $engine->locator()->locate($template);

        if ($path !== null) {
            return $engine->renderFile($path, $vars, $options);
        }

        return $engine->renderString($template, $vars, $options);
    }
}
