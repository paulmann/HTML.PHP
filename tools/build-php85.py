#!/usr/bin/env python3
"""
Builds php85/html.php from php83/html.php.

The 8.5 edition is API- and behaviour-identical; the transformation applies
genuine PHP 8.4/8.5 language features at well-chosen seams:

  * RenderState value object shared by include contexts, with a property
    hook normalising timefmt and readonly promotion;
  * asymmetric visibility (public private(set)) on Context::$now;
  * the pipe operator | > in the Renderer pass loop;
  * array_first()/array_last();
  * first-class callable syntax is_string(...) in the cache sweep;
  * #[\\NoDiscard] on value-returning API where discarding is a bug;
  * typed class constants (8.3 syntax, restored);
  * MIN_PHP guard raised to 80500.

Every replacement is asserted; the script fails loudly on drift.
"""
import re
import sys

SRC = 'php83/html.php'
DST = 'php85/html.php'

src = open(SRC, encoding='utf-8').read()
n = 0


def rep(old: str, new: str, count: int = 1) -> None:
    global src, n
    found = src.count(old)
    if found != count:
        sys.exit(f'REPLACE FAILED (found {found}, expected {count}):\n---\n{old[:400]}\n---')
    src = src.replace(old, new)
    n += count


# ---------------------------------------------------------------- A. header
rep(' * VERSION: 3.0.0 (PHP 8.3 edition)',
    ' * VERSION: 3.0.0 (PHP 8.5 edition)')
rep(' * Compatibility target: PHP 8.3+ (see php85/html.php for the PHP 8.5 edition).',
    ' * Compatibility target: PHP 8.5+ (see php83/html.php for the portable 8.3 edition).\n'
    ' *\n'
    ' * This edition additionally uses PHP 8.4/8.5 language features: property\n'
    ' * hooks (RenderState::$timefmt), readonly and asymmetric-visibility\n'
    ' * constructor promotion (Context), the pipe operator in the render\n'
    ' * pipeline, array_first()/array_last(), first-class callable syntax,\n'
    ' * typed class constants and #[\\NoDiscard] annotations. On PHP < 8.5 it\n'
    ' * fails fast with a parse error; use php83/html.php there.')
rep(" *   - WordPress bridge + maintenance toolkit (separate file).",
    " *   - WordPress bridge + maintenance toolkit (separate file).\n"
    " *   - 8.5 edition: property hooks, readonly/asymmetric-visibility state,\n"
    " *     pipe-operator render pipeline, array_first/array_last,\n"
    " *     first-class callables, typed constants and #[\\NoDiscard].")

# ------------------------------------------------------------- B. Version
rep("        public const EDITION = '8.3';", "        public const EDITION = '8.5';")
rep('        public const MIN_PHP = 80300;', '        public const MIN_PHP = 80500;')
rep("                    self::MIN_PHP === 80300 ? '8.3' : '8.5',",
    "                    '8.5',")

# ------------------------------------------------- C. RenderState + Context
old_ctx_props = """    final class Context
    {
        public int $now;
        public int $depth = 0;
        public int $processed = 0;

        /** @var array<string, true> realpath stack guarding include cycles */
        public array $stack = [];

        public string $timefmt;
        public string $sizefmt;
        public ?string $errmsg = null;

        /** @var array<string, string>|null */
        private ?array $builtins = null;

        public function __construct(
            public ?string $currentFile = null,
            public ?string $rootOverride = null
        ) {
            $this->now = time();
            $this->timefmt = TimeFormat::current();
            $this->sizefmt = strtolower(Config::text('HTML_SSI_SIZEFMT', 'abbrev'));

            $globalErrmsg = $GLOBALS['HTML_SSI_ERRMSG'] ?? null;
            $this->errmsg = is_string($globalErrmsg) ? $globalErrmsg : null;
        }

        /**
         * Child context for an included file (shares budgets and #config state).
         */
        public function child(string $file): self
        {
            $child = new self($file, $this->rootOverride);
            $child->now = $this->now;
            $child->depth = $this->depth + 1;
            $child->processed = &$this->processed;
            $child->stack = &$this->stack;
            $child->timefmt = &$this->timefmt;
            $child->sizefmt = &$this->sizefmt;
            $child->errmsg = &$this->errmsg;

            return $child;
        }"""

