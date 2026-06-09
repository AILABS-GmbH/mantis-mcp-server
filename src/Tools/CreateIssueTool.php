<?php

declare(strict_types=1);

namespace MantisMcp\Tools;

/**
 * Creates a new issue in Mantis.
 */
final class CreateIssueTool extends AbstractTool
{
    public function name(): string
    {
        return 'mantis_create_issue';
    }

    public function description(): string
    {
        return 'Creates a new issue (ticket) in a Mantis project. '
            . 'Required: project_id, summary, description, category. '
            . 'Optional: priority, severity, additional_information.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'ID of the target project (see mantis_list_projects).',
                    'minimum' => 1,
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Short, concise summary (title).',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Detailed description of the problem.',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Category name (e.g. "General").',
                ],
                'priority' => [
                    'type' => 'string',
                    'description' => 'Optional: priority (e.g. low, normal, high, urgent).',
                ],
                'severity' => [
                    'type' => 'string',
                    'description' => 'Optional: severity (e.g. minor, major, crash, block).',
                ],
                'additional_information' => [
                    'type' => 'string',
                    'description' => 'Optional: extra information, steps to reproduce, etc.',
                ],
            ],
            'required' => ['project_id', 'summary', 'description', 'category'],
            'additionalProperties' => false,
        ];
    }

    protected function run(array $args): mixed
    {
        $body = [
            'summary' => $this->requireString($args, 'summary'),
            'description' => $this->requireString($args, 'description'),
            'project' => ['id' => $this->requireInt($args, 'project_id')],
            'category' => ['name' => $this->requireString($args, 'category')],
        ];

        if (($priority = $this->optionalString($args, 'priority')) !== null) {
            $body['priority'] = ['name' => $priority];
        }
        if (($severity = $this->optionalString($args, 'severity')) !== null) {
            $body['severity'] = ['name' => $severity];
        }
        if (($info = $this->optionalString($args, 'additional_information')) !== null) {
            $body['additional_information'] = $info;
        }

        $response = $this->client->post('issues', $body);
        $created = $response['issue'] ?? [];

        return [
            'created' => true,
            'issue' => $this->summarizeIssue($created, withDescription: false),
        ];
    }
}
