<?php
require_once __DIR__ . '/db.php';

// ─── HTTP helpers ─────────────────────────────────────────────────────────────

function scGet(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Referer: https://soundcloud.com/',
        ],
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code >= 200 && $code < 400) ? (string)$body : '';
}

function scGetJson(string $url): ?array {
    $body = scGet($url);
    if (!$body) return null;
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function scDownloadBinary(string $url, string $destPath): bool {
    if (!$url) return false;
    $fh = fopen($destPath, 'wb');
    if (!$fh) return false;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Referer: https://soundcloud.com/',
            'Origin: https://soundcloud.com/',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if ($code !== 200 || !file_exists($destPath) || filesize($destPath) < 4096) {
        @unlink($destPath);
        return false;
    }
    // Verify the file is audio (not an HTML/JSON error page)
    $magic = file_get_contents($destPath, false, null, 0, 4);
    if ($magic === false) { @unlink($destPath); return false; }
    $isAudio = (substr($magic, 0, 3) === 'ID3')                      // ID3v2-tagged MP3
            || (ord($magic[0]) === 0xFF && ord($magic[1]) >= 0xE0)   // MPEG frame sync
            || (substr($magic, 0, 4) === 'ftyp')                     // M4A/AAC
            || (substr($magic, 0, 4) === 'OggS');                    // OGG
    if (!$isAudio) { @unlink($destPath); return false; }
    return true;
}

// ─── client_id extraction ─────────────────────────────────────────────────────
// SC embeds a client_id in their JS bundles and page HTML. We use three methods:
// 1. __sc_hydration JSON embedded directly in the HTML
// 2. JS bundle scan (multiple URL patterns, multiple regex patterns)
// 3. Fallback: scan the /discover page too

function scExtractClientId(): string {
    $cacheFile = dirname(__DIR__) . '/data/sc_cid.txt';

    // Return cached value if less than 6 hours old
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 21600) {
        $cached = trim(file_get_contents($cacheFile));
        if (preg_match('/^[a-zA-Z0-9_-]{16,}$/', $cached)) return $cached;
    }

    // client_id regex patterns (SC changes their JS structure periodically)
    $cidPatterns = [
        '/[,{(;=]client_id\s*:\s*"([a-zA-Z0-9_-]{16,64})"/',
        "/[,{(;=]client_id\\s*:\\s*'([a-zA-Z0-9_-]{16,64})'/",
        '/"client_id"\s*:\s*"([a-zA-Z0-9_-]{16,64})"/',
        '/client_id=([a-zA-Z0-9_-]{16,64})[&"\'\\s,}]/',
        '/[,{(;=]clientId\s*:\s*"([a-zA-Z0-9_-]{16,64})"/',
        "/[,{(;=]clientId\\s*:\\s*'([a-zA-Z0-9_-]{16,64})'/",
        '/[^a-z]client_id["\']\s*[=:]\s*["\']([a-zA-Z0-9_-]{16,64})["\']/',
    ];

    $pages = ['https://soundcloud.com/', 'https://soundcloud.com/discover'];

    foreach ($pages as $pageUrl) {
        $html = scGet($pageUrl);
        if (!$html) continue;

        // Method 1: __sc_hydration JSON (SoundCloud embeds config in page HTML)
        if (preg_match('/window\.__sc_hydration\s*=\s*(\[[\s\S]+?\]);/', $html, $hm)) {
            $hydration = @json_decode($hm[1], true);
            if (is_array($hydration)) {
                foreach ($hydration as $item) {
                    foreach (['clientId', 'client_id'] as $k) {
                        $c = $item['data'][$k] ?? '';
                        if ($c && preg_match('/^[a-zA-Z0-9_-]{16,}$/', $c)) {
                            file_put_contents($cacheFile, $c);
                            return $c;
                        }
                    }
                }
            }
        }

        // Method 2: Scan JS bundle files linked from the page
        preg_match_all(
            '#\bsrc=["\']?(https://[a-z0-9._-]*sndcdn\.com/assets/[^\s"\'<>]+\.js)["\']?#i',
            $html, $m
        );
        $jsUrls = array_unique($m[1] ?? []);

        foreach (array_reverse($jsUrls) as $jsUrl) {
            $js = scGet($jsUrl);
            if (!$js) continue;
            foreach ($cidPatterns as $pat) {
                if (preg_match($pat, $js, $mm)) {
                    $cid = trim($mm[1]);
                    if (preg_match('/^[a-zA-Z0-9_-]{16,}$/', $cid)) {
                        file_put_contents($cacheFile, $cid);
                        return $cid;
                    }
                }
            }
        }
    }

    // Clear stale cache on failure so the next request retries immediately
    if (file_exists($cacheFile)) @unlink($cacheFile);
    return '';
}

