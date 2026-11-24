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
            $this->json([
                'error' => 'Paste a valid public Spotify, Apple Music, or YouTube Music playlist link (personal auto-mixes are not supported).',
            ], 422);
            return;
        }

        [$platform, $cleanUrl] = $parsed;
        $hash = hash('sha256', $cleanUrl);

        $existing = $this->playlists->findByUrlHash($hash);
        if ($existing !== null) {
            $isStale = $existing['status'] === 'processing'
                && !empty($existing['updated_at'])
                && (time() - strtotime((string) $existing['updated_at'])) > 240;

            if ($existing['status'] === 'failed' || $isStale) {
                $playlistId = (int) $existing['id'];
                $this->playlists->resetForRetry($playlistId);
                $this->dispatchBackgroundWorker($playlistId);
                $this->json(['slug' => $existing['slug'], 'status' => 'processing']);
                return;
            }

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
        // Supports regional prefixes like open.spotify.com/intl-en/playlist/...
        if (preg_match('#open\.spotify\.com/(?:intl-[a-z-]+/)?playlist/([a-zA-Z0-9]+)#i', $url, $m)) {
            return ['spotify', "https://open.spotify.com/playlist/{$m[1]}"];
        }

        // Supports both /playlist/name/pl.xxx and /playlist/pl.xxx
        if (preg_match('#music\.apple\.com/([a-z]{2})/playlist/(?:[^/]+/)?(pl\.[a-zA-Z0-9-]+)#i', $url, $m)) {
            return ['apple_music', "https://music.apple.com/" . strtolower($m[1]) . "/playlist/{$m[2]}"];
        }

        // Supports /playlist?list=... and /watch?v=...&list=... (rejects personal RD auto-mixes)
        if (preg_match('#(?:music\.)?youtube\.com/(?:playlist|watch)\?.*list=([a-zA-Z0-9_-]+)#i', $url, $m)) {
            $listId = $m[1];
            if (str_starts_with($listId, 'RD')) {
                return null;
            }
            return ['youtube_music', "https://music.youtube.com/playlist?list={$listId}"];
        }

        return null;
    }

    private function dispatchBackgroundWorker(int $playlistId): void
    {
        $worker = realpath(__DIR__ . '/../../workers/process_playlist.php');
        if ($worker === false) {
            return;
        }

        $phpBin = $this->resolvePhpCli();
        $logFile = dirname($worker) . DIRECTORY_SEPARATOR . 'worker.log';
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($worker) . ' ' . $playlistId;

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /B "" ' . $cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1', 'r'));
        } else {
            exec($cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &');
        }
    }

    private function resolvePhpCli(): string
    {
        if (!empty($_ENV['PHP_CLI_PATH'])) {
            return (string) $_ENV['PHP_CLI_PATH'];
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $candidate = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe';
            return file_exists($candidate) ? $candidate : 'php';
        }

        return PHP_BINARY ?: 'php';
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }
}