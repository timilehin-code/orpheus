Orpheus works as an asynchronous, cache-first pipeline split into four stages:

URL Normalization & Cache Check (POST /api/playlists)
React sends the pasted playlist URL to PHP. PHP strips tracking parameters, hashes the clean URL, and checks the playlists table in MySQL. If it has been converted before, PHP immediately returns the existing slug. If it is new, PHP inserts a processing row and spawns a detached background CLI worker (workers/process_playlist.php) so the HTTP request returns in milliseconds without timing out.

Source Playlist Extraction (SpotifyExtractor, AppleMusicExtractor, YouTubeExtractor)
The background worker picks the right extractor for the source link and pulls a standardized array of tracks (title, artist, duration_ms, and the native platform_id) using YouTube's API/page payload, Spotify's public embed JSON, or Apple Music's serialized page data.

Deduplication & Cross-Platform Matching (TrackMatcher)
For each song, PHP first checks your global tracks table using a normalized artist + title hash—if another playlist already resolved that song, it reuses the saved IDs immediately. Otherwise, it strips noise like (Official Video) or (feat. ...), queries the missing platforms, and picks the best match using similar_text() plus a duration tolerance check (±3s). All three platform IDs are saved into tracks and linked in order via playlist_tracks.

Status Polling & Instant Deep Links (GET /api/playlists/{slug})
While the worker runs, React redirects to /p/{slug} and polls the GET endpoint every 2 seconds. Once the worker flips the playlist status to completed, the API returns the full tracklist with all three platform IDs attached, letting React render instant deep links ([open.spotify.com/track/](https://open.spotify.com/track/)..., [music.apple.com/](https://music.apple.com/)..., [music.youtube.com/watch?v=](https://music.youtube.com/watch?v=)...) for each platform icon.