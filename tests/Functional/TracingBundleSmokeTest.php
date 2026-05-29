<?php

declare(strict_types=1);

namespace Amashukov\TracingBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversNothing]
final class TracingBundleSmokeTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testRequestIdEndToEnd(): void
    {
        $client = $this->bootBrowser();

        $client->request(Request::METHOD_GET, '/ping', server: [
            'HTTP_X-Request-Id' => '01923e1c-ab27-7c47-9b3a-1234567890ab',
        ]);

        $response = $client->getResponse();
        self::assertSame(
            '01923e1c-ab27-7c47-9b3a-1234567890ab',
            $response->headers->get('X-Request-Id'),
        );
    }

    public function testGeneratesUuidWhenHeaderAbsent(): void
    {
        $client = $this->bootBrowser();
        $client->request(Request::METHOD_GET, '/ping');

        $generated = $client->getResponse()->headers->get('X-Request-Id');
        self::assertIsString($generated);
        self::assertSame(36, strlen($generated));
        self::assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $generated);
    }

    public function testInvalidHeaderRegeneratesFreshUuid(): void
    {
        $client = $this->bootBrowser();
        $client->request(Request::METHOD_GET, '/ping', server: [
            'HTTP_X-Request-Id' => 'not-a-uuid; DROP TABLE users;--',
        ]);

        $generated = $client->getResponse()->headers->get('X-Request-Id');
        self::assertIsString($generated);
        self::assertSame(36, strlen($generated));
        self::assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $generated);
        self::assertNotSame('not-a-uuid; DROP TABLE users;--', $generated);
    }

    private function bootBrowser(): KernelBrowser
    {
        $kernel = self::bootKernel();

        return new KernelBrowser($kernel);
    }
}
