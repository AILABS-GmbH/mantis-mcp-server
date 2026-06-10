# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-06-10

### Added
- **Host extension variant** (`extension/`) for MantisBT **1.2.x**, deployed
  inside the Mantis installation at `/api/mcp/`. Bootstraps Mantis' own
  `core.php` (database access via `config_inc.php`, core business logic) and
  authenticates every request **per user via HTTP Basic Auth** against
  Mantis' account database (`auth_attempt_script_login`). Stateless MCP — no
  cookies, no sessions, no API tokens. PHP-8-safe error handling around the
  PHP-5-era core (replaces Mantis' HTML error handler, converts
  `trigger_error()` business failures into clean tool errors). Same tool
  names/schemas as the standalone server.
- Mock of the Mantis 1.2.8 core API surface (`tests/mock-mantis-core.php`)
  for testing the extension without a Mantis installation.

## [1.0.0] - 2026-06-10

### Added
- Initial release of the Mantis MCP server.
- MCP Streamable HTTP transport (JSON-RPC 2.0 over HTTP).
- Session management via the `Mcp-Session-Id` header (no PHP sessions/cookies).
- Hardened runtime: `display_errors`/`html_errors` disabled, custom
  error/exception/shutdown handlers.
- JSON Lines file logger with secret redaction.
- curl-based MantisBT REST client with TLS verification and timeouts.
- Tools: `mantis_list_projects`, `mantis_list_issues`, `mantis_get_issue`,
  `mantis_create_issue`, `mantis_update_issue`, `mantis_add_note`.
- Optional bearer-token auth and Origin allow-list for the MCP endpoint.
- PSR-4 fallback autoloader so the server runs without Composer.

[1.1.0]: https://github.com/AILABS-GmbH/mantis-mcp-server/releases/tag/v1.1.0
[1.0.0]: https://github.com/AILABS-GmbH/mantis-mcp-server/releases/tag/v1.0.0
