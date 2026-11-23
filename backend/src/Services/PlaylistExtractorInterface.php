<?php

declare(strict_types=1);

namespace App\Services;

interface PlaylistExtractorInterface
{
    /**
     * @return array{
     *   title: string,
     *   cover_url: ?string,
     *   tracks: array<int, array{
     *     title: string,
     *     artist: string,
     *     duration_ms: ?int,
     *     platform_id: string,
     *     cover_url: ?string
     *   }>
     * }
     */
    public function extract(string $playlistUrl): array;
}