new_ctx_props = """    /**
     * Mutable per-render state shared by an include tree: #config overrides,
     * the include-cycle stack and the directive budget.
     *
     * PHP 8.5 flavour: $timefmt uses a property hook so an empty assignment
     * can never leave the render without a usable time format.
     */
    final class RenderState
    {
        public string $timefmt {
            set(string $value) {
                $this->timefmt = $value !== '' ? $value : TimeFormat::DEFAULT_FORMAT;
            }
        }

        public string $sizefmt;
        public ?string $errmsg = null;
        public int $processed = 0;

        /** @var array<string, true> realpath stack guarding include cycles */
        public array $stack = [];

        public function __construct()
        {
            $this->timefmt = TimeFormat::current();
            $this->sizefmt = strtolower(Config::text('HTML_SSI_SIZEFMT', 'abbrev'));

            $globalErrmsg = $GLOBALS['HTML_SSI_ERRMSG'] ?? null;
            $this->errmsg = is_string($globalErrmsg) ? $globalErrmsg : null;
        }
    }

    final class Context
    {
        public int $depth = 0;

        /** @var array<string, string>|null */
        private ?array $builtins = null;

        public function __construct(
            public ?string $currentFile = null,
            public ?string $rootOverride = null,
            public readonly RenderState $state = new RenderState(),
            public private(set) int $now = 0
        ) {
            $this->now = time();
        }

        /**
         * Child context for an included file (shares the render state object).
         */
        public function child(string $file): self
        {
            $child = new self($file, $this->rootOverride, $this->state, $this->now);
            $child->depth = $this->depth + 1;

            return $child;
        }"""
rep(old_ctx_props, new_ctx_props)

# Context method bodies referencing moved fields
rep("""            $timefmt = $context !== null ? $context->timefmt : TimeFormat::current();
            $now = $context !== null ? $context->now : time();""",
    """            $timefmt = $context !== null ? $context->state->timefmt : TimeFormat::current();
            $now = $context !== null ? $context->now : time();""")

rep("""        public function errorText(): string
        {
            return $this->errmsg ?? '';
        }""",
    """        public function errorText(): string
        {
            return $this->state->errmsg ?? '';
        }""")

rep("""            if ($this->processed >= $maxTags) {
                Errors::add(Errors::SSI_LIMIT_REACHED, 'SSI directive budget exhausted.', ['processed' => $this->processed]);""",
    """            if ($this->state->processed >= $maxTags) {
                Errors::add(Errors::SSI_LIMIT_REACHED, 'SSI directive budget exhausted.', ['processed' => $this->state->processed]);""")

# Processor field accesses
rep("""            } finally {
                unset($this->context->stack[$path]);
                $this->context->processed = $child->processed;
            }""",
    """            } finally {
                unset($this->context->state->stack[$path]);
            }""")
rep('$this->context->timefmt', '$this->context->state->timefmt', 2)   # config set + lastmod read
rep('$this->context->sizefmt', '$this->context->state->sizefmt', 2)
rep('$this->context->errmsg = $attributes[\'errmsg\'];',
    '$this->context->state->errmsg = $attributes[\'errmsg\'];')
rep('++$this->context->processed;', '++$this->context->state->processed;')
rep('isset($this->context->stack[$path])', 'isset($this->context->state->stack[$path])')
rep('$this->context->stack[$path] = true;', '$this->context->state->stack[$path] = true;')

# ---------------------------------------------------- D. Renderer pipe loop
old_loop = """            $text = $template;

            for ($pass = 0; $pass < $passes; ++$pass) {
                if (($options['ssi'] ?? true)) {
                    $context = new Context(
                        isset($options['file']) && is_string($options['file']) ? $options['file'] : null,
                        isset($options['root']) && is_string($options['root']) ? $options['root'] : null
                    );
                    $text = Processor::process($text, $context);
                }

                $text = self::renderBlocks($text, $vars, $options);
            }"""
new_loop = """            $file = isset($options['file']) && is_string($options['file']) ? $options['file'] : null;
            $root = isset($options['root']) && is_string($options['root']) ? $options['root'] : null;

            $applySsi = static fn(string $chunk): string => ($options['ssi'] ?? true)
                ? Processor::process($chunk, new Context($file, $root))
                : $chunk;
            $applyBlocks = static fn(string $chunk): string => self::renderBlocks($chunk, $vars, $options);

            $text = $template;

            for ($pass = 0; $pass < $passes; ++$pass) {
                // PHP 8.5 pipe operator: the value threads through each stage.
                $text = $text |> $applySsi(...) |> $applyBlocks(...);
            }"""
rep(old_loop, new_loop)

