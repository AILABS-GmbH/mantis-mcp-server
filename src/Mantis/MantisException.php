<?php

declare(strict_types=1);

namespace MantisMcp\Mantis;

use RuntimeException;
use Throwable;

/**
 * Error while communicating with the Mantis REST API.
 */
final class MantisException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly ?string $detail = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }
}
