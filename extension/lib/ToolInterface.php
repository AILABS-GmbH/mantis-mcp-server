<?php

declare(strict_types=1);

namespace MantisMcp\Extension;

/**
 * Contract for an MCP tool backed by the Mantis core APIs.
 */
interface ToolInterface
{
    /** Unique, stable tool name (snake_case). */
    public function name(): string;

    /** Short description aimed at the LLM. */
    public function description(): string;

    /**
     * JSON schema of the input parameters.
     *
     * @return array<string,mixed>
     */
    public function inputSchema(): array;

    /**
     * Executes the tool as the currently authenticated Mantis user.
     *
     * @param array<string,mixed> $arguments
     * @return mixed
     *
     * @throws JsonRpcException
     * @throws MantisCoreError
     */
    public function execute(array $arguments): mixed;
}