# ----------------------------------------------- E. array_first/array_last
rep('            $fallback = self::normalize($default ?? $availableList[0]);',
    '            $fallback = self::normalize($default ?? array_first($availableList));')
rep("""        public static function last(): ?array
        {
            return self::$entries === [] ? null : self::$entries[count(self::$entries) - 1];
        }""",
    """        public static function last(): ?array
        {
            return self::$entries === [] ? null : array_last(self::$entries);
        }""")

# ------------------------------------------- F. first-class callable syntax
rep("""            foreach ((array) glob($directory . \\DIRECTORY_SEPARATOR . '*' . self::EXTENSION) as $path) {
                if (is_string($path) && is_file($path) && @unlink($path)) {
                    ++$deleted;
                }
            }""",
    """            $entries = array_filter(
                (array) glob($directory . \\DIRECTORY_SEPARATOR . '*' . self::EXTENSION),
                is_string(...)
            );

            foreach ($entries as $path) {
                if (is_file($path) && @unlink($path)) {
                    ++$deleted;
                }
            }""")

# ------------------------------------------------------- G. #[\NoDiscard]
nodiscard = [
    ('        public static function banner(): string', 'Returns the version banner.'),
    ('        public static function evaluate(string $expression, ?Context $context = null): bool', 'The condition result drives branch selection.'),
    ('        public function select(int $n): int', 'The plural index is the whole point of the call.'),
    ('        public static function interpolate(string $text, array $args): string', 'The interpolated string is the result.'),
    ('        public static function apply(string $name, string $value, array $args = []): string', 'The filtered value is the result.'),
    ("""        public static function process(
            string $html,
            callable $resolver,
            string $execSymbol = '%',
            bool $autoEscape = false
        ): string {""", 'The rendered string is the result.'),
    ('        public static function renderFile(string $file, array $vars = [], array $options = []): string', 'The rendered string is the result.'),
    ("        public static function slugify(string $text, string $separator = '-'): string", 'The slug is the result.'),
    ("        public static function truncate(string $text, int $length, string $suffix = '\u2026'): string", 'The truncated string is the result.'),
    ('        public static function divide(mixed $a, mixed $b): float|int', 'The quotient is the result.'),
    ('        public static function stats(): array', 'The statistics array is the result.'),
    ('        public static function last(): ?array', 'The entry is the result.'),
    ('        public static function json(mixed $value): string', 'The encoded string is the result.'),
]
for signature, reason in nodiscard:
    rep(signature, "        #[\\NoDiscard('" + reason + "')]\n" + signature)

global_nodiscard = [
    'function html_version(): string',
    'function html_config(?array $overrides = null): array',
    'function html_detect_locale(array $available, ?string $acceptLanguage = null, ?string $default = null): string',
    'function html_get_locale(): string',
    'function html_ssi_variables(?string $file = null): array',
    'function html_slugify(string $text, string $separator = \'-\'): string',
    'function html_truncate(string $text, int $length, string $suffix = \'…\'): string',
    'function html_escape(string $value): string',
    'function html_escape_attr(string $value): string',
    'function html_escape_url(string $value): string',
    'function html_errors(string $code = \'\'): array',
    'function Divide(mixed $a, mixed $b): float|int',
]
for signature in global_nodiscard:
    rep('    ' + signature, "    #[\\NoDiscard]\n    " + signature)

# --------------------------------------------------- H. typed class consts
def type_constants(text: str) -> str:
    def infer(value: str) -> str:
        v = value.lstrip()
        if v.startswith("'") or v.startswith('"'):
            return 'string'
        if v.startswith('['):
            return 'array'
        if v.startswith('true') or v.startswith('false'):
            return 'bool'
        if re.match(r'^-?\d', v):
            return 'int'
        return ''

    def sub(m):
        indent, vis, name, value = m.group(1), m.group(2), m.group(3), m.group(4)
        kind = infer(value)
        if kind == '':
            return m.group(0)
        return f'{indent}{vis} const {kind} {name} = {value}'

    return re.sub(
        r'^(\s*)((?:public|private|protected)) const ([A-Z][A-Z0-9_]*) = (.*)$',
        sub, text, flags=re.M)

before = src
src = type_constants(src)
if src == before:
    sys.exit('typed-constant pass changed nothing — pattern drift')
n_typed = sum(1 for a, b in zip(before.splitlines(), src.splitlines()) if a != b)

open(DST, 'w', encoding='utf-8').write(src)
print(f'OK: {DST} written; {n} targeted replacements, ~{n_typed} typed-constant lines')
