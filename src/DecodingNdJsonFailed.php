<?php

declare(strict_types=1);

namespace Bakame\Aide\NdJson;

use Throwable;

class DecodingNdJsonFailed extends NdJsonException
{
    public function __construct(
        string $message,
        protected mixed $value = null,
        protected string|int|null $offset = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getOffset(): string|int|null
    {
        return $this->offset;
    }
}
