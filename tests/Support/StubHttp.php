<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Support;

use Uengage\PlatformSdk\Http\HttpClient;
use Uengage\PlatformSdk\Http\HttpResponse;

/**
 * Shared test helper: a HttpClient whose backend is a programmable
 * queue of (status, body) responses, with per-call inspection of the
 * outgoing (method, url, headers, body) tuple.
 *
 * Tests register one or more canned responses with `pushResponse(...)`
 * and then assert against the captured calls via `getCalls()`.
 */
class StubHttp
{
    /** @var HttpClient */
    public $client;

    /** @var array<int, array{status:int,body:string,headers:array<string,string>}> */
    private $responses = [];

    /** @var array<int, array{method:string,url:string,headers:array<string,string>,body:?string}> */
    private $calls = [];

    public function __construct(string $baseUrl = 'https://api.test')
    {
        $this->client = new HttpClient($baseUrl);
        $self = $this;
        $this->client->setFetchOverrideForTesting(function ($method, $url, $headers, $body) use ($self) {
            $self->calls[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
            ];
            if (empty($self->responses)) {
                return new HttpResponse(500, [], '{"error":"no canned response queued"}');
            }
            $next = array_shift($self->responses);
            return new HttpResponse($next['status'], $next['headers'], $next['body']);
        });
    }

    /**
     * @param array<string, string> $headers
     */
    public function pushResponse(int $status, string $body, array $headers = []): void
    {
        $this->responses[] = ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    public function pushJson(int $status, $payload): void
    {
        $this->pushResponse($status, json_encode($payload), ['content-type' => 'application/json']);
    }

    /**
     * @return array<int, array{method:string,url:string,headers:array<string,string>,body:?string}>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    public function lastCall(): array
    {
        $n = count($this->calls);
        if ($n === 0) {
            throw new \RuntimeException('StubHttp: no calls captured yet');
        }
        return $this->calls[$n - 1];
    }
}
