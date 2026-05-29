<?php
require_once __DIR__ . '/db.php';

function ytdlpBin(): string {
    static $bin = null;
    if ($bin !== null) return $bin;
    $candidates = ['yt-dlp', '/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp', '/usr/local/sbin/yt-dlp'];
    foreach ($candidates as $c) {
        $out = [];
        exec('which ' . escapeshellarg($c) . ' 2>/dev/null', $out, $ret);
        if ($ret === 0 && !empty($out[0])) {
            $bin = trim($out[0]);
            return $bin;
        }
    }
    $bin = '';
    return $bin;
}

function ffmpegAvailable(): bool {
    exec('which ffmpeg 2>/dev/null', $out, $ret);
    return $ret === 0 && !empty($out[0]);
}

function scDownloadDir(): string {
    $dir = dirname(__DIR__) . '/uploads/sc_music';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function syncSoundCloudProfile(int $profileId): array {
    $ytdlp = ytdlpBin();
    if (!$ytdlp) {
        return [
            'success' => false,
            'error'   => 'yt-dlp not found on this server. Install it with: pip install yt-dlp',
        ];
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM soundcloud_profiles WHERE id = ?");
    $stmt->execute([$profileId]);
    $profile = $stmt->fetch();
    if (!$profile) {
        return ['success' => false, 'error' => 'Profile not found.'];
    }

    $username = preg_replace('/[^a-zA-Z0-9_.-]/', '', $profile['username']);
    if (!$username) {
        return ['success' => false, 'error' => 'Invalid SoundCloud username.'];
    }

    $scUrl = 'https://soundcloud.com/' . $username;
    $dir   = scDownloadDir();

    set_time_limit(600);

    // Step 1: fetch track list without downloading
    $listCmd = escapeshellarg($ytdlp)
             . ' --flat-playlist --dump-json --no-warnings'
             . ' ' . escapeshellarg($scUrl)
             . ' 2>/dev/null';
    $lines = [];
    exec($listCmd, $lines);

    if (empty($lines)) {
        return [
            'success' => false,
            'error'   => 'No tracks found for soundcloud.com/' . $username
                       . '. Make sure the profile is public.',
        ];
    }

    $tracks = [];
    foreach ($lines as $line) {
        $info = json_decode(trim($line), true);
        if (!$info || empty($info['id'])) continue;
        $trackUrl = $info['webpage_url'] ?? $info['url'] ?? '';
        if (!$trackUrl) $trackUrl = 'https://soundcloud.com/' . $username . '/' . $info['id'];
        $tracks[] = [
            'id'        => (string)$info['id'],
            'title'     => $info['title']    ?? 'Untitled',
            'uploader'  => $info['uploader'] ?? $info['channel'] ?? $username,
            'thumbnail' => $info['thumbnail'] ?? '',
            'url'       => $trackUrl,
        ];
    }

    if (empty($tracks)) {
        return ['success' => false, 'error' => 'Could not parse track data from yt-dlp output.'];
    }

    $added    = 0;
    $updated  = 0;
    $dlFailed = 0;

    $checkStmt  = $db->prepare("SELECT id FROM soundcloud_tracks WHERE sc_track_id = ?");
    $updateStmt = $db->prepare(
        "UPDATE soundcloud_tracks SET title=?, artist=?, artwork_url=?, permalink_url=?, local_filename=? WHERE sc_track_id=?"
    );
    $insertStmt = $db->prepare(
        "INSERT INTO soundcloud_tracks (profile_id, sc_track_id, title, artist, artwork_url, permalink_url, local_filename)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($tracks as $t) {
        // Download the MP3 if not already on disk
        $localFile  = '';
        $outputGlob = $dir . '/' . $t['id'] . '.*';
        $existing   = glob($outputGlob);

        if (!empty($existing)) {
            $localFile = basename($existing[0]);
        } else {
            $dlCmd = escapeshellarg($ytdlp)
                   . ' -x --audio-format mp3 --audio-quality 0'
                   . ' --no-playlist --no-warnings'
                   . ' -o ' . escapeshellarg($dir . '/%(id)s.%(ext)s')
                   . ' ' . escapeshellarg($t['url'])
                   . ' 2>/dev/null';
            exec($dlCmd);

            $found = glob($outputGlob);
            if (!empty($found)) {
                $localFile = basename($found[0]);
            } else {
                $dlFailed++;
            }
        }

        $checkStmt->execute([$t['id']]);
        if ($checkStmt->fetchColumn()) {
            $updateStmt->execute([
                $t['title'], $t['uploader'], $t['thumbnail'],
                $t['url'], $localFile, $t['id'],
            ]);
            $updated++;
        } else {
            $insertStmt->execute([
                $profileId, $t['id'], $t['title'], $t['uploader'],
                $t['thumbnail'], $t['url'], $localFile,
            ]);
            $added++;
        }
    }

    $db->prepare("UPDATE soundcloud_profiles SET display_name=?, last_synced=CURRENT_TIMESTAMP WHERE id=?")
       ->execute([$username, $profileId]);

    $total = count($tracks);
    $msg   = "Sync done: {$added} new, {$updated} updated, {$total} total.";
    if ($dlFailed > 0) {
        $msg .= " {$dlFailed} track(s) could not be downloaded"
              . " (make sure \"Allow Downloads\" is enabled on SoundCloud for each track,"
              . (ffmpegAvailable() ? '' : ' and ffmpeg is installed for MP3 conversion')
              . ').';
    }

    return [
        'success' => true,
        'added'   => $added,
        'updated' => $updated,
        'total'   => $total,
        'msg'     => $msg,
    ];
}
