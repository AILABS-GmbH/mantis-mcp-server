<?php

/**
 * Test harness: a mock of the MantisBT 1.2.8 core API surface used by the
 * MCP extension. Lets us exercise the full HTTP flow (Basic Auth, JSON-RPC,
 * tools, error paths) without a real Mantis installation or database.
 *
 * Used via: MANTIS_MCP_CORE_PATH=tests/mock-mantis-core.php php -S ... extension/
 *
 * It mimics 1.2.8 behavior closely, including:
 *  - signalling business errors via trigger_error(<code>, E_USER_ERROR),
 *  - emitting PHP warnings on load (like the real 1.2.x core under PHP 8),
 *  - registering an HTML error handler (like core/error_api.php does),
 *  - starting a PHP session (like core/session_api.php does for web SAPI).
 */

// --- Simulate 1.2.x core noise/behavior under PHP 8 -------------------------
@trigger_error('mock core: simulated deprecation noise', E_USER_DEPRECATED);
echo ''; // simulate potential stray output being buffered

// Mantis registers an HTML-oriented error handler at load time.
set_error_handler(static function ($severity, $message) {
    echo "<br /><b>MANTIS HTML ERROR HANDLER</b>: {$message}<br />";
    return true;
});

// Mantis 1.2.x starts a PHP session for web requests.
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// --- Constants (from core/constant_inc.php) ----------------------------------
define('ALL_PROJECTS', 0);
define('VS_PUBLIC', 10);
define('VS_PRIVATE', 50);
define('ERROR', E_USER_ERROR);

// --- Mock state ---------------------------------------------------------------
$GLOBALS['mock_current_user'] = 0;
$GLOBALS['mock_users'] = [
    1 => ['username' => 'jdoe', 'password' => 'secret', 'enabled' => true],
    2 => ['username' => 'viewer', 'password' => 'viewpw', 'enabled' => true],
];
$GLOBALS['mock_notes'] = [
    101 => [
        (object) ['id' => 9001, 'reporter_id' => 1, 'date_submitted' => 1700000000, 'view_state' => VS_PUBLIC, 'note' => 'First note'],
    ],
];
$GLOBALS['mock_next_bug_id'] = 200;
$GLOBALS['mock_next_note_id'] = 9100;

/** Builds a BugData-like object. */
function mock_bug(int $id, array $overrides = []): object
{
    return (object) array_merge([
        'id' => $id,
        'project_id' => 1,
        'summary' => "Issue {$id}",
        'category_id' => 5,
        'status' => 10,
        'resolution' => 10,
        'priority' => 30,
        'severity' => 50,
        'reporter_id' => 1,
        'handler_id' => 0,
        'date_submitted' => 1700000000,
        'last_updated' => 1700001000,
        'description' => "Description of issue {$id}",
        'additional_information' => '',
    ], $overrides);
}

$GLOBALS['mock_bugs'] = [101 => mock_bug(101), 102 => mock_bug(102, ['status' => 50, 'handler_id' => 1])];

// --- config / enums -------------------------------------------------------------
function config_get(string $option)
{
    $configs = [
        'status_enum_string' => '10:new,20:feedback,30:acknowledged,40:confirmed,50:assigned,80:resolved,90:closed',
        'resolution_enum_string' => '10:open,20:fixed,30:reopened',
        'priority_enum_string' => '10:none,20:low,30:normal,40:high,50:urgent,60:immediate',
        'severity_enum_string' => '10:feature,20:trivial,30:text,40:tweak,50:minor,60:major,70:crash,80:block',
        'project_status_enum_string' => '10:development,30:release,50:stable,70:obsolete',
        'view_bug_threshold' => 10,
        'report_bug_threshold' => 25,
        'update_bug_threshold' => 40,
        'add_bugnote_threshold' => 25,
    ];
    return $configs[$option] ?? null;
}

// Real MantisEnum from 1.2.8 semantics (simplified, API-compatible).
class MantisEnum
{
    public static function getAssocArrayIndexedByValues(string $enumString): array
    {
        $result = [];
        foreach (explode(',', $enumString) as $pair) {
            [$value, $label] = explode(':', trim($pair), 2);
            $result[(int) $value] = $label;
        }
        return $result;
    }

    public static function getLabel(string $enumString, $value): string
    {
        $assoc = self::getAssocArrayIndexedByValues($enumString);
        return $assoc[(int) $value] ?? ('@' . $value . '@');
    }

    public static function getValue(string $enumString, string $label)
    {
        $assoc = array_flip(self::getAssocArrayIndexedByValues($enumString));
        return $assoc[$label] ?? false;
    }

    public static function getValues(string $enumString): array
    {
        return array_keys(self::getAssocArrayIndexedByValues($enumString));
    }
}

// --- auth ------------------------------------------------------------------------
function auth_attempt_script_login(string $username, ?string $password = null): bool
{
    foreach ($GLOBALS['mock_users'] as $id => $u) {
        if ($u['username'] === $username && $u['enabled'] && ($password === null || $u['password'] === $password)) {
            $GLOBALS['mock_current_user'] = $id;
            return true;
        }
    }
    return false;
}

