# omnipost/youtube

YouTube for [glitchr/omnipost](https://github.com/glitchr-studio/omnipost): the channel and its
videos read with an API key, videos and Shorts uploaded through OAuth - the YouTube Data API v3.

```php
$youtube = (new YouTubeProviderFactory($http))->create(['api_key' => '...', 'channel_id' => 'UC...']);   // $http: the application's HTTP client; none given, the factory makes its own
```

No framework needed: the package requires `glitchr/omnipost` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnipost:
    providers:
        youtube:
            factory: youtube
            options:
                api_key: '%env(default::YOUTUBE_API_KEY)%'             # reading: the channel, its videos
                channel_id: '%env(default::YOUTUBE_CHANNEL_ID)%'       # UC...; without it, the channel the OAuth token belongs to
                client_id: '%env(default::YOUTUBE_CLIENT_ID)%'         # the OAuth client: uploading, deleting
                client_secret: '%env(default::YOUTUBE_CLIENT_SECRET)%'
                refresh_token: '%env(default::YOUTUBE_REFRESH_TOKEN)%' # what exchange() gave
                privacy: private                                       # private, unlisted, public
                category_id: ~                                         # "10" is Music
```

No option is required to build the provider: `capabilities()` and `authorizationUrl()` need no
credential, and a call that needs one and finds none throws `InvalidConfigException`.

## Reading, with a key

`account()`: the channel (its handle, title, picture, subscribers unless hidden, videos count).
`feed($pageToken, $limit)`: the channel's uploads playlist, newest first, then the videos for
their durations (ISO 8601, `PT1M30S`) and views. The API does not tell a Short nor the video's
ratio: a video of three minutes at most is given as REEL, a longer one as VIDEO. `permalink` is the
watch page, `url` the embed player, `thumbnailUrl` the largest thumbnail.

## Uploading, with OAuth

```php
$publication = $youtube->publish($post->for(Platform::YOUTUBE));   // PROCESSING, the video's id, its watch URL
$youtube->status($publication->id);                                // PUBLISHED once processed; FAILED with the reason
$youtube->delete($publication->id);
```

`publish()` checks the post (a title is required, 100 characters at most; the caption - with its
hashtags - is the description, 5 000 at most; a REEL lasts three minutes at most), then uploads in
two calls (a *resumable* upload): the metadata, then the bytes - from `Media::$path`, or brought
from `Media::$url` to a temporary file when there is no local one. A Short is a video like any
other: vertical (or square) and three minutes at most, YouTube files it as a Short itself.

A post scheduled (`withScheduledAt()`) goes up private with its `publishAt`, and YouTube publishes
it then (PENDING until that time). The access token is drawn from the refresh token and kept for
its hour.

**Uploads are private until the project is audited.** The videos uploaded by an API project that
has not passed Google's compliance audit are locked private, whatever `privacy` says: the artist
makes them public in YouTube Studio. That is why `privacy` defaults to `private`; the audit (a form,
the site's terms and privacy policy, a few weeks) lifts it.

## What it takes

- A Google Cloud project with the **YouTube Data API v3** enabled.
- To read: an **API key** (APIs & Services → Credentials) and the channel's id (`UC...`, in
  YouTube Studio → Settings → Channel → Advanced, or the channel's URL).
- To upload: an **OAuth client** (type *Web application*, the site's redirect URI declared), the
  OAuth consent screen with the scopes `youtube.upload` and `youtube.readonly`, and the artist's
  account among its test users while the app is in *Testing* - a refresh token of an app in
  Testing dies after 7 days: publish the consent screen ("In production") for one that lasts.
  Then `authorizationUrl()` (offline access, consent asked again: that is what gives a refresh
  token) and `exchange()`; the refresh token is the `refresh_token` option. A revoked one is an
  `UnauthorizedException`: the channel is connected again.
- **Quota**: 10 000 units a day per project by default. A read costs 1 unit; an upload is the
  expensive call - about 100 units now, so some 100 uploads a day (check Google's quota calculator:
  it cost 1 600 until recently). More is asked for in the Cloud console.

License: LGPL-3.0-or-later.
