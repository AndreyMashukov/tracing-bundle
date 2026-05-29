<?php

declare(strict_types=1);

use Amashukov\RectorRules\NoArrayAssertContainsInTestsRector;
use Amashukov\RectorRules\NoAssertCallInSrcRector;
use Amashukov\RectorRules\NoAssertInsideIfInFunctionalTestsRector;
use Amashukov\RectorRules\NoCommentsOutsideInterfaceMethodDocBlockRector;
use Amashukov\RectorRules\NoDirectDbMutationInFunctionalTestsRector;
use Amashukov\RectorRules\NoDirectDispatchInFunctionalTestsRector;
use Amashukov\RectorRules\NoEnvironmentCheckInSrcRector;
use Amashukov\RectorRules\NoExistenceOnlyAssertionsInTestsRector;
use Amashukov\RectorRules\NoNullCoalesceNewFallbackRector;
use Amashukov\RectorRules\NoPhpstanIgnoreRector;
use Amashukov\RectorRules\NoSuperglobalAccessRector;
use Amashukov\RectorRules\NoTypeOnlyAssertionsInTestsRector;
use Amashukov\RectorRules\RequirePsrClockInterfaceRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/tests/Functional/TestKernel.php',
    ])
    ->withRules([
        NoArrayAssertContainsInTestsRector::class,
        NoAssertCallInSrcRector::class,
        NoAssertInsideIfInFunctionalTestsRector::class,
        NoCommentsOutsideInterfaceMethodDocBlockRector::class,
        NoDirectDbMutationInFunctionalTestsRector::class,
        NoDirectDispatchInFunctionalTestsRector::class,
        NoEnvironmentCheckInSrcRector::class,
        NoExistenceOnlyAssertionsInTestsRector::class,
        NoNullCoalesceNewFallbackRector::class,
        NoPhpstanIgnoreRector::class,
        NoSuperglobalAccessRector::class,
        NoTypeOnlyAssertionsInTestsRector::class,
        RequirePsrClockInterfaceRector::class,
    ]);
