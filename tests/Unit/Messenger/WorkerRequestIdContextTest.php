<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Tests\Unit\Messenger;

use Amashukov\TracingBundle\Messenger\WorkerRequestIdContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerRequestIdContext::class)]
final class WorkerRequestIdContextTest extends TestCase
{
    public function testGetRequestIdIsNullWhenUnset(): void
    {
        self::assertNull((new WorkerRequestIdContext())->getRequestId());
    }

    public function testSetRequestIdThenGetReturnsValue(): void
    {
        $context = new WorkerRequestIdContext();
        $context->setRequestId('01923e1c-ab27-7c47-9b3a-1234567890ab');

        self::assertSame('01923e1c-ab27-7c47-9b3a-1234567890ab', $context->getRequestId());
    }

    public function testClearResetsToNull(): void
    {
        $context = new WorkerRequestIdContext();
        $context->setRequestId('abc');
        $context->clear();

        self::assertNull($context->getRequestId());
    }
}
