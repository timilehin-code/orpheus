<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class TrackRepository
{
    public function __construct(private PDO $db) {}

    public function buildNormalizedKey(string $artist, string $title): string
    {
        return \App\Services\TrackMatcher::buildCacheKey($artist, $title);
    }

    public function findByNormalizedKey(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM tracks WHERE normalized_key = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO tracks (title, artist, cover_url, normalized_key, duration_ms, spotify_id, apple_music_id, youtube_music_id)
             VALUES (:title, :artist, :cover_url, :normalized_key, :duration_ms, :spotify_id, :apple_music_id, :youtube_music_id)'
        );
        $stmt->execute([
            'title'            => $data['title'],
            'artist'           => $data['artist'],
            'cover_url'        => $data['cover_url'],
            'normalized_key'   => $data['normalized_key'],
            'duration_ms'      => $data['duration_ms'],
            'spotify_id'       => $data['spotify_id'],
            'apple_music_id'   => $data['apple_music_id'],
            'youtube_music_id' => $data['youtube_music_id'],
        ]);

        return (int) $this->db->lastInsertId();
    }
}