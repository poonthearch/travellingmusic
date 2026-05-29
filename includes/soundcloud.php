<?php
require_once __DIR__ . '/db.php';

function scFetch(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; travellingmusic/1.0)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$body) return null;
    return json_decode($body, true);
}

function scResolveUser(string $username, string $clientId): ?array {
    $url = 'https://api-v2.soundcloud.com/resolve?' . http_build_query([
        'url'       => 'https://soundcloud.com/' . $username,
        'client_id' => $clientId,
    ]);
    return scFetch($url);
}

function scGetUserTracks(string $userId, string $clientId, int $limit = 200): array {
    $url = 'https://api-v2.soundcloud.com/users/' . $userId . '/tracks?' . http_build_query([
        'client_id'    => $clientId,
        'limit'        => min($limit, 200),
        'representation' => 'compact',
    ]);
    $data = scFetch($url);
    if (!$data || empty($data['collection'])) return [];

    $tracks = [];
    foreach ($data['collection'] as $t) {
        if (($t['kind'] ?? '') !== 'track') continue;

        $artwork = $t['artwork_url'] ?? '';
        // Upgrade to larger artwork
        if ($artwork) {
            $artwork = str_replace('-large.', '-t300x300.', $artwork);
        }

        $tracks[] = [
            'sc_track_id'   => (string)$t['id'],
            'title'         => $t['title']                  ?? 'Untitled',
            'artist'        => $t['user']['username']       ?? '',
            'artwork_url'   => $artwork,
            'permalink_url' => $t['permalink_url']          ?? '',
            'duration'      => (int)($t['duration']        ?? 0),
        ];
    }
    return $tracks;
}

function syncSoundCloudProfile(int $profileId): array {
    $db       = getDB();
    $clientId = getSetting('sc_client_id');

    if (empty($clientId)) {
        return ['success' => false, 'error' => 'SoundCloud client_id not configured in Settings.'];
    }

    $stmt = $db->prepare("SELECT * FROM soundcloud_profiles WHERE id = ?");
    $stmt->execute([$profileId]);
    $profile = $stmt->fetch();
    if (!$profile) return ['success' => false, 'error' => 'Profile not found.'];

    $user = scResolveUser($profile['username'], $clientId);
    if (!$user || empty($user['id'])) {
        return ['success' => false, 'error' => 'Could not resolve SoundCloud user "' . $profile['username'] . '". Check username and client_id.'];
    }

    $userId      = (string)$user['id'];
    $displayName = $user['username'] ?? $profile['username'];

    $tracks = scGetUserTracks($userId, $clientId);
    if (empty($tracks)) {
        return ['success' => false, 'error' => 'No public tracks found for this user.'];
    }

    $added   = 0;
    $updated = 0;

    $checkStmt = $db->prepare("SELECT id FROM soundcloud_tracks WHERE sc_track_id = ?");
    $updateStmt = $db->prepare(
        "UPDATE soundcloud_tracks SET title=?, artist=?, artwork_url=?, permalink_url=?, duration=? WHERE sc_track_id=?"
    );
    $insertStmt = $db->prepare(
        "INSERT INTO soundcloud_tracks (profile_id, sc_track_id, title, artist, artwork_url, permalink_url, duration)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($tracks as $t) {
        $checkStmt->execute([$t['sc_track_id']]);
        if ($checkStmt->fetchColumn()) {
            $updateStmt->execute([
                $t['title'], $t['artist'], $t['artwork_url'],
                $t['permalink_url'], $t['duration'], $t['sc_track_id'],
            ]);
            $updated++;
        } else {
            $insertStmt->execute([
                $profileId, $t['sc_track_id'], $t['title'], $t['artist'],
                $t['artwork_url'], $t['permalink_url'], $t['duration'],
            ]);
            $added++;
        }
    }

    $db->prepare("UPDATE soundcloud_profiles SET display_name=?, sc_user_id=?, last_synced=CURRENT_TIMESTAMP WHERE id=?")
       ->execute([$displayName, $userId, $profileId]);

    return ['success' => true, 'added' => $added, 'updated' => $updated, 'total' => count($tracks)];
}