// ─── SC API v2 wrappers ───────────────────────────────────────────────────────

function scResolveUser(string $username, string $clientId): ?array {
    $url = 'https://api-v2.soundcloud.com/resolve?' . http_build_query([
        'url'       => 'https://soundcloud.com/' . $username,
        'client_id' => $clientId,
    ]);
    return scGetJson($url);
}

function scGetUserTracks(string $userId, string $clientId, int $limit = 200): array {
    // No representation=compact — we need full data including publisher_metadata
    $url = 'https://api-v2.soundcloud.com/users/' . $userId . '/tracks?' . http_build_query([
        'client_id'           => $clientId,
        'limit'               => min($limit, 200),
        'linked_partitioning' => 1,
    ]);
    $data = scGetJson($url);
    if (!$data || empty($data['collection'])) return [];

    $out = [];
    foreach ($data['collection'] as $t) {
        if (($t['kind'] ?? '') !== 'track') continue;
        $art = $t['artwork_url'] ?? '';
        if ($art) $art = str_replace('-large.', '-t300x300.', $art);

        // Artist: prefer publisher_metadata.artist, then user full_name, then username
        $pm     = $t['publisher_metadata'] ?? [];
        $artist = trim($pm['artist'] ?? '')
               ?: trim($t['user']['full_name'] ?? '')
               ?: ($t['user']['username'] ?? '');

        // Release date: prefer explicit release_date, fall back to created_at (upload date)
        $rawDate = $t['release_date'] ?? $t['created_at'] ?? '';
        $releaseDate = '';
        if ($rawDate) {
            // Convert ISO-8601 "2024-03-15T00:00:00Z" → "15/03/24"
            $ts = strtotime($rawDate);
            if ($ts !== false) $releaseDate = date('d/m/y', $ts);
        }

        $out[] = [
            'sc_track_id'   => (string)($t['id'] ?? ''),
            'title'         => $t['title']        ?? 'Untitled',
            'artist'        => $artist,
            'artwork_url'   => $art,
            'permalink_url' => $t['permalink_url'] ?? '',
            'release_date'  => $releaseDate,
            'downloadable'  => !empty($t['downloadable']),
        ];
    }
    return $out;
}

// Returns the redirect URL for tracks where the artist enabled "Allow Downloads"
function scGetDownloadUrl(string $trackId, string $clientId): string {
    $data = scGetJson('https://api-v2.soundcloud.com/tracks/' . $trackId
                    . '/download?client_id=' . urlencode($clientId));
    return $data['redirectUri'] ?? '';
}

// Returns a directly downloadable MP3 URL for any public track.
// SC now serves most streams via signed transcoding URLs rather than http_mp3_128_url.
function scGetStreamUrl(string $trackId, string $clientId): string {
    // Method 1: fetch full track info → find progressive MP3 transcoding → resolve to CDN URL
    $track = scGetJson('https://api-v2.soundcloud.com/tracks/' . $trackId
                     . '?client_id=' . urlencode($clientId));
    foreach ($track['media']['transcodings'] ?? [] as $tc) {
        if (($tc['format']['protocol'] ?? '') === 'progressive'
            && strpos($tc['format']['mime_type'] ?? '', 'mpeg') !== false) {
            $resolved = scGetJson(($tc['url'] ?? '') . '?client_id=' . urlencode($clientId));
            if (!empty($resolved['url'])) return $resolved['url'];
        }
    }

    // Method 2: /streams endpoint (older field, sometimes still present)
    $streams = scGetJson('https://api-v2.soundcloud.com/tracks/' . $trackId
                       . '/streams?client_id=' . urlencode($clientId));
    $url = ($streams['http_mp3_128_url'] ?? $streams['preview_mp3_128_url'] ?? '');
    if ($url) return $url;

    // Method 3: v1 API stream (last resort — may redirect to HLS, handled by downloader)
    return 'https://api.soundcloud.com/tracks/' . $trackId
         . '/stream?client_id=' . urlencode($clientId);
}

