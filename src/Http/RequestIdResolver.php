<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Http;

use Amashukov\TracingBundle\Messenger\WorkerRequestIdContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class RequestIdResolver implements RequestIdResolverInterface
{
    public const string CLI_FALLBACK = 'cli';

    public const string REQUEST_ATTR = 'request_id';

    public function __construct(
        private RequestStack $requestStack,
        private ?WorkerRequestIdContext $workerContext = null,
    ) {}

    public function current(): string
    {
        $request = $this->requestStack->getMainRequest();
        if ($request instanceof Request) {
            $value = $request->attributes->get(self::REQUEST_ATTR);
            if (is_string($value) && '' !== $value) {
                return $value;
            }
        }

        $workerId = $this->workerContext?->getRequestId();
        if (is_string($workerId) && '' !== $workerId) {
            return $workerId;
        }

        return self::CLI_FALLBACK;
    }
}
