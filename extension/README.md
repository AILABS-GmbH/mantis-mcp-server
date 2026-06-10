# Mantis MCP Extension (host-deployed, Mantis 1.2.x)

An MCP endpoint that runs **inside** an existing MantisBT installation —
designed for **MantisBT 1.2.8 under PHP 8**, where the bundled SOAP API
(nusoap) is broken. It bootstraps Mantis' own `core.php`, so it uses the
database connection from `config_inc.php` and the core business logic
(`bug_api`, `bugnote_api`, filters, access control) directly. No REST API,
no SOAP, no separate service required.

```
https://your-mantis.example.com/api/mcp/
```

## Key properties

- **User-based authentication via HTTP Basic Auth.** Every request carries a
  Mantis username + password, verified per request through Mantis' own
  `auth_attempt_script_login()`. All actions run as that user with their
  normal Mantis permissions; reporter/note author are attributed correctly.
  No API tokens, no cookies, no server-side sessions (stateless MCP).
- **PHP-8-safe around a PHP-5-era core.** Error output is fully disabled and
  buffered before `core.php` loads (the 1.2.x core is noisy under PHP 8), and
  Mantis' HTML error handler is replaced by a PHP-8-correct one after loading
  — the same takeover the SOAP API attempted, minus its `ArgumentCountError`.
  Mantis `trigger_error()` business failures become clean MCP tool errors.
- **No cookies, guaranteed.** Mantis 1.2.x starts a PHP session while loading
  `core.php`; the extension sets `session.use_cookies=0` beforehand so no
  `Set-Cookie` header ever leaves the server.
- **Core-level access checks.** Listing uses Mantis' filter engine (only
  issues the user may see); create/update/note check the configured
  thresholds (`report_bug_threshold`, `update_bug_threshold`,
  `add_bugnote_threshold`).
- **JSON Lines file logging** with secret redaction (passwords never appear
  in logs).

## Installation

1. Copy this `extension/` folder to your Mantis installation as:

   ```
   <mantis-root>/api/mcp/
   ```

   (so that `<mantis-root>/api/mcp/index.php` exists; the extension locates
   `core.php` two directories up.)

2. Make `api/mcp/logs/` writable by the web server user:

   ```bash
   chmod 750 api/mcp/logs
   ```

3. Optional: copy `config.example.php` to `config.php` to change the log
   directory or log level.

4. Verify the endpoint:

   ```bash
   curl -s -u your_user:your_password -X POST https://your-mantis/api/mcp/ \
     -H 'Content-Type: application/json' \
     -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
   ```

The shipped `.htaccess` routes requests to `index.php`, forwards the
`Authorization` header under CGI/FastCGI (common on managed hosting), and
denies direct web access to `lib/` and `logs/`.

## Connecting an MCP client

```bash
claude mcp add --transport http mantis https://your-mantis/api/mcp/ \
  --header "Authorization: Basic $(printf 'your_user:your_password' | base64)"
```

The server is stateless: it intentionally returns no `Mcp-Session-Id` header,
and clients authenticate every request via Basic Auth.

## Tools

Identical names and schemas to the standalone server, so clients can switch
between the two deployments transparently:

| Tool                    | Backed by (Mantis 1.2.8 core)                        |
|-------------------------|------------------------------------------------------|
| `mantis_list_projects`  | `current_user_get_accessible_projects()`             |
| `mantis_list_issues`    | `filter_get_bug_rows()` (permission-aware, paginated)|
| `mantis_get_issue`      | `bug_get()` + `bugnote_get_all_visible_bugnotes()`   |
| `mantis_create_issue`   | `BugData->create()` + `email_new_bug()`              |
| `mantis_update_issue`   | `BugData->update()` (history + notification mails)   |
| `mantis_add_note`       | `bugnote_add()`                                      |

## Security notes

- **TLS is mandatory in practice** — Basic Auth sends credentials with every
  request. Never expose this endpoint over plain HTTP.
- The endpoint enforces Mantis' own per-user permissions, but anyone with a
  valid Mantis account can use it. Consider additional network-level
  restrictions if your Mantis instance is internet-facing.
- `logs/` is denied via `.htaccess`; keep it that way and rotate/clean logs
  as needed.

## Compatibility

- Target: **MantisBT 1.2.8** (all core calls verified against the 1.2.8
  source); should work on other 1.2.x releases with the same API surface.
- PHP: **8.x** (developed against 8.5). The extension shields itself from the
  old core's PHP 8 warnings; it does not patch Mantis itself.
- Web server: Apache (`.htaccess` shipped). For nginx, route the location to
  `index.php` and pass the `Authorization` header.

## Testing without a Mantis installation

The repository contains a mock of the 1.2.8 core API surface
(`tests/mock-mantis-core.php`) that simulates the old core's quirks (load-time
warnings, HTML error handler, `session_start()`, `trigger_error()` failures):

```bash
MANTIS_MCP_CORE_PATH="$PWD/tests/mock-mantis-core.php" \
  php -S 127.0.0.1:8095 -t extension
curl -s -u jdoe:secret -X POST http://127.0.0.1:8095/index.php \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```
