<?php
/**
 * 01 — Config value object, Container, Diagnostics, Scope, RequestContext.
 */

declare(strict_types=1);

use HtmlPhp\Config;
use HtmlPhp\Container;
use HtmlPhp\Diagnostics;
use HtmlPhp\Engine;
use HtmlPhp\Exception\ConfigException;
use HtmlPhp\Exception\ContainerException;
use HtmlPhp\RequestContext;
use HtmlPhp\Scope;

group('Config: construction');

test('defaults are complete and immutable', function (): void {
    $config = Config::default();

    assertSame(false, $config->debug);
    assertSame('UTF-8', $config->charset);
    assertSame(true, $config->syntax->autoEscape);
    assertSame('empty', $config->syntax->missingVar->value);
    assertSame(true, $config->ssi->enabled);
    assertSame('deny', $config->ssi->execMode->value);
    assertSame('en', $config->i18n->locale);
    assertSame(false, $config->cache->enabled);
    assertSame(16, $config->limits->maxSsiDepth);
});

test('fromArray reads nested groups', function (): void {
    $config = Config::fromArray([
        'debug' => true,
        'templates' => ['paths' => ['/tmp/a'], 'extension' => '.htm'],
        'syntax' => ['auto_escape' => false, 'missing_var' => 'throw'],
        'ssi' => ['exec_mode' => 'allow-list', 'time_format' => '%Y'],
        'cache' => ['enabled' => true, 'driver' => 'array', 'ttl' => 60],
        'limits' => ['max_ssi_depth' => 4],
    ]);

    assertSame(true, $config->debug);
    assertSame(['/tmp/a'], $config->templates->paths);
    assertSame('.htm', $config->templates->extension);
    assertSame(false, $config->syntax->autoEscape);
    assertSame('throw', $config->syntax->missingVar->value);
    assertSame('allow-list', $config->ssi->execMode->value);
    assertSame('%Y', $config->ssi->timeFormat);
    assertSame(true, $config->cache->enabled);
    assertSame(60, $config->cache->ttl);
    assertSame(4, $config->limits->maxSsiDepth);
});

test('unknown keys are rejected at every level', function (): void {
    assertThrows(ConfigException::class, static fn() => Config::fromArray(['debg' => true]));
    assertThrows(ConfigException::class, static fn() => Config::fromArray(['ssi' => ['exek' => true]]));
});

test('invalid enum values are rejected', function (): void {
    assertThrows(ConfigException::class, static fn() => Config::fromArray(['syntax' => ['missing_var' => 'shrug']]));
    assertThrows(ConfigException::class, static fn() => Config::fromArray(['cache' => ['driver' => 'redis']]));
    assertThrows(ConfigException::class, static fn() => Config::fromArray(['ssi' => ['size_format' => 'huge']]));
});

test('out-of-bounds limits throw', function (): void {
    assertThrows(ConfigException::class, static fn() => Config::fromArray(['limits' => ['max_ssi_depth' => 5000]]));
    assertThrows(ConfigException::class, static fn() => Config::fromArray(['cache' => ['ttl' => -5]]));
});

test('ssi exec delegate without callback throws', function (): void {
    assertThrows(ConfigException::class, static fn() => Config::fromArray(['ssi' => ['exec_mode' => 'delegate']]));
});

test('with* returns a modified clone', function (): void {
    $config = Config::default();
    $debug = $config->withDebug(true);

    assertSame(false, $config->debug);
    assertSame(true, $debug->debug);
});

test('merge overlays partial arrays', function (): void {
    $config = Config::fromArray(['ssi' => ['enabled' => true, 'time_format' => '%Y']]);
    $merged = $config->merge(['ssi' => ['time_format' => '%H:%M'], 'debug' => true]);

    assertSame('%H:%M', $merged->ssi->timeFormat);
    assertSame(true, $merged->ssi->enabled);
    assertSame(true, $merged->debug);
});

test('boolean-ish strings coerce predictably', function (): void {
    $config = Config::fromArray(['debug' => 'off', 'ssi' => ['enabled' => '1']]);
    assertSame(false, $config->debug);
    assertSame(true, $config->ssi->enabled);
});

test('v3-style boolean exec switch maps to policy', function (): void {
    assertSame('allow-list', Config::fromArray(['ssi' => ['exec_mode' => true]])->ssi->execMode->value);
    assertSame('deny', Config::fromArray(['ssi' => ['exec_mode' => false]])->ssi->execMode->value);
});

test('toArray round-trips through fromArray', function (): void {
    $config = Config::fromArray(['debug' => true, 'i18n' => ['locale' => 'de_DE']]);
    $again = Config::fromArray($config->toArray());

    assertSame($config->toArray(), $again->toArray());
    assertSame('de_DE', $again->i18n->locale);
});

group('Container');

test('factories are lazy and shared', function (): void {
    $calls = 0;
    $container = new Container();
    $container->factory('svc', static function () use (&$calls): stdClass {
        ++$calls;

        return new stdClass();
    });

    assertSame(false, $container->has('missing'));
    $first = $container->get('svc');
    $second = $container->get('svc');

    assertSame(1, $calls);
    assertSame($first, $second);
});

test('instance() overrides factories', function (): void {
    $container = new Container();
    $container->factory('svc', static fn() => new stdClass());
    $marker = new stdClass();
    $container->instance('svc', $marker);

    assertSame($marker, $container->get('svc'));
});

