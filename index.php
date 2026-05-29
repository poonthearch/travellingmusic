<?php
require_once __DIR__ . '/includes/db.php';

$db = getDB();

$artists       = $db->query("SELECT * FROM artists ORDER BY sort_order ASC, id ASC")->fetchAll();
$merchItems    = $db->query("SELECT * FROM merch_items ORDER BY sort_order ASC, id ASC")->fetchAll();
$tracks        = $db->query("SELECT * FROM tracks ORDER BY sort_order ASC, id ASC")->fetchAll();
$guestReleases = $db->query("SELECT * FROM guest_releases ORDER BY sort_order ASC, id ASC")->fetchAll();
$scQuery = "SELECT st.*, sp.display_name AS profile_name, sp.section AS profile_section
            FROM soundcloud_tracks st
            JOIN soundcloud_profiles sp ON sp.id = st.profile_id
            ORDER BY st.sort_order ASC, st.id ASC";
$allScTracks    = $db->query($scQuery)->fetchAll();
$scTrackMusic   = array_filter($allScTracks, fn($t) => $t['profile_section'] === 'music');
$scTrackExternal= array_filter($allScTracks, fn($t) => $t['profile_section'] !== 'music');

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

        body {
            margin: 0;
            padding: 0;
            background: white;
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
            z-index: -1;
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
                    <?php if (empty($merchItems)): ?>
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
                            <?php if ($sc['artwork_url']): ?>
                            <img class="thumb" src="<?php echo htmlspecialchars($sc['artwork_url']); ?>" alt="" style="vertical-align:middle;margin-right:4px">
                            <?php endif; ?>
                            <?php echo htmlspecialchars($sc['title']); ?>
                        </td>
                        <td><?php echo htmlspecialchars($sc['artist'] ?: $sc['profile_name']); ?></td>
                        <td></td>
                        <td>
                            <button class="play-btn" onclick="playSC(
                                '<?php echo htmlspecialchars(addslashes($sc['permalink_url'])); ?>',
                                '<?php echo htmlspecialchars(addslashes($sc['title'])); ?>',
                                '<?php echo htmlspecialchars(addslashes($sc['artist'] ?: $sc['profile_name'])); ?>'
                            )">Play</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($tracks) && empty($scTrackMusic)): ?>
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
                    <?php foreach ($artists as $artist): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($artist['name']); ?></td>
                        <td><?php echo htmlspecialchars($artist['links']); ?></td>
                        <td><?php echo htmlspecialchars($artist['about']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($artists)): ?>
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
                            <a style="color:black" href="<?php echo htmlspecialchars($g['about']); ?>" target="_blank">details</a>
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
                        <td><?php echo htmlspecialchars($sc['title']); ?></td>
                        <td><?php echo htmlspecialchars($sc['artist'] ?: $sc['profile_name']); ?></td>
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
                            <button class="play-btn" onclick="playSC(
                                '<?php echo htmlspecialchars(addslashes($sc['permalink_url'])); ?>',
                                '<?php echo htmlspecialchars(addslashes($sc['title'])); ?>',
                                '<?php echo htmlspecialchars(addslashes($sc['artist'] ?: $sc['profile_name'])); ?>'
                            )">Play</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if (empty($guestReleases) && empty($scTrackExternal)): ?>
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
// ---- fractal background ----
(function() {
    const canvas = document.getElementById('fractal-canvas');
    const ctx    = canvas.getContext('2d');
    let animId, lastTime = 0;
    const FPS_INTERVAL = 1000 / 30;
    let coefficients = [0.5, 0.5, 0.5, 0.5];
    let targets      = coefficients.map(() => Math.random() * 0.6 + 0.2);
    let speed        = 0.02;

    function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }

    function drawGasket(x, y, r, depth) {
        if (depth <= 0) return;
        ctx.beginPath();
        ctx.arc(x, y, r * coefficients[1], 0, Math.PI * 2);
        ctx.stroke();
        if (depth > 1) {
            const nr = r * 0.5;
            drawGasket(x - r * 0.5, y, nr, depth - 1);
            drawGasket(x + r * 0.5, y, nr, depth - 1);
            drawGasket(x, y - r * 0.5, nr, depth - 1);
            drawGasket(x, y + r * 0.5, nr, depth - 1);
        }
    }

    function tick(ts) {
        if (ts - lastTime < FPS_INTERVAL) { animId = requestAnimationFrame(tick); return; }
        lastTime = ts;

        ctx.fillStyle = 'rgba(255,255,255,0.05)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        if (isPlaying && analyserNode) {
            analyserNode.getByteFrequencyData(freqData);
            const bass = freqData[0] / 255, mid = freqData[10] / 255, hi = freqData[50] / 255;
            targets[0] = 0.2 + bass * 2; targets[1] = 0.2 + mid * 2;
            targets[2] = 0.2 + hi * 2;   targets[3] = 0.2 + (bass + mid + hi) / 3 * 2;
            speed = 0.02 + (bass + mid + hi) / 3 * 0.08;
            ctx.lineWidth = 0.5 + bass * 2;
        } else {
            speed = 0.02; ctx.lineWidth = 0.5;
        }

        ctx.strokeStyle = 'rgba(0,0,0,0.7)';
        drawGasket(canvas.width / 2, canvas.height / 2, Math.min(canvas.width, canvas.height) * 2, 4);

        for (let i = 0; i < 4; i++) {
            coefficients[i] += (targets[i] - coefficients[i]) * speed;
            if (Math.abs(coefficients[i] - targets[i]) < 0.01) targets[i] = Math.random() * 0.6 + 0.2;
        }
        animId = requestAnimationFrame(tick);
    }

    resize();
    window.addEventListener('resize', resize);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) cancelAnimationFrame(animId);
        else { animId = requestAnimationFrame(tick); }
    });
    animId = requestAnimationFrame(tick);
})();

