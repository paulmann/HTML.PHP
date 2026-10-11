<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress;

/**
 * WordPress-aware template discovery + rendering.
 *
 * Search order: child theme → parent theme (through `locate_template()`)
 * → the extra directories you pass. Unlike v3, `.php` files are never
 * treated as templates: a template is data, and rendering PHP source as
 * text would leak it.
 */
final class TemplateLoader
{
    /** Suffixes tried for a bare slug, in order. */
    private const array SUFFIXES = ['.ssi.htm', '.ssi.html', '.ssi', '.htm', '.html', '.tpl'];

    public function __construct(private readonly \HtmlPhp\Engine $engine)
    {
    }

    /**
     * Finds the first existing template among candidate names.
     *
     * Search order: child theme → parent theme (locate_template when
     * available) → each directory in $dirs.
     *
     * @param string|list<string> $names
     * @param list<string> $dirs
     */
    public static function locate(string|array $names, array $dirs = []): ?string
    {
        $names = is_string($names) ? [$names] : $names;
        $candidates = [];

        foreach ($names as $name) {
            if ($name === '' || str_contains($name, "\0")) {
                continue;
            }

            // A template is data: script files are never located, even when
            // asked for by name (source-disclosure guard).
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (in_array($extension, ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps'], true)) {
                continue;
            }

            $candidates[] = $name;

            if (!str_contains($name, '.')) {
                foreach (self::SUFFIXES as $suffix) {
                    $candidates[] = $name . $suffix;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        if (function_exists('locate_template')) {
            $found = (string) locate_template($candidates);

            if ($found !== '' && is_file($found)) {
                return $found;
            }
        }

        foreach ($dirs as $dir) {
            if (!is_string($dir) || !is_dir($dir)) {
                continue;
            }

            foreach ($candidates as $candidate) {
                $path = rtrim($dir, '/\\') . \DIRECTORY_SEPARATOR
                    . str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, ltrim($candidate, '/\\'));

                if (is_file($path)) {
                    return $path;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }


    /**
     * Renders the first located template among $names with WP context vars.
     *
     * @param string|list<string> $names
     * @param array<string, mixed> $vars
     * @param list<string> $dirs
     */
    public function render(string|array $names, array $vars = [], array $dirs = []): string
    {
        $path = self::locate($names, $dirs);

        if ($path === null) {
            throw new \HtmlPhp\Exception\TemplateNotFoundException(
                'No WordPress template could be located.',
                ['names' => (array) $names]
            );
        }

        return $this->engine->renderFile(
            $path,
            $this->withWordPressContext($vars),
            new \HtmlPhp\Template\RenderOptions(file: $path)
        );
    }

    /**
     * get_template_part()-style rendering that returns the markup instead
     * of echoing it (slug + optional name, e.g. `content` + `single`).
     * Missing parts are a diagnostic, not a fatal — theme convention.
     *
     * @param array<string, mixed> $vars
     */
    public function renderPart(string $slug, ?string $name = null, array $vars = []): string
    {
        $names = $name !== null && $name !== '' ? [$slug . '-' . $name, $slug] : [$slug];

        $dirs = [];

        if (function_exists('get_stylesheet_directory')) {
            $dirs[] = (string) get_stylesheet_directory() . '/template-parts';
            $dirs[] = (string) get_stylesheet_directory();
        }

        $path = self::locate($names, $dirs);

        if ($path === null) {
            $this->engine->diagnostics()->record(
                \HtmlPhp\Diagnostics::WP_BRIDGE,
                'Template part not found.',
                ['slug' => $slug, 'name' => $name ?? '']
            );

            return '';
        }

        return $this->engine->renderFile(
            $path,
            $this->withWordPressContext($vars),
            new \HtmlPhp\Template\RenderOptions(file: $path)
        );
    }

    /**
     * Adds `wp_*` context variables every theme template can rely on.
     *
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function withWordPressContext(array $vars): array
    {
        $context = [];

        if (function_exists('get_bloginfo')) {
            $context['wp_blogname'] = (string) get_bloginfo('name');
            $context['wp_description'] = (string) get_bloginfo('description');
            $context['wp_version'] = (string) get_bloginfo('version');
            $context['wp_charset'] = (string) get_bloginfo('charset');
            $context['wp_language'] = (string) get_bloginfo('language');
        }

        if (function_exists('home_url')) {
            $context['wp_home_url'] = (string) home_url('/');
        }

        $context['wp_locale'] = $this->engine->locale();
        $context['wp_year'] = date('Y');

        return $vars + $context;
    }
}
