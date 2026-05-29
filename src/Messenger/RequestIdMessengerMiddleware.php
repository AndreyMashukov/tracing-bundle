<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Messenger;

use Amashukov\TracingBundle\Http\RequestIdResolverInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class RequestIdMessengerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestIdResolverInterface $resolver,
        private WorkerRequestIdContext $workerContext,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $isConsume = $envelope->last(ReceivedStamp::class) instanceof StampInterface;

        if (!$isConsume) {
            if ([] === $envelope->all(RequestIdStamp::class)) {
                $envelope = $envelope->with(new RequestIdStamp($this->resolver->current()));
            }

            return $stack->next()->handle($envelope, $stack);
        }

        $stamp = $envelope->last(RequestIdStamp::class);
        if (!$stamp instanceof RequestIdStamp) {
            return $stack->next()->handle($envelope, $stack);
        }

        $this->workerContext->setRequestId($stamp->requestId());

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->workerContext->clear();
        }
    }
}
