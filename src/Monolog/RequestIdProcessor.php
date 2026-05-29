<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Monolog;

use Amashukov\TracingBundle\Http\RequestIdResolverInterface;
use Monolog\LogRecord;

final readonly class RequestIdProcessor
{
    public function __construct(
        private RequestIdResolverInterface $resolver,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: ['request_id' => $this->resolver->current()] + $record->extra);
    }
}
