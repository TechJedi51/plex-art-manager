<?php
declare(strict_types=1);

/**
 * Fanart.tv candidate images, keyed by TMDB id (movies only).
 *
 * NOTE ON SQUARE ART: Fanart.tv has no "square" category for movies (square
 * cover art is mainly a music/artist concept on Fanart.tv). Requesting
 * candidates for asset_type 'square' will return an empty logo/background/
 * poster set from this provider — see providers/tmdb.php for the same caveat.
 * If square art matters a lot, consider adding ThePosterDB or MediUX as a
 * future provider (both used by Kometa-adjacent tooling); they're not wired
 * up here since they don't have stable public APIs.
 */
class FanartProvider
{
    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? (get_setting('fanart_api_key') ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Returns candidates for one asset type: [['url' => ..., 'lang' => ..., 'likes' => ...], ...]
     */
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
            'poster' => 'movieposter',
            'art'    => 'moviebackground',
            'logo'   => 'hdmovielogo', // fall back to movielogo below if empty
        ];
        $field = $fieldMap[$assetType] ?? null;
        if (!$field) {
            return [];
        }

        $raw = $data[$field] ?? [];
        if ($assetType === 'logo' && empty($raw)) {
            $raw = $data['movielogo'] ?? [];
        }

        $out = [];
        foreach ($raw as $entry) {
            $out[] = [
                'url'    => $entry['url'] ?? null,
                'lang'   => $entry['lang'] ?? null,
                'likes'  => isset($entry['likes']) ? (int) $entry['likes'] : null,
                'source' => 'fanart',
            ];
        }
        // Best-liked first.
        usort($out, fn($a, $b) => ($b['likes'] ?? 0) <=> ($a['likes'] ?? 0));
        return array_values(array_filter($out, fn($c) => !empty($c['url'])));
    }

    private function fetch(int $tmdbId): ?array
    {
        $url = "https://webservice.fanart.tv/v3/movies/{$tmdbId}?api_key=" . urlencode($this->apiKey);
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