// ---- player ----
let isPlaying    = false;
let currentMode  = null;  // 'local' | 'sc'
let audioCtx     = null;
let analyserNode = null;
let freqData     = null;
let sourceNode   = null;
let scWidget     = null;
let scReady      = false;

const audioEl   = document.getElementById('audio-player');
const scIframe  = document.getElementById('sc-widget');

function setupAnalyser() {
    if (audioCtx) return;
    audioCtx     = new (window.AudioContext || window.webkitAudioContext)();
    analyserNode = audioCtx.createAnalyser();
    analyserNode.fftSize = 256;
    sourceNode   = audioCtx.createMediaElementSource(audioEl);
    sourceNode.connect(analyserNode);
    analyserNode.connect(audioCtx.destination);
    freqData     = new Uint8Array(analyserNode.frequencyBinCount);
}

function initSCWidget() {
    if (scWidget) return;
    scWidget = SC.Widget(scIframe);
    scWidget.bind(SC.Widget.Events.READY, function() { scReady = true; });
    scWidget.bind(SC.Widget.Events.PLAY,  function() { isPlaying = true;  updateIcon(); });
    scWidget.bind(SC.Widget.Events.PAUSE, function() { isPlaying = false; updateIcon(); });
    scWidget.bind(SC.Widget.Events.FINISH,function() { isPlaying = false; updateIcon(); nextTrack(); });
    scWidget.bind(SC.Widget.Events.PLAY_PROGRESS, function(e) {
        const bar  = document.getElementById('progress-bar');
        const disp = document.getElementById('time-display');
        bar.style.width = (e.relativePosition * 100) + '%';
        const cur  = Math.floor(e.currentPosition / 1000);
        scWidget.getDuration(function(dur) {
            const total = Math.floor(dur / 1000);
            disp.textContent = fmtTime(cur) + ' / ' + fmtTime(total);
        });
    });
}

function fmtTime(s) {
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
}

// Playlist state
const localPlaylist = [];   // {url, title, artist}
const scPlaylist    = [];   // {url, title, artist}
let   localIdx      = 0;
let   scIdx         = 0;
let   playlistMode  = 'local'; // which list prev/next operates on

function playLocal(url, title, artist) {
    if (currentMode === 'sc' && scWidget) scWidget.pause();
    currentMode  = 'local';
    playlistMode = 'local';

    setupAnalyser();
    if (audioCtx.state === 'suspended') audioCtx.resume();

    audioEl.src = url;
    audioEl.play();
    isPlaying = true;
    updateIcon();
    setNowPlaying(title, artist);

    localIdx = localPlaylist.findIndex(t => t.url === url);
    if (localIdx === -1) localIdx = 0;
}

