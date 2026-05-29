<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/soundcloud.php';

// ─── helpers ────────────────────────────────────────────────────────────────

function isAuth(): bool {
    return !empty($_SESSION['admin']);
}

function requireAuth(): void {
    if (!isAuth()) {
        header('Location: admin.php?page=login');
        exit;
    }
}

function redirect(string $to): void {
    header('Location: ' . $to);
    exit;
}

function uploadFile(string $field, string $dir, array $allowed): array {
    if (empty($_FILES[$field]['name'])) return ['ok' => false, 'error' => 'No file'];
    $orig = $_FILES[$field]['name'];
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return ['ok' => false, 'error' => 'File type not allowed'];
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'Upload error'];

    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($orig, PATHINFO_FILENAME));
    $name = uniqid() . '_' . $safe . '.' . $ext;
    $dest = rtrim($dir, '/') . '/' . $name;

    if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Failed to save file'];
    }
    return ['ok' => true, 'filename' => $name];
}

// ─── routing & actions ──────────────────────────────────────────────────────

$page    = $_GET['page']   ?? 'dashboard';
$msg     = '';
$msgType = 'ok'; // 'ok' | 'err'

// LOGIN
if ($page === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pw   = $_POST['password'] ?? '';
        $hash = getSetting('admin_password');
        if (password_verify($pw, $hash)) {
            $_SESSION['admin'] = true;
            redirect('admin.php');
        } else {
            $msg = 'Wrong password.'; $msgType = 'err';
        }
    }
