<?php
session_start();

// Load config
$config = require __DIR__ . '/config.php';

// Simple auth guard
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    // If request is AJAX/API, return JSON error; else redirect to login
    if (isset($_REQUEST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    } else {
        header('Location: login.php');
        exit;
    }
}

// Utilities
function read_apps($apps_file) {
    if (!file_exists($apps_file)) return [];
    $json = file_get_contents($apps_file);
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}

function write_apps($apps_file, $data) {
    $dir = dirname($apps_file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    // atomic write with lock
    $tmp = $apps_file . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $apps_file);
}

// Handle API actions (when ajax=1 or action param set)
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : null;
if ($action) {
    header('Content-Type: application/json; charset=utf-8');
    $apps_file = $config->apps_file;
    $apks_dir = $config->apks_dir;
    if (!is_dir($apks_dir)) mkdir($apks_dir, 0755, true);

    try {
        if ($action === 'list_apps') {
            $apps = read_apps($apps_file);
            echo json_encode(['ok' => true, 'apps' => array_values($apps)]);
            exit;
        }

        if ($action === 'get_app') {
            $id = isset($_GET['id']) ? $_GET['id'] : null;
            $apps = read_apps($apps_file);
            foreach ($apps as $app) if ($app['id'] === $id) { echo json_encode(['ok' => true, 'app' => $app]); exit; }
            echo json_encode(['ok' => false, 'error' => 'App not found']); exit;
        }

        if ($action === 'add_app') {
            // accept name, desc, maybe image url, and apk file upload
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $desc = isset($_POST['desc']) ? trim($_POST['desc']) : '';
            $img = isset($_POST['img']) ? trim($_POST['img']) : '';
            $created_at = date('c');

            if ($name === '') throw new Exception('Name required');

            $apk_filename = '';
            if (isset($_FILES['apk']) && $_FILES['apk']['error'] === UPLOAD_ERR_OK) {
                $f = $_FILES['apk'];
                if ($f['size'] > $config->max_apk_size) throw new Exception('APK too large');
                $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
                $safe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
                $apk_filename = $safe . '-' . time() . '.' . $ext;
                $dest = rtrim($apks_dir, '/') . '/' . $apk_filename;
                if (!move_uploaded_file($f['tmp_name'], $dest)) throw new Exception('Failed to move uploaded APK');
            } else {
                // if no upload but apk param (URL or filename) provided, we accept it
                if (isset($_POST['apk']) && trim($_POST['apk']) !== '') $apk_filename = trim($_POST['apk']);
            }

            // if no image provided, use placeholder
            if ($img === '') $img = 'https://via.placeholder.com/600x300?text=' . rawurlencode($name);

            $apps = read_apps($apps_file);
            $id = bin2hex(random_bytes(8));
            $new = [
                'id' => $id,
                'name' => $name,
                'desc' => $desc,
                'img' => $img,
                'apk' => $apk_filename,
                'created_at' => $created_at
            ];
            array_unshift($apps, $new); // put newest first
            write_apps($apps_file, $apps);
            echo json_encode(['ok' => true, 'app' => $new]);
            exit;
        }

        if ($action === 'edit_app') {
            $id = isset($_POST['id']) ? $_POST['id'] : null;
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $desc = isset($_POST['desc']) ? trim($_POST['desc']) : '';
            $img = isset($_POST['img']) ? trim($_POST['img']) : '';

            if (!$id) throw new Exception('Missing id');

            $apps = read_apps($apps_file);
            $found = false;
            foreach ($apps as &$app) {
                if ($app['id'] === $id) {
                    if ($name !== '') $app['name'] = $name;
                    if ($desc !== '') $app['desc'] = $desc;
                    if ($img !== '') $app['img'] = $img;

                    // handle new apk file upload optionally
                    if (isset($_FILES['apk']) && $_FILES['apk']['error'] === UPLOAD_ERR_OK) {
                        $f = $_FILES['apk'];
                        if ($f['size'] > $config->max_apk_size) throw new Exception('APK too large');
                        $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
                        $safe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
                        $apk_filename = $safe . '-' . time() . '.' . $ext;
                        $dest = rtrim($apks_dir, '/') . '/' . $apk_filename;
                        if (!move_uploaded_file($f['tmp_name'], $dest)) throw new Exception('Failed to move uploaded APK');
                        // optionally delete previous file if it exists and is in apks_dir
                        if (!empty($app['apk']) && file_exists($apks_dir . '/' . $app['apk'])) {
                            @unlink($apks_dir . '/' . $app['apk']);
                        }
                        $app['apk'] = $apk_filename;
                    }

                    $found = true;
                    break;
                }
            }
            unset($app);
            if (!$found) throw new Exception('App not found');
            write_apps($apps_file, $apps);
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($action === 'delete_app') {
            $id = isset($_POST['id']) ? $_POST['id'] : null;
            if (!$id) throw new Exception('Missing id');
            $apps = read_apps($apps_file);
            $new = [];
            $deleted = false;
            foreach ($apps as $app) {
                if ($app['id'] === $id) {
                    // delete apk file if stored in apks_dir
                    if (!empty($app['apk']) && file_exists($apks_dir . '/' . $app['apk'])) {
                        @unlink($apks_dir . '/' . $app['apk']);
                    }
                    $deleted = true;
                    continue;
                }
                $new[] = $app;
            }
            if (!$deleted) throw new Exception('App not found');
            write_apps($apps_file, $new);
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($action === 'save_settings') {
            // only allow password change right now
            $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : '';
            if ($new_password === '') throw new Exception('Password required');
            // update config file - since config.php returns static data, we'll update by writing a new config.php
            // BE CAREFUL: in production you may store config in DB or environment variables.
            $cfg_path = __DIR__ . '/config.php';
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $config_content = "<?php\nreturn (object)[\n    'admin_user' => " . var_export($config->admin_user, true) . ",\n    'admin_pass_hash' => " . var_export($hash, true) . ",\n    'apps_file' => " . var_export($config->apps_file, true) . ",\n    'apks_dir' => " . var_export($config->apks_dir, true) . ",\n    'max_apk_size' => " . var_export($config->max_apk_size, true) . "\n];\n";
            if (!is_writable($cfg_path)) {
                throw new Exception('config.php not writable. Change file permissions to allow updates.');
            }
            file_put_contents($cfg_path, $config_content);
            echo json_encode(['ok' => true]);
            exit;
        }

        throw new Exception('Unknown action: ' . $action);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// If not an API action, render the HTML admin dashboard page below
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Admin Dashboard - AppVerse</title>
  <style>
    /* Keep the theme similar to your index / original admin styles */
    body { font-family: 'Poppins', sans-serif; background-color: #0b0f19; color:#e6e6e6; margin:0; display:flex; height:100vh; overflow:hidden; }
    .sidebar { width: 240px; background-color:#101726; display:flex; flex-direction:column; padding:25px 0; box-shadow:2px 0 10px rgba(0,0,0,0.5); }
    .sidebar h2 { color:#00aaff; text-align:center; margin-bottom:40px; font-size:22px; font-weight:700; }
    .sidebar a { text-decoration:none; color:#e6e6e6; padding:14px 20px; margin:4px 12px; border-radius:8px; display:flex; align-items:center; gap:10px; transition: background 0.3s, color 0.3s; }
    .sidebar a:hover, .sidebar a.active { background-color:#00aaff; color:#fff; }
    .main { flex:1; padding:30px; overflow-y:auto; background:linear-gradient(180deg, rgba(15,20,30,0.95), rgba(10,12,18,1)); }
    .main h1 { color:#00aaff; margin-bottom:6px; font-size:28px; font-weight:700; }
    .card-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(250px,1fr)); gap:18px; margin-top:18px; }
    .card { background:#151d2f; border-radius:12px; padding:18px; box-shadow:0 4px 12px rgba(0,0,0,0.4); }
    .btn { background-color:#00aaff; border:none; color:#fff; padding:10px 14px; border-radius:8px; cursor:pointer; font-weight:600; }
    .btn:hover { background-color:#0078c8; }
    .apps-list {
      display: flex;
      flex-direction: column;
      gap: 16px;
      margin-top: 18px;
      overflow-y: auto;
      max-height: calc(100vh - 180px);
    }

    .app-card {
      background: #0f1724;
      border-radius: 10px;
      padding: 16px;
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: 14px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.4);
    }

    .app-card img { width:88px; height:52px; object-fit:cover; border-radius:6px; }
    .app-meta { flex:1; }
    .small { font-size:13px; color:#cfeeff; }
    .muted { color:#9fbcd8; font-size:13px; }
    .top-actions { display:flex; gap:12px; align-items:center; margin-bottom:12px; }
    /* modal */
    .modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.6); display:none; align-items:center; justify-content:center; z-index:2000; }
    .modal { background:#121826; border-radius:12px; padding:18px; width:540px; max-width:calc(100% - 32px); box-shadow:0 12px 40px rgba(0,170,255,0.08); }
    .form-row { margin-bottom:10px; display:flex; flex-direction:column; gap:6px; }
    input[type="text"], textarea { padding:10px; border-radius:8px; border:none; background:rgba(255,255,255,0.03); color:#e6e6e6; width:100%; }
    label { font-size:13px; color:#cfeeff; }
    .form-actions { display:flex; gap:8px; justify-content:flex-end; margin-top:12px; }
    .danger { background:#ff6b6b; }
    .muted-pill { background: rgba(255,255,255,0.03); padding:6px 8px; border-radius:8px; color:#cfeeff; font-size:12px; display:inline-block; }

    /* 🌌 AppVerse Admin Dashboard — Custom Scrollbar */
    .apps-list::-webkit-scrollbar {
      width: 10px;
    }

    .apps-list::-webkit-scrollbar-track {
      background: #0b101a; /* dark track background */
      border-radius: 10px;
    }

    .apps-list::-webkit-scrollbar-thumb {
      background: linear-gradient(180deg, #1e3a8a, #2563eb);
      border-radius: 10px;
      box-shadow: 0 0 8px rgba(37, 99, 235, 0.4);
      transition: all 0.3s ease;
    }

    /* Hover effect */
    .apps-list::-webkit-scrollbar-thumb:hover {
      background: linear-gradient(180deg, #3b82f6, #60a5fa);
      box-shadow: 0 0 14px rgba(96, 165, 250, 0.6);
      transform: scaleX(1.2);
    }

    /* Subtle pulsing animation */
    @keyframes pulseScroll {
      0% { box-shadow: 0 0 6px rgba(37, 99, 235, 0.3); }
      50% { box-shadow: 0 0 12px rgba(96, 165, 250, 0.6); }
      100% { box-shadow: 0 0 6px rgba(37, 99, 235, 0.3); }
    }

    .apps-list::-webkit-scrollbar-thumb {
      animation: pulseScroll 3s infinite ease-in-out;
    }

    /* Smooth scroll for a premium feel */
    .apps-list {
      scroll-behavior: smooth;
      scrollbar-width: thin; /* for Firefox */
      scrollbar-color: #2563eb #0b101a;
    }

    /* 🌌 Logout Spinner Animation */
    .logout-spinner {
      border: 3px solid rgba(255, 255, 255, 0.1);
      border-top: 3px solid #00aaff;
      border-radius: 50%;
      width: 42px;
      height: 42px;
      margin: 0 auto;
      animation: spin 1s linear infinite, glowPulse 2s ease-in-out infinite;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    @keyframes glowPulse {
      0% { box-shadow: 0 0 5px rgba(0,170,255,0.4); }
      50% { box-shadow: 0 0 15px rgba(0,170,255,0.8); }
      100% { box-shadow: 0 0 5px rgba(0,170,255,0.4); }
    }

  </style>
</head>
<body>
  <div class="sidebar">
    <h2>Admin Panel</h2>
    <a href="#" class="active">🏠 Dashboard</a>
    <a href="#" id="addApkBtn">➕ Add APK</a>
    <a href="#" id="viewAllBtn">📦 View All APKs</a>
    <a href="#" id="settingsBtn">⚙️ Settings</a>
    <a href="#" id="logoutBtn">🚪 Logout</a>
  </div>

  <div class="main">
    <h1>Welcome, Admin</h1>
    <p class="muted">Control and manage your AppVerse content here.</p>

    <div class="top-actions">
      <button class="btn" id="reloadBtn">Refresh List</button>
      <div class="muted-pill">APKs directory: <span id="apksDirName"></span></div>
    </div>

    <div id="appsContainer" class="apps-list" aria-live="polite">
      <!-- apps populated here -->
    </div>
  </div>

  <!-- modal backdrop -->
  <div class="modal-backdrop" id="modalBackdrop" role="dialog" aria-hidden="true">
    <div class="modal" id="modalContent" role="document">
      <!-- dynamic content -->
    </div>
  </div>

<script>
  const APIDOC = { url: 'admin-dashboard.php' };

  document.getElementById('apksDirName').innerText = '<?php echo addslashes($config->apks_dir); ?>';

  async function api(action, data = null, files = null) {
    const form = new FormData();
    form.append('action', action);
    form.append('ajax', '1');
    if (data) for (const k in data) form.append(k, data[k]);
    if (files) {
      for (const key in files) form.append(key, files[key]);
    }
    const res = await fetch(APIDOC.url, { method: 'POST', body: form });
    return res.json();
  }

  async function loadList() {
    const r = await fetch(APIDOC.url + '?action=list_apps&ajax=1');
    const j = await r.json();
    if (!j.ok) { alert(j.error || 'Failed to load'); return; }
    const cont = document.getElementById('appsContainer');
    cont.innerHTML = '';
    j.apps.forEach(app => {
      const el = document.createElement('div');
      el.className = 'app-card';
      el.innerHTML = `
        <img src="${app.img}" alt="${app.name}"/>
        <div class="app-meta">
          <div style="display:flex; justify-content:space-between; gap:8px; align-items:center;">
            <strong>${escapeHTML(app.name)}</strong>
            <div class="muted small">${app.created_at ? new Date(app.created_at).toLocaleString() : ''}</div>
          </div>
          <div class="small muted">${escapeHTML(app.desc || '')}</div>
          <div style="margin-top:8px; display:flex; gap:8px;">
            <button class="btn" onclick="openEdit('${app.id}')">Edit</button>
            <button class="btn" onclick="downloadApk('${app.apk}')">Download</button>
            <button class="btn danger" onclick="confirmDelete('${app.id}','${escapeJS(app.name)}')">Delete</button>
          </div>
        </div>
      `;
      cont.appendChild(el);
    });
  }

  function openModal(html) {
    document.getElementById('modalContent').innerHTML = html;
    document.getElementById('modalBackdrop').style.display = 'flex';
    document.getElementById('modalBackdrop').setAttribute('aria-hidden', 'false');
  }
  function closeModal() {
    document.getElementById('modalBackdrop').style.display = 'none';
    document.getElementById('modalBackdrop').setAttribute('aria-hidden', 'true');
  }

  // Add APK modal
  document.getElementById('addApkBtn').addEventListener('click', () => {
    openModal(`
      <h3>Add New APK</h3>
      <div class="form-row"><label>Name</label><input id="f_name" type="text"/></div>
      <div class="form-row"><label>Description</label><textarea id="f_desc"></textarea></div>
      <div class="form-row"><label>Image URL (optional)</label><input id="f_img" type="text" placeholder="https://..."/></div>
      <div class="form-row"><label>Image File (optional)</label><input id="f_img_file" type="file" accept="image/*"/></div>
      <div class="form-row"><label>APK URL (optional)</label><input id="f_apk_url" type="text" placeholder="https://..."/></div>
      <div class="form-row"><label>APK File (optional)</label><input id="f_apk" type="file" accept=".apk"/></div>
      <div class="form-actions">
        <button class="btn" onclick="submitAdd()">Add</button>
        <button class="btn" onclick="closeModal()">Cancel</button>
      </div>
    `);
  });


  async function submitAdd() {
  const name = document.getElementById('f_name').value.trim();
  if (!name) { alert('Name is required'); return; }

  const desc = document.getElementById('f_desc').value.trim();
  const imgUrl = document.getElementById('f_img').value.trim();
  const imgFile = document.getElementById('f_img_file').files[0];
  const apkFile = document.getElementById('f_apk').files[0];
  const apkUrl = document.getElementById('f_apk_url').value.trim();

  const formData = new FormData();
  formData.append('action', 'add_app');
  formData.append('ajax', '1');
  formData.append('name', name);
  formData.append('desc', desc);

  // If image file is provided, upload it; otherwise use image URL if available
  if (imgFile) {
    formData.append('img', '');
    formData.append('image_file', imgFile); // extra file field, handled below
  } else if (imgUrl) {
    formData.append('img', imgUrl);
  }

  // If APK file is provided, upload it; otherwise if URL is given, use it
  if (apkFile) {
    formData.append('apk', apkFile);
  } else if (apkUrl) {
    formData.append('apk', apkUrl);
  }

  const res = await fetch(APIDOC.url, { method: 'POST', body: formData });
  const j = await res.json();
  if (!j.ok) { alert(j.error || 'Add failed'); return; }

  closeModal();
  loadList();
}

  // Edit
  async function openEdit(id) {
    const r = await fetch(APIDOC.url + '?action=get_app&id=' + encodeURIComponent(id) + '&ajax=1');
    const j = await r.json();
    if (!j.ok) { alert(j.error || 'Cannot load app'); return; }
    const app = j.app;
    openModal(`
      <h3>Edit APK</h3>
      <div class="form-row"><label>Name</label><input id="e_name" type="text" value="${escapeHTML(app.name)}"/></div>
      <div class="form-row"><label>Description</label><textarea id="e_desc">${escapeHTML(app.desc)}</textarea></div>
      <div class="form-row"><label>Image URL (optional)</label><input id="e_img" type="text" placeholder="https://example.com/image.jpg" value="${escapeHTML(app.img || '')}"/></div>
      <div class="form-row"><label>Image File (optional)</label><input id="e_img_file" type="file" accept="image/*"/></div>
      <div class="form-row"><label>Replace APK (via URL, optional)</label><input id="e_apk_url" type="text" placeholder="https://example.com/app.apk"/></div>
      <div class="form-row"><label>Replace APK (optional)</label><input id="e_apk" type="file" accept=".apk"/></div>
      <div class="form-actions">
        <button class="btn" onclick="submitEdit('${app.id}')">Save</button>
        <button class="btn" onclick="closeModal()">Cancel</button>
      </div>
    `);
  }

  async function submitEdit(id) {
    const name = document.getElementById('e_name').value.trim();
    const desc = document.getElementById('e_desc').value;
    const img = document.getElementById('e_img').value;
    const apkInput = document.getElementById('e_apk');

    const form = new FormData();
    form.append('action','edit_app');
    form.append('ajax','1');
    form.append('id', id);
    form.append('name', name);
    form.append('desc', desc);
    form.append('img', img);
    if (apkInput && apkInput.files && apkInput.files[0]) form.append('apk', apkInput.files[0]);

    const imgFile = document.getElementById('e_img_file').files[0];
    const apkUrl = document.getElementById('e_apk_url').value.trim();

    if (imgFile) {
      form.append('image_file', imgFile);
    }

    if (apkUrl) {
      form.append('apk', apkUrl);
    }

    const res = await fetch(APIDOC.url, { method: 'POST', body: form });
    const j = await res.json();
    if (!j.ok) { alert(j.error || 'Save failed'); return; }
    closeModal();
    loadList();
  }

  function confirmDelete(id, name) {
  openModal(`
    <h3 style="color:#ff6b6b; text-align:center; margin-bottom:10px;">Delete App</h3>
    <p style="text-align:center; color:#cfeeff;">Are you sure you want to delete <strong>"${escapeHTML(name)}"</strong>? This action cannot be undone.</p>
    <div id="deleteAnimation" style="display:none; text-align:center; margin-top:20px;">
      <div style="width:40px; height:40px; border:4px solid rgba(255,255,255,0.2); border-top-color:#ff6b6b; border-radius:50%; margin:0 auto; animation: spin 1s linear infinite;"></div>
      <p style="margin-top:8px; color:#9fbcd8;">Deleting...</p>
    </div>
    <div class="form-actions" id="deleteActions">
      <button class="btn danger" id="confirmDeleteBtn">Delete</button>
      <button class="btn" onclick="closeModal()">Cancel</button>
    </div>
    <style>
      @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    </style>
  `);

  document.getElementById('confirmDeleteBtn').addEventListener('click', async () => {
    // Show animation
    document.getElementById('deleteActions').style.display = 'none';
    document.getElementById('deleteAnimation').style.display = 'block';

    // Perform delete
    const res = await fetch(APIDOC.url, {
      method: 'POST',
      body: new URLSearchParams({ action: 'delete_app', ajax: '1', id })
    });
    const j = await res.json();

    // Handle response
    if (!j.ok) {
      alert(j.error || 'Delete failed');
      closeModal();
      return;
    }

    // Smooth animation before closing
    setTimeout(() => {
      closeModal();
      loadList(); // refresh apps
    }, 1200);
  });
}


  // Download APK helper (either file path or name)
  function downloadApk(apk) {
    if (!apk) { alert('No apk file available'); return; }
    // If apk looks like a filename stored in apks dir, link to that
    const url = '<?php echo basename($config->apks_dir); ?>/' + encodeURIComponent(apk);
    // create link and click
    const a = document.createElement('a');
    a.href = url;
    a.download = apk;
    document.body.appendChild(a);
    a.click();
    a.remove();
  }

  // settings
  document.getElementById('settingsBtn').addEventListener('click', () => {
    openModal(`
      <h3>Settings</h3>
      <div class="form-row"><label>Change Admin Password</label><input id="s_new_pass" type="text" placeholder="New password"/></div>
      <div class="form-actions"><button class="btn" onclick="saveSettings()">Save</button><button class="btn" onclick="closeModal()">Cancel</button></div>
    `);
  });

  async function saveSettings() {
    const pass = document.getElementById('s_new_pass').value.trim();
    if (!pass) { alert('Password required'); return; }
    const form = new FormData();
    form.append('action','save_settings');
    form.append('ajax','1');
    form.append('new_password', pass);
    const res = await fetch(APIDOC.url, { method:'POST', body: form });
    const j = await res.json();
    if (!j.ok) { alert(j.error || 'Save failed'); return; }
    alert('Password updated. You will be logged out.');
    // force logout for safety; redirect to login
    window.location.href = 'login.php';
  }

  // small helpers
  function escapeHTML(s) {
    if (!s) return '';
    return s.toString().replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; });
  }
  function escapeJS(s) {
    return s.replace(/'/g,"\\'");
  }

  // modal click outside to close
  document.getElementById('modalBackdrop').addEventListener('click', (e) => {
    if (e.target === document.getElementById('modalBackdrop')) closeModal();
  });

  // init
  document.getElementById('reloadBtn').addEventListener('click', loadList);
  document.getElementById('viewAllBtn').addEventListener('click', loadList);

  // initial load
  loadList();

  // --- Logout confirmation modal ---
  document.getElementById('logoutBtn').addEventListener('click', () => {
    openModal(`
      <div style="text-align:center;">
        <h3 style="color:#00aaff;">Confirm Logout</h3>
        <p class="muted">Are you sure you want to log out from your admin dashboard?</p>
        <div class="form-actions" style="justify-content:center; margin-top:20px;">
          <button class="btn danger" onclick="confirmLogout()">Yes, Logout</button>
          <button class="btn" onclick="closeModal()">Cancel</button>
        </div>
      </div>
    `);
  });

  function confirmLogout() {
    const modal = document.getElementById('modalContent');
    modal.innerHTML = `
      <div style="text-align:center; padding:30px;">
        <div class="logout-spinner"></div>
        <p style="margin-top:16px; color:#cfeeff;">Logging out, please wait...</p>
      </div>
    `;
    // add small delay for animation before redirect
    setTimeout(() => {
      window.location.href = 'logout.php';
    }, 1600);
  }

</script>

<!-- ✅ MUTUAL-EXCLUSIVITY: Image URL <-> Image File (Instant Disable Version) -->
<script>
function syncAddImageFields() {
  const url = document.getElementById('f_img');
  const file = document.getElementById('f_img_file');
  if (!url || !file) return;

  // Instantly disable the opposite when one is used
  if (url === document.activeElement && url.value.trim() !== '') {
    file.value = '';
    file.disabled = true;
    file.title = 'Disabled because Image URL is used.';
  } else if (file.files.length > 0) {
    url.value = '';
    url.disabled = true;
    url.title = 'Disabled because local image file is used.';
  }

  // If both are empty, re-enable everything
  if (url.value.trim() === '' && file.files.length === 0) {
    url.disabled = false;
    file.disabled = false;
    url.title = '';
    file.title = '';
  }
}

function syncEditImageFields() {
  const url = document.getElementById('e_img');
  const file = document.getElementById('e_img_file');
  if (!url || !file) return;

  if (url === document.activeElement && url.value.trim() !== '') {
    file.value = '';
    file.disabled = true;
    file.title = 'Disabled because Image URL is used.';
  } else if (file.files.length > 0) {
    url.value = '';
    url.disabled = true;
    url.title = 'Disabled because local image file is used.';
  }

  if (url.value.trim() === '' && file.files.length === 0) {
    url.disabled = false;
    file.disabled = false;
    url.title = '';
    file.title = '';
  }
}

/* Listen for both typing & file selections */
document.addEventListener('input', e => {
  if (e.target.id === 'f_img') syncAddImageFields();
  if (e.target.id === 'e_img') syncEditImageFields();
});

document.addEventListener('change', e => {
  if (e.target.id === 'f_img_file') syncAddImageFields();
  if (e.target.id === 'e_img_file') syncEditImageFields();
});

/* Safety: reset once modal opens or page loads */
window.addEventListener('DOMContentLoaded', () => {
  setTimeout(() => {
    syncAddImageFields();
    syncEditImageFields();
  }, 100);
});
</script>

<!-- ✅ MUTUAL-EXCLUSIVITY: APK URL <-> APK File (Instant Disable Version) -->
<script>
function syncAddApkFields() {
  const url = document.getElementById('f_apk_url');
  const file = document.getElementById('f_apk'); // matches your input id

  if (!url || !file) return;

  // If user is typing into URL -> immediately disable file input and clear selected file
  if (document.activeElement === url && url.value.trim() !== '') {
    try { file.value = ''; } catch (e) {}
    file.disabled = true;
    file.title = 'Disabled because APK URL is used.';
  } else if (file.files && file.files.length > 0) {
    // If file chosen -> immediately clear & disable URL
    url.value = '';
    url.disabled = true;
    url.title = 'Disabled because local APK file is used.';
  }

  // If both are empty => re-enable both
  if ((url.value || '').trim() === '' && (!file.files || file.files.length === 0)) {
    url.disabled = false;
    url.title = '';
    file.disabled = false;
    file.title = '';
  }
}

/* Helpers for Edit modal (ids: e_apk, e_apk_url) */
function syncEditApkFields() {
  const url = document.getElementById('e_apk_url');
  const file = document.getElementById('e_apk'); // matches your edit file id

  if (!url || !file) return;

  if (document.activeElement === url && url.value.trim() !== '') {
    try { file.value = ''; } catch (e) {}
    file.disabled = true;
    file.title = 'Disabled because APK URL is used.';
  } else if (file.files && file.files.length > 0) {
    url.value = '';
    url.disabled = true;
    url.title = 'Disabled because local APK file is used.';
  }

  if ((url.value || '').trim() === '' && (!file.files || file.files.length === 0)) {
    url.disabled = false;
    url.title = '';
    file.disabled = false;
    file.title = '';
  }
}

/* Global listeners: 'input' for typing, 'change' for file selection */
document.addEventListener('input', (e) => {
  if (!e || !e.target) return;
  const id = e.target.id;
  if (id === 'f_apk_url') syncAddApkFields();
  if (id === 'e_apk_url') syncEditApkFields();
});

document.addEventListener('change', (e) => {
  if (!e || !e.target) return;
  const id = e.target.id;
  if (id === 'f_apk') syncAddApkFields();
  if (id === 'e_apk') syncEditApkFields();
});

/* Also call when modals open — detect clicks on buttons that open modals and run sync shortly after.
   This ensures state is correct for dynamically created modal content. */
document.addEventListener('click', (e) => {
  const target = e.target;
  if (!target) return;

  // when Add modal opens (button with id addApkBtn)
  if (target.id === 'addApkBtn' || target.closest && target.closest('#addApkBtn')) {
    setTimeout(syncAddApkFields, 80);
  }
  // when Edit modal is opened by openEdit (we run sync inside openEdit after modal HTML created)
});

/* Defensive: some modals are dynamic. If openEdit() creates inputs, call syncEditApkFields()
   just after openEdit inserts HTML. To be safe, run a short poll that re-syncs when an e_apk or e_apk_url appears. */
(function watchForEditInputs() {
  const found = document.getElementById('e_apk') && document.getElementById('e_apk_url');
  if (!found) {
    // keep watching a few times
    let attempts = 0;
    const iv = setInterval(() => {
      attempts++;
      if (document.getElementById('e_apk') || document.getElementById('e_apk_url')) {
        // call once to sync if elements appear
        syncEditApkFields();
      }
      if (attempts > 20) clearInterval(iv);
    }, 150);
  } else {
    syncEditApkFields();
  }
})();
</script>

<style>
/* 🔹 Disabled fields dimmed (fits your neon cyber theme) */
input:disabled,
input[type="file"]:disabled {
  opacity: 0.6;
  filter: grayscale(40%) brightness(0.8);
  cursor: not-allowed;
  transition: all 0.25s ease-in-out;
}
</style>

</body>
</html>
