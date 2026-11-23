<?php

declare(strict_types=1);

namespace App\Services;

use DateInterval;
use RuntimeException;

class YouTubeExtractor implements PlaylistExtractorInterface
{
    public function extract(string $playlistUrl): array
    {
        if (!preg_match('#(?:music\.)?youtube\.com/playlist\?.*list=([a-zA-Z0-9_-]+)#', $playlistUrl, $matches)) {
            throw new RuntimeException('Invalid YouTube or YouTube Music playlist URL.');
        }

        $listId = $matches[1];
        $apiKey = trim((string) ($_ENV['YOUTUBE_API_KEY'] ?? ''));

        return $apiKey !== ''
            ? $this->extractViaApi($listId, $apiKey)
            : $this->extractViaPage($listId);
    }

    private function extractViaApi(string $listId, string $apiKey): array
    {
        $meta = $this->httpGetJson(
            "https://www.googleapis.com/youtube/v3/playlists?part=snippet&id={$listId}&key={$apiKey}"
        );
        $snippet = $meta['items'][0]['snippet'] ?? null;
        if (!$snippet) {
            throw new RuntimeException('YouTube playlist not found or private.');
        }

        $itemsRes = $this->httpGetJson(
            "https://www.googleapis.com/youtube/v3/playlistItems?part=snippet,contentDetails&maxResults=50&playlistId={$listId}&key={$apiKey}"
        );

        $rawItems = $itemsRes['items'] ?? [];
        $videoIds = array_filter(array_map(
            fn($item) => $item['contentDetails']['videoId'] ?? null,
            $rawItems
        ));

        $durations = $this->fetchVideoDurations($videoIds, $apiKey);
        $tracks = [];

        foreach ($rawItems as $item) {
            $videoId = $item['contentDetails']['videoId'] ?? '';
            $itemSnippet = $item['snippet'] ?? [];
            $title = trim((string) ($itemSnippet['title'] ?? ''));

            if ($videoId === '' || $title === '' || $title === 'Private video' || $title === 'Deleted video') {
                continue;
            }

            $channel = (string) ($itemSnippet['videoOwnerChannelTitle'] ?? 'Unknown Artist');
            $artist = trim((string) preg_replace('/\s*-\s*Topic$/i', '', $channel));

            $tracks[] = [
                'title'       => $title,
                'artist'      => $artist,
                'duration_ms' => $durations[$videoId] ?? null,
                'platform_id' => $videoId,
                'cover_url'   => $itemSnippet['thumbnails']['high']['url']
                    ?? $itemSnippet['thumbnails']['default']['url']
                    ?? "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
            ];
        }

        return [
            'title'     => trim((string) ($snippet['title'] ?? 'YouTube Playlist')),
            'cover_url' => $snippet['thumbnails']['high']['url']
                ?? $snippet['thumbnails']['default']['url']
                ?? ($tracks[0]['cover_url'] ?? null),
            'tracks'    => $tracks,
        ];
    }

    private function fetchVideoDurations(array $videoIds, string $apiKey): array
    {
        if (empty($videoIds)) {
            return [];
        }

        $ids = implode(',', array_slice($videoIds, 0, 50));
        $res = $this->httpGetJson(
            "https://www.googleapis.com/youtube/v3/videos?part=contentDetails&id={$ids}&key={$apiKey}"
        );

        $map = [];
        foreach ($res['items'] ?? [] as $video) {
            $iso = $video['contentDetails']['duration'] ?? '';
            if ($iso !== '') {
                $interval = new DateInterval($iso);
                $seconds = ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
                $map[$video['id']] = $seconds * 1000;
            }
        }

        return $map;
    }

    private function extractViaPage(string $listId): array
    {
        $html = $this->httpGet("https://www.youtube.com/playlist?list={$listId}");

        if (!preg_match('/var ytInitialData = (\{.*?\});<\/script>/s', $html, $match)) {
            throw new RuntimeException('Could not parse YouTube playlist page.');
        }

        $data = json_decode($match[1], true);
        $playlistTitle = $data['metadata']['playlistMetadataRenderer']['title'] ?? 'YouTube Playlist';

        $contents = $data['contents']['twoColumnBrowseResultsRenderer']['tabs'][0]['tabRenderer']['content']
            ['sectionListRenderer']['contents'][0]['itemSectionRenderer']['contents'][0]
            ['playlistVideoListRenderer']['contents'] ?? [];

        if (empty($contents)) {
            throw new RuntimeException('YouTube playlist is empty or private.');
        }

        $tracks = [];
        foreach ($contents as $entry) {
            $video = $entry['playlistVideoRenderer'] ?? null;
            if (!$video || empty($video['videoId'])) {
                continue;
            }

            $videoId = $video['videoId'];
            $title = trim((string) ($video['title']['runs'][0]['text'] ?? 'Unknown Title'));
            $channel = (string) ($video['shortBylineText']['runs'][0]['text'] ?? 'Unknown Artist');
            $artist = trim((string) preg_replace('/\s*-\s*Topic$/i', '', $channel));
            $seconds = isset($video['lengthSeconds']) ? (int) $video['lengthSeconds'] : 0;

            $tracks[] = [
                'title'       => $title,
                'artist'      => $artist,
                'duration_ms' => $seconds > 0 ? $seconds * 1000 : null,
                'platform_id' => $videoId,
                'cover_url'   => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
            ];
        }

        return [
            'title'     => trim((string) $playlistTitle),
            'cover_url' => $tracks[0]['cover_url'] ?? null,
            'tracks'    => $tracks,
        ];
    }

    private function httpGetJson(string $url): array
    {
        return json_decode($this->httpGet($url), true) ?? [];
    }

    private function httpGet(string $url): string
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
            throw new RuntimeException("YouTube request failed with HTTP {$status}.");
        }

        return (string) $response;
    }
}