// LOGOUT
} elseif ($page === 'logout') {
    session_destroy();
    redirect('admin.php?page=login');

// All other pages need auth
} else {
    requireAuth();

    $db = getDB();

    // ── ARTISTS ──────────────────────────────────────────────────────────────
    if ($page === 'artists') {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $db->prepare("INSERT INTO artists (name,links,about,sort_order) VALUES (?,?,?,?)")
               ->execute([trim($_POST['name']),trim($_POST['links']),trim($_POST['about']),(int)$_POST['sort_order']]);
            $msg = 'Artist added.';

        } elseif ($action === 'edit') {
            $db->prepare("UPDATE artists SET name=?,links=?,about=?,sort_order=? WHERE id=?")
               ->execute([trim($_POST['name']),trim($_POST['links']),trim($_POST['about']),(int)$_POST['sort_order'],(int)$_POST['id']]);
            $msg = 'Artist updated.';

        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM artists WHERE id=?")->execute([(int)$_POST['id']]);
            $msg = 'Artist deleted.';
        }
    }

    // ── MERCH ─────────────────────────────────────────────────────────────────
    elseif ($page === 'merch') {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $img = uploadFile('image', __DIR__ . '/uploads/images', ['jpg','jpeg','png','webp','gif']);
            if (!$img['ok'] && !empty($_POST['existing_image'])) {
                $img = ['ok' => true, 'filename' => basename($_POST['existing_image'])];
            }
            $db->prepare("INSERT INTO merch_items (image_filename,title,size,price,about,sort_order) VALUES (?,?,?,?,?,?)")
               ->execute([$img['ok']?$img['filename']:'',trim($_POST['title']),trim($_POST['size']),trim($_POST['price']),trim($_POST['about']),(int)$_POST['sort_order']]);
            $msg = $img['ok'] ? 'Merch item added.' : 'Merch item added (no image: '.$img['error'].')';

        } elseif ($action === 'edit') {
            $img = uploadFile('image', __DIR__ . '/uploads/images', ['jpg','jpeg','png','webp','gif']);
            $filename = $img['ok'] ? $img['filename'] : trim($_POST['existing_image']);
            $db->prepare("UPDATE merch_items SET image_filename=?,title=?,size=?,price=?,about=?,sort_order=? WHERE id=?")
               ->execute([$filename,trim($_POST['title']),trim($_POST['size']),trim($_POST['price']),trim($_POST['about']),(int)$_POST['sort_order'],(int)$_POST['id']]);
            $msg = 'Merch item updated.';

        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM merch_items WHERE id=?")->execute([(int)$_POST['id']]);
            $msg = 'Merch item deleted.';
        }
    }

    // ── TRACKS ────────────────────────────────────────────────────────────────
    elseif ($page === 'tracks') {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $mp3 = uploadFile('mp3', __DIR__ . '/uploads/music', ['mp3']);
            if (!$mp3['ok']) { $msg = 'Upload error: '.$mp3['error']; $msgType='err'; }
            else {
                $db->prepare("INSERT INTO tracks (filename,title,artist,release_date,sort_order) VALUES (?,?,?,?,?)")
                   ->execute([$mp3['filename'],trim($_POST['title']),trim($_POST['artist']),trim($_POST['release_date']),(int)$_POST['sort_order']]);
                $msg = 'Track uploaded.';
            }

        } elseif ($action === 'edit') {
            $mp3 = uploadFile('mp3', __DIR__ . '/uploads/music', ['mp3']);
            if ($mp3['ok']) {
                $db->prepare("UPDATE tracks SET filename=?,title=?,artist=?,release_date=?,sort_order=? WHERE id=?")
                   ->execute([$mp3['filename'],trim($_POST['title']),trim($_POST['artist']),trim($_POST['release_date']),(int)$_POST['sort_order'],(int)$_POST['id']]);
            } else {
                $db->prepare("UPDATE tracks SET title=?,artist=?,release_date=?,sort_order=? WHERE id=?")
                   ->execute([trim($_POST['title']),trim($_POST['artist']),trim($_POST['release_date']),(int)$_POST['sort_order'],(int)$_POST['id']]);
            }
            $msg = 'Track updated.';

        } elseif ($action === 'delete') {
            $row = $db->prepare("SELECT filename FROM tracks WHERE id=?");
            $row->execute([(int)$_POST['id']]);
            $r = $row->fetch();
            if ($r && $r['filename']) @unlink(__DIR__ . '/uploads/music/' . $r['filename']);
            $db->prepare("DELETE FROM tracks WHERE id=?")->execute([(int)$_POST['id']]);
            $msg = 'Track deleted.';
        }
    }

    // ── GUEST RELEASES ────────────────────────────────────────────────────────
    elseif ($page === 'guests') {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $mp3 = uploadFile('mp3', __DIR__ . '/uploads/guests', ['mp3']);
            if (!$mp3['ok']) { $msg = 'MP3 upload error: '.$mp3['error']; $msgType='err'; }
            else {
                $art = uploadFile('artwork', __DIR__ . '/uploads/guests', ['jpg','jpeg','png','webp','gif']);
                $db->prepare("INSERT INTO guest_releases (track_id,artist_name,artwork_filename,about,mp3_filename,sort_order) VALUES (?,?,?,?,?,?)")
                   ->execute([trim($_POST['track_id']),trim($_POST['artist_name']),$art['ok']?$art['filename']:'',trim($_POST['about']),$mp3['filename'],(int)$_POST['sort_order']]);
                $msg = 'Guest release added.';
            }

        } elseif ($action === 'edit') {
            $mp3 = uploadFile('mp3', __DIR__ . '/uploads/guests', ['mp3']);
            $art = uploadFile('artwork', __DIR__ . '/uploads/guests', ['jpg','jpeg','png','webp','gif']);
            $mp3file = $mp3['ok'] ? $mp3['filename'] : trim($_POST['existing_mp3']);
            $artfile = $art['ok'] ? $art['filename'] : trim($_POST['existing_artwork']);
            $db->prepare("UPDATE guest_releases SET track_id=?,artist_name=?,artwork_filename=?,about=?,mp3_filename=?,sort_order=? WHERE id=?")
               ->execute([trim($_POST['track_id']),trim($_POST['artist_name']),$artfile,trim($_POST['about']),$mp3file,(int)$_POST['sort_order'],(int)$_POST['id']]);
            $msg = 'Guest release updated.';

        } elseif ($action === 'delete') {
            $row = $db->prepare("SELECT mp3_filename,artwork_filename FROM guest_releases WHERE id=?");
            $row->execute([(int)$_POST['id']]);
            $r = $row->fetch();
            if ($r) {
                if ($r['mp3_filename'])     @unlink(__DIR__ . '/uploads/guests/' . $r['mp3_filename']);
                if ($r['artwork_filename']) @unlink(__DIR__ . '/uploads/guests/' . $r['artwork_filename']);
            }
            $db->prepare("DELETE FROM guest_releases WHERE id=?")->execute([(int)$_POST['id']]);
            $msg = 'Guest release deleted.';
        }
    }

    // ── SOUNDCLOUD ────────────────────────────────────────────────────────────
    elseif ($page === 'soundcloud') {
        $action = $_POST['action'] ?? $_GET['action'] ?? '';

        if ($action === 'add_profile') {
            $username = trim($_POST['username']);
            if ($username) {
                try {
                    $db->prepare("INSERT INTO soundcloud_profiles (username) VALUES (?)")->execute([$username]);
                    $msg = 'Profile added. Click Sync to fetch tracks.';
                } catch (Exception $e) {
                    $msg = 'Profile already exists.'; $msgType = 'err';
                }
            }

        } elseif ($action === 'delete_profile') {
            $db->prepare("DELETE FROM soundcloud_profiles WHERE id=?")->execute([(int)$_POST['id']]);
            $msg = 'Profile and its tracks removed.';

        } elseif ($action === 'sync') {
            $result = syncSoundCloudProfile((int)$_POST['id']);
            if ($result['success']) {
                $msg = "Sync done: {$result['added']} new, {$result['updated']} updated, {$result['total']} total tracks.";
            } else {
                $msg = 'Sync failed: ' . $result['error']; $msgType = 'err';
            }

        } elseif ($action === 'delete_track') {
            $db->prepare("DELETE FROM soundcloud_tracks WHERE id=?")->execute([(int)$_POST['id']]);
            $msg = 'Track removed.';
        }
    }

    // ── SETTINGS ─────────────────────────────────────────────────────────────
    elseif ($page === 'settings') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!empty($_POST['new_password'])) {
                if ($_POST['new_password'] !== $_POST['confirm_password']) {
                    $msg = 'Passwords do not match.'; $msgType = 'err';
                } else {
                    setSetting('admin_password', password_hash($_POST['new_password'], PASSWORD_DEFAULT));
                    $msg = 'Password updated.';
                }
            }
            if (isset($_POST['sc_client_id'])) {
                setSetting('sc_client_id', trim($_POST['sc_client_id']));
                $msg = ($msg ? $msg . ' ' : '') . 'SoundCloud client_id saved.';
            }
            if (isset($_POST['site_title'])) {
                setSetting('site_title', trim($_POST['site_title']) ?: 'travelling music™');
            }
        }
    }
}

