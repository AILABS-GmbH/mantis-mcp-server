<?php

declare(strict_types=1);

namespace MantisMcp\Extension\Tools;

use MantisMcp\Extension\AbstractTool;

/**
 * Lists the projects accessible to the authenticated user.
 */
final class ListProjectsTool extends AbstractTool
{
    public function name(): string
    {
        return 'mantis_list_projects';
    }

    public function description(): string
    {
        return 'Lists all projects the authenticated user can access '
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

    public function execute(array $arguments): mixed
    {
        $projectIds = current_user_get_accessible_projects();

        $result = [];
        foreach ($projectIds as $projectId) {
            $projectId = (int) $projectId;
            $row = project_get_row($projectId);
            $result[] = [
                'id' => $projectId,
                'name' => (string) $row['name'],
                'status' => $this->enumLabel('project_status_enum_string', (int) $row['status']),
                'enabled' => (bool) $row['enabled'],
                'description' => (string) $row['description'],
            ];
        }

        return ['count' => count($result), 'projects' => $result];
    }
}
