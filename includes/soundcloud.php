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
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: */*', 'Accept-Language: en-US,en;q=0.9'],
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
    $fh = fopen($destPath, 'wb');
    if (!$fh) return false;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
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
    return true;
}

// ─── client_id extraction ─────────────────────────────────────────────────────
// SC embeds a client_id in their JS bundles. We parse it out the same way
// yt-dlp and other tools do — no additional software needed.

function scExtractClientId(): string {
    $cacheFile = dirname(__DIR__) . '/data/sc_cid.txt';

    // Return cached value if less than 6 hours old
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 21600) {
        $cached = trim(file_get_contents($cacheFile));
        if (preg_match('/^[a-zA-Z0-9]{16,}$/', $cached)) return $cached;
    }

    // Fetch SC homepage to find JS bundle URLs
    $html = scGet('https://soundcloud.com/');
    if (!$html) return '';

    // Collect all JS bundle URLs from <script src="...">
    preg_match_all(
        '#<script[^>]+src=["\']?(https://a-v2\.sndcdn\.com/assets/[^"\'>\s]+\.js)["\']?#i',
        $html, $m
    );
    $jsUrls = array_unique($m[1] ?? []);

    // client_id is usually in one of the later bundles — try in reverse
    foreach (array_reverse($jsUrls) as $jsUrl) {
        $js = scGet($jsUrl);
        if (!$js) continue;

        // Known patterns where SC embeds client_id in their minified JS
        foreach ([
            '/[,{(]client_id:"([a-zA-Z0-9_-]{20,50})"/',
            "/[,{(]client_id:'([a-zA-Z0-9_-]{20,50})'/",
            '/client_id=([a-zA-Z0-9_-]{20,50})[&,}"\']/',
        ] as $pat) {
            if (preg_match($pat, $js, $mm)) {
                $cid = $mm[1];
                file_put_contents($cacheFile, $cid);
                return $cid;
            }
        }
    }

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
    $url = 'https://api-v2.soundcloud.com/users/' . $userId . '/tracks?' . http_build_query([
        'client_id'           => $clientId,
        'limit'               => min($limit, 200),
        'representation'      => 'compact',
        'linked_partitioning' => 1,
    ]);
    $data = scGetJson($url);
    if (!$data || empty($data['collection'])) return [];

    $out = [];
    foreach ($data['collection'] as $t) {
        if (($t['kind'] ?? '') !== 'track') continue;
        $art = $t['artwork_url'] ?? '';
        if ($art) $art = str_replace('-large.', '-t300x300.', $art);
        $out[] = [
            'sc_track_id'   => (string)($t['id'] ?? ''),
            'title'         => $t['title']               ?? 'Untitled',
            'artist'        => $t['user']['username']    ?? '',
            'artwork_url'   => $art,
            'permalink_url' => $t['permalink_url']       ?? '',
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

// Returns the 128 kbps MP3 progressive stream URL (works for all public tracks)
function scGetStreamUrl(string $trackId, string $clientId): string {
    $data = scGetJson('https://api-v2.soundcloud.com/tracks/' . $trackId
                    . '/streams?client_id=' . urlencode($clientId));
    // Prefer progressive mp3 stream, fall back to 128k http url
    return $data['http_mp3_128_url'] ?? $data['preview_mp3_128_url'] ?? '';
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
        "UPDATE soundcloud_tracks SET title=?, artist=?, artwork_url=?, permalink_url=?, local_filename=? WHERE sc_track_id=?"
    );
    $insertStmt = $db->prepare(
        "INSERT INTO soundcloud_tracks (profile_id, sc_track_id, title, artist, artwork_url, permalink_url, local_filename)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
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
                $t['permalink_url'], $localFile, $t['sc_track_id'],
            ]);
            $updated++;
        } else {
            $insertStmt->execute([
                $profileId, $t['sc_track_id'], $t['title'], $t['artist'],
                $t['artwork_url'], $t['permalink_url'], $localFile,
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
