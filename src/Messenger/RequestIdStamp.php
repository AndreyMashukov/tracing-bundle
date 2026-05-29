<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class RequestIdStamp implements StampInterface
{
    public function __construct(
        private string $requestId,
    ) {}

    public function requestId(): string
    {
        return $this->requestId;
    }
}
