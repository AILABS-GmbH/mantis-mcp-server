<?php

declare(strict_types=1);

namespace MantisMcp\Tools;

use MantisMcp\Mantis\MantisClient;
use MantisMcp\Mantis\MantisException;
use MantisMcp\Mcp\JsonRpcException;
use MantisMcp\Mcp\ToolInterface;

/**
 * Common base class for all Mantis tools.
 *
 * Encapsulates:
 * - the Mantis client,
 * - the uniform mapping of MantisException -> JsonRpcException,
 * - small, strict validation helpers for input arguments.
 */
abstract class AbstractTool implements ToolInterface
{
    public function __construct(protected readonly MantisClient $client)
    {
    }

    /**
     * Template method: runs run() and unifies error handling.
     *
     * @param array<string,mixed> $arguments
     */
    final public function execute(array $arguments): mixed
    {
        try {
            return $this->run($arguments);
        } catch (MantisException $e) {
            throw new JsonRpcException(
                JsonRpcException::UPSTREAM_ERROR,
                $e->getMessage(),
                $e->getDetail() !== null ? ['detail' => $e->getDetail(), 'status' => $e->getHttpStatus()] : null,
                $e,
            );
        }
    }

    /**
     * Concrete tool logic.
     *
     * @param array<string,mixed> $args
     */
    abstract protected function run(array $args): mixed;

    // --- Validation helpers -------------------------------------------------

    /** @param array<string,mixed> $args */
    protected function requireInt(array $args, string $key): int
    {
        if (!isset($args[$key]) || !is_numeric($args[$key])) {
            throw new JsonRpcException(JsonRpcException::INVALID_PARAMS, "Required field '{$key}' is missing or not a number.");
        }
        return (int) $args[$key];
    }

    /** @param array<string,mixed> $args */
    protected function optionalInt(array $args, string $key): ?int
    {
        if (!isset($args[$key]) || $args[$key] === '' || !is_numeric($args[$key])) {
            return null;
        }
        return (int) $args[$key];
    }

    /** @param array<string,mixed> $args */
    protected function requireString(array $args, string $key): string
    {
        $value = $args[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new JsonRpcException(JsonRpcException::INVALID_PARAMS, "Required field '{$key}' is missing or empty.");
        }
        return $value;
    }

    /** @param array<string,mixed> $args */
    protected function optionalString(array $args, string $key): ?string
    {
        $value = $args[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        return $value;
    }

    /** @param array<string,mixed> $args */
    protected function optionalBool(array $args, string $key): ?bool
    {
        if (!array_key_exists($key, $args)) {
            return null;
        }
        return (bool) $args[$key];
    }

    // --- Formatting ---------------------------------------------------------

    /**
     * Reduces a full Mantis issue to its most relevant fields, to avoid
     * flooding the LLM context with raw data.
     *
     * @param array<string,mixed> $issue
     * @return array<string,mixed>
     */
    protected function summarizeIssue(array $issue, bool $withDescription = true): array
    {
        $summary = [
            'id' => $issue['id'] ?? null,
            'summary' => $issue['summary'] ?? null,
            'project' => $issue['project']['name'] ?? null,
            'category' => $issue['category']['name'] ?? null,
            'status' => $issue['status']['name'] ?? null,
            'resolution' => $issue['resolution']['name'] ?? null,
            'priority' => $issue['priority']['name'] ?? null,
            'severity' => $issue['severity']['name'] ?? null,
            'reporter' => $issue['reporter']['name'] ?? null,
            'handler' => $issue['handler']['name'] ?? null,
            'created_at' => $issue['created_at'] ?? null,
            'updated_at' => $issue['updated_at'] ?? null,
        ];

        if ($withDescription) {
            $summary['description'] = $issue['description'] ?? null;
            $summary['additional_information'] = $issue['additional_information'] ?? null;
        }

        return $summary;
    }
}