// ─── fetch data for display ──────────────────────────────────────────────────
if (isAuth() && $page !== 'login' && $page !== 'logout') {
    $db = getDB();
    switch ($page) {
        case 'dashboard':
            $counts = [
                'artists'   => $db->query("SELECT COUNT(*) FROM artists")->fetchColumn(),
                'merch'     => $db->query("SELECT COUNT(*) FROM merch_items")->fetchColumn(),
                'tracks'    => $db->query("SELECT COUNT(*) FROM tracks")->fetchColumn(),
                'guests'    => $db->query("SELECT COUNT(*) FROM guest_releases")->fetchColumn(),
                'sc_tracks' => $db->query("SELECT COUNT(*) FROM soundcloud_tracks")->fetchColumn(),
                'sc_profiles'=> $db->query("SELECT COUNT(*) FROM soundcloud_profiles")->fetchColumn(),
            ];
            break;
        case 'artists':
            $rows = $db->query("SELECT * FROM artists ORDER BY sort_order ASC, id ASC")->fetchAll();
            break;
        case 'merch':
            $rows = $db->query("SELECT * FROM merch_items ORDER BY sort_order ASC, id ASC")->fetchAll();
            break;
        case 'tracks':
            $rows = $db->query("SELECT * FROM tracks ORDER BY sort_order ASC, id ASC")->fetchAll();
            break;
        case 'guests':
            $rows = $db->query("SELECT * FROM guest_releases ORDER BY sort_order ASC, id ASC")->fetchAll();
            break;
        case 'soundcloud':
            $scProfiles = $db->query("SELECT * FROM soundcloud_profiles ORDER BY id ASC")->fetchAll();
            $scRows     = $db->query("SELECT st.*, sp.username AS profile_username FROM soundcloud_tracks st JOIN soundcloud_profiles sp ON sp.id = st.profile_id ORDER BY st.profile_id ASC, st.id ASC")->fetchAll();
            break;
        case 'settings':
            $currentSCId    = getSetting('sc_client_id');
            $adminToken     = getSetting('admin_token');
            $currentSiteTitle = getSetting('site_title');
            break;
    }
}

