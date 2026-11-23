<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class PlaylistRepository
{
    public function __construct(private PDO $db) {}

    public function findByUrlHash(string $hash): ?array
    {
        $stmt = $this->db->prepare('SELECT slug, status FROM playlists WHERE source_url_hash = :hash LIMIT 1');
        $stmt->execute(['hash' => $hash]);
        return $stmt->fetch() ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, slug, title, cover_url, source_platform, source_url, status FROM playlists WHERE slug = :slug LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        return $stmt->fetch() ?: null;
    }

    public function create(string $slug, string $platform, string $cleanUrl, string $hash): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO playlists (slug, source_platform, source_url, source_url_hash, status)
             VALUES (:slug, :platform, :url, :hash, "processing")'
        );
        $stmt->execute([
            'slug'     => $slug,
            'platform' => $platform,
            'url'      => $cleanUrl,
            'hash'     => $hash,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function getTracksForPlaylist(int $playlistId): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.title, t.artist, t.cover_url, t.duration_ms,
                    t.spotify_id, t.apple_music_id, t.youtube_music_id
             FROM playlist_tracks pt
             INNER JOIN tracks t ON t.id = pt.track_id
             WHERE pt.playlist_id = :playlist_id
             ORDER BY pt.position ASC'
        );
        $stmt->execute(['playlist_id' => $playlistId]);
        return $stmt->fetchAll();
    }
}