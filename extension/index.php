<?php

declare(strict_types=1);

/**
 * Mantis MCP Extension — entry point.
 *
 * Deploy this folder as <mantis-root>/api/mcp/ inside a MantisBT 1.2.x
 * installation. The endpoint then lives at:
 *
 *     https://your-mantis/api/mcp/
 *
 * Architecture:
 *  - Bootstraps Mantis' own core.php (like api/soap/mc_core.php does), which
 *    provides the database connection from config_inc.php and all core APIs.
 *  - Authenticates EVERY request via HTTP Basic Auth against Mantis' user
 *    database (auth_attempt_script_login) — user-based, no API tokens, no
 *    cookies, no server-side sessions.
 *  - Speaks MCP (JSON-RPC 2.0 over POST), stateless.
 *
 * Designed for MantisBT 1.2.8 running under PHP 8 (where the bundled nusoap
 * SOAP API is broken); all core calls verified against the 1.2.8 source.
 */

use MantisMcp\Extension\BasicAuth;
use MantisMcp\Extension\Dispatcher;
use MantisMcp\Extension\Hardening;
use MantisMcp\Extension\Logger;
use MantisMcp\Extension\ToolRegistry;
use MantisMcp\Extension\Tools\AddNoteTool;
use MantisMcp\Extension\Tools\CreateIssueTool;
use MantisMcp\Extension\Tools\GetIssueTool;
use MantisMcp\Extension\Tools\ListIssuesTool;
use MantisMcp\Extension\Tools\ListProjectsTool;
use MantisMcp\Extension\Tools\UpdateIssueTool;

define('MANTIS_MCP', true);

$t_mcp_dir = __DIR__;

// --- Load our classes (no Composer on the Mantis host) -----------------------
require $t_mcp_dir . '/lib/Logger.php';
require $t_mcp_dir . '/lib/MantisCoreError.php';
require $t_mcp_dir . '/lib/Hardening.php';
require $t_mcp_dir . '/lib/JsonRpcException.php';
require $t_mcp_dir . '/lib/BasicAuth.php';
require $t_mcp_dir . '/lib/ToolInterface.php';
require $t_mcp_dir . '/lib/ToolRegistry.php';
require $t_mcp_dir . '/lib/AbstractTool.php';
require $t_mcp_dir . '/lib/Dispatcher.php';
require $t_mcp_dir . '/lib/Tools/ListProjectsTool.php';
require $t_mcp_dir . '/lib/Tools/ListIssuesTool.php';
require $t_mcp_dir . '/lib/Tools/GetIssueTool.php';
require $t_mcp_dir . '/lib/Tools/CreateIssueTool.php';
require $t_mcp_dir . '/lib/Tools/UpdateIssueTool.php';
require $t_mcp_dir . '/lib/Tools/AddNoteTool.php';

// --- Optional local configuration --------------------------------------------
$t_config = [
    'log_dir' => $t_mcp_dir . '/logs',
    'log_level' => 'info',
];
if (is_file($t_mcp_dir . '/config.php')) {
    $t_user_config = require $t_mcp_dir . '/config.php';
    if (is_array($t_user_config)) {
        $t_config = array_merge($t_config, $t_user_config);
    }
}

$t_logger = new Logger((string) $t_config['log_dir'], (string) $t_config['log_level']);

// --- Phase 1: harden BEFORE loading the (PHP-8-noisy) Mantis core ------------
Hardening::preCore();

// --- Load the Mantis core (provides DB connection + all core APIs) -----------
// This folder lives at <mantis>/api/mcp, so the core is two levels up.
// MANTIS_MCP_CORE_PATH can override the location (used by the test harness).
$g_bypass_headers = true;
$t_core = getenv('MANTIS_MCP_CORE_PATH') ?: dirname($t_mcp_dir, 2) . DIRECTORY_SEPARATOR . 'core.php';
if (!is_file($t_core)) {
    $t_logger->critical('Mantis core.php not found', ['path' => $t_core]);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => ['code' => -32603, 'message' => 'Mantis core not found — is the extension installed in <mantis>/api/mcp/?'],
    ], JSON_UNESCAPED_SLASHES);
    return;
}
require_once $t_core;

// --- Phase 2: take over error handling from the Mantis core ------------------
Hardening::postCore($t_logger);

// --- Authenticate the request (user-based, via Mantis itself) ----------------
$t_credentials = BasicAuth::credentials();
if ($t_credentials === null) {
    BasicAuth::deny();
    return;
}
$t_user_id = BasicAuth::authenticate($t_credentials[0], $t_credentials[1], $t_logger);
if ($t_user_id === null) {
    BasicAuth::deny();
    return;
}

// --- Register tools and dispatch ----------------------------------------------
$t_tools = new ToolRegistry();
$t_tools->register(new ListProjectsTool());
$t_tools->register(new ListIssuesTool());
$t_tools->register(new GetIssueTool());
$t_tools->register(new CreateIssueTool());
$t_tools->register(new UpdateIssueTool());
$t_tools->register(new AddNoteTool());

$t_dispatcher = new Dispatcher(
    tools: $t_tools,
    logger: $t_logger,
    serverName: 'mantis-mcp-extension',
    serverVersion: '1.0.0',
);

$t_dispatcher->handle();
