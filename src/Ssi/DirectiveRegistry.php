<?php

declare(strict_types=1);

namespace HtmlPhp\Ssi;

/**
 * Registry of custom (or overriding) SSI directives.
 */
final class DirectiveRegistry
{
    /** @var array<string, DirectiveInterface> */
    private array $directives = [];

    public function register(DirectiveInterface $directive): void
    {
        $name = strtolower(trim($directive->name()));

        if ($name === '' || preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(
                'Directive names must match [a-z][a-z0-9_-]* — got "' . $directive->name() . '".',
            );
        }

        $this->directives[$name] = $directive;
    }

    public function has(string $name): bool
    {
        return isset($this->directives[strtolower($name)]);
    }

    public function get(string $name): ?DirectiveInterface
    {
        return $this->directives[strtolower($name)] ?? null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->directives);
    }

    public function clear(): void
    {
        $this->directives = [];
    }
}
