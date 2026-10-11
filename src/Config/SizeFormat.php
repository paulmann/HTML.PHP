<?php

declare(strict_types=1);

namespace HtmlPhp\Config;

/** Apache `#config sizefmt` values. */
enum SizeFormat: string
{
    case Abbrev = 'abbrev';
    case Bytes = 'bytes';
}
