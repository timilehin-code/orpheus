<?php

declare(strict_types=1);

namespace App\Services;

class TrackMatcher
{
    private ?string $spotifyToken = null;

    public function matchTrack(array $track, string $sourcePlatform): array
    {
        $ids = [
            'spotify_id'       => $sourcePlatform === 'spotify' ? $track['platform_id'] : null,
            'apple_music_id'   => $sourcePlatform === 'apple_music' ? $track['platform_id'] : null,
            'youtube_music_id' => $sourcePlatform === 'youtube_music' ? $track['platform_id'] : null,
            'cover_url'        => $track['cover_url'] ?? null,
        ];

        $cleanTitle = $this->cleanText($track['title']);
        $cleanArtist = $this->cleanArtist($track['artist']);
        $durationMs = $track['duration_ms'] ?? null;

        if ($ids['apple_music_id'] === null) {
            $appleMatch = $this->searchAppleMusic($cleanTitle, $cleanArtist, $durationMs);
            if ($appleMatch !== null) {
                $ids['apple_music_id'] = $appleMatch['id'];
                $ids['cover_url'] ??= $appleMatch['cover_url'];
            }
        }

        if ($ids['youtube_music_id'] === null) {
            $ids['youtube_music_id'] = $this->searchYouTubeMusic($cleanTitle, $cleanArtist, $durationMs);
        }

        if ($ids['spotify_id'] === null) {
            $spotifyMatch = $this->searchSpotify($cleanTitle, $cleanArtist, $durationMs);
            if ($spotifyMatch !== null) {
                $ids['spotify_id'] = $spotifyMatch['id'];
                $ids['cover_url'] ??= $spotifyMatch['cover_url'];
            }
        }

        return $ids;
    }

    private function searchAppleMusic(string $title, string $artist, ?int $durationMs): ?array
    {
        $query = urlencode(trim("{$artist} {$title}"));
        $json = $this->httpGet("https://itunes.apple.com/search?term={$query}&media=music&entity=song&limit=5");
        $data = json_decode($json, true);

        $best = null;
        $bestScore = 0.0;

        foreach ($data['results'] ?? [] as $item) {
            $candTitle = $this->cleanText((string) ($item['trackName'] ?? ''));
            $candArtist = $this->cleanArtist((string) ($item['artistName'] ?? ''));
            $candDuration = isset($item['trackTimeMillis']) ? (int) $item['trackTimeMillis'] : null;

            $score = $this->scoreCandidate($title, $artist, $durationMs, $candTitle, $candArtist, $candDuration);
            if ($score > $bestScore && $score >= 65.0) {
                $bestScore = $score;
                $art = (string) ($item['artworkUrl100'] ?? '');
                $best = [
                    'id'        => (string) $item['trackId'],
                    'cover_url' => $art !== '' ? str_replace('100x100bb', '300x300bb', $art) : null,
                ];
            }
        }

        return $best;
    }

    private function searchYouTubeMusic(string $title, string $artist, ?int $durationMs): ?string
    {
        $query = urlencode(trim("{$artist} {$title} audio"));
        $html = $this->httpGet("https://www.youtube.com/results?search_query={$query}");

        if (!preg_match('/var ytInitialData = (\{.*?\});<\/script>/s', $html, $match)) {
            return null;
        }

        $data = json_decode($match[1], true);
        $videos = [];
        $this->collectYouTubeVideos($data, $videos);

        $bestId = null;
        $bestScore = 0.0;

        foreach (array_slice($videos, 0, 6) as $video) {
            $candTitle = $this->cleanText($video['title']);
            $candArtist = $this->cleanArtist($video['channel']);
            $score = $this->scoreCandidate($title, $artist, $durationMs, $candTitle, $candArtist, $video['duration_ms']);

            if ($score > $bestScore && $score >= 60.0) {
                $bestScore = $score;
                $bestId = $video['id'];
            }
        }

        return $bestId;
    }

