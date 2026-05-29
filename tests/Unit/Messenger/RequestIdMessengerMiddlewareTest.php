<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Tests\Unit\Messenger;

use Amashukov\TracingBundle\Http\RequestIdResolverInterface;
use Amashukov\TracingBundle\Messenger\RequestIdMessengerMiddleware;
use Amashukov\TracingBundle\Messenger\RequestIdStamp;
use Amashukov\TracingBundle\Messenger\WorkerRequestIdContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

#[CoversClass(RequestIdMessengerMiddleware::class)]
final class RequestIdMessengerMiddlewareTest extends TestCase
{
    public function testDispatchPathAttachesStampFromResolverWhenNoStampPresent(): void
    {
        $resolver = $this->createStub(RequestIdResolverInterface::class);
        $resolver->method('current')->willReturn('01923e1c-ab27-7c47-9b3a-1234567890ab');
        $capture    = new EnvelopeCapture();
        $stack      = new CapturingStack(new EnvelopeCapturingHandler($capture));
        $middleware = new RequestIdMessengerMiddleware($resolver, new WorkerRequestIdContext());

        $middleware->handle(new Envelope(new stdClass()), $stack);

        self::assertInstanceOf(Envelope::class, $capture->envelope);
        $stamp = $capture->envelope->last(RequestIdStamp::class);
        self::assertInstanceOf(RequestIdStamp::class, $stamp);
        self::assertSame('01923e1c-ab27-7c47-9b3a-1234567890ab', $stamp->requestId());
    }

    public function testDispatchPathDoesNotOverwriteExistingStamp(): void
    {
        $resolver = $this->createStub(RequestIdResolverInterface::class);
        $resolver->method('current')->willReturn('new-id');
        $envelope   = new Envelope(new stdClass(), [new RequestIdStamp('preexisting-id')]);
        $capture    = new EnvelopeCapture();
        $stack      = new CapturingStack(new EnvelopeCapturingHandler($capture));
        $middleware = new RequestIdMessengerMiddleware($resolver, new WorkerRequestIdContext());

        $middleware->handle($envelope, $stack);

        self::assertInstanceOf(Envelope::class, $capture->envelope);
        self::assertCount(1, $capture->envelope->all(RequestIdStamp::class), 'middleware must not stack a second stamp on dispatch path when one already exists');
        self::assertSame('preexisting-id', $capture->envelope->last(RequestIdStamp::class)?->requestId());
    }

    public function testConsumePathReadsStampAndPopulatesWorkerContextDuringHandlerExecution(): void
    {
        $resolver = $this->createStub(RequestIdResolverInterface::class);
        $context  = new WorkerRequestIdContext();
        $envelope = new Envelope(new stdClass(), [
            new ReceivedStamp('transport'),
            new RequestIdStamp('worker-id-xyz'),
        ]);

        $capture = new WorkerIdSnapshot();
        $stack   = new CapturingStack(new WorkerIdSnapshotHandler($context, $capture));

        (new RequestIdMessengerMiddleware($resolver, $context))->handle($envelope, $stack);

        self::assertSame('worker-id-xyz', $capture->workerId, 'WorkerContext must hold the stamp id during the downstream middleware/handler execution');
    }

    public function testConsumePathClearsWorkerContextAfterHandlerReturns(): void
    {
        $resolver = $this->createStub(RequestIdResolverInterface::class);
        $context  = new WorkerRequestIdContext();
        $envelope = new Envelope(new stdClass(), [
            new ReceivedStamp('transport'),
            new RequestIdStamp('worker-id-xyz'),
        ]);

        (new RequestIdMessengerMiddleware($resolver, $context))->handle($envelope, new CapturingStack(new PassthroughHandler()));

        self::assertNull($context->getRequestId(), 'WorkerContext must be cleared after the handler returns so subsequent messages do not inherit the prior request_id');
    }

    public function testConsumePathWithoutStampLeavesWorkerContextUntouched(): void
    {
        $resolver = $this->createStub(RequestIdResolverInterface::class);
        $context  = new WorkerRequestIdContext();
        $context->setRequestId('preset-value');
        $envelope = new Envelope(new stdClass(), [new ReceivedStamp('transport')]);

        (new RequestIdMessengerMiddleware($resolver, $context))->handle($envelope, new CapturingStack(new PassthroughHandler()));

        self::assertSame('preset-value', $context->getRequestId(), 'consume path without RequestIdStamp must not touch WorkerContext (preserves any value set externally)');
    }
}

final class EnvelopeCapture
{
    public ?Envelope $envelope = null;
}

final class WorkerIdSnapshot
{
    public ?string $workerId = null;
}

final readonly class EnvelopeCapturingHandler implements MiddlewareInterface
{
    public function __construct(
        private EnvelopeCapture $capture,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->capture->envelope = $envelope;

        return $envelope;
    }
}

final readonly class WorkerIdSnapshotHandler implements MiddlewareInterface
{
    public function __construct(
        private WorkerRequestIdContext $context,
        private WorkerIdSnapshot $capture,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->capture->workerId = $this->context->getRequestId();

        return $envelope;
    }
}

final readonly class PassthroughHandler implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        return $envelope;
    }
}

final readonly class CapturingStack implements StackInterface
{
    public function __construct(
        private MiddlewareInterface $handler,
    ) {}

    public function next(): MiddlewareInterface
    {
        return $this->handler;
    }
}
