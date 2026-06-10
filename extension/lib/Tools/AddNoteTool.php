<?php

declare(strict_types=1);

namespace MantisMcp\Extension\Tools;

use MantisMcp\Extension\AbstractTool;
use MantisMcp\Extension\JsonRpcException;

/**
 * Adds a note (comment) to an issue via bugnote_add(), as the authenticated
 * user — history and notification emails behave exactly like in the UI.
 */
final class AddNoteTool extends AbstractTool
{
    public function name(): string
    {
        return 'mantis_add_note';
    }

    public function description(): string
    {
        return 'Adds a note/comment to an existing issue as the authenticated user. '
            . 'The note can optionally be marked as private.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'issue_id' => [
                    'type' => 'integer',
                    'description' => 'ID of the issue the note is added to.',
                    'minimum' => 1,
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'Content of the note.',
                ],
                'private' => [
                    'type' => 'boolean',
                    'description' => 'Optional: true => visible to internal users only. Default: false.',
                ],
            ],
            'required' => ['issue_id', 'text'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): mixed
    {
        $issueId = $this->requireInt($arguments, 'issue_id');
        $text = $this->requireString($arguments, 'text');
        $private = $this->optionalBool($arguments, 'private') ?? false;

        if (!bug_exists($issueId)) {
            throw new JsonRpcException(JsonRpcException::INVALID_PARAMS, "Issue {$issueId} does not exist.");
        }
        if (!access_has_bug_level((int) config_get('add_bugnote_threshold'), $issueId)) {
            throw new JsonRpcException(
                JsonRpcException::INVALID_PARAMS,
                "Access denied: you are not allowed to add notes to issue {$issueId}."
            );
        }

        $noteId = (int) bugnote_add($issueId, $text, '0:00', $private);

        return [
            'added' => true,
            'issue_id' => $issueId,
            'note' => [
                'id' => $noteId,
                'view_state' => $private ? 'private' : 'public',
            ],
        ];
    }
}
