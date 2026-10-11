<?php

declare(strict_types=1);

namespace HtmlPhp\Log;

use HtmlPhp\Config\Log as LogConfig;
use HtmlPhp\Config\LogDriver;

/**
 * Chooses the logger implementation described by the configuration.
 */
final class LoggerFactory
{
    private function __construct()
    {
    }

    public static function create(LogConfig $config): LoggerInterface
    {
        return match ($config->driver) {
            LogDriver::Array => new ArrayLogger(),
            LogDriver::ErrorLog => new ErrorLogLogger($config->channel, $config->level),
            LogDriver::Syslog => new SyslogLogger($config->channel),
            LogDriver::File => $config->path === ''
                ? new NullLogger()
                : new FileLogger($config->path, $config->channel, $config->level),
            LogDriver::WordPress => class_exists('\HtmlPhp\WordPress\WpLogger')
                ? new \HtmlPhp\WordPress\WpLogger($config->channel, $config->level)
                : new ErrorLogLogger($config->channel, $config->level),
            LogDriver::Null => new NullLogger(),
        };
    }

    /**
     * Wraps a host `Psr\Log\LoggerInterface` (or anything with the same
     * method shape) so Monolog, Symfony and Laravel loggers plug straight in.
     */
    public static function wrap(object $psrLogger): LoggerInterface
    {
        return new Psr3Adapter($psrLogger);
    }
}
