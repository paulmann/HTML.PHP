<?php

declare(strict_types=1);

namespace HtmlPhp\I18n;

use HtmlPhp\Config;
use HtmlPhp\Container;
use HtmlPhp\Diagnostics;
use HtmlPhp\Event\Dispatcher;

/**
 * Builds the default translator from the container.
 *
 * Hosts that want a fully custom implementation register it themselves:
 * `$container->instance(TranslatorInterface::class, $myTranslator);`
 */
final class TranslatorFactory
{
    private function __construct()
    {
    }

    public static function create(Container $container): TranslatorInterface
    {
        /** @var Config $config */
        $config = $container->get(Config::class);

        return new Translator(
            $config->i18n,
            $container->service(Diagnostics::class),
            $container->service(Dispatcher::class)
        );
    }
}