function auth_get_current_user_id(): int
{
    return $GLOBALS['mock_current_user'];
}

// --- access (viewer=user 2 has low access: may view, not report/update) -----------
function access_has_project_level(int $threshold, ?int $projectId = null, ?int $userId = null): bool
{
    $level = $GLOBALS['mock_current_user'] === 1 ? 90 : 10;
    return $level >= $threshold;
}

function access_has_bug_level(int $threshold, int $bugId, ?int $userId = null): bool
{
    $level = $GLOBALS['mock_current_user'] === 1 ? 90 : 10;
    return $level >= $threshold;
}

// --- projects ----------------------------------------------------------------------
function current_user_get_accessible_projects(): array
{
    return [1];
}

function project_get_row(int $id): array
{
    return ['id' => $id, 'name' => 'Demo Project', 'status' => 50, 'enabled' => 1, 'description' => 'A mock project'];
}

function project_get_name(int $id): string
{
    return 'Demo Project';
}

// --- categories / users ---------------------------------------------------------------
function category_get_id_by_name(string $name, int $projectId, bool $triggerErrors = true)
{
    if (strtolower($name) === 'general') {
        return 5;
    }
    if ($triggerErrors) {
        trigger_error('1302', ERROR); // like the real core: numeric error code
    }
    return false;
}

function category_get_name(int $id): string
{
    return $id === 5 ? 'General' : "Category {$id}";
}

function user_get_name(int $id): string
{
    return $GLOBALS['mock_users'][$id]['username'] ?? "user{$id}";
}

function error_string(int $code): string
{
    $strings = [1302 => 'Category not found.', 1100 => 'Issue not found.'];
    return $strings[$code] ?? "Mantis error {$code}";
}

// --- bugs --------------------------------------------------------------------------------
function bug_exists(int $id): bool
{
    return isset($GLOBALS['mock_bugs'][$id]);
}

function bug_get(int $id, bool $extended = false): object
{
    if (!bug_exists($id)) {
        trigger_error('1100', ERROR);
    }
    return $GLOBALS['mock_bugs'][$id];
}

// BugData replacement compatible with `new \BugData()` + ->create()/->update().
class BugData
{
    public $id = 0;
    public $project_id = 0;
    public $summary = '';
    public $category_id = 0;
    public $status = 10;
    public $resolution = 10;
    public $priority = 30;
    public $severity = 50;
    public $reporter_id = 0;
    public $handler_id = 0;
    public $date_submitted = 0;
    public $last_updated = 0;
    public $description = '';
    public $additional_information = '';

    public function create(): int
    {
        $this->id = $GLOBALS['mock_next_bug_id']++;
        $this->date_submitted = time();
        $this->last_updated = time();
        $GLOBALS['mock_bugs'][$this->id] = (object) get_object_vars($this);
        return $this->id;
    }

    public function update(bool $extended = false, bool $bypassMail = false): bool
    {
        $this->last_updated = time();
        $GLOBALS['mock_bugs'][$this->id] = (object) get_object_vars($this);
        return true;
    }
}

// bug_get returns plain stdClass in this mock; give them update() via wrapper:
// the extension calls ->update() on the object from bug_get(), so wrap stdClass.
// Simplest: store BugData instances in mock_bugs from the start.
foreach ($GLOBALS['mock_bugs'] as $bid => $obj) {
    $bug = new BugData();
    foreach (get_object_vars($obj) as $k => $v) {
        $bug->$k = $v;
    }
    $GLOBALS['mock_bugs'][$bid] = $bug;
}

function email_new_bug(int $id): void
{
}

// --- notes ---------------------------------------------------------------------------------
function bugnote_get_all_visible_bugnotes(int $bugId, string $order, int $limit, ?int $userId = null): array
{
    return $GLOBALS['mock_notes'][$bugId] ?? [];
}

function bugnote_add(int $bugId, string $text, string $timeTracking = '0:00', bool $private = false): int
{
    $id = $GLOBALS['mock_next_note_id']++;
    $GLOBALS['mock_notes'][$bugId][] = (object) [
        'id' => $id,
        'reporter_id' => auth_get_current_user_id(),
        'date_submitted' => time(),
        'view_state' => $private ? VS_PRIVATE : VS_PUBLIC,
        'note' => $text,
    ];
    return $id;
}

// --- filter ----------------------------------------------------------------------------------
function filter_get_bug_rows(&$page, &$perPage, &$pageCount, &$bugCount, $customFilter = null, $projectId = null, $userId = null, $showSticky = null)
{
    $all = array_values($GLOBALS['mock_bugs']);
    if ($projectId !== null && $projectId !== ALL_PROJECTS) {
        $all = array_values(array_filter($all, static fn ($b) => (int) $b->project_id === (int) $projectId));
    }
    $bugCount = count($all);
    $pageCount = max(1, (int) ceil($bugCount / max(1, $perPage)));
    return array_slice($all, ($page - 1) * $perPage, $perPage);
}