// ─── HTML ────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — travelling music</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0; padding: 0;
            font-family: 'Courier New', Courier, monospace;
            background: #fff;
            color: #000;
            font-size: 14px;
        }

        a { color: #000; }
        a:hover { text-decoration: none; }

        /* Layout */
        .layout { display: flex; min-height: 100vh; }

        .sidebar {
            width: 200px;
            flex-shrink: 0;
            border-right: 1px solid #000;
            padding: 20px 0;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar .logo {
            padding: 0 15px 20px;
            font-size: 13px;
            border-bottom: 1px solid #000;
            margin-bottom: 10px;
            font-weight: bold;
        }

        .sidebar nav a {
            display: block;
            padding: 8px 15px;
            text-decoration: none;
            color: #000;
            border-bottom: 1px solid #eee;
            transition: background .1s;
        }
        .sidebar nav a:hover, .sidebar nav a.active { background: #000; color: #fff; }

        .main {
            flex: 1;
            padding: 25px 30px;
            overflow-x: auto;
        }

        h1 { font-size: 1.3em; margin: 0 0 20px; border-bottom: 1px solid #000; padding-bottom: 8px; }
        h2 { font-size: 1.1em; margin: 25px 0 10px; }

        /* Messages */
        .msg {
            padding: 10px 15px;
            margin-bottom: 15px;
            border: 1px solid;
            font-size: 13px;
        }
        .msg.ok  { border-color: #000; background: #f8f8f8; }
        .msg.err { border-color: #000; background: #ffeaea; }

        /* Tables */
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #000; padding: 8px 10px; text-align: left; vertical-align: middle; }
        th { background: #000; color: #fff; font-weight: normal; }
        tr:hover td { background: #f9f9f9; }

        img.thumb { width: 50px; height: 50px; object-fit: cover; display: block; }

        /* Forms */
        .form-section {
            border: 1px solid #000;
            padding: 20px;
            margin-bottom: 25px;
        }
        .form-section h2 { margin-top: 0; }

        .field { margin-bottom: 12px; }
        .field label { display: block; font-size: 12px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .5px; }
        .field input[type=text],
        .field input[type=password],
        .field input[type=number],
        .field input[type=date],
        .field textarea,
        .field select {
            width: 100%;
            border: 1px solid #000;
            padding: 7px 9px;
            font-family: inherit;
            font-size: 13px;
            background: #fff;
        }
        .field textarea { height: 70px; resize: vertical; }
        .field input[type=file] { font-size: 13px; }

        .field-row { display: flex; gap: 12px; }
        .field-row .field { flex: 1; }

        .btn {
            display: inline-block;
            border: 1px solid #000;
            padding: 7px 16px;
            background: #fff;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            text-decoration: none;
            color: #000;
        }
        .btn:hover   { background: #000; color: #fff; }
        .btn.primary { background: #000; color: #fff; }
        .btn.primary:hover { background: #333; }
        .btn.danger  { border-color: #c00; color: #c00; }
        .btn.danger:hover { background: #c00; color: #fff; border-color: #c00; }
        .btn.sm { padding: 4px 10px; font-size: 12px; }

        /* Login page */
        .login-wrap {
            max-width: 340px;
            margin: 80px auto;
            border: 1px solid #000;
            padding: 30px;
        }
        .login-wrap h1 { text-align: center; margin-bottom: 20px; border: none; }

        /* Dashboard stats */
        .stats { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 25px; }
        .stat-card {
            border: 1px solid #000;
            padding: 15px 20px;
            min-width: 120px;
            text-align: center;
        }
        .stat-card .num { font-size: 2em; font-weight: bold; line-height: 1; }
        .stat-card .label { font-size: 11px; text-transform: uppercase; margin-top: 4px; }

        /* Inline edit form (hidden by default) */
        .edit-form-row { display: none; }
        .edit-form-row td { background: #f8f8f8; }

        /* SC artwork small */
        img.sc-art { width: 40px; height: 40px; object-fit: cover; }

        @media (max-width: 640px) {
            .sidebar { width: 150px; }
            .main { padding: 15px; }
        }
    </style>
</head>
<body>

<?php if ($page === 'login'): ?>
<!-- ═══════════════════ LOGIN ═══════════════════ -->
<div class="login-wrap">
    <h1>travelling music<br>admin</h1>
    <?php if ($msg): ?><div class="msg <?php echo $msgType; ?>"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
    <form method="post">
        <div class="field">
            <label>Password</label>
            <input type="password" name="password" autofocus required>
        </div>
        <button class="btn primary" style="width:100%">Login</button>
    </form>
</div>

<?php else: ?>
<!-- ═══════════════════ MAIN LAYOUT ═══════════════════ -->
<div class="layout">
    <aside class="sidebar">
        <div class="logo">travelling<br>music™<br>admin</div>
        <nav>
            <a href="admin.php"                 class="<?php echo $page==='dashboard'?'active':''; ?>">Dashboard</a>
            <a href="admin.php?page=artists"    class="<?php echo $page==='artists'?'active':''; ?>">Projects</a>
            <a href="admin.php?page=merch"      class="<?php echo $page==='merch'?'active':''; ?>">Merch</a>
            <a href="admin.php?page=tracks"     class="<?php echo $page==='tracks'?'active':''; ?>">Music</a>
            <a href="admin.php?page=guests"     class="<?php echo $page==='guests'?'active':''; ?>">Guest Releases</a>
            <a href="admin.php?page=soundcloud" class="<?php echo $page==='soundcloud'?'active':''; ?>">SoundCloud</a>
            <a href="admin.php?page=settings"   class="<?php echo $page==='settings'?'active':''; ?>">Settings</a>
            <a href="index.php" target="_blank">View Site</a>
            <a href="admin.php?page=logout">Logout</a>
        </nav>
    </aside>

    <main class="main">
        <?php if ($msg): ?>
        <div class="msg <?php echo $msgType; ?>"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <?php
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // DASHBOARD
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        if ($page === 'dashboard'):
        ?>
        <h1>Dashboard</h1>
        <div class="stats">
            <div class="stat-card"><div class="num"><?php echo $counts['artists']; ?></div><div class="label">Projects</div></div>
            <div class="stat-card"><div class="num"><?php echo $counts['merch']; ?></div><div class="label">Merch</div></div>
            <div class="stat-card"><div class="num"><?php echo $counts['tracks']; ?></div><div class="label">Tracks</div></div>
            <div class="stat-card"><div class="num"><?php echo $counts['guests']; ?></div><div class="label">Guest Rel.</div></div>
            <div class="stat-card"><div class="num"><?php echo $counts['sc_profiles']; ?></div><div class="label">SC Profiles</div></div>
            <div class="stat-card"><div class="num"><?php echo $counts['sc_tracks']; ?></div><div class="label">SC Tracks</div></div>
        </div>
        <p>Use the sidebar to manage all site content.</p>
        <p><a href="index.php" target="_blank" class="btn">Open site &rarr;</a></p>

        <?php
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // ARTISTS / PROJECTS
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        elseif ($page === 'artists'):
        ?>
        <h1>Projects</h1>

        <!-- Add form -->
        <div class="form-section">
            <h2>Add project</h2>
            <form method="post">
                <input type="hidden" name="action" value="add">
                <div class="field-row">
                    <div class="field"><label>Name</label><input type="text" name="name" required></div>
                    <div class="field"><label>Links</label><input type="text" name="links" placeholder="https://..."></div>
                    <div class="field" style="flex:.3"><label>Order</label><input type="number" name="sort_order" value="0" min="0"></div>
                </div>
                <div class="field"><label>About</label><textarea name="about"></textarea></div>
                <button class="btn primary">Add</button>
            </form>
        </div>

        <!-- List -->
        <table>
            <thead><tr><th>Name</th><th>Links</th><th>About</th><th>Order</th><th width="120">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?php echo htmlspecialchars($r['name']); ?></td>
                <td><?php echo htmlspecialchars($r['links']); ?></td>
                <td><?php echo htmlspecialchars($r['about']); ?></td>
                <td><?php echo $r['sort_order']; ?></td>
                <td>
                    <button class="btn sm" onclick="toggleEdit('a<?php echo $r['id']; ?>')">Edit</button>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                        <button class="btn sm danger">Del</button>
                    </form>
                </td>
            </tr>
            <tr class="edit-form-row" id="a<?php echo $r['id']; ?>">
                <td colspan="5">
                    <form method="post">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                        <div class="field-row">
                            <div class="field"><label>Name</label><input type="text" name="name" value="<?php echo htmlspecialchars($r['name']); ?>" required></div>
                            <div class="field"><label>Links</label><input type="text" name="links" value="<?php echo htmlspecialchars($r['links']); ?>"></div>
                            <div class="field" style="flex:.3"><label>Order</label><input type="number" name="sort_order" value="<?php echo $r['sort_order']; ?>" min="0"></div>
                        </div>
                        <div class="field"><label>About</label><textarea name="about"><?php echo htmlspecialchars($r['about']); ?></textarea></div>
                        <button class="btn primary sm">Save</button>
                        <button type="button" class="btn sm" onclick="toggleEdit('a<?php echo $r['id']; ?>')">Cancel</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr><td colspan="5" style="text-align:center;color:#999">No projects yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // MERCH
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        elseif ($page === 'merch'):
        ?>
        <h1>Merch Items</h1>

        <div class="form-section">
            <h2>Add item</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add">
                <div class="field-row">
                    <div class="field"><label>Title</label><input type="text" name="title" required></div>
                    <div class="field"><label>Size</label><input type="text" name="size" placeholder="M, L, XL…"></div>
                    <div class="field"><label>Price ($)</label><input type="text" name="price" placeholder="0"></div>
                    <div class="field" style="flex:.3"><label>Order</label><input type="number" name="sort_order" value="0" min="0"></div>
                </div>
                <div class="field"><label>About / description</label><textarea name="about"></textarea></div>
                <div class="field"><label>Image (jpg/png/webp)</label><input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif"></div>
                <button class="btn primary">Add</button>
            </form>
        </div>

        <table>
            <thead><tr><th>Pic</th><th>Title</th><th>Size</th><th>Price</th><th>About</th><th>Ord</th><th width="120">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?php if($r['image_filename']): ?><img class="thumb" src="uploads/images/<?php echo htmlspecialchars($r['image_filename']); ?>"><?php endif; ?></td>
                <td><?php echo htmlspecialchars($r['title']); ?></td>
                <td><?php echo htmlspecialchars($r['size']); ?></td>
                <td><?php echo htmlspecialchars($r['price']); ?>$</td>
                <td><?php echo htmlspecialchars(mb_substr($r['about'],0,60)); ?><?php if(mb_strlen($r['about'])>60) echo '…'; ?></td>
                <td><?php echo $r['sort_order']; ?></td>
                <td>
                    <button class="btn sm" onclick="toggleEdit('m<?php echo $r['id']; ?>')">Edit</button>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                        <button class="btn sm danger">Del</button>
                    </form>
                </td>
            </tr>
            <tr class="edit-form-row" id="m<?php echo $r['id']; ?>">
                <td colspan="7">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                        <input type="hidden" name="existing_image" value="<?php echo htmlspecialchars($r['image_filename']); ?>">
                        <div class="field-row">
                            <div class="field"><label>Title</label><input type="text" name="title" value="<?php echo htmlspecialchars($r['title']); ?>" required></div>
                            <div class="field"><label>Size</label><input type="text" name="size" value="<?php echo htmlspecialchars($r['size']); ?>"></div>
                            <div class="field"><label>Price</label><input type="text" name="price" value="<?php echo htmlspecialchars($r['price']); ?>"></div>
                            <div class="field" style="flex:.3"><label>Order</label><input type="number" name="sort_order" value="<?php echo $r['sort_order']; ?>" min="0"></div>
                        </div>
                        <div class="field"><label>About</label><textarea name="about"><?php echo htmlspecialchars($r['about']); ?></textarea></div>
                        <div class="field"><label>New image (leave blank to keep current)</label><input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif"></div>
                        <button class="btn primary sm">Save</button>
                        <button type="button" class="btn sm" onclick="toggleEdit('m<?php echo $r['id']; ?>')">Cancel</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr><td colspan="7" style="text-align:center;color:#999">No merch items yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // TRACKS (unreleased / own music)
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        elseif ($page === 'tracks'):
        ?>
        <h1>Music / Unreleased Tracks</h1>

        <div class="form-section">
            <h2>Upload track</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add">
                <div class="field-row">
                    <div class="field"><label>Title (required)</label><input type="text" name="title" required placeholder="Track name"></div>
                    <div class="field"><label>Artist</label><input type="text" name="artist" placeholder="Artist name"></div>
                </div>
                <div class="field-row">
                    <div class="field"><label>Release Date</label><input type="text" name="release_date" placeholder="DD/MM/YY or free text"></div>
                    <div class="field" style="flex:.3"><label>Order</label><input type="number" name="sort_order" value="0" min="0"></div>
                </div>
                <div class="field"><label>MP3 file</label><input type="file" name="mp3" accept=".mp3" required></div>
                <button class="btn primary">Upload</button>
            </form>
        </div>

        <table>
            <thead><tr><th>Title</th><th>Artist</th><th>Date</th><th>File</th><th>Ord</th><th width="120">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?php echo htmlspecialchars($r['title']); ?></td>
                <td><?php echo htmlspecialchars($r['artist']); ?></td>
                <td><?php echo htmlspecialchars($r['release_date']); ?></td>
                <td style="font-size:11px"><?php echo htmlspecialchars($r['filename']); ?></td>
                <td><?php echo $r['sort_order']; ?></td>
                <td>
                    <button class="btn sm" onclick="toggleEdit('t<?php echo $r['id']; ?>')">Edit</button>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete track and file?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                        <button class="btn sm danger">Del</button>
                    </form>
                </td>
            </tr>
            <tr class="edit-form-row" id="t<?php echo $r['id']; ?>">
                <td colspan="6">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                        <div class="field-row">
                            <div class="field"><label>Title</label><input type="text" name="title" value="<?php echo htmlspecialchars($r['title']); ?>" required></div>
                            <div class="field"><label>Artist</label><input type="text" name="artist" value="<?php echo htmlspecialchars($r['artist']); ?>"></div>
                        </div>
                        <div class="field-row">
                            <div class="field"><label>Release Date</label><input type="text" name="release_date" value="<?php echo htmlspecialchars($r['release_date']); ?>"></div>
                            <div class="field" style="flex:.3"><label>Order</label><input type="number" name="sort_order" value="<?php echo $r['sort_order']; ?>" min="0"></div>
                        </div>
                        <div class="field"><label>Replace MP3 (leave blank to keep current)</label><input type="file" name="mp3" accept=".mp3"></div>
                        <button class="btn primary sm">Save</button>
                        <button type="button" class="btn sm" onclick="toggleEdit('t<?php echo $r['id']; ?>')">Cancel</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr><td colspan="6" style="text-align:center;color:#999">No tracks yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // GUEST RELEASES
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        elseif ($page === 'guests'):
        ?>
        <h1>Guest / External Releases</h1>

        <div class="form-section">
            <h2>Add guest release</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add">
                <div class="field-row">
                    <div class="field"><label>Track ID / Title</label><input type="text" name="track_id" required placeholder="e.g. TM-001"></div>
                    <div class="field"><label>Artist Name</label><input type="text" name="artist_name" required></div>
                    <div class="field" style="flex:.3"><label>Order</label><input type="number" name="sort_order" value="0" min="0"></div>
                </div>
                <div class="field"><label>About / Link (URL shown as "details")</label><input type="text" name="about" placeholder="https://..."></div>
                <div class="field-row">
                    <div class="field"><label>MP3 file</label><input type="file" name="mp3" accept=".mp3" required></div>
                    <div class="field"><label>Artwork (jpg/png)</label><input type="file" name="artwork" accept=".jpg,.jpeg,.png,.webp"></div>
                </div>
                <button class="btn primary">Add</button>
            </form>
        </div>

        <table>
            <thead><tr><th>Art</th><th>Track ID</th><th>Artist</th><th>About</th><th>MP3</th><th>Ord</th><th width="120">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?php if($r['artwork_filename']): ?><img class="thumb" src="uploads/guests/<?php echo htmlspecialchars($r['artwork_filename']); ?>"><?php endif; ?></td>
                <td><?php echo htmlspecialchars($r['track_id']); ?></td>
                <td><?php echo htmlspecialchars($r['artist_name']); ?></td>
                <td><?php echo htmlspecialchars(mb_substr($r['about'],0,40)); ?></td>
                <td style="font-size:11px"><?php echo htmlspecialchars($r['mp3_filename']); ?></td>
                <td><?php echo $r['sort_order']; ?></td>
                <td>
                    <button class="btn sm" onclick="toggleEdit('g<?php echo $r['id']; ?>')">Edit</button>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                        <button class="btn sm danger">Del</button>
                    </form>
                </td>
            </tr>
            <tr class="edit-form-row" id="g<?php echo $r['id']; ?>">
                <td colspan="7">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                        <input type="hidden" name="existing_mp3" value="<?php echo htmlspecialchars($r['mp3_filename']); ?>">
                        <input type="hidden" name="existing_artwork" value="<?php echo htmlspecialchars($r['artwork_filename']); ?>">
                        <div class="field-row">
                            <div class="field"><label>Track ID</label><input type="text" name="track_id" value="<?php echo htmlspecialchars($r['track_id']); ?>" required></div>
                            <div class="field"><label>Artist Name</label><input type="text" name="artist_name" value="<?php echo htmlspecialchars($r['artist_name']); ?>" required></div>
                            <div class="field" style="flex:.3"><label>Order</label><input type="number" name="sort_order" value="<?php echo $r['sort_order']; ?>" min="0"></div>
                        </div>
                        <div class="field"><label>About / Link</label><input type="text" name="about" value="<?php echo htmlspecialchars($r['about']); ?>"></div>
                        <div class="field-row">
                            <div class="field"><label>New MP3 (blank = keep)</label><input type="file" name="mp3" accept=".mp3"></div>
                            <div class="field"><label>New Artwork (blank = keep)</label><input type="file" name="artwork" accept=".jpg,.jpeg,.png,.webp"></div>
                        </div>
                        <button class="btn primary sm">Save</button>
                        <button type="button" class="btn sm" onclick="toggleEdit('g<?php echo $r['id']; ?>')">Cancel</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr><td colspan="7" style="text-align:center;color:#999">No guest releases yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // SOUNDCLOUD
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        elseif ($page === 'soundcloud'):
        ?>
        <h1>SoundCloud Profiles</h1>

        <?php if (!getSetting('sc_client_id')): ?>
        <div class="msg err">SoundCloud client_id is not set. Go to <a href="admin.php?page=settings">Settings</a> to add it before syncing.</div>
        <?php endif; ?>

        <!-- Add profile -->
        <div class="form-section">
            <h2>Link a SoundCloud profile</h2>
            <form method="post">
                <input type="hidden" name="action" value="add_profile">
                <div class="field-row">
                    <div class="field">
                        <label>SoundCloud username</label>
                        <input type="text" name="username" required placeholder="e.g. yourartist (from soundcloud.com/yourartist)">
                    </div>
                    <div class="field" style="flex:0;padding-top:22px">
                        <button class="btn primary">Add Profile</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Profiles list -->
        <?php if (!empty($scProfiles)): ?>
        <h2>Linked profiles</h2>
        <table>
            <thead><tr><th>Username</th><th>Display Name</th><th>Last Synced</th><th>Tracks</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($scProfiles as $p):
                $cnt = getDB()->prepare("SELECT COUNT(*) FROM soundcloud_tracks WHERE profile_id=?");
                $cnt->execute([$p['id']]);
                $trackCount = $cnt->fetchColumn();
            ?>
            <tr>
                <td>
                    <a href="https://soundcloud.com/<?php echo htmlspecialchars($p['username']); ?>" target="_blank">
                        <?php echo htmlspecialchars($p['username']); ?>
                    </a>
                </td>
                <td><?php echo htmlspecialchars($p['display_name'] ?: '—'); ?></td>
                <td><?php echo $p['last_synced'] ? htmlspecialchars($p['last_synced']) : '—'; ?></td>
                <td><?php echo $trackCount; ?></td>
                <td>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="sync">
                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                        <button class="btn sm primary">Sync Tracks</button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete profile and ALL its tracks?')">
                        <input type="hidden" name="action" value="delete_profile">
                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                        <button class="btn sm danger">Remove</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- SC Tracks list -->
        <?php if (!empty($scRows)): ?>
        <h2>SoundCloud tracks (<?php echo count($scRows); ?>)</h2>
        <table>
            <thead><tr><th width="50">Art</th><th>Title</th><th>Artist</th><th>Profile</th><th>Duration</th><th width="100">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($scRows as $t): ?>
            <tr>
                <td><?php if($t['artwork_url']): ?><img class="sc-art" src="<?php echo htmlspecialchars($t['artwork_url']); ?>" onerror="this.style.display='none'"><?php endif; ?></td>
                <td>
                    <a href="<?php echo htmlspecialchars($t['permalink_url']); ?>" target="_blank">
                        <?php echo htmlspecialchars($t['title']); ?>
                    </a>
                </td>
                <td><?php echo htmlspecialchars($t['artist']); ?></td>
                <td><?php echo htmlspecialchars($t['profile_username']); ?></td>
                <td><?php
                    $s = intdiv($t['duration'], 1000);
                    echo floor($s/60).':'.str_pad($s%60,2,'0',STR_PAD_LEFT);
                ?></td>
                <td>
                    <form method="post" style="display:inline" onsubmit="return confirm('Remove this track?')">
                        <input type="hidden" name="action" value="delete_track">
                        <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                        <button class="btn sm danger">Del</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php elseif (!empty($scProfiles)): ?>
        <p>No tracks yet. Click <strong>Sync Tracks</strong> on a profile above.</p>
        <?php endif; ?>

        <?php
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // SETTINGS
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        elseif ($page === 'settings'):
        ?>
        <h1>Settings</h1>

        <form method="post">
            <!-- Site -->
            <div class="form-section">
                <h2>Site</h2>
                <div class="field">
                    <label>Site Title</label>
                    <input type="text" name="site_title" value="<?php echo htmlspecialchars($currentSiteTitle); ?>">
                </div>
                <button class="btn primary">Save</button>
            </div>

            <!-- SoundCloud -->
            <div class="form-section">
                <h2>SoundCloud API</h2>
                <p style="font-size:12px;margin-top:0">
                    To sync SoundCloud profiles you need a <strong>client_id</strong>.<br>
                    Register your app at <a href="https://soundcloud.com/you/apps" target="_blank">soundcloud.com/you/apps</a>
                    and paste the client_id below.
                </p>
                <div class="field">
                    <label>SoundCloud client_id</label>
                    <input type="text" name="sc_client_id" value="<?php echo htmlspecialchars($currentSCId); ?>" placeholder="Paste your SC client_id">
                </div>
                <button class="btn primary">Save</button>
            </div>
        </form>

        <!-- Change password (separate form) -->
        <form method="post">
            <div class="form-section">
                <h2>Change admin password</h2>
                <div class="field">
                    <label>New password</label>
                    <input type="password" name="new_password" autocomplete="new-password">
                </div>
                <div class="field">
                    <label>Confirm new password</label>
                    <input type="password" name="confirm_password">
                </div>
                <button class="btn primary">Change Password</button>
            </div>
        </form>

        <!-- Admin URL info -->
        <div class="form-section">
            <h2>Admin access</h2>
            <p style="font-size:12px">
                The admin panel is accessible at <strong>admin.php</strong> — share this URL only with trusted people.<br>
                Your session expires when you close the browser (or click Logout).
            </p>
            <p style="font-size:12px">Default password on first install: <code>admin123</code> — change it above immediately.</p>
        </div>

        <?php endif; ?>

    </main>
</div><!-- /.layout -->
<?php endif; ?>

<script>
function toggleEdit(id) {
    const row = document.getElementById(id);
    if (!row) return;
    row.style.display = (row.style.display === 'table-row') ? 'none' : 'table-row';
}
</script>
</body>
</html>
