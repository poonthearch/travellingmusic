<?php
require_once __DIR__ . '/includes/db.php';

$db = getDB();

// Base URL path for resolving relative links stored in the DB
// e.g. if site lives at /www/travellingmusic.ru/, relative "slicer.php" → "/www/travellingmusic.ru/slicer.php"
$_sitePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');

// ── DB content ───────────────────────────────────────────────────────────────
$artists       = $db->query("SELECT * FROM artists ORDER BY sort_order ASC, id ASC")->fetchAll();
$merchItems    = $db->query("SELECT * FROM merch_items ORDER BY sort_order ASC, id ASC")->fetchAll();
$tracks        = $db->query("SELECT * FROM tracks ORDER BY sort_order ASC, id ASC")->fetchAll();
$guestReleases = $db->query("SELECT * FROM guest_releases ORDER BY sort_order ASC, id ASC")->fetchAll();
$scQuery = "SELECT st.*, sp.display_name AS profile_name, sp.section AS profile_section
            FROM soundcloud_tracks st
            JOIN soundcloud_profiles sp ON sp.id = st.profile_id
            ORDER BY st.sort_order ASC, st.id ASC";
$allScTracks     = $db->query($scQuery)->fetchAll();
$scTrackMusic    = array_filter($allScTracks, function($t) { return $t['profile_section'] === 'music'; });
$scTrackExternal = array_filter($allScTracks, function($t) { return $t['profile_section'] !== 'music'; });

// ── Backward-compat: legacy flat-file content ────────────────────────────────
// Reads the old file layout that existed before the DB rewrite.
// Content from files is appended after DB rows so existing deployments keep
// showing their uploaded material without any migration step.

// Legacy music files: music/*.mp3  (original dir was "muyysic" but support both)
$legacyTracks = array();
foreach (array('music', 'muyysic') as $_mdir) {
    if (!is_dir(__DIR__ . '/' . $_mdir)) continue;
    foreach (scandir(__DIR__ . '/' . $_mdir) as $_f) {
        if (strtolower(pathinfo($_f, PATHINFO_EXTENSION)) !== 'mp3') continue;
        $_base  = pathinfo($_f, PATHINFO_FILENAME);
        $_parts = explode('-', $_base);
        $_date  = isset($_parts[2]) ? implode('/', str_split(preg_replace('/[^0-9]/','',$_parts[2]), 2)) : '';
        $legacyTracks[] = array(
            'id'           => 'legacy_' . $_f,
            'filename'     => $_f,
            'title'        => $_parts[0] ?? $_base,
            'artist'       => $_parts[1] ?? '',
            'release_date' => $_date,
            '_legacy_dir'  => $_mdir,
        );
    }
}

// Legacy merch images: images/*.jpg|jpeg
$legacyMerch = array();
if (is_dir(__DIR__ . '/images')) {
    foreach (scandir(__DIR__ . '/images') as $_f) {
        $_ext = strtolower(pathinfo($_f, PATHINFO_EXTENSION));
        if (!in_array($_ext, array('jpg','jpeg','png','webp'))) continue;
        $_base  = pathinfo($_f, PATHINFO_FILENAME);
        $_parts = explode('-', $_base);
        $legacyMerch[] = array(
            'id'             => 'legacy_' . $_f,
            'image_filename' => $_f,
            'title'          => $_parts[0] ?? $_base,
            'size'           => $_parts[1] ?? '',
            'price'          => $_parts[2] ?? '',
            'about'          => isset($_parts[3]) ? str_replace('_', ' ', $_parts[3]) : '',
            '_legacy'        => true,
        );
    }
}

