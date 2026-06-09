<?php

declare(strict_types=1);

namespace MantisMcp\Mcp;

use MantisMcp\Support\Logger;
use Throwable;

/**
 * MCP server over the Streamable HTTP transport.
 *
 * Implements the JSON-RPC methods relevant for a tool server:
 *   - initialize
 *   - notifications/initialized
 *   - ping
 *   - tools/list
 *   - tools/call
 *
 * Session management is done exclusively via the "Mcp-Session-Id" header -
 * NO PHP sessions/cookies are used.
 */
final class Server
{
    /** Protocol version preferred by this server. */
    private const PREFERRED_PROTOCOL = '2025-06-18';

    /** Accepted protocol versions (the client's choice is mirrored back). */
    private const SUPPORTED_PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const SESSION_HEADER = 'Mcp-Session-Id';

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly SessionStore $sessions,
        private readonly Logger $logger,
        private readonly string $serverName = 'mantis-mcp-server',
        private readonly string $serverVersion = '1.0.0',
        private readonly string $authToken = '',
        /** @var string[] */
        private readonly array $allowedOrigins = [],
    ) {
    }

    /**
     * Main entry point: processes exactly one HTTP request and sends the response.
     */
    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // --- Security: check Origin (DNS-rebinding protection) --------------
        if (!$this->originAllowed()) {
            $this->logger->warning('Rejected origin', ['origin' => $_SERVER['HTTP_ORIGIN'] ?? null]);
            $this->sendHttpError(403, 'Forbidden origin');
            return;
        }

        // --- Security: bearer auth (optional) -------------------------------
        if (!$this->authorized()) {
            $this->logger->warning('Unauthorized access', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            header('WWW-Authenticate: Bearer');
            $this->sendHttpError(401, 'Unauthorized');
            return;
        }

        switch ($method) {
            case 'POST':
                $this->handlePost();
                break;
            case 'DELETE':
                $this->handleDelete();
                break;
            case 'GET':
                // Server-initiated SSE streams are not supported.
                header('Allow: POST, DELETE');
                $this->sendHttpError(405, 'Method Not Allowed: this server only supports POST/DELETE');
                break;
            default:
                header('Allow: POST, DELETE');
                $this->sendHttpError(405, 'Method Not Allowed');
        }
    }

    private function handlePost(): void
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            $this->sendJsonRpcError(null, JsonRpcException::INVALID_REQUEST, 'Empty request body');
            return;
        }

        $payload = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('JSON parse error', ['error' => json_last_error_msg()]);
            $this->sendJsonRpcError(null, JsonRpcException::PARSE_ERROR, 'Parse error');
            return;
        }

        // The body must be a JSON object (request) or array (batch).
        if (!is_array($payload)) {
            $this->sendJsonRpcError(null, JsonRpcException::INVALID_REQUEST, 'Invalid Request');
            return;
        }

        // Batch requests (JSON array) are supported.
        $isBatch = array_is_list($payload) && $payload !== [];
        $messages = $isBatch ? $payload : [$payload];

        $responses = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                $responses[] = $this->errorResponse(null, JsonRpcException::INVALID_REQUEST, 'Invalid Request');
                continue;
            }
            $response = $this->dispatch($message);
            if ($response !== null) {
                $responses[] = $response;
            }
        }

        // Pure notifications => no response body, just 202 Accepted.
        if ($responses === []) {
            http_response_code(202);
            $this->commonHeaders();
            return;
        }

        $body = $isBatch ? $responses : $responses[0];
        $this->sendJson(200, $body);
    }

    private function handleDelete(): void
    {
        $sessionId = $this->incomingSessionId();
        if ($sessionId !== null && $this->sessions->isValid($sessionId)) {
            $this->sessions->delete($sessionId);
            $this->logger->info('Session terminated', ['session' => substr($sessionId, 0, 8) . '…']);
        }
        http_response_code(204);
        $this->commonHeaders();
    }

    /**
     * Processes a single JSON-RPC message.
     *
     * @param array<string,mixed> $message
     * @return array<string,mixed>|null Response, or null for notifications.
     */
    private function dispatch(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $isNotification = !array_key_exists('id', $message);
        $rpcMethod = is_string($message['method'] ?? null) ? $message['method'] : '';
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if (($message['jsonrpc'] ?? null) !== '2.0' || $rpcMethod === '') {
            return $isNotification ? null : $this->errorResponse($id, JsonRpcException::INVALID_REQUEST, 'Invalid Request');
        }

        $this->logger->info('RPC', ['method' => $rpcMethod, 'notification' => $isNotification]);

        try {
            // "initialize" creates the session and does not require one itself.
            if ($rpcMethod === 'initialize') {
                return $this->resultResponse($id, $this->handleInitialize($params));
            }

            // All other methods require a valid session.
            $this->requireSession();

            switch ($rpcMethod) {
                case 'notifications/initialized':
                case 'notifications/cancelled':
                    return null; // Notification, no response.

                case 'ping':
                    return $this->resultResponse($id, new \stdClass());

                case 'tools/list':
                    return $this->resultResponse($id, ['tools' => $this->tools->describeAll()]);

                case 'tools/call':
                    return $this->resultResponse($id, $this->handleToolCall($params));

                default:
                    if ($isNotification) {
                        return null;
                    }
                    return $this->errorResponse($id, JsonRpcException::METHOD_NOT_FOUND, "Unknown method: {$rpcMethod}");
            }
        } catch (JsonRpcException $e) {
            $this->logger->warning('RPC error', [
                'method' => $rpcMethod,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
            return $isNotification ? null : $this->errorResponse($id, $e->getCode(), $e->getMessage(), $e->getData());
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error during RPC', [
                'method' => $rpcMethod,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return $isNotification ? null : $this->errorResponse($id, JsonRpcException::INTERNAL_ERROR, 'Internal error');
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function handleInitialize(array $params): array
    {
        $requested = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '';
        $protocol = in_array($requested, self::SUPPORTED_PROTOCOLS, true) ? $requested : self::PREFERRED_PROTOCOL;

        // Create a new session and return its ID as a header.
        $sessionId = $this->sessions->create(['protocol' => $protocol, 'initialized' => true]);
        header(self::SESSION_HEADER . ': ' . $sessionId);

        $this->logger->info('Session initialized', [
            'protocol' => $protocol,
            'session' => substr($sessionId, 0, 8) . '…',
            'client' => $params['clientInfo']['name'] ?? null,
        ]);

        return [
            'protocolVersion' => $protocol,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'logging' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => $this->serverName,
                'version' => $this->serverVersion,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function handleToolCall(array $params): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ($name === '' || !$this->tools->has($name)) {
            throw new JsonRpcException(JsonRpcException::INVALID_PARAMS, "Unknown tool: {$name}");
        }

        $tool = $this->tools->get($name);
        $this->logger->info('Tool call', ['tool' => $name, 'args' => $arguments]);

        try {
            $result = $tool->execute($arguments);
        } catch (JsonRpcException $e) {
            // Business/upstream errors are returned as tool errors (isError)
            // rather than protocol errors, so the LLM can react sensibly.
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => 'Error: ' . $e->getMessage(),
                ]],
                'isError' => true,
            ];
        }

        $text = is_string($result)
            ? $result
            : (json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return [
            'content' => [[
                'type' => 'text',
                'text' => $text,
            ]],
            'isError' => false,
        ];
    }

    private function requireSession(): void
    {
        $sessionId = $this->incomingSessionId();
        if ($sessionId === null || !$this->sessions->isValid($sessionId)) {
            // Per the spec a 404 tells the client to re-initialize. We signal
            // this via an exception that the caller turns into an error body;
            // the protocol error is sufficient to prompt re-initialization.
            throw new JsonRpcException(
                JsonRpcException::INVALID_REQUEST,
                'Missing or invalid session. Call "initialize" first.'
            );
        }
        $this->sessions->touch($sessionId);
    }

    private function incomingSessionId(): ?string
    {
        $value = $_SERVER['HTTP_MCP_SESSION_ID'] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        return SessionStore::isValidId($value) ? $value : null;
    }

    // --- Security helpers ---------------------------------------------------

    private function originAllowed(): bool
    {
        if ($this->allowedOrigins === []) {
            return true; // Check disabled.
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if (!is_string($origin) || $origin === '') {
            return true; // Non-browser clients send no Origin.
        }
        return in_array($origin, $this->allowedOrigins, true);
    }

    private function authorized(): bool
    {
        if ($this->authToken === '') {
            return true; // Auth disabled.
        }
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($header) || !str_starts_with($header, 'Bearer ')) {
            return false;
        }
        $provided = substr($header, 7);
        // Constant-time comparison to mitigate timing attacks.
        return hash_equals($this->authToken, $provided);
    }

    // --- Response helpers ---------------------------------------------------

    /**
     * @param array<string,mixed>|\stdClass $result
     * @return array<string,mixed>
     */
    private function resultResponse(mixed $id, array|\stdClass $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string,mixed> */
    private function errorResponse(mixed $id, int $code, string $message, mixed $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    private function sendJsonRpcError(mixed $id, int $code, string $message): void
    {
        $this->sendJson(200, $this->errorResponse($id, $code, $message));
    }

    private function sendJson(int $status, mixed $body): void
    {
        http_response_code($status);
        $this->commonHeaders();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sendHttpError(int $status, string $message): void
    {
        $this->sendJson($status, $this->errorResponse(null, JsonRpcException::INVALID_REQUEST, $message));
    }

    private function commonHeaders(): void
    {
        // No caches, no cookies.
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
}
