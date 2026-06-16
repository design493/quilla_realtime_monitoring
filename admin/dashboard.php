<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
// ── Philippine Working Days Helper (inline) ──────────────────────────────
if (!function_exists('getPHHolidays')) {
    function getPHHolidays(int $year): array {
        $fixed = [
            '01-01','02-25','04-09','05-01','06-12',
            '08-21','11-01','11-02','11-30',
            '12-08','12-24','12-25','12-30','12-31',
        ];
        $holidays = array_map(fn($md) => "$year-$md", $fixed);
        $holidays[] = date('Y-m-d', strtotime("last monday of august $year"));
        $easter = easter_date($year);
        $holidays[] = date('Y-m-d', $easter - 3 * 86400);
        $holidays[] = date('Y-m-d', $easter - 2 * 86400);
        $holidays[] = date('Y-m-d', $easter - 1 * 86400);
        $eids = [
            'fitr' => [2024=>'2024-04-10',2025=>'2025-03-31',2026=>'2026-03-20',2027=>'2027-03-10'],
            'adha' => [2024=>'2024-06-17',2025=>'2025-06-07',2026=>'2026-05-27',2027=>'2027-05-17'],
        ];
        foreach ($eids as $e) { if (isset($e[$year])) $holidays[] = $e[$year]; }
        return array_values(array_unique($holidays));
    }
    function countWorkingDaysUntilDeadline(string $deadlineDate): int {
        $today    = new DateTime(date('Y-m-d'));
        $deadline = new DateTime($deadlineDate);
        if ($deadline <= $today) return 0;
        $sy = (int)$today->format('Y'); $ey = (int)$deadline->format('Y');
        $hols = [];
        for ($y = $sy; $y <= $ey; $y++) $hols = array_merge($hols, getPHHolidays($y));
        $holSet = array_flip($hols);
        $count = 0; $cur = clone $today; $cur->modify('+1 day');
        while ($cur <= $deadline) {
            $dow = (int)$cur->format('N');
            if ($dow < 6 && !isset($holSet[$cur->format('Y-m-d')])) $count++;
            $cur->modify('+1 day');
        }
        return $count;
    }
}
// ─────────────────────────────────────────────────────────────────────────
requireAdmin();

$pdo  = getPDO();
$user = currentUser();

// Stats
$totalOrders     = $pdo->query("SELECT COUNT(*) FROM production_orders WHERE status='active'")->fetchColumn();
$totalUsers      = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();

$activeOrder = $pdo->query("SELECT * FROM production_orders WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
$orderId     = $activeOrder['id'] ?? 0;

$finStage = $pdo->query("SELECT id, name FROM stages WHERE COALESCE(is_active,1)=1 ORDER BY sort_order DESC LIMIT 1")->fetch();
$finId    = $finStage['id'] ?? 5;

$totalCompleted = 0;
if ($orderId) {
    $s = $pdo->prepare("SELECT COALESCE(SUM(do.qty_produced),0) FROM daily_outputs do JOIN order_sizes os ON os.id=do.order_size_id WHERE os.order_id=? AND do.stage_id=?");
    $s->execute([$orderId, $finId]);
    $totalCompleted = (int)$s->fetchColumn();
}

// Today's output by stage
$todayStage = $pdo->prepare("
    SELECT s.id, s.name, COALESCE(SUM(do.qty_produced),0) as total
    FROM stages s
    LEFT JOIN daily_outputs do
        ON  do.stage_id = s.id
        AND do.log_date = CURDATE()
        AND do.order_size_id IN (
            SELECT id FROM order_sizes WHERE order_id = ?
        )
    GROUP BY s.id, s.name ORDER BY s.sort_order
");
$todayStage->execute([$orderId]);
$todayStage = $todayStage->fetchAll();

// Consistency last 14 days
$consistency = $pdo->prepare("SELECT log_date, total_pairs FROM daily_order_totals WHERE order_id=? ORDER BY log_date DESC LIMIT 14");
$consistency->execute([$orderId]);
$consistency = array_reverse($consistency->fetchAll());

// Per-stage consistency — last 14 days with data, per stage
$stageConsistency = [];
if ($orderId) {
    $scDates = array_map(fn($c) => $c['log_date'], $consistency);
    // Get all active stages
    $activeStages = $pdo->query("SELECT * FROM stages WHERE COALESCE(is_active,1)=1 ORDER BY sort_order")->fetchAll();
    foreach ($activeStages as $ast) {
        $stageConsistency[$ast['id']] = ['name' => $ast['name'], 'data' => []];
        foreach ($scDates as $ld) {
            $sq = $pdo->prepare("
                SELECT COALESCE(SUM(do.qty_produced),0)
                FROM daily_outputs do
                JOIN order_sizes os ON os.id=do.order_size_id
                WHERE os.order_id=? AND do.stage_id=? AND do.log_date=?
            ");
            $sq->execute([$orderId, $ast['id'], $ld]);
            $stageConsistency[$ast['id']]['data'][] = (int)$sq->fetchColumn();
        }
    }
}

// Recent outputs — from activity log to show add & subtract actions
$recentOut = $pdo->prepare("
    SELECT al.log_date, os.size_label, s.name as stage,
           al.action, al.qty_change as qty_produced, u.full_name,
           COALESCE(al.confirmed_by,'') as confirmed_by,
           COALESCE(al.subtract_reason,'') as subtract_reason,
           al.entered_at
    FROM output_activity_log al
    JOIN order_sizes os ON os.id = al.order_size_id
    JOIN stages s ON s.id = al.stage_id
    JOIN users u ON u.id = al.entered_by
    WHERE os.order_id=?
    ORDER BY al.entered_at DESC LIMIT 50
");
try {
    $recentOut->execute([$orderId]);
    $recentOut = $recentOut->fetchAll();
} catch(Exception $e) { $recentOut = []; }

// All users
$allUsers = $pdo->query("SELECT * FROM users ORDER BY role, full_name")->fetchAll();

// All sizes
$allSizes = [];
if ($orderId) {
    $st = $pdo->prepare("SELECT * FROM order_sizes WHERE order_id=? ORDER BY sort_order");
    $st->execute([$orderId]);
    $allSizes = $st->fetchAll();
}

$stages = $pdo->query("SELECT * FROM stages ORDER BY sort_order")->fetchAll();

// Inventory summary for dashboard
$invTotalMats  = 0; $invLowStock = 0; $invOutOfStock = 0; $invLowItems = [];
try {
    $invTotalMats  = (int)$pdo->query("SELECT COUNT(*) FROM inventory_materials WHERE is_active=1")->fetchColumn();
    $invLowStock   = (int)$pdo->query("SELECT COUNT(*) FROM inventory_materials WHERE is_active=1 AND quantity_in_stock <= minimum_stock AND minimum_stock > 0 AND quantity_in_stock > 0")->fetchColumn();
    $invOutOfStock = (int)$pdo->query("SELECT COUNT(*) FROM inventory_materials WHERE is_active=1 AND quantity_in_stock = 0")->fetchColumn();
    $invLowStmt    = $pdo->query("SELECT material_name, quantity_in_stock, minimum_stock, unit FROM inventory_materials WHERE is_active=1 AND ((quantity_in_stock <= minimum_stock AND minimum_stock > 0) OR quantity_in_stock = 0) ORDER BY quantity_in_stock ASC LIMIT 5");
    $invLowItems   = $invLowStmt->fetchAll();
} catch(Exception $e){}

// Archived orders — queried at top so it reflects latest DB state after any POST/redirect
$archivedOrders = $pdo->query("
    SELECT po.*,
           u.full_name as created_by_name,
           (SELECT COALESCE(SUM(do2.qty_produced),0)
            FROM daily_outputs do2
            JOIN order_sizes os2 ON os2.id=do2.order_size_id
            JOIN stages st2 ON st2.id=do2.stage_id
            WHERE os2.order_id=po.id AND st2.name='Finishing'
           ) as total_completed,
           (SELECT COUNT(DISTINCT do3.log_date)
            FROM daily_outputs do3
            JOIN order_sizes os3 ON os3.id=do3.order_size_id
            WHERE os3.order_id=po.id
           ) as active_days
    FROM production_orders po
    LEFT JOIN users u ON u.id=po.created_by
    WHERE po.status='archived'
    ORDER BY po.id DESC
")->fetchAll();

$msg = '';
// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $un   = trim($_POST['username'] ?? '');
        $fn   = trim($_POST['full_name'] ?? '');
        $pw   = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'viewer';
        if ($un && $fn && $pw) {
            $hash = password_hash($pw, PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role) VALUES (?,?,?,?)")->execute([$un,$hash,$fn,$role]);
            $msg = "User '$un' created successfully.";
        }
    }

    if ($action === 'edit_user') {
        $uid  = (int)($_POST['uid']      ?? 0);
        $un   = trim($_POST['username']  ?? '');
        $fn   = trim($_POST['full_name'] ?? '');
        $role = $_POST['role']           ?? '';
        $pw   = trim($_POST['password']  ?? '');
        if ($uid && $uid !== $user['id'] && $un && $fn) {
            if ($pw) {
                $hash = password_hash($pw, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE users SET username=?, full_name=?, role=?, password_hash=? WHERE id=?")
                    ->execute([$un, $fn, $role, $hash, $uid]);
            } else {
                $pdo->prepare("UPDATE users SET username=?, full_name=?, role=? WHERE id=?")
                    ->execute([$un, $fn, $role, $uid]);
            }
            $msg = "User '$un' updated successfully.";
        }
    }


    if ($action === 'toggle_user') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid && $uid !== $user['id']) {
            $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id=?")->execute([$uid]);
            $msg = "User status updated.";
        }
    }

    if ($action === 'delete_user') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid && $uid !== $user['id']) {
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
            $msg = "User deleted permanently.";
        }
    }

    if ($action === 'add_output') {
        $sizeId  = (int)($_POST['size_id']  ?? 0);
        $stageId = (int)($_POST['stage_id'] ?? 0);
        $qty     = (int)($_POST['qty']      ?? 0);
        $logDate = $_POST['log_date'] ?? date('Y-m-d');
        $confirmedBy = trim($_POST['confirmed_by'] ?? '');
        // Ensure column exists
        try { $pdo->exec("ALTER TABLE daily_outputs ADD COLUMN confirmed_by VARCHAR(120) NULL"); } catch(Exception $e){}
        if ($sizeId && $stageId && $qty > 0) {
            $pdo->prepare("INSERT INTO daily_outputs (order_size_id, stage_id, log_date, qty_produced, entered_by, confirmed_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE qty_produced = qty_produced + VALUES(qty_produced), confirmed_by = VALUES(confirmed_by)")->execute([$sizeId,$stageId,$logDate,$qty,$user['id'],$confirmedBy]);
            // Recalculate daily total
            $nt = $pdo->prepare("SELECT COALESCE(SUM(do.qty_produced),0) FROM daily_outputs do JOIN order_sizes os ON os.id=do.order_size_id WHERE os.order_id=? AND do.stage_id=? AND do.log_date=?");
            $nt->execute([$orderId,$finId,$logDate]);
            $nt = (int)$nt->fetchColumn();
            $pdo->prepare("INSERT INTO daily_order_totals (order_id,log_date,total_pairs) VALUES (?,?,?) ON DUPLICATE KEY UPDATE total_pairs=?")->execute([$orderId,$logDate,$nt,$nt]);
            $msg = "Output logged.";
        }
    }

    if ($action === 'update_target') {
        $target   = (int)($_POST['target_pairs'] ?? 0);
        $deadline = $_POST['deadline'] ?? '';
        $isAjax   = !empty($_POST['ajax']);
        if ($target > 0 && $deadline) {
            $pdo->prepare("UPDATE production_orders SET target_pairs=?, deadline=? WHERE id=?")->execute([$target,$deadline,$orderId]);
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'target_pairs' => $target, 'deadline' => $deadline]);
                exit;
            }
            $msg = "Order updated.";
        } elseif ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'msg' => 'Invalid input.']);
            exit;
        }
    }

    if ($action === 'add_size') {
        $label = trim($_POST['size_label'] ?? '');
        $qty   = (int)($_POST['target_qty'] ?? 0);
        if ($label && $qty > 0 && $orderId) {
            $maxSort = $pdo->prepare("SELECT MAX(sort_order) FROM order_sizes WHERE order_id=?");
            $maxSort->execute([$orderId]);
            $pdo->prepare("INSERT INTO order_sizes (order_id,size_label,target_qty,sort_order) VALUES (?,?,?,?)")->execute([$orderId,$label,$qty,(int)$maxSort->fetchColumn()+1]);
            $msg = "Size added.";
        }
    }

    if ($action === 'delete_size') {
        $sid = (int)($_POST['sid'] ?? 0);
        if ($sid) { $pdo->prepare("DELETE FROM order_sizes WHERE id=?")->execute([$sid]); $msg = "Size removed."; }
    }

    if ($action === 'edit_size') {
        $sid   = (int)($_POST['sid']        ?? 0);
        $label = trim($_POST['size_label']  ?? '');
        $qty   = (int)($_POST['target_qty'] ?? 0);
        if ($sid && $label && $qty > 0) {
            $pdo->prepare("UPDATE order_sizes SET size_label=?, target_qty=? WHERE id=?")->execute([$label, $qty, $sid]);
            $msg = "Size updated.";
        }
    }

    if ($action === 'add_confirmer') {
        $name = trim($_POST['confirmer_name'] ?? '');
        if ($name) {
            $cf = __DIR__ . '/../config/confirmers.json';
            $data = file_exists($cf) ? json_decode(file_get_contents($cf), true) : ['names'=>[]];
            if (!in_array($name, $data['names'])) { $data['names'][] = $name; }
            file_put_contents($cf, json_encode($data));
            $msg = "Confirmer '$name' added.";
        }
    }

    if ($action === 'remove_confirmer') {
        $name = trim($_POST['confirmer_name'] ?? '');
        $cf   = __DIR__ . '/../config/confirmers.json';
        if (file_exists($cf)) {
            $data = json_decode(file_get_contents($cf), true);
            $data['names'] = array_values(array_filter($data['names'], fn($n) => $n !== $name));
            file_put_contents($cf, json_encode($data));
            $msg = "Confirmer removed.";
        }
    }

    if ($action === 'add_announcement') {
        $title = trim($_POST['ann_title'] ?? '');
        $body  = trim($_POST['ann_body']  ?? '');
        $prio  = $_POST['ann_priority'] ?? 'medium';
        if ($title) {
            $pdo->query("UPDATE announcements SET is_active=0");
            $pdo->prepare("INSERT INTO announcements (title,body,priority,created_by) VALUES (?,?,?,?)")->execute([$title,$body,$prio,$user['id']]);
            $msg = "Announcement published.";
        }
    }

    if ($action === 'update_running_model') {
        $modelName = trim($_POST['model_name'] ?? '');
        $settingsFile = __DIR__ . '/../config/running_model.json';
        $settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
        if ($modelName !== '') $settings['name'] = $modelName;
        if (!empty($_FILES['model_image']['tmp_name'])) {
            $uploadDir = __DIR__ . '/../uploads/model/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['model_image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed)) {
                $filename = 'running_model_' . time() . '.' . $ext;
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                foreach (glob($uploadDir . 'running_model_*') as $old) { unlink($old); }
                move_uploaded_file($_FILES['model_image']['tmp_name'], $uploadDir . $filename);
                $settings['image'] = $filename; // store filename only; each page builds its own URL
            }
        }
        file_put_contents($settingsFile, json_encode($settings));
        $msg = "Running model updated.";
    }

    if ($action === 'reset_project') {
        $newCodeRaw  = trim($_POST['new_order_code'] ?? '');
        $newTarget   = (int)($_POST['new_target_pairs'] ?? 0);
        $newDeadline = $_POST['new_deadline'] ?? '';
        if ($newTarget > 0 && $newDeadline) {
            // Archive ALL current active orders (even if 0 rows, this is safe)
            $archiveStmt = $pdo->query("UPDATE production_orders SET status='archived' WHERE status='active'");
            $archivedCount = $archiveStmt->rowCount();
            // Clear announcements
            $pdo->query("UPDATE announcements SET is_active=0");
            // NOTE: inventory_materials and inventory_transactions are intentionally
            // NOT touched here — inventory data persists across project resets.
            // Make order_code unique — if user left blank or it clashes, append a timestamp suffix
            if (!$newCodeRaw) $newCodeRaw = 'ORD-' . date('Ymd');
            // Check for duplicate and add suffix if needed
            $exists = $pdo->prepare("SELECT COUNT(*) FROM production_orders WHERE order_code=?");
            $exists->execute([$newCodeRaw]);
            if ($exists->fetchColumn() > 0) {
                $newCodeRaw = $newCodeRaw . '-' . date('His');
            }
            $pdo->prepare("INSERT INTO production_orders (order_code, target_pairs, deadline, status, created_by) VALUES (?,?,?,'active',?)")
                ->execute([$newCodeRaw, $newTarget, $newDeadline, $user['id']]);
            $msg = "Project reset! New order '{$newCodeRaw}' is now active." . ($archivedCount > 0 ? " Previous order archived." : " (No previous active order found to archive.)");
        } else {
            // Validation failed — redirect back with error instead of silently doing nothing
            header("Location: dashboard.php?reset_error=1&page=archives"); exit;
        }
    }

    if ($action === 'delete_archive') {
        $aid = (int)($_POST['archive_id'] ?? 0);
        if ($aid) {
            $pdo->prepare("DELETE do FROM daily_outputs do JOIN order_sizes os ON os.id=do.order_size_id WHERE os.order_id=?")->execute([$aid]);
            $pdo->prepare("DELETE FROM daily_order_totals WHERE order_id=?")->execute([$aid]);
            $pdo->prepare("DELETE FROM order_sizes WHERE order_id=?")->execute([$aid]);
            $pdo->prepare("DELETE FROM production_orders WHERE id=? AND status='archived'")->execute([$aid]);
            $msg = "Archive deleted.";
        }
    }

    // ── STAGE MANAGEMENT ──────────────────────────────────────────────────────
    if ($action === 'add_stage') {
        $name = trim($_POST['stage_name'] ?? '');
        if ($name) {
            $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM stages")->fetchColumn();
            $pdo->prepare("INSERT INTO stages (name, sort_order, is_active) VALUES (?,?,1)")
                ->execute([$name, $maxSort + 1]);
            $msg = "Stage '$name' added.";
        }
    }

    if ($action === 'edit_stage') {
        $sid  = (int)($_POST['stage_id']   ?? 0);
        $name = trim($_POST['stage_name']  ?? '');
        $sort = (int)($_POST['sort_order'] ?? 0);
        if ($sid && $name) {
            $pdo->prepare("UPDATE stages SET name=?, sort_order=? WHERE id=?")
                ->execute([$name, $sort, $sid]);
            $msg = "Stage updated.";
        }
    }

    if ($action === 'toggle_stage') {
        $sid = (int)($_POST['stage_id'] ?? 0);
        if ($sid) {
            // Ensure is_active column exists
            try { $pdo->exec("ALTER TABLE stages ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1"); } catch(Exception $e){}
            $pdo->prepare("UPDATE stages SET is_active = 1 - COALESCE(is_active,1) WHERE id=?")->execute([$sid]);
            $msg = "Stage visibility updated.";
        }
    }

    if ($action === 'delete_stage') {
        $sid = (int)($_POST['stage_id'] ?? 0);
        if ($sid) {
            $pdo->prepare("DELETE FROM daily_outputs WHERE stage_id=?")->execute([$sid]);
            $pdo->prepare("DELETE FROM stages WHERE id=?")->execute([$sid]);
            $msg = "Stage deleted.";
        }
    }

    if ($action === 'reorder_stages') {
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (is_array($order)) {
            $stmt = $pdo->prepare("UPDATE stages SET sort_order=? WHERE id=?");
            foreach ($order as $pos => $sid) {
                $stmt->execute([$pos + 1, (int)$sid]);
            }
        }
        // Return JSON for AJAX call
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    // ── END STAGE MANAGEMENT ──────────────────────────────────────────────────

    if ($action === 'set_subtract_pin') {
        $newPin = trim($_POST['subtract_pin'] ?? '');
        if ($newPin && preg_match('/^\d{4}$/', $newPin)) {
            $pf = __DIR__ . '/../config/subtract_pin.json';
            file_put_contents($pf, json_encode(['pin_hash' => password_hash($newPin, PASSWORD_BCRYPT)]));
            $msg = "Subtract PIN updated successfully.";
        } else {
            $msg = "â ï¸ PIN must be exactly 4 digits (0-9).";
        }
    }

    // Map each action back to its page so the redirect returns to the same section
    $pageMap = [
        'add_user'             => 'users',
        'edit_user'            => 'users',
        'toggle_user'          => 'users',
        'delete_user'          => 'users',
        'add_output'           => 'outputs',
        'update_target'        => 'orders',
        'add_size'             => 'orders',
        'delete_size'          => 'orders',
        'edit_size'            => 'orders',
        'add_confirmer'        => 'confirmers',
        'remove_confirmer'     => 'confirmers',
        'set_subtract_pin'     => 'confirmers',
        'update_running_model' => 'dashboard',
        'add_announcement'     => 'announce',
        'reset_project'        => 'archives',
        'delete_archive'       => 'archives',
        'add_stage'            => 'stages',
        'edit_stage'           => 'stages',
        'toggle_stage'         => 'stages',
        'delete_stage'         => 'stages',
    ];
    $returnPage = $pageMap[$action] ?? 'dashboard';
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_type'] = (strpos($msg, '⚠️') !== false || strpos($msg, 'Error') !== false) ? 'error' : 'success';
    header("Location: dashboard.php?page=" . $returnPage); exit;
}

// Read and clear flash (won't reappear on refresh)
$msg = '';
$msgType = 'success';
if (!empty($_SESSION['flash_msg'])) {
    $msg     = $_SESSION['flash_msg'];
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}
if (isset($_GET['reset_error'])) { $msg = "⚠️ Reset failed: New Target Pairs is required and must be greater than 0."; $msgType = 'error'; }

// Load running model settings
$modelSettingsFile = __DIR__ . '/../config/running_model.json';
$modelSettings = file_exists($modelSettingsFile) ? json_decode(file_get_contents($modelSettingsFile), true) : [];
$modelName  = $modelSettings['name']  ?? '';
$modelImageFile = $modelSettings['image'] ?? ''; // just the filename
// Migrate old format (path stored instead of filename) — strip any directory prefix
if ($modelImageFile && str_contains($modelImageFile, '/')) {
    $modelImageFile = basename($modelImageFile);
    $modelSettings['image'] = $modelImageFile;
    file_put_contents($modelSettingsFile, json_encode($modelSettings));
}
// URL relative to admin/ folder
$modelImageUrl = $modelImageFile ? '../uploads/model/' . $modelImageFile : '';