// Legacy artists: artists/artists.txt
$legacyArtists = array();
if (file_exists(__DIR__ . '/artists/artists.txt')) {
    foreach (file(__DIR__ . '/artists/artists.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        $_p = explode(' ', $_line, 3);
        $legacyArtists[] = array(
            'id'    => 'legacy_' . md5($_line),
            'name'  => $_p[0] ?? '',
            'links' => $_p[1] ?? '',
            'about' => $_p[2] ?? '',
        );
    }
}

// Legacy guest releases: guests/*.mp3  (paired with same-named image)
$legacyGuests = array();
if (is_dir(__DIR__ . '/guests')) {
    $_gImgs = array();
    foreach (scandir(__DIR__ . '/guests') as $_f) {
        $_ext = strtolower(pathinfo($_f, PATHINFO_EXTENSION));
        if (in_array($_ext, array('jpg','jpeg','png'))) {
            $_gImgs[pathinfo($_f, PATHINFO_FILENAME)] = $_f;
        }
    }
    foreach (scandir(__DIR__ . '/guests') as $_f) {
        if (strtolower(pathinfo($_f, PATHINFO_EXTENSION)) !== 'mp3') continue;
        $_base  = pathinfo($_f, PATHINFO_FILENAME);
        $_parts = explode('*', $_base);
        if (count($_parts) < 2) {
            // Simple filename fallback
            $_parts = array($_base, $_base, '', '');
        }
        $_tid   = $_parts[1] ?? $_base;
        $_art   = isset($_gImgs[$_tid]) ? $_gImgs[$_tid] : '';
        $_about = isset($_parts[3]) ? str_replace('_', ' ', $_parts[3]) : '';
        $legacyGuests[] = array(
            'id'               => 'legacy_' . $_f,
            'track_id'         => $_tid,
            'artist_name'      => $_parts[0] ?? '',
            'artwork_filename' => $_art,
            'about'            => $_about,
            'mp3_filename'     => $_f,
            '_legacy'          => true,
        );
    }
}

$siteTitle = getSetting('site_title') ?: 'travelling music™';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($siteTitle); ?></title>
    <style>
        :root {
            --primary-color: #000;
            --background-opacity: 0.9;
        }

        /* White sits on html so canvas stays visible */
        html { background: white; }

        body {
            margin: 0;
            padding: 0;
            background: transparent;
            font-family: Arial, sans-serif;
            position: relative;
            height: 100vh;
        }

        #fractal-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
        }

        .container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 10px;
        }

        .column {
            flex: 1 1 300px;
            min-width: calc(50% - 20px);
            max-width: calc(50% - 20px);
            box-sizing: border-box;
            background: rgba(255, 255, 255, 0.0);
            height: 45vh;
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 1;
        }

        h2 {
            font-size: 1.2em;
            margin: 0 0 10px 0;
            color: var(--primary-color);
            position: sticky;
            top: 0;
            background: rgba(255, 255, 255, 0);
            z-index: 2;
            padding: 5px;
            text-shadow: 0px 0px 5px white, 5px 0 1em white, 0 5px 1em white;
        }

        .table-container {
            overflow-x: auto;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            flex: 1;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(255, 255, 255, var(--background-opacity));
        }

        th, td {
            padding: 10px;
            font-size: 0.9em;
            border: 1px solid black;
            word-break: break-word;
        }

        img.thumb {
            width: 60px;
            height: 60px;
            object-fit: cover;
        }

        #player {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(255, 255, 255, 0.95);
            border-top: 1px solid black;
            padding: 10px 15px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        }

        #now-playing {
            font-size: 14px;
            margin-bottom: 10px;
            color: #333;
            font-weight: bold;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        #player-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        #progress-container {
            flex-grow: 1;
            height: 4px;
            background: #e5e5e5;
            border-radius: 2px;
            margin: 0 10px;
            cursor: pointer;
        }

        #progress-bar {
            height: 100%;
            background: black;
            border-radius: 2px;
            width: 0%;
            transition: width 0.1s linear;
        }

        #time-display {
            font-size: 12px;
            color: black;
            min-width: 80px;
            text-align: center;
        }

        .player-btn {
            background: transparent;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #333;
            transition: all 0.2s;
        }

        .player-btn:hover { background: #f0f0f0; }
        .player-btn:active { transform: scale(0.95); }
        .player-btn.play-pause { background: black; color: white; }
        .player-btn.play-pause:hover { background: grey; }

        button.play-btn {
            background: transparent;
            border: 1px solid black;
            padding: 4px 10px;
            cursor: pointer;
            font-size: 0.85em;
            color: black;
        }
        button.play-btn:hover { background: #f0f0f0; }

        .modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background-color: rgba(255,255,255,0.98);
            cursor: pointer;
            overflow: hidden;
        }
        .modal-content {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            max-width: 95vw;
            max-height: 95vh;
            object-fit: contain;
            pointer-events: none;
        }
        .close-btn {
            position: fixed;
            right: 25px; top: 25px;
            font-size: 40px;
            color: #000;
            cursor: pointer;
            z-index: 1002;
            line-height: 1;
            transition: transform 0.3s;
        }
        .close-btn:hover { transform: rotate(90deg) scale(1.2); }

        /* SC widget hidden iframe */
        #sc-widget-container { display: none; }

        @media (max-width: 768px) {
            body { padding-bottom: 120px; }
            .column { flex: 1 1 100%; max-width: 100%; min-width: unset; }
            h2 { font-size: 1.1em; }
            th, td { padding: 8px; font-size: 0.85em; }
            img.thumb { width: 50px; height: 50px; }
        }
        @media (max-width: 480px) {
            th, td { padding: 6px; font-size: 0.8em; }
            img.thumb { width: 40px; height: 40px; }
        }
    </style>
</head>
<body>

<canvas id="fractal-canvas"></canvas>

<div class="container">

    <!-- MERCH -->
    <div class="column" style="height:45vh">
        <h2>./travelling/items</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th width="10%">Pic</th>
                        <th width="15%">Title</th>
                        <th width="10%">Size</th>
                        <th width="10%">Price</th>
                        <th>About</th>
                        <th width="10%">Buy</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($merchItems as $item): ?>
                    <tr>
                        <td>
                            <?php if ($item['image_filename']): ?>
                            <img class="thumb"
                                 src="uploads/images/<?php echo htmlspecialchars($item['image_filename']); ?>"
                                 alt="<?php echo htmlspecialchars($item['title']); ?>"
                                 onclick="openModal(this.src)"
                                 style="cursor:zoom-in">
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($item['title']); ?></td>
                        <td><?php echo htmlspecialchars($item['size']); ?></td>
                        <td><?php echo htmlspecialchars($item['price']); ?>$</td>
                        <td><?php echo htmlspecialchars($item['about']); ?></td>
                        <td><a style="color:black" href="mailto:sell@travellingmusic.ru?subject=merch">link</a></td>
                    </tr>
                    <?php endforeach; ?>
                    <!-- Legacy merch from images/ -->
                    <?php foreach ($legacyMerch as $item): ?>
                    <tr>
                        <td>
                            <img class="thumb"
                                 src="images/<?php echo htmlspecialchars($item['image_filename']); ?>"
                                 alt="<?php echo htmlspecialchars($item['title']); ?>"
                                 onclick="openModal(this.src)"
                                 style="cursor:zoom-in">
                        </td>
                        <td><?php echo htmlspecialchars($item['title']); ?></td>
                        <td><?php echo htmlspecialchars($item['size']); ?></td>
                        <td><?php echo htmlspecialchars($item['price']); ?>$</td>
                        <td><?php echo htmlspecialchars($item['about']); ?></td>
                        <td><a style="color:black" href="mailto:sell@travellingmusic.ru?subject=merch">link</a></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($merchItems) && empty($legacyMerch)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#999">no items yet</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MUSIC TRACKS -->
    <div class="column" style="height:45vh">
        <h2>./travelling/music</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Artist</th>
                        <th width="18%">Release Date</th>
                        <th width="10%">Play</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tracks as $track): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($track['title']); ?></td>
                        <td><?php echo htmlspecialchars($track['artist']); ?></td>
                        <td><?php echo htmlspecialchars($track['release_date']); ?></td>
                        <td>
                            <button class="play-btn" onclick="playLocal(
                                'uploads/music/<?php echo htmlspecialchars($track['filename']); ?>',
                                '<?php echo htmlspecialchars(addslashes($track['title'])); ?>',
                                '<?php echo htmlspecialchars(addslashes($track['artist'])); ?>'
                            )">Play</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <!-- SoundCloud tracks assigned to music section -->
                    <?php foreach ($scTrackMusic as $sc): ?>
                    <tr>
                        <td>
                            <a href="<?php echo htmlspecialchars($sc['permalink_url']); ?>" target="_blank" style="color:black;text-decoration:none">
                            <?php if ($sc['artwork_url']): ?>
                            <img class="thumb" src="<?php echo htmlspecialchars($sc['artwork_url']); ?>" alt="" style="vertical-align:middle;margin-right:4px">
                            <?php endif; ?>
                            <?php echo htmlspecialchars($sc['title']); ?></a>
                        </td>
                        <td><?php echo htmlspecialchars($sc['artist']); ?></td>
                        <td><?php echo htmlspecialchars($sc['release_date'] ?? ''); ?></td>
                        <td>
                            <?php if ($sc['local_filename']): ?>
                            <button class="play-btn" onclick="playLocal(
                                'uploads/sc_music/<?php echo htmlspecialchars($sc['local_filename']); ?>',
                                '<?php echo htmlspecialchars(addslashes($sc['title'])); ?>',
                                '<?php echo htmlspecialchars(addslashes($sc['artist'])); ?>'
                            )">Play</button>
                            <?php else: ?>
                            <button class="play-btn" onclick="playSC(
                                '<?php echo htmlspecialchars(addslashes($sc['permalink_url'])); ?>',
                                '<?php echo htmlspecialchars(addslashes($sc['title'])); ?>',
                                '<?php echo htmlspecialchars(addslashes($sc['artist'])); ?>'
                            )">Play</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <!-- Legacy tracks from music/ or muyysic/ -->
                    <?php foreach ($legacyTracks as $track): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($track['title']); ?></td>
                        <td><?php echo htmlspecialchars($track['artist']); ?></td>
                        <td><?php echo htmlspecialchars($track['release_date']); ?></td>
                        <td>
                            <button class="play-btn" onclick="playLocal(
                                '<?php echo htmlspecialchars($track['_legacy_dir']); ?>/<?php echo htmlspecialchars($track['filename']); ?>',
                                '<?php echo htmlspecialchars(addslashes($track['title'])); ?>',
                                '<?php echo htmlspecialchars(addslashes($track['artist'])); ?>'
                            )">Play</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($tracks) && empty($scTrackMusic) && empty($legacyTracks)): ?>
                    <tr><td colspan="4" style="text-align:center;color:#999">no tracks yet</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ARTISTS / PROJECTS -->
    <div class="column" style="height:37vh">
        <h2>./travelling/projects</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th width="25%">Name</th>
                        <th width="20%">Links</th>
                        <th>About</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_merge($artists, $legacyArtists) as $artist): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($artist['name']); ?></td>
                        <td><?php
                            $lnk = trim($artist['links']);
                            if ($lnk) {
                                $href = preg_match('#^(https?://|//|/|#|mailto:)#i', $lnk) ? $lnk : $_sitePath . '/' . $lnk;
                                $target = (strpos($lnk, 'http') === 0) ? ' target="_blank"' : '';
                                echo '<a style="color:black" href="' . htmlspecialchars($href) . '"' . $target . '>'
                                   . htmlspecialchars($lnk) . '</a>';
                            }
                        ?></td>
                        <td><?php echo htmlspecialchars($artist['about']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($artists) && empty($legacyArtists)): ?>
                    <tr><td colspan="3" style="text-align:center;color:#999">no projects yet</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- EXTERNAL RELEASES (guest + soundcloud) -->
    <div class="column" style="height:37vh; margin-bottom:10vh">
        <h2>./travelling/external_releases</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th width="20%">Title</th>
                        <th>Artist</th>
                        <th width="60px">Art</th>
                        <th>About</th>
                        <th width="17%">Play</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Uploaded guest releases -->
                    <?php foreach ($guestReleases as $g): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($g['track_id']); ?></td>
                        <td><?php echo htmlspecialchars($g['artist_name']); ?></td>
                        <td>
                            <?php if ($g['artwork_filename']): ?>
                            <img class="thumb"
                                 src="uploads/guests/<?php echo htmlspecialchars($g['artwork_filename']); ?>"
                                 alt="<?php echo htmlspecialchars($g['track_id']); ?>">
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($g['about']): ?>
                            <?php $_href = preg_match('#^(https?://|//|/|#|mailto:)#i', $g['about']) ? $g['about'] : $_sitePath . '/' . $g['about']; ?>
                            <?php $_atgt = (strpos($g['about'], 'http') === 0) ? ' target="_blank"' : ''; ?>
                            <a style="color:black" href="<?php echo htmlspecialchars($_href); ?>"<?php echo $_atgt; ?>><?php echo htmlspecialchars($g['about']); ?></a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="play-btn" onclick="playLocal(
                                'uploads/guests/<?php echo htmlspecialchars($g['mp3_filename']); ?>',
                                '<?php echo htmlspecialchars(addslashes($g['track_id'])); ?>',
                                '<?php echo htmlspecialchars(addslashes($g['artist_name'])); ?>'
                            )">Play</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- SoundCloud tracks assigned to external section -->
                    <?php foreach ($scTrackExternal as $sc): ?>
                    <tr>
                        <td><a href="<?php echo htmlspecialchars($sc['permalink_url']); ?>" target="_blank" style="color:black;text-decoration:none"><?php echo htmlspecialchars($sc['title']); ?></a></td>
                        <td><?php echo htmlspecialchars($sc['artist']); ?></td>
                        <td>
                            <?php if ($sc['artwork_url']): ?>
                            <img class="thumb"
                                 src="<?php echo htmlspecialchars($sc['artwork_url']); ?>"
                                 alt="<?php echo htmlspecialchars($sc['title']); ?>">
                            <?php endif; ?>
                        </td>
                        <td>
                            <a style="color:black" href="<?php echo htmlspecialchars($sc['permalink_url']); ?>" target="_blank">SC</a>
                        </td>
                        <td>
                            <?php if ($sc['local_filename']): ?>
                            <button class="play-btn" onclick="playLocal(
                                'uploads/sc_music/<?php echo htmlspecialchars($sc['local_filename']); ?>',
                                '<?php echo htmlspecialchars(addslashes($sc['title'])); ?>',
                                '<?php echo htmlspecialchars(addslashes($sc['artist'] ?: $sc['profile_name'])); ?>'
                            )">Play</button>
                            <?php else: ?>
                            <button class="play-btn" onclick="playSC(
                                '<?php echo htmlspecialchars(addslashes($sc['permalink_url'])); ?>',
                                '<?php echo htmlspecialchars(addslashes($sc['title'])); ?>',
                                '<?php echo htmlspecialchars(addslashes($sc['artist'] ?: $sc['profile_name'])); ?>'
                            )">Play</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- Legacy guest releases from guests/ -->
                    <?php foreach ($legacyGuests as $g): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($g['track_id']); ?></td>
                        <td><?php echo htmlspecialchars($g['artist_name']); ?></td>
                        <td>
                            <?php if ($g['artwork_filename']): ?>
                            <img class="thumb"
                                 src="guests/<?php echo htmlspecialchars($g['artwork_filename']); ?>"
                                 alt="<?php echo htmlspecialchars($g['track_id']); ?>">
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($g['about']): ?>
                            <?php $_href = preg_match('#^(https?://|//|/|#|mailto:)#i', $g['about']) ? $g['about'] : $_sitePath . '/' . $g['about']; ?>
                            <?php $_atgt = (strpos($g['about'], 'http') === 0) ? ' target="_blank"' : ''; ?>
                            <a style="color:black" href="<?php echo htmlspecialchars($_href); ?>"<?php echo $_atgt; ?>><?php echo htmlspecialchars($g['about']); ?></a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="play-btn" onclick="playLocal(
                                'guests/<?php echo htmlspecialchars($g['mp3_filename']); ?>',
                                '<?php echo htmlspecialchars(addslashes($g['track_id'])); ?>',
                                '<?php echo htmlspecialchars(addslashes($g['artist_name'])); ?>'
                            )">Play</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($guestReleases) && empty($scTrackExternal) && empty($legacyGuests)): ?>
                    <tr><td colspan="5" style="text-align:center;color:#999">no external releases yet</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /.container -->

