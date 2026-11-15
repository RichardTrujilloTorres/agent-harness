<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Security;

use InvalidArgumentException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * POSTs JSON with safe transport defaults and reads the response body with a size cap.
 *
 * - http and https URLs only
 * - TLS peer and host verification always on
 * - redirects not followed unless asked for
 * - the timeout applies to idle time and to the whole request
 */
final readonly class BoundedHttp
{
    public const int DEFAULT_MAX_RESPONSE_BYTES = 1_048_576;

    /** How many redirects are followed when following is enabled. */
    public const int MAX_REDIRECTS = 5;

    public function __construct(
        private HttpClientInterface $client,
    ) {}

    public static function isHttpUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) && is_string($host) && $host !== '';
    }

    /**
     * @param array<mixed> $payload
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     *
     * @throws BodyTooLargeException when the body is larger than $maxBytes
     * @throws TransportExceptionInterface when the server cannot be reached or the timeout is hit
     */
    public function postJson(string $url, array $payload, array $headers, int $timeoutMs, int $maxBytes, bool $followRedirects = false): array
    {
        if (!self::isHttpUrl($url)) {
            throw new InvalidArgumentException(sprintf('Only http:// and https:// URLs are allowed, got "%s".', $url));
        }
        $seconds = $timeoutMs / 1000;
        $response = $this->client->request('POST', $url, [
            'json' => $payload,
            'headers' => $headers,
            'timeout' => $seconds,
            'max_duration' => $seconds,
            'max_redirects' => $followRedirects ? self::MAX_REDIRECTS : 0,
            'verify_peer' => true,
            'verify_host' => true,
        ]);

        $status = $response->getStatusCode();
        $declared = $response->getHeaders(false)['content-length'][0] ?? null;
        if (is_numeric($declared) && (int) $declared > $maxBytes) {
            $response->cancel();

            throw new BodyTooLargeException($maxBytes);
        }

        $body = '';
        foreach ($this->client->stream($response) as $chunk) {
            $body .= $chunk->getContent();
            if (strlen($body) > $maxBytes) {
                $response->cancel();

                throw new BodyTooLargeException($maxBytes);
            }
        }

        return ['status' => $status, 'body' => $body];
    }
}