// Load confirmers (team leaders)
$confirmersFile = __DIR__ . '/../config/confirmers.json';
$confirmersList = file_exists($confirmersFile) ? (json_decode(file_get_contents($confirmersFile), true)['names'] ?? []) : [];
// Load subtract PIN status
$subtractPinFile2 = __DIR__ . '/../config/subtract_pin.json';
$subtractPinIsSet = file_exists($subtractPinFile2) && !empty(json_decode(file_get_contents($subtractPinFile2), true)['pin_hash'] ?? '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Quilla — Admin Panel</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800;900&family=Barlow:wght@400;500;600;700&display=swap');

/* PRINT STYLES */
@media print {
  .sidebar,.btn-reset,.btn-action-bar,.reset-modal-bg,.stat-edit-btn { display:none!important; }
  .main { margin-left:0!important; padding:10px!important; }
  body { background:#fff!important; }
  .stat-grid { grid-template-columns: repeat(5,1fr)!important; }
  .panel { break-inside:avoid; }
  @page { margin:1.5cm; }
}
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#1a3a8f;--blue-dark:#122a6b;--blue-mid:#1e47b0;--blue-light:#2557d6;
  --gold:#f5c518;--gold2:#e8b500;
  --green:#22c55e;--red:#dc2626;--purple:#7c3aed;
  --bg:#0d1e52;--sidebar:#112068;
  --main:#eef2fb;--white:#fff;
  --border:#c8d5f0;--text:#0d1e52;--text2:#4a5b8a;
  --card-bg:#fff;
}
body{font-family:'Barlow',sans-serif;background:var(--main);color:var(--text);display:flex;min-height:100vh}

/* SIDEBAR */
.sidebar{
  width:248px;
  background:linear-gradient(175deg,var(--blue-dark) 0%,var(--bg) 100%);
  color:#fff;display:flex;flex-direction:column;min-height:100vh;
  position:fixed;left:0;top:0;z-index:50;
  border-right:3px solid #4479ee;
}
.sb-logo{
  padding:16px 20px;
  border-bottom:1px solid rgba(255,255,255,.1);
  background:#142e75;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
}
.sb-logo img{
  height:54px;width:auto;object-fit:contain;display:block;
}
.sb-logo-sub{
  font-size:9px;font-weight:700;letter-spacing:3px;
  color:rgba(255,255,255,.5);margin-top:3px;text-transform:uppercase;
}
.sb-role{
  display:inline-block;margin-top:8px;
  font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;
  background: #4479ee;color:var(--blue-dark);
  padding:2px 10px;border-radius:20px;
}
.sb-nav{flex:1;padding:14px 0}
.sb-section{
  font-size:9px;font-weight:700;letter-spacing:2px;
  color:rgba(255,255,255,.3);padding:16px 20px 6px;text-transform:uppercase;
}
.sb-link{
  display:flex;align-items:center;gap:10px;
  padding:11px 20px;color:rgba(255,255,255,.65);
  text-decoration:none;font-size:13px;font-weight:600;letter-spacing:.3px;
  transition:.2s;cursor:pointer;border:none;background:none;
  width:100%;text-align:left;border-left:3px solid transparent;
}
.sb-link:hover,.sb-link.active{
  background:rgba(255,255,255,.09);color:#fff;
  border-left:3px solid var(--gold);
  padding-left:22px;
}
.sb-link svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2;flex-shrink:0;opacity:.8}
.sb-bottom{padding:16px 20px;border-top:1px solid rgba(255,255,255,.1)}
.sb-user{font-size:13px;color:rgba(255,255,255,.55);margin-bottom:10px}
.sb-user strong{display:block;color:#fff;font-size:14px;font-weight:700}
.btn-logout{
  width:100%;padding:9px;
  background:rgba(255,255,255,.07);
  color:rgba(255,255,255,.7);
  border:1px solid rgba(255,255,255,.15);
  border-radius:6px;font-size:12px;font-weight:700;
  letter-spacing:.5px;text-transform:uppercase;
  cursor:pointer;transition:.2s;
}
.btn-logout:hover{background:var(--red);color:#fff;border-color:var(--red)}

/* MAIN */
.main{margin-left:248px;flex:1;padding:28px;min-height:100vh}
.page{display:none}
.page.active{display:block}
h1.page-title{
  font-family:'Barlow Condensed',sans-serif;
  font-size:26px;font-weight:900;letter-spacing:1px;text-transform:uppercase;
  color:var(--blue-dark);margin-bottom:22px;
  display:flex;align-items:center;gap:10px;
  padding-bottom:12px;border-bottom:3px solid var(--blue-light);
}

/* STAT CARDS */
.stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-bottom:28px}

/* RUNNING MODEL CARD */
.stat-model{
  background:var(--card-bg);border-radius:12px;padding:16px;
  border:1px solid var(--border);
  position:relative;overflow:hidden;
  box-shadow:0 2px 12px rgba(26,58,143,.08);
  transition:transform .2s,box-shadow .2s;
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;
}
.stat-model:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(26,58,143,.14)}
.stat-model::before{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:#102663;
}
.stat-model-label{
  font-size:11px;color:var(--text2);font-weight:700;text-transform:uppercase;letter-spacing:1px;text-align:center;margin-top:2px;
}
.stat-model-img-wrap{
  width:80px;height:80px;border-radius:10px;overflow:hidden;
  border:2px solid var(--border);background:#f0f4fc;
  display:flex;align-items:center;justify-content:center;cursor:pointer;position:relative;
}
.stat-model-img-wrap img{width:100%;height:100%;object-fit:cover}
.stat-model-img-wrap .model-overlay{
  display:none;position:absolute;inset:0;background:rgba(26,58,143,.55);
  align-items:center;justify-content:center;border-radius:8px;
  font-size:10px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.5px;text-align:center;
  flex-direction:column;gap:3px;
}
.stat-model-img-wrap:hover .model-overlay{display:flex}
.stat-model-upload-btn{
  font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;
  background:var(--blue);color:#fff;border:none;border-radius:5px;
  padding:4px 10px;cursor:pointer;font-family:'Barlow',sans-serif;transition:.2s;
  margin-top:2px;
}
.stat-model-upload-btn:hover{background:var(--blue-dark)}
.stat-model-name{
  font-size:12px;font-weight:800;color:var(--blue-dark);text-align:center;
  max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.stat{
  background:var(--card-bg);border-radius:12px;padding:20px;
  border:1px solid var(--border);
  position:relative;overflow:hidden;
  box-shadow:0 2px 12px rgba(26,58,143,.08);
  transition:transform .2s,box-shadow .2s;
}
.stat:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(26,58,143,.14)}
.stat::before{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:linear-gradient(90deg,var(--blue),var(--blue-light));
}
.stat-icon{
  width:42px;height:42px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  font-size:20px;margin-bottom:12px;
}
.stat-val{font-size:34px;font-weight:900;color:var(--blue-dark);line-height:1;font-family:'Barlow Condensed',sans-serif;}
.stat-label{font-size:11px;color:var(--text2);margin-top:4px;font-weight:700;text-transform:uppercase;letter-spacing:1px}
.stat-accent{position:absolute;right:0;top:0;bottom:0;width:4px;border-radius:0 12px 12px 0}

/* PANEL / TABLE */
.panel{
  background:var(--card-bg);border:1px solid var(--border);
  border-radius:12px;overflow:hidden;margin-bottom:24px;
  box-shadow:0 2px 10px rgba(26,58,143,.06);
}
.panel-head{
  padding:14px 20px;
  border-bottom:2px solid var(--blue);
  display:flex;align-items:center;justify-content:space-between;
  background:linear-gradient(90deg,var(--blue) 0%,var(--blue-mid) 100%);
}
.panel-title{font-size:13px;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:1px}