<!-- PLAYER -->
<div id="player">
    <div id="now-playing">
        <span id="track-name">No track selected</span>
        <span id="artist-name"></span>
    </div>
    <div id="player-controls">
        <button class="player-btn" onclick="previousTrack()" title="Previous">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
        </button>
        <button class="player-btn play-pause" onclick="togglePlay()" id="play-pause-btn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="play-icon">
                <path d="M5 3l14 9-14 9V3z"/>
            </svg>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="pause-icon" style="display:none">
                <path d="M6 4h4v16H6zM14 4h4v16h-4z"/>
            </svg>
        </button>
        <button class="player-btn" onclick="nextTrack()" title="Next">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
        </button>
        <div id="progress-container" onclick="seek(event)">
            <div id="progress-bar"></div>
        </div>
        <div id="time-display">0:00 / 0:00</div>
    </div>
</div>

<!-- HTML5 audio for local files -->
<audio id="audio-player" style="display:none"></audio>

<!-- SoundCloud widget (hidden) -->
<div id="sc-widget-container">
    <iframe id="sc-widget"
            width="100%" height="166"
            scrolling="no" frameborder="no"
            allow="autoplay"
            src="about:blank"></iframe>
</div>

<!-- Image modal -->
<div class="modal" onclick="closeModal()">
    <span class="close-btn" onclick="closeModal()">&times;</span>
    <img class="modal-content" id="expanded-img">