    private function searchSpotify(string $title, string $artist, ?int $durationMs): ?array
    {
        $token = $this->getSpotifyToken();
        if ($token === null) {
            return null;
        }

        $query = urlencode(trim("track:{$title} artist:{$artist}"));
        $json = $this->httpGet(
            "https://api.spotify.com/v1/search?q={$query}&type=track&limit=5",
            ["Authorization: Bearer {$token}"]
        );
        $data = json_decode($json, true);

        $best = null;
        $bestScore = 0.0;

        foreach ($data['tracks']['items'] ?? [] as $item) {
            $candTitle = $this->cleanText((string) ($item['name'] ?? ''));
            $candArtist = $this->cleanArtist((string) ($item['artists'][0]['name'] ?? ''));
            $candDuration = isset($item['duration_ms']) ? (int) $item['duration_ms'] : null;

            $score = $this->scoreCandidate($title, $artist, $durationMs, $candTitle, $candArtist, $candDuration);
            if ($score > $bestScore && $score >= 65.0) {
                $bestScore = $score;
                $best = [
                    'id'        => (string) $item['id'],
                    'cover_url' => $item['album']['images'][0]['url'] ?? null,
                ];
            }
        }

        return $best;
    }

    private function getSpotifyToken(): ?string
    {
        if ($this->spotifyToken !== null) {
            return $this->spotifyToken;
        }

        $clientId = trim((string) ($_ENV['SPOTIFY_CLIENT_ID'] ?? ''));
        $clientSecret = trim((string) ($_ENV['SPOTIFY_CLIENT_SECRET'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $ch = curl_init('https://accounts.spotify.com/api/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode("{$clientId}:{$clientSecret}"),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);

        $res = json_decode((string) curl_exec($ch), true);
        curl_close($ch);

        $this->spotifyToken = $res['access_token'] ?? null;
        return $this->spotifyToken;
    }

    private function scoreCandidate(
        string $title,
        string $artist,
        ?int $durationMs,
        string $candTitle,
        string $candArtist,
        ?int $candDuration
    ): float {
        similar_text(strtolower("{$artist} {$title}"), strtolower("{$candArtist} {$candTitle}"), $percent);

        if ($durationMs !== null && $candDuration !== null) {
            $diff = abs($durationMs - $candDuration);
            if ($diff > 6000) {
                $percent -= 25.0;
            } elseif ($diff <= 3000) {
                $percent += 10.0;
            }
        }

        return $percent;
    }

    private function collectYouTubeVideos(mixed $node, array &$videos): void
    {
        if (!is_array($node) || count($videos) >= 6) {
            return;
        }

        if (isset($node['videoRenderer']['videoId'])) {
            $v = $node['videoRenderer'];
            $lengthStr = (string) ($v['lengthText']['simpleText'] ?? '');
            $videos[] = [
                'id'          => (string) $v['videoId'],
                'title'       => (string) ($v['title']['runs'][0]['text'] ?? ''),
                'channel'     => (string) ($v['ownerText']['runs'][0]['text'] ?? ''),
                'duration_ms' => $this->parseDurationText($lengthStr),
            ];
            return;
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collectYouTubeVideos($child, $videos);
            }
        }
    }

    private function parseDurationText(string $text): ?int
    {
        if ($text === '' || !str_contains($text, ':')) {
            return null;
        }
        $parts = array_map('intval', explode(':', $text));
        if (count($parts) === 2) {
            return (($parts[0] * 60) + $parts[1]) * 1000;
        }
        if (count($parts) === 3) {
            return (($parts[0] * 3600) + ($parts[1] * 60) + $parts[2]) * 1000;
        }
        return null;
    }

    private function cleanText(string $text): string
    {
        $text = preg_replace('/[\(\[].*?(feat|ft|official|video|audio|lyrics|visualizer|from).*?[\)\]]/i', '', $text);
        return trim((string) preg_replace('/\s+/', ' ', (string) $text));
    }

    private function cleanArtist(string $artist): string
    {
        $artist = preg_replace('/\s*-\s*Topic$/i', '', $artist);
        $parts = preg_split('/[,&]|feat\.|ft\./i', (string) $artist);
        return trim((string) ($parts[0] ?? $artist));
    }

    private function httpGet(string $url, array $headers = []): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => array_merge(['Accept-Language: en-US,en;q=0.9'], $headers),
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res === false ? '' : (string) $res;
    }
}