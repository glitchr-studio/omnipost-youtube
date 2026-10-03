<?php

namespace Omnipost\YouTube\Tests;

use Omnipost\Exception\InvalidConfigException;
use Omnipost\Exception\InvalidPostException;
use Omnipost\Exception\ProviderException;
use Omnipost\Exception\UnauthorizedException;
use Omnipost\Model\Media;
use Omnipost\Model\Post;
use Omnipost\Model\PostKind;
use Omnipost\Model\PublicationState;
use Omnipost\Model\Variant;
use Omnipost\Platform;
use Omnipost\YouTube\YouTubeProvider;
use Omnipost\YouTube\YouTubeProviderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class YouTubeProviderTest extends TestCase
{
    /** @var list<array{method: string, url: string, path: string, query: array<string, string>, headers: array<string, string>, body: string}> */
    private array $calls = [];

    private int $tokens = 0;

    private string $video = '';

    protected function setUp(): void
    {
        $this->video = (string) tempnam(sys_get_temp_dir(), 'omnipost');
        file_put_contents($this->video, str_repeat("\0", 2048));
    }

    protected function tearDown(): void
    {
        @unlink($this->video);
    }

    private function provider(array $options = []): YouTubeProvider
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $headers = [];
            foreach ($options['headers'] ?? [] as $header) {
                [$name, $value] = explode(': ', $header, 2);
                $headers[strtolower($name)] = $value;
            }
            $body = $options['body'] ?? '';
            if (\is_resource($body)) {
                $body = stream_get_contents($body);
            } elseif (\is_callable($body)) {
                $chunks = '';
                while ('' !== $chunk = $body(8192)) {
                    $chunks .= $chunk;
                }
                $body = $chunks;
            }
            $this->calls[] = ['method' => $method, 'url' => $url, 'path' => $path, 'query' => $query, 'headers' => $headers, 'body' => (string) $body];

            if ('oauth2.googleapis.com' === parse_url($url, \PHP_URL_HOST)) {
                parse_str((string) $body, $form);

                return match ($form['grant_type'] ?? null) {
                    'refresh_token' => '1//revoked' === $form['refresh_token'] ? self::json(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400) : self::json(['access_token' => 'ya29.'.++$this->tokens, 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/youtube.upload', 'token_type' => 'Bearer']),
                    'authorization_code' => self::json(['access_token' => 'ya29.new', 'expires_in' => 3599, 'refresh_token' => '1//new', 'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly', 'token_type' => 'Bearer']),
                    default => self::json(['error' => 'unsupported_grant_type'], 400),
                };
            }
            if ('cdn.example' === parse_url($url, \PHP_URL_HOST)) {
                return new MockResponse(str_repeat('v', 1000), ['response_headers' => ['Content-Type' => 'video/mp4']]);
            }

            return match (true) {
                'POST' === $method && '/upload/youtube/v3/videos' === $path => new MockResponse('', ['response_headers' => ['Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=XYZ']]),
                'PUT' === $method && '/upload/youtube/v3/videos' === $path => self::json(self::uploaded('uploaded')),
                'GET' === $method && '/youtube/v3/channels' === $path => self::json(['items' => [self::channel()]]),
                'GET' === $method && '/youtube/v3/playlistItems' === $path => self::json(self::playlist()),
                'GET' === $method && '/youtube/v3/videos' === $path && 'contentDetails,statistics' === $query['part'] => self::json(['items' => [
                    ['id' => 'shortA', 'contentDetails' => ['duration' => 'PT58S'], 'statistics' => ['viewCount' => '1200', 'likeCount' => '80', 'commentCount' => '3']],
                    ['id' => 'longB', 'contentDetails' => ['duration' => 'PT1H2M3S'], 'statistics' => ['viewCount' => '50']],
                ]]),
                'GET' === $method && '/youtube/v3/videos' === $path => self::json(['items' => match ($query['id']) {
                    'processed' => [self::uploaded('processed')],
                    'uploading' => [self::uploaded('uploaded')],
                    'rejected' => [self::uploaded('rejected', ['rejectionReason' => 'duplicate'])],
                    'failed' => [self::uploaded('failed', ['failureReason' => 'codec'])],
                    'scheduled' => [self::uploaded('processed', ['privacyStatus' => 'private', 'publishAt' => '2099-01-01T18:00:00Z'])],
                    default => [],
                }]),
                'DELETE' === $method && '/youtube/v3/videos' === $path => new MockResponse('', ['http_code' => 204]),
                default => self::json(['error' => ['code' => 404, 'message' => 'Unknown '.$method.' '.$path, 'errors' => [['reason' => 'notFound']]]], 404),
            };
        });

        return (new YouTubeProviderFactory($http))->create($options + ['api_key' => 'AIza-key', 'channel_id' => 'UCabc', 'client_id' => 'cid.apps.googleusercontent.com', 'client_secret' => 'csecret', 'refresh_token' => '1//refresh']);
    }

    private static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status, 'response_headers' => ['Content-Type' => 'application/json']]);
    }

    private function short(?string $path = null): Post
    {
        return Post::reel(Media::video('https://cdn.example/strauss.mp4', $path ?? $this->video, 42.0, 1080, 1920), 'Strauss, tonight.', null, ['violin'])
            ->withVariant(new Variant(Platform::YOUTUBE, title: 'Strauss, Sonata'));
    }

    /** @return list<array{method: string, url: string, path: string, query: array<string, string>, headers: array<string, string>, body: string}> */
    private function calls(string $method, string $path): array
    {
        return array_values(array_filter($this->calls, static fn (array $c) => $c['method'] === $method && $c['path'] === $path));
    }

    public function testTheFeedIsTheUploadsPlaylistWithTheVideosDurations(): void
    {
        $feed = $this->provider()->feed(null, 2);

        self::assertSame('CAUQAA', $feed->next);
        [$short, $long] = $feed->items;
        self::assertSame('shortA', $short->id);
        self::assertSame(PostKind::REEL, $short->kind, 'three minutes at most');
        self::assertSame(58.0, $short->duration);
        self::assertSame(1200, $short->views);
        self::assertSame(80, $short->likes);
        self::assertSame(3, $short->comments);
        self::assertSame('https://www.youtube.com/watch?v=shortA', $short->permalink);
        self::assertSame('https://i.ytimg.com/vi/shortA/maxresdefault.jpg', $short->thumbnailUrl);
        self::assertSame('Strauss, Sonata', $short->caption);
        self::assertEquals(new \DateTimeImmutable('2026-09-30T18:00:00Z'), $short->publishedAt);
        self::assertSame(PostKind::VIDEO, $long->kind);
        self::assertSame(3723.0, $long->duration);
        self::assertSame('https://i.ytimg.com/vi/longB/hqdefault.jpg', $long->thumbnailUrl);

        $playlist = $this->calls('GET', '/youtube/v3/playlistItems')[0];
        self::assertSame(['part' => 'snippet,contentDetails', 'playlistId' => 'UUabc', 'maxResults' => '2'], $playlist['query']);
        self::assertSame('AIza-key', $playlist['headers']['x-goog-api-key'], 'read with the key');
        self::assertSame('shortA,longB', $this->calls('GET', '/youtube/v3/videos')[0]['query']['id'], 'the durations in one call');
        self::assertCount(0, $this->calls('POST', '/token'), 'no OAuth to read');
    }

    public function testIsoDurations(): void
    {
        self::assertSame(90.0, YouTubeProvider::seconds('PT1M30S'));
        self::assertSame(3600.0, YouTubeProvider::seconds('PT1H'));
        self::assertSame(86_401.0, YouTubeProvider::seconds('P1DT1S'));
        self::assertSame(0.0, YouTubeProvider::seconds('P0D'));
        self::assertNull(YouTubeProvider::seconds('soon'));
    }

    public function testTheAccountIsTheChannel(): void
    {
        $account = $this->provider()->account();

        self::assertSame('UCabc', $account->id);
        self::assertSame('@claireviolin', $account->username);
        self::assertSame('Claire', $account->name);
        self::assertSame('https://www.youtube.com/@claireviolin', $account->url);
        self::assertSame(4200, $account->followers);
        self::assertSame(36, $account->posts);
        self::assertSame(['part' => 'snippet,statistics,contentDetails', 'id' => 'UCabc'], $this->calls[0]['query']);
    }

    public function testWithoutAChannelIdTheChannelIsTheTokensOwn(): void
    {
        $this->provider(['channel_id' => null])->account();

        self::assertSame('POST', $this->calls[0]['method'], 'a token first');
        self::assertSame(['part' => 'snippet,statistics,contentDetails', 'mine' => 'true'], $this->calls[1]['query']);
        self::assertSame('Bearer ya29.1', $this->calls[1]['headers']['authorization']);
    }

    public function testAShortIsUploadedInTwoStepsWithAnAccessTokenDrawnOnce(): void
    {
        $youtube = $this->provider();
        $publication = $youtube->publish($this->short()->for(Platform::YOUTUBE));

        self::assertSame('vid123', $publication->id);
        self::assertSame(PublicationState::PROCESSING, $publication->state);
        self::assertSame('https://www.youtube.com/watch?v=vid123', $publication->permalink);

        self::assertCount(1, $this->calls('POST', '/token'));
        $start = $this->calls('POST', '/upload/youtube/v3/videos')[0];
        self::assertSame(['uploadType' => 'resumable', 'part' => 'snippet,status'], $start['query']);
        self::assertSame('Bearer ya29.1', $start['headers']['authorization']);
        self::assertSame('2048', $start['headers']['x-upload-content-length']);
        self::assertSame([
            'snippet' => ['title' => 'Strauss, Sonata', 'description' => "Strauss, tonight.\n\n#violin", 'tags' => ['violin']],
            'status' => ['privacyStatus' => 'private', 'selfDeclaredMadeForKids' => false],
        ], json_decode($start['body'], true));

        $put = $this->calls('PUT', '/upload/youtube/v3/videos')[0];
        self::assertSame('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=XYZ', $put['url'], 'to the Location answered');
        self::assertSame(2048, \strlen($put['body']), 'the file\'s bytes');
        self::assertSame('Bearer ya29.1', $put['headers']['authorization']);

        $youtube->delete('vid123');
        self::assertCount(1, $this->calls('POST', '/token'), 'the token kept for its hour');
        self::assertSame(['id' => 'vid123'], $this->calls('DELETE', '/youtube/v3/videos')[0]['query']);
    }

    public function testAVideoWithOnlyAUrlIsBroughtThenUploaded(): void
    {
        $post = Post::reel(Media::video('https://cdn.example/strauss.mp4', null, 42.0, 1080, 1920), '', 'Strauss')
            ->withVariant(new Variant(Platform::YOUTUBE, options: ['privacy' => 'unlisted']));

        $this->provider()->publish($post->for(Platform::YOUTUBE));

        $start = $this->calls('POST', '/upload/youtube/v3/videos')[0];
        self::assertSame('video/mp4', $start['headers']['x-upload-content-type']);
        self::assertSame('unlisted', json_decode($start['body'], true)['status']['privacyStatus'], 'the variant\'s privacy');
        self::assertSame(str_repeat('v', 1000), $this->calls('PUT', '/upload/youtube/v3/videos')[0]['body']);
    }

    public function testAScheduledVideoGoesUpPrivateWithItsTime(): void
    {
        $post = $this->short()->withScheduledAt(new \DateTimeImmutable('2099-01-01 19:00', new \DateTimeZone('Europe/Paris')));

        $this->provider(['privacy' => 'public'])->publish($post->for(Platform::YOUTUBE));

        $status = json_decode($this->calls('POST', '/upload/youtube/v3/videos')[0]['body'], true)['status'];
        self::assertSame(['privacyStatus' => 'private', 'selfDeclaredMadeForKids' => false, 'publishAt' => '2099-01-01T18:00:00Z'], $status);
    }

    public function testTheStatusIsTheUploadsStatus(): void
    {
        $youtube = $this->provider();

        $processed = $youtube->status('processed');
        self::assertTrue($processed->isPublished());
        self::assertEquals(new \DateTimeImmutable('2026-10-03T09:00:00Z'), $processed->publishedAt);
        self::assertSame(PublicationState::PROCESSING, $youtube->status('uploading')->state);
        self::assertSame(PublicationState::PENDING, $youtube->status('scheduled')->state, 'there, not out yet');
        $rejected = $youtube->status('rejected');
        self::assertTrue($rejected->isFailed());
        self::assertSame('duplicate', $rejected->error);
        self::assertSame('codec', $youtube->status('failed')->error);
        self::assertStringStartsWith('Bearer ', $this->calls('GET', '/youtube/v3/videos')[0]['headers']['authorization'] ?? '', 'as the channel: a key does not see a private video');

        $this->expectException(ProviderException::class);
        $youtube->status('gone');
    }

    public function testTheValidatorRefusesAShortWithoutATitleBeforeAnyRequest(): void
    {
        $youtube = $this->provider();
        try {
            $youtube->publish($this->short()->for(Platform::INSTAGRAM));
            self::fail('No title: refused.');
        } catch (InvalidPostException $e) {
            self::assertSame('youtube', $e->provider);
            self::assertSame(['title'], array_map(static fn ($v) => $v->path, $e->violations));
        }
        self::assertSame([], $this->calls);

        $long = Post::reel(Media::video('https://cdn.example/a.mp4', null, 200.0), '', 'Long');
        self::assertSame(['media[0].duration'], array_map(static fn ($v) => $v->path, $youtube->validate($long)), 'a Short is three minutes at most');
        self::assertSame([], $youtube->validate(new Post(PostKind::VIDEO, $long->media, '', 'Long')), 'a video is not');
        self::assertNotEmpty($youtube->validate(Post::image(Media::image('https://cdn.example/a.jpg'))));
    }

    public function testTokensRefreshAndOAuth(): void
    {
        $youtube = $this->provider();
        self::assertNull($youtube->token(), 'nothing drawn yet');

        $token = $youtube->refresh();
        self::assertSame('ya29.1', $token->accessToken);
        self::assertSame('1//refresh', $token->refreshToken);
        self::assertTrue($token->isExpiring(1));
        parse_str($this->calls[0]['body'], $form);
        self::assertSame(['client_id' => 'cid.apps.googleusercontent.com', 'client_secret' => 'csecret', 'grant_type' => 'refresh_token', 'refresh_token' => '1//refresh'], $form);
        self::assertSame('ya29.1', $youtube->token()->accessToken);

        $url = $youtube->authorizationUrl('https://site.example/admin/youtube', 'xyz');
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame('https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly', $query['scope']);
        self::assertSame(['offline', 'consent', 'code', 'xyz'], [$query['access_type'], $query['prompt'], $query['response_type'], $query['state']]);

        $exchanged = $youtube->exchange('4/code', 'https://site.example/admin/youtube');
        self::assertSame('1//new', $exchanged->refreshToken, 'the refresh token to keep');
        self::assertCount(2, $exchanged->scopes);
    }

    public function testARevokedGrantIsUnauthorized(): void
    {
        try {
            $this->provider(['refresh_token' => '1//revoked'])->publish($this->short()->for(Platform::YOUTUBE));
            self::fail('A revoked grant is refused.');
        } catch (UnauthorizedException $e) {
            self::assertSame('invalid_grant', $e->providerCode);
        }
    }

    public function testWithoutCredentialsOnlyWhatNeedsNoneWorks(): void
    {
        $youtube = (new YouTubeProviderFactory(new MockHttpClient()))->create();

        self::assertTrue($youtube->capabilities()->titleRequired);
        try {
            $youtube->publish($this->short()->for(Platform::YOUTUBE));
            self::fail('No OAuth: no upload.');
        } catch (InvalidConfigException $e) {
            self::assertStringContainsString('client_id, client_secret, refresh_token', $e->getMessage());
        }
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "youtube" provider needs: api_key');
        $youtube->feed();
    }

    /** @return array<string, mixed> */
    private static function channel(): array
    {
        return [
            'id' => 'UCabc',
            'snippet' => ['title' => 'Claire', 'customUrl' => '@claireviolin', 'thumbnails' => ['high' => ['url' => 'https://yt3.example/c.jpg']]],
            'statistics' => ['subscriberCount' => '4200', 'videoCount' => '36', 'hiddenSubscriberCount' => false],
            'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UUabc']],
        ];
    }

    /** @return array<string, mixed> */
    private static function playlist(): array
    {
        return [
            'nextPageToken' => 'CAUQAA',
            'items' => [
                ['snippet' => ['title' => 'Strauss, Sonata', 'publishedAt' => '2026-09-30T18:05:00Z', 'resourceId' => ['videoId' => 'shortA'], 'thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/vi/shortA/hqdefault.jpg'], 'maxres' => ['url' => 'https://i.ytimg.com/vi/shortA/maxresdefault.jpg']]], 'contentDetails' => ['videoId' => 'shortA', 'videoPublishedAt' => '2026-09-30T18:00:00Z']],
                ['snippet' => ['title' => 'The whole concert', 'publishedAt' => '2026-09-01T18:00:00Z', 'resourceId' => ['videoId' => 'longB'], 'thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/vi/longB/hqdefault.jpg']]], 'contentDetails' => ['videoId' => 'longB', 'videoPublishedAt' => '2026-09-01T18:00:00Z']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function uploaded(string $uploadStatus, array $status = []): array
    {
        return ['id' => 'vid123', 'snippet' => ['title' => 'Strauss, Sonata', 'publishedAt' => '2026-10-03T09:00:00Z'], 'status' => $status + ['uploadStatus' => $uploadStatus, 'privacyStatus' => 'public']];
    }
}
