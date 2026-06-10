<?php

declare(strict_types=1);

namespace MantisMcp\Extension\Tools;

use MantisMcp\Extension\AbstractTool;

/**
 * Lists issues page by page using Mantis' own filter engine, which enforces
 * the user's view permissions.
 */
final class ListIssuesTool extends AbstractTool
{
    public function name(): string
    {
        return 'mantis_list_issues';
    }

    public function description(): string
    {
        return 'Lists issues visible to the authenticated user, optionally filtered by project, '
            . 'with pagination. Returns a compact summary per issue (without long descriptions).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: only issues of this project.',
                    'minimum' => 1,
                ],
                'page' => [
                    'type' => 'integer',
                    'description' => 'Page number (1-based). Default: 1.',
                    'minimum' => 1,
                ],
                'page_size' => [
                    'type' => 'integer',
                    'description' => 'Number of issues per page (1-100). Default: 25.',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): mixed
    {
        $page = $this->optionalInt($arguments, 'page') ?? 1;
        $page = max(1, $page);
        $pageSize = $this->optionalInt($arguments, 'page_size') ?? 25;
        $pageSize = max(1, min(100, $pageSize));
        $projectId = $this->optionalInt($arguments, 'project_id') ?? ALL_PROJECTS;

        // filter_get_bug_rows() applies the user's access rights and returns
        // BugData objects. The first four parameters are by-reference.
        $pageCount = 0;
        $bugCount = 0;
        $rows = filter_get_bug_rows($page, $pageSize, $pageCount, $bugCount, null, $projectId);
        if (!is_array($rows)) {
            $rows = [];
        }

        $result = [];
        foreach ($rows as $bug) {
            $result[] = $this->summarizeBug($bug, false);
        }

        return [
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => (int) $pageCount,
            'total_issues' => (int) $bugCount,
            'count' => count($result),
            'issues' => $result,
        ];
    }
}
