<?php

namespace IshanShah\SafeRetry\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use IshanShah\SafeRetry\Support\DefaultFingerprinter;
use IshanShah\SafeRetry\Tests\TestCase;

class FingerprinterTest extends TestCase
{
    private function fp(Request $request): string
    {
        return (new DefaultFingerprinter)->fingerprint($request);
    }

    private function jsonRequest(string $method, string $uri, string $body): Request
    {
        return Request::create($uri, $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    }

    public function test_same_request_has_same_fingerprint(): void
    {
        $this->assertSame(
            $this->fp($this->jsonRequest('POST', '/orders', '{"a":1}')),
            $this->fp($this->jsonRequest('POST', '/orders', '{"a":1}')),
        );
    }

    public function test_body_method_path_and_query_change_the_fingerprint(): void
    {
        $base = $this->fp($this->jsonRequest('POST', '/orders', '{"a":1}'));

        $this->assertNotSame($base, $this->fp($this->jsonRequest('POST', '/orders', '{"a":2}')));
        $this->assertNotSame($base, $this->fp($this->jsonRequest('PUT', '/orders', '{"a":1}')));
        $this->assertNotSame($base, $this->fp($this->jsonRequest('POST', '/carts', '{"a":1}')));
        $this->assertNotSame($base, $this->fp($this->jsonRequest('POST', '/orders?x=1', '{"a":1}')));
    }

    public function test_query_order_does_not_matter(): void
    {
        $this->assertSame(
            $this->fp($this->jsonRequest('POST', '/o?a=1&b=2', '')),
            $this->fp($this->jsonRequest('POST', '/o?b=2&a=1', '')),
        );
    }

    public function test_form_field_order_does_not_matter(): void
    {
        $a = Request::create('/o', 'POST', ['x' => '1', 'y' => '2'], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'x=1&y=2');
        $b = Request::create('/o', 'POST', ['y' => '2', 'x' => '1'], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'y=2&x=1');

        $this->assertSame($this->fp($a), $this->fp($b));
    }

    public function test_uploaded_file_contents_change_the_fingerprint(): void
    {
        $one = UploadedFile::fake()->createWithContent('a.txt', 'one');
        $two = UploadedFile::fake()->createWithContent('a.txt', 'two');

        $this->assertNotSame(
            $this->fp(Request::create('/o', 'POST', [], [], ['f' => $one])),
            $this->fp(Request::create('/o', 'POST', [], [], ['f' => $two])),
        );
    }
}
