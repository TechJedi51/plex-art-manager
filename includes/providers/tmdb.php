<?php
declare(strict_types=1);

/**
 * TMDB candidate images via the /movie/{id}/images endpoint.
 *
 * NOTE ON SQUARE ART: TMDB doesn't have a square-crop category either — its
 * `logos` come in varying aspect ratios but nothing purpose-built as square
 * cover art. Requesting 'square' candidates from this provider returns [].
 */
class TmdbProvider
{
    private string $apiKey;
    private const IMG_BASE = 'https://image.tmdb.org/t/p/';

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? (get_setting('tmdb_api_key') ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function getCandidates(int $tmdbId, string $assetType): array
    {
        if (!$this->isConfigured() || $assetType === 'square') {
            return [];
        }

        $data = $this->fetch($tmdbId);
        if (!$data) {
            return [];
        }

        $fieldMap = [
            'poster' => ['key' => 'posters', 'size' => 'w500'],
            'art'    => ['key' => 'backdrops', 'size' => 'w1280'],
            'logo'   => ['key' => 'logos', 'size' => 'w500'],
        ];
        $conf = $fieldMap[$assetType] ?? null;
        if (!$conf) {
            return [];
        }

        $out = [];
        foreach ($data[$conf['key']] ?? [] as $entry) {
            $out[] = [
                'url'         => self::IMG_BASE . $conf['size'] . ($entry['file_path'] ?? ''),
                'lang'        => $entry['iso_639_1'] ?? null,
                'voteAverage' => $entry['vote_average'] ?? null,
                'width'       => $entry['width'] ?? null,
                'height'      => $entry['height'] ?? null,
                'source'      => 'tmdb',
            ];
        }
        usort($out, fn($a, $b) => ($b['voteAverage'] ?? 0) <=> ($a['voteAverage'] ?? 0));
        return $out;
    }

    private function fetch(int $tmdbId): ?array
    {
        // include_image_language pulls in language-neutral (logos/textless) art too
        $url = "https://api.themoviedb.org/3/movie/{$tmdbId}/images"
            . '?api_key=' . urlencode($this->apiKey)
            . '&include_image_language=en,null';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code >= 400) {
            return null;
        }
        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }
}
