<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Uid\Uuid;

final readonly class RequestIdListener implements EventSubscriberInterface
{
    public const string HEADER = 'X-Request-Id';

    public static function getSubscribedEvents(): array
    {
        return [
            RequestEvent::class  => ['onRequest', 256],
            ResponseEvent::class => ['onResponse', -256],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request     = $event->getRequest();
        $headerId    = (string) $request->headers->get(self::HEADER, '');
        $isValidUuid = 36 === strlen($headerId) && 1 === preg_match('/^[a-f0-9-]{36}$/i', $headerId);
        $requestId   = $isValidUuid ? strtolower($headerId) : Uuid::v7()->toRfc4122();
        $request->attributes->set(RequestIdResolver::REQUEST_ATTR, $requestId);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $requestId = $event->getRequest()->attributes->get(RequestIdResolver::REQUEST_ATTR);
        if (is_string($requestId) && '' !== $requestId) {
            $event->getResponse()->headers->set(self::HEADER, $requestId);
        }
    }
}
