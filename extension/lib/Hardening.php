<?php

declare(strict_types=1);

namespace MantisMcp\Extension;

use Throwable;

/**
 * Hardens the PHP runtime for the MCP endpoint running INSIDE a MantisBT
 * installation (tested against MantisBT 1.2.8 under PHP 8).
 *
 * Two-phase design:
 *
 *  - preCore():  must run BEFORE including Mantis' core.php. Disables any
 *    error output (old Mantis core emits many warnings under PHP 8 that
 *    would corrupt the JSON-RPC stream) and disables session cookies —
 *    Mantis 1.2.x calls session_start() while loading core.php, and we must
 *    not let it send a Set-Cookie header.
 *
 *  - postCore(): must run AFTER including core.php. Mantis registers its own
 *    HTML-oriented error handler (core/error_api.php); we override it with a
 *    PHP-8-safe handler that logs and converts Mantis' trigger_error() based
 *    failures (E_USER_ERROR) into exceptions the dispatcher can turn into
 *    proper tool errors. This mirrors what api/soap/mc_api.php does with
 *    mc_error_handler(), minus its PHP 8 incompatibility.
 */
final class Hardening
{
    private static ?Logger $logger = null;

    /** Call BEFORE require(core.php). */
    public static function preCore(): void
    {
        // Never let PHP (or old Mantis code) write errors into the response.
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('html_errors', '0');
        ini_set('log_errors', '0');
        error_reporting(E_ALL);

        // Mantis 1.2.x starts a PHP session while loading core.php. Keep the
        // session memory-only: no cookies, no cache headers.
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '0');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cache_limiter', '');

        ini_set('expose_php', '0');

        // Buffer any stray output produced while core.php loads (warnings
        // echoed by isolated code paths, BOMs, etc.) so it can be discarded
        // before we emit JSON.
        ob_start();
    }

    /** Call AFTER require(core.php). */
    public static function postCore(Logger $logger): void
    {
        self::$logger = $logger;

        // Discard anything old Mantis code printed during bootstrap.
        $stray = ob_get_clean();
        if (is_string($stray) && trim($stray) !== '') {
            $logger->debug('Discarded stray output from Mantis core bootstrap', [
                'bytes' => strlen($stray),
                'preview' => substr(trim($stray), 0, 200),
            ]);
        }

        // Mantis' core registered its own HTML error handler — replace it.
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * PHP-8-safe error handler (4 arguments, unlike Mantis 1.2.x handlers).
     *
     * Mantis signals business failures via trigger_error(<code>, ERROR)
     * where <code> is a numeric Mantis error constant. We convert those to
     * MantisCoreError exceptions so tools can report them gracefully.
     * Ordinary warnings/deprecations from the 1.2.x core under PHP 8 are
     * logged at debug level and suppressed.
     */
    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) {
            return true; // suppressed with @
        }

        if ($severity === E_USER_ERROR) {
            // Translate a numeric Mantis error code into its message.
            $text = $message;
            if (ctype_digit($message) && function_exists('error_string')) {
                $translated = error_string((int) $message);
                if (is_string($translated) && $translated !== '') {
                    $text = $translated;
                }
            }
            throw new MantisCoreError($text, ctype_digit($message) ? (int) $message : 0);
        }

        // Old core emits plenty of warnings/deprecations under PHP 8 — keep
        // them out of the response and out of noisy log levels.
        self::$logger?->debug('PHP notice from Mantis core', [
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
        ]);

        return true;
    }

    public static function handleException(Throwable $e): void
    {
        self::$logger?->critical('Unhandled exception', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        self::emergencyJsonError();
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null) {
            return;
        }
        $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
        if (($error['type'] & $fatalTypes) === 0) {
            return;
        }
        self::$logger?->critical('Fatal error (shutdown)', $error);
        self::emergencyJsonError();
    }

    private static function emergencyJsonError(): void
    {
        if (headers_sent()) {
            return;
        }
        // Drop any partial output so the client receives clean JSON.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32603, 'message' => 'Internal server error'],
        ], JSON_UNESCAPED_SLASHES);
    }
}