/* SCROLLABLE TABLE BODY — max 20 rows */
.table-scroll-wrap{
  overflow-x:auto;
  overflow-y:auto;
  max-height:calc(20 * 52px); /* ~20 rows */
}
.table-scroll-wrap::-webkit-scrollbar{width:6px;height:6px}
.table-scroll-wrap::-webkit-scrollbar-track{background:#f1f5f9;border-radius:4px}
.table-scroll-wrap::-webkit-scrollbar-thumb{background:#c8d5f0;border-radius:4px}
.table-scroll-wrap::-webkit-scrollbar-thumb:hover{background:#93a9d8}
.table-scroll-wrap table{width:100%;border-collapse:collapse}
.table-scroll-wrap thead th{position:sticky;top:0;z-index:2}

table{width:100%;border-collapse:collapse}
th{
  padding:10px 14px;text-align:left;font-size:11px;font-weight:700;
  color:var(--blue-dark);background:#e8eef8;
  text-transform:uppercase;letter-spacing:.8px;border-bottom:2px solid var(--border);
}
td{padding:11px 14px;font-size:13px;border-bottom:1px solid #edf1fb;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#f0f4fc}

/* BADGES */
.badge{display:inline-block;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:.3px}
.badge-admin{background:#1a3a8f;color:#fff}
.badge-supervisor{background:#dcfce7;color:#166534}
.badge-production{background:#dcfce7;color:#166534}
.badge-active{background:#dcfce7;color:#166534}
.badge-inactive{background:#fee2e2;color:#991b1b}
.badge-green{background:#dcfce7;color:#166534}
.badge-orange{background:#1a3a8f;color:#fff}

/* FORMS */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:20px}
.form-grid.col3{grid-template-columns:1fr 1fr 1fr}
.fg{display:flex;flex-direction:column;gap:6px}
.fg label{font-size:11px;font-weight:700;color:var(--blue-dark);text-transform:uppercase;letter-spacing:.8px}
.fg input,.fg select,.fg textarea{
  padding:9px 12px;border:1.5px solid var(--border);
  border-radius:6px;font-size:13px;outline:none;transition:.2s;
  font-family:'Barlow',sans-serif;
}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--blue-light);box-shadow:0 0 0 3px rgba(37,87,214,.12)}
.form-footer{padding:0 20px 20px;display:flex;justify-content:flex-end}
.btn{
  padding:10px 24px;border:none;border-radius:6px;
  font-size:12px;font-weight:800;letter-spacing:1px;text-transform:uppercase;
  cursor:pointer;transition:.2s;font-family:'Barlow',sans-serif;
}
.btn-primary{background:var(--blue);color:#fff}
.btn-primary:hover{background:var(--blue-dark);box-shadow:0 4px 12px rgba(26,58,143,.3)}
.btn-sm{padding:5px 12px;font-size:11px;border-radius:5px}
.btn-danger{background:#fee2e2;color:#dc2626}
.btn-danger:hover{background:#dc2626;color:#fff}
.btn-toggle{background:#dbeafe;color:#1e40af}
.btn-edit{background:#fef9c3;color:#854d0e;border:1px solid #fde68a}
.btn-edit:hover{background:#fde047;color:#713f12}

/* MSG */
/* ── TOAST NOTIFICATION (centered) ── */
.toast{
  position:fixed;
  top:50%;left:50%;
  transform:translate(-50%,-50%) scale(.85);
  z-index:9999;
  min-width:320px;max-width:460px;
  padding:28px 36px;
  border-radius:20px;
  font-size:18px;font-weight:800;
  text-align:center;
  box-shadow:0 20px 60px rgba(13,30,82,.28), 0 4px 20px rgba(0,0,0,.12);
  display:flex;flex-direction:column;align-items:center;gap:10px;
  opacity:0;
  animation:toastPop .35s cubic-bezier(.175,.885,.32,1.275) forwards;
  pointer-events:none;
}
.toast-title{font-size:20px;font-weight:900;letter-spacing:-.3px}
.toast-sub{font-size:13px;font-weight:600;opacity:.75;margin-top:2px}
.toast.success{background:#fff;border:3px solid #16a34a;color:#14532d;}
.toast.success .toast-icon-wrap{
  width:64px;height:64px;border-radius:50%;
  background:linear-gradient(135deg,#16a34a,#22c55e);
  display:flex;align-items:center;justify-content:center;
  font-size:32px;color:#fff;
  box-shadow:0 6px 20px rgba(22,163,74,.35);
  margin-bottom:6px;
}
.toast.error{background:#fff;border:3px solid #dc2626;color:#7f1d1d;}
.toast.error .toast-icon-wrap{
  width:64px;height:64px;border-radius:50%;
  background:linear-gradient(135deg,#dc2626,#ef4444);
  display:flex;align-items:center;justify-content:center;
  font-size:32px;color:#fff;
  box-shadow:0 6px 20px rgba(220,38,38,.35);
  margin-bottom:6px;
}
.toast-overlay{
  position:fixed;inset:0;
  background:rgba(13,30,82,.25);
  backdrop-filter:blur(3px);
  z-index:9998;opacity:0;
  animation:fadeInBg .3s ease forwards;
}
@keyframes toastPop{
  0%  {opacity:0;transform:translate(-50%,-50%) scale(.7)}
  100%{opacity:1;transform:translate(-50%,-50%) scale(1)}
}
@keyframes fadeInBg{from{opacity:0}to{opacity:1}}
@keyframes toastOut{
  0%  {opacity:1;transform:translate(-50%,-50%) scale(1)}
  100%{opacity:0;transform:translate(-50%,-50%) scale(.8)}
}

/* BAR CHART */
.bar-chart-wrap{padding:20px 24px 16px;width:100%}
.bar-chart-canvas{width:100%;display:block}

/* RESET BUTTON */
.btn-reset{
  background:linear-gradient(90deg,#dc2626,#b91c1c);color:#fff;
  padding:11px 22px;border:none;border-radius:8px;
  font-size:12px;font-weight:800;letter-spacing:1px;text-transform:uppercase;
  cursor:pointer;transition:.2s;font-family:'Barlow',sans-serif;
  display:inline-flex;align-items:center;gap:8px;
  box-shadow:0 4px 14px rgba(220,38,38,.3);
}
.btn-reset:hover{background:linear-gradient(90deg,#b91c1c,#991b1b);box-shadow:0 6px 20px rgba(220,38,38,.4);transform:translateY(-1px)}

/* RESET MODAL */
.reset-modal-bg{
  display:none;position:fixed;inset:0;
  background:rgba(13,30,82,.7);z-index:300;
  align-items:center;justify-content:center;backdrop-filter:blur(5px);
}
.reset-modal-bg.open{display:flex}
.reset-modal{
  background:#fff;border-radius:16px;padding:32px;width:440px;
  box-shadow:0 24px 60px rgba(13,30,82,.35);
  border-top:5px solid #dc2626;
}
.reset-modal h3{
  font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:900;
  color:#dc2626;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;
}
.reset-modal .rm-sub{font-size:13px;color:var(--text2);margin-bottom:20px;line-height:1.5}
.reset-modal .rm-warn{
  background:#fff5f5;border:1px solid #fecaca;border-radius:8px;
  padding:10px 14px;font-size:12px;color:#991b1b;font-weight:600;
  margin-bottom:20px;display:flex;align-items:flex-start;gap:8px;
}
.reset-modal label{
  display:block;font-size:11px;font-weight:700;color:var(--blue-dark);
  text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;margin-top:14px;
}
.reset-modal input{
  width:100%;border:1.5px solid var(--border);border-radius:6px;
  padding:9px 12px;font-size:13px;font-family:'Barlow',sans-serif;
  outline:none;transition:.2s;background:#f5f8ff;
}
.reset-modal input:focus{border-color:#dc2626;box-shadow:0 0 0 3px rgba(220,38,38,.12)}
.rm-btns{display:flex;gap:10px;margin-top:22px}
.rm-confirm{
  flex:1;padding:12px;background:#dc2626;color:#fff;border:none;
  border-radius:6px;font-weight:800;cursor:pointer;font-size:13px;
  font-family:'Barlow',sans-serif;text-transform:uppercase;letter-spacing:.5px;transition:.2s;
}
.rm-confirm:hover{background:#b91c1c}
.rm-cancel{
  flex:1;padding:12px;background:#e8eef8;color:var(--blue-dark);border:none;
  border-radius:6px;font-weight:700;cursor:pointer;font-family:'Barlow',sans-serif;transition:.2s;
}
.rm-cancel:hover{background:var(--border)}

/* PROGRESS */
.prog{background:#dbeafe;border-radius:20px;height:8px;overflow:hidden;margin-top:6px}
.prog-fill{height:100%;border-radius:20px;background:linear-gradient(var(--blue-light))}

/* EDIT INLINE BUTTON on stat cards */
.stat-edit-btn{
  position:absolute;top:10px;right:10px;
  background:rgba(26,58,143,.1);border:none;border-radius:5px;
  padding:3px 8px;font-size:10px;font-weight:700;color:var(--blue-dark);
  cursor:pointer;transition:.2s;letter-spacing:.3px;font-family:'Barlow',sans-serif;
  text-transform:uppercase;
}
.stat-edit-btn:hover{background:var(--blue);color:#fff}
.stat-editable{position:relative;transition:all .25s}
.stat-edit-form{padding:4px 0}
.stat-edit-form input:focus{border-color:var(--blue-light)!important;box-shadow:0 0 0 3px rgba(37,87,214,.12)!important}

/* EDIT TARGET MODAL */
.edit-target-modal-bg{
  display:none;position:fixed;inset:0;
  background:rgba(13,30,82,.7);z-index:300;
  align-items:center;justify-content:center;backdrop-filter:blur(5px);
}
.edit-target-modal-bg.open{display:flex}
.edit-target-modal{
  background:#fff;border-radius:16px;padding:32px;width:420px;
  box-shadow:0 24px 60px rgba(13,30,82,.35);
  border-top:5px solid var(--blue);
}
.edit-target-modal h3{
  font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:900;
  color:var(--blue-dark);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;
}
.edit-target-modal .rm-sub{font-size:13px;color:var(--text2);margin-bottom:20px;line-height:1.5}
.edit-target-modal label{
  display:block;font-size:11px;font-weight:700;color:var(--blue-dark);
  text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;margin-top:14px;
}
.edit-target-modal input{
  width:100%;border:1.5px solid var(--border);border-radius:6px;
  padding:9px 12px;font-size:13px;font-family:'Barlow',sans-serif;
  outline:none;transition:.2s;background:#f5f8ff;
}
.edit-target-modal input:focus{border-color:var(--blue-light);box-shadow:0 0 0 3px rgba(37,87,214,.12)}

/* =============================================
   RESPONSIVE — ALL SCREEN SIZES
   ============================================= */

/* Large desktops 1400px+ */
@media (min-width:1400px) {
  .main { padding:32px 36px; }
  .stat-grid { grid-template-columns:repeat(5,1fr); }
}

/* Standard desktops 1200–1399px */
@media (max-width:1399px) {
  .stat-grid { grid-template-columns:repeat(4,1fr); }
}

/* Small desktops / large laptops 1024–1199px */
@media (max-width:1199px) {
  .sidebar { width:220px; }
  .main { margin-left:220px; padding:22px; }
  .stat-grid { grid-template-columns:repeat(3,1fr); }
  .form-grid.col3 { grid-template-columns:1fr 1fr; }
}

/* Tablets landscape / small laptops 900–1023px */
@media (max-width:1023px) {
  .sidebar { width:200px; }
  .main { margin-left:200px; padding:18px; }
  .stat-grid { grid-template-columns:repeat(2,1fr); }
  .form-grid { grid-template-columns:1fr; }
  .form-grid.col3 { grid-template-columns:1fr; }
  .table-scroll-wrap { overflow-x:auto; }
  table { min-width:560px; }
  .dash-action-row { flex-wrap:wrap; gap:8px; }
  .btn-action-bar { font-size:11px; padding:8px 14px; }
}

/* ── MOBILE SIDEBAR → DRAWER (≤899px) ── */

/* Hide desktop sidebar, show mobile top bar */
.mobile-topbar {
  display:none;
  position:sticky;top:0;z-index:90;
  background:linear-gradient(90deg,var(--blue-dark),var(--blue-mid));
  padding:0 14px;height:54px;
  align-items:center;justify-content:space-between;
  border-bottom:3px solid var(--gold);
  box-shadow:0 2px 14px rgba(13,30,82,.25);
}
.mobile-topbar-logo img { height:32px;width:auto;object-fit:contain; }
.mobile-hamburger {
  background:transparent;border:none;cursor:pointer;
  padding:8px;color:#fff;display:flex;align-items:center;
}
/* Drawer overlay */
.sb-overlay {
  display:none;position:fixed;inset:0;
  background:rgba(10,20,60,.55);z-index:95;
  backdrop-filter:blur(3px);
}
.sb-overlay.open { display:block; }
/* Drawer itself */
.sb-drawer {
  position:fixed;left:0;top:0;bottom:0;
  width:260px;z-index:96;
  background:linear-gradient(175deg,var(--blue-dark) 0%,var(--bg) 100%);
  border-right:3px solid #4479ee;
  display:flex;flex-direction:column;
  transform:translateX(-100%);
  transition:transform .28s cubic-bezier(.4,0,.2,1);
}
.sb-drawer.open { transform:translateX(0); }
.sb-drawer-close {
  position:absolute;top:12px;right:12px;
  background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);
  color:#fff;font-size:18px;width:30px;height:30px;border-radius:50%;
  cursor:pointer;display:flex;align-items:center;justify-content:center;
}

@media (max-width:899px) {
  body { display:block; }
  .sidebar { display:none; }
  .mobile-topbar { display:flex; }
  .main { margin-left:0; padding:16px; }
  .stat-grid { grid-template-columns:repeat(2,1fr); gap:12px; }
  h1.page-title { font-size:20px; }
  .table-scroll-wrap { overflow-x:auto; }
  table { min-width:500px; }
}

/* Mobile 640–767px */
@media (max-width:767px) {
  .stat-grid { grid-template-columns:1fr 1fr; gap:10px; }
  .stat { padding:14px; }
  .stat-val { font-size:26px; }
  .reset-modal { width:95vw; padding:22px 16px; }
  .edit-target-modal { width:95vw; padding:22px 16px; }
  .panel-head { padding:10px 14px; flex-wrap:wrap; gap:6px; }
  .panel-title { font-size:11px; }
  th { padding:8px 10px; font-size:10px; }
  td { padding:8px 10px; font-size:12px; }
  .form-grid { padding:14px; gap:12px; }
  .form-footer { padding:0 14px 14px; }
  .btn { padding:9px 18px; font-size:11px; }
  .dash-action-row .btn-action-bar { flex:1 1 calc(50% - 8px); justify-content:center; }
}

/* Small mobile up to 639px */
@media (max-width:639px) {
  .stat-grid { grid-template-columns:1fr 1fr; }
  .stat-val { font-size:22px; }
  .main { padding:10px; }
  h1.page-title { font-size:16px; margin-bottom:12px; }
  .dash-action-row .btn-action-bar { flex:1 1 calc(50% - 8px); justify-content:center; }
  .reset-modal, .edit-target-modal { width:100vw; border-radius:14px 14px 0 0; padding:20px 14px 28px; }
  .reset-modal-bg.open, .edit-target-modal-bg.open { align-items:flex-end; }
  table th, table td { font-size:11px; padding:7px 8px; }
  .panel { border-radius:8px; }
}

/* Very small phones ≤390px */
@media (max-width:390px) {
  .stat-grid { grid-template-columns:1fr; }
  .main { padding:8px; }
  h1.page-title { font-size:15px; }
}

</style>
</head>
<body>

<!-- MOBILE TOP BAR (visible only ≤899px) -->
<div class="mobile-topbar">
  <div class="mobile-topbar-logo"><img src="../assets/quilla_logo.jpg" alt="Quilla"></div>
  <button class="mobile-hamburger" onclick="openSbDrawer()" aria-label="Open menu">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
      <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>
</div>

<!-- MOBILE DRAWER OVERLAY -->
<div class="sb-overlay" id="sb-overlay" onclick="closeSbDrawer()"></div>

<!-- MOBILE DRAWER (cloned sidebar) -->
<div class="sb-drawer" id="sb-drawer">
  <button class="sb-drawer-close" onclick="closeSbDrawer()">✕</button>
  <div class="sb-logo" style="padding-top:18px">
    <img src="../assets/quilla_logo.jpg" alt="Quilla">
    <div class="sb-role">Admin Panel</div>
  </div>
  <nav class="sb-nav" style="flex-direction:column;overflow-y:auto;flex:1">
    <div class="sb-section">Main</div>
    <button class="sb-link" onclick="showPage('dashboard',this);closeSbDrawer()">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      <span>Dashboard</span>
    </button>
    <button class="sb-link" onclick="showPage('outputs',this);closeSbDrawer()">
      <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      <span>Output History</span>
    </button>
    <button class="sb-link" onclick="showPage('orders',this);closeSbDrawer()">
      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      <span>Orders &amp; Sizes</span>
    </button>
    <div class="sb-section">Management</div>
    <button class="sb-link" onclick="showPage('users',this);closeSbDrawer()">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
      <span>Users</span>
    </button>
    <button class="sb-link" onclick="showPage('announce',this);closeSbDrawer()">
      <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
      <span>Announcements</span>
    </button>
    <button class="sb-link" onclick="showPage('confirmers',this);closeSbDrawer()">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
      <span>Team Leaders</span>
    </button>
    <button class="sb-link" onclick="showPage('stages',this);closeSbDrawer()">
      <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/><path d="M5 3h14M5 21h14" stroke-linecap="round"/></svg>
      <span>Production Process</span>
    </button>
    <a class="sb-link" href="../inventory.php">
      <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg>
      <span>Inventory</span>
    </a>
    <div class="sb-section">View</div>
    <button class="sb-link" onclick="showPage('archives',this);closeSbDrawer()">
      <svg viewBox="0 0 24 24"><path d="M21 8v13H3V8"/><rect x="1" y="3" width="22" height="5"/><path d="M10 12h4"/></svg>
      <span>Archives</span>
    </button>
    <a class="sb-link" href="../index.php" target="_blank">
      <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
      <span>Production Panel</span>
    </a>
  </nav>
  <div class="sb-bottom">
    <div class="sb-user"><strong><?= htmlspecialchars($user['full_name']) ?></strong><?= htmlspecialchars($user['role']) ?></div>
    <form method="POST" action="../logout.php"><button class="btn-logout" type="submit">Sign Out</button></form>
  </div>
</div>

<!-- SIDEBAR -->
<div class="sidebar">
  <div class="sb-logo">
    <img src="../assets/quilla_logo.jpg" alt="Quilla">
    <div class="sb-role">Admin Panel</div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Main</div>
    <button class="sb-link active" onclick="showPage('dashboard',this)">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </button>
    <button class="sb-link" onclick="showPage('outputs',this)">
      <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      Output History
    </button>
    <button class="sb-link" onclick="showPage('orders',this)">
      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Orders & Sizes
    </button>
    <div class="sb-section">Management</div>
    <button class="sb-link" onclick="showPage('users',this)">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
      Users
    </button>
    <button class="sb-link" onclick="showPage('announce',this)">
      <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
      Announcements
    </button>
    <button class="sb-link" onclick="showPage('confirmers',this)">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
      Team Leaders
    </button>
    <button class="sb-link" onclick="showPage('stages',this)">
      <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/><path d="M5 3h14M5 21h14" stroke-linecap="round"/></svg>
      Production Process
    </button>
    <a class="sb-link" href="../inventory.php">
      <svg viewBox="0 0 24 24" style="opacity:1"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/><line x1="12" y1="12" x2="12" y2="12.01"/></svg>
       Inventory
    </a>
    <div class="sb-section">View</div>
    <button class="sb-link" onclick="showPage('archives',this)">
      <svg viewBox="0 0 24 24"><path d="M21 8v13H3V8"/><rect x="1" y="3" width="22" height="5"/><path d="M10 12h4"/></svg>
      Archives
    </button>
    <a class="sb-link" href="../index.php" target="_blank">
      <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
      Production Floor View
    </a>
  </nav>
  <div class="sb-bottom">
    <div class="sb-user"><strong><?= htmlspecialchars($user['full_name']) ?></strong><?= $user['username'] ?></div>
    <button class="btn-logout" onclick="location.href='../logout.php'"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
  stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
  <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
  <polyline points="16 17 21 12 16 7" />
  <line x1="21" y1="12" x2="9" y2="12" />
</svg>Sign Out</button>
  </div>
</div>

<!-- MAIN CONTENT -->
<div class="main">
  <?php if($msg):
    $isError = ($msgType === 'error');
    $icon    = $isError ? '✕' : '✓';
    $title   = $isError ? 'May Mali!' : 'Na-save na!';
    $sub     = htmlspecialchars($msg);
  ?>
  <div class="toast-overlay" id="toastOverlay"></div>
  <div class="toast <?= $isError ? 'error' : 'success' ?>" id="toastBox">
    <div class="<?= $isError ? 'error' : 'success' ?> toast-icon-wrap"><?= $icon ?></div>
    <div class="toast-title"><?= $title ?></div>
    <div class="toast-sub"><?= $sub ?></div>
  </div>
  <?php endif; ?>

  <!-- ===== DASHBOARD ===== -->
  <div id="page-dashboard" class="page active">
    <h1 class="page-title" style="justify-content:space-between">
      <span><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
  stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
  <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
  <circle cx="9" cy="7" r="4" />
  <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
  <path d="M16 3.13a4 4 0 0 1 0 7.75" />
</svg> Admin Dashboard</span>
      <button class="btn-reset" onclick="document.getElementById('resetModal').classList.add('open')">
        🔄 New Project Reset
      </button>
    </h1>

    <!-- ACTION BAR: Save DOC / Print -->
    <div class="dash-action-row">
      <button class="btn-action-bar btn-save-doc" onclick="saveDashboardAsDoc()">
        📝 Save as DOC
      </button>
      <button class="btn-action-bar btn-print" onclick="printDashboard()">
        🖨️ Print
      </button>
      <span style="font-size:11px;color:var(--text2);font-weight:600">Export current dashboard snapshot</span>
    </div>

    <div class="stat-grid" id="dash-stat-grid" data-target="<?= (int)($activeOrder['target_pairs'] ?? 0) ?>">
    <div class="stat stat-editable" id="cardTargetPairs">
        <!-- VIEW MODE only — edit opens shared modal -->
        <div class="stat-view" id="viewTargetPairs">
          <button class="stat-edit-btn" onclick="openOrderEditModal()" title="Edit Target Pairs & Deadline"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
  stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
  <path d="M12 20h9" />
  <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
</svg> Edit</button>
          <div class="stat-icon" style="background:#ffffff"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
<path fill-rule="evenodd" clip-rule="evenodd" d="M10.6503 2.83047C10.8634 2.69217 11.1321 2.67115 11.3641 2.77462L17.8933 5.68625C18.4409 5.93042 18.8975 6.13403 19.234 6.32093C19.5523 6.49766 19.9316 6.74481 20.1199 7.15033C20.3593 7.66557 20.3336 8.26491 20.0511 8.75779C19.8287 9.14572 19.4297 9.35953 19.0975 9.5084C18.7462 9.66584 18.2739 9.82966 17.7074 10.0261L11.8086 12.0721V17.3089C11.8086 17.7231 11.4728 18.0589 11.0586 18.0589C10.6444 18.0589 10.3086 17.7231 10.3086 17.3089L10.3086 3.4596C10.3086 3.20555 10.4372 2.96877 10.6503 2.83047ZM11.8086 4.61524V10.4844L17.1816 8.62082C17.7912 8.4094 18.2003 8.26677 18.4841 8.13958C18.6699 8.05631 18.7414 8.00431 18.761 7.9896C18.787 7.93147 18.7899 7.86554 18.7689 7.8054C18.7506 7.78906 18.6838 7.73114 18.5058 7.63229C18.2339 7.4813 17.8385 7.30421 17.2493 7.04144L11.8086 4.61524ZM8.40036 13.9428C8.47467 14.3503 8.20457 14.7409 7.79708 14.8152C6.40539 15.069 5.27467 15.4724 4.51364 15.941C3.7229 16.4279 3.5 16.87 3.5 17.1617C3.5 17.3602 3.59577 17.6218 3.92277 17.9362C4.25182 18.2525 4.76752 18.5719 5.46631 18.8561C6.86009 19.4228 8.83724 19.7904 11.0596 19.7904C13.2819 19.7904 15.2591 19.4228 16.6529 18.8561C17.3516 18.5719 17.8673 18.2525 18.1964 17.9362C18.5234 17.6218 18.6192 17.3602 18.6192 17.1617C18.6192 16.87 18.3963 16.4279 17.6055 15.941C16.8445 15.4724 15.7138 15.069 14.3221 14.8152C13.9146 14.7409 13.6445 14.3503 13.7188 13.9428C13.7931 13.5353 14.1837 13.2653 14.5912 13.3396C16.0951 13.6138 17.4207 14.0656 18.392 14.6637C19.3336 15.2435 20.1192 16.0795 20.1192 17.1617C20.1192 17.8963 19.7499 18.5235 19.2359 19.0176C18.724 19.5097 18.0228 19.9183 17.2178 20.2456C15.6041 20.9017 13.4265 21.2904 11.0596 21.2904C8.69267 21.2904 6.51503 20.9017 4.90133 20.2456C4.09638 19.9183 3.39517 19.5097 2.88324 19.0176C2.36927 18.5235 2 17.8963 2 17.1617C2 16.0795 2.78555 15.2435 3.72717 14.6637C4.6985 14.0656 6.02404 13.6138 7.52798 13.3396C7.93547 13.2653 8.32605 13.5353 8.40036 13.9428Z" fill="black"/>
</svg>
</div>
          <div class="stat-val" id="dash-target-val"><?= number_format($activeOrder['target_pairs'] ?? 0) ?></div>
          <div class="stat-label">Target Pairs</div>
          <?php
            $tp  = (int)($activeOrder['target_pairs'] ?? 0);
            $pct = $tp > 0 ? min(100, round($totalCompleted / $tp * 100)) : 0;
          ?>
          <div class="prog" style="margin-top:10px">
            <div class="prog-fill" id="dash-target-prog-fill" style="width:<?= $pct ?>%"></div>
          </div>
          <div style="font-size:11px;color:var(--text2);margin-top:5px;font-weight:700" id="dash-target-prog-text">
            <?= number_format($totalCompleted) ?> / <?= number_format($tp) ?> &nbsp;·&nbsp;
            <span id="dash-target-pct" style="color:<?= $pct >= 100 ? 'var(--green)' : 'var(--blue-light)' ?>;font-weight:800"><?= $pct ?>%</span>
          </div>
          <div class="stat-accent" style="background:#f97316"></div>
        </div>
      </div>
      <div class="stat">
        <div class="stat-icon" style="background:#ffffff"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
<path fill-rule="evenodd" clip-rule="evenodd" d="M8.9249 2.1861C9.23634 2.45918 9.26743 2.93304 8.99434 3.24448L7.96855 4.41433H16.031L15.0052 3.24448C14.7322 2.93304 14.7633 2.45918 15.0747 2.1861C15.3861 1.91301 15.86 1.9441 16.1331 2.25554L18.026 4.41433H19.1477C20.5488 4.41433 21.6846 5.55017 21.6846 6.9513C21.6846 7.73256 21.3315 8.43133 20.7761 8.89671L19.7772 15.8909C19.6417 16.8397 19.534 17.5941 19.3994 18.2009C19.2613 18.8233 19.0843 19.3452 18.7825 19.8154C18.2904 20.5823 17.5881 21.1914 16.7593 21.5702C16.2512 21.8024 15.7094 21.9039 15.0738 21.9526C14.454 22 13.6921 22 12.7337 22H11.2659C10.3075 22 9.54555 22 8.9258 21.9526C8.29016 21.9039 7.7484 21.8024 7.24026 21.5702C6.41151 21.1914 5.70919 20.5823 5.21706 19.8154C4.91532 19.3452 4.73825 18.8233 4.60021 18.2009C4.46562 17.5941 4.35789 16.8397 4.22239 15.891L3.22345 8.89671C2.66808 8.43133 2.31494 7.73256 2.31494 6.9513C2.31494 5.55017 3.45078 4.41433 4.85191 4.41433H5.97357L7.86651 2.25554C8.1396 1.9441 8.61346 1.91301 8.9249 2.1861ZM6.30514 5.91433H4.85191C4.27921 5.91433 3.81494 6.3786 3.81494 6.9513C3.81494 7.5237 4.27871 7.98777 4.85099 7.98827C4.8513 7.98827 4.85069 7.98827 4.85099 7.98827L19.1477 7.98827C19.7204 7.98827 20.1846 7.524 20.1846 6.9513C20.1846 6.3786 19.7204 5.91433 19.1477 5.91433H17.6945C17.6891 5.91439 17.6838 5.91439 17.6785 5.91433H6.32104C6.31574 5.91439 6.31044 5.91439 6.30514 5.91433ZM4.82316 9.48827L5.70303 15.6489C5.84379 16.6344 5.94392 17.3319 6.06463 17.8761C6.1833 18.4111 6.31094 18.7427 6.47947 19.0053C6.81619 19.53 7.29673 19.9468 7.86377 20.2059C8.14757 20.3356 8.49384 20.4151 9.04029 20.457C9.5961 20.4995 10.3007 20.5 11.2963 20.5H12.7033C13.6989 20.5 14.4035 20.4995 14.9593 20.457C15.5058 20.4151 15.852 20.3356 16.1358 20.2059C16.7029 19.9468 17.1834 19.53 17.5201 19.0053C17.6887 18.7427 17.8163 18.4111 17.935 17.8761C18.0557 17.3319 18.1558 16.6344 18.2966 15.6489L19.1764 9.48827L4.85191 9.48827C4.85143 9.48827 4.85096 9.48827 4.85048 9.48827H4.82316ZM14.5301 13.1305C14.823 13.4234 14.823 13.8982 14.5301 14.1911L11.8635 16.8578C11.5706 17.1507 11.0957 17.1507 10.8028 16.8578L9.46947 15.5245C9.17658 15.2316 9.17658 14.7567 9.46947 14.4638C9.76236 14.1709 10.2372 14.1709 10.5301 14.4638L11.3331 15.2668L13.4695 13.1305C13.7624 12.8376 14.2372 12.8376 14.5301 13.1305Z" fill="black"/>
</svg>
</div>
        <div class="stat-val" id="dash-total-completed"><?= number_format($totalCompleted) ?></div>
        <div class="stat-label">Pairs Completed</div>
        <div class="stat-accent" style="background:var(--green)"></div>
        <div class="prog" style="margin-top:8px"><div class="prog-fill" id="dash-completed-prog-fill" style="width:<?= min(100,round($totalCompleted/max(1,$activeOrder['target_pairs']??1)*100)) ?>%"></div></div>
        <div id="dash-completed-pct-text" style="font-size:11px;color:var(--text2);margin-top:4px"><?= round($totalCompleted/max(1,$activeOrder['target_pairs']??1)*100) ?>% of target</div>
      </div>

      <!-- RUNNING MODEL CARD -->
      <div class="stat stat-model">
        <div style="font-size:11px;font-weight:800;color:var(--text2);text-transform:uppercase;letter-spacing:1px;text-align:center;margin-bottom:4px">👟 Running Model</div>
        <div class="stat-model-img-wrap" onclick="document.getElementById('modelFileInput').click()" title="Click to change model image">
          <?php if($modelImageUrl): ?>
            <img src="<?= htmlspecialchars($modelImageUrl) ?>?v=<?= time() ?>" alt="Running Model" id="modelPreviewImg">
          <?php else: ?>
            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='80' viewBox='0 0 80 80'%3E%3Crect width='80' height='80' fill='%23eef2fb'/%3E%3Ctext x='40' y='46' text-anchor='middle' font-size='32' font-family='sans-serif'%3E👟%3C/text%3E%3C/svg%3E" alt="No image" id="modelPreviewImg">
          <?php endif; ?>
          <div class="model-overlay">📷<br>Change</div>
        </div>
        <div class="stat-model-name" id="modelNameDisplay"><?= $modelName ? htmlspecialchars($modelName) : 'No model set' ?></div>
        <form method="POST" enctype="multipart/form-data" id="modelForm" action="dashboard.php">
          <input type="hidden" name="action" value="update_running_model">
          <input type="file" name="model_image" id="modelFileInput" accept="image/*" style="display:none" onchange="previewModel(this)">
          <input type="text" name="model_name" id="modelNameInput" placeholder="Model name…"
            value="<?= htmlspecialchars($modelName) ?>"
            style="border:1.5px solid var(--border);border-radius:5px;padding:4px 8px;font-size:11px;width:100%;text-align:center;font-family:'Barlow',sans-serif;outline:none;margin-top:2px"
            oninput="document.getElementById('modelSaveBtn').style.display='inline-block'">
          <button type="submit" id="modelSaveBtn" class="stat-model-upload-btn" style="display:none;margin-top:6px;width:100%">💾 Save</button>
        </form>
      </div>

      <div class="stat">
        <div class="stat-icon" style="background:#ede9fe">👥</div>
        <div class="stat-val"><?= $totalUsers ?></div>
        <div class="stat-label">Active Users</div>
        <div class="stat-accent" style="background:var(--purple)"></div>
      </div>
      <div class="stat stat-editable" id="cardDeadline">
        <!-- VIEW MODE -->
        <div class="stat-view" id="viewDeadline">
          <button class="stat-edit-btn" onclick="openOrderEditModal()" title="Edit Deadline & Target"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
  stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
  <path d="M12 20h9" />
  <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
</svg> Edit</button>
          <div class="stat-icon" style="background:#fef3c7">📅</div>
          <?php
            $daysLeft = $activeOrder ? countWorkingDaysUntilDeadline($activeOrder['deadline']) : 0;
            $deadlineColor = $daysLeft <= 3 ? '#dc2626' : ($daysLeft <= 7 ? '#f59e0b' : 'var(--blue-dark)');
          ?>
          <div class="stat-val" id="dash-deadline-days" style="color:<?= $deadlineColor ?>"><?= $daysLeft ?></div>
          <div class="stat-label">Days to Deadline</div>
          <div class="stat-accent" style="background:#f59e0b"></div>
          <div style="font-size:11px;color:var(--text2);margin-top:5px;font-weight:600" id="dash-deadline-date">
            <?= $activeOrder ? date('M j, Y', strtotime($activeOrder['deadline'])) : '—' ?>
          </div>
          <?php if($daysLeft <= 3 && $activeOrder): ?>
          <div style="font-size:10px;font-weight:800;color:#dc2626;margin-top:4px;text-transform:uppercase;letter-spacing:.5px" id="dash-deadline-warn">⚠️ Deadline soon!</div>
          <?php else: ?>
          <div id="dash-deadline-warn" style="display:none"></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Today by Stage -->

    <div class="panel">
      <div class="panel-head"><div class="panel-title">📦 Today's Output by Stage</div></div>
      <table>
        <tr><?php foreach($todayStage as $ts): ?><th style="text-align:center"><?= htmlspecialchars($ts['name']) ?></th><?php endforeach; ?></tr>
        <tr id="today-stage-row"><?php foreach($todayStage as $ts):
          $tsColor = ($ts['total'] > 0) ? '#166534' : '#9ca3af';
        ?><td id="today-stage-<?= $ts['id'] ?>" style="text-align:center;font-size:18px;font-weight:800;color:<?= $tsColor ?>"><?= $ts['total'] ?></td><?php endforeach; ?></tr>
      </table>
    </div>

    <!-- Consistency Bar Chart — Individual chart per Stage -->
    <div class="panel">
      <div class="panel-head"><div class="panel-title">📊 Output Consistency — Daily per Stage (Last 14 Days)</div></div>
      <?php
        $dashBarLabels = array_map(fn($c) => date('d/m', strtotime($c['log_date'])), $consistency);
        $stageColors   = ['#2563eb','#16a34a','#f59e0b','#dc2626','#7c3aed','#0891b2','#db2777','#65a30d'];
        $scStages      = array_values($stageConsistency);
      ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;padding:16px 20px">
        <?php
          // Re-key so we keep stage DB id alongside array index
          $scStagesKeyed = [];
          foreach(array_keys($stageConsistency) as $scStageId) {
            $scStagesKeyed[] = array_merge($stageConsistency[$scStageId], ['stage_db_id' => $scStageId]);
          }
        ?>
        <?php foreach($scStagesKeyed as $si => $sc):
          $color   = $stageColors[$si % count($stageColors)];
          $maxVal  = max(array_merge($sc['data'], [1]));
          $total   = array_sum($sc['data']);
          $canvasId = 'stage-chart-' . $si;
        ?>
        <div style="background:#f8faff;border:1.5px solid var(--border);border-radius:12px;
                    padding:14px;border-top:4px solid <?= $color ?>">
          <!-- Stage title + total -->
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <div style="font-size:12px;font-weight:800;color:var(--blue-dark);text-transform:uppercase;
                        letter-spacing:.8px">
              <?= htmlspecialchars($sc['name']) ?>
            </div>
            <div id="stage-chart-total-<?= (int)$sc['stage_db_id'] ?>"
                 style="background:<?= $color ?>;color:#fff;font-size:11px;font-weight:800;
                        padding:3px 10px;border-radius:20px">
              <?= number_format($total) ?> total
            </div>
          </div>
          <canvas id="<?= $canvasId ?>" height="120"
            style="width:100%;display:block"
            data-stage-id="<?= (int)$sc['stage_db_id'] ?>"
            data-vals="<?= htmlspecialchars(json_encode($sc['data'])) ?>"
            data-labels="<?= htmlspecialchars(json_encode(array_values($dashBarLabels))) ?>"
            data-color="<?= $color ?>"
            data-maxv="<?= $maxVal ?>">
          </canvas>
        </div>
        <?php endforeach; ?>
      </div>

      <script>
      // Named so the polling loop can call drawStageCharts() to redraw after data updates
      function drawStageCharts() {
        document.querySelectorAll('canvas[id^="stage-chart-"]').forEach(function(canvas) {
          var vals   = JSON.parse(canvas.getAttribute('data-vals'));
          var labels = JSON.parse(canvas.getAttribute('data-labels'));
          var color  = canvas.getAttribute('data-color');
          var maxV   = parseFloat(canvas.getAttribute('data-maxv')) || 1;

          var dpr = window.devicePixelRatio || 1;
          var W   = canvas.parentNode.offsetWidth - 28;
          var H   = 120;
          canvas.width  = W * dpr;
          canvas.height = H * dpr;
          canvas.style.width  = W + 'px';
          canvas.style.height = H + 'px';

          var ctx    = canvas.getContext('2d');
          ctx.scale(dpr, dpr);

          var n      = vals.length || 1;
          var padL   = 28, padR = 6, padT = 14, padB = 28;
          var chartW = W - padL - padR;
          var chartH = H - padT - padB;
          var barW   = chartW / n;
          var gap    = barW * 0.25;
          var bw     = barW - gap;

          // Grid lines (2 only — clean look)
          [0.5, 1].forEach(function(ratio) {
            var gy = padT + chartH - ratio * chartH;
            ctx.setLineDash([3, 3]);
            ctx.strokeStyle = '#dde4f0';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(padL, gy);
            ctx.lineTo(padL + chartW, gy);
            ctx.stroke();
            ctx.setLineDash([]);
            ctx.fillStyle = '#b0bcd4';
            ctx.font = '9px Barlow,sans-serif';
            ctx.textAlign = 'right';
            ctx.fillText(Math.round(ratio * maxV), padL - 3, gy + 3);
          });

          // Bars
          vals.forEach(function(v, i) {
            var barH = Math.max(v > 0 ? 3 : 0, (v / maxV) * chartH);
            var x    = padL + i * barW + gap / 2;
            var y    = padT + chartH - barH;

            // Bar
            ctx.fillStyle = color;
            ctx.globalAlpha = v > 0 ? 0.85 : 0.12;
            ctx.beginPath();
            if (ctx.roundRect) {
              ctx.roundRect(x, y, bw, barH || chartH, v > 0 ? [3,3,0,0] : [3,3,0,0]);
            } else {
              ctx.rect(x, y, bw, Math.max(barH, 2));
            }
            ctx.fill();
            ctx.globalAlpha = 1;

            // Value on top
            if (v > 0 && barH > 12) {
              ctx.fillStyle = color;
              ctx.font = 'bold 9px Barlow,sans-serif';
              ctx.textAlign = 'center';
              ctx.fillText(v, x + bw / 2, y - 2);
            }

            // Date label (show every 2nd label if many days)
            if (n <= 7 || i % 2 === 0) {
              ctx.fillStyle = '#6b7fa8';
              ctx.font = '8px Barlow,sans-serif';
              ctx.textAlign = 'center';
              ctx.fillText(labels[i], x + bw / 2, H - padB + 11);
            }
          });

          // Baseline
          ctx.strokeStyle = '#c8d5f0';
          ctx.lineWidth = 1.5;
          ctx.beginPath();
          ctx.moveTo(padL, padT + chartH);
          ctx.lineTo(padL + chartW, padT + chartH);
          ctx.stroke();
        });
      }
      drawStageCharts();
      </script>
    </div>

    <!-- INVENTORY SUMMARY PANEL -->
    <?php if ($invTotalMats > 0 || $invLowStock > 0 || $invOutOfStock > 0): ?>
    <div class="panel" style="margin-bottom:24px">
      <div class="panel-head" style="background:linear-gradient(90deg,#065f46,#059669)">
        <div class="panel-title">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg>
          📦 Inventory Overview
        </div>
        <a href="../inventory.php" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff;text-decoration:none;padding:5px 14px;border-radius:6px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;display:inline-flex;align-items:center;gap:6px">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
          Manage Inventory
        </a>
      </div>
      <div style="padding:18px 20px">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:<?php echo !empty($invLowItems) ? '20px' : '0'; ?>">
          <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;padding:14px 16px;text-align:center">
            <div style="font-size:28px;font-weight:900;color:#15803d;font-family:'Barlow Condensed',sans-serif"><?php echo $invTotalMats; ?></div>
            <div style="font-size:11px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:.8px;margin-top:3px">Total Materials</div>
          </div>
          <div style="background:<?php echo $invLowStock > 0 ? '#fffbeb' : '#f0fdf4'; ?>;border:1.5px solid <?php echo $invLowStock > 0 ? '#fde68a' : '#bbf7d0'; ?>;border-radius:10px;padding:14px 16px;text-align:center">
            <div style="font-size:28px;font-weight:900;color:<?php echo $invLowStock > 0 ? '#92400e' : '#15803d'; ?>;font-family:'Barlow Condensed',sans-serif"><?php echo $invLowStock; ?></div>
            <div style="font-size:11px;font-weight:700;color:<?php echo $invLowStock > 0 ? '#92400e' : '#166534'; ?>;text-transform:uppercase;letter-spacing:.8px;margin-top:3px">⚠️ Low Stock</div>
          </div>
          <div style="background:<?php echo $invOutOfStock > 0 ? '#fff5f5' : '#f0fdf4'; ?>;border:1.5px solid <?php echo $invOutOfStock > 0 ? '#fca5a5' : '#bbf7d0'; ?>;border-radius:10px;padding:14px 16px;text-align:center">
            <div style="font-size:28px;font-weight:900;color:<?php echo $invOutOfStock > 0 ? '#dc2626' : '#15803d'; ?>;font-family:'Barlow Condensed',sans-serif"><?php echo $invOutOfStock; ?></div>
            <div style="font-size:11px;font-weight:700;color:<?php echo $invOutOfStock > 0 ? '#dc2626' : '#166534'; ?>;text-transform:uppercase;letter-spacing:.8px;margin-top:3px">🚫 Out of Stock</div>
          </div>
        </div>
        <?php if (!empty($invLowItems)): ?>
        <div style="border-top:1px solid #e2e8f0;padding-top:16px">
          <div style="font-size:11px;font-weight:800;color:#92400e;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;display:flex;align-items:center;gap:6px">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Needs Attention
          </div>
          <div style="display:flex;flex-direction:column;gap:7px">
            <?php foreach($invLowItems as $li):
              $liQty = (float)$li['quantity_in_stock'];
              $isOut = ($liQty == 0);
            ?>
            <div style="display:flex;align-items:center;justify-content:space-between;background:<?php echo $isOut ? '#fff5f5' : '#fffbeb'; ?>;border:1px solid <?php echo $isOut ? '#fca5a5' : '#fde68a'; ?>;border-radius:8px;padding:9px 14px">
              <div style="font-size:13px;font-weight:700;color:var(--blue-dark)"><?php echo htmlspecialchars($li['material_name']); ?></div>
              <div style="display:flex;align-items:center;gap:8px">
                <span style="font-size:12px;font-weight:700;color:<?php echo $isOut ? '#dc2626' : '#92400e'; ?>">
                  <?php echo number_format($liQty, 2); ?> <?php echo htmlspecialchars($li['unit']); ?>
                </span>
                <span style="background:<?php echo $isOut ? '#fee2e2' : '#fef3c7'; ?>;color:<?php echo $isOut ? '#dc2626' : '#92400e'; ?>;font-size:10px;font-weight:800;padding:2px 8px;border-radius:4px;text-transform:uppercase">
                  <?php echo $isOut ? '🚫 Out' : '⚠️ Low'; ?>
                </span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php if ($invLowStock + $invOutOfStock > 5): ?>
          <div style="margin-top:10px;text-align:center">
            <a href="../inventory.php?status=low" style="font-size:12px;font-weight:700;color:var(--blue-light);text-decoration:none">
              View all <?php echo $invLowStock + $invOutOfStock; ?> items needing attention &rarr;
            </a>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>


    <!-- Recent Activity -->
    <div class="panel">
      <div class="panel-head"><div class="panel-title">🕒 Recent Output Entries <span id="dash-live-badge" style="background:rgba(255,255,255,.18);color:#fff;font-size:10px;padding:2px 9px;border-radius:20px;margin-left:8px;font-weight:700;letter-spacing:.5px">● LIVE</span></div></div>
      <div class="table-scroll-wrap">
      <table>
        <thead>
        <tr><th>Date &amp; Time</th><th>Size</th><th>Stage</th><th>Action</th><th>Qty</th><th>Entered By</th><th>✅ Confirmed By</th><th>Reason</th></tr>
        </thead>
        <tbody id="dash-recent-tbody">
        <?php foreach($recentOut as $r): ?>
        <tr>
          <td>
            <div style="font-weight:700"><?= date('M j, Y', strtotime($r['log_date'])) ?></div>
            <div style="display:inline-flex;align-items:center;gap:4px;margin-top:3px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:20px;padding:2px 8px">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <span style="font-size:10px;font-weight:700;color:#1d4ed8;letter-spacing:.3px">
                <?= !empty($r['entered_at']) ? date('h:i A', strtotime($r['entered_at'])) : '—' ?>
              </span>
            </div>
          </td>
          <td><span class="badge" style="background:#fff3eb;color:#c2410c"><?= htmlspecialchars($r['size_label']) ?></span></td>
          <td><?= htmlspecialchars($r['stage']) ?></td>
          <td>
            <?php if(($r['action']??'add')==='minus'): ?>
              <span style="background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;font-size:10px;font-weight:800;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap">➖ Subtracted</span>
            <?php else: ?>
              <span style="background:#f0fdf4;border:1px solid #86efac;color:#16a34a;font-size:10px;font-weight:800;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap">➕ Added</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if(($r['action']??'add')==='minus'): ?>
              <strong style="color:#dc2626">−<?= $r['qty_produced'] ?></strong>
            <?php else: ?>
              <strong style="color:#16a34a">+<?= $r['qty_produced'] ?></strong>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($r['full_name']) ?></td>
          <td>
            <?php if(!empty($r['confirmed_by'])): ?>
              <span style="display:inline-flex;align-items:center;gap:5px;background:#dcfce7;color:#166534;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;border:1px solid #bbf7d0">
                <span style="width:6px;height:6px;border-radius:50%;background:#22c55e;flex-shrink:0;display:inline-block"></span>
                <?= htmlspecialchars($r['confirmed_by']) ?>
              </span>
            <?php else: ?>
              <span style="display:inline-flex;align-items:center;gap:5px;background:#fef3c7;color:#92400e;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;border:1px solid #fde68a">⚠️ Not confirmed</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if(($r['action']??'add')==='minus' && !empty($r['subtract_reason'])): ?>
              <span style="display:inline-flex;align-items:center;gap:5px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:3px 9px;font-size:11px;color:#9a3412;font-weight:600;max-width:160px;word-break:break-word">
                📝 <?= htmlspecialchars($r['subtract_reason']) ?>
              </span>
            <?php elseif(($r['action']??'add')==='minus'): ?>
              <span style="color:#9ca3af;font-size:12px">—</span>
            <?php else: ?>
              <span style="color:#d1d5db;font-size:11px">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <!-- ===== OUTPUTS ===== -->
  <div id="page-outputs" class="page">
    <h1 class="page-title">📋 Production Output History</h1>

    <!-- Full Output History Log -->
    <div class="panel">
      <div class="panel-head" style="justify-content:space-between">
        <div class="panel-title">📋 Output History Log
          <span style="background:rgba(255,255,255,.2);color:#fff;font-size:10px;padding:2px 10px;border-radius:20px;margin-left:8px;font-weight:700">
            Latest 50 entries
          </span>
        </div>
        <div style="font-size:11px;color:rgba(255,255,255,.6);font-weight:600">
          Order: <?= htmlspecialchars($activeOrder['order_code'] ?? '—') ?>
        </div>
      </div>

      <?php if(empty($recentOut)): ?>
        <div style="padding:40px;text-align:center;color:var(--text2)">
          <div style="font-size:36px;margin-bottom:10px">📭</div>
          <div style="font-weight:700;font-size:14px">No output entries yet.</div>
          <div style="font-size:12px;margin-top:4px">Logged entries will appear here.</div>
        </div>
      <?php else: ?>

      <!-- Summary row -->
      <?php
        $totalAdded    = array_sum(array_column(array_filter($recentOut, fn($r) => ($r['action']??'add')==='add'),    'qty_produced'));
        $totalSubtracted = array_sum(array_column(array_filter($recentOut, fn($r) => ($r['action']??'add')==='minus'), 'qty_produced'));
        $uniqueDays    = count(array_unique(array_column($recentOut, 'log_date')));
        $confirmedCount = count(array_filter($recentOut, fn($r) => !empty($r['confirmed_by'])));
      ?>
      <div style="display:flex;gap:0;border-bottom:2px solid var(--border)">
        <div style="flex:1;padding:14px 20px;text-align:center;border-right:1px solid var(--border)">
          <div id="oh-entries-count" style="font-size:22px;font-weight:900;color:var(--blue-dark);font-family:'Barlow Condensed',sans-serif"><?= count($recentOut) ?></div>
          <div style="font-size:10px;color:var(--text2);font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-top:2px">Entries Shown</div>
        </div>
        <div style="flex:1;padding:14px 20px;text-align:center;border-right:1px solid var(--border)">
          <div id="oh-total-added" style="font-size:22px;font-weight:900;color:#166534;font-family:'Barlow Condensed',sans-serif">+<?= number_format($totalAdded) ?></div>
          <div style="font-size:10px;color:var(--text2);font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-top:2px">Total Added</div>
        </div>
        <div style="flex:1;padding:14px 20px;text-align:center;border-right:1px solid var(--border)">
          <div id="oh-total-subtracted" style="font-size:22px;font-weight:900;color:#dc2626;font-family:'Barlow Condensed',sans-serif">−<?= number_format($totalSubtracted) ?></div>
          <div style="font-size:10px;color:var(--text2);font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-top:2px">Total Subtracted</div>
        </div>
        <div style="flex:1;padding:14px 20px;text-align:center;border-right:1px solid var(--border)">
          <div id="oh-unique-days" style="font-size:22px;font-weight:900;color:var(--blue-dark);font-family:'Barlow Condensed',sans-serif"><?= $uniqueDays ?></div>
          <div style="font-size:10px;color:var(--text2);font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-top:2px">Active Days</div>
        </div>
        <div style="flex:1;padding:14px 20px;text-align:center">
          <div id="oh-confirmed-count" style="font-size:22px;font-weight:900;color:#f59e0b;font-family:'Barlow Condensed',sans-serif"><?= $confirmedCount ?></div>
          <div style="font-size:10px;color:var(--text2);font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-top:2px">Confirmed</div>
        </div>
      </div>

      <!-- FILTER BAR -->
      <div id="oh-filter-bar" style="padding:14px 20px;border-bottom:2px solid var(--border);background:#f8faff;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2.5" style="flex-shrink:0"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <div style="display:flex;flex-direction:column;gap:3px">
          <label style="font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.8px">Exact Date</label>
          <input type="date" id="oh-filter-date"
            style="padding:7px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:12px;font-family:'Barlow',sans-serif;outline:none;color:var(--text);background:#fff;cursor:pointer"
            onchange="ohApplyFilters()">
        </div>
        <div style="display:flex;flex-direction:column;gap:3px">
          <label style="font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.8px">Month</label>
          <input type="month" id="oh-filter-month"
            style="padding:7px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:12px;font-family:'Barlow',sans-serif;outline:none;color:var(--text);background:#fff;cursor:pointer"
            onchange="ohApplyFilters()">
        </div>
        <div style="display:flex;flex-direction:column;gap:3px">
          <label style="font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.8px">Stage</label>
          <select id="oh-filter-stage" onchange="ohApplyFilters()"
            style="padding:7px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:12px;font-family:'Barlow',sans-serif;outline:none;background:#fff;color:var(--text)">
            <option value="">All Stages</option>
            <?php foreach($stages as $st): ?>
              <option value="<?= htmlspecialchars($st['name']) ?>"><?= htmlspecialchars($st['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex;flex-direction:column;gap:3px">
          <label style="font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.8px">Action</label>
          <select id="oh-filter-action" onchange="ohApplyFilters()"
            style="padding:7px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:12px;font-family:'Barlow',sans-serif;outline:none;background:#fff;color:var(--text)">
            <option value="">All Actions</option>
            <option value="add">Added</option>
            <option value="minus">Subtracted</option>
          </select>
        </div>
        <div style="display:flex;flex-direction:column;gap:3px">
          <label style="font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.8px">Size</label>
          <select id="oh-filter-size" onchange="ohApplyFilters()"
            style="padding:7px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:12px;font-family:'Barlow',sans-serif;outline:none;background:#fff;color:var(--text)">
            <option value="">All Sizes</option>
            <?php foreach($allSizes as $sz): ?>
              <option value="<?= htmlspecialchars($sz['size_label']) ?>"><?= htmlspecialchars($sz['size_label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="margin-left:auto;display:flex;align-items:flex-end">
          <button onclick="ohClearFilters()"
            style="padding:7px 16px;background:#e8eef8;border:1.5px solid var(--border);border-radius:6px;font-size:11px;font-weight:800;color:var(--blue-dark);cursor:pointer;font-family:'Barlow',sans-serif;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:6px">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            Clear Filters
          </button>
        </div>
        <div id="oh-result-count" style="font-size:11px;font-weight:700;color:var(--text2);display:none;align-items:center;gap:5px;white-space:nowrap">
          <span id="oh-count-num" style="font-size:15px;font-weight:900;color:var(--blue-dark)">0</span>&nbsp;result(s) found
        </div>
      </div>
      <div id="oh-no-results" style="display:none;padding:36px;text-align:center;color:var(--text2)">
        <div style="font-size:32px;margin-bottom:10px">&#128269;</div>
        <div style="font-weight:700;font-size:14px">No entries match your filter.</div>
        <div style="font-size:12px;margin-top:4px">Try a different date or clear the filters.</div>
      </div>
      <div class="table-scroll-wrap" style="overflow-x:auto">
        <table id="oh-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Date & Time</th>
              <th>Size</th>
              <th>Stage</th>
              <th style="text-align:center">Action</th>
              <th style="text-align:center">Qty</th>
              <th>Logged By</th>
              <th>Confirmed By</th>
              <th>Reason</th>
            </tr>
          </thead>
          <tbody id="oh-tbody">
          <?php foreach($recentOut as $i => $r): ?>
          <tr data-date="<?= $r['log_date'] ?>"
              data-month="<?= substr($r['log_date'],0,7) ?>"
              data-stage="<?= htmlspecialchars($r['stage']) ?>"
              data-action="<?= $r['action']??'add' ?>"
              data-size="<?= htmlspecialchars($r['size_label']) ?>">
            <td style="color:var(--text2);font-weight:700;font-size:12px"><?= count($recentOut) - $i ?></td>
            <td>
              <div style="font-weight:700"><?= date('M j, Y', strtotime($r['log_date'])) ?></div>
              <div style="display:inline-flex;align-items:center;gap:4px;margin-top:3px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:20px;padding:2px 8px">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span style="font-size:10px;font-weight:700;color:#1d4ed8;letter-spacing:.3px">
                  <?= !empty($r['entered_at']) ? date('h:i A', strtotime($r['entered_at'])) : '—' ?>
                </span>
              </div>
            </td>
            <td><span class="badge badge-orange"><?= htmlspecialchars($r['size_label']) ?></span></td>
            <td>
              <span style="background:#eff6ff;color:var(--blue-dark);padding:3px 10px;border-radius:5px;font-size:11px;font-weight:700;display:inline-block">
                <?= htmlspecialchars($r['stage']) ?>
              </span>
            </td>
            <td style="text-align:center">
              <?php if(($r['action']??'add')==='minus'): ?>
                <span style="background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;font-size:10px;font-weight:800;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px">➖ Subtracted</span>
              <?php else: ?>
                <span style="background:#f0fdf4;border:1px solid #86efac;color:#16a34a;font-size:10px;font-weight:800;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px">➕ Added</span>
              <?php endif; ?>
            </td>
            <td style="text-align:center">
              <?php if(($r['action']??'add')==='minus'): ?>
                <span style="background:#fef2f2;color:#dc2626;font-weight:900;font-size:15px;padding:4px 14px;border-radius:6px;font-family:'Barlow Condensed',sans-serif;display:inline-block">
                  −<?= number_format($r['qty_produced']) ?>
                </span>
              <?php else: ?>
                <span style="background:#dcfce7;color:#166534;font-weight:900;font-size:15px;padding:4px 14px;border-radius:6px;font-family:'Barlow Condensed',sans-serif;display:inline-block">
                  +<?= number_format($r['qty_produced']) ?>
                </span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;font-weight:600"><?= htmlspecialchars($r['full_name']) ?></td>
            <td>
              <?php if(!empty($r['confirmed_by'])): ?>
                <span style="background:#fef9c3;color:#854d0e;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:4px;border:1px solid #fde68a">
                  ✅ <?= htmlspecialchars($r['confirmed_by']) ?>
                </span>
              <?php else: ?>
                <span style="color:#9ca3af;font-size:12px">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if(($r['action']??'add')==='minus' && !empty($r['subtract_reason'])): ?>
                <span style="display:inline-flex;align-items:center;gap:5px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:4px 10px;font-size:11px;color:#9a3412;font-weight:600;max-width:180px;word-break:break-word">
                  📝 <?= htmlspecialchars($r['subtract_reason']) ?>
                </span>
              <?php elseif(($r['action']??'add')==='minus'): ?>
                <span style="color:#9ca3af;font-size:12px">—</span>
              <?php else: ?>
                <span style="color:#d1d5db;font-size:11px">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ===== ORDERS & SIZES ===== -->
  <div id="page-orders" class="page">
    <h1 class="page-title">📄 Orders & Size Management</h1>

    <!-- Update order -->
    <?php if($activeOrder): ?>
    <div class="panel">
      <div class="panel-head"><div class="panel-title">Active Order: <?= htmlspecialchars($activeOrder['order_code']) ?></div></div>
      <form method="POST">
        <input type="hidden" name="action" value="update_target">
        <div class="form-grid">
          <div class="fg"><label>Target Pairs</label><input type="number" name="target_pairs" value="<?= $activeOrder['target_pairs'] ?>" required></div>
          <div class="fg"><label>Deadline</label><input type="date" name="deadline" value="<?= $activeOrder['deadline'] ?>" required></div>
        </div>
        <div class="form-footer"><button type="submit" class="btn btn-primary">Update Order</button></div>
      </form>
    </div>
    <?php endif; ?>

    <!-- Add size -->
    <div class="panel">
      <div class="panel-head"><div class="panel-title">Add Size Variant</div></div>
      <form method="POST">
        <input type="hidden" name="action" value="add_size">
        <div class="form-grid">
          <div class="fg"><label>Size Label (e.g. 13us 45uk)</label><input type="text" name="size_label" placeholder="13us (45uk)" required></div>
          <div class="fg"><label>Target Qty</label><input type="number" name="target_qty" min="1" value="50" required></div>
        </div>
        <div class="form-footer"><button type="submit" class="btn btn-primary">+ Add Size</button></div>
      </form>
    </div>

    <!-- List sizes -->
    <div class="panel">
      <div class="panel-head"><div class="panel-title">Size Variants</div></div>
      <table>
        <tr><th>#</th><th>Size</th><th>Target Qty</th><th>Action</th></tr>
        <?php foreach($allSizes as $i=>$sz): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><span class="badge badge-orange"><?= htmlspecialchars($sz['size_label']) ?></span></td>
          <td><strong><?= $sz['target_qty'] ?></strong></td>
          <td style="display:flex;gap:6px;align-items:center">
            <button type="button" class="btn btn-sm btn-edit"
              onclick="openEditSize(<?= $sz['id'] ?>, '<?= htmlspecialchars(addslashes($sz['size_label'])) ?>', <?= $sz['target_qty'] ?>)">
              ✏️ Edit
            </button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Remove this size?')">
              <input type="hidden" name="action" value="delete_size">
              <input type="hidden" name="sid" value="<?= $sz['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

  <!-- ===== USERS ===== -->
  <div id="page-users" class="page">
    <h1 class="page-title">👥 User Management</h1>
    <!-- Role explanation cards -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:24px">
      <div style="background:#fff;border-radius:12px;border:2px solid #1a3a8f;padding:16px 20px;box-shadow:0 2px 8px rgba(26,58,143,.08)">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
          <span style="font-size:22px">👑</span>
          <div>
            <div style="font-family:'Barlow Condensed',sans-serif;font-size:18px;font-weight:900;color:#1a3a8f;text-transform:uppercase;letter-spacing:.5px">Admin</div>
            <span style="background:#1a3a8f;color:#fff;font-size:10px;font-weight:800;padding:2px 8px;border-radius:20px">Full Access</span>
          </div>
        </div>
        <ul style="font-size:12px;color:#4a5b8a;line-height:1.8;padding-left:16px">
          <li>View & manage all dashboard pages</li>
          <li>Add / edit / delete production orders</li>
          <li>Manage users &amp; assign roles</li>
          <li>Full inventory control (add, edit, delete materials)</li>
          <li>Manage stages, announcements, settings</li>
          <li>View archives &amp; output history</li>
        </ul>
      </div>
      <div style="background:#fff;border-radius:12px;border:2px solid #22c55e;padding:16px 20px;box-shadow:0 2px 8px rgba(34,197,94,.08)">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
          <span style="font-size:22px">🏭</span>
          <div>
            <div style="font-family:'Barlow Condensed',sans-serif;font-size:18px;font-weight:900;color:#166534;text-transform:uppercase;letter-spacing:.5px">Production</div>
            <span style="background:#22c55e;color:#fff;font-size:10px;font-weight:800;padding:2px 8px;border-radius:20px">Production Access</span>
          </div>
        </div>
        <ul style="font-size:12px;color:#4a5b8a;line-height:1.8;padding-left:16px">
          <li>Access production panel (index.php)</li>
          <li>Input daily output per stage &amp; size</li>
          <li>View &amp; adjust inventory stock levels</li>
          <li>View inventory transactions</li>
          <li>❌ Cannot add/edit/delete materials</li>
          <li>❌ No access to admin dashboard</li>
        </ul>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><div class="panel-title">Add New User</div></div>
      <form method="POST">
        <input type="hidden" name="action" value="add_user">
        <div class="form-grid col3">
          <div class="fg"><label>Username</label><input type="text" name="username" required></div>
          <div class="fg"><label>Full Name</label><input type="text" name="full_name" required></div>
          <div class="fg"><label>Password</label><input type="password" name="password" required></div>
          <div class="fg"><label>Role</label>
            <select name="role">
              <option value="supervisor">Production</option>
              <option value="admin">Admin</option>
            </select>
          </div>
        </div>
        <div class="form-footer"><button type="submit" class="btn btn-primary">Create User</button></div>
      </form>
    </div>

    <div class="panel">
      <div class="panel-head"><div class="panel-title">All Users (<?= count($allUsers) ?>)</div></div>
      <table>
        <tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Credentials</th><th>Action</th></tr>
        <?php foreach($allUsers as $u): ?>
        <tr>
          <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
          <td style="font-family:monospace;font-size:13px"><?= htmlspecialchars($u['username']) ?></td>
          <td><span class="badge badge-<?= $u['role'] === 'supervisor' ? 'supervisor' : $u['role'] ?>"><?= $u['role']==='supervisor' ? '🏭 Production' : '👑 Admin' ?></span></td>
          <td><span class="badge <?= $u['is_active']?'badge-active':'badge-inactive' ?>"><?= $u['is_active']?'Active':'Inactive' ?></span></td>
          <td>
            <?php if($u['id'] !== $user['id']): ?>
              <button onclick="openEditUser(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>', '<?= htmlspecialchars(addslashes($u['full_name'])) ?>', '<?= $u['role'] ?>')"
                style="background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;font-size:11px;font-weight:700;padding:4px 12px;border-radius:6px;cursor:pointer;letter-spacing:.3px">
                ✏️ Edit Credentials
              </button>
            <?php else: ?>
              <span style="font-size:12px;color:#aaa">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if($u['id'] !== $user['id']): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle_user">
              <input type="hidden" name="uid" value="<?= $u['id'] ?>">
              <button type="submit" class="btn btn-sm btn-toggle"><?= $u['is_active']?'Deactivate':'Activate' ?></button>
            </form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Permanently delete user \'<?= htmlspecialchars(addslashes($u['full_name'])) ?>\'? This cannot be undone.')">
              <input type="hidden" name="action" value="delete_user">
              <input type="hidden" name="uid" value="<?= $u['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger">Remove</button>
            </form>
            <?php else: ?><span style="font-size:12px;color:#aaa">Current user</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

  <!-- ===== ANNOUNCEMENTS ===== -->
  <div id="page-announce" class="page">
    <h1 class="page-title">📢 Announcements</h1>
    <div class="panel">
      <div class="panel-head"><div class="panel-title">Publish Announcement (replaces current)</div></div>
      <form method="POST">
        <input type="hidden" name="action" value="add_announcement">
        <div class="form-grid">
          <div class="fg"><label>Title</label><input type="text" name="ann_title" required></div>
          <div class="fg"><label>Priority</label>
            <select name="ann_priority">
              <option value="low">Low</option>
              <option value="medium" selected>Medium</option>
              <option value="high">High</option>
            </select>
          </div>
          <div class="fg" style="grid-column:1/-1"><label>Message Body</label><textarea name="ann_body" rows="3" style="resize:vertical"></textarea></div>
        </div>
        <div class="form-footer"><button type="submit" class="btn btn-primary">Publish</button></div>
      </form>
    </div>
    <?php
    $allAnn = $pdo->query("SELECT a.*, u.full_name FROM announcements a JOIN users u ON u.id=a.created_by ORDER BY a.created_at DESC")->fetchAll();
    ?>
    <div class="panel">
      <div class="panel-head"><div class="panel-title">All Announcements</div></div>
      <table>
        <tr><th>Title</th><th>Priority</th><th>Status</th><th>By</th><th>Date</th></tr>
        <?php foreach($allAnn as $an): ?>
        <tr>
          <td><strong><?= htmlspecialchars($an['title']) ?></strong><br><span style="font-size:12px;color:var(--text2)"><?= htmlspecialchars(substr($an['body'],0,60)) ?>...</span></td>
          <td><?= ucfirst($an['priority']) ?></td>
          <td><span class="badge <?= $an['is_active']?'badge-active':'badge-inactive' ?>"><?= $an['is_active']?'Live':'Archived' ?></span></td>
          <td><?= htmlspecialchars($an['full_name']) ?></td>
          <td><?= date('M j, Y', strtotime($an['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

  <!-- ===== TEAM LEADERS / CONFIRMERS ===== -->
  <div id="page-confirmers" class="page">
    <h1 class="page-title">✅ Team Leaders — Confirmation Dropdown</h1>
    <p style="color:var(--text2);font-size:13px;margin-bottom:20px;font-weight:500">
      These names appear in the <strong>"Confirmed By"</strong> dropdown inside the Add Production Qty modal on the production floor. Add or remove team leaders here.
    </p>

    <!-- Add confirmer -->
    <div class="panel">
      <div class="panel-head"><div class="panel-title">➕ Add Team Leader Name</div></div>
      <form method="POST">
        <input type="hidden" name="action" value="add_confirmer">
        <div class="form-grid" style="grid-template-columns:1fr auto;align-items:end">
          <div class="fg">
            <label>Full Name</label>
            <input type="text" name="confirmer_name" placeholder="e.g. Juan dela Cruz" required>
          </div>
          <div class="fg">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn-primary" style="height:41px">+ Add Name</button>
          </div>
        </div>
      </form>
    </div>

    <!-- List confirmers -->
    <div class="panel">
      <div class="panel-head"><div class="panel-title">👥 Current Team Leaders (<?= count($confirmersList) ?>)</div></div>
      <?php if(empty($confirmersList)): ?>
        <div style="padding:24px;text-align:center;color:var(--text2);font-size:13px">
          No team leaders added yet. Add names above so they appear in the confirmation dropdown.
        </div>
      <?php else: ?>
      <table>
        <tr><th>#</th><th>Name</th><th>Action</th></tr>
        <?php foreach($confirmersList as $ci => $cn): ?>
        <tr>
          <td style="color:var(--text2);font-weight:700"><?= $ci+1 ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <span style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--blue-light));display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:13px;flex-shrink:0">
                <?= strtoupper(substr($cn,0,1)) ?>
              </span>
              <strong><?= htmlspecialchars($cn) ?></strong>
            </div>
          </td>
          <td>
            <form method="POST" style="display:inline" onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($cn)) ?> from the dropdown?')">
              <input type="hidden" name="action" value="remove_confirmer">
              <input type="hidden" name="confirmer_name" value="<?= htmlspecialchars($cn) ?>">
              <button type="submit" class="btn btn-sm btn-danger">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>

    <!-- Subtract PIN Management -->
    <div class="panel" style="border:2px solid #fcd34d;background:#fffbeb">
      <div class="panel-head" style="background:#fef3c7;border-bottom:1px solid #fcd34d">
        <div class="panel-title" style="color:#92400e">Subtract PIN</div>
      </div>
      <div style="padding:16px 20px">
        <div style="font-size:13px;color:#78350f;margin-bottom:14px;line-height:1.6">
          Set a <strong>4-digit PIN</strong> that must be entered before anyone can subtract production qty.
          Keep this PIN only among supervisors/admins.<br>
          <strong>Status:</strong>
          <?php if($subtractPinIsSet): ?>
            <span style="color:#16a34a;font-weight:700">🔐 PIN is set</span>
          <?php else: ?>
            <span style="color:#dc2626;font-weight:700">🔐¸ No PIN set — subtraction is currently unprotected!</span>
          <?php endif; ?>
        </div>
        <form method="POST">
          <input type="hidden" name="action" value="set_subtract_pin">
          <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            <div class="fg" style="flex:0 0 auto">
              <label>New 4-Digit PIN</label>
              <div style="display:flex;gap:6px">
                <?php for($pi=0;$pi<4;$pi++): ?>
                <input type="password" name="subtract_pin_digit[]" maxlength="1" inputmode="numeric" pattern="[0-9]"
                  id="admin-pin-<?= $pi ?>"
                  oninput="adminPinInput(this,<?= $pi ?>)"
                  onkeydown="adminPinKey(event,<?= $pi ?>)"
                  required
                  style="width:48px;height:52px;text-align:center;font-size:24px;font-weight:900;
                         border:2px solid #fcd34d;border-radius:10px;outline:none;
                         background:#fff;color:#1a3a8f;font-family:'Barlow',sans-serif">
                <?php endfor; ?>
                <!-- Hidden combined field -->
                <input type="hidden" name="subtract_pin" id="admin-pin-combined">
              </div>
            </div>
            <div class="fg" style="flex:0 0 auto">
              <label>&nbsp;</label>
              <button type="submit" class="btn btn-primary" style="height:52px;padding:0 24px"
                onclick="return combineAdminPin()">
                Save PIN
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <div class="panel" style="background:#eff6ff;border-color:#bfdbfe">
      <div style="padding:16px 20px;display:flex;align-items:flex-start;gap:12px">
        <span style="font-size:20px">ð¡</span>
        <div>
          <div style="font-weight:800;color:var(--blue-dark);margin-bottom:4px;font-size:13px">How it works</div>
          <div style="font-size:12px;color:var(--text2);line-height:1.6">
            When a supervisor or admin subtracts production qty on the floor, they must:<br>
            1. Enter the quantity to subtract<br>
            2. Select a <strong>Team Leader</strong> to confirm<br>
            3. Enter the <strong>4-digit Supervisor PIN</strong><br>
            4. Enter the reason for subtraction<br>
            Only then can they save the entry.
          </div>
        </div>
      </div>
    </div>
  </div><!-- end page-confirmers -->

<script>
function adminPinInput(el, idx) {
  el.value = el.value.replace(/[^0-9]/g,'').slice(-1);
  if (el.value && idx < 3) {
    var n = document.getElementById('admin-pin-' + (idx+1));
    if (n) n.focus();
  }
}
function adminPinKey(e, idx) {
  if (e.key === 'Backspace' && !document.getElementById('admin-pin-'+idx).value && idx > 0) {
    var p = document.getElementById('admin-pin-' + (idx-1));
    if (p) { p.value = ''; p.focus(); }
  }
}
function combineAdminPin() {
  var pin = '';
  for (var i = 0; i < 4; i++) {
    var d = document.getElementById('admin-pin-' + i);
    pin += d ? (d.value || '') : '';
  }
  if (pin.length !== 4) { alert('Please enter all 4 PIN digits.'); return false; }
  document.getElementById('admin-pin-combined').value = pin;
  return true;
}
</script>

  <!-- ===== STAGES MANAGEMENT ===== -->
  <div id="page-stages" class="page">
    <h1 class="page-title">⚙️ Production Process</h1>
    <p style="color:var(--text2);font-size:13px;margin-bottom:20px;font-weight:500">
      Manage the production process stages (e.g. Cutting, Stitching, Lasting, Soling, Finishing).
      These columns appear in the production floor panel. <strong>Sort order</strong> controls left-to-right column position.
    </p>

    <!-- Add Stage -->
    <div class="panel">
      <div class="panel-head"><div class="panel-title">➕ Add New Process</div></div>
      <form method="POST">
        <input type="hidden" name="action" value="add_stage">
        <div class="form-grid" style="grid-template-columns:1fr auto;align-items:end;padding:18px 20px">
          <div class="fg">
            <label>Stage Name</label>
            <input type="text" name="stage_name" placeholder="e.g. Cementing, Inspection…" required
              style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;font-family:'Barlow',sans-serif;outline:none">
          </div>
          <div class="fg">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn-primary" style="height:41px;white-space:nowrap">+ Add Stage</button>
          </div>
        </div>
      </form>
    </div>

    <!-- Stage List -->
    <div class="panel">
      <div class="panel-head">
        <div class="panel-title">🏭 Current Process (<?= count($stages) ?>)</div>
        <div style="font-size:11px;color:rgba(255,255,255,.6);font-weight:500">Changes take effect immediately on the production floor</div>
      </div>

      <?php if(empty($stages)): ?>
        <div style="padding:40px;text-align:center;color:var(--text2);font-size:14px">
          No stages found. Add one above.
        </div>
      <?php else: ?>
      <table style="table-layout:fixed;width:100%">
        <thead>
          <tr>
            <th style="width:40px"></th>
            <th style="width:70px">Order</th>
            <th>Stage Name</th>
            <th style="width:110px;text-align:center">Status</th>
            <th style="width:260px;text-align:right;white-space:nowrap">Actions</th>
          </tr>
        </thead>
        <tbody id="stages-sortable">
        <?php foreach($stages as $sg):
          $stUsed = $pdo->prepare("SELECT COUNT(*) FROM daily_outputs WHERE stage_id=?");
          $stUsed->execute([$sg['id']]);
          $stUsedCount = (int)$stUsed->fetchColumn();
          $isActive = isset($sg['is_active']) ? (int)$sg['is_active'] : 1;
        ?>
        <tr id="stage-row-<?= $sg['id'] ?>" data-id="<?= $sg['id'] ?>" style="transition:.2s;<?= !$isActive ? 'opacity:.55;' : '' ?>">
          <td style="text-align:center;cursor:grab;color:#94a3b8" class="drag-handle" title="Drag to reorder">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <circle cx="9" cy="5" r="1.2" fill="currentColor"/><circle cx="15" cy="5" r="1.2" fill="currentColor"/>
              <circle cx="9" cy="12" r="1.2" fill="currentColor"/><circle cx="15" cy="12" r="1.2" fill="currentColor"/>
              <circle cx="9" cy="19" r="1.2" fill="currentColor"/><circle cx="15" cy="19" r="1.2" fill="currentColor"/>
            </svg>
          </td>
          <td>
            <span id="sort-badge-<?= $sg['id'] ?>" style="display:inline-flex;align-items:center;justify-content:center;
              width:32px;height:32px;border-radius:8px;background:var(--blue);color:#fff;
              font-weight:900;font-family:'Barlow Condensed',sans-serif;font-size:16px">
              <?= (int)$sg['sort_order'] ?>
            </span>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <span style="width:36px;height:36px;border-radius:10px;flex-shrink:0;
                background:linear-gradient(135deg,var(--blue-mid),var(--blue-light));
                display:inline-flex;align-items:center;justify-content:center;
                color:#fff;font-weight:800;font-size:13px">
                <?= strtoupper(substr($sg['name'],0,2)) ?>
              </span>
              <div style="font-weight:700;font-size:14px;color:var(--blue-dark)"><?= htmlspecialchars($sg['name']) ?></div>
            </div>
          </td>
          <td style="text-align:center">
            <span class="badge <?= $isActive ? 'badge-active' : 'badge-inactive' ?>"
              style="font-size:10px;padding:3px 10px">
              <?= $isActive ? '✓ Active' : '⊘ Hidden' ?>
            </span>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <div style="display:inline-flex;gap:5px;align-items:center">
              <!-- Edit -->
              <button type="button"
                onclick="openEditStage(<?= $sg['id'] ?>, <?= htmlspecialchars(json_encode($sg['name'])) ?>, <?= (int)$sg['sort_order'] ?>)"
                class="btn btn-sm" style="background:#eff6ff;color:var(--blue);border:1px solid #bfdbfe;white-space:nowrap">
                ✏️ Edit
              </button>

              <!-- Toggle active -->
              <form method="POST" style="display:inline;margin:0">
                <input type="hidden" name="action" value="toggle_stage">
                <input type="hidden" name="stage_id" value="<?= $sg['id'] ?>">
                <button type="submit" class="btn btn-sm" style="white-space:nowrap;
                  background:<?= $isActive ? '#fff7ed' : '#f0fdf4' ?>;
                  color:<?= $isActive ? '#c2410c' : '#166534' ?>;
                  border:1px solid <?= $isActive ? '#fed7aa' : '#bbf7d0' ?>">
                  <?= $isActive ? '🙈 Hide' : '👁 Show' ?>
                </button>
              </form>

              <!-- Delete — always enabled -->
              <form method="POST" style="display:inline;margin:0"
                onsubmit="return confirm('Delete stage \'<?= htmlspecialchars(addslashes($sg['name'])) ?>\'? This cannot be undone.')">
                <input type="hidden" name="action" value="delete_stage">
                <input type="hidden" name="stage_id" value="<?= $sg['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger" style="white-space:nowrap">🗑️ Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- Info box -->
    <div class="panel" style="background:#eff6ff;border-color:#bfdbfe">
      <div style="padding:16px 20px;display:flex;align-items:flex-start;gap:12px">
        <span style="font-size:20px">💡</span>
        <div>
          <div style="font-weight:800;color:var(--blue-dark);margin-bottom:6px;font-size:13px">How Stages Work</div>
          <div style="font-size:12px;color:var(--text2);line-height:1.7">
            • <strong>Sort Order</strong> — controls the left-to-right column order on the production floor panel.<br>
            • <strong>Hide</strong> — removes the column from the floor panel without deleting any data.<br>
            • <strong>Delete</strong> — permanently removes the stage. Only available if no output records exist.<br>
            • <strong>Finishing</strong> is used to count total completed pairs — keep it active.
          </div>
        </div>
      </div>
    </div>
  </div><!-- end page-stages -->

  <!-- ===== ARCHIVES ===== -->
  <div id="page-archives" class="page">
    <h1 class="page-title">📦 Project Archives</h1>

    <?php // $archivedOrders already fetched at top of file ?>

    <?php if(empty($archivedOrders)): ?>
      <div class="panel">
        <div style="padding:48px;text-align:center;color:var(--text2)">
          <div style="font-size:48px;margin-bottom:12px">📭</div>
          <div style="font-size:15px;font-weight:700">No archived projects yet.</div>
          <div style="font-size:13px;margin-top:6px">When you do a New Project Reset, the old project gets archived here.</div>
        </div>
      </div>
    <?php else: ?>

      <!-- Summary count -->
      <div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap">
        <div class="stat" style="flex:0 0 180px;min-width:160px">
          <div class="stat-icon" style="background:#f0fdf4">📦</div>
          <div class="stat-val" style="font-size:28px"><?= count($archivedOrders) ?></div>
          <div class="stat-label">Total Archived</div>
          <div class="stat-accent" style="background:#22c55e"></div>
        </div>
        <?php
          $totalArchPairs = array_sum(array_column($archivedOrders, 'total_completed'));
          $totalArchTarget = array_sum(array_column($archivedOrders, 'target_pairs'));
        ?>
        <div class="stat" style="flex:0 0 200px;min-width:160px">
          <div class="stat-icon" style="background:#fff3eb">👟</div>
          <div class="stat-val" style="font-size:28px"><?= number_format($totalArchPairs) ?></div>
          <div class="stat-label">Total Pairs Produced</div>
          <div class="stat-accent" style="background:#f97316"></div>
          <div style="font-size:11px;color:var(--text2);margin-top:4px">across all projects</div>
        </div>
        <div class="stat" style="flex:0 0 200px;min-width:160px">
          <div class="stat-icon" style="background:#eff6ff">🎯</div>
          <div class="stat-val" style="font-size:28px">
            <?= $totalArchTarget > 0 ? round($totalArchPairs/$totalArchTarget*100) : 0 ?>%
          </div>
          <div class="stat-label">Overall Completion</div>
          <div class="stat-accent" style="background:var(--blue-light)"></div>
          <div class="prog" style="margin-top:8px">
            <div class="prog-fill" style="width:<?php echo $totalArchTarget > 0 ? min(100, round($totalArchPairs/$totalArchTarget*100)) : 0; ?>%"></div>
          </div>
        </div>
      </div>

      <!-- Archive cards -->
      <?php foreach($archivedOrders as $ao):
        $aTarget    = (int)$ao['target_pairs'];
        $aCompleted = (int)$ao['total_completed'];
        $aPct       = $aTarget > 0 ? min(100, round($aCompleted/$aTarget*100)) : 0;
        $aPctColor  = $aPct >= 100 ? '#22c55e' : ($aPct >= 75 ? '#f59e0b' : '#dc2626');

        // Per-stage totals for this archived order
        $archStages = $pdo->prepare("
            SELECT s.name, COALESCE(SUM(do.qty_produced),0) as total
            FROM stages s
            LEFT JOIN daily_outputs do
                ON do.stage_id=s.id
                AND do.order_size_id IN (SELECT id FROM order_sizes WHERE order_id=?)
            GROUP BY s.id, s.name ORDER BY s.sort_order
        ");
        $archStages->execute([$ao['id']]);
        $archStages = $archStages->fetchAll();

        // Sizes for this order
        $archSizes = $pdo->prepare("SELECT * FROM order_sizes WHERE order_id=? ORDER BY sort_order");
        $archSizes->execute([$ao['id']]);
        $archSizes = $archSizes->fetchAll();

        // Daily output history (last 14 days of this order)
        $archDaily = $pdo->prepare("SELECT log_date, total_pairs FROM daily_order_totals WHERE order_id=? ORDER BY log_date ASC LIMIT 30");
        $archDaily->execute([$ao['id']]);
        $archDaily = $archDaily->fetchAll();

        $archCreated  = $ao['created_at'] ? date('M j, Y', strtotime($ao['created_at'])) : '—';
        $archDeadline = $ao['deadline']   ? date('M j, Y', strtotime($ao['deadline']))    : '—';
      ?>
      <div class="panel" data-archive-id="<?= $ao['id'] ?>" style="margin-bottom:28px;border-left:4px solid var(--blue-light)">
        <!-- Header -->
        <div class="panel-head" style="background:linear-gradient(90deg,#122a6b,#1e47b0);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
          <div>
            <div class="panel-title" style="font-size:15px">📦 <?= htmlspecialchars($ao['order_code']) ?></div>
            <div style="font-size:11px;color:rgba(255,255,255,.6);margin-top:2px;font-weight:500">
              Created: <?= $archCreated ?> &nbsp;·&nbsp; Deadline was: <?= $archDeadline ?>
              <?php if($ao['created_by_name']): ?> &nbsp;·&nbsp; By: <?= htmlspecialchars($ao['created_by_name']) ?><?php endif; ?>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span style="background:rgba(255,255,255,.15);color:#fff;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:800">
              <?= $aPct ?>% complete
            </span>
            <span style="background:<?= $aPct>=100?'#22c55e':'rgba(255,255,255,.1)' ?>;color:#fff;padding:5px 14px;border-radius:20px;font-size:11px;font-weight:700">
              <?= $aPct>=100 ? '✅ TARGET MET' : '⚠️ NOT MET' ?>
            </span>
            <!-- Action buttons -->
            <button type="button"
              onclick="printArchive(<?= $ao['id'] ?>)"
              style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;cursor:pointer">
              🖨️ Print
            </button>
            <button type="button"
              onclick="saveArchiveCSV(<?= $ao['id'] ?>, '<?= htmlspecialchars(addslashes($ao['order_code'])) ?>')"
              style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;cursor:pointer">
              💾 Save CSV
            </button>
            <button type="button"
              onclick="deleteArchive(<?= $ao['id'] ?>, '<?= htmlspecialchars(addslashes($ao['order_code'])) ?>')"
              style="background:rgba(220,38,38,.5);border:1px solid rgba(220,38,38,.7);color:#fff;padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;cursor:pointer">
              🗑️ Delete
            </button>
          </div>
        </div>

        <div style="padding:20px">

          <!-- Stat row -->
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px">
            <div class="arch-stat-box" style="background:#f5f8ff;border-radius:10px;padding:14px;border:1px solid var(--border);text-align:center">
              <div class="arch-stat-lbl" style="font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px">Target</div>
              <div class="arch-stat-val" style="font-size:24px;font-weight:900;color:var(--blue-dark);font-family:'Barlow Condensed',sans-serif"><?= number_format($aTarget) ?></div>
              <div style="font-size:10px;color:var(--text2)">pairs</div>
            </div>
            <div class="arch-stat-box" style="background:#f0fdf4;border-radius:10px;padding:14px;border:1px solid #bbf7d0;text-align:center">
              <div class="arch-stat-lbl" style="font-size:11px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px">Completed</div>
              <div class="arch-stat-val" style="font-size:24px;font-weight:900;color:#166534;font-family:'Barlow Condensed',sans-serif"><?= number_format($aCompleted) ?></div>
              <div style="font-size:10px;color:#166534">pairs finished</div>
            </div>
            <div class="arch-stat-box" style="background:#fff;border-radius:10px;padding:14px;border:1px solid var(--border);text-align:center">
              <div class="arch-stat-lbl" style="font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px">Completion</div>
              <div class="arch-stat-val" style="font-size:24px;font-weight:900;font-family:'Barlow Condensed',sans-serif;color:<?= $aPctColor ?>"><?= $aPct ?>%</div>
              <div class="prog" style="margin-top:6px"><div class="prog-fill" style="width:<?= $aPct ?>%"></div></div>
            </div>
            <div class="arch-stat-box" style="background:#fff;border-radius:10px;padding:14px;border:1px solid var(--border);text-align:center">
              <div class="arch-stat-lbl" style="font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px">Active Days</div>
              <div class="arch-stat-val" style="font-size:24px;font-weight:900;color:var(--blue-dark);font-family:'Barlow Condensed',sans-serif"><?= (int)$ao['active_days'] ?></div>
              <div style="font-size:10px;color:var(--text2)">days with output</div>
            </div>
          </div>

          <!-- Output by stage -->
          <?php if(!empty($archStages)): ?>
          <div style="margin-bottom:18px">
            <div style="font-size:11px;font-weight:800;color:var(--blue-dark);text-transform:uppercase;letter-spacing:1px;margin-bottom:10px">📊 Total Output by Stage</div>
            <div style="display:grid;grid-template-columns:repeat(<?= count($archStages) ?>,1fr);gap:10px">
              <?php foreach($archStages as $as): ?>
              <div style="background:#f5f8ff;border:1px solid var(--border);border-radius:8px;padding:12px;text-align:center">
                <div style="font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px"><?= htmlspecialchars($as['name']) ?></div>
                <div style="font-size:20px;font-weight:900;color:<?php echo $as['total'] > 0 ? '#166534' : '#9ca3af'; ?>;font-family:'Barlow Condensed',sans-serif"><?= number_format($as['total']) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- Sizes breakdown -->
          <?php if(!empty($archSizes)): ?>
          <details style="margin-bottom:14px">
            <summary style="cursor:pointer;font-size:11px;font-weight:800;color:var(--blue-dark);text-transform:uppercase;letter-spacing:1px;padding:8px 0;user-select:none">
              👟 Size Breakdown (<?= count($archSizes) ?> sizes) — click to expand
            </summary>
            <div style="margin-top:10px;overflow-x:auto">
              <table>
                <tr>
                  <th>#</th><th>Size</th><th>Target Qty</th>
                  <?php foreach($archStages as $as): ?><th style="text-align:center"><?= htmlspecialchars($as['name']) ?></th><?php endforeach; ?>
                </tr>
                <?php foreach($archSizes as $si => $sz):
                  // Per-size, per-stage output
                  $szStages = $pdo->prepare("
                      SELECT s.name, COALESCE(SUM(do.qty_produced),0) as total
                      FROM stages s
                      LEFT JOIN daily_outputs do ON do.stage_id=s.id AND do.order_size_id=?
                      GROUP BY s.id, s.name ORDER BY s.sort_order
                  ");
                  $szStages->execute([$sz['id']]);
                  $szStages = $szStages->fetchAll();
                  $szStageMap = array_column($szStages, 'total', 'name');
                ?>
                <tr>
                  <td style="color:var(--text2);font-weight:700"><?= $si+1 ?></td>
                  <td><span class="badge badge-orange"><?= htmlspecialchars($sz['size_label']) ?></span></td>
                  <td style="font-weight:700"><?= number_format($sz['target_qty']) ?></td>
                  <?php foreach($archStages as $as):
                    $szVal   = $szStageMap[$as['name']] ?? 0;
                    $szColor = ($szVal > 0) ? '#166534' : '#9ca3af';
                  ?>
                  <td style="text-align:center;font-weight:700;color:<?= $szColor ?>">
                    <?= number_format($szStageMap[$as['name']] ?? 0) ?>
                  </td>
                  <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
              </table>
            </div>
          </details>
          <?php endif; ?>

          <!-- Daily output sparkline -->
          <?php if(!empty($archDaily)): ?>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--blue-dark);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">📈 Daily Output History</div>
            <canvas id="archBar_<?= $ao['id'] ?>" style="width:100%;display:block" height="100"></canvas>
            <script>
            (function(){
              var data   = <?= json_encode(array_column($archDaily,'total_pairs')) ?>;
              var labels = <?= json_encode(array_map(fn($d)=>date('d/m',strtotime($d['log_date'])),$archDaily)) ?>;
              var canvas = document.getElementById('archBar_<?= $ao['id'] ?>');
              if(!canvas) return;
              var dpr=window.devicePixelRatio||1, W=canvas.parentNode.offsetWidth, H=100;
              canvas.width=W*dpr; canvas.height=H*dpr;
              canvas.style.width=W+'px'; canvas.style.height=H+'px';
              var ctx=canvas.getContext('2d'); ctx.scale(dpr,dpr);
              var n=data.length||1, maxV=Math.max.apply(null,data.concat([1]));
              var padL=32,padR=8,padT=10,padB=28,chartW=W-padL-padR,chartH=H-padT-padB;
              var barW=chartW/n, gap=barW*.2, bw=barW-gap;
              ctx.strokeStyle='#e8eef8'; ctx.lineWidth=1; ctx.setLineDash([3,3]);
              [0,.5,1].forEach(function(g){
                var gy=padT+chartH-g*chartH;
                ctx.beginPath(); ctx.moveTo(padL,gy); ctx.lineTo(padL+chartW,gy); ctx.stroke();
                ctx.setLineDash([]); ctx.fillStyle='#9ca3af'; ctx.font='9px Barlow,sans-serif';
                ctx.textAlign='right'; ctx.fillText(Math.round(g*maxV),padL-4,gy+3);
                ctx.setLineDash([3,3]);
              });
              ctx.setLineDash([]);
              data.forEach(function(v,i){
                var barH=Math.max(2,(v/maxV)*chartH), x=padL+i*barW+gap/2, y=padT+chartH-barH;
                ctx.fillStyle='#2563eb';
                if(ctx.roundRect) ctx.roundRect(x,y,bw,barH,[3,3,0,0]);
                else ctx.rect(x,y,bw,barH);
                ctx.fill();
                if(data.length<=20){
                  ctx.fillStyle='#4a5b8a'; ctx.font='8px Barlow,sans-serif';
                  ctx.textAlign='center'; ctx.fillText(labels[i],x+bw/2,H-padB+12);
                }
              });
              ctx.strokeStyle='#c8d5f0'; ctx.lineWidth=1.5;
              ctx.beginPath(); ctx.moveTo(padL,padT+chartH); ctx.lineTo(padL+chartW,padT+chartH); ctx.stroke();
            })();
            </script>
          </div>
          <?php endif; ?>

        </div><!-- end padding -->
      </div><!-- end panel -->
      <?php endforeach; ?>

    <!-- Hidden delete form -->
    <form method="POST" id="deleteArchiveForm" style="display:none">
      <input type="hidden" name="action" value="delete_archive">
      <input type="hidden" name="archive_id" id="deleteArchiveId">
    </form>

    <?php endif; ?>
  </div><!-- end page-archives -->

<script>
// ---- ARCHIVE: DELETE ----
function deleteArchive(id, code) {
  if (!confirm('⚠️ Permanently delete archive "' + code + '"?\n\nThis will remove ALL output data for this order. This cannot be undone.')) return;
  document.getElementById('deleteArchiveId').value = id;
  document.getElementById('deleteArchiveForm').submit();
}

// ---- ARCHIVE: PRINT ----
function printArchive(id) {
  var card = document.querySelector('[data-archive-id="' + id + '"]');
  if (!card) return;
  var w = window.open('', '_blank', 'width=900,height=700');
  w.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Archive Report</title>'
    + '<style>'
    + 'body{font-family:Arial,sans-serif;color:#0d1e52;margin:0;padding:20px;font-size:13px}'
    + 'h1{font-size:20px;font-weight:900;color:#122a6b;border-bottom:3px solid #1a3a8f;padding-bottom:8px;margin-bottom:4px}'
    + '.meta{font-size:11px;color:#4a5b8a;margin-bottom:18px}'
    + '.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}'
    + '.box{border:1px solid #c8d5f0;border-radius:8px;padding:12px;text-align:center;background:#f5f8ff}'
    + '.bv{font-size:22px;font-weight:900;color:#122a6b}'
    + '.bl{font-size:10px;color:#4a5b8a;text-transform:uppercase;letter-spacing:1px;margin-top:2px}'
    + '.panel{border:1px solid #c8d5f0;border-radius:8px;overflow:hidden;margin-bottom:16px}'
    + '.ph{background:#1a3a8f;color:#fff;padding:8px 14px;font-size:11px;font-weight:800;text-transform:uppercase}'
    + 'table{width:100%;border-collapse:collapse}'
    + 'th{background:#e8eef8;padding:7px 10px;text-align:left;font-size:11px;font-weight:700;color:#122a6b;border-bottom:2px solid #c8d5f0}'
    + 'td{padding:7px 10px;font-size:12px;border-bottom:1px solid #edf1fb;text-align:center}'
    + '@media print{@page{margin:1.5cm}button{display:none}}'
    + '</style></head><body>');
  w.document.write(card.innerHTML);
  w.document.write('</body></html>');
  w.document.close();
  w.focus();
  setTimeout(function(){ w.print(); }, 600);
}

// ---- ARCHIVE: SAVE CSV ----
function saveArchiveCSV(id, code) {
  var card = document.querySelector('[data-archive-id="' + id + '"]');
  if (!card) return;
  var rows = [['Order', 'Stage/Size', 'Value']];
  // Stat boxes
  card.querySelectorAll('.arch-stat-box').forEach(function(box) {
    var lbl = box.querySelector('.arch-stat-lbl') ? box.querySelector('.arch-stat-lbl').textContent.trim() : '';
    var val = box.querySelector('.arch-stat-val') ? box.querySelector('.arch-stat-val').textContent.trim() : '';
    if (lbl && val) rows.push([code, lbl, val]);
  });
  // Tables
  card.querySelectorAll('table').forEach(function(tbl) {
    var headers = [];
    tbl.querySelectorAll('tr:first-child th').forEach(function(th){ headers.push('"'+th.textContent.trim()+'"'); });
    if (headers.length) rows.push(headers.join(','));
    tbl.querySelectorAll('tr:not(:first-child)').forEach(function(tr) {
      var cells = [];
      tr.querySelectorAll('td').forEach(function(td){ cells.push('"'+td.textContent.trim()+'"'); });
      if (cells.length) rows.push(cells.join(','));
    });
  });
  var csv = rows.map(function(r){ return Array.isArray(r) ? r.map(function(c){ return '"'+String(c).replace(/"/g,'""')+'"'; }).join(',') : r; }).join('\n');
  var blob = new Blob(['\uFEFF' + csv], {type:'text/csv;charset=utf-8'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'Archive-' + code + '.csv';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
}
</script>

</div><!-- end .main -->

<!-- EDIT SIZE MODAL -->
<div class="reset-modal-bg" id="editSizeModal">
  <div class="reset-modal" style="border-top-color:var(--blue)">
    <h3 style="color:var(--blue-dark)">✏️ Edit Size Variant</h3>
    <p class="rm-sub">Update the size label and target quantity below.</p>
    <form method="POST" action="dashboard.php?page=orders">
      <input type="hidden" name="action" value="edit_size">
      <input type="hidden" name="sid" id="editSizeId">
      <label>Size Label</label>
      <input type="text" name="size_label" id="editSizeLabel" placeholder="e.g. 8us (42uk)" required>
      <label>Target Quantity</label>
      <input type="number" name="target_qty" id="editSizeQty" min="1" required>
      <div class="rm-btns">
        <button type="submit" class="rm-confirm" style="background:var(--blue)">💾 Save Changes</button>
        <button type="button" class="rm-cancel" onclick="document.getElementById('editSizeModal').classList.remove('open')">Cancel</button>
      </div>
    </form>
  </div>
</div>


<!-- RESET PROJECT MODAL -->
<div class="reset-modal-bg" id="resetModal">
  <div class="reset-modal">
    <h3>🔄 New Project Reset</h3>
    <p class="rm-sub">Start a fresh production order. The current order will be archived and all output data will be preserved for records.</p>
    <div class="rm-warn">
      ⚠️ <span>This action is irreversible. The current active order will be <strong>archived</strong> and production outputs will reset for the new project.</span>
    </div>
    <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;padding:10px 14px;margin-top:10px;font-size:12px;color:#166534;font-weight:600;display:flex;align-items:flex-start;gap:8px">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" style="flex-shrink:0;margin-top:1px"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <span><strong>Inventory is NOT affected.</strong> All materials, stock levels, and inventory data will remain intact after the reset.</span>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="reset_project">
      <label>New Order Code</label>
      <input type="text" name="new_order_code" placeholder="e.g. ORD-2026-B" value="ORD-<?= date('Ymd') ?>-<?= chr(65 + (intval(date('His')) % 26)) ?>" required>
      <label>New Target Pairs</label>
      <input type="number" name="new_target_pairs" min="1" placeholder="e.g. 500" required>
      <label>New Deadline</label>
      <input type="date" name="new_deadline" value="<?php
        // Default deadline = 30 working days (Mon–Fri, excl. PH holidays) from today
        $wdCount = 0;
        $wdCur   = new DateTime();
        $wdYear  = (int)$wdCur->format('Y');
        $wdHols  = array_flip(array_merge(getPHHolidays($wdYear), getPHHolidays($wdYear + 1)));
        while ($wdCount < 30) {
            $wdCur->modify('+1 day');
            $dow = (int)$wdCur->format('N');
            $ymd = $wdCur->format('Y-m-d');
            if ($dow < 6 && !isset($wdHols[$ymd])) $wdCount++;
        }
        echo $wdCur->format('Y-m-d');
      ?>" required>
      <div class="rm-btns">
        <button type="submit" class="rm-confirm" id="resetConfirmBtn">
          ✓ Confirm Reset
        </button>
        <button type="button" class="rm-cancel" onclick="document.getElementById('resetModal').classList.remove('open')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function previewModel(input) {
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function(e) {
      document.getElementById('modelPreviewImg').src = e.target.result;
      document.getElementById('modelSaveBtn').style.display = 'inline-block';
    };
    reader.readAsDataURL(input.files[0]);
  }
}

function showPage(id, btn) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.sb-link').forEach(b => b.classList.remove('active'));
  var target = document.getElementById('page-' + id);
  if (!target) return;
  target.classList.add('active');
  if (btn) btn.classList.add('active');
  // Persist current page in BOTH hash and sessionStorage so refresh restores it
  try { sessionStorage.setItem('dashPage', id); } catch(e){}
  history.replaceState(null, '', window.location.pathname + window.location.search.replace(/[?&]page=[^&]*/g,'').replace(/^&/,'?') + '#' + id);
}

// Close reset modal on backdrop click or Escape
document.getElementById('resetModal').addEventListener('click', function(e){
  if(e.target===this) this.classList.remove('open');
});
document.addEventListener('keydown', function(e){
  if(e.key==='Escape') {
    document.getElementById('resetModal').classList.remove('open');
    document.getElementById('editSizeModal').classList.remove('open');
    // Close any open inline edit forms
    ['TargetPairs'].forEach(function(id){
      var v = document.getElementById('view'+id);
      var ed = document.getElementById('edit'+id);
      if (v && ed && ed.style.display !== 'none') { ed.style.display='none'; v.style.display=''; }
    });
  }
});

// Fix reset confirm button — two-step confirmation
document.getElementById('resetConfirmBtn').addEventListener('click', function(e){
  if (!this.dataset.confirmed) {
    e.preventDefault();
    this.textContent = '⚠️ Click again to confirm!';
    this.style.background = '#f59e0b';
    this.dataset.confirmed = '1';
    setTimeout(() => { this.textContent = '✓ Confirm Reset'; this.style.background=''; delete this.dataset.confirmed; }, 4000);
  }
  // second click: let the form submit normally
});

// Inline edit toggle for stat cards
function toggleStatEdit(cardId) {
  var view = document.getElementById('view' + cardId);
  var edit = document.getElementById('edit' + cardId);
  if (!view || !edit) return;
  var isOpen = edit.style.display !== 'none';
  if (isOpen) {
    edit.style.display = 'none';
    view.style.display = '';
  } else {
    edit.style.display = '';
    view.style.display = 'none';
    var firstInput = edit.querySelector('input[type="number"],input[type="date"]');
    if (firstInput) setTimeout(function(){ firstInput.select(); }, 60);
  }
}

// ---- PRINT ----
function printDashboard() {
  window.print();
}

// ---- SAVE AS PDF using browser print dialog ----
function saveDashboardAsPDF() {
  var now = new Date().toLocaleString();

  // --- Stat cards ---
  var statCards = '';
  document.querySelectorAll('#page-dashboard .stat-grid .stat, #page-dashboard .stat-grid .stat-model').forEach(function(card){
    var val = card.querySelector('.stat-val') ? card.querySelector('.stat-val').textContent.trim() : '';
    var lbl = card.querySelector('.stat-label') ? card.querySelector('.stat-label').textContent.trim() : '';
    var sub = card.querySelector('[style*="font-size:11px"]') ? card.querySelector('[style*="font-size:11px"]').textContent.trim() : '';
    if (!val && !lbl) {
      var modelName = card.querySelector('.stat-model-name') ? card.querySelector('.stat-model-name').textContent.trim() : '';
      statCards += '<div class="sc"><div class="sv">👟</div><div class="sl">Running Model</div><div class="ss">'+modelName+'</div></div>';
    } else {
      statCards += '<div class="sc"><div class="sv">'+val+'</div><div class="sl">'+lbl+'</div>'+(sub?'<div class="ss">'+sub+'</div>':'')+'</div>';
    }
  });

  // --- Today's Output by Stage (first panel table only) ---
  var stageTable = '';
  var firstPanel = document.querySelector('#page-dashboard .panel table');
  if (firstPanel) {
    var rows = firstPanel.querySelectorAll('tr');
    stageTable = '<table>';
    rows.forEach(function(row) {
      stageTable += '<tr>';
      row.querySelectorAll('th, td').forEach(function(cell) {
        var tag = cell.tagName.toLowerCase();
        stageTable += '<'+tag+'>'+cell.textContent.trim()+'</'+tag+'>';
      });
      stageTable += '</tr>';
    });
    stageTable += '</table>';
  }

  // --- Output Consistency chart data ---
  var consistencySection = '';
  var chartCanvas = document.getElementById('dashBarChart');
  if (chartCanvas) {
    try {
      var chartImg = chartCanvas.toDataURL('image/png');
      consistencySection = '<div class="panel"><div class="ph">📈 Output Consistency – Daily (Last 14 Days)</div>'
        + '<div style="padding:12px"><img src="'+chartImg+'" style="width:100%;max-height:220px;object-fit:contain"></div></div>';
    } catch(e) { consistencySection = ''; }
  }

  // --- Recent Output Entries (third panel) ---
  var recentRows = '';
  var panels = document.querySelectorAll('#page-dashboard .panel');
  var recentPanel = panels[2]; // 0=stage, 1=consistency chart wrap, 2=recent entries
  if (recentPanel) {
    var rows = recentPanel.querySelectorAll('table tr');
    rows.forEach(function(row){
      recentRows += '<tr>';
      row.querySelectorAll('th,td').forEach(function(cell){
        var tag = cell.tagName.toLowerCase();
        recentRows += '<'+tag+'>'+cell.textContent.trim()+'</'+tag+'>';
      });
      recentRows += '</tr>';
    });
  }

  var html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Dashboard Report</title>'
    + '<style>'
    + 'body{font-family:Arial,sans-serif;color:#0d1e52;margin:0;padding:20px;font-size:13px}'
    + 'h1{font-size:22px;font-weight:900;color:#122a6b;border-bottom:3px solid #1a3a8f;padding-bottom:8px;margin-bottom:16px}'
    + '.meta{font-size:11px;color:#4a5b8a;margin-bottom:20px}'
    + '.sg{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px}'
    + '.sc{border:1px solid #c8d5f0;border-radius:8px;padding:12px;text-align:center;background:#f5f8ff}'
    + '.sv{font-size:28px;font-weight:900;color:#122a6b}'
    + '.sl{font-size:10px;font-weight:700;text-transform:uppercase;color:#4a5b8a;letter-spacing:1px;margin-top:2px}'
    + '.ss{font-size:10px;color:#4a5b8a;margin-top:3px}'
    + '.panel{border:1px solid #c8d5f0;border-radius:8px;overflow:hidden;margin-bottom:18px}'
    + '.ph{background:#1a3a8f;color:#fff;padding:10px 14px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:1px}'
    + 'table{width:100%;border-collapse:collapse}'
    + 'th{background:#e8eef8;padding:8px 10px;text-align:left;font-size:11px;font-weight:700;color:#122a6b;text-transform:uppercase;border-bottom:2px solid #c8d5f0}'
    + 'td{padding:8px 10px;font-size:12px;border-bottom:1px solid #edf1fb}'
    + '@media print{@page{margin:1.5cm}}'
    + '</style></head><body>'
    + '<h1>📊 Admin Dashboard Report</h1>'
    + '<div class="meta">Generated: '+now+' &nbsp;|&nbsp; Quilla Production System</div>'
    + '<div class="sg">'+statCards+'</div>'
    + '<div class="panel"><div class="ph">📦 Today\'s Output by Stage</div>'+stageTable+'</div>'
    + consistencySection
    + '<div class="panel"><div class="ph">🕒 Recent Output Entries</div><table>'+recentRows+'</table></div>'
    + '</body></html>';

  var w = window.open('', '_blank', 'width=900,height=700');
  w.document.write(html);
  w.document.close();
  w.focus();
  setTimeout(function(){ w.print(); }, 800);
}

// ---- SAVE AS DOC (HTML download that Word can open) ----
function saveDashboardAsDoc() {
  var now = new Date();
  var dateStr = now.toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});
  var timeStr = now.toLocaleTimeString('en-US');

  // --- Stat cards ---
  var statsHtml = '';
  document.querySelectorAll('#page-dashboard .stat').forEach(function(card){
    var val = card.querySelector('.stat-val') ? card.querySelector('.stat-val').textContent.trim() : '';
    var lbl = card.querySelector('.stat-label') ? card.querySelector('.stat-label').textContent.trim() : '';
    var sub = card.querySelector('[style*="font-size:11px"]') ? card.querySelector('[style*="font-size:11px"]').textContent.trim() : '';
    if (val && lbl) statsHtml += '<td><div class="stat-val">'+val+'</div><div class="stat-lbl">'+lbl+'</div>'+(sub?'<div style="font-size:8pt;color:#4a5b8a">'+sub+'</div>':'')+'</td>';
  });
  var modelName = document.querySelector('#page-dashboard .stat-model-name');
  if (modelName) statsHtml += '<td><div class="stat-val">👟</div><div class="stat-lbl">Running Model</div><div style="font-size:8pt;color:#4a5b8a">'+modelName.textContent.trim()+'</div></td>';

  // --- Today's Output by Stage ---
  var stageRows = '';
  var firstTable = document.querySelector('#page-dashboard .panel table');
  if (firstTable) {
    firstTable.querySelectorAll('tr').forEach(function(row, i) {
      stageRows += '<tr>';
      row.querySelectorAll('th,td').forEach(function(cell) {
        var txt = cell.textContent.trim();
        if (i === 0) {
          stageRows += '<th style="background:#1a3a8f;color:#fff;padding:8pt;text-align:center;font-size:10pt">'+txt+'</th>';
        } else {
          var num = parseInt(txt);
          stageRows += '<td style="text-align:center;font-weight:bold;font-size:14pt;padding:6pt;color:'+(num>0?'#166534':'#9ca3af')+'">'+txt+'</td>';
        }
      });
      stageRows += '</tr>';
    });
  }

  // --- Bar chart as image ---
  var chartImgHtml = '';
  var chartCanvas = document.getElementById('dashBar');
  if (chartCanvas) {
    try {
      var chartImg = chartCanvas.toDataURL('image/png');
      chartImgHtml = '<img src="'+chartImg+'" style="width:100%;max-height:250px;display:block;margin:8pt 0">';
    } catch(e) { chartImgHtml = ''; }
  }

  // --- Recent Output Entries ---
  var recentRows = '';
  var panels = document.querySelectorAll('#page-dashboard .panel');
  var recentPanel = panels[2];
  if (recentPanel) {
    recentPanel.querySelectorAll('table tr').forEach(function(row, i){
      recentRows += '<tr>';
      row.querySelectorAll('th,td').forEach(function(cell){
        recentRows += i===0
          ? '<th>'+cell.textContent.trim()+'</th>'
          : '<td>'+cell.textContent.trim()+'</td>';
      });
      recentRows += '</tr>';
    });
  }

  var docContent = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">'
    + '<head><meta charset="UTF-8"><title>Dashboard Report</title>'
    + '<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>90</w:Zoom><w:DoNotOptimizeForBrowser/></w:WordDocument></xml><![endif]-->'
    + '<style>body{font-family:Calibri,Arial,sans-serif;color:#0d1e52;font-size:11pt;margin:0}'
    + 'h1{font-size:20pt;font-weight:bold;color:#122a6b;border-bottom:2pt solid #1a3a8f;padding-bottom:6pt;margin-bottom:12pt}'
    + 'h2{font-size:13pt;font-weight:bold;color:#fff;background:#1a3a8f;padding:6pt 10pt;margin:14pt 0 0 0}'
    + '.meta{font-size:9pt;color:#4a5b8a;margin-bottom:18pt}'
    + 'table{width:100%;border-collapse:collapse;margin-bottom:18pt}'
    + 'th{background:#e8eef8;border:1pt solid #c8d5f0;padding:6pt;font-size:9pt;font-weight:bold;text-align:left}'
    + 'td{border:1pt solid #c8d5f0;padding:6pt;font-size:10pt}'
    + '.stat-table td{text-align:center;width:20%;border:1pt solid #c8d5f0}'
    + '.stat-val{font-size:22pt;font-weight:bold;color:#122a6b}'
    + '.stat-lbl{font-size:8pt;color:#4a5b8a;text-transform:uppercase}'
    + '@page Section1{size:A4;margin:2cm}div.Section1{page:Section1}'
    + '</style></head><body><div class="Section1">'
    + '<h1>&#128202; Admin Dashboard Report</h1>'
    + '<div class="meta"><strong>Generated:</strong> '+dateStr+' at '+timeStr+'<br><strong>System:</strong> Quilla Production Management</div>'
    + '<h2>&#127919; Production Summary</h2>'
    + '<table class="stat-table"><tr>'+statsHtml+'</tr></table>'
    + '<h2>&#128230; Today\'s Output by Stage</h2>'
    + '<table><tbody>'+stageRows+'</tbody></table>'
    + '<h2>&#128202; Output Consistency – Daily (Last 14 Days)</h2>'
    + '<div style="border:1pt solid #c8d5f0;padding:8pt;margin-bottom:18pt">'+chartImgHtml+'</div>'
    + '<h2>&#128336; Recent Output Entries</h2>'
    + '<table><tbody>'+recentRows+'</tbody></table>'
    + '</div></body></html>';

  var blob = new Blob(['\ufeff', docContent], { type: 'application/msword' });
  var url  = URL.createObjectURL(blob);
  var a    = document.createElement('a');
  a.href   = url;
  a.download = 'Dashboard-Report-' + now.toISOString().slice(0,10) + '.doc';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

// Edit size modal
function openEditSize(id, label, qty) {
  document.getElementById('editSizeId').value    = id;
  document.getElementById('editSizeLabel').value = label;
  document.getElementById('editSizeQty').value   = qty;
  document.getElementById('editSizeModal').classList.add('open');
}
document.getElementById('editSizeModal').addEventListener('click', function(e){
  if(e.target===this) this.classList.remove('open');
});

// ── PAGE RESTORE ON REFRESH / POST REDIRECT ─────────────────────────────
// Priority: 1) ?page= (POST redirect) → 2) #hash → 3) sessionStorage
(function () {
  var validPages = ['dashboard','outputs','orders','users','announce','confirmers','stages','archives'];

  function activatePage(id) {
    var target = document.getElementById('page-' + id);
    if (!target) return false;
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.sb-link').forEach(b => b.classList.remove('active'));
    target.classList.add('active');
    document.querySelectorAll('.sb-link').forEach(function (b) {
      var oc = b.getAttribute('onclick') || '';
      if (oc.indexOf("'" + id + "'") !== -1) b.classList.add('active');
    });
    try { sessionStorage.setItem('dashPage', id); } catch(e){}
    return true;
  }

  // 1. ?page= from POST redirect (highest priority — server told us where to go)
  var params = new URLSearchParams(window.location.search);
  var fromPost = params.get('page');
  if (fromPost && validPages.indexOf(fromPost) !== -1) {
    activatePage(fromPost);
    // Clean URL: keep query params except ?page=, set hash
    var cleanSearch = window.location.search.replace(/[?&]page=[^&]*/g,'').replace(/^&/,'?');
    history.replaceState(null, '', window.location.pathname + cleanSearch + '#' + fromPost);
    return;
  }

  // 2. Hash in URL (user navigated and refreshed)
  var fromHash = (window.location.hash || '').replace('#','');
  if (fromHash && validPages.indexOf(fromHash) !== -1) {
    activatePage(fromHash);
    return;
  }

  // 3. sessionStorage fallback (hash was cleared somehow)
  try {
    var fromStorage = sessionStorage.getItem('dashPage');
    if (fromStorage && validPages.indexOf(fromStorage) !== -1) {
      activatePage(fromStorage);
      history.replaceState(null, '', window.location.pathname + '#' + fromStorage);
      return;
    }
  } catch(e){}

  // Default: stay on dashboard
  activatePage('dashboard');
})();</script>
<!-- EDIT USER CREDENTIALS MODAL -->
<div id="editUserModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;padding:30px;width:100%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <h3 style="margin:0 0 20px;font-family:'Barlow Condensed',sans-serif;font-size:20px;color:#1e3a8a">✏️ Edit User Credentials</h3>
    <form method="POST">
      <input type="hidden" name="action" value="edit_user">
      <input type="hidden" name="uid" id="editUid">

      <div style="margin-bottom:14px">
        <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#64748b;display:block;margin-bottom:5px">Full Name</label>
        <input type="text" name="full_name" id="editFullName" required
          style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:9px 12px;font-size:14px;box-sizing:border-box;outline:none">
      </div>

      <div style="margin-bottom:14px">
        <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#64748b;display:block;margin-bottom:5px">Username</label>
        <input type="text" name="username" id="editUsername" required
          style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:9px 12px;font-size:14px;font-family:monospace;box-sizing:border-box;outline:none">
      </div>

      <div style="margin-bottom:14px">
        <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#64748b;display:block;margin-bottom:5px">Role</label>
        <select name="role" id="editRole"
          style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:9px 12px;font-size:14px;box-sizing:border-box;outline:none;background:#fff">
          <option value="supervisor">Production</option>
          <option value="admin">Admin</option>
        </select>
      </div>

      <div style="margin-bottom:20px">
        <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#64748b;display:block;margin-bottom:5px">
          New Password <span style="color:#94a3b8;font-weight:400;text-transform:none">(leave blank to keep current)</span>
        </label>
        <input type="password" name="password" id="editPassword" placeholder="Enter new password…"
          style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:9px 12px;font-size:14px;box-sizing:border-box;outline:none">
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button type="button" onclick="closeEditUser()"
          style="background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b;font-size:13px;font-weight:700;padding:9px 20px;border-radius:8px;cursor:pointer">
          Cancel
        </button>
        <button type="submit"
          style="background:linear-gradient(90deg,#1e3a8a,#2563eb);border:none;color:#fff;font-size:13px;font-weight:700;padding:9px 24px;border-radius:8px;cursor:pointer">
          💾 Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditUser(uid, username, fullName, role) {
  document.getElementById('editUid').value      = uid;
  document.getElementById('editUsername').value = username;
  document.getElementById('editFullName').value = fullName;
  document.getElementById('editRole').value     = role;
  document.getElementById('editPassword').value = '';
  var modal = document.getElementById('editUserModal');
  modal.style.display = 'flex';
}
function closeEditUser() {
  document.getElementById('editUserModal').style.display = 'none';
}
document.getElementById('editUserModal').addEventListener('click', function(e) {
  if (e.target === this) closeEditUser();
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeEditUser();
});
</script>

<script>
/* ══════════════════════════════════════════════
   REAL-TIME POLLING — updates every 5 seconds
   ══════════════════════════════════════════════ */
(function() {
  var INTERVAL = 3000;   // poll every 3 s for snappier real-time feel
  var lastTs   = -1;     // -1 forces the very first poll to always apply its data

  // Live dot indicator
  var ind = document.createElement('div');
  ind.style.cssText = 'position:fixed;bottom:18px;right:18px;z-index:9999;display:flex;align-items:center;gap:7px;background:#fff;border:1.5px solid #e0e7ff;border-radius:20px;padding:6px 13px 6px 10px;font-size:12px;font-weight:700;color:#142e75;box-shadow:0 2px 12px rgba(20,46,117,.10);user-select:none;';
  ind.innerHTML = '<span id="dash-pulse" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#22c55e;animation:dashPulse 1.4s infinite"></span> LIVE';
  document.body.appendChild(ind);
  var s = document.createElement('style');
  s.textContent = '@keyframes dashPulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.5)}70%{box-shadow:0 0 0 7px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}} @keyframes dashNewRow{0%,60%{background:#fefce8;box-shadow:inset 0 0 0 2px #f59e0b}100%{background:transparent;box-shadow:none}} @keyframes dashFlash{0%{transform:scale(1.6);box-shadow:0 0 0 0 rgba(34,197,94,.8)}100%{transform:scale(1);box-shadow:0 0 0 10px rgba(34,197,94,0)}}';
  document.head.appendChild(s);

  function fmt(n) { return parseInt(n||0).toString().replace(/\B(?=(\d{3})+(?!\d))/g,','); }
  function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  // Track seen entry keys so new ones get highlighted
  var seenKeys = {};
  document.querySelectorAll('#dash-recent-tbody tr[data-key]').forEach(function(r){
    seenKeys[r.getAttribute('data-key')] = true;
  });

  function buildDashRow(re) {
    var key = (re.size_label||'')+'|'+(re.stage||'')+'|'+(re.log_date||'')+'|'+(re.qty_produced||'')+'|'+(re.action||'add');
    var isNew = !seenKeys[key];
    seenKeys[key] = true;

    // Date/time
    var d = re.log_date ? re.log_date.replace(/-/g,'/') : '';
    var timeStr = '';
    if (re.entered_at) {
      var dt = new Date(re.entered_at.replace(' ','T'));
      if (!isNaN(dt)) {
        var h = dt.getHours(), m = dt.getMinutes(), ap = h>=12?'PM':'AM';
        h = h%12||12;
        timeStr = h+':'+(m<10?'0':'')+m+' '+ap;
      }
    }
    var isMinus = (re.action === 'minus');

    var dateCell = '<div style="font-weight:700">'+esc(d)+'</div>'
      + (timeStr ? '<div style="display:inline-flex;align-items:center;gap:4px;margin-top:3px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:20px;padding:2px 8px">'
        + '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
        + '<span style="font-size:10px;font-weight:700;color:#1d4ed8;letter-spacing:.3px">'+timeStr+'</span></div>' : '');

    var actionHtml = isMinus
      ? '<span style="background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;font-size:10px;font-weight:800;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap">➖ Subtracted</span>'
      : '<span style="background:#f0fdf4;border:1px solid #86efac;color:#16a34a;font-size:10px;font-weight:800;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap">➕ Added</span>';

    var qtyHtml = isMinus
      ? '<strong style="color:#dc2626">−'+esc(re.qty_produced)+'</strong>'
      : '<strong style="color:#16a34a">+'+esc(re.qty_produced)+'</strong>';

    var confirmedHtml = re.confirmed_by
      ? '<span style="display:inline-flex;align-items:center;gap:5px;background:#dcfce7;color:#166534;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;border:1px solid #bbf7d0">'
        + '<span style="width:6px;height:6px;border-radius:50%;background:#22c55e;flex-shrink:0;display:inline-block"></span>'
        + esc(re.confirmed_by)+'</span>'
      : '<span style="display:inline-flex;align-items:center;gap:5px;background:#fef3c7;color:#92400e;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;border:1px solid #fde68a">⚠️ Not confirmed</span>';

    var subtract_reason = re.subtract_reason || '';
    var reasonHtml = (isMinus && subtract_reason)
      ? '<span style="display:inline-flex;align-items:center;gap:5px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:3px 9px;font-size:11px;color:#9a3412;font-weight:600;max-width:160px;word-break:break-word">📝 '+esc(subtract_reason)+'</span>'
      : (isMinus ? '<span style="color:#9ca3af;font-size:12px">—</span>' : '<span style="color:#d1d5db;font-size:11px">—</span>');

    var rowStyle = isNew ? 'animation:dashNewRow 3s ease forwards' : '';
    return '<tr data-key="'+esc(key)+'" style="'+rowStyle+'">'
      + '<td>'+dateCell+'</td>'
      + '<td><span class="badge" style="background:#fff3eb;color:#c2410c">'+esc(re.size_label)+'</span></td>'
      + '<td>'+esc(re.stage)+'</td>'
      + '<td>'+actionHtml+'</td>'
      + '<td>'+qtyHtml+'</td>'
      + '<td>'+esc(re.full_name||re.entered_by_name||'')+'</td>'
      + '<td>'+confirmedHtml+'</td>'
      + '<td>'+reasonHtml+'</td>'
      + '</tr>';
  }

  // Build a row for the Output History (oh-tbody) — matches dashboard.php's existing column layout
  var seenOhKeys = {};
  document.querySelectorAll('#oh-tbody tr[data-key]').forEach(function(r){
    seenOhKeys[r.getAttribute('data-key')] = true;
  });

  function buildOhRow(re, idx, total) {
    var key = (re.size_label||'')+'|'+(re.stage||'')+'|'+(re.log_date||'')+'|'+(re.qty_produced||'')+'|'+(re.action||'add');
    var isMinus = (re.action === 'minus');
    var timeStr = '';
    if (re.entered_at) {
      var dt = new Date(re.entered_at.replace(' ','T'));
      if (!isNaN(dt)) {
        var h = dt.getHours(), m = dt.getMinutes(), ap = h>=12?'PM':'AM';
        h = h%12||12;
        timeStr = h+':'+(m<10?'0':'')+m+' '+ap;
      }
    }
    var subtract_reason = re.subtract_reason || '';
    var dateCell = '<div style="font-weight:700">'+(re.log_date||'')+'</div>'
      + (timeStr ? '<div style="display:inline-flex;align-items:center;gap:4px;margin-top:3px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:20px;padding:2px 8px">'
        + '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
        + '<span style="font-size:10px;font-weight:700;color:#1d4ed8;letter-spacing:.3px">'+timeStr+'</span></div>' : '');

    return '<tr data-key="'+esc(key)+'"'
      +' data-date="'+(re.log_date||'')+'"'
      +' data-month="'+(re.log_date?re.log_date.substring(0,7):'')+'"'
      +' data-stage="'+esc(re.stage||'')+'"'
      +' data-action="'+esc(re.action||'add')+'"'
      +' data-size="'+esc(re.size_label||'')+'">'
      + '<td style="color:var(--text2);font-weight:700;font-size:12px">'+(total-idx)+'</td>'
      + '<td>'+dateCell+'</td>'
      + '<td><span class="badge badge-orange">'+esc(re.size_label)+'</span></td>'
      + '<td><span style="background:#eff6ff;color:var(--blue-dark);padding:3px 10px;border-radius:5px;font-size:11px;font-weight:700;display:inline-block">'+esc(re.stage)+'</span></td>'
      + '<td style="text-align:center">'+(isMinus
          ? '<span style="background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;font-size:10px;font-weight:800;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px">➖ Subtracted</span>'
          : '<span style="background:#f0fdf4;border:1px solid #86efac;color:#16a34a;font-size:10px;font-weight:800;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px">➕ Added</span>')+'</td>'
      + '<td style="text-align:center">'+(isMinus
          ? '<span style="background:#fef2f2;color:#dc2626;font-weight:900;font-size:15px;padding:4px 14px;border-radius:6px;font-family:\'Barlow Condensed\',sans-serif;display:inline-block">−'+fmt(re.qty_produced)+'</span>'
          : '<span style="background:#dcfce7;color:#166534;font-weight:900;font-size:15px;padding:4px 14px;border-radius:6px;font-family:\'Barlow Condensed\',sans-serif;display:inline-block">+'+fmt(re.qty_produced)+'</span>')+'</td>'
      + '<td style="font-size:12px;font-weight:600">'+esc(re.full_name||re.entered_by_name||'')+'</td>'
      + '<td>'+(re.confirmed_by
          ? '<span style="background:#fef9c3;color:#854d0e;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:4px;border:1px solid #fde68a">✅ '+esc(re.confirmed_by)+'</span>'
          : '<span style="color:#9ca3af;font-size:12px">—</span>')+'</td>'
      + '<td>'+(isMinus && subtract_reason
          ? '<span style="display:inline-flex;align-items:center;gap:5px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:4px 10px;font-size:11px;color:#9a3412;font-weight:600;max-width:180px;word-break:break-word">📝 '+esc(subtract_reason)+'</span>'
          : (isMinus ? '<span style="color:#9ca3af;font-size:12px">—</span>' : '<span style="color:#d1d5db;font-size:11px">—</span>'))+'</td>'
      + '</tr>';
  }

  function poll() {
    fetch('/quilla_production/poll.php?_='+Date.now(), {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(data){
        var dot = document.getElementById('dash-pulse');

        if (data.ts === lastTs) {
          // No change — keep dot green and pulsing normally
          if (dot) { dot.style.background = '#22c55e'; }
          return;
        }
        lastTs = data.ts;

        // ── Pairs completed number ───────────────────────────────────
        var tc = document.getElementById('dash-total-completed');
        if (tc) tc.textContent = fmt(data.totalCompleted);

        // ── Progress bars + pct text (both stat cards) ──────────────
        var statGrid   = document.getElementById('dash-stat-grid');
        var targetPairs = statGrid ? parseInt(statGrid.getAttribute('data-target') || '0') : 0;
        if (targetPairs > 0) {
          var pct = Math.min(100, Math.round(data.totalCompleted / targetPairs * 100));

          // Target Pairs card
          var tpFill = document.getElementById('dash-target-prog-fill');
          var tpText = document.getElementById('dash-target-pct');
          var tpFull = document.getElementById('dash-target-prog-text');
          if (tpFill) tpFill.style.width = pct + '%';
          if (tpText) { tpText.textContent = pct + '%'; tpText.style.color = pct >= 100 ? 'var(--green)' : 'var(--blue-light)'; }
          if (tpFull) {
            var inner = fmt(data.totalCompleted) + ' / ' + fmt(targetPairs) + ' · ';
            tpFull.innerHTML = inner + '<span id="dash-target-pct" style="color:' + (pct >= 100 ? 'var(--green)' : 'var(--blue-light)') + ';font-weight:800">' + pct + '%</span>';
          }

          // Pairs Completed card
          var cFill = document.getElementById('dash-completed-prog-fill');
          var cPct  = document.getElementById('dash-completed-pct-text');
          if (cFill) cFill.style.width = pct + '%';
          if (cPct)  cPct.textContent  = pct + '% of target';
        }

        // ── Today by stage (row numbers) ────────────────────────────
        if (data.todayStage) {
          data.todayStage.forEach(function(ts){
            var el = document.getElementById('today-stage-'+ts.id);
            if (!el) return;
            el.textContent = ts.total;
            el.style.color = parseInt(ts.total) > 0 ? '#166634' : '#9ca3af';
          });
        }

        // ── Consistency chart totals + redraw last bar ───────────────
        if (data.stageTotals) {
          document.querySelectorAll('canvas[data-stage-id]').forEach(function(canvas) {
            var sid = canvas.getAttribute('data-stage-id');
            if (!sid || data.stageTotals[sid] === undefined) return;
            var newTotal = parseInt(data.stageTotals[sid] || 0);

            // Update the "X total" badge
            var badge = document.getElementById('stage-chart-total-' + sid);
            if (badge) badge.textContent = fmt(newTotal) + ' total';

            // Update the last bar value in data-vals to today's todayStage value
            if (data.todayStage) {
              var todayVal = 0;
              data.todayStage.forEach(function(ts) { if (String(ts.id) === String(sid)) todayVal = parseInt(ts.total || 0); });
              var vals = JSON.parse(canvas.getAttribute('data-vals') || '[]');
              if (vals.length > 0) {
                vals[vals.length - 1] = todayVal;
                var maxV = Math.max.apply(null, vals.concat([1]));
                canvas.setAttribute('data-vals', JSON.stringify(vals));
                canvas.setAttribute('data-maxv', maxV);
              }
            }
          });
          // Redraw all charts with updated data
          if (typeof drawStageCharts === 'function') drawStageCharts();
        }

        // ── Dashboard recent entries tbody ───────────────────────────
        if (data.recentEntries && data.recentEntries.length) {
          var dashTbody = document.getElementById('dash-recent-tbody');
          if (dashTbody) {
            dashTbody.innerHTML = data.recentEntries.map(buildDashRow).join('');
          }

          // ── Output History tab tbody (oh-tbody) ─────────────────
          var ohTbody = document.getElementById('oh-tbody');
          if (ohTbody) {
            var total = data.recentEntries.length;
            ohTbody.innerHTML = data.recentEntries.map(function(re, i){
              return buildOhRow(re, i, total);
            }).join('');
            // Re-apply any active filters after refresh
            if (typeof ohApplyFilters === 'function') ohApplyFilters();
          }

          // ── Update summary stat row counts ────────────────────────
          var entries    = data.recentEntries;
          var totalAdded = 0, totalSubtracted = 0, uniqueDays = {}, confirmedCount = 0;
          entries.forEach(function(r){
            if ((r.action||'add') === 'add') totalAdded += parseInt(r.qty_produced||0);
            else totalSubtracted += parseInt(r.qty_produced||0);
            if (r.log_date) uniqueDays[r.log_date] = true;
            if (r.confirmed_by) confirmedCount++;
          });
          var elCount = document.getElementById('oh-entries-count');
          var elAdded = document.getElementById('oh-total-added');
          var elSub   = document.getElementById('oh-total-subtracted');
          var elDays  = document.getElementById('oh-unique-days');
          var elConf  = document.getElementById('oh-confirmed-count');
          if (elCount) elCount.textContent = entries.length;
          if (elAdded) elAdded.textContent = '+'+fmt(totalAdded);
          if (elSub)   elSub.textContent   = '−'+fmt(totalSubtracted);
          if (elDays)  elDays.textContent   = Object.keys(uniqueDays).length;
          if (elConf)  elConf.textContent   = confirmedCount;
        }

        // Flash dot to signal data changed
        if (dot) {
          dot.style.background = '#22c55e';
          dot.style.animation  = 'none';
          void dot.offsetWidth;
          dot.style.animation  = 'dashFlash .5s ease, dashPulse 1.4s infinite .5s';
        }
      })
      .catch(function(){
        var dot = document.getElementById('dash-pulse');
        if (dot) dot.style.background = '#dc2626';
      });
  }

  setInterval(poll, INTERVAL);
  setTimeout(poll, 800);
})();
</script>

<!-- ══════════════════════════════════════════════
     EDIT STAGE MODAL
     ══════════════════════════════════════════════ -->
<div id="editStageModal"
  style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;
         align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;padding:30px;width:100%;max-width:440px;
              box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <h3 style="margin:0 0 20px;font-family:'Barlow Condensed',sans-serif;font-size:20px;
               color:#1e3a8a">✏️ Edit Stage</h3>
    <form method="POST">
      <input type="hidden" name="action"   value="edit_stage">
      <input type="hidden" name="stage_id" id="editStageId">

      <div style="margin-bottom:14px">
        <label style="font-size:11px;font-weight:700;text-transform:uppercase;
                      letter-spacing:.6px;color:#64748b;display:block;margin-bottom:5px">
          Stage Name
        </label>
        <input type="text" name="stage_name" id="editStageName" required
          style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;
                 padding:9px 12px;font-size:14px;box-sizing:border-box;outline:none">
      </div>

      <div style="margin-bottom:20px">
        <label style="font-size:11px;font-weight:700;text-transform:uppercase;
                      letter-spacing:.6px;color:#64748b;display:block;margin-bottom:5px">
          Sort Order
          <span style="font-weight:400;text-transform:none;color:#94a3b8">
            (lower = further left in panel)
          </span>
        </label>
        <input type="number" name="sort_order" id="editStageSortOrder" min="1" required
          style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;
                 padding:9px 12px;font-size:14px;box-sizing:border-box;outline:none">
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button type="button" onclick="closeEditStage()"
          style="background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b;
                 font-size:13px;font-weight:700;padding:9px 20px;border-radius:8px;cursor:pointer">
          Cancel
        </button>
        <button type="submit"
          style="background:linear-gradient(90deg,#1e3a8a,#2563eb);border:none;color:#fff;
                 font-size:13px;font-weight:700;padding:9px 24px;border-radius:8px;cursor:pointer">
          💾 Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- SortableJS for drag-and-drop stage reordering -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
<script>
(function() {
  var tbody = document.getElementById('stages-sortable');
  if (!tbody) return;

  // Save indicator
  var saveInd = document.createElement('div');
  saveInd.id = 'sort-save-ind';
  saveInd.style.cssText = 'display:none;position:fixed;top:18px;right:80px;z-index:9999;'
    + 'background:#1e3a8a;color:#fff;padding:8px 18px;border-radius:20px;'
    + 'font-size:12px;font-weight:700;box-shadow:0 4px 16px rgba(0,0,0,.2);'
    + 'transition:opacity .3s;';
  document.body.appendChild(saveInd);

  function showInd(text, color) {
    saveInd.textContent = text;
    saveInd.style.background = color || '#1e3a8a';
    saveInd.style.display = 'block';
    saveInd.style.opacity = '1';
  }
  function hideInd() {
    saveInd.style.opacity = '0';
    setTimeout(function(){ saveInd.style.display = 'none'; }, 400);
  }

  Sortable.create(tbody, {
    handle: '.drag-handle',
    animation: 150,
    ghostClass: 'sortable-ghost',
    chosenClass: 'sortable-chosen',
    onEnd: function() {
      // Collect new order
      var rows = tbody.querySelectorAll('tr[data-id]');
      var order = [];
      rows.forEach(function(row, i) {
        order.push(row.getAttribute('data-id'));
        // Update the badge number live
        var badge = row.querySelector('[id^="sort-badge-"]');
        if (badge) badge.textContent = i + 1;
      });

      showInd('💾 Saving order…', '#1e3a8a');

      // POST via fetch
      var fd = new FormData();
      fd.append('action', 'reorder_stages');
      fd.append('order', JSON.stringify(order));

      fetch('dashboard.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (data.ok) {
            showInd('✓ Order saved!', '#166534');
            setTimeout(hideInd, 1800);
          } else {
            showInd('⚠️ Save failed', '#dc2626');
            setTimeout(hideInd, 2500);
          }
        })
        .catch(function(){
          showInd('⚠️ Network error', '#dc2626');
          setTimeout(hideInd, 2500);
        });
    }
  });
})();
</script>
<style>
.sortable-ghost { opacity:.4; background:#e0e7ff !important; }
.sortable-chosen { background:#f0f4ff !important; box-shadow:0 4px 16px rgba(30,58,138,.15); }
.drag-handle:hover { color: var(--blue) !important; }
tr[data-id] { cursor: default; }
</style>

<script>
function openEditStage(id, name, sortOrder) {
  document.getElementById('editStageId').value        = id;
  document.getElementById('editStageName').value      = name;
  document.getElementById('editStageSortOrder').value = sortOrder;
  var modal = document.getElementById('editStageModal');
  modal.style.display = 'flex';
}
function closeEditStage() {
  document.getElementById('editStageModal').style.display = 'none';
}
document.getElementById('editStageModal').addEventListener('click', function(e) {
  if (e.target === this) closeEditStage();
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeEditStage();
});
</script>

<script>
// OUTPUT HISTORY FILTER
function ohApplyFilters() {
  var filterDate   = document.getElementById('oh-filter-date').value;
  var filterMonth  = document.getElementById('oh-filter-month').value;
  var filterStage  = document.getElementById('oh-filter-stage').value;
  var filterAction = document.getElementById('oh-filter-action').value;
  var filterSize   = document.getElementById('oh-filter-size').value;

  var tbody = document.getElementById('oh-tbody');
  if (!tbody) return;

  var rows    = tbody.querySelectorAll('tr');
  var visible = 0;

  rows.forEach(function(row) {
    var rowDate   = row.getAttribute('data-date')   || '';
    var rowMonth  = row.getAttribute('data-month')  || '';
    var rowStage  = row.getAttribute('data-stage')  || '';
    var rowAction = row.getAttribute('data-action') || '';
    var rowSize   = row.getAttribute('data-size')   || '';
    var show = true;

    if (filterDate) {
      if (rowDate !== filterDate) show = false;
    } else if (filterMonth) {
      if (rowMonth !== filterMonth) show = false;
    }
    if (filterStage  && rowStage  !== filterStage)  show = false;
    if (filterAction && rowAction !== filterAction)  show = false;
    if (filterSize   && rowSize   !== filterSize)    show = false;

    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });

  var countDiv = document.getElementById('oh-result-count');
  var countNum = document.getElementById('oh-count-num');
  var noRes    = document.getElementById('oh-no-results');
  var table    = document.getElementById('oh-table');
  var hasFilter = filterDate || filterMonth || filterStage || filterAction || filterSize;

  if (hasFilter) {
    if (countDiv) { countDiv.style.display = 'flex'; }
    if (countNum) { countNum.textContent = visible; }
    if (noRes)  noRes.style.display  = visible === 0 ? 'block' : 'none';
    if (table)  table.style.display  = visible === 0 ? 'none'  : '';
  } else {
    if (countDiv) { countDiv.style.display = 'none'; }
    if (noRes)  noRes.style.display  = 'none';
    if (table)  table.style.display  = '';
  }
}

function ohClearFilters() {
  var d = document.getElementById('oh-filter-date');
  var m = document.getElementById('oh-filter-month');
  var s = document.getElementById('oh-filter-stage');
  var a = document.getElementById('oh-filter-action');
  var z = document.getElementById('oh-filter-size');
  if (d) d.value = '';
  if (m) m.value = '';
  if (s) s.value = '';
  if (a) a.value = '';
  if (z) z.value = '';
  ohApplyFilters();
}
</script>

<script>
/* ── TOAST AUTO-DISMISS ── */
var toastBox = document.getElementById('toastBox');
var toastOverlay = document.getElementById('toastOverlay');
if (toastBox) {
  function dismissToast() {
    toastBox.style.animation = 'toastOut .3s ease forwards';
    if (toastOverlay) toastOverlay.style.animation = 'toastOut .3s ease forwards';
    setTimeout(function(){
      if(toastBox) toastBox.remove();
      if(toastOverlay) toastOverlay.remove();
    }, 320);
  }
  setTimeout(dismissToast, 2800);
  if (toastOverlay) toastOverlay.addEventListener('click', dismissToast);
  toastBox.addEventListener('click', dismissToast);
}
</script>

<script>
/* ── MOBILE SIDEBAR DRAWER ── */
function openSbDrawer() {
  document.getElementById('sb-overlay').classList.add('open');
  document.getElementById('sb-drawer').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeSbDrawer() {
  document.getElementById('sb-overlay').classList.remove('open');
  document.getElementById('sb-drawer').classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') { closeSbDrawer(); closeOrderEditModal(); }
});
</script>

<!-- ══ SHARED ORDER EDIT MODAL ══════════════════════════════════════════════ -->
<div id="orderEditModalBg" style="
  display:none;position:fixed;inset:0;z-index:500;
  background:rgba(13,30,82,.55);backdrop-filter:blur(4px);
  align-items:center;justify-content:center;
" onclick="if(event.target===this)closeOrderEditModal()">
  <div style="
    background:#fff;border-radius:16px;padding:28px 28px 22px;
    width:380px;max-width:95vw;box-shadow:0 24px 60px rgba(13,30,82,.3);
    position:relative;
    animation:oemFadeIn .22s cubic-bezier(.4,0,.2,1);
  ">
    <div style="height:4px;position:absolute;top:0;left:0;right:0;border-radius:16px 16px 0 0;background:linear-gradient(90deg,var(--blue),var(--blue-light))"></div>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
      <div style="font-family:'Barlow Condensed',sans-serif;font-size:20px;font-weight:900;color:var(--blue-dark);text-transform:uppercase;letter-spacing:1px">
        ✏️ Edit Order
      </div>
      <button onclick="closeOrderEditModal()" style="background:none;border:none;cursor:pointer;font-size:20px;color:#94a3b8;line-height:1;padding:2px 6px;border-radius:4px" onmouseover="this.style.background='#fee2e2';this.style.color='#dc2626'" onmouseout="this.style.background='none';this.style.color='#94a3b8'">✕</button>
    </div>
    <form id="orderEditModalForm">
      <input type="hidden" name="action" value="update_target">
      <div style="margin-bottom:14px">
        <label style="font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.8px;display:block;margin-bottom:5px">🎯 Target Pairs</label>
        <input type="number" id="oem-target" name="target_pairs" min="1" required
          style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:22px;font-weight:900;font-family:'Barlow Condensed',sans-serif;outline:none;background:#f5f8ff;color:var(--blue-dark);transition:.2s"
          onfocus="this.style.borderColor='var(--blue-light)';this.style.boxShadow='0 0 0 3px rgba(37,87,214,.12)'"
          onblur="this.style.borderColor='var(--border)';this.style.boxShadow='none'">
      </div>
      <div style="margin-bottom:20px">
        <label style="font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.8px;display:block;margin-bottom:5px">📅 Deadline</label>
        <input type="date" id="oem-deadline" name="deadline" required
          style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;font-weight:700;font-family:'Barlow',sans-serif;outline:none;background:#f5f8ff;color:var(--blue-dark);transition:.2s"
          onfocus="this.style.borderColor='var(--blue-light)';this.style.boxShadow='0 0 0 3px rgba(37,87,214,.12)'"
          onblur="this.style.borderColor='var(--border)';this.style.boxShadow='none'">
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" id="oem-save-btn" style="flex:1;padding:10px;background:linear-gradient(90deg,var(--blue),var(--blue-light));color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:800;cursor:pointer;font-family:'Barlow',sans-serif;text-transform:uppercase;letter-spacing:.5px;transition:.2s">
          💾 Save Changes
        </button>
        <button type="button" onclick="closeOrderEditModal()" style="padding:10px 16px;background:#e8eef8;color:var(--blue-dark);border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Barlow',sans-serif">
          Cancel
        </button>
      </div>
    </form>
    <div id="oem-msg" style="display:none;margin-top:12px;padding:8px 12px;border-radius:6px;font-size:12px;font-weight:700;text-align:center"></div>
  </div>
</div>
<style>
@keyframes oemFadeIn {
  from { opacity:0; transform:translateY(-16px) scale(.97); }
  to   { opacity:1; transform:translateY(0)     scale(1); }
}
</style>
<script>
var _oemCurrentTarget   = <?= (int)($activeOrder['target_pairs'] ?? 0) ?>;
var _oemCurrentDeadline = <?= json_encode($activeOrder['deadline'] ?? date('Y-m-d')) ?>;
var _oemOrderId         = <?= (int)($activeOrder['id'] ?? 0) ?>;

function openOrderEditModal() {
  document.getElementById('oem-target').value   = _oemCurrentTarget;
  document.getElementById('oem-deadline').value = _oemCurrentDeadline;
  document.getElementById('oem-msg').style.display = 'none';
  var bg = document.getElementById('orderEditModalBg');
  bg.style.display = 'flex';
  setTimeout(function(){ document.getElementById('oem-target').select(); }, 80);
}

function closeOrderEditModal() {
  document.getElementById('orderEditModalBg').style.display = 'none';
}

document.getElementById('orderEditModalForm').addEventListener('submit', function(e) {
  e.preventDefault();
  var newTarget   = parseInt(document.getElementById('oem-target').value);
  var newDeadline = document.getElementById('oem-deadline').value;
  if (!newTarget || newTarget < 1 || !newDeadline) return;
  var btn = document.getElementById('oem-save-btn');
  btn.disabled = true; btn.textContent = 'Saving…';

  var fd = new FormData();
  fd.append('action', 'update_target');
  fd.append('ajax', '1');
  fd.append('target_pairs', newTarget);
  fd.append('deadline', newDeadline);

  fetch('dashboard.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data) {
      btn.disabled = false; btn.textContent = '💾 Save Changes';
      if (!data.ok) {
        var msg = document.getElementById('oem-msg');
        msg.style.display='block'; msg.style.background='#fee2e2'; msg.style.color='#dc2626';
        msg.textContent = data.msg || '✖ Error saving.';
        return;
      }

      _oemCurrentTarget   = newTarget;
      _oemCurrentDeadline = newDeadline;

      // ── Update Target Pairs card ──────────────────────────────────────────
      var tval = document.getElementById('dash-target-val');
      if (tval) tval.textContent = newTarget.toLocaleString();

      // Update progress text  e.g. "0 / 61 · 0%"
      var progText = document.getElementById('dash-target-prog-text');
      var completed = parseInt((document.getElementById('dash-total-completed') || {}).textContent || '0');
      var pct = newTarget > 0 ? Math.min(100, Math.round(completed / newTarget * 100)) : 0;
      if (progText) {
        progText.innerHTML = completed.toLocaleString() + ' / ' + newTarget.toLocaleString()
          + ' &nbsp;·&nbsp; <span id="dash-target-pct" style="color:'+(pct>=100?'var(--green)':'var(--blue-light)')+';font-weight:800">'+pct+'%</span>';
      }
      var tpf = document.getElementById('dash-target-prog-fill');
      if (tpf) tpf.style.width = pct + '%';

      // Also update "Pairs Completed" card progress %
      var cpf = document.getElementById('dash-completed-prog-fill');
      if (cpf) cpf.style.width = pct + '%';
      var cpct = document.getElementById('dash-completed-pct-text');
      if (cpct) cpct.textContent = pct + '% of target';

      // stat-grid data-target
      var sg = document.getElementById('dash-stat-grid');
      if (sg) sg.setAttribute('data-target', newTarget);

      // ── Update Deadline card ──────────────────────────────────────────────
      // Format date nicely (avoids timezone shift by treating as local)
      var parts = newDeadline.split('-');
      var dlocal = new Date(parseInt(parts[0]), parseInt(parts[1])-1, parseInt(parts[2]));
      var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      var dateStr = months[dlocal.getMonth()] + ' ' + dlocal.getDate() + ', ' + dlocal.getFullYear();
      var ddateEl = document.getElementById('dash-deadline-date');
      if (ddateEl) ddateEl.textContent = dateStr;

      // Recalculate working days left (Mon–Fri only)
      var today = new Date(); today.setHours(0,0,0,0);
      var dl = new Date(parseInt(parts[0]), parseInt(parts[1])-1, parseInt(parts[2]));
      var days = 0;
      var cur = new Date(today);
      while (cur < dl) { cur.setDate(cur.getDate()+1); var wd=cur.getDay(); if(wd!==0&&wd!==6) days++; }

      var ddays = document.getElementById('dash-deadline-days');
      if (ddays) {
        ddays.textContent = days;
        ddays.style.color = days <= 3 ? '#dc2626' : (days <= 7 ? '#f59e0b' : 'var(--blue-dark)');
      }
      var dwarn = document.getElementById('dash-deadline-warn');
      if (dwarn) {
        if (days <= 3) { dwarn.style.display=''; dwarn.textContent='⚠️ Deadline soon!'; }
        else           { dwarn.style.display='none'; }
      }

      // Show success then close
      var msg = document.getElementById('oem-msg');
      msg.style.display = 'block';
      msg.style.background = '#dcfce7'; msg.style.color = '#15803d';
      msg.textContent = '✔ Saved! Both cards updated.';
      setTimeout(function(){ closeOrderEditModal(); }, 900);
    })
    .catch(function() {
      btn.disabled = false; btn.textContent = '💾 Save Changes';
      var msg = document.getElementById('oem-msg');
      msg.style.display = 'block';
      msg.style.background = '#fee2e2'; msg.style.color = '#dc2626';
      msg.textContent = '✖ Error saving. Please try again.';
    });
});
</script>

</body>
</html>