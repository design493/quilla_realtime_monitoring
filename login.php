<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

if (isLoggedIn()) {
    header('Location: /quilla_production/index.php'); exit;
}

// ── RATE LIMITING ─────────────────────────────────────────────────────────
// Uses PHP session to track attempts per IP.
// Max 3 attempts, then 5-minute lockout.
if (session_status() === PHP_SESSION_NONE) session_start();

$MAX_ATTEMPTS  = 3;
$LOCKOUT_SECS  = 5 * 60; // 5 minutes
$ip            = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$sessionKey    = 'login_attempts_' . md5($ip);
$lockKey       = 'login_locked_until_' . md5($ip);

$now           = time();
$lockedUntil   = $_SESSION[$lockKey] ?? 0;
$isLocked      = $lockedUntil > $now;
$secsRemaining = $isLocked ? ($lockedUntil - $now) : 0;
$attempts      = $_SESSION[$sessionKey] ?? 0;

$error = '';
$lockMsg = '';

if ($isLocked) {
    $mins = floor($secsRemaining / 60);
    $secs = $secsRemaining % 60;
    $lockMsg = sprintf('Too many failed attempts. Try again in %d:%02d.', $mins, $secs);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username && $password) {
        $pdo  = getPDO();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        $selectedRoleInput = $_POST['selected_role'] ?? '';
        // Map UI role label to DB role value
        $roleMap = ['admin' => 'admin', 'production' => 'supervisor'];
        $expectedDbRole = $roleMap[$selectedRoleInput] ?? null;

        if ($user && password_verify($password, $user['password_hash'])) {
            // ✅ Credentials OK — now check role matches
            if ($expectedDbRole && $user['role'] !== $expectedDbRole) {
                // Wrong role selected — show specific error
                $attempts++;
                $_SESSION[$sessionKey] = $attempts;
                $_SESSION['login_role_hint'] = $selectedRoleInput;
                $remaining = $MAX_ATTEMPTS - $attempts;
                if ($attempts >= $MAX_ATTEMPTS) {
                    $_SESSION[$lockKey] = $now + $LOCKOUT_SECS;
                    $lockMsg = 'Too many failed attempts. Try again in 5:00.';
                } else {
                    $error = 'Wrong role selected. This account is not a ' . ucfirst($selectedRoleInput) . '. ' . $remaining . ' attempt' . ($remaining===1?'':'s') . ' remaining.';
                }
            } else {
                // ✅ Role matches — clear counters and log in
                unset($_SESSION[$sessionKey], $_SESSION[$lockKey], $_SESSION['login_role_hint']);
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role']      = $user['role'];
                // admin → admin dashboard, supervisor (production) → production panel
                $dest = ($user['role'] === 'admin') ? 'admin/dashboard.php' : 'index.php';
                header("Location: /quilla_production/$dest"); exit;
            }
        } else {
            // ❌ Wrong credentials — increment counter
            $_SESSION['login_role_hint'] = $selectedRoleInput;
            $attempts++;
            $_SESSION[$sessionKey] = $attempts;
            $remaining = $MAX_ATTEMPTS - $attempts;
            if ($attempts >= $MAX_ATTEMPTS) {
                $_SESSION[$lockKey] = $now + $LOCKOUT_SECS;
                $lockMsg = 'Too many failed attempts. Try again in 5:00.';
                $error   = '';
            } else {
                $error = 'Invalid username or password. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.';
            }
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quilla — Login</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
h2{text-align:center;}
.sub{text-align:center;}
body{font-family:'Segoe UI',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;position:relative;overflow:hidden; background: #263e66}
.gif-bg{position:fixed;top:0;left:0;width:100%;height:100%;z-index:0;object-fit:cover; opacity: 50%;}
.card{background:#fff;border-radius:16px;box-shadow:0 4px 30px rgba(0,0,0,.10);padding:48px 40px;width:100%;max-width:420px;position:relative;z-index:1}
.logo{text-align:center;margin-bottom:32px}
.logo img{max-width: 180px;}
.logo-text{font-size:28px;font-weight:800;letter-spacing:-1px;color:#1a1a2e}
.logo-text span{color:#e85d04}
.logo-sub{font-size:11px;color:#888;letter-spacing:2px;text-transform:uppercase;margin-top:2px}
h2{font-size:20px;font-weight:700;color:#1a1a2e;margin-bottom:6px}
p.sub{color:#888;font-size:14px;margin-bottom:28px}
label{display:block;font-size:13px;font-weight:600;color:#444;margin-bottom:6px}
input[type=text],input[type=password]{width:100%;padding:12px 14px;border:1.5px solid #e0e0e0;border-radius:10px;font-size:15px;outline:none;transition:.2s;background:#fafafa}
input:focus{border-color:#5877cb;background:#fff}
.btn{width:100%;padding:13px;background:#142e75;color:#fff;border:none;border-radius:10px;font-size:16px;font-weight:700;cursor:pointer;margin-top:22px;transition:.2s;letter-spacing:.3px}
.btn:hover{background:#5877cb}
.error{background:#fff0ed;color:#c94e00;border:1px solid #fbc9b5;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:18px}
.field{margin-bottom:18px}
.hint{font-size:12px;color: #122a6b;text-align:center;margin-top:18px}
</style>
</head>
<body>

<?php
$gif = "assets/tech.gif"; // path ng GIF file
?>
<img src="<?php echo $gif; ?>" alt="" class="gif-bg">

<div class="card">
  <div class="logo">
    <div class="nav-logo"><img src="assets/quilla_logo 2.jpg" alt="quilla"></div>
  </div>
  <!-- Role selector — shown first, slides to login form -->
  <div id="roleStep">
    <h2>Welcome back</h2>
    <p class="sub">Select your role to continue</p>
    <div class="role-grid">
      <button type="button" class="role-card" id="roleAdmin" onclick="selectRole('admin')">
        <div class="role-icon" style="background: #2557d6">👤</div>
        <div class="role-name">Admin</div>
        <div class="role-desc">Full system access &amp; management</div>
        <div class="role-check" id="checkAdmin"></div>
      </button>
      <button type="button" class="role-card" id="roleProd" onclick="selectRole('production')">
        <div class="role-icon" style="background: #e9e793">👥</div>
        <div class="role-name">Production</div>
        <div class="role-desc">Output input &amp; inventory access</div>
        <div class="role-check" id="checkProd"></div>
      </button>
    </div>
    <button type="button" class="btn" id="roleNextBtn" onclick="goToLogin()" disabled
      style="margin-top:20px;opacity:.4;cursor:not-allowed">
      Continue →
    </button>
  </div>

  <!-- Login form — hidden until role is selected -->
  <div id="loginStep" style="display:none">
    <div id="rolePill" style="display:flex;align-items:center;justify-content:space-between;
      border-radius:10px;padding:10px 14px;margin-bottom:20px;font-size:13px;font-weight:700">
    </div>

    <?php if ($lockMsg): ?>
      <div class="error lockout-box">
        <div style="font-size:28px;text-align:center;margin-bottom:8px">🔒</div>
        <div style="font-weight:700;font-size:14px;text-align:center;margin-bottom:4px">Account Temporarily Locked</div>
        <div style="text-align:center;font-size:13px" id="lockMsg"><?= htmlspecialchars($lockMsg) ?></div>
        <div style="text-align:center;margin-top:10px">
          <span style="font-size:11px;color:#c94e00;font-weight:600">Remaining: </span>
          <span id="countdown" style="font-family:monospace;font-size:15px;font-weight:800;color:#c94e00"><?= sprintf('%d:%02d', floor($secsRemaining/60), $secsRemaining%60) ?></span>
        </div>
      </div>
      <div style="height:4px;background:#fde8de;border-radius:4px;margin-bottom:18px;overflow:hidden">
        <div id="lockBar" style="height:100%;background:#e85d04;border-radius:4px;transition:width 1s linear;width:<?= round(($secsRemaining/$LOCKOUT_SECS)*100) ?>%"></div>
      </div>
    <?php elseif ($error): ?>
      <div class="error">
        <?= htmlspecialchars($error) ?>
        <div style="display:flex;justify-content:center;gap:6px;margin-top:10px">
          <?php for($d=0;$d<$MAX_ATTEMPTS;$d++): ?>
            <span style="width:10px;height:10px;border-radius:50%;background:<?= $d < $attempts ? '#c94e00' : '#fbc9b5' ?>;display:inline-block"></span>
          <?php endfor; ?>
        </div>
      </div>
    <?php endif; ?>

    <form method="POST" <?= $isLocked ? 'style="opacity:.45;pointer-events:none"' : '' ?>>
      <input type="hidden" name="selected_role" id="hiddenRole" value="">
      <div class="field">
        <label>Username</label>
        <input type="text" name="username" placeholder="Enter username"
          value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
          <?= $isLocked ? 'disabled' : 'required autofocus' ?>>
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" placeholder="Enter password"
          <?= $isLocked ? 'disabled' : 'required' ?>>
      </div>
      <button type="submit" class="btn" id="signInBtn" <?= $isLocked ? 'disabled' : '' ?>>Sign In →</button>
    </form>

    <?php if (!$isLocked && $attempts > 0): ?>
    <div style="display:flex;justify-content:center;gap:6px;margin-top:14px">
      <?php for($d=0;$d<$MAX_ATTEMPTS;$d++): ?>
        <span style="width:10px;height:10px;border-radius:50%;background:<?= $d < $attempts ? '#c94e00' : '#e0e0e0' ?>;display:inline-block"></span>
      <?php endfor; ?>
    </div>
    <?php endif; ?>

    <button type="button" onclick="backToRole()"
      style="background:none;border:none;color:#888;font-size:12px;cursor:pointer;margin-top:14px;width:100%;text-align:center">
      ← Change role
    </button>
  </div>

  <p class="hint">"Small progress every day creates big results."</p>
</div>
<style>
.lockout-box{background:#fff0ed;color:#c94e00;border:1.5px solid #fbc9b5;border-radius:10px;padding:16px 14px;margin-bottom:10px}

/* Role selector */
.role-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:20px}
.role-card{background:#f8faff;border:2px solid #e0e7f0;border-radius:14px;padding:20px 14px 16px;
  cursor:pointer;text-align:center;transition:all .2s;position:relative;outline:none;
  display:flex;flex-direction:column;align-items:center;gap:8px}
.role-card:hover{border-color:#5877cb;background:#eff3ff;transform:translateY(-2px);box-shadow:0 6px 18px rgba(26,58,143,.12)}
.role-card.selected-admin{border-color:#1a3a8f;background:#eff3ff;box-shadow:0 0 0 3px rgba(26,58,143,.15)}
.role-card.selected-prod{border-color:#16a34a;background:#f0fdf4;box-shadow:0 0 0 3px rgba(22,163,74,.15)}
.role-icon{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:24px;margin-bottom:2px;box-shadow:0 4px 12px rgba(0,0,0,.12)}
.role-name{font-size:15px;font-weight:800;color:#1a1a2e;letter-spacing:.3px}
.role-desc{font-size:11px;color:#888;line-height:1.4}
.role-check{width:20px;height:20px;border-radius:50%;border:2px solid #ddd;position:absolute;
  top:10px;right:10px;transition:all .2s;display:flex;align-items:center;justify-content:center;font-size:11px}
.role-card.selected-admin .role-check{background:#1a3a8f;border-color:#1a3a8f;color:#fff}
.role-card.selected-admin .role-check::after{content:'✓'}
.role-card.selected-prod .role-check{background:#16a34a;border-color:#16a34a;color:#fff}
.role-card.selected-prod .role-check::after{content:'✓'}

/* Step transitions */
#roleStep,#loginStep{transition:opacity .25s}
</style>

<?php if($isLocked): ?>
<script>
(function(){
  var secs = <?= $secsRemaining ?>;
  var total = <?= $LOCKOUT_SECS ?>;
  var cd    = document.getElementById('countdown');
  var bar   = document.getElementById('lockBar');
  var timer = setInterval(function(){
    secs--;
    if (secs <= 0) {
      clearInterval(timer);
      // Auto-reload so user can try again
      window.location.reload();
      return;
    }
    var m = Math.floor(secs/60), s = secs%60;
    if(cd)  cd.textContent = m + ':' + (s<10?'0':'') + s;
    if(bar) bar.style.width = Math.round((secs/total)*100) + '%';
  }, 1000);
})();
</script>
<?php endif; ?>
<script>
var selectedRole = '';

// If returning from a failed POST, restore the login step
<?php if ($_SERVER['REQUEST_METHOD']==='POST' || $isLocked || $error): ?>
(function(){
  var sr = '<?= htmlspecialchars($_POST['selected_role'] ?? ($isLocked ? ($_SESSION['login_role_hint'] ?? '') : '')) ?>';
  if (!sr) sr = 'admin'; // default fallback
  selectedRole = sr;
  showLoginStep(sr);
})();
<?php endif; ?>

function selectRole(role) {
  selectedRole = role;
  document.getElementById('roleAdmin').className = 'role-card' + (role==='admin' ? ' selected-admin' : '');
  document.getElementById('roleProd').className  = 'role-card' + (role==='production' ? ' selected-prod' : '');
  var btn = document.getElementById('roleNextBtn');
  btn.disabled = false;
  btn.style.opacity = '1';
  btn.style.cursor  = 'pointer';
  // Update button color to match role
  btn.style.background = role === 'admin' ? '#142e75' : '#16a34a';
}

function goToLogin() {
  if (!selectedRole) return;
  showLoginStep(selectedRole);
}

function showLoginStep(role) {
  document.getElementById('roleStep').style.display  = 'none';
  document.getElementById('loginStep').style.display = 'block';
  document.getElementById('hiddenRole').value = role;

  // Style the role pill
  var pill = document.getElementById('rolePill');
  var isAdmin = role === 'admin';
  pill.style.background = isAdmin ? '#eff3ff' : '#f0fdf4';
  pill.style.border     = '1.5px solid ' + (isAdmin ? '#1a3a8f' : '#16a34a');
  pill.style.color      = isAdmin ? '#1a3a8f' : '#166534';
  pill.innerHTML =
    '<span>' + (isAdmin ? '👤 Signing in as <strong>Admin</strong>' : '👥 Signing in as <strong>Production</strong>') + '</span>' +
    '<span style="font-size:11px;opacity:.7">← change</span>';
  pill.style.cursor = 'pointer';
  pill.onclick = backToRole;

  // Update sign in button color
  var signBtn = document.getElementById('signInBtn');
  if (signBtn) signBtn.style.background = isAdmin ? '#142e75' : '#16a34a';
}

function backToRole() {
  document.getElementById('loginStep').style.display = 'none';
  document.getElementById('roleStep').style.display  = 'block';
  if (selectedRole) selectRole(selectedRole); // keep visual selection
}
</script>
</body>
</html>