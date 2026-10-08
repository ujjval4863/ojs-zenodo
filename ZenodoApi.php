<?php

namespace APP\plugins\generic\zenodo;

class ZenodoApi
{
    public function __construct(
        private string $baseUrl,
        private string $token
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    /**
     * Create a draft using Zenodo's current InvenioRDM Records API.
     */
    public function createDraft(array $payload): array
    {
        return $this->request('POST', '/records', ['json' => $payload]);
    }

    public function getDraft(string $id): array
    {
        return $this->request('GET', '/records/' . rawurlencode($id) . '/draft');
    }

    public function getRecord(string $id): array
    {
        return $this->request('GET', '/records/' . rawurlencode($id));
    }

    public function updateDraft(string $id, array $payload): array
    {
        return $this->request('PUT', '/records/' . rawurlencode($id) . '/draft', ['json' => $payload]);
    }

    public function reserveDoi(string $id, ?string $url = null): array
    {
        return $this->request('POST', $url ?: '/records/' . rawurlencode($id) . '/draft/pids/doi');
    }

    public function publishDraft(string $id, ?string $url = null): array
    {
        return $this->request('POST', $url ?: '/records/' . rawurlencode($id) . '/draft/actions/publish');
    }

    public function listDraftFiles(string $id, ?string $url = null): array
    {
        return $this->request('GET', $url ?: '/records/' . rawurlencode($id) . '/draft/files');
    }

    public function initializeDraftFile(string $id, string $filename, ?string $filesUrl = null): array
    {
        $result = $this->request('POST', $filesUrl ?: '/records/' . rawurlencode($id) . '/draft/files', [
            'json' => [['key' => $filename]],
        ]);

        $entries = $result['entries'] ?? [];
        if (!is_array($entries) || empty($entries[0]) || !is_array($entries[0])) {
            throw new \RuntimeException('Zenodo did not return an initialized file entry.');
        }
        return $entries[0];
    }

    public function deleteDraftFile(string $id, string $filename): void
    {
        $this->request(
            'DELETE',
            '/records/' . rawurlencode($id) . '/draft/files/' . rawurlencode($filename)
        );
    }

    public function uploadDraftFileContent(string $contentUrl, string $path): array
    {
        return $this->uploadBinary($contentUrl, $path, 'application/octet-stream');
    }

    public function commitDraftFile(string $commitUrl): array
    {
        return $this->request('POST', $commitUrl);
    }

    /**
     * Compatibility fallback for Zenodo's legacy bucket upload API.
     * This is only used if the current staged-file flow cannot persist a file.
     */
    public function getLegacyDeposition(string $id): array
    {
        return $this->request('GET', '/deposit/depositions/' . rawurlencode($id));
    }

    public function uploadToLegacyBucket(string $bucketUrl, string $path, string $filename): array
    {
        return $this->uploadBinary(
            rtrim($bucketUrl, '/') . '/' . rawurlencode($filename),
            $path,
            'application/pdf'
        );
    }

    public function testConnection(): array
    {
        return $this->request('GET', '/records?status=draft&page=1&size=1');
    }

    private function uploadBinary(string $url, string $path, string $contentType): array
    {
        if (!is_readable($path)) {
            throw new \RuntimeException('OJS could not read the article PDF.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('OJS could not open the article PDF.');
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            fclose($handle);
            throw new \RuntimeException('The OJS article PDF is empty or its size could not be determined.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $handle,
            CURLOPT_INFILESIZE => $size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 900,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
                'Content-Type: ' . $contentType,
            ],
            CURLOPT_USERAGENT => 'OJS-Zenodo-Plugin/1.6.2',
        ]);

        try {
            return $this->finish($ch);
        } finally {
            fclose($handle);
        }
    }

    private function request(string $method, string $pathOrUrl, array $options = []): array
    {
        $url = preg_match('#^https?://#i', $pathOrUrl)
            ? $pathOrUrl
            : $this->baseUrl . '/' . ltrim($pathOrUrl, '/');

        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];

        $curlOptions = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'OJS-Zenodo-Plugin/1.6.2',
        ];

