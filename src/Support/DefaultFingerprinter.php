<?php

namespace IshanShah\SafeRetry\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use IshanShah\SafeRetry\Contracts\Fingerprinter;

/**
 * Hashes the method, path, query string, body and any configured headers.
 *
 * JSON and form bodies are compared by value, so key order and whitespace
 * don't matter. Fields listed in safe-retry.fingerprint.ignore are left out.
 */
class DefaultFingerprinter implements Fingerprinter
{
    /** @var array<int, string> */
    protected array $ignore;

    /** @var array<int, string> */
    protected array $headers;

    public function __construct(?Config $config = null)
    {
        $this->ignore = (array) ($config?->get('safe-retry.fingerprint.ignore') ?? []);
        $this->headers = (array) ($config?->get('safe-retry.fingerprint.headers') ?? []);
    }

    public function fingerprint(Request $request): string
    {
        $query = $request->query();
        $this->sortRecursive($query);

        $parts = [
            strtoupper($request->getMethod()),
            '/'.ltrim($request->path(), '/'),
            json_encode($query),
            hash('sha256', $this->body($request)),
            json_encode($this->headerValues($request)),
        ];

        return hash('sha256', implode('|', $parts));
    }

    protected function body(Request $request): string
    {
        if ($request->files->count() > 0 || $this->isForm($request)) {
            return $this->formBody($request);
        }

        $content = $request->getContent();

        if ($content === '') {
            return '';
        }

        if ($request->isJson()) {
            $decoded = json_decode($content, true);

            if (is_array($decoded)) {
                return $this->encode($decoded);
            }
        }

        return $content;
    }

    /**
     * Multipart boundaries change on every send, so the raw content can't be
     * compared. Hash the parsed fields and the uploaded files' contents.
     */
    protected function formBody(Request $request): string
    {
        $files = [];
        foreach ($request->allFiles() as $name => $file) {
            $files[$name] = $this->hashFiles($file);
        }

        return $this->encode(['input' => $request->request->all(), 'files' => $files]);
    }

    /**
     * @param  array<mixed>  $data
     */
    protected function encode(array $data): string
    {
        if (isset($data['input']) && is_array($data['input'])) {
            Arr::forget($data['input'], $this->ignore);
        } else {
            Arr::forget($data, $this->ignore);
        }

        $this->sortRecursive($data);

        return (string) json_encode($data);
    }

    /**
     * @return array<string, string|null>
     */
    protected function headerValues(Request $request): array
    {
        $values = [];

        foreach ($this->headers as $header) {
            $values[strtolower($header)] = $request->headers->get($header);
        }

        ksort($values);

        return $values;
    }

    protected function isForm(Request $request): bool
    {
        $type = (string) $request->headers->get('CONTENT_TYPE');

        return str_starts_with($type, 'application/x-www-form-urlencoded')
            || str_starts_with($type, 'multipart/form-data');
    }

    /**
     * @param  UploadedFile|array<mixed>  $file
     * @return string|array<mixed>
     */
    protected function hashFiles(UploadedFile|array $file): string|array
    {
        if (is_array($file)) {
            return array_map(fn ($f) => $this->hashFiles($f), $file);
        }

        return $file->getClientOriginalName().':'.(hash_file('sha256', $file->getRealPath()) ?: '');
    }

    /**
     * Sort maps by key so order doesn't matter. Lists keep their order.
     *
     * @param  array<mixed>  $array
     */
    protected function sortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }

        if (! array_is_list($array)) {
            ksort($array);
        }
    }
}
