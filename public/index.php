<?php

declare(strict_types=1);

/**
 * HTTP entry point of the Mantis MCP server (Streamable HTTP transport).
 *
 * Development usage:
 *   php -S 127.0.0.1:8088 -t public
 *
 * In production, run behind a web server (nginx/Apache + php-fpm) and expose
 * ONLY this directory (public/) as the document root.
 */

use MantisMcp\Mantis\MantisClient;
use MantisMcp\Mcp\Server;
use MantisMcp\Mcp\SessionStore;
use MantisMcp\Mcp\ToolRegistry;
use MantisMcp\Support\Bootstrap;
use MantisMcp\Support\Config;
use MantisMcp\Support\Logger;
use MantisMcp\Tools\AddNoteTool;
use MantisMcp\Tools\CreateIssueTool;
use MantisMcp\Tools\GetIssueTool;
use MantisMcp\Tools\ListIssuesTool;
use MantisMcp\Tools\ListProjectsTool;
use MantisMcp\Tools\UpdateIssueTool;

$projectRoot = dirname(__DIR__);

require $projectRoot . '/config/autoload.php';

// --- Configuration & logger first, so errors are logged from here on --------
$config = Config::load($projectRoot);

$logDir = $config->getString('LOG_DIR', 'logs');
if (!str_starts_with($logDir, '/')) {
    $logDir = $projectRoot . '/' . $logDir;
}
$logger = new Logger($logDir, $config->getString('LOG_LEVEL', 'info'));

// Harden the PHP runtime (display_errors off, html_errors off, set handlers).
Bootstrap::init($logger);

// --- Validate required configuration ----------------------------------------
$baseUrl = $config->getString('MANTIS_BASE_URL');
$apiToken = $config->getString('MANTIS_API_TOKEN');

if ($baseUrl === '' || $apiToken === '') {
    $logger->critical('Incomplete configuration', [
        'has_base_url' => $baseUrl !== '',
        'has_token' => $apiToken !== '',
    ]);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => ['code' => -32603, 'message' => 'Incomplete server configuration (see log).'],
    ], JSON_UNESCAPED_SLASHES);
    return;
}

// --- Optional impersonation (act as a specific Mantis user) -----------------
// The MantisBT REST API has no Basic Auth; instead a privileged token can act
// on behalf of another user via the "Mantis-Username" header. When enabled,
// the target user is taken from the incoming "X-Mantis-User" request header,
// falling back to MANTIS_DEFAULT_USER. The username is validated to prevent
// header injection.
$impersonateUser = null;
if ($config->getBool('MANTIS_IMPERSONATION_ENABLED', false)) {
    $headerUser = $_SERVER['HTTP_X_MANTIS_USER'] ?? '';
    $headerUser = is_string($headerUser) ? trim($headerUser) : '';
    $candidate = $headerUser !== '' ? $headerUser : $config->getString('MANTIS_DEFAULT_USER');

    if ($candidate !== '') {
        // Reject control characters (CR/LF etc.) and overly long values.
        if (preg_match('/^[^\x00-\x1f\x7f]{1,255}$/', $candidate)) {
            $impersonateUser = $candidate;
            $logger->info('Impersonation active', ['mantis_user' => $impersonateUser]);
        } else {
            $logger->warning('Rejected impersonation username (invalid characters)');
        }
    }
} elseif (!empty($_SERVER['HTTP_X_MANTIS_USER'])) {
    // Fail safe: never impersonate silently when the feature is disabled.
    $logger->warning('X-Mantis-User header ignored (impersonation disabled)');
}

// --- Mantis client -----------------------------------------------------------
$mantis = new MantisClient(
    baseUrl: $baseUrl,
    apiToken: $apiToken,
    logger: $logger,
    verifyTls: $config->getBool('MANTIS_VERIFY_TLS', true),
    caBundle: $config->getString('MANTIS_CA_BUNDLE') ?: null,
    connectTimeout: $config->getInt('MANTIS_CONNECT_TIMEOUT', 5),
    timeout: $config->getInt('MANTIS_TIMEOUT', 30),
    impersonateUser: $impersonateUser,
);

// --- Register tools ----------------------------------------------------------
$tools = new ToolRegistry();
$tools->register(new ListProjectsTool($mantis));
$tools->register(new ListIssuesTool($mantis));
$tools->register(new GetIssueTool($mantis));
$tools->register(new CreateIssueTool($mantis));
$tools->register(new UpdateIssueTool($mantis));
$tools->register(new AddNoteTool($mantis));

// --- Session store (header-based, NO PHP sessions/cookies) ------------------
$sessions = new SessionStore(
    dir: $projectRoot . '/var/sessions',
    ttl: $config->getInt('MCP_SESSION_TTL', 3600),
);

// --- Start the server --------------------------------------------------------
$server = new Server(
    tools: $tools,
    sessions: $sessions,
    logger: $logger,
    serverName: 'mantis-mcp-server',
    serverVersion: '1.0.0',
    authToken: $config->getString('MCP_AUTH_TOKEN'),
    allowedOrigins: $config->getList('MCP_ALLOWED_ORIGINS'),
);

$server->handle();
