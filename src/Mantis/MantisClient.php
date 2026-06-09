<?php

declare(strict_types=1);

namespace MantisMcp\Mantis;

use MantisMcp\Support\Logger;

/**
 * Lightweight, robust client for the MantisBT REST API (/api/rest).
 *
 * Authentication uses the personal API token in the "Authorization" header
 * (Mantis expects the raw token, without a "Bearer" prefix).
 *
 * Properties:
 * - curl-based, with configurable timeouts.
 * - TLS verification enabled by default (optional CA bundle).
 * - Uniform error handling via MantisException.
 * - No output of any kind; everything goes through the logger.
 */
final class MantisClient
{
    private string $apiBase;

    public function __construct(
        string $baseUrl,
        private readonly string $apiToken,
        private readonly Logger $logger,
        private readonly bool $verifyTls = true,
        private readonly ?string $caBundle = null,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 30,
    ) {
        $this->apiBase = rtrim($baseUrl, '/') . '/api/rest';
    }

    /**
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query, null);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, [], $body);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function patch(string $path, array $body): array
    {
        return $this->request('PATCH', $path, [], $body);
    }

    /** @return array<string,mixed> */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path, [], null);
    }

    /**
     * Performs an HTTP request and returns the decoded JSON response.
     *
     * @param array<string,scalar> $query
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     *
     * @throws MantisException
     */
    private function request(string $method, string $path, array $query, ?array $body): array
    {
        $url = $this->apiBase . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new MantisException('Failed to initialize curl');
        }

        $headers = [
            'Authorization: ' . $this->apiToken,
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ];

        if ($this->caBundle !== null && $this->caBundle !== '') {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new MantisException('Failed to serialize request body');
            }
            $options[CURLOPT_POSTFIELDS] = $json;
            $headers[] = 'Content-Type: application/json';
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $start = microtime(true);
        $responseBody = curl_exec($ch);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        if ($responseBody === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            $this->logger->error('Mantis transport error', [
                'method' => $method,
                'path' => $path,
                'curl_errno' => $errno,
                'curl_error' => $error,
                'duration_ms' => $durationMs,
            ]);
            throw new MantisException("Connection to Mantis failed ({$error})");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $this->logger->debug('Mantis response', [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'duration_ms' => $durationMs,
        ]);

        return $this->parseResponse($method, $path, $status, (string) $responseBody);
    }

    /**
     * @return array<string,mixed>
     * @throws MantisException
     */
    private function parseResponse(string $method, string $path, int $status, string $rawBody): array
    {
        // 204 / empty body: successful call without content.
        if ($rawBody === '') {
            if ($status >= 200 && $status < 300) {
                return [];
            }
            throw new MantisException("Mantis responded with HTTP {$status}", $status);
        }

        $decoded = json_decode($rawBody, true);

        if ($status < 200 || $status >= 300) {
            $detail = is_array($decoded) && isset($decoded['message'])
                ? (string) $decoded['message']
                : substr($rawBody, 0, 500);

            $this->logger->warning('Mantis error response', [
                'method' => $method,
                'path' => $path,
                'status' => $status,
                'detail' => $detail,
            ]);

            throw new MantisException(
                $this->humanStatus($status, $detail),
                $status,
                $detail,
            );
        }

        if (!is_array($decoded)) {
            throw new MantisException('Unexpected response format from Mantis (not a JSON object)', $status);
        }

        return $decoded;
    }

    private function humanStatus(int $status, string $detail): string
    {
        return match (true) {
            $status === 401 => 'Mantis rejected the authentication (check the API token).',
            $status === 403 => 'Not authorized for this Mantis action.',
            $status === 404 => 'Requested Mantis resource not found.',
            $status === 422 => "Mantis rejected the data: {$detail}",
            $status >= 500 => "Mantis server error (HTTP {$status}).",
            default => "Mantis error (HTTP {$status}): {$detail}",
        };
    }
}
