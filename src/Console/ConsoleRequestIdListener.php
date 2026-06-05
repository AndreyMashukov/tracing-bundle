<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Console;

use Amashukov\TracingBundle\Messenger\WorkerRequestIdContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ConsoleRequestIdListener implements EventSubscriberInterface
{
    public function __construct(
        private WorkerRequestIdContext $workerContext,
        private LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleCommandEvent::class   => ['onCommand', 256],
            ConsoleTerminateEvent::class => 'onTerminate',
            ConsoleErrorEvent::class     => 'onError',
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $uuid = Uuid::v7()->toRfc4122();
        $this->workerContext->setRequestId($uuid);
        $this->logger->debug('CLI process request ID assigned', [
            'request_id' => $uuid,
            'pid'        => getmypid(),
        ]);
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $this->workerContext->clear();
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        $this->workerContext->clear();
    }
}
