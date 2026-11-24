<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use App\Repositories\PlaylistRepository;
use App\Repositories\TrackRepository;
use App\Services\AppleMusicExtractor;
use App\Services\SpotifyExtractor;
use App\Services\TrackMatcher;
use App\Services\YouTubeExtractor;

$playlistId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($playlistId <= 0) {
    exit("Missing playlist ID.\n");
}

$pdo = getDatabaseConnection();
$playlists = new PlaylistRepository($pdo);
$tracksRepo = new TrackRepository($pdo);
$matcher = new TrackMatcher();

$playlist = $playlists->findById($playlistId);
if ($playlist === null) {
    exit("Playlist #{$playlistId} not found.\n");
}

try {
    $extractor = match ($playlist['source_platform']) {
        'spotify'       => new SpotifyExtractor(),
        'apple_music'   => new AppleMusicExtractor(),
        'youtube_music' => new YouTubeExtractor(),
        default         => throw new RuntimeException('Unsupported platform.'),
    };

    $extracted = $extractor->extract($playlist['source_url']);

    foreach ($extracted['tracks'] as $index => $item) {
        $key = $tracksRepo->buildNormalizedKey($item['artist'], $item['title']);
        $cached = $tracksRepo->findByNormalizedKey($key);

        if ($cached !== null) {
            $trackId = (int) $cached['id'];
        } else {
            $matched = $matcher->matchTrack($item, $playlist['source_platform']);
            $trackId = $tracksRepo->create([
                'title'            => $item['title'],
                'artist'           => $item['artist'],
                'cover_url'        => $matched['cover_url'],
                'normalized_key'   => $key,
                'duration_ms'      => $item['duration_ms'],
                'spotify_id'       => $matched['spotify_id'],
                'apple_music_id'   => $matched['apple_music_id'],
                'youtube_music_id' => $matched['youtube_music_id'],
            ]);
        }

        $playlists->attachTrack($playlistId, $trackId, $index + 1);
    }

    $playlists->markCompleted($playlistId, $extracted['title'], $extracted['cover_url']);
    echo "Completed playlist #{$playlistId}\n";
} catch (Throwable $e) {
    $playlists->markFailed($playlistId);
    fwrite(STDERR, "Failed playlist #{$playlistId}: {$e->getMessage()}\n");
    exit(1);
}