// ─── Download directory ───────────────────────────────────────────────────────

function scDownloadDir(): string {
    $dir = dirname(__DIR__) . '/uploads/sc_music';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

// ─── Main sync ────────────────────────────────────────────────────────────────

function syncSoundCloudProfile(int $profileId): array {
    $clientId = scExtractClientId();
    if (!$clientId) {
        return [
            'success' => false,
            'error'   => 'Could not extract SoundCloud client_id. '
                       . 'SC may have changed their JS structure — try again in a few minutes.',
        ];
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM soundcloud_profiles WHERE id = ?");
    $stmt->execute([$profileId]);
    $profile = $stmt->fetch();
    if (!$profile) return ['success' => false, 'error' => 'Profile not found.'];

    $user = scResolveUser($profile['username'], $clientId);
    if (!$user || empty($user['id'])) {
        return [
            'success' => false,
            'error'   => 'Could not resolve SoundCloud user "' . $profile['username']
                       . '". Make sure the profile is public.',
        ];
    }

    $userId      = (string)$user['id'];
    $displayName = $user['username'] ?? $profile['username'];

    $tracks = scGetUserTracks($userId, $clientId);
    if (empty($tracks)) {
        return ['success' => false, 'error' => 'No public tracks found for this user.'];
    }

    $dir      = scDownloadDir();
    $added    = 0;
    $updated  = 0;
    $dlFailed = 0;

    set_time_limit(600);

    $checkStmt  = $db->prepare("SELECT id FROM soundcloud_tracks WHERE sc_track_id = ?");
    $updateStmt = $db->prepare(
        "UPDATE soundcloud_tracks SET title=?, artist=?, artwork_url=?, permalink_url=?, release_date=?, local_filename=? WHERE sc_track_id=?"
    );
    $insertStmt = $db->prepare(
        "INSERT INTO soundcloud_tracks (profile_id, sc_track_id, title, artist, artwork_url, permalink_url, release_date, local_filename)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($tracks as $t) {
        $localFile = '';
        $destMp3   = $dir . '/' . $t['sc_track_id'] . '.mp3';

        if (file_exists($destMp3)) {
            $localFile = $t['sc_track_id'] . '.mp3';
        } else {
            // Try direct download first (artist enabled "Allow Downloads")
            $dlUrl = '';
            if ($t['downloadable']) {
                $dlUrl = scGetDownloadUrl($t['sc_track_id'], $clientId);
            }
            // Fall back to 128 kbps MP3 stream (works for any public track)
            if (!$dlUrl) {
                $dlUrl = scGetStreamUrl($t['sc_track_id'], $clientId);
            }

            if ($dlUrl && scDownloadBinary($dlUrl, $destMp3)) {
                $localFile = $t['sc_track_id'] . '.mp3';
            } else {
                $dlFailed++;
            }
        }

        $checkStmt->execute([$t['sc_track_id']]);
        if ($checkStmt->fetchColumn()) {
            $updateStmt->execute([
                $t['title'], $t['artist'], $t['artwork_url'],
                $t['permalink_url'], $t['release_date'], $localFile, $t['sc_track_id'],
            ]);
            $updated++;
        } else {
            $insertStmt->execute([
                $profileId, $t['sc_track_id'], $t['title'], $t['artist'],
                $t['artwork_url'], $t['permalink_url'], $t['release_date'], $localFile,
            ]);
            $added++;
        }
    }

    $db->prepare("UPDATE soundcloud_profiles SET display_name=?, sc_user_id=?, last_synced=CURRENT_TIMESTAMP WHERE id=?")
       ->execute([$displayName, $userId, $profileId]);

    $total = count($tracks);
    $msg   = "Sync done: {$added} new, {$updated} updated, {$total} total.";
    if ($dlFailed > 0) {
        $msg .= " {$dlFailed} track(s) could not be downloaded"
              . " — ensure \"Allow Downloads\" is enabled on SoundCloud for those tracks.";
    }

    return [
        'success' => true,
        'added'   => $added,
        'updated' => $updated,
        'total'   => $total,
        'msg'     => $msg,
    ];
}
