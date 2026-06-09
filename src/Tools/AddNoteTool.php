<?php

declare(strict_types=1);

namespace MantisMcp\Tools;

/**
 * Adds a note (comment) to an issue.
 */
final class AddNoteTool extends AbstractTool
{
    public function name(): string
    {
        return 'mantis_add_note';
    }

    public function description(): string
    {
        return 'Adds a note/comment to an existing issue. '
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

    protected function run(array $args): mixed
    {
        $issueId = $this->requireInt($args, 'issue_id');
        $text = $this->requireString($args, 'text');
        $private = $this->optionalBool($args, 'private') ?? false;

        $body = [
            'text' => $text,
            'view_state' => ['name' => $private ? 'private' : 'public'],
        ];

        $response = $this->client->post("issues/{$issueId}/notes", $body);

        return [
            'added' => true,
            'issue_id' => $issueId,
            'note' => [
                'id' => $response['note']['id'] ?? null,
                'view_state' => $response['note']['view_state']['name'] ?? ($private ? 'private' : 'public'),
            ],
        ];
    }
}
