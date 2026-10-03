<?php

namespace Omnipost\YouTube;

use Omnipost\Auth\OAuthInterface;
use Omnipost\Auth\RefreshableInterface;
use Omnipost\Exception\InvalidConfigException;
use Omnipost\Exception\InvalidPostException;
use Omnipost\Exception\ProviderException;
use Omnipost\FeedInterface;
use Omnipost\Model\Account;
use Omnipost\Model\Capabilities;
use Omnipost\Model\Feed;
use Omnipost\Model\FeedItem;
use Omnipost\Model\MediaKind;
use Omnipost\Model\Post;
use Omnipost\Model\PostKind;
use Omnipost\Model\Publication;
use Omnipost\Model\PublicationState;
use Omnipost\Model\Token;
use Omnipost\Model\Violation;
use Omnipost\Platform;
use Omnipost\PublisherInterface;
use Omnipost\Validator;

/**
 * A YouTube channel: who it is, its uploads, and a video uploaded - a
 * Short when it is vertical and three minutes at most (YouTube decides, on
 * the file: a REEL is uploaded as any video is).
 *
 * Reading takes a key. Uploading and deleting take the channel's consent
 * (OAuth: authorizationUrl(), exchange(), then the refresh token as an
 * option). An upload is "private" unless the "privacy" option - or the
 * post's own "privacy" option - says otherwise: the videos of an API
 * project that has not passed Google's audit are locked private anyway,
 * and the channel's owner makes them public in YouTube Studio.
 */
final class YouTubeProvider implements PublisherInterface, FeedInterface, RefreshableInterface, OAuthInterface
{
    public const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    /** Uploading, and reading the channel as its owner. */
    public const SCOPES = ['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly'];

    /** What YouTube takes for a Short, in seconds. */
    public const SHORT_MAX_DURATION = 180.0;

    /** @var array<string, mixed>|null the channel, read once */
    private ?array $channel = null;

    public function __construct(
        private readonly Api $api,
        private readonly ?string $channelId = null,
        private readonly string $privacy = 'private',
        private readonly ?string $categoryId = null,
        private readonly Validator $validator = new Validator(),
    ) {
    }

    public function getName(): string
    {
        return 'youtube';
    }

    public function getPlatform(): Platform
    {
        return Platform::YOUTUBE;
    }

    /**
     * A title is required; the caption is the description. No bound on the
     * duration nor the ratio here, as they are the Short's alone, not the
     * video's: validate() checks the Short's three minutes.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            kinds: [PostKind::REEL, PostKind::VIDEO],
            captionMaxLength: 5000,
            maxTags: 30,
            titleRequired: true,
            titleMaxLength: 100,
            mediaMaxCount: 1,
            videoFormats: ['video/mp4', 'video/quicktime', 'video/webm', 'mp4', 'mov', 'webm'],
            scheduling: true,
        );
    }

    /**
     * The post against capabilities(), and against what they cannot say:
     * the media is a video, and a REEL - a Short - lasts three minutes at most.
     *
     * @return list<Violation>
     */
    public function validate(Post $post): array
    {
        $violations = $this->validator->validate($post, $this->capabilities());
        $media = $post->media[0] ?? null;
        if (null !== $media && MediaKind::VIDEO !== $media->kind) {
            $violations[] = new Violation('media[0]', 'A video is expected.');
        } elseif (null !== $media && PostKind::REEL === $post->kind && null !== $media->duration && $media->duration > self::SHORT_MAX_DURATION) {
            $violations[] = new Violation('media[0].duration', \sprintf('%.1f s, at most %.0f s for a Short.', $media->duration, self::SHORT_MAX_DURATION));
        }

        return $violations;
    }

