<?php

declare(strict_types=1);

namespace MantisMcp\Support;

use Throwable;

/**
 * Central hardening of the PHP runtime for an MCP server.
 *
 * Important: an MCP server communicates exclusively over JSON-RPC. Any HTML
 * error output would corrupt the protocol stream, so HTML errors and direct
 * output are fully disabled and every error is redirected to our file log.
 */
final class Bootstrap
{
    private static ?Logger $logger = null;

    public static function init(Logger $logger): void
    {
        self::$logger = $logger;

        // --- Harden error output --------------------------------------------
        // Never send errors/notices to the client (it would corrupt JSON-RPC
        // and leak paths/stack traces).
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        // No HTML formatting of error messages.
        ini_set('html_errors', '0');
        // We log to our own file; disable PHP's built-in error_log to avoid
        // duplicate or unwanted output.
        ini_set('log_errors', '0');
        // Capture everything internally; filtering happens in our handler/logger.
        error_reporting(E_ALL);

        // No implicit PHP sessions/cookies - MCP uses the Mcp-Session-Id
        // header. Defensively neutralize cookie-related session settings.
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '0');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cache_limiter', '');

        // Set a deterministic timezone (prevents warnings).
        if (ini_get('date.timezone') === '' || ini_get('date.timezone') === false) {
            date_default_timezone_set('UTC');
        }

        // Reduce server signature exposure.
        ini_set('expose_php', '0');

        // --- Register handlers ----------------------------------------------
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Turns PHP errors into logged records. Returns false for suppressed
     * errors so the normal error handling (e.g. the @ operator) is preserved.
     */
    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        // Respect errors suppressed with the @ operator.
        if (!(error_reporting() & $severity)) {
            return false;
        }

        self::$logger?->error('PHP error', [
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
        ]);

        // Do not escalate further, but do not convert plain notices into
        // exceptions either (favouring stability).
        return true;
    }

    public static function handleException(Throwable $e): void
    {
        self::$logger?->critical('Unhandled exception', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        // If no response has been sent yet: generic 500 JSON response.
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32603,
                    'message' => 'Internal server error',
                ],
            ], JSON_UNESCAPED_SLASHES);
        }
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

        self::$logger?->critical('Fatal error (shutdown)', [
            'type' => $error['type'],
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
        ]);

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32603,
                    'message' => 'Internal server error',
                ],
            ], JSON_UNESCAPED_SLASHES);
        }
    }
}
