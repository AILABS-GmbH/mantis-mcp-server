<?php

/**
 * Mantis MCP Extension — optional local configuration.
 *
 * Copy to "config.php" to override the defaults. The file must return an
 * array. Database credentials are NOT configured here — they come from
 * Mantis' own config_inc.php via core.php.
 */

return [
    // Directory for the JSON Lines log files. Must be writable by the web
    // server user and must not be web-accessible (the shipped logs/.htaccess
    // takes care of that for the default location).
    'log_dir' => __DIR__ . '/logs',

    // debug | info | notice | warning | error | critical
    // Note: at "debug" the suppressed PHP warnings of the old Mantis core
    // are logged too — useful for diagnosis, noisy in production.
    'log_level' => 'info',
];
