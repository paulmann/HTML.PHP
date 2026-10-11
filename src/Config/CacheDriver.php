<?php

declare(strict_types=1);

namespace HtmlPhp\Config;

/**
 * Render-cache backends shipped with the library.
 *
 * `WordPress` resolves to the object-cache/transient adapter and is only
 * usable inside WordPress; the container throws a clear error otherwise.
 * Custom backends are registered by putting any
 * {@see \HtmlPhp\Contracts\CacheInterface} implementation into the container.
 */
enum CacheDriver: string
{
    case Null = 'null';
    case Array = 'array';
    case File = 'file';
    case Apcu = 'apcu';
    case WordPress = 'wordpress';
}
