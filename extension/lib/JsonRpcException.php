<?php

declare(strict_types=1);

namespace MantisMcp\Extension;

use RuntimeException;
use Throwable;

/**
 * Exception that maps onto a JSON-RPC 2.0 error.
 */
final class JsonRpcException extends RuntimeException
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

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