</div>

<script src="https://w.soundcloud.com/player/api.js"></script>
<script>
/* ======================================================
   All JS in global scope — no IIFEs, var throughout,
   json_encode for all PHP→JS data transfer.
   ====================================================== */

// ── fractal canvas ──────────────────────────────────────
var fractalCanvas  = document.getElementById('fractal-canvas');
var fractalCtx     = fractalCanvas.getContext('2d');
var fractalAnimId;
var fractalLast    = 0;
var FPS_INTERVAL   = 1000 / 30;
var coefficients   = [0.5, 0.5, 0.5, 0.5];
var fractalTargets = [
    Math.random() * 0.6 + 0.2,
    Math.random() * 0.6 + 0.2,
    Math.random() * 0.6 + 0.2,
    Math.random() * 0.6 + 0.2
];
var fractalSpeed   = 0.02;

function fractalResize() {
    fractalCanvas.width  = window.innerWidth;
    fractalCanvas.height = window.innerHeight;
}

function drawGasket(x, y, r, depth) {
    if (depth <= 0) return;
    fractalCtx.beginPath();
    fractalCtx.arc(x, y, Math.max(1, r * coefficients[1]), 0, Math.PI * 2);
    fractalCtx.stroke();
    if (depth > 1) {
        var nr = r * 0.5;
        drawGasket(x - r * 0.5, y,       nr, depth - 1);
        drawGasket(x + r * 0.5, y,       nr, depth - 1);
        drawGasket(x,           y - r * 0.5, nr, depth - 1);
        drawGasket(x,           y + r * 0.5, nr, depth - 1);
    }
}

