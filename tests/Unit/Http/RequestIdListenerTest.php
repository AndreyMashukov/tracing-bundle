<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Tests\Unit\Http;

use Amashukov\TracingBundle\Http\RequestIdListener;
use Amashukov\TracingBundle\Http\RequestIdResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(RequestIdListener::class)]
final class RequestIdListenerTest extends TestCase
{
    public function testAcceptsValidUuidV7FromHeader(): void
    {
        $request = Request::create('/');
        $request->headers->set('X-Request-Id', '01923e1c-ab27-7c47-9b3a-1234567890ab');
        $this->fireOnRequest($request);

        self::assertSame('01923e1c-ab27-7c47-9b3a-1234567890ab', $request->attributes->get(RequestIdResolver::REQUEST_ATTR));
    }

    public function testLowercasesIncomingHeader(): void
    {
        $request = Request::create('/');
        $request->headers->set('X-Request-Id', 'ABC123EF-1234-7C47-9B3A-1234567890AB');
        $this->fireOnRequest($request);

        self::assertSame('abc123ef-1234-7c47-9b3a-1234567890ab', $request->attributes->get(RequestIdResolver::REQUEST_ATTR));
    }

    #[DataProvider('invalidHeaderProvider')]
    public function testGeneratesUuidV7WhenHeaderMissingOrInvalid(string $headerValue): void
    {
        $request = Request::create('/');
        if ('' !== $headerValue) {
            $request->headers->set('X-Request-Id', $headerValue);
        }
        $this->fireOnRequest($request);

        $generated = $request->attributes->get(RequestIdResolver::REQUEST_ATTR);
        self::assertIsString($generated);
        self::assertSame(36, strlen($generated));
        self::assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $generated);
        self::assertSame('7', $generated[14], 'must be UUIDv7 — version nibble at offset 14 is "7"');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidHeaderProvider(): array
    {
        return [
            'missing'    => [''],
            'too short'  => ['abc123'],
            'too long'   => [str_repeat('a', 64)],
            'wrong char' => ['xyz-not-a-valid-uuid-xxxxxxxxxxxxxxxx'],
            'sql inj'    => ["'; DROP TABLE--xxxxxxxxxxxxxxxxxxxxxx"],
        ];
    }

    public function testMirrorsRequestIdToResponseHeader(): void
    {
        $request = Request::create('/');
        $request->attributes->set(RequestIdResolver::REQUEST_ATTR, '01923e1c-ab27-7c47-9b3a-1234567890ab');
        $kernel  = $this->createStub(HttpKernelInterface::class);
        $event   = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response());

        (new RequestIdListener())->onResponse($event);

        self::assertSame('01923e1c-ab27-7c47-9b3a-1234567890ab', $event->getResponse()->headers->get('X-Request-Id'));
    }

    public function testSkipsSubRequest(): void
    {
        $request = Request::create('/');
        $request->headers->set('X-Request-Id', '01923e1c-ab27-7c47-9b3a-1234567890ab');
        $kernel  = $this->createStub(HttpKernelInterface::class);
        $event   = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        (new RequestIdListener())->onRequest($event);

        self::assertNull($request->attributes->get(RequestIdResolver::REQUEST_ATTR));
    }

    public function testSubscribedEventsShape(): void
    {
        self::assertSame(
            [
                RequestEvent::class  => ['onRequest', 256],
                ResponseEvent::class => ['onResponse', -256],
            ],
            RequestIdListener::getSubscribedEvents(),
        );
    }

    private function fireOnRequest(Request $request): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event  = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        (new RequestIdListener())->onRequest($event);
    }
}
