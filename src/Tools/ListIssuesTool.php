<?php

declare(strict_types=1);

namespace MantisMcp\Tools;

/**
 * Lists issues of a project page by page (compact summary).
 */
final class ListIssuesTool extends AbstractTool
{
    public function name(): string
    {
        return 'mantis_list_issues';
    }

    public function description(): string
    {
        return 'Lists issues, optionally filtered by project, with pagination. '
            . 'Returns a compact summary per issue (without long descriptions).';
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

    protected function run(array $args): mixed
    {
        $page = $this->optionalInt($args, 'page') ?? 1;
        $pageSize = $this->optionalInt($args, 'page_size') ?? 25;
        $pageSize = max(1, min(100, $pageSize));

        $query = [
            'page' => max(1, $page),
            'page_size' => $pageSize,
        ];
        $projectId = $this->optionalInt($args, 'project_id');
        if ($projectId !== null) {
            $query['project_id'] = $projectId;
        }

        $response = $this->client->get('issues', $query);
        $issues = $response['issues'] ?? [];

        $result = [];
        foreach ($issues as $issue) {
            $result[] = $this->summarizeIssue($issue, withDescription: false);
        }

        return [
            'page' => $query['page'],
            'page_size' => $pageSize,
            'count' => count($result),
            'issues' => $result,
        ];
    }
}
