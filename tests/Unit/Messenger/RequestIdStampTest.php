<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Tests\Unit\Messenger;

use Amashukov\TracingBundle\Messenger\RequestIdStamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestIdStamp::class)]
final class RequestIdStampTest extends TestCase
{
    public function testStampPreservesCtorValue(): void
    {
        $stamp = new RequestIdStamp('01923e1c-ab27-7c47-9b3a-1234567890ab');

        self::assertSame('01923e1c-ab27-7c47-9b3a-1234567890ab', $stamp->requestId());
    }

    public function testStampAcceptsCliFallbackString(): void
    {
        $stamp = new RequestIdStamp('cli');

        self::assertSame('cli', $stamp->requestId());
    }
}
