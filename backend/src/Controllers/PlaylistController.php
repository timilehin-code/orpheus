<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PlaylistRepository;

class PlaylistController
{
    public function __construct(private PlaylistRepository $playlists) {}

    public function store(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $rawUrl = trim((string) ($input['url'] ?? ''));

        $parsed = $this->parsePlaylistUrl($rawUrl);
        if ($parsed === null) {
            $this->json(['error' => 'Paste a valid Spotify, Apple Music, or YouTube Music playlist link.'], 422);
            return;
        }

        [$platform, $cleanUrl] = $parsed;
        $hash = hash('sha256', $cleanUrl);

        // Return immediately if this playlist was already converted
        $existing = $this->playlists->findByUrlHash($hash);
        if ($existing !== null) {
            $this->json(['slug' => $existing['slug'], 'status' => $existing['status']]);
            return;
        }

        $slug = bin2hex(random_bytes(4));
        $playlistId = $this->playlists->create($slug, $platform, $cleanUrl, $hash);

        $this->dispatchBackgroundWorker($playlistId);

        $this->json(['slug' => $slug, 'status' => 'processing'], 201);
    }

    public function show(string $slug): void
    {
        $playlist = $this->playlists->findBySlug($slug);
        if ($playlist === null) {
            $this->json(['error' => 'Playlist not found.'], 404);
            return;
        }

        $tracks = $playlist['status'] === 'completed'
            ? $this->playlists->getTracksForPlaylist((int) $playlist['id'])
            : [];

        unset($playlist['id']);
        $playlist['tracks'] = $tracks;

        $this->json($playlist);
    }

    private function parsePlaylistUrl(string $url): ?array
    {
        if (preg_match('#open\.spotify\.com/playlist/([a-zA-Z0-9]+)#', $url, $m)) {
            return ['spotify', "https://open.spotify.com/playlist/{$m[1]}"];
        }

        if (preg_match('#music\.apple\.com/([a-z]{2})/playlist/[^/]+/(pl\.[a-zA-Z0-9-]+)#', $url, $m)) {
            return ['apple_music', "https://music.apple.com/{$m[1]}/playlist/{$m[2]}"];
        }

        if (preg_match('#(?:music\.)?youtube\.com/playlist\?.*list=([a-zA-Z0-9_-]+)#', $url, $m)) {
            return ['youtube_music', "https://music.youtube.com/playlist?list={$m[1]}"];
        }

        return null;
    }

    private function dispatchBackgroundWorker(int $playlistId): void
    {
        $worker = realpath(__DIR__ . '/../../workers/process_playlist.php');
        if ($worker === false) {
            return;
        }

        $phpBin = PHP_BINARY ?: 'php';
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($worker) . ' ' . $playlistId;

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /B "" ' . $cmd . ' > NUL 2>&1', 'r'));
        } else {
            exec($cmd . ' > /dev/null 2>&1 &');
        }
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }
}