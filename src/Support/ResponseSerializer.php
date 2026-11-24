<?php

namespace IshanShah\SafeRetry\Support;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResponseSerializer
{
    /**
     * @param  array<int, string>  $excludedHeaders
     */
    public function __construct(protected array $excludedHeaders = []) {}

    /**
     * Streamed and file responses have no in-memory body we can capture.
     */
    public function canSerialize(SymfonyResponse $response): bool
    {
        return ! $response instanceof StreamedResponse
            && ! $response instanceof BinaryFileResponse
            && $response->getContent() !== false;
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(SymfonyResponse $response, string $fingerprint): array
    {
        $excluded = array_map('strtolower', $this->excludedHeaders);
        $headers = [];

        foreach ($response->headers->allPreserveCase() as $name => $values) {
            if (! in_array(strtolower($name), $excluded, true)) {
                $headers[$name] = $values;
            }
        }

        return [
            'type' => 'http',
            'fingerprint' => $fingerprint,
            'status' => $response->getStatusCode(),
            'headers' => $headers,
            'body' => base64_encode((string) $response->getContent()),
            'created_at' => now()->getTimestamp(),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function unserialize(array $record): Response
    {
        return new Response(
            base64_decode((string) ($record['body'] ?? ''), true) ?: '',
            (int) ($record['status'] ?? 200),
            (array) ($record['headers'] ?? []),
        );
    }
}
