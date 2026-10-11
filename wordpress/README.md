# WordPress Integration

* **[html-wp.php](html-wp.php)** — bridge + maintenance toolkit (require it
  from your plugin/theme, then call `html_wp_boot()`);
* **[html-php-maintenance/](html-php-maintenance/)** — ready-made
  maintenance plugin (Tools → HTML.PHP dashboard, daily health cron,
  dashboard widget, WP-CLI commands).

Full documentation: [../docs/WORDPRESS.md](../docs/WORDPRESS.md).
Installation layout expected by the plugin:

```text
wp-content/plugins/html-php/
├── php83/html.php            # or php85/html.php
├── wordpress/
│   ├── html-wp.php
│   └── html-php-maintenance/ # activate this as the plugin
```

(Or adjust the single `require_once` in `html-php-maintenance.php` and
define `HTML_WP_ENGINE` with the absolute path to your `html.php`.)
