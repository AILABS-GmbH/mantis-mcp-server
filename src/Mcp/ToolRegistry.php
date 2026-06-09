<?php

declare(strict_types=1);

namespace MantisMcp\Mcp;

/**
 * Holds the registered tools and exposes them to the server.
 */
final class ToolRegistry
{
    /** @var array<string,ToolInterface> */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Returns the tool definitions in the MCP tools/list format.
     *
     * @return list<array<string,mixed>>
     */
    public function describeAll(): array
    {
        $list = [];
        foreach ($this->tools as $tool) {
            $list[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }
        return $list;
    }
}
