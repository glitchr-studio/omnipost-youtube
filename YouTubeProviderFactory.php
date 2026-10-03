<?php

namespace Omnipost\YouTube;

use Omnipost\Config;
use Omnipost\ProviderFactory;
use Omnipost\ProviderInterface;

/**
 * YouTube, through the Data API v3: the channel and its videos read with a
 * key, videos and Shorts uploaded through OAuth.
 *
 *   options:
 *     api_key: '%env(default::YOUTUBE_API_KEY)%'             # reading: the channel, its videos
 *     channel_id: '%env(default::YOUTUBE_CHANNEL_ID)%'       # UC...; without it, the channel the OAuth token belongs to
 *     client_id: '%env(default::YOUTUBE_CLIENT_ID)%'         # the Google OAuth client: uploading, deleting, connecting
 *     client_secret: '%env(default::YOUTUBE_CLIENT_SECRET)%'
 *     refresh_token: '%env(default::YOUTUBE_REFRESH_TOKEN)%' # what exchange() gave: access tokens are drawn from it
 *     access_token: ~                                        # or a token at hand (an hour's life)
 *     privacy: private                                       # private, unlisted, public: what an upload is set to
 *     category_id: ~                                         # "10" is Music; YouTube's default otherwise
 *     base_uri: https://www.googleapis.com/youtube/v3
 *     upload_uri: https://www.googleapis.com/upload/youtube/v3
 *
 * Nothing is required to build the provider: capabilities() and
 * authorizationUrl() need no credential. A call that needs one and finds
 * none throws InvalidConfigException.
 */
final class YouTubeProviderFactory extends ProviderFactory
{
    protected function populate(Config $config): void
    {
        $config->defaults([
            'omnipost.factory_name' => 'youtube',
            'omnipost.required_options' => [],
            'api_key' => null,
            'channel_id' => null,
            'client_id' => null,
            'client_secret' => null,
            'refresh_token' => null,
            'access_token' => null,
            'privacy' => 'private',
            'category_id' => null,
            'base_uri' => Api::BASE_URI,
            'upload_uri' => Api::UPLOAD_URI,
        ]);
    }

    protected function build(Config $config): ProviderInterface
    {
        $string = static fn (string $key): ?string => $config[$key] ? (string) $config[$key] : null;

        return new YouTubeProvider(
            new Api($string('api_key'), $string('client_id'), $string('client_secret'), $string('refresh_token'), $string('access_token'), (string) $config['base_uri'], (string) $config['upload_uri'], $this->http),
            $string('channel_id'),
            (string) $config['privacy'],
            $string('category_id'),
        );
    }
}
