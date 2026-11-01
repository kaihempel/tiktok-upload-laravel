<?php

declare(strict_types=1);

namespace Kuweh\TikTokUpload\Http;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Kuweh\TikTokUpload\Exceptions\ErrorMapper;
use Kuweh\TikTokUpload\Exceptions\TikTokException;
use Kuweh\TikTokUpload\Exceptions\UnexpectedResponseException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Low level transport: sends requests to the TikTok Open API, unwraps the
 * {data, error} envelope and maps errors onto exceptions. Has no knowledge of
 * stored accounts (see TikTokHttpClient for that).
 */
class ApiTransport
{
    private const array SENSITIVE_HEADERS = ['authorization', 'cookie', 'set-cookie', 'proxy-authorization'];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl = 'https://open.tiktokapis.com',
        private readonly ?LoggerInterface $logger = new NullLogger,
        private readonly int $timeout = 30,
    ) {}

    /**
     * Sends a JSON request with a bearer token and returns the "data" part.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function json(string $method, string $path, string $accessToken, array $payload = [], array $query = []): array
    {
        $request = $this->request()
            ->withToken($accessToken)
            ->asJson();

        $url = $this->url($path, $query);

        $response = strtoupper($method) === 'GET'
            ? $request->get($url)
            : $request->send(strtoupper($method), $url, ['json' => $payload === [] ? new \stdClass : $payload]);

        return $this->unwrap($response, $method, $url, ['Authorization' => 'Bearer '.$accessToken]);
    }

    /**
     * Sends an application/x-www-form-urlencoded request to an OAuth endpoint.
     * OAuth endpoints answer with a flat body instead of the {data, error} envelope.
     *
     * @param  array<string, scalar|null>  $form
     * @return array<string, mixed>
     */
    public function form(string $path, array $form): array
    {
        $url = $this->url($path);
        $response = $this->request()->asForm()->post($url, $form);
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        $errorCode = $body['error'] ?? null;

        // Some OAuth errors are delivered in the API envelope format.
        if (is_array($errorCode)) {
            return $this->unwrap($response, 'POST', $url);
        }

        if ($response->successful() && ($errorCode === null || $errorCode === '')) {
            return $body;
        }

        $code = is_string($errorCode) && $errorCode !== '' ? $errorCode : 'http_'.$response->status();

        throw $this->fail(
            $code,
            (string) ($body['error_description'] ?? $body['message'] ?? ''),
            isset($body['log_id']) ? (string) $body['log_id'] : null,
            $response,
            'POST',
            $url,
        );
    }

    /**
     * Unwraps the TikTok API envelope. A request is only successful when error.code === "ok".
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function unwrap(Response $response, string $method = 'POST', string $url = '', array $headers = []): array
    {
        $body = $response->json();
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = (string) ($error['code'] ?? '');
        $logId = isset($error['log_id']) && $error['log_id'] !== '' ? (string) $error['log_id'] : null;

        if ($response->successful() && $code === 'ok') {
            $this->logger?->debug('TikTok API request succeeded.', [
                'method' => strtoupper($method),
                'url' => $url,
                'log_id' => $logId,
            ]);

            $data = is_array($body) ? ($body['data'] ?? []) : [];

            return is_array($data) ? $data : [];
        }

        if ($code === '' || $code === 'ok') {
            if ($response->successful()) {
                throw new UnexpectedResponseException('TikTok returned a response without a valid error object.', 'invalid_response', $logId, $response->status());
            }

            $code = 'http_'.$response->status();
        }

        throw $this->fail($code, (string) ($error['message'] ?? ''), $logId, $response, $method, $url, $headers);
    }

    public function request(): PendingRequest
    {
        return $this->http->acceptJson()->timeout($this->timeout);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public static function redactHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), self::SENSITIVE_HEADERS, true)) {
                $headers[$name] = '***';
            }
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function url(string $path, array $query = []): string
    {
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function fail(string $code, string $message, ?string $logId, Response $response, string $method, string $url, array $headers = []): TikTokException
    {
        $retryAfter = $response->header('Retry-After');

        $exception = ErrorMapper::map(
            $code,
            $message,
            $logId,
            $response->status(),
            is_numeric($retryAfter) ? (int) $retryAfter : null,
        );

        $this->logger?->warning('TikTok API request failed.', [
            'method' => strtoupper($method),
            'url' => $url,
            'status' => $response->status(),
            'code' => $code,
            'message' => $message,
            'log_id' => $logId,
            'headers' => self::redactHeaders($headers),
        ]);

        return $exception;
    }
}
