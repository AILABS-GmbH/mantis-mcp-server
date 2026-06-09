<?php

declare(strict_types=1);

namespace MantisMcp\Tools;

/**
 * Returns the details of a single issue, including notes.
 */
final class GetIssueTool extends AbstractTool
{
    public function name(): string
    {
        return 'mantis_get_issue';
    }

    public function description(): string
    {
        return 'Returns the details of a Mantis issue by its ID, including '
            . 'status, assignment and existing notes.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'issue_id' => [
                    'type' => 'integer',
                    'description' => 'The numeric ID of the issue.',
                    'minimum' => 1,
                ],
            ],
            'required' => ['issue_id'],
            'additionalProperties' => false,
        ];
    }

    protected function run(array $args): mixed
    {
        $issueId = $this->requireInt($args, 'issue_id');

        $response = $this->client->get("issues/{$issueId}");
        $issues = $response['issues'] ?? [];
        if ($issues === []) {
            return ['found' => false, 'issue_id' => $issueId];
        }

        $issue = $issues[0];
        $result = $this->summarizeIssue($issue);

        // Append notes in a compact form.
        $notes = [];
        foreach ($issue['notes'] ?? [] as $note) {
            $notes[] = [
                'id' => $note['id'] ?? null,
                'reporter' => $note['reporter']['name'] ?? null,
                'created_at' => $note['created_at'] ?? null,
                'text' => $note['text'] ?? null,
            ];
        }
        $result['notes'] = $notes;

        return ['found' => true, 'issue' => $result];
    }
}
