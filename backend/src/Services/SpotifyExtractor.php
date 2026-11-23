<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class SpotifyExtractor implements PlaylistExtractorInterface
{
    public function extract(string $playlistUrl): array
    {
        if (!preg_match('#open\.spotify\.com/playlist/([a-zA-Z0-9]+)#', $playlistUrl, $matches)) {
            throw new RuntimeException('Invalid Spotify playlist URL.');
        }

        $embedUrl = "https://open.spotify.com/embed/playlist/{$matches[1]}";
        $html = $this->fetchHtml($embedUrl);

        if (!preg_match('#<script id="__NEXT_DATA__" type="application/json">(.*?)</script>#s', $html, $jsonMatch)) {
            throw new RuntimeException('Could not locate playlist data in Spotify embed.');
        }

        $data = json_decode($jsonMatch[1], true);
        $entity = $data['props']['pageProps']['state']['data']['entity'] ?? null;

        if (!$entity || empty($entity['trackList'])) {
            throw new RuntimeException('Playlist is private or empty.');
        }

        $coverUrl = $entity['coverArt']['sources'][0]['url']
            ?? $entity['visualIdentity']['image'][0]['url']
            ?? null;

        $tracks = [];
        foreach ($entity['trackList'] as $item) {
            $uri = $item['uri'] ?? '';
            if (!str_starts_with($uri, 'spotify:track:')) {
                continue;
            }

            $tracks[] = [
                'title'       => trim((string) ($item['title'] ?? 'Unknown Title')),
                'artist'      => trim((string) ($item['subtitle'] ?? 'Unknown Artist')),
                'duration_ms' => isset($item['duration']) ? (int) $item['duration'] : null,
                'platform_id' => str_replace('spotify:track:', '', $uri),
                'cover_url'   => $coverUrl,
            ];
        }

        return [
            'title'     => trim((string) ($entity['name'] ?? 'Spotify Playlist')),
            'cover_url' => $coverUrl,
            'tracks'    => $tracks,
        ];
    }

    private function fetchHtml(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status !== 200) {
            throw new RuntimeException("Spotify request failed with HTTP {$status}.");
        }

        return (string) $response;
    }
}