    /**
     * The video uploaded in two calls (a resumable upload): its metadata,
     * then its bytes - from Media::$path, or brought from Media::$url when
     * there is no local file. A post scheduled goes up private, for YouTube
     * to publish at that time (PENDING until then).
     */
    public function publish(Post $post): Publication
    {
        if ($violations = $this->validate($post)) {
            throw new InvalidPostException($this->getName(), $violations);
        }
        if (!$this->api->canAuthorize()) {
            // Before the video is brought from its URL for nothing.
            throw new InvalidConfigException('The "youtube" provider needs: client_id, client_secret, refresh_token (or an access_token) to upload.');
        }
        $media = $post->media[0];
        $scheduled = null !== $post->scheduledAt && $post->scheduledAt > new \DateTimeImmutable();
        $metadata = [
            'snippet' => array_filter([
                'title' => (string) $post->title,
                'description' => $post->text(),
                'tags' => array_values(array_map(static fn (string $t) => ltrim($t, '#'), $post->tags)),
                'categoryId' => $post->options['category_id'] ?? $this->categoryId,
            ], static fn ($v) => null !== $v && [] !== $v),
            'status' => [
                'privacyStatus' => $scheduled ? 'private' : (string) ($post->options['privacy'] ?? $this->privacy),
                'selfDeclaredMadeForKids' => false,
            ] + ($scheduled ? ['publishAt' => $post->scheduledAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')] : []),
        ];

        if (null !== $media->path) {
            $stream = @fopen($media->path, 'r') ?: throw new ProviderException($this->getName(), \sprintf('The video\'s file "%s" cannot be read.', $media->path));
            $size = filesize($media->path) ?: $media->size;
            $mime = $media->mime;
        } else {
            [$stream, $size, $mime] = $this->api->download($media->url);
            $mime = $media->mime ?? $mime;
        }
        $mime = $mime && str_starts_with($mime, 'video/') ? $mime : 'video/*';
        try {
            $video = $this->api->upload($this->api->startUpload($metadata, $mime, $size), $stream, $mime, $size);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        return self::publication($video);
    }

    public function status(string $publicationId): Publication
    {
        // As the channel when it can: a key does not see a private video.
        $videos = $this->api->get('videos', ['part' => 'status,snippet', 'id' => $publicationId], $this->api->canAuthorize());
        $video = $videos['items'][0] ?? throw new ProviderException($this->getName(), \sprintf('No video "%s" (deleted, or private and read with a key).', $publicationId), 404, 'videoNotFound');

        return self::publication($video);
    }

    public function delete(string $publicationId): void
    {
        $this->api->delete('videos', ['id' => $publicationId]);
    }

    public function account(): Account
    {
        $channel = $this->channel();
        $snippet = $channel['snippet'] ?? [];
        $statistics = $channel['statistics'] ?? [];
        $handle = $snippet['customUrl'] ?? null;

        return new Account(
            Platform::YOUTUBE,
            (string) $channel['id'],
            (string) ($handle ?? $snippet['title'] ?? $channel['id']),
            $snippet['title'] ?? null,
            'https://www.youtube.com/'.($handle ?: 'channel/'.$channel['id']),
            self::thumbnail($snippet),
            // Hidden by the channel: not known.
            ($statistics['hiddenSubscriberCount'] ?? false) || !isset($statistics['subscriberCount']) ? null : (int) $statistics['subscriberCount'],
            isset($statistics['videoCount']) ? (int) $statistics['videoCount'] : null,
        );
    }

    /**
     * The channel's uploads, newest first: the uploads playlist, then the
     * videos themselves for what the playlist does not say (duration,
     * views). A video of three minutes at most is given as a REEL - the
     * API does not tell a Short, nor the ratio - any longer one as a VIDEO.
     * The item's url is the player's (to embed); its caption, the title.
     */
    public function feed(?string $cursor = null, int $limit = 25): Feed
    {
        $uploads = $this->channel()['contentDetails']['relatedPlaylists']['uploads'] ?? throw new ProviderException($this->getName(), 'The channel has no uploads playlist.');
        $page = $this->api->get('playlistItems', ['part' => 'snippet,contentDetails', 'playlistId' => $uploads, 'maxResults' => max(1, min(50, $limit)), 'pageToken' => $cursor], $this->mine());
        $entries = array_values($page['items'] ?? []);
        $ids = array_values(array_filter(array_map(static fn (array $e) => $e['contentDetails']['videoId'] ?? $e['snippet']['resourceId']['videoId'] ?? null, $entries)));

        $videos = [];
        if ($ids) {
            foreach ($this->api->get('videos', ['part' => 'contentDetails,statistics', 'id' => implode(',', $ids)], $this->mine())['items'] ?? [] as $video) {
                $videos[$video['id']] = $video;
            }
        }

        $items = [];
        foreach ($entries as $entry) {
            $id = $entry['contentDetails']['videoId'] ?? $entry['snippet']['resourceId']['videoId'] ?? null;
            if (null === $id) {
                continue;
            }
            $snippet = $entry['snippet'] ?? [];
            $video = $videos[$id] ?? [];
            $duration = isset($video['contentDetails']['duration']) ? self::seconds($video['contentDetails']['duration']) : null;
            $statistics = $video['statistics'] ?? [];
            $items[] = new FeedItem(
                $id,
                null !== $duration && $duration <= self::SHORT_MAX_DURATION ? PostKind::REEL : PostKind::VIDEO,
                'https://www.youtube.com/watch?v='.$id,
                'https://www.youtube.com/embed/'.$id,
                self::thumbnail($snippet),
                $snippet['title'] ?? null,
                self::date($entry['contentDetails']['videoPublishedAt'] ?? $snippet['publishedAt'] ?? null),
                $duration,
                [],
                isset($statistics['likeCount']) ? (int) $statistics['likeCount'] : null,
                isset($statistics['commentCount']) ? (int) $statistics['commentCount'] : null,
                isset($statistics['viewCount']) ? (int) $statistics['viewCount'] : null,
                ['playlistItem' => $entry, 'video' => $video],
            );
        }

        return new Feed($items, $page['nextPageToken'] ?? null);
    }

    /** The access token at hand - an hour's life - with the refresh token it was drawn from. */
    public function token(): ?Token
    {
        return $this->api->token();
    }

    public function refresh(): Token
    {
        return $this->api->refresh();
    }

    /** Offline access, consent asked again: that is what makes Google give a refresh token. */
    public function authorizationUrl(string $redirectUri, string $state, array $scopes = []): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->api->clientId() ?? throw new InvalidConfigException('The "youtube" provider needs: client_id.'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes ?: self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /** The code for an access token and the refresh token to keep. */
    public function exchange(string $code, string $redirectUri): Token
    {
        return $this->api->grant(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
    }

    /** ISO 8601, as YouTube writes a duration: "PT1M30S" is 90.0. */
    public static function seconds(string $duration): ?float
    {
        if (!preg_match('/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', $duration, $m)) {
            return null;
        }

        return (int) ($m[1] ?? 0) * 604800 + (int) ($m[2] ?? 0) * 86400 + (int) ($m[3] ?? 0) * 3600 + (int) ($m[4] ?? 0) * 60 + (float) ($m[5] ?? 0);
    }

    /** Without a channel id, the channel is the token's own. */
    private function mine(): bool
    {
        return null === $this->channelId;
    }

    /** @return array<string, mixed> */
    private function channel(): array
    {
        if (null !== $this->channel) {
            return $this->channel;
        }
        if ($this->mine() && !$this->api->canAuthorize()) {
            throw new InvalidConfigException('The "youtube" provider needs: api_key and channel_id to read a channel (or client_id, client_secret, refresh_token to read its own).');
        }
        $channels = $this->api->get('channels', ['part' => 'snippet,statistics,contentDetails'] + ($this->mine() ? ['mine' => true] : ['id' => $this->channelId]), $this->mine());

        return $this->channel = $channels['items'][0] ?? throw new ProviderException($this->getName(), $this->mine() ? 'The account has no channel.' : \sprintf('No channel "%s".', $this->channelId), 404, 'channelNotFound');
    }

    /** @param array<string, mixed> $video */
    private static function publication(array $video): Publication
    {
        $id = (string) $video['id'];
        $status = $video['status'] ?? [];
        $permalink = 'https://www.youtube.com/watch?v='.$id;
        $publishAt = self::date($status['publishAt'] ?? null);

        return match ($status['uploadStatus'] ?? null) {
            'failed', 'rejected', 'deleted' => new Publication(Platform::YOUTUBE, $id, PublicationState::FAILED, $permalink, (string) ($status['failureReason'] ?? $status['rejectionReason'] ?? $status['uploadStatus']), raw: $video),
            'processed' => null !== $publishAt && $publishAt > new \DateTimeImmutable() && 'private' === ($status['privacyStatus'] ?? null)
                // Scheduled: there, and not out yet.
                ? new Publication(Platform::YOUTUBE, $id, PublicationState::PENDING, $permalink, raw: $video)
                : new Publication(Platform::YOUTUBE, $id, PublicationState::PUBLISHED, $permalink, null, self::date($video['snippet']['publishedAt'] ?? null), $video),
            default => new Publication(Platform::YOUTUBE, $id, PublicationState::PROCESSING, $permalink, raw: $video),
        };
    }

    /** @param array<string, mixed> $snippet */
    private static function thumbnail(array $snippet): ?string
    {
        $thumbnails = $snippet['thumbnails'] ?? [];
        foreach (['maxres', 'high', 'standard', 'medium', 'default'] as $size) {
            if (isset($thumbnails[$size]['url'])) {
                return $thumbnails[$size]['url'];
            }
        }

        return null;
    }

    private static function date(?string $date): ?\DateTimeImmutable
    {
        try {
            return $date ? new \DateTimeImmutable($date) : null;
        } catch (\Exception) {
            return null;
        }
    }
}
