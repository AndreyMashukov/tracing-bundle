<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Messenger;

final class WorkerRequestIdContext
{
    private ?string $requestId = null;

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function clear(): void
    {
        $this->requestId = null;
    }
}
