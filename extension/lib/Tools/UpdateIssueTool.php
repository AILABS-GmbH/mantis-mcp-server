<?php

declare(strict_types=1);

namespace MantisMcp\Extension\Tools;

use MantisMcp\Extension\AbstractTool;
use MantisMcp\Extension\JsonRpcException;

/**
 * Updates selected fields of an existing issue via BugData->update(), so the
 * core's history/email logic applies (same call the SOAP API uses).
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

    public function execute(array $arguments): mixed
    {
        $issueId = $this->requireInt($arguments, 'issue_id');

        if (!bug_exists($issueId)) {
            throw new JsonRpcException(JsonRpcException::INVALID_PARAMS, "Issue {$issueId} does not exist.");
        }
        if (!access_has_bug_level((int) config_get('update_bug_threshold'), $issueId)) {
            throw new JsonRpcException(
                JsonRpcException::INVALID_PARAMS,
                "Access denied: you are not allowed to update issue {$issueId}."
            );
        }

        // Load with extended fields so description/additional_information are
        // present when update(true) writes the bug text back.
        $bug = bug_get($issueId, true);

        $changed = false;
        if (($status = $this->optionalString($arguments, 'status')) !== null) {
            $bug->status = $this->enumValue('status_enum_string', $status);
            $changed = true;
        }
        if (($priority = $this->optionalString($arguments, 'priority')) !== null) {
            $bug->priority = $this->enumValue('priority_enum_string', $priority);
            $changed = true;
        }
        if (($severity = $this->optionalString($arguments, 'severity')) !== null) {
            $bug->severity = $this->enumValue('severity_enum_string', $severity);
            $changed = true;
        }
        if (($summary = $this->optionalString($arguments, 'summary')) !== null) {
            $bug->summary = $summary;
            $changed = true;
        }
        if (($description = $this->optionalString($arguments, 'description')) !== null) {
            $bug->description = $description;
            $changed = true;
        }

        if (!$changed) {
            throw new JsonRpcException(JsonRpcException::INVALID_PARAMS, 'No field to update was provided.');
        }

        // update(extended, bypass_mail): extended=true also saves the bug
        // text; mail is NOT bypassed so watchers get notified like in the UI.
        $bug->update(true, false);

        return [
            'updated' => true,
            'issue' => $this->summarizeBug(bug_get($issueId, true), false),
        ];
    }
}