function fractalTick(ts) {
    if (ts - fractalLast < FPS_INTERVAL) {
        fractalAnimId = requestAnimationFrame(fractalTick);
        return;
    }
    fractalLast = ts;

    fractalCtx.fillStyle = 'rgba(255,255,255,0.05)';
    fractalCtx.fillRect(0, 0, fractalCanvas.width, fractalCanvas.height);

    if (isPlaying && analyserNode && freqData) {
        analyserNode.getByteFrequencyData(freqData);
        var bass = freqData[0] / 255;
        var mid  = freqData[10] / 255;
        var hi   = freqData[50] / 255;
        fractalTargets[0] = 0.2 + bass * 2;
        fractalTargets[1] = 0.2 + mid  * 2;
        fractalTargets[2] = 0.2 + hi   * 2;
        fractalTargets[3] = 0.2 + (bass + mid + hi) / 3 * 2;
        fractalSpeed      = 0.02 + (bass + mid + hi) / 3 * 0.08;
        fractalCtx.lineWidth = 0.5 + bass * 2;
    } else {
        fractalSpeed         = 0.02;
        fractalCtx.lineWidth = 0.5;
    }

    fractalCtx.strokeStyle = 'rgba(0,0,0,0.7)';
    var cx = fractalCanvas.width  / 2;
    var cy = fractalCanvas.height / 2;
    var cr = Math.min(fractalCanvas.width, fractalCanvas.height) * 2;
    drawGasket(cx, cy, cr, 4);

    for (var i = 0; i < 4; i++) {
        coefficients[i] += (fractalTargets[i] - coefficients[i]) * fractalSpeed;
        if (Math.abs(coefficients[i] - fractalTargets[i]) < 0.01) {
            fractalTargets[i] = Math.random() * 0.6 + 0.2;
        }
    }

    fractalAnimId = requestAnimationFrame(fractalTick);
}

