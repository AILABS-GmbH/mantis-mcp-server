<?php

declare(strict_types=1);

namespace MantisMcp\Support;

/**
 * Lightweight, file-based logger using the JSON Lines format.
 *
 * - One log line = one JSON object (easy to parse and ship to log tooling).
 * - Writes atomically with LOCK_EX so concurrent requests do not interleave
 *   and corrupt each other's lines.
 * - Defensively redacts known secrets (tokens, passwords) from the context.
 *
 * Intentionally NOT a PSR-3 implementation to stay dependency-free, but the
 * method signatures follow PSR-3 conventions.
 */
final class Logger
{
    private const LEVELS = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
    ];

    private string $filePath;
    private int $minLevel;

    /** @var string[] Substrings whose values are masked in the context. */
    private array $secretKeys = ['token', 'authorization', 'password', 'secret', 'api_key', 'apikey'];

    public function __construct(string $logDir, string $minLevel = 'info')
    {
        if (!is_dir($logDir)) {
            // 0750: owner/group only - no world-readable logs.
            @mkdir($logDir, 0750, true);
        }
        $this->filePath = rtrim($logDir, '/') . '/mantis-mcp-' . date('Y-m-d') . '.log';
        $this->minLevel = self::LEVELS[$minLevel] ?? self::LEVELS['info'];
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $priority = self::LEVELS[$level] ?? self::LEVELS['info'];
        if ($priority < $this->minLevel) {
            return;
        }

        $record = [
            'ts' => date('c'),
            'level' => $level,
            'pid' => getmypid(),
            'message' => $message,
            'context' => $this->redact($context),
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            $line = json_encode([
                'ts' => date('c'),
                'level' => 'error',
                'message' => 'Failed to serialize log record',
                'original_message' => $message,
            ]);
        }

        // A failed write must never abort the request.
        @file_put_contents($this->filePath, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Recursively replaces values of suspicious keys with "***REDACTED***".
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function redact(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $lowerKey = is_string($key) ? strtolower($key) : (string) $key;
            $isSecret = false;
            foreach ($this->secretKeys as $needle) {
                if (str_contains($lowerKey, $needle)) {
                    $isSecret = true;
                    break;
                }
            }

            if ($isSecret) {
                $clean[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $clean[$key] = $this->redact($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
