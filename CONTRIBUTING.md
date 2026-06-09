# Contributing

Thanks for your interest in improving the Mantis MCP Server! This document
describes how to set up the project locally and the conventions we follow.

## Getting started

```bash
git clone https://github.com/AILABS-GmbH/mantis-mcp-server.git
cd mantis-mcp-server
composer install
cp .env.example .env   # fill in MANTIS_BASE_URL and MANTIS_API_TOKEN
```

Run the development server:

```bash
composer serve   # php -d display_errors=0 -S 127.0.0.1:8088 -t public
```

## Before opening a pull request

1. **Lint** every PHP file — there must be no syntax errors:
   ```bash
   composer lint
   ```
2. **Smoke-test** the protocol locally (see the "Quick test with curl" section
   in the [README](README.md)). At minimum, verify that `initialize` returns an
   `Mcp-Session-Id` header and that `tools/list` works.
3. Keep changes focused and describe the motivation in the PR description.

## Coding conventions

- **Language:** all code, comments, commit messages, error messages and
  documentation are written in **English**.
- **PHP:** target **PHP ≥ 8.1**, use `declare(strict_types=1)` in every file,
  and follow **PSR-12** formatting and **PSR-4** autoloading (`MantisMcp\` →
  `src/`).
- **Style:** prefer small, single-responsibility classes; `final` by default;
  constructor property promotion where it improves readability.
- **Errors:** never echo errors to the client. Log via the injected `Logger`
  and surface failures as `JsonRpcException` / tool `isError` results.
- **Secrets:** never log tokens or passwords in clear text; rely on the logger's
  redaction and keep secrets out of committed files.

## Adding a new tool

1. Create a class in `src/Tools/` extending `AbstractTool`.
2. Implement `name()`, `description()`, `inputSchema()` and `run()`.
3. Register it in [`public/index.php`](public/index.php).
4. Add a row to the tool table in the [README](README.md).

Tools should validate their input with the helpers from `AbstractTool`
(`requireInt`, `requireString`, …) and return compact, LLM-friendly payloads.

## Reporting security issues

Please do **not** open public issues for security vulnerabilities. Instead,
report them privately to the maintainers (see the repository contact details).

## License

By contributing, you agree that your contributions will be licensed under the
[MIT License](LICENSE).
