<?php
session_start();

// Load config
$config = require __DIR__ . '/config.php';

$showSuccessOverlay = false;
$errorMessage = '';

// RATE LIMIT CONFIG
$maxAttempts = 5;
$blockSeconds = 5*60; // 5 minutes

// initialize rate-limit structures in session
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['first_failed_at'] = null;
}

function is_blocked() {
    global $maxAttempts, $blockSeconds;
    if (!isset($_SESSION['login_attempts']) || $_SESSION['login_attempts'] < $maxAttempts) return false;
    if (!isset($_SESSION['first_failed_at'])) return false;
    return (time() - $_SESSION['first_failed_at']) < $blockSeconds;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (is_blocked()) {
        $remain = $blockSeconds - (time() - $_SESSION['first_failed_at']);
        $errorMessage = 'Too many failed attempts. Try again in ' . ceil($remain) . ' seconds.';
    } else {
        $username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        if ($username === $config->admin_user && password_verify($password, $config->admin_pass_hash)) {
            // success
            $_SESSION['admin_logged_in'] = true;
            // reset attempts
            $_SESSION['login_attempts'] = 0;
            $_SESSION['first_failed_at'] = null;
            $showSuccessOverlay = true;
        } else {
            // failed
            if ($_SESSION['login_attempts'] === 0) {
                $_SESSION['first_failed_at'] = time();
            }
            $_SESSION['login_attempts'] += 1;
            if ($_SESSION['login_attempts'] >= $maxAttempts) {
                $errorMessage = 'Too many failed attempts. Blocked for ' . ($blockSeconds/60) . ' minutes.';
            } else {
                $errorMessage = 'Invalid Credentials. Attempts: ' . $_SESSION['login_attempts'] . '/' . $maxAttempts;
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Login - AppVerse</title>
  <style>
    /* Keep your existing styles (copied from your original login.php) */
    /* ... (omitted here to keep answer short) ... */
    /* I recommend copying the exact CSS you already had into this file to keep theme identical. */
    body { font-family: 'Poppins', sans-serif; background: radial-gradient(circle at top, #0b0f19, #050710); color: #e6e6e6; margin: 0; height: 100vh; display:flex; align-items:center; justify-content:center; overflow:hidden; }
    .login-container { background: rgba(21, 29, 47, 0.9); border: 1px solid rgba(0,170,255,0.2); border-radius: 15px; padding: 40px 45px; width: 380px; box-shadow: 0 8px 25px rgba(0,170,255,0.15); text-align: center; position: relative; overflow: hidden; backdrop-filter: blur(12px); z-index: 2; }
    .glow-border { position: absolute; inset: -3px; border-radius: 16px; background: linear-gradient(60deg, rgba(0,170,255,0.18), rgba(0,200,255,0.08)); mix-blend-mode: screen; filter: blur(12px); animation: pulse 3s infinite ease-in-out; z-index: 0; }
    @keyframes pulse { 0%,100% {opacity:0.7; transform:scale(1);} 50% {opacity:1; transform:scale(1.02);} }
    .login-content { position: relative; z-index: 2; }
    h2 { color: #00aaff; margin-bottom: 20px; font-size: 1.8em; }
    form { display:flex; flex-direction:column; gap:15px; }
    input[type="text"], input[type="password"] { padding:12px; border:none; border-radius:8px; background: rgba(255,255,255,0.05); color:#fff; font-size:15px; outline:none; transition: box-shadow 0.3s ease; }
    input[type="text"]:focus, input[type="password"]:focus { box-shadow: 0 0 8px rgba(0,170,255,0.4); }
    button { background-color: #00aaff; border:none; color:#fff; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; font-size:15px; transition: background 0.3s ease; }
    button:hover { background-color: #0078c8; }
    .error { color:#ff6b6b; margin-top:10px; font-size:14px; }
    .back-link { display:inline-block; margin-top:15px; color:#cfeeff; text-decoration:none; font-size:14px; }
    .back-link:hover { color:#00aaff; text-decoration:underline; }

    /* ✅ Success Animation Overlay */
    /* ✅ Success Animation Overlay (Upgraded) */
    .success-overlay {
      position: fixed;
      inset: 0;
      background: radial-gradient(circle at center, rgba(0,170,255,0.15), #000810 80%);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-direction: column;
      z-index: 9999;
      animation: fadeIn 0.5s ease forwards;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    .success-icon {
      width: 90px;
      height: 90px;
      border: 4px solid #00aaff;
      border-radius: 50%;
      position: relative;
      box-shadow: 0 0 20px #00aaff88;
      animation: pulse 2s infinite ease-in-out;
    }

    .checkmark {
      position: absolute;
      left: 25px;
      top: 35px;
      width: 25px;
      height: 45px;
      border-right: 4px solid #00e6ff;
      border-bottom: 4px solid #00e6ff;
      transform: rotate(45deg);
      animation: drawCheck 0.8s ease forwards 0.4s;
      opacity: 0;
    }

    @keyframes drawCheck {
      to { opacity: 1; }
    }

    .success-text {
      margin-top: 25px;
      color: #ffffff; /* changed to white */
      font-size: 20px;
      font-weight: 600;
      text-shadow: 0 0 18px #00aaffaa, 0 0 30px #0078c8aa;
      animation: fadeIn 1s ease forwards 0.6s, pulseText 3s infinite ease-in-out 1.2s;
      opacity: 0;
    }

    @keyframes pulseText {
      0%, 100% { text-shadow: 0 0 10px #00aaffaa, 0 0 25px #0078c8aa; }
      50% { text-shadow: 0 0 25px #00e6ffaa, 0 0 40px #00aaffaa; }
    }

    /* ✨ New redirect animation for the whole overlay */
    @keyframes slideAway {
      0% { opacity: 1; transform: translateY(0); }
      80% { opacity: 0.9; transform: translateY(-30px) scale(1.05); }
      100% { opacity: 0; transform: translateY(-80px) scale(0.95); }
    }



    /* small responsive tweak */
    @media (max-width: 420px) {
      .login-container { width: 320px; padding: 30px; }
    }
  </style>
</head>
<body>
  <div class="login-container" aria-live="polite">
    <div class="glow-border" aria-hidden="true"></div>
    <div class="login-content">
      <h2>Admin Login</h2>

      <?php if ($errorMessage !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES); ?></p>
      <?php endif; ?>

      <form method="POST" action="">
        <input type="text" name="username" placeholder="Username" required autocomplete="username" />
        <input type="password" name="password" placeholder="Password" required autocomplete="current-password" />
        <button type="submit">Login</button>
      </form>

      <a href="index.html" class="back-link">← Back to Home</a>
    </div>
  </div>

  <?php if ($showSuccessOverlay): ?>
  <div class="success-overlay" id="doorOverlay">
    <div class="door-container">
      <div class="door left-door"></div>
      <div class="door right-door"></div>
      <div class="door-text">Access Granted</div>
    </div>
  </div>

  <style>
    .door-container {
      position: relative;
      width: 100%;
      height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
      overflow: hidden;
      background: radial-gradient(circle at center, #00111a 0%, #000 100%);
    }

    .door {
      position: absolute;
      width: 50%;
      height: 100%;
      top: 0;
      background: linear-gradient(145deg, #001f33, #000);
      border: 2px solid #00aaff33;
      box-shadow: inset 0 0 20px #00aaff33;
      transition: transform 1.5s ease-in-out;
      z-index: 5;
    }

    .left-door {
      left: 0;
      border-right: 1px solid #00aaff77;
    }

    .right-door {
      right: 0;
      border-left: 1px solid #00aaff77;
    }

    .door-text {
      position: absolute;
      z-index: 10;
      color: #00d0ff;
      font-size: 26px;
      font-weight: 600;
      text-shadow: 0 0 15px #00aaff, 0 0 30px #00ffff;
      letter-spacing: 2px;
      opacity: 0;
      transform: scale(0.9);
      transition: opacity 0.8s ease, transform 0.8s ease;
    }

    .doors-open .left-door {
      transform: translateX(-100%);
    }

    .doors-open .right-door {
      transform: translateX(100%);
    }

    .doors-open .door-text {
      opacity: 1;
      transform: scale(1);
    }
  </style>

  <script>
    const container = document.querySelector('.door-container');
    const text = document.querySelector('.door-text');

    // Start door animation after a short delay
    setTimeout(() => {
      container.classList.add('doors-open');
    }, 300);

    // Redirect after animation completes
    setTimeout(() => {
      text.textContent = "Welcome to Dashboard...";
    }, 1200);

    setTimeout(() => {
      window.location.replace('admin-dashboard.php');
    }, 2500);
  </script>
<?php endif; ?>

</body>
</html>
