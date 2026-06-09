<?php

declare(strict_types=1);

namespace MantisMcp\Tools;

/**
 * Lists the Mantis projects visible to the configured token.
 */
final class ListProjectsTool extends AbstractTool
{
    public function name(): string
    {
        return 'mantis_list_projects';
    }

    public function description(): string
    {
        return 'Lists all projects the configured API token can access '
            . '(id, name, status, description). Useful for determining the project_id for other tools.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    protected function run(array $args): mixed
    {
        $response = $this->client->get('projects');
        $projects = $response['projects'] ?? [];

        $result = [];
        foreach ($projects as $project) {
            $result[] = [
                'id' => $project['id'] ?? null,
                'name' => $project['name'] ?? null,
                'status' => $project['status']['name'] ?? null,
                'enabled' => $project['enabled'] ?? null,
                'description' => $project['description'] ?? null,
            ];
        }

        return ['count' => count($result), 'projects' => $result];
    }
}