function playSC(permalinkUrl, title, artist) {
    if (currentMode === 'local') audioEl.pause();
    currentMode  = 'sc';
    playlistMode = 'sc';

    if (!scWidget) initSCWidget();

    const widgetUrl = 'https://w.soundcloud.com/player/?url=' +
        encodeURIComponent(permalinkUrl) +
        '&auto_play=true&hide_related=true&show_comments=false&show_user=false&show_reposts=false&show_teaser=false';

    scIframe.src = widgetUrl;

    // Re-bind after src change
    setTimeout(function() {
        scWidget = SC.Widget(scIframe);
        scReady  = false;
        scWidget.bind(SC.Widget.Events.READY, function() {
            scReady = true;
            scWidget.play();
            isPlaying = true;
            updateIcon();
        });
        scWidget.bind(SC.Widget.Events.PAUSE,  function() { isPlaying = false; updateIcon(); });
        scWidget.bind(SC.Widget.Events.FINISH, function() { isPlaying = false; updateIcon(); nextTrack(); });
        scWidget.bind(SC.Widget.Events.PLAY_PROGRESS, function(e) {
            document.getElementById('progress-bar').style.width = (e.relativePosition * 100) + '%';
            const cur = Math.floor(e.currentPosition / 1000);
            scWidget.getDuration(function(dur) {
                document.getElementById('time-display').textContent =
                    fmtTime(cur) + ' / ' + fmtTime(Math.floor(dur / 1000));
            });
        });
    }, 300);

    isPlaying = true;
    updateIcon();
    setNowPlaying(title, artist);

    scIdx = scPlaylist.findIndex(t => t.url === permalinkUrl);
    if (scIdx === -1) scIdx = 0;
}

function setNowPlaying(title, artist) {
    document.getElementById('track-name').textContent  = title;
    document.getElementById('artist-name').textContent = artist ? ' - ' + artist : '';
}

function togglePlay() {
    if (currentMode === 'local') {
        if (isPlaying) audioEl.pause(); else audioEl.play();
    } else if (currentMode === 'sc' && scWidget) {
        if (isPlaying) scWidget.pause(); else scWidget.play();
    }
}

function updateIcon() {
    document.getElementById('play-icon').style.display  = isPlaying ? 'none'  : 'block';
    document.getElementById('pause-icon').style.display = isPlaying ? 'block' : 'none';
}

function seek(e) {
    const rect = document.getElementById('progress-container').getBoundingClientRect();
    const pos  = (e.clientX - rect.left) / rect.width;
    if (currentMode === 'local' && audioEl.duration) {
        audioEl.currentTime = pos * audioEl.duration;
    } else if (currentMode === 'sc' && scWidget) {
        scWidget.getDuration(function(dur) { scWidget.seekTo(pos * dur); });
    }
}

function previousTrack() {
    if (playlistMode === 'sc') {
        if (scIdx > 0) { scIdx--; const t = scPlaylist[scIdx]; playSC(t.url, t.title, t.artist); }
    } else {
        if (localIdx > 0) { localIdx--; const t = localPlaylist[localIdx]; playLocal(t.url, t.title, t.artist); }
    }
}

function nextTrack() {
    if (playlistMode === 'sc') {
        if (scIdx < scPlaylist.length - 1) { scIdx++; const t = scPlaylist[scIdx]; playSC(t.url, t.title, t.artist); }
    } else {
        if (localIdx < localPlaylist.length - 1) { localIdx++; const t = localPlaylist[localIdx]; playLocal(t.url, t.title, t.artist); }
    }
}

// Build playlists from page data
(function() {
    <?php foreach ($tracks as $t): ?>
    localPlaylist.push({
        url:    'uploads/music/<?php echo htmlspecialchars($t['filename']); ?>',
        title:  '<?php echo htmlspecialchars(addslashes($t['title'])); ?>',
        artist: '<?php echo htmlspecialchars(addslashes($t['artist'])); ?>'
    });
    <?php endforeach; ?>
    <?php foreach ($guestReleases as $g): ?>
    localPlaylist.push({
        url:    'uploads/guests/<?php echo htmlspecialchars($g['mp3_filename']); ?>',
        title:  '<?php echo htmlspecialchars(addslashes($g['track_id'])); ?>',
        artist: '<?php echo htmlspecialchars(addslashes($g['artist_name'])); ?>'
    });
    <?php endforeach; ?>
    <?php foreach ($allScTracks as $sc): ?>
    scPlaylist.push({
        url:    '<?php echo htmlspecialchars(addslashes($sc['permalink_url'])); ?>',
        title:  '<?php echo htmlspecialchars(addslashes($sc['title'])); ?>',
        artist: '<?php echo htmlspecialchars(addslashes($sc['artist'] ?: $sc['profile_name'])); ?>'
    });
    <?php endforeach; ?>
})();

// HTML5 audio events
audioEl.addEventListener('timeupdate', function() {
    if (currentMode !== 'local' || !audioEl.duration) return;
    const pct = (audioEl.currentTime / audioEl.duration) * 100;
    document.getElementById('progress-bar').style.width = pct + '%';
    document.getElementById('time-display').textContent =
        fmtTime(Math.floor(audioEl.currentTime)) + ' / ' + fmtTime(Math.floor(audioEl.duration));
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