        if (array_key_exists('json', $options)) {
            $body = json_encode(
                $options['json'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $headers[] = 'Content-Type: application/json';
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $curlOptions);
        return $this->finish($ch);
    }

    private function finish($ch): array
    {
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new ZenodoApiException('Zenodo connection failed: ' . $curlError, 0);
        }

        if ($response === '' && $status >= 200 && $status < 300) {
            return [];
        }

        $decoded = json_decode($response, true);

        if ($status < 200 || $status >= 300) {
            $message = 'Request failed.';
            if (is_array($decoded)) {
                $baseMessage = !empty($decoded['message']) && is_scalar($decoded['message'])
                    ? trim((string) $decoded['message'])
                    : '';

                $details = $this->extractErrorDetails($decoded['errors'] ?? []);
                if (!$details && isset($decoded['details'])) {
                    $details = $this->extractErrorDetails($decoded['details']);
                }

                if ($details) {
                    $message = ($baseMessage !== '' ? $baseMessage . ' — ' : '') . implode('; ', $details);
                } elseif ($baseMessage !== '') {
                    $message = $baseMessage;
                } else {
                    $compact = json_encode(
                        $decoded,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    );
                    if (is_string($compact) && $compact !== '') {
                        $message = mb_substr($compact, 0, 2000);
                    }
                }
            } elseif (trim((string) $response) !== '') {
                $message = trim(strip_tags((string) $response));
            }

            throw new ZenodoApiException(
                'Zenodo API returned HTTP ' . $status . ': ' . $message,
                $status
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Return validation details exposed on a draft or error response.
     */
    public function getValidationErrorDetails(array $payload): array
    {
        $details = $this->extractErrorDetails($payload['errors'] ?? []);
        if (!$details && isset($payload['details'])) {
            $details = $this->extractErrorDetails($payload['details']);
        }
        return $details;
    }

    /**
     * Flatten InvenioRDM/Zenodo validation errors.
     *
     * Current Zenodo responses commonly use:
     *   {"errors":[{"field":"metadata.foo","messages":["reason"]}]}
     * Older responses may instead use a singular "message" key or an
     * associative field => messages map.
     */
    private function extractErrorDetails($errors, string $prefix = ''): array
    {
        $details = [];

        if (is_scalar($errors)) {
            $value = trim((string) $errors);
            return $value !== '' ? [($prefix !== '' ? $prefix . ': ' : '') . $value] : [];
        }

        if (!is_array($errors)) {
            return [];
        }

        if (isset($errors['field']) || isset($errors['message']) || isset($errors['messages'])) {
            $field = isset($errors['field']) && is_scalar($errors['field'])
                ? trim((string) $errors['field'])
                : $prefix;

            if (isset($errors['message']) && is_scalar($errors['message'])) {
                $value = trim((string) $errors['message']);
                if ($value !== '') {
                    $details[] = ($field !== '' ? $field . ': ' : '') . $value;
                }
            }

            if (isset($errors['messages'])) {
                foreach ($this->extractErrorDetails($errors['messages']) as $value) {
                    $details[] = $field !== '' && !str_starts_with($value, $field . ': ')
                        ? $field . ': ' . $value
                        : $value;
                }
            }

            return array_values(array_unique(array_filter($details)));
        }

        foreach ($errors as $key => $value) {
            $childPrefix = $prefix;
            if (!is_int($key) && is_string($key) && $key !== '') {
                $childPrefix = $prefix !== '' ? $prefix . '.' . $key : $key;
            }
            foreach ($this->extractErrorDetails($value, $childPrefix) as $detail) {
                $details[] = $detail;
            }
        }

        return array_values(array_unique(array_filter($details)));
    }
}