function fractalStart() {
    fractalResize();
    fractalAnimId = requestAnimationFrame(fractalTick);
}

window.addEventListener('resize', fractalResize);
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        cancelAnimationFrame(fractalAnimId);
    } else {
        fractalAnimId = requestAnimationFrame(fractalTick);
    }
});
fractalStart();

// ── audio player ────────────────────────────────────────
var isPlaying    = false;
var currentMode  = null;
var audioCtx     = null;
var analyserNode = null;
var freqData     = null;
var sourceNode   = null;
var scWidget     = null;

var audioEl  = document.getElementById('audio-player');
var scIframe = document.getElementById('sc-widget');

function setupAnalyser() {
    if (audioCtx) return;
    try {
        audioCtx     = new (window.AudioContext || window.webkitAudioContext)();
        analyserNode = audioCtx.createAnalyser();
        analyserNode.fftSize = 256;
        sourceNode   = audioCtx.createMediaElementSource(audioEl);
        sourceNode.connect(analyserNode);
        analyserNode.connect(audioCtx.destination);
        freqData     = new Uint8Array(analyserNode.frequencyBinCount);
    } catch (e) {
        console.warn('AudioContext setup failed:', e);
        audioCtx = null;
    }
}

function fmtTime(s) {
    s = Math.floor(s);
    return Math.floor(s / 60) + ':' + (s % 60 < 10 ? '0' : '') + (s % 60);
}

