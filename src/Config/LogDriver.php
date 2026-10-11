<?php

declare(strict_types=1);

namespace HtmlPhp\Config;

/** Diagnostics sinks shipped with the library. */
enum LogDriver: string
{
    case Null = 'null';
    case Array = 'array';
    case ErrorLog = 'errorlog';
    case File = 'file';
    case Syslog = 'syslog';
    case WordPress = 'wordpress';
}
