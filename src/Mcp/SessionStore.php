<?php

declare(strict_types=1);

namespace MantisMcp\Mcp;

/**
 * File-based management of MCP sessions.
 *
 * DELIBERATELY WITHOUT PHP sessions/cookies: the MCP Streamable HTTP transport
 * identifies sessions via the "Mcp-Session-Id" HTTP header. This store
 * generates and validates those IDs and keeps minimal state (e.g. whether
 * "initialize" already happened) in one JSON file per session.
 *
 * Security aspects:
 * - Session IDs are cryptographically random (>= 128 bits of entropy).
 * - IDs are strictly validated before any file access (no path traversal).
 * - Expired sessions are removed on access (lazy GC).
 */
final class SessionStore
{
    private string $dir;
    private int $ttl;

    public function __construct(string $dir, int $ttl = 3600)
    {
        $this->dir = rtrim($dir, '/');
        $this->ttl = max(60, $ttl);
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
    }

    /**
     * Creates a new session and returns its ID.
     *
     * @param array<string,mixed> $initialData
     */
    public function create(array $initialData = []): string
    {
        $id = bin2hex(random_bytes(32)); // 256 bits
        $data = $initialData + [
            'created_at' => time(),
            'last_seen' => time(),
        ];
        $this->write($id, $data);

        return $id;
    }

    /**
     * Returns whether a session exists and is valid (not expired).
     */
    public function isValid(string $id): bool
    {
        return $this->read($id) !== null;
    }

    /**
     * Reads the session data, or null if invalid/expired.
     *
     * @return array<string,mixed>|null
     */
    public function read(string $id): ?array
    {
        if (!self::isValidId($id)) {
            return null;
        }

        $path = $this->pathFor($id);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            @unlink($path);
            return null;
        }

        $lastSeen = (int) ($data['last_seen'] ?? 0);
        if ($lastSeen > 0 && (time() - $lastSeen) > $this->ttl) {
            @unlink($path); // lazy GC
            return null;
        }

        return $data;
    }

    /**
     * Updates "last_seen" and optionally merges additional fields.
     *
     * @param array<string,mixed> $merge
     */
    public function touch(string $id, array $merge = []): void
    {
        $data = $this->read($id);
        if ($data === null) {
            return;
        }
        $data = array_merge($data, $merge);
        $data['last_seen'] = time();
        $this->write($id, $data);
    }

    public function delete(string $id): void
    {
        if (!self::isValidId($id)) {
            return;
        }
        $path = $this->pathFor($id);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Strict validation: exactly 64 hex characters, no path traversal. */
    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $id);
    }

    /** @param array<string,mixed> $data */
    private function write(string $id, array $data): void
    {
        $path = $this->pathFor($id);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        @file_put_contents($path, $json, LOCK_EX);
        @chmod($path, 0640);
    }

    private function pathFor(string $id): string
    {
        return $this->dir . '/sess_' . $id . '.json';
    }
}
