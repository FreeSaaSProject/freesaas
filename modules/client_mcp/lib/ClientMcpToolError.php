<?php
declare(strict_types=1);

/** Tool-level failure reported to the agent as isError result (not a JSON-RPC error). */
final class ClientMcpToolError extends RuntimeException
{
    /** @var string */
    private $errorCode;

    /** @var array<string,mixed> */
    private $extra;

    /** @param array<string,mixed> $extra */
    public function __construct(string $errorCode, string $message, array $extra = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->extra = $extra;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        return [
            'ok' => false,
            'error' => $this->errorCode,
            'message' => $this->getMessage(),
        ] + $this->extra;
    }
}
