# Workstreams (delegation map)

Each stream is independently verifiable. Status: `todo` / `wip` / `done`.

| # | Stream | Deliverables | Depends on | Status |
|---|---|---|---|---|
| 1 | Support + Contracts | `src/Support/{Str,Num,Arr,Pcre,Clock}.php`, `src/{Config,ConfigBuilder,Container,Scope,RequestContext,Diagnostics}.php`, `src/Contracts/*`, enums | — | todo |
| 2 | Infra | `src/Io/{Files,AtomicWriter,PathGuard}.php`, `src/Html/{Escaper,Cleaner}.php`, `src/Cache/*`, `src/Log/*`, `src/Event/*` | 1 | todo |
| 3 | SSI | `src/Ssi/*` incl. `Directives/{Config,Set,Include,Echo,If,Elif,Else,Endif,Exec,Fsize,Flastmod,Printenv,Block,Fallback}.php`, `Expression`, `Exec`, `TimeFormat`, `SizeFormat`, `Builtins` | 1,2 | todo |
| 4 | Template engine | `src/Template/{Resolver,Substitution,Filters,Loops,Conditionals,Inheritance,Partials,RawBlocks,Renderer,CompileCache}.php` | 1,2,3 | todo |
| 5 | i18n | `src/I18n/{Locale,PluralForms,Catalog,CatalogReader{Po,Mo},Translator,Interpolator,Extractor}.php` | 1,2 | todo |
| 6 | Engine facade | `src/Engine.php`, `src/Version.php`, `src/Exceptions/*` | 1-5 | todo |
| 7 | WordPress | `wordpress/html-wp.php` (bridge + Maintenance toolkit), `wordpress/html-php-v4/` plugin | 6 | todo |
| 8 | Tests | `tests/run.php`, `tests/TestKit.php`, `tests/cases/*.test.php`, `tests/wp-stubs.php`, `tests/fixtures/*` | 1-7 | todo |
| 9 | Build + editions | `tools/build.php`, `php83/html.php`, `php85/html.php`, `composer.json` ×3 | 6 | todo |
| 10 | Docs + release | `README.md`, `docs/*.md` (EN), `CHANGELOG.md`, `LICENSE`, `examples/*`, `.github/workflows/ci.yml`, `phpstan.neon` | all | todo |

## Verification commands

```bash
cd /home/user/html-php
php8.3 -l src/**/*.php              # syntax, 8.3 edition
php8.3 tests/run.php                # unit + integration matrix (src/)
php8.5 tests/run.php --bundle=php85 # same suite against the built bundle
php8.3 tools/build.php && php8.5 -l php85/html.php
```
