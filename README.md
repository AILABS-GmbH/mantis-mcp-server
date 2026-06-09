# Mantis MCP Server

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.1-777BB4.svg)](https://www.php.net/)
[![MCP](https://img.shields.io/badge/MCP-Streamable%20HTTP-2ea44f.svg)](https://modelcontextprotocol.io/)

A lightweight, secure and well-structured **Model Context Protocol (MCP) server**
written in **PHP** for the **MantisBT** bug tracker. It exposes the MantisBT REST
API as MCP tools so that an LLM can read, create, update and comment on issues.

---

## Features

- **Transport:** MCP *Streamable HTTP* (JSON-RPC 2.0 over HTTP).
- **Sessions via the `Mcp-Session-Id` header only** — **no PHP sessions, no
  cookies** (`session.use_cookies=0`).
- **Hardened runtime:** `display_errors=0`, `html_errors=0`,
  `display_startup_errors=0`, plus custom error/exception/shutdown handlers — no
  HTML or stack trace ever leaks into the JSON-RPC stream.
- **Custom file logging** in JSON Lines format with **secret redaction**
  (tokens/passwords are masked in the logs).
- **Security:** optional bearer token for the MCP endpoint (constant-time
  comparison), optional Origin allow-list (DNS-rebinding protection), TLS
  verification against Mantis, strict session-ID validation (no path traversal),
  per-tool input validation.
- **Stable:** upstream/business errors are returned as tool errors (`isError`)
  instead of crashing the server; clear connection and request timeouts.
- **Dependency-free:** runs with or without Composer (PSR-4 fallback autoloader).

## Project layout

```
.
├── public/index.php          # HTTP entry point (the only web-exposed file)
├── config/autoload.php       # Composer or fallback autoloader
├── src/
│   ├── Support/              # Bootstrap (hardening), Config, Logger
│   ├── Mcp/                  # Server, SessionStore, ToolRegistry, JSON-RPC
│   ├── Mantis/               # REST client + exception
│   └── Tools/                # The individual MCP tools
├── var/sessions/             # File-based MCP sessions (no cookies!)
├── logs/                     # JSON Lines log files
├── .env.example              # Configuration template
└── composer.json
```

## Requirements

- **PHP ≥ 8.1** with the `curl`, `json` and `mbstring` extensions.
- A reachable **MantisBT** instance with the REST API enabled and a personal
  **API token** (Mantis → *My Account → API Tokens*).

## Installation

```bash
git clone https://github.com/AILABS-GmbH/mantis-mcp-server.git
cd mantis-mcp-server

# Optional but recommended — optimized autoloader:
composer install   # creates vendor/ (otherwise the fallback autoloader is used)

# Create your configuration:
cp .env.example .env
# Edit .env and set MANTIS_BASE_URL and MANTIS_API_TOKEN.
```

## Configuration

All configuration is read from environment variables or the `.env` file (real
environment variables take precedence). See [`.env.example`](.env.example) for
the full list.

| Variable                | Required | Default | Description                                            |
|-------------------------|:--------:|---------|--------------------------------------------------------|
| `MANTIS_BASE_URL`       |   yes    | —       | Base URL of Mantis (without trailing slash).           |
| `MANTIS_API_TOKEN`      |   yes    | —       | Personal Mantis API token.                             |
| `MANTIS_VERIFY_TLS`     |    no    | `true`  | Verify the Mantis TLS certificate.                     |
| `MANTIS_CA_BUNDLE`      |    no    | —       | Path to a CA bundle for internal certificates.         |
| `MANTIS_CONNECT_TIMEOUT`|    no    | `5`     | Connection timeout in seconds.                         |
| `MANTIS_TIMEOUT`        |    no    | `30`    | Total request timeout in seconds.                      |
| `MANTIS_IMPERSONATION_ENABLED` | no | `false` | Allow acting on behalf of another Mantis user.    |
| `MANTIS_DEFAULT_USER`   |    no    | —       | Fallback user when no `X-Mantis-User` header is sent.  |
| `MCP_AUTH_TOKEN`        |    no    | —       | Bearer token required on the MCP endpoint.             |
| `MCP_ALLOWED_ORIGINS`   |    no    | —       | Comma-separated Origin allow-list.                     |
| `MCP_SESSION_TTL`       |    no    | `3600`  | Session lifetime (inactivity) in seconds.              |
| `LOG_LEVEL`             |    no    | `info`  | `debug`/`info`/`notice`/`warning`/`error`/`critical`.  |
| `LOG_DIR`               |    no    | `logs`  | Directory for log files.                               |

## Running

Development (built-in PHP server):

```bash
composer serve
# equivalent to:
php -d display_errors=0 -S 127.0.0.1:8088 -t public
```

Production: run behind **nginx/Apache + php-fpm** and expose only the `public/`
directory as the document root. Additionally secure it with TLS and
`MCP_AUTH_TOKEN`.

## Available tools

| Tool                    | Purpose                                             |
|-------------------------|-----------------------------------------------------|
| `mantis_list_projects`  | List visible projects (to discover project ids).    |
| `mantis_list_issues`    | List issues (optionally per project), paginated.    |
| `mantis_get_issue`      | Fetch a single issue including its notes.           |
| `mantis_create_issue`   | Create a new issue.                                 |
| `mantis_update_issue`   | Update issue fields (status, priority, …).          |
| `mantis_add_note`       | Add a note/comment (optionally private).            |

## Quick test with curl

```bash
BASE=http://127.0.0.1:8088/

# 1) Initialize — returns the Mcp-Session-Id response header:
curl -i -X POST "$BASE" \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize",
       "params":{"protocolVersion":"2025-06-18","capabilities":{},
                 "clientInfo":{"name":"curl","version":"1.0"}}}'

# 2) Take the session id from the response header:
SID="<value-of-Mcp-Session-Id>"

# 3) List tools:
curl -s -X POST "$BASE" \
  -H 'Content-Type: application/json' \
  -H "Mcp-Session-Id: $SID" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'

# 4) List projects:
curl -s -X POST "$BASE" \
  -H 'Content-Type: application/json' \
  -H "Mcp-Session-Id: $SID" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call",
       "params":{"name":"mantis_list_projects","arguments":{}}}'

# 5) Terminate the session:
curl -i -X DELETE "$BASE" -H "Mcp-Session-Id: $SID"
```

## Connecting an MCP client

For clients with HTTP transport (e.g. `claude mcp add --transport http`):

```bash
claude mcp add --transport http mantis http://127.0.0.1:8088/ \
  --header "Authorization: Bearer YOUR_MCP_AUTH_TOKEN"
```

The `Authorization` header is only required when `MCP_AUTH_TOKEN` is set.

## Acting as a specific Mantis user (impersonation)

The MantisBT REST API does **not** support HTTP Basic Auth — authentication is
always via a personal API token, and that token *is* the acting user. To
attribute actions to a different Mantis user, MantisBT provides **impersonation**:
a token whose owner has sufficient access (`impersonate_user_threshold`,
typically an administrator) can act as another user via the `Mantis-Username`
header. The impersonated user's password is **not** required.

This server wires that up as follows:

1. Set `MANTIS_IMPERSONATION_ENABLED=true` and use an administrator API token in
   `MANTIS_API_TOKEN`.
2. Have the MCP client send the target user in the `X-Mantis-User` request
   header. The server validates it (no control characters) and forwards it as
   `Mantis-Username` to Mantis.
3. Optionally set `MANTIS_DEFAULT_USER` as a fallback when no header is sent.

```bash
curl -s -X POST http://127.0.0.1:8088/ \
  -H 'Content-Type: application/json' \
  -H 'Mcp-Session-Id: <session>' \
  -H 'X-Mantis-User: jdoe' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call",
       "params":{"name":"mantis_create_issue","arguments":{ ... }}}'
```

When impersonation is **disabled**, any incoming `X-Mantis-User` header is
ignored (and logged) — the server never impersonates silently.

## Security notes

- In production, always keep `MANTIS_VERIFY_TLS=true` and serve the endpoint
  over TLS.
- Set `MCP_AUTH_TOKEN` unless the endpoint is already protected by a VPN/proxy —
  otherwise anyone with network access can perform the Mantis actions.
- Logs live in `logs/` with restricted permissions (0750/0640). Tokens are
  masked automatically, but still restrict access to the directory.
- `var/sessions/` and `logs/` must **not** be reachable through the web server —
  only `public/` should be published.
- Impersonation uses a **privileged** administrator token. When enabled, anyone
  who can reach the endpoint and pass `X-Mantis-User` can act as any user —
  always combine it with `MCP_AUTH_TOKEN` and/or network-level restrictions.

## Contributing

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) first.
If you use an AI coding assistant, see [AGENTS.md](AGENTS.md) for project
conventions.

## License

Released under the [MIT License](LICENSE).
