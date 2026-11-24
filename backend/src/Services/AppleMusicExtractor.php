<?php

declare(strict_types=1);

namespace App\Services;

use DateInterval;
use RuntimeException;
use Throwable;

class AppleMusicExtractor implements PlaylistExtractorInterface
{
    public function extract(string $playlistUrl): array
    {
        if (!preg_match('#music\.apple\.com/([a-z]{2})/playlist/(?:[^/]+/)?(pl\.[a-zA-Z0-9-]+)#i', $playlistUrl, $matches)) {
            throw new RuntimeException('Invalid Apple Music playlist URL.');
        }

        $storefront = strtolower($matches[1]);
        $playlistId = $matches[2];
        $url = "https://music.apple.com/{$storefront}/playlist/{$playlistId}";

        $html = $this->fetchHtml($url);

        $playlistTitle = $this->extractMetaContent($html, 'og:title') ?? 'Apple Music Playlist';
        $playlistTitle = preg_replace('/\s+-\s+Playlist\s+-\s+Apple Music$/i', '', $playlistTitle);
        $playlistCover = $this->extractMetaContent($html, 'og:image');

        $tracks = [];
        $seenIds = [];

        // 1. Parse serialized-server-data
        if (preg_match('#<script[^>]+id="serialized-server-data"[^>]*>(.*?)</script>#s', $html, $jsonMatch)) {
            $serverData = json_decode($jsonMatch[1], true);
            if (is_array($serverData)) {
                $this->collectTracks($serverData, $tracks, $seenIds);
            }
        }

        // 2. Fallback: Parse schema.org MusicPlaylist JSON-LD
        if (empty($tracks)) {
            $tracks = $this->extractFromJsonLd($html, $playlistCover);
        }

        if (empty($tracks)) {
            throw new RuntimeException('Apple Music playlist is private or empty.');
        }

        return [
            'title'     => trim((string) $playlistTitle),
            'cover_url' => $playlistCover ?? ($tracks[0]['cover_url'] ?? null),
            'tracks'    => $tracks,
        ];
    }

    private function collectTracks(mixed $node, array &$tracks, array &$seenIds): void
    {
        if (!is_array($node)) {
            return;
        }

        $kind = $node['contentDescriptor']['kind']
            ?? $node['playParams']['kind']
            ?? $node['itemKind']
            ?? null;

        $rawId = $node['contentDescriptor']['identifiers']['storeAdamID']
            ?? $node['playParams']['catalogId']
            ?? $node['playParams']['id']
            ?? $node['id']
            ?? '';

        $trackId = is_scalar($rawId) ? (string) $rawId : '';

        $title = $node['title'] ?? $node['name'] ?? null;

        $artist = $node['artistName'] ?? null;
        if (!is_string($artist) && !empty($node['subtitleLinks']) && is_array($node['subtitleLinks'])) {
            $names = array_filter(array_map(
                fn($link) => is_array($link) && is_string($link['title'] ?? null) ? $link['title'] : null,
                $node['subtitleLinks']
            ));
            if (!empty($names)) {
                $artist = implode(', ', $names);
            }
        }

        if ($kind === 'song' && $trackId !== '' && ctype_digit($trackId) && is_string($title) && is_string($artist)) {
            if (!isset($seenIds[$trackId])) {
                $seenIds[$trackId] = true;

                $rawArt = $node['artwork']['dictionary']['url']
                    ?? $node['artwork']['url']
                    ?? null;

                $coverUrl = is_string($rawArt)
                    ? str_replace(['{w}', '{h}', '{f}'], ['300', '300', 'jpg'], $rawArt)
                    : null;

                $duration = $node['durationInMillis'] ?? $node['duration'] ?? null;

                $tracks[] = [
                    'title'       => trim($title),
                    'artist'      => trim($artist),
                    'duration_ms' => is_numeric($duration) ? (int) $duration : null,
                    'platform_id' => $trackId,
                    'cover_url'   => $coverUrl,
                ];
            }
            return;
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collectTracks($child, $tracks, $seenIds);
            }
        }
    }

    private function extractFromJsonLd(string $html, ?string $defaultCover): array
    {
        if (!preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#s', $html, $matches)) {
            return [];
        }

        $tracks = [];
        foreach ($matches[1] as $rawJson) {
            $data = json_decode(trim($rawJson), true);
            if (!is_array($data) || ($data['@type'] ?? '') !== 'MusicPlaylist') {
                continue;
            }

            foreach ($data['track'] ?? [] as $item) {
                $songUrl = (string) ($item['url'] ?? '');
                if (!preg_match('#[?&]i=(\d+)#', $songUrl, $idMatch) && !preg_match('#/(\d+)(?:\?.*)?$#', $songUrl, $idMatch)) {
                    continue;
                }

                $artists = $item['byArtist'] ?? [];
                if (isset($artists['name'])) {
                    $artistName = $artists['name'];
                } elseif (is_array($artists)) {
                    $artistName = implode(', ', array_filter(array_column($artists, 'name')));
                } else {
                    $artistName = 'Unknown Artist';
                }

                $durationMs = null;
                if (!empty($item['duration']) && is_string($item['duration'])) {
                    try {
                        $iv = new DateInterval($item['duration']);
                        $durationMs = (($iv->h * 3600) + ($iv->i * 60) + $iv->s) * 1000;
                    } catch (Throwable) {
                        $durationMs = null;
                    }
                }

                $tracks[] = [
                    'title'       => trim((string) ($item['name'] ?? 'Unknown Title')),
                    'artist'      => trim($artistName ?: 'Unknown Artist'),
                    'duration_ms' => $durationMs,
                    'platform_id' => $idMatch[1],
                    'cover_url'   => $defaultCover,
                ];
            }
        }

        return $tracks;
    }

    private function extractMetaContent(string $html, string $property): ?string
    {
        $pattern = '#<meta[^>]+property=["\']' . preg_quote($property, '#') . '["\'][^>]+content=["\']([^"\']+)["\']#i';
        if (preg_match($pattern, $html, $match)) {
            return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return null;
    }

    private function fetchHtml(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept-Language: en-US,en;q=0.9'],
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status !== 200) {
            throw new RuntimeException("Apple Music request failed with HTTP {$status}.");
        }

        return (string) $response;
    }
}