function updateIcon() {
    document.getElementById('play-icon').style.display  = isPlaying ? 'none'  : 'block';
    document.getElementById('pause-icon').style.display = isPlaying ? 'block' : 'none';
}

function setNowPlaying(title, artist) {
    document.getElementById('track-name').textContent  = title;
    document.getElementById('artist-name').textContent = artist ? ' - ' + artist : '';
}

var localPlaylist = [];
var scPlaylist    = [];
var localIdx      = 0;
var scIdx         = 0;
var playlistMode  = 'local';

function startPlay() {
    var promise = audioEl.play();
    if (promise !== undefined) {
        promise.then(function() {
            isPlaying = true;
            updateIcon();
        }).catch(function(err) {
            console.warn('play() failed:', err);
        });
    } else {
        isPlaying = true;
        updateIcon();
    }
}

function playLocal(url, title, artist) {
    if (currentMode === 'sc' && scWidget) scWidget.pause();
    currentMode  = 'local';
    playlistMode = 'local';

    setupAnalyser();
    audioEl.src = url;

    if (audioCtx) {
        audioCtx.resume().then(function() { startPlay(); }).catch(function() { startPlay(); });
    } else {
        startPlay();
    }

    setNowPlaying(title, artist);

    for (var i = 0; i < localPlaylist.length; i++) {
        if (localPlaylist[i].url === url) { localIdx = i; break; }
    }
}

function playSC(permalinkUrl, title, artist) {
    if (currentMode === 'local') { audioEl.pause(); isPlaying = false; updateIcon(); }
    currentMode  = 'sc';
    playlistMode = 'sc';

    scIframe.src = 'https://w.soundcloud.com/player/?url=' +
        encodeURIComponent(permalinkUrl) +
        '&auto_play=true&hide_related=true&show_comments=false' +
        '&show_user=false&show_reposts=false&show_teaser=false';

    setTimeout(function() {
        if (typeof SC === 'undefined') return;
        scWidget = SC.Widget(scIframe);
        scWidget.bind(SC.Widget.Events.READY, function() { scWidget.play(); });
        scWidget.bind(SC.Widget.Events.PLAY,  function() { isPlaying = true;  updateIcon(); });
        scWidget.bind(SC.Widget.Events.PAUSE, function() { isPlaying = false; updateIcon(); });
        scWidget.bind(SC.Widget.Events.FINISH,function() { isPlaying = false; updateIcon(); nextTrack(); });
        scWidget.bind(SC.Widget.Events.PLAY_PROGRESS, function(e) {
            document.getElementById('progress-bar').style.width = (e.relativePosition * 100) + '%';
            scWidget.getDuration(function(dur) {
                document.getElementById('time-display').textContent =
                    fmtTime(e.currentPosition / 1000) + ' / ' + fmtTime(dur / 1000);
            });
        });
    }, 400);

    isPlaying = true;
    updateIcon();
    setNowPlaying(title, artist);

    for (var i = 0; i < scPlaylist.length; i++) {
        if (scPlaylist[i].url === permalinkUrl) { scIdx = i; break; }
    }
}

