<?php

declare(strict_types=1);

namespace MantisMcp\Extension\Tools;

use MantisMcp\Extension\AbstractTool;
use MantisMcp\Extension\JsonRpcException;

/**
 * Creates a new issue via the Mantis core (BugData->create()), so all core
 * validation, history and email notification logic applies.
 */
final class CreateIssueTool extends AbstractTool
{
    public function name(): string
    {
        return 'mantis_create_issue';
    }

    public function description(): string
    {
        return 'Creates a new issue (ticket) in a Mantis project as the authenticated user. '
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
                    'description' => 'Category name (must exist in the project).',
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

    public function execute(array $arguments): mixed
    {
        $projectId = $this->requireInt($arguments, 'project_id');
        $summary = $this->requireString($arguments, 'summary');
        $description = $this->requireString($arguments, 'description');
        $category = $this->requireString($arguments, 'category');

        if (!access_has_project_level((int) config_get('report_bug_threshold'), $projectId)) {
            throw new JsonRpcException(
                JsonRpcException::INVALID_PARAMS,
                "Access denied: you are not allowed to report issues in project {$projectId}."
            );
        }

        // Resolves the category name; triggers a Mantis error (-> tool error)
        // if the category does not exist in this project.
        $categoryId = (int) category_get_id_by_name($category, $projectId);

        $bug = new \BugData();
        $bug->project_id = $projectId;
        $bug->summary = $summary;
        $bug->description = $description;
        $bug->category_id = $categoryId;
        $bug->reporter_id = auth_get_current_user_id();
        $bug->handler_id = 0;

        if (($priority = $this->optionalString($arguments, 'priority')) !== null) {
            $bug->priority = $this->enumValue('priority_enum_string', $priority);
        }
        if (($severity = $this->optionalString($arguments, 'severity')) !== null) {
            $bug->severity = $this->enumValue('severity_enum_string', $severity);
        }
        if (($info = $this->optionalString($arguments, 'additional_information')) !== null) {
            $bug->additional_information = $info;
        }

        $issueId = (int) $bug->create();

        // Same post-create step the SOAP API performs.
        if (function_exists('email_new_bug')) {
            email_new_bug($issueId);
        }

        return [
            'created' => true,
            'issue' => $this->summarizeBug(bug_get($issueId, true), false),
        ];
    }
}
