<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Http;

interface RequestIdResolverInterface
{
    /**
     * Returns the request_id for the current request, or `cli` when no
     * HTTP request is active (console commands, messenger workers).
     */
    public function current(): string;
}
