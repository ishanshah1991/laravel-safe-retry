<?php

namespace IshanShah\SafeRetry\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use IshanShah\SafeRetry\Contracts\Fingerprinter;

class DefaultFingerprinter implements Fingerprinter
{
    public function fingerprint(Request $request): string
    {
        $query = $request->query();
        $this->sortRecursive($query);

        $parts = [
            strtoupper($request->getMethod()),
            '/'.ltrim($request->path(), '/'),
            json_encode($query),
            hash('sha256', $this->body($request)),
        ];

        return hash('sha256', implode('|', $parts));
    }

    protected function body(Request $request): string
    {
        // Form and multipart requests have no raw content we can rely on
        // (multipart boundaries change on every send), so hash the parsed input.
        $content = $request->getContent();

        if ($content !== '' && $request->files->count() === 0 && ! $this->isForm($request)) {
            return $content;
        }

        $input = $request->request->all();
        $this->sortRecursive($input);

        $files = [];
        foreach ($request->allFiles() as $name => $file) {
            $files[$name] = $this->hashFiles($file);
        }
        $this->sortRecursive($files);

        return json_encode([$input, $files]) ?: '';
    }

    protected function isForm(Request $request): bool
    {
        return str_starts_with((string) $request->headers->get('CONTENT_TYPE'), 'application/x-www-form-urlencoded');
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
     * @param  array<mixed>  $array
     */
    protected function sortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }

        ksort($array);
    }
}
