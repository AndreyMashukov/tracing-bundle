<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Tests\Unit\Monolog;

use Amashukov\TracingBundle\Http\RequestIdResolverInterface;
use Amashukov\TracingBundle\Monolog\RequestIdProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestIdProcessor::class)]
final class RequestIdProcessorTest extends TestCase
{
    public function testAttachesRequestIdToRecordExtra(): void
    {
        $resolver = $this->createStub(RequestIdResolverInterface::class);
        $resolver->method('current')->willReturn('01923e1c-ab27-7c47-9b3a-1234567890ab');

        $original = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: [],
            extra: ['preexisting' => 'kept'],
        );

        $processed = (new RequestIdProcessor($resolver))($original);

        self::assertSame('01923e1c-ab27-7c47-9b3a-1234567890ab', $processed->extra['request_id']);
        self::assertSame('kept', $processed->extra['preexisting'], 'must preserve pre-existing extra keys');
    }

    public function testPropagatesCliFallback(): void
    {
        $resolver = $this->createStub(RequestIdResolverInterface::class);
        $resolver->method('current')->willReturn('cli');
        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'console error',
            context: [],
            extra: [],
        );

        $processed = (new RequestIdProcessor($resolver))($record);

        self::assertSame('cli', $processed->extra['request_id']);
    }
}
