<?php

declare(strict_types=1);

namespace MantisMcp\Extension;

/**
 * Extracts and verifies HTTP Basic Auth credentials against Mantis' own
 * user database (auth_attempt_script_login). No cookie is set; the login
 * only exists for the lifetime of this request — the same mechanism the
 * legacy SOAP API used for its per-call username/password authentication.
 */
final class BasicAuth
{
    /**
     * Returns [username, password] from the request, or null if absent.
     *
     * Checks PHP_AUTH_* first (mod_php), then the raw Authorization header
     * (HTTP_AUTHORIZATION / REDIRECT_HTTP_AUTHORIZATION), which is how the
     * credentials arrive under CGI/FastCGI — common on managed hosting.
     * The shipped .htaccess forwards the header for that case.
     *
     * @return array{0:string,1:string}|null
     */
    public static function credentials(): ?array
    {
        $user = $_SERVER['PHP_AUTH_USER'] ?? null;
        $pass = $_SERVER['PHP_AUTH_PW'] ?? null;
        if (is_string($user) && $user !== '' && is_string($pass)) {
            return [$user, $pass];
        }

        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $header = $_SERVER[$key] ?? '';
            if (!is_string($header) || stripos($header, 'Basic ') !== 0) {
                continue;
            }
            $decoded = base64_decode(substr($header, 6), true);
            if ($decoded === false || !str_contains($decoded, ':')) {
                continue;
            }
            [$user, $pass] = explode(':', $decoded, 2);
            if ($user !== '') {
                return [$user, $pass];
            }
        }

        return null;
    }

    /**
     * Verifies the credentials via the Mantis core and logs the user in for
     * this request. Returns the authenticated user id, or null on failure.
     */
    public static function authenticate(string $username, string $password, Logger $logger): ?int
    {
        // auth_attempt_script_login() checks the account exists, is enabled,
        // and that the password matches (whatever hash scheme Mantis uses).
        if (!auth_attempt_script_login($username, $password)) {
            $logger->warning('Basic auth failed', [
                'username' => $username,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            return null;
        }

        $userId = (int) auth_get_current_user_id();
        $logger->info('Authenticated', ['username' => $username, 'user_id' => $userId]);

        return $userId;
    }

    /** Sends a 401 challenge with a JSON-RPC error body. */
    public static function deny(): void
    {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="Mantis MCP", charset="UTF-8"');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => JsonRpcException::INVALID_REQUEST,
                'message' => 'Unauthorized: send your Mantis username and password via HTTP Basic Auth.',
            ],
        ], JSON_UNESCAPED_SLASHES);
    }
}
