<?php

namespace Omnipost\YouTube;

use Omnipost\Exception\InvalidConfigException;
use Omnipost\Exception\ProviderException;
use Omnipost\Exception\UnauthorizedException;
use Omnipost\Model\Token;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The YouTube Data API v3, thin. Two ways in: a key (X-Goog-Api-Key),
 * enough to read what is public; OAuth (a Bearer token drawn from the
 * refresh token, kept for its hour), for what is the channel's own -
 * uploading, deleting, "mine". Google's errors as Omnipost's: HTTP 401 and
 * a refused grant are an UnauthorizedException, the rest a
 * ProviderException with Google's reason (quotaExceeded, uploadLimitExceeded...).
 * Through the application's HTTP client when one is given.
 */
final class Api
{
    public const PROVIDER = 'youtube';
    public const BASE_URI = 'https://www.googleapis.com/youtube/v3';
    public const UPLOAD_URI = 'https://www.googleapis.com/upload/youtube/v3';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private readonly HttpClientInterface $http;

    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
        private ?string $refreshToken = null,
        private ?string $accessToken = null,
        private readonly string $baseUri = self::BASE_URI,
        private readonly string $uploadUri = self::UPLOAD_URI,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create();
    }

    public function clientId(): ?string
    {
        return $this->clientId;
    }

    /** Whether a call can go out as the channel: a token at hand, or what it takes to draw one. */
    public function canAuthorize(): bool
    {
        return null !== $this->accessToken || $this->canRefresh();
    }

    /**
     * @param array<string, scalar|null> $query
     * @param bool                       $oauth as the channel, even when a key would do
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], bool $oauth = false): array
    {
        return $this->decode($this->request('GET', $this->url($path), ['query' => self::filled($query), 'headers' => $this->auth($oauth)]));
    }

    /** @param array<string, scalar|null> $query */
    public function delete(string $path, array $query = []): void
    {
        $this->decode($this->request('DELETE', $this->url($path), ['query' => self::filled($query), 'headers' => $this->auth(true)]));
    }

    /**
     * A resumable upload opened: the video's metadata sent, the URL the
     * bytes go to answered.
     *
     * @param array<string, mixed> $metadata {snippet, status}
     */
    public function startUpload(array $metadata, string $mime, ?int $size): string
    {
        $response = $this->request('POST', rtrim($this->uploadUri, '/').'/videos', [
            'query' => ['uploadType' => 'resumable', 'part' => implode(',', array_keys($metadata))],
            'headers' => $this->auth(true) + ['Content-Type' => 'application/json; charset=UTF-8', 'X-Upload-Content-Type' => $mime] + (null !== $size ? ['X-Upload-Content-Length' => (string) $size] : []),
            'body' => json_encode($metadata, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        ]);
        $this->decode($response);
        try {
            $location = $response->getHeaders(false)['location'][0] ?? null;
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException(self::PROVIDER, 'YouTube could not be reached: '.$e->getMessage(), null, null, $e);
        }

        return $location ?? throw new ProviderException(self::PROVIDER, 'No upload URL in YouTube\'s answer.');
    }

    /**
     * The bytes, to the URL startUpload() gave.
     *
     * @param resource $stream
     *
     * @return array<string, mixed> the video
     */
    public function upload(string $location, $stream, string $mime, ?int $size): array
    {
        return $this->decode($this->request('PUT', $location, [
            'headers' => $this->auth(true) + ['Content-Type' => $mime] + (null !== $size ? ['Content-Length' => (string) $size] : []),
            'body' => $stream,
            // A video takes the time it takes: no idle limit shorter than a slow line, no overall one.
            'timeout' => 300,
            'max_duration' => 0,
        ]));
    }

    /**
     * A file elsewhere, brought to a temporary one - a video that only has
     * a URL - for upload() to send.
     *
     * @return array{resource, int, ?string} the stream rewound, its size, its content type
     */
    public function download(string $url): array
    {
        $tmp = tmpfile() ?: throw new ProviderException(self::PROVIDER, 'No temporary file to bring the video to.');
        try {
            $response = $this->http->request('GET', $url, ['timeout' => 300, 'max_duration' => 0]);
            if (200 !== $status = $response->getStatusCode()) {
                throw new ProviderException(self::PROVIDER, \sprintf('The video could not be fetched from its URL (HTTP %d).', $status), $status);
            }
            foreach ($this->http->stream($response) as $chunk) {
                fwrite($tmp, $chunk->getContent());
            }
            $type = $response->getHeaders(false)['content-type'][0] ?? null;
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException(self::PROVIDER, 'The video could not be fetched from its URL: '.$e->getMessage(), null, null, $e);
        }
        $size = (int) ftell($tmp);
        rewind($tmp);

        return [$tmp, $size, $type ? trim(explode(';', $type)[0]) : null];
    }

    /** The access token at hand, null before the first one is drawn. */
    public function token(): ?Token
    {
        return null !== $this->accessToken ? new Token($this->accessToken, $this->expiresAt, $this->refreshToken) : null;
    }

    /** A fresh access token from the refresh token. */
    public function refresh(): Token
    {
        if (!$this->canRefresh()) {
            throw new InvalidConfigException('The "youtube" provider needs: client_id, client_secret, refresh_token.');
        }

        return $this->grant(['grant_type' => 'refresh_token', 'refresh_token' => $this->refreshToken]);
    }

    /**
     * Google's token endpoint, the client's id and secret added; the
     * tokens answered are the ones the calls go out with from now on.
     *
     * @param array<string, string|null> $fields
     */
    public function grant(array $fields): Token
    {
        if (!$this->clientId || !$this->clientSecret) {
            throw new InvalidConfigException('The "youtube" provider needs: client_id, client_secret.');
        }
        $data = $this->decode($this->request('POST', self::TOKEN_URL, ['body' => ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret] + $fields]));
        if ('' === (string) ($data['access_token'] ?? '')) {
            throw new ProviderException(self::PROVIDER, 'No token in Google\'s answer.');
        }
        $this->accessToken = (string) $data['access_token'];
        $this->expiresAt = isset($data['expires_in']) ? new \DateTimeImmutable('+'.(int) $data['expires_in'].' seconds') : null;
        $this->refreshToken = $data['refresh_token'] ?? $this->refreshToken;

        return new Token($this->accessToken, $this->expiresAt, $this->refreshToken, isset($data['scope']) ? explode(' ', (string) $data['scope']) : []);
    }

    private function canRefresh(): bool
    {
        return $this->refreshToken && $this->clientId && $this->clientSecret;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUri, '/').'/'.ltrim($path, '/');
    }

    /** @return array<string, string> */
    private function auth(bool $oauth): array
    {
        if (!$oauth && $this->apiKey) {
            return ['X-Goog-Api-Key' => $this->apiKey];
        }
        if (!$this->canAuthorize()) {
            throw new InvalidConfigException($oauth
                ? 'The "youtube" provider needs: client_id, client_secret, refresh_token (or an access_token) - the channel\'s consent, for what a key cannot do.'
                : 'The "youtube" provider needs: api_key (or client_id, client_secret, refresh_token).');
        }
        // A minute's margin; a token given as an option has no known end: it is used until Google refuses it.
        if (null === $this->accessToken || (null !== $this->expiresAt && $this->expiresAt <= new \DateTimeImmutable('+60 seconds') && $this->canRefresh())) {
            $this->refresh();
        }

        return ['Authorization' => 'Bearer '.$this->accessToken];
    }

    /** @param array<string, mixed> $options */
    private function request(string $method, string $url, array $options): ResponseInterface
    {
        try {
            $response = $this->http->request($method, $url, $options);
            $response->getStatusCode();

            return $response;
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException(self::PROVIDER, 'YouTube could not be reached: '.$e->getMessage(), null, null, $e);
        }
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        try {
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException(self::PROVIDER, 'YouTube could not be reached: '.$e->getMessage(), null, null, $e);
        }
        $data = \is_array($data) ? $data : [];
        if ($status < 400 && !isset($data['error'])) {
            return $data;
        }

        // The API: {error: {code, message, errors: [{reason}]}}; the token endpoint: {error: "invalid_grant", error_description}.
        $error = $data['error'] ?? null;
        if (\is_array($error)) {
            $message = (string) ($error['message'] ?? \sprintf('HTTP %d.', $status));
            $reason = $error['errors'][0]['reason'] ?? $error['status'] ?? null;
        } else {
            $message = (string) ($data['error_description'] ?? $error ?? \sprintf('HTTP %d.', $status));
            $reason = \is_string($error) ? $error : null;
        }
        if (401 === $status || \in_array($reason, ['invalid_grant', 'invalid_client', 'unauthorized_client', 'authError'], true)) {
            throw new UnauthorizedException(self::PROVIDER, $message, $status, $reason);
        }

        throw new ProviderException(self::PROVIDER, $message, $status, $reason);
    }

    /**
     * @param array<string, scalar|null> $values
     *
     * @return array<string, scalar>
     */
    private static function filled(array $values): array
    {
        return array_map(static fn ($v) => \is_bool($v) ? ($v ? 'true' : 'false') : $v, array_filter($values, static fn ($v) => null !== $v && '' !== $v));
    }
}
