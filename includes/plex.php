<?php
declare(strict_types=1);

/**
 * Thin wrapper around Plex's HTTP API. Plex returns JSON if you send
 * Accept: application/json, which is all we need — no XML parsing.
 */
class PlexClient
{
    private string $baseUrl;
    private string $token;

    public function __construct(?string $baseUrl = null, ?string $token = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (get_setting('plex_url') ?? ''), '/');
        $this->token = $token ?? (get_setting('plex_token') ?? '');
        if ($this->baseUrl === '' || $this->token === '') {
            throw new RuntimeException('Plex URL/token are not configured. Set them on the Settings page.');
        }
    }

    public function tokenParam(): string
    {
        return $this->token;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** Build an absolute, token-authenticated URL from a Plex-relative image path. */
    public function absoluteUrl(string $relative): string
    {
        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }
        $sep = str_contains($relative, '?') ? '&' : '?';
        return $this->baseUrl . $relative . $sep . 'X-Plex-Token=' . urlencode($this->token);
    }

    /**
     * GET a Plex API path and return the decoded MediaContainer array.
     * $extraHeaders lets callers pass X-Plex-Container-Start/Size for pagination.
     */
    private function request(string $path, array $extraHeaders = []): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => array_merge([
                'Accept: application/json',
                'X-Plex-Token: ' . $this->token,
            ], $extraHeaders),
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Plex request failed: $err");
        }
        if ($httpCode >= 400) {
            throw new RuntimeException("Plex returned HTTP $httpCode for $path");
        }
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['MediaContainer'])) {
            throw new RuntimeException("Unexpected response from Plex for $path");
        }
        return $json['MediaContainer'];
    }

    /** All library sections (movie, show, artist, etc.), for the section picker. */
    public function getLibraries(): array
    {
        $mc = $this->request('/library/sections');
        return $mc['Directory'] ?? [];
    }

    /**
     * One page of items from a library section, using Plex's own pagination
     * (X-Plex-Container-Start/Size) so we never have to pull a whole library
     * into memory just to process a batch of 25.
     */
    public function getSectionItems(int $sectionId, int $start, int $size, ?string $libtype = null): array
    {
        $path = "/library/sections/{$sectionId}/all";
        $params = [];
        if ($libtype) {
            $typeMap = ['movie' => 1, 'show' => 2, 'season' => 3, 'episode' => 4, 'artist' => 8, 'album' => 9];
            if (isset($typeMap[$libtype])) {
                $params[] = 'type=' . $typeMap[$libtype];
            }
        }
        if ($params) {
            $path .= '?' . implode('&', $params);
        }
        $mc = $this->request($path, [
            'X-Plex-Container-Start: ' . $start,
            'X-Plex-Container-Size: ' . $size,
        ]);
        return [
            'items' => $mc['Metadata'] ?? [],
            'totalSize' => (int) ($mc['totalSize'] ?? $mc['size'] ?? 0),
        ];
    }

    /**
     * Full metadata for one item, including the Image[] list (poster,
     * background, clearLogo, backgroundSquare) and Guid[] list (tmdb/imdb ids)
     * in a single request.
     */
    public function getItemFull(int $ratingKey): array
    {
        $mc = $this->request("/library/metadata/{$ratingKey}");
        $items = $mc['Metadata'] ?? [];
        if (!$items) {
            throw new RuntimeException("Rating key {$ratingKey} not found on Plex");
        }
        return $items[0];
    }

    /**
     * Pull the local filesystem folder for an item out of its Media/Part locations.
     * For shows/seasons without their own file, callers should instead resolve
     * via the first episode — Plex Art Manager focuses on movie libraries, which always
     * have Media/Part on the item itself.
     */
    public function itemFolderPath(array $item): ?string
    {
        $file = $item['Media'][0]['Part'][0]['file'] ?? null;
        if (!$file) {
            return null;
        }
        return dirname(map_path($file));
    }

    /** Extract a numeric TMDB id from an item's Guid[] list, e.g. "tmdb://9401". */
    public function itemTmdbId(array $item): ?int
    {
        foreach ($item['Guid'] ?? [] as $guid) {
            if (preg_match('#^tmdb://(\d+)#', $guid['id'] ?? '', $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    public function itemImdbId(array $item): ?string
    {
        foreach ($item['Guid'] ?? [] as $guid) {
            if (preg_match('#^imdb://(tt\d+)#', $guid['id'] ?? '', $m)) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * The video file's own basename (no extension, no directory), e.g.
     * "2 Days in the Valley (1996) [Commentary]" for a file at
     * ".../2 Days in the Valley (1996) [Commentary].mkv". Used to recognize
     * Plex's documented movie-filename-based poster naming convention
     * (see find_existing_poster() in helpers.php) - confirmed against
     * support.plex.tv's "Local Media Assets - Movies" article.
     */
    public function itemVideoBasename(array $item): ?string
    {
        $file = $item['Media'][0]['Part'][0]['file'] ?? null;
        if (!$file) {
            return null;
        }
        return pathinfo($file, PATHINFO_FILENAME);
    }

    /**
     * Map our asset type keys to Plex's Image "type" strings and pull the
     * matching URL out of an item's Image[] list, absolutized with the token.
     * Confirmed against live Plex metadata: coverPoster, background, clearLogo,
     * backgroundSquare.
     */
    public function itemAssetUrl(array $item, string $assetType): ?string
    {
        $typeMap = [
            'poster' => 'coverPoster',
            'art'    => 'background',
            'logo'   => 'clearLogo',
            'square' => 'backgroundSquare',
        ];
        $wanted = $typeMap[$assetType] ?? null;
        if (!$wanted) {
            return null;
        }
        foreach ($item['Image'] ?? [] as $image) {
            if (($image['type'] ?? null) === $wanted) {
                return $this->absoluteUrl($image['url']);
            }
        }
        return null;
    }
}

/** Local filename each asset type is saved as. */
const ASSET_FILENAMES = [
    'poster' => 'poster.jpg',
    'art'    => 'background.jpg',
    'square' => 'square.jpg',
    'logo'   => 'logo.png',
];

const ASSET_LABELS = [
    'poster' => 'Poster',
    'art'    => 'Background',
    'square' => 'Square Art',
    'logo'   => 'Logo',
];
