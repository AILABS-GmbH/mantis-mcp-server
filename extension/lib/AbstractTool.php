<?php

declare(strict_types=1);

namespace MantisMcp\Extension;

/**
 * Common base for tools that talk directly to the MantisBT 1.2.x core APIs
 * (bug_api, bugnote_api, project_api, ...). All Mantis functions used here
 * were verified against the MantisBT 1.2.8 source.
 *
 * Mantis signals failures via trigger_error(); the Hardening error handler
 * converts those into MantisCoreError exceptions, which the dispatcher turns
 * into tool errors (isError) — so a missing issue id etc. never kills the
 * request.
 */
abstract class AbstractTool implements ToolInterface
{
    // --- Input validation helpers --------------------------------------------

    /** @param array<string,mixed> $args */
    protected function requireInt(array $args, string $key): int
    {
        if (!isset($args[$key]) || !is_numeric($args[$key])) {
            throw new JsonRpcException(JsonRpcException::INVALID_PARAMS, "Required field '{$key}' is missing or not a number.");
        }
        return (int) $args[$key];
    }

    /** @param array<string,mixed> $args */
    protected function optionalInt(array $args, string $key): ?int
    {
        if (!isset($args[$key]) || $args[$key] === '' || !is_numeric($args[$key])) {
            return null;
        }
        return (int) $args[$key];
    }

    /** @param array<string,mixed> $args */
    protected function requireString(array $args, string $key): string
    {
        $value = $args[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new JsonRpcException(JsonRpcException::INVALID_PARAMS, "Required field '{$key}' is missing or empty.");
        }
        return $value;
    }

    /** @param array<string,mixed> $args */
    protected function optionalString(array $args, string $key): ?string
    {
        $value = $args[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        return $value;
    }

    /** @param array<string,mixed> $args */
    protected function optionalBool(array $args, string $key): ?bool
    {
        if (!array_key_exists($key, $args)) {
            return null;
        }
        return (bool) $args[$key];
    }

    // --- Mantis enum helpers --------------------------------------------------

    /**
     * Translates an enum label (e.g. "assigned") into its numeric Mantis code
     * using the configured enum string (e.g. config "status_enum_string").
     */
    protected function enumValue(string $configOption, string $label): int
    {
        $enumString = (string) config_get($configOption);
        $value = \MantisEnum::getValue($enumString, strtolower(trim($label)));
        if ($value === false) {
            $valid = implode(', ', \MantisEnum::getValues($enumString) !== []
                ? array_map(
                    static fn ($v) => \MantisEnum::getLabel($enumString, $v),
                    \MantisEnum::getValues($enumString)
                )
                : []);
            throw new JsonRpcException(
                JsonRpcException::INVALID_PARAMS,
                "Unknown value '{$label}' for {$configOption}. Valid values: {$valid}"
            );
        }
        return (int) $value;
    }

    /** Translates a numeric Mantis enum code into its label. */
    protected function enumLabel(string $configOption, int $value): string
    {
        return (string) \MantisEnum::getLabel((string) config_get($configOption), $value);
    }

    // --- Formatting -----------------------------------------------------------

    /**
     * Serializes a BugData object (from bug_get / filter_get_bug_rows) into a
     * compact, LLM-friendly array.
     *
     * @param object $bug BugData instance from the Mantis core.
     * @return array<string,mixed>
     */
    protected function summarizeBug(object $bug, bool $withDescription = true): array
    {
        $summary = [
            'id' => (int) $bug->id,
            'summary' => (string) $bug->summary,
            'project' => function_exists('project_get_name') ? project_get_name((int) $bug->project_id) : (int) $bug->project_id,
            'category' => $this->categoryName($bug),
            'status' => $this->enumLabel('status_enum_string', (int) $bug->status),
            'resolution' => $this->enumLabel('resolution_enum_string', (int) $bug->resolution),
            'priority' => $this->enumLabel('priority_enum_string', (int) $bug->priority),
            'severity' => $this->enumLabel('severity_enum_string', (int) $bug->severity),
            'reporter' => $this->userName((int) $bug->reporter_id),
            'handler' => $this->userName((int) $bug->handler_id),
            'created_at' => date('c', (int) $bug->date_submitted),
            'updated_at' => date('c', (int) $bug->last_updated),
        ];

        if ($withDescription) {
            // Extended fields (description etc.) are lazy-loaded by BugData.
            $summary['description'] = (string) $bug->description;
            $summary['additional_information'] = (string) $bug->additional_information;
        }

        return $summary;
    }

    /** Resolves the category name of a bug (1.2.x stores a category_id). */
    private function categoryName(object $bug): ?string
    {
        $categoryId = (int) ($bug->category_id ?? 0);
        if ($categoryId <= 0 || !function_exists('category_get_name')) {
            return null;
        }
        try {
            return (string) category_get_name($categoryId);
        } catch (MantisCoreError) {
            return null;
        }
    }

    /** Resolves a user id to a username; null for 0/unknown. */
    protected function userName(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            return (string) user_get_name($userId);
        } catch (MantisCoreError) {
            return null;
        }
    }
}
