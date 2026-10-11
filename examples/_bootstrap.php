<?php
/**
 * Shared example bootstrap: loads the engine edition that matches the
 * running PHP (php85/html.php on PHP 8.5+, php83/html.php otherwise).
 */

declare(strict_types=1);

$edition = PHP_VERSION_ID >= 80500 ? 'php85' : 'php83';

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . $edition . DIRECTORY_SEPARATOR . 'html.php';

date_default_timezone_set('UTC');
