<?php

declare(strict_types=1);

namespace HtmlPhp\Config;

/**
 * How `<!--#exec cmd="…"-->` and `<!--#exec cgi="…"-->` are handled.
 *
 * `Deny` is the default: a template is data, not a shell prompt. Allow-list
 * still refuses anything not matched by an explicit pattern.
 */
enum ExecMode: string
{
    /** Refuse every `#exec`, emit `errmsg` and record a diagnostic. */
    case Deny = 'deny';

    /** Run only commands/CGI paths matching the configured allow-list. */
    case AllowList = 'allow-list';

    /** Delegate the decision to a host-supplied callable. */
    case Delegate = 'delegate';
}
