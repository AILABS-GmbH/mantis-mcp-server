<?php

declare(strict_types=1);

namespace MantisMcp\Extension\Tools;

use MantisMcp\Extension\AbstractTool;
use MantisMcp\Extension\JsonRpcException;

/**
 * Returns the details of a single issue, including its visible notes.
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
            . 'status, assignment and the notes visible to the authenticated user.';
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

    public function execute(array $arguments): mixed
    {
        $issueId = $this->requireInt($arguments, 'issue_id');

        if (!bug_exists($issueId)) {
            return ['found' => false, 'issue_id' => $issueId];
        }
        if (!access_has_bug_level((int) config_get('view_bug_threshold'), $issueId)) {
            throw new JsonRpcException(
                JsonRpcException::INVALID_PARAMS,
                "Access denied: you are not allowed to view issue {$issueId}."
            );
        }

        $bug = bug_get($issueId, true);
        $result = $this->summarizeBug($bug);

        // Notes, filtered to what this user may see.
        $notes = [];
        $rawNotes = bugnote_get_all_visible_bugnotes($issueId, 'ASC', 0);
        if (is_array($rawNotes)) {
            foreach ($rawNotes as $note) {
                $notes[] = [
                    'id' => (int) $note->id,
                    'reporter' => $this->userName((int) $note->reporter_id),
                    'created_at' => date('c', (int) $note->date_submitted),
                    'private' => ((int) $note->view_state) !== VS_PUBLIC,
                    'text' => (string) $note->note,
                ];
            }
        }
        $result['notes'] = $notes;

        return ['found' => true, 'issue' => $result];
    }
}