function togglePlay() {
    if (currentMode === 'local') {
        if (isPlaying) {
            audioEl.pause();
        } else {
            if (audioCtx && audioCtx.state === 'suspended') {
                audioCtx.resume().then(function() { startPlay(); });
            } else { startPlay(); }
        }
    } else if (currentMode === 'sc' && scWidget) {
        if (isPlaying) scWidget.pause(); else scWidget.play();
    }
}

function seek(e) {
    var rect = document.getElementById('progress-container').getBoundingClientRect();
    var pos  = (e.clientX - rect.left) / rect.width;
    if (currentMode === 'local' && audioEl.duration) {
        audioEl.currentTime = pos * audioEl.duration;
    } else if (currentMode === 'sc' && scWidget) {
        scWidget.getDuration(function(dur) { scWidget.seekTo(pos * dur); });
    }
}

function previousTrack() {
    var t;
    if (playlistMode === 'sc') {
        if (scIdx > 0) { scIdx--; t = scPlaylist[scIdx]; playSC(t.url, t.title, t.artist); }
    } else {
        if (localIdx > 0) { localIdx--; t = localPlaylist[localIdx]; playLocal(t.url, t.title, t.artist); }
    }
}

function nextTrack() {
    var t;
    if (playlistMode === 'sc') {
        if (scIdx < scPlaylist.length - 1) { scIdx++; t = scPlaylist[scIdx]; playSC(t.url, t.title, t.artist); }
    } else {
        if (localIdx < localPlaylist.length - 1) { localIdx++; t = localPlaylist[localIdx]; playLocal(t.url, t.title, t.artist); }
    }
}

// ── playlists (json_encode is safe in <script> blocks) ──
<?php
// Build local playlist array in PHP, then dump as JSON
$jsLocal = array();
foreach ($tracks as $t) {
    $jsLocal[] = array(
        'url'    => 'uploads/music/' . $t['filename'],
        'title'  => $t['title'],
        'artist' => $t['artist'],
    );
}
foreach ($guestReleases as $g) {
    $jsLocal[] = array(
        'url'    => 'uploads/guests/' . $g['mp3_filename'],
        'title'  => $g['track_id'],
        'artist' => $g['artist_name'],
    );
}
foreach ($legacyTracks as $t) {
    $jsLocal[] = array(
        'url'    => $t['_legacy_dir'] . '/' . $t['filename'],
        'title'  => $t['title'],
        'artist' => $t['artist'],
    );
}
foreach ($legacyGuests as $g) {
    $jsLocal[] = array(
        'url'    => 'guests/' . $g['mp3_filename'],
        'title'  => $g['track_id'],
        'artist' => $g['artist_name'],
    );
}
foreach ($allScTracks as $sc) {
    if ($sc['local_filename']) {
        $jsLocal[] = array(
            'url'    => 'uploads/sc_music/' . $sc['local_filename'],
            'title'  => $sc['title'],
            'artist' => $sc['artist'],
        );
    }
}
$jsSC = array();
foreach ($allScTracks as $sc) {
    if (!$sc['local_filename']) {
        $jsSC[] = array(
            'url'    => $sc['permalink_url'],
            'title'  => $sc['title'],
            'artist' => $sc['artist'],
        );
    }
}
?>
localPlaylist = <?php echo json_encode($jsLocal, JSON_UNESCAPED_UNICODE); ?>;
scPlaylist    = <?php echo json_encode($jsSC, JSON_UNESCAPED_UNICODE); ?>;

// HTML5 audio events
audioEl.addEventListener('timeupdate', function() {
    if (currentMode !== 'local' || !audioEl.duration) return;
    var pct = (audioEl.currentTime / audioEl.duration) * 100;
    document.getElementById('progress-bar').style.width = pct + '%';
    document.getElementById('time-display').textContent =
        fmtTime(audioEl.currentTime) + ' / ' + fmtTime(audioEl.duration);
});
audioEl.addEventListener('ended',  function() { isPlaying = false; updateIcon(); nextTrack(); });
audioEl.addEventListener('play',   function() { isPlaying = true;  updateIcon(); });
audioEl.addEventListener('pause',  function() { isPlaying = false; updateIcon(); });
audioEl.volume = 0.7;

// Modal
function openModal(src) {
    document.getElementById('expanded-img').src = src;
    document.querySelector('.modal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}
function closeModal() {
    document.querySelector('.modal').style.display = 'none';
    document.body.style.overflow = 'auto';
}
</script>
</body>
</html>
