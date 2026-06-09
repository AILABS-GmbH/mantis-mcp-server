<?php

declare(strict_types=1);

namespace MantisMcp\Tools;

use MantisMcp\Mcp\JsonRpcException;

/**
 * Updates selected fields of an existing issue.
 */
final class UpdateIssueTool extends AbstractTool
{
    public function name(): string
    {
        return 'mantis_update_issue';
    }

    public function description(): string
    {
        return 'Updates fields of an existing issue (status, priority, severity, '
            . 'summary, description). Only the provided fields are changed.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'issue_id' => [
                    'type' => 'integer',
                    'description' => 'ID of the issue to update.',
                    'minimum' => 1,
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'New status (e.g. new, assigned, resolved, closed).',
                ],
                'priority' => [
                    'type' => 'string',
                    'description' => 'New priority (e.g. low, normal, high, urgent).',
                ],
                'severity' => [
                    'type' => 'string',
                    'description' => 'New severity (e.g. minor, major, crash).',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'New title/summary.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'New description.',
                ],
            ],
            'required' => ['issue_id'],
            'additionalProperties' => false,
        ];
    }

    protected function run(array $args): mixed
    {
        $issueId = $this->requireInt($args, 'issue_id');

        $body = [];
        if (($status = $this->optionalString($args, 'status')) !== null) {
            $body['status'] = ['name' => $status];
        }
        if (($priority = $this->optionalString($args, 'priority')) !== null) {
            $body['priority'] = ['name' => $priority];
        }
        if (($severity = $this->optionalString($args, 'severity')) !== null) {
            $body['severity'] = ['name' => $severity];
        }
        if (($summary = $this->optionalString($args, 'summary')) !== null) {
            $body['summary'] = $summary;
        }
        if (($description = $this->optionalString($args, 'description')) !== null) {
            $body['description'] = $description;
        }

        if ($body === []) {
            throw new JsonRpcException(
                JsonRpcException::INVALID_PARAMS,
                'No field to update was provided.'
            );
        }

        $response = $this->client->patch("issues/{$issueId}", $body);
        $updated = $response['issue'] ?? [];

        return [
            'updated' => true,
            'issue' => $this->summarizeIssue($updated, withDescription: false),
        ];
    }
}
