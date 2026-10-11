<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress;

use HtmlPhp\Diagnostics;
use HtmlPhp\Engine;
use HtmlPhp\Support\Str;
use HtmlPhp\Template\RenderOptions;

/**
 * `[tag attr="value"]` shortcodes backed by engine templates.
 *
 * Attributes become scoped variables (invalid names are dropped), the
 * enclosed content is available as `%{content}`. Render failures never
 * take the page down: they return `''` and record a diagnostic.
 */
final class Shortcodes
{
    public function __construct(private readonly Engine $engine) {}

    /**
     * @param array<string, mixed> $defaults merged under the shortcode attributes
     */
    public function register(string $tag, string $template, array $defaults = []): void
    {
        $tag = preg_replace('~[^a-z0-9_-]~i', '', $tag) ?? '';

        if ($tag === '' || !\function_exists('add_shortcode')) {
            return;
        }

        $engine = $this->engine;

        $handler = static function (mixed $atts, mixed $content = null) use ($engine, $template, $defaults): string {
            $vars = $defaults;

            if (\is_array($atts)) {
                foreach ($atts as $key => $value) {
                    if (\is_string($key) && Str::isValidVariableName($key)) {
                        $vars[$key] = \is_scalar($value) || $value === null ? $value : '';
                    }
                }
            }

            $vars['content'] = \is_string($content) ? $content : '';

            try {
                $path = TemplateLoader::locate($template) ?? $template;

                return $engine->renderFile($path, $vars, new RenderOptions(file: $path));
            } catch (\Throwable $error) {
                $engine->diagnostics()->record(
                    Diagnostics::WP_BRIDGE,
                    'Shortcode render failed: ' . $error->getMessage(),
                    ['template' => $template],
                );

                return '';
            }
        };

        add_shortcode($tag, $handler);
    }
}
