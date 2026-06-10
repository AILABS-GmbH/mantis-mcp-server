# AGENTS.md

Guidance for AI coding agents (and humans) working in this repository.

## What this project is

A PHP implementation of an **MCP (Model Context Protocol) server** for the
**MantisBT** bug tracker, in two deployment variants:

1. **Standalone server** (`src/`, `public/index.php`) — talks to the Mantis
   **2.x REST API** with an API token; header-based MCP sessions.
2. **Host extension** (`extension/`) — deployed inside a Mantis **1.2.x**
   installation at `/api/mcp/`; bootstraps Mantis' own `core.php` and calls
   the core APIs directly; **HTTP Basic Auth per user**, stateless (no
   sessions at all). All Mantis core calls were verified against the
   MantisBT 1.2.8 source. Test without a Mantis install via
   `MANTIS_MCP_CORE_PATH=tests/mock-mantis-core.php php -S ... -t extension`.

Transport in both: JSON-RPC 2.0 over HTTP POST. Tool names/schemas are
identical in both variants — keep them in sync when changing either.

## Golden rules

1. **English only.** All code, comments, identifiers, log messages, error
   strings, commit messages and docs are in English.
2. **No HTML / no stray output.** The response stream is JSON-RPC. Never `echo`
   debug output, never enable `display_errors`. Error hardening lives in
   [`src/Support/Bootstrap.php`](src/Support/Bootstrap.php) and must stay intact
   (`display_errors=0`, `html_errors=0`, custom handlers).
3. **No PHP sessions or cookies.** Session state is keyed solely by the
   `Mcp-Session-Id` header via [`src/Mcp/SessionStore.php`](src/Mcp/SessionStore.php).
   Do not introduce `session_start()` or `setcookie()`.
4. **Never leak secrets.** Tokens/passwords must not appear in logs or responses.
   The [`Logger`](src/Support/Logger.php) redacts known secret keys — keep that
   behavior when adding context fields.
5. **Fail safe.** Upstream/business failures become `JsonRpcException` and are
   surfaced as tool `isError` results, not uncaught exceptions.

## Architecture map

| Path                     | Responsibility                                         |
|--------------------------|--------------------------------------------------------|
| `public/index.php`       | Composition root: config, logger, client, tools, run.  |
| `src/Support/Bootstrap`  | Runtime hardening + error/exception/shutdown handlers.  |
| `src/Support/Config`     | Dependency-free env/.env loader.                        |
| `src/Support/Logger`     | JSON Lines file logger with secret redaction.           |
| `src/Mcp/Server`         | JSON-RPC dispatch, MCP methods, auth/origin checks.     |
| `src/Mcp/SessionStore`   | Header-based session lifecycle (file-backed).           |
| `src/Mcp/ToolRegistry`   | Tool registration + `tools/list` description.           |
| `src/Mantis/MantisClient`| curl-based REST client with timeouts + TLS verify.      |
| `src/Tools/*`            | One class per MCP tool, all extend `AbstractTool`.      |

## Conventions

- PHP ≥ 8.1, `declare(strict_types=1)` in every file, PSR-12 + PSR-4 (`MantisMcp\`).
- Classes are `final` unless designed for extension (`AbstractTool`).
- Validate tool input with the `AbstractTool` helpers; return compact payloads.

## How to verify changes

```bash
composer lint          # php -l over all PHP files — must be clean
composer serve         # start the dev server on 127.0.0.1:8088
```

Then run the curl smoke test from the [README](README.md): `initialize` must
return an `Mcp-Session-Id` header (and **no** `Set-Cookie`), `tools/list` must
list the tools, and a session-less `ping` must be rejected.

## Adding a tool (checklist)

- [ ] New class in `src/Tools/` extending `AbstractTool`.
- [ ] Implement `name()`, `description()`, `inputSchema()`, `run()`.
- [ ] Register it in `public/index.php`.
- [ ] Document it in the README tool table.
- [ ] `composer lint` passes and the tool is listed via `tools/list`.

## Out of scope / do not

- Do not add heavy frameworks or unnecessary dependencies — the project is
  intentionally lean and runs without Composer if needed.
- Do not commit `.env`, real tokens, log files or session files.
