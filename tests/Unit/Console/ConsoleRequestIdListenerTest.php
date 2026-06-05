<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Tests\Unit\Console;

use Amashukov\TracingBundle\Console\ConsoleRequestIdListener;
use Amashukov\TracingBundle\Messenger\WorkerRequestIdContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(ConsoleRequestIdListener::class)]
final class ConsoleRequestIdListenerTest extends TestCase
{
    private WorkerRequestIdContext $context;

    private LoggerInterface&MockObject $logger;

    private ConsoleRequestIdListener $listener;

    protected function setUp(): void
    {
        $this->context  = new WorkerRequestIdContext();
        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->listener = new ConsoleRequestIdListener($this->context, $this->logger);
    }

    public function testOnCommandSetsRequestIdInContextAndLogsDebug(): void
    {
        $this->logger->expects(self::once())
            ->method('debug')
            ->with(
                'CLI process request ID assigned',
                self::callback(static function (array $ctx): bool {
                    return isset($ctx['request_id'], $ctx['pid'])
                        && is_string($ctx['request_id'])
                        && 36 === strlen($ctx['request_id'])
                        && is_int($ctx['pid']);
                }),
            );

        $this->listener->onCommand($this->makeCommandEvent());

        $uuid = $this->context->getRequestId();
        self::assertNotNull($uuid);
        self::assertSame(36, strlen($uuid));
        self::assertSame('7', $uuid[14], 'must be UUIDv7 — version nibble at offset 14');
    }

    public function testOnTerminateClearsContext(): void
    {
        $this->context->setRequestId('some-id');
        $this->listener->onTerminate($this->makeTerminateEvent());
        self::assertNull($this->context->getRequestId());
    }

    public function testOnErrorClearsContext(): void
    {
        $this->context->setRequestId('some-id');
        $this->listener->onError($this->makeErrorEvent());
        self::assertNull($this->context->getRequestId());
    }

    public function testSubscribedEventsShape(): void
    {
        self::assertSame(
            [
                ConsoleCommandEvent::class   => ['onCommand', 256],
                ConsoleTerminateEvent::class => 'onTerminate',
                ConsoleErrorEvent::class     => 'onError',
            ],
            ConsoleRequestIdListener::getSubscribedEvents(),
        );
    }

    private function makeCommandEvent(): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(
            new Command('test'),
            $this->createStub(InputInterface::class),
            $this->createStub(OutputInterface::class),
        );
    }

    private function makeTerminateEvent(): ConsoleTerminateEvent
    {
        return new ConsoleTerminateEvent(
            new Command('test'),
            $this->createStub(InputInterface::class),
            $this->createStub(OutputInterface::class),
            0,
        );
    }

    private function makeErrorEvent(): ConsoleErrorEvent
    {
        return new ConsoleErrorEvent(
            $this->createStub(InputInterface::class),
            $this->createStub(OutputInterface::class),
            new \RuntimeException('test'),
            new Command('test'),
        );
    }
}
