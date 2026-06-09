<?php

declare(strict_types=1);

namespace MantisMcp\Support;

/**
 * Minimal, dependency-free configuration loader.
 *
 * Reads values in this order: real environment variables first, then an
 * optional .env file in the project root. Real environment variables take
 * precedence so container/systemd deployments work without a .env file.
 */
final class Config
{
    /** @var array<string,string> */
    private array $values;

    /** @param array<string,string> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * Loads the configuration. $envFile is optional; if it does not exist,
     * only real environment variables are used.
     */
    public static function load(string $projectRoot, ?string $envFile = null): self
    {
        $values = [];

        $envFile ??= $projectRoot . '/.env';
        if (is_file($envFile) && is_readable($envFile)) {
            $values = self::parseEnvFile($envFile);
        }

        // Real environment variables override file values.
        foreach (array_keys($values) as $key) {
            $real = getenv($key);
            if ($real !== false) {
                $values[$key] = $real;
            }
        }

        return new self($values);
    }

    /** @return array<string,string> */
    private static function parseEnvFile(string $file): array
    {
        $result = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Strip surrounding quotes.
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            $result[$key] = $value;
        }

        return $result;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        return $value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? null;
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }
        return (int) $value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return string[] */
    public function getList(string $key): array
    {
        $value = $this->getString($key);
        if ($value === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($v) => $v !== ''));
    }
}