test('unknown service throws ContainerException', function (): void {
    assertThrows(ContainerException::class, static fn() => (new Container())->get('nope'));
});

test('service() enforces the type', function (): void {
    $container = new Container();
    $container->instance(stdClass::class, new stdClass());
    $container->instance('wrong', new stdClass());

    assertInstanceOf(stdClass::class, $container->service(stdClass::class));
    assertThrows(ContainerException::class, static fn() => $container->service(Container::class));
});

test('withDefaults wires the full graph and Engine::create accepts setup', function (): void {
    $engine = Engine::create([], static function (Container $container): void {
        $container->instance('marker', 'seen');
    });

    assertSame('seen', $engine->container()->get('marker'));
    assertInstanceOf(HtmlPhp\Template\Renderer::class, $engine->renderer());
    assertInstanceOf(HtmlPhp\Ssi\Processor::class, $engine->ssi());
    assertInstanceOf(HtmlPhp\I18n\Translator::class, $engine->translator());
    assertSame($engine->config(), $engine->container()->service(Config::class));
});

group('Diagnostics');

test('records, filters by code, caps and clears', function (): void {
    $diagnostics = new Diagnostics(3);
    $diagnostics->record('a-code', 'first');
    $diagnostics->record('b-code', 'second', ['x' => 1]);
    $diagnostics->record('a-code', 'third');
    $diagnostics->record('a-code', 'dropped by cap');

    assertSame(3, $diagnostics->count());
    assertSame(2, $diagnostics->count('a-code'));
    assertSame('third', $diagnostics->last()['message']);
    assertSame(['x' => 1], $diagnostics->byCode('b-code')[0]['context']);
    assertSame(false, $diagnostics->clean());

    $diagnostics->clear();
    assertSame(true, $diagnostics->clean());
    assertSame(null, $diagnostics->last());
});

test('forwards to the logger when wired', function (): void {
    $logger = new HtmlPhp\Log\ArrayLogger();
    $diagnostics = new Diagnostics(10, $logger);
    $diagnostics->record('missing-var', 'gone', ['name' => 'x']);

    assertSame(1, count($logger->records()));
    assertSame('warning', $logger->records()[0]['level']);
    assertSame('gone', $logger->records()[0]['message']);
});

group('Scope');

test('inner frames shadow outer ones', function (): void {
    $scope = new Scope(['a' => 1, 'b' => 2]);
    $scope->push(['b' => 3]);

    assertSame([true, 1], $scope->find('a'));
    assertSame([true, 3], $scope->find('b'));
    $scope->pop();
    assertSame([true, 2], $scope->find('b'));
});

test('dot notation walks arrays, ArrayAccess and objects incl. accessors', function (): void {
    $object = new class {
        public string $name = 'obj';

        public function label(): string
        {
            return 'via-method';
        }

        public function getComputed(): string
        {
            return 'via-getter';
        }
    };

    $scope = new Scope([
        'user' => ['address' => ['city' => 'Berlin']],
        'obj' => $object,
        'arr' => new ArrayObject(['k' => 'v']),
    ]);

    assertSame('Berlin', $scope->get('user.address.city'));
    assertSame('obj', $scope->get('obj.name'));
    assertSame('via-method', $scope->get('obj.label'));
    assertSame('via-getter', $scope->get('obj.computed'));
    assertSame('v', $scope->get('arr.k'));
    assertSame([false, null], $scope->find('user.address.zip'));
});

test('with() scopes a frame and always pops', function (): void {
    $scope = new Scope(['a' => 1]);

    $result = $scope->with(['a' => 2], static fn(Scope $inner) => $inner->get('a'));

    assertSame(2, $result);
    assertSame(1, $scope->get('a'));
    assertSame(1, $scope->depth());
});

test('there is no $GLOBALS fallback', function (): void {
    $GLOBALS['ESCAPE_ME'] = 'leak';
    $scope = new Scope([]);

    assertSame([false, null], $scope->find('ESCAPE_ME'));
    unset($GLOBALS['ESCAPE_ME']);
});

group('RequestContext');

test('fromServer sanitises and derives document fields', function (): void {
    $request = RequestContext::fromServer([
        'DOCUMENT_ROOT' => '/var/www',
        'REQUEST_URI' => '/dir/page.html?x=1',
        'SCRIPT_NAME' => '/dir/page.php',
        'REQUEST_METHOD' => 'post',
        'HTTP_USER_AGENT' => 'UA',
        'HTTP_X_CUSTOM' => 'yes',
        'OBJ' => new stdClass(), // non-scalar dropped
    ]);

    assertSame('/var/www', $request->documentRoot);
    assertSame('/dir/page.html', $request->documentUri);
    assertSame('page.html', $request->documentName);
    assertSame('POST', $request->method);
    assertSame('UA', $request->userAgent);
    assertSame('yes', $request->http('X_CUSTOM'));
    assertSame('yes', $request->http('HTTP_X_CUSTOM'));
    assertSame(null, $request->http('HTTP_MISSING'));
    assertSame(false, array_key_exists('OBJ', $request->server));
});

test('cli() is neutral', function (): void {
    $request = RequestContext::cli('/tmp');

    assertSame('CLI', $request->method);
    assertSame('/tmp', $request->documentRoot);
    assertSame('/', $request->documentUri);
});
