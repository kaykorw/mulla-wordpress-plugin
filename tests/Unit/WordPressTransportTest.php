<?php

declare(strict_types=1);

namespace MullaPayments\Tests\Unit;

use MullaPayments\Http\WordPressTransport;
use MullaPayments\Vendor\Mulla\Exceptions\ConnectionException;
use MullaPayments\Vendor\Mulla\Http\Request;
use PHPUnit\Framework\TestCase;

final class WordPressTransportTest extends TestCase
{
    protected function setUp(): void
    {
        \WpStubs::reset();
    }

    public function testSendsTheRequestThroughTheWordPressHttpApi(): void
    {
        \WpStubs::$nextResponse = [
            'response' => ['code' => 201],
            'headers' => ['Content-Type' => 'application/json', 'Set-Cookie' => ['a=1', 'b=2']],
            'body' => '{"id":"ch_1"}',
        ];

        $response = (new WordPressTransport())->send(new Request(
            'POST',
            'https://mfs.kayko.dev/gateway/v1/charges/initiate',
            ['x-api-key' => 'sk_test_x', 'user-agent' => 'mulla-wordpress/0.0.1'],
            '{"amount":100}',
            12.0,
        ));

        self::assertSame(201, $response->statusCode);
        self::assertSame('{"id":"ch_1"}', $response->body);
        self::assertSame('application/json', $response->headers['content-type']);
        self::assertSame('b=2', $response->headers['set-cookie']);

        self::assertCount(1, \WpStubs::$requests);
        $args = \WpStubs::$requests[0]['args'];
        self::assertSame('POST', $args['method']);
        self::assertSame('{"amount":100}', $args['body']);
        self::assertSame(12.0, $args['timeout']);
        self::assertSame(0, $args['redirection']);
        self::assertTrue($args['sslverify']);
        self::assertSame('mulla-wordpress/0.0.1', $args['user-agent']);
    }

    public function testOmitsTheBodyWhenThereIsNone(): void
    {
        (new WordPressTransport())->send(new Request('GET', 'https://mfs.kayko.dev/gateway/v1/charges'));

        self::assertArrayNotHasKey('body', \WpStubs::$requests[0]['args']);
    }

    public function testAConnectionThatNeverStartedIsSafeToRetry(): void
    {
        \WpStubs::$nextResponse = new \WP_Error('http_request_failed', 'cURL error 6: Could not resolve host: mfs.kayko.dev');

        $e = $this->sendExpectingFailure();

        self::assertFalse($e->requestMayHaveBeenSent());
        self::assertStringContainsString('Could not resolve host', $e->getMessage());
    }

    public function testATimeoutMayHaveReachedMulla(): void
    {
        \WpStubs::$nextResponse = new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 10001 milliseconds');

        self::assertTrue($this->sendExpectingFailure()->requestMayHaveBeenSent());
    }

    public function testTheErrorMessageDoesNotLeakQueryStrings(): void
    {
        \WpStubs::$nextResponse = new \WP_Error('http_request_failed', 'cURL error 28: timed out');

        $e = $this->sendExpectingFailure('https://mfs.kayko.dev/gateway/v1/checkout/abc?token=secret-token');

        self::assertStringNotContainsString('secret-token', $e->getMessage());
    }

    private function sendExpectingFailure(string $url = 'https://mfs.kayko.dev/gateway/v1/charges'): ConnectionException
    {
        try {
            (new WordPressTransport())->send(new Request('POST', $url, [], '{}'));
        } catch (ConnectionException $e) {
            return $e;
        }

        self::fail('Expected a ConnectionException.');
    }
}
