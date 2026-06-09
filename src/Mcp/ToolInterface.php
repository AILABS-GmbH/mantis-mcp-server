<?php

declare(strict_types=1);

namespace MantisMcp\Mcp;

/**
 * Contract for an MCP tool.
 *
 * Each tool describes itself (name, description, JSON schema of its input)
 * and performs an action when called. The return value is the business
 * payload; wrapping it into the MCP "content" format is the server's job.
 */
interface ToolInterface
{
    /** Unique, stable tool name (snake_case). */
    public function name(): string;

    /** Short description aimed at the LLM. */
    public function description(): string;

    /**
     * JSON schema (Draft 2020-12 compatible) of the input parameters.
     *
     * @return array<string,mixed>
     */
    public function inputSchema(): array;

    /**
     * Executes the tool.
     *
     * @param array<string,mixed> $arguments Already decoded arguments.
     * @return mixed Business result (returned to the client as JSON text).
     *
     * @throws JsonRpcException On validation or upstream errors.
     */
    public function execute(array $arguments): mixed;
}
