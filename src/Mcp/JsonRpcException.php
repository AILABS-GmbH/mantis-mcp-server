<?php

declare(strict_types=1);

namespace MantisMcp\Mcp;

use RuntimeException;
use Throwable;

/**
 * Exception that maps onto a JSON-RPC error.
 *
 * The code follows the JSON-RPC 2.0 specification:
 *   -32700 Parse error
 *   -32600 Invalid Request
 *   -32601 Method not found
 *   -32602 Invalid params
 *   -32603 Internal error
 * Application-specific errors use the -32000 to -32099 range.
 */
final class JsonRpcException extends RuntimeException
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /** Application-specific: upstream error from Mantis. */
    public const UPSTREAM_ERROR = -32010;

    /** @var mixed Optional structured extra data. */
    private mixed $data;

    public function __construct(int $code, string $message, mixed $data = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->data = $data;
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}
