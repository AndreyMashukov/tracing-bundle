<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Tests\Unit\Http;

use Amashukov\TracingBundle\Http\RequestIdResolver;
use Amashukov\TracingBundle\Messenger\WorkerRequestIdContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(RequestIdResolver::class)]
final class RequestIdResolverTest extends TestCase
{
    public function testReturnsRequestIdFromMainRequestAttributes(): void
    {
        $request = Request::create('/');
        $request->attributes->set(RequestIdResolver::REQUEST_ATTR, '01923e1c-ab27-7c47-9b3a-1234567890ab');
        $stack = new RequestStack([$request]);

        self::assertSame('01923e1c-ab27-7c47-9b3a-1234567890ab', (new RequestIdResolver($stack))->current());
    }

    public function testReturnsCliFallbackWhenNoRequest(): void
    {
        self::assertSame(RequestIdResolver::CLI_FALLBACK, (new RequestIdResolver(new RequestStack()))->current());
    }

    public function testReturnsCliFallbackWhenAttributeMissing(): void
    {
        $stack = new RequestStack([Request::create('/')]);

        self::assertSame(RequestIdResolver::CLI_FALLBACK, (new RequestIdResolver($stack))->current());
    }

    public function testReturnsCliFallbackWhenAttributeNotString(): void
    {
        $request = Request::create('/');
        $request->attributes->set(RequestIdResolver::REQUEST_ATTR, ['array']);
        $stack = new RequestStack([$request]);

        self::assertSame(RequestIdResolver::CLI_FALLBACK, (new RequestIdResolver($stack))->current());
    }

    public function testReturnsWorkerContextIdWhenNoHttpRequestAndWorkerContextSet(): void
    {
        $context = new WorkerRequestIdContext();
        $context->setRequestId('worker-id-from-stamp');

        $resolver = new RequestIdResolver(new RequestStack(), $context);

        self::assertSame('worker-id-from-stamp', $resolver->current());
    }

    public function testHttpRequestStillWinsOverWorkerContext(): void
    {
        $request = Request::create('/');
        $request->attributes->set(RequestIdResolver::REQUEST_ATTR, 'http-request-id');
        $context = new WorkerRequestIdContext();
        $context->setRequestId('worker-id-loser');

        $resolver = new RequestIdResolver(new RequestStack([$request]), $context);

        self::assertSame('http-request-id', $resolver->current(), 'HTTP main-request attribute always wins; worker context is only the no-HTTP fallback');
    }
}
