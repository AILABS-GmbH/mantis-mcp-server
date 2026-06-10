<?php

declare(strict_types=1);

namespace MantisMcp\Extension;

use Throwable;

/**
 * Stateless MCP dispatcher (Streamable HTTP, JSON-RPC 2.0).
 *
 * Unlike the standalone server there is NO session handling here: every
 * request carries HTTP Basic Auth credentials that are verified against
 * Mantis' own user database per request. Per the MCP specification a server
 * that does not return an Mcp-Session-Id header during initialization is a
 * sessionless server — clients then simply omit the header.
 *
 * Supported methods: initialize, notifications/*, ping, tools/list, tools/call.
 */
final class Dispatcher
{
    private const PREFERRED_PROTOCOL = '2025-06-18';
    private const SUPPORTED_PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly Logger $logger,
        private readonly string $serverName = 'mantis-mcp-extension',
        private readonly string $serverVersion = '1.0.0',
    ) {
    }

    /** Processes the current HTTP request and emits the response. */
    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method !== 'POST') {
            header('Allow: POST');
            $this->sendJson(405, $this->errorResponse(null, JsonRpcException::INVALID_REQUEST, 'Method Not Allowed: only POST is supported'));
            return;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            $this->sendJson(200, $this->errorResponse(null, JsonRpcException::INVALID_REQUEST, 'Empty request body'));
            return;
        }

        $payload = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('JSON parse error', ['error' => json_last_error_msg()]);
            $this->sendJson(200, $this->errorResponse(null, JsonRpcException::PARSE_ERROR, 'Parse error'));
            return;
        }
        if (!is_array($payload)) {
            $this->sendJson(200, $this->errorResponse(null, JsonRpcException::INVALID_REQUEST, 'Invalid Request'));
            return;
        }

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

        if ($responses === []) {
            // Pure notifications.
            http_response_code(202);
            $this->commonHeaders();
            return;
        }

        $this->sendJson(200, $isBatch ? $responses : $responses[0]);
    }

    /**
     * @param array<string,mixed> $message
     * @return array<string,mixed>|null
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
            switch ($rpcMethod) {
                case 'initialize':
                    return $this->resultResponse($id, $this->handleInitialize($params));

                case 'notifications/initialized':
                case 'notifications/cancelled':
                    return null;

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
            $this->logger->warning('RPC error', ['method' => $rpcMethod, 'code' => $e->getCode(), 'message' => $e->getMessage()]);
            return $isNotification ? null : $this->errorResponse($id, $e->getCode(), $e->getMessage(), $e->getData());
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error during RPC', [
                'method' => $rpcMethod,
                'exception' => get_class($e),
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

        $this->logger->info('Initialized (stateless)', [
            'protocol' => $protocol,
            'client' => $params['clientInfo']['name'] ?? null,
        ]);

        // Deliberately no Mcp-Session-Id header: this server is stateless,
        // authentication happens per request via Basic Auth.
        return [
            'protocolVersion' => $protocol,
            'capabilities' => [
                'tools' => ['listChanged' => false],
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
        } catch (JsonRpcException | MantisCoreError $e) {
            // Business errors (validation, access denied, Mantis core errors)
            // become tool errors so the LLM can react sensibly.
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }

        $text = is_string($result)
            ? $result
            : (json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => false,
        ];
    }

    // --- Response helpers -----------------------------------------------------

    /** @return array<string,mixed> */
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

    private function sendJson(int $status, mixed $body): void
    {
        http_response_code($status);
        $this->commonHeaders();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function commonHeaders(): void
    {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
}
