<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
requireLogin();
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

$pdo  = getPDO();
$user = currentUser();

// Ensure activity log table exists before any queries
try { $pdo->exec("CREATE TABLE IF NOT EXISTS output_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_size_id INT NOT NULL,
    stage_id INT NOT NULL,
    log_date DATE NOT NULL,
    action ENUM('add','minus') NOT NULL DEFAULT 'add',
    qty_change INT NOT NULL,
    confirmed_by VARCHAR(120),
    subtract_reason VARCHAR(255) NULL,
    entered_by INT NOT NULL,
    entered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE output_activity_log ADD COLUMN subtract_reason VARCHAR(255) NULL"); } catch(Exception $e){}

// Active order
$order = $pdo->query("SELECT * FROM production_orders WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
$orderId = $order['id'] ?? 0;

// Sizes for this order
$sizes = $pdo->prepare("SELECT * FROM order_sizes WHERE order_id=? ORDER BY sort_order");
$sizes->execute([$orderId]);
$sizes = $sizes->fetchAll();

// Stages
$stages = $pdo->query("SELECT * FROM stages WHERE COALESCE(is_active,1)=1 ORDER BY sort_order")->fetchAll();

// Date for view/history filter — defaults to today
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$isToday = ($date === date('Y-m-d'));

// Outputs — cumulative up to and including selected date (progressive history)
$outRows = $pdo->prepare("
    SELECT do.order_size_id, do.stage_id, SUM(do.qty_produced) as qty_produced
    FROM daily_outputs do
    WHERE do.order_size_id IN (SELECT id FROM order_sizes WHERE order_id = ?)
      AND do.log_date <= ?
    GROUP BY do.order_size_id, do.stage_id
");
$outRows->execute([$orderId, $date]);
$outputs = [];
foreach ($outRows->fetchAll() as $r) {
    $outputs[$r['order_size_id']][$r['stage_id']] = $r['qty_produced'];
}

// Total completed pairs — based on the LAST active production stage (by sort_order)
$finStage = $pdo->query("SELECT id, name FROM stages WHERE COALESCE(is_active,1)=1 ORDER BY sort_order DESC LIMIT 1")->fetch();
$finId = $finStage['id'] ?? 5;
$totalCompleted = $pdo->prepare("
    SELECT COALESCE(SUM(do.qty_produced),0) as total
    FROM daily_outputs do
    JOIN order_sizes os ON os.id = do.order_size_id
    WHERE os.order_id = ? AND do.stage_id = ? AND do.log_date <= ?
");
$totalCompleted->execute([$orderId, $finId, $date]);
$totalCompleted = (int)$totalCompleted->fetchColumn();

// Per-stage cumulative totals up to selected date
$stageTotals = [];
foreach ($stages as $st) {
    $stq = $pdo->prepare("
        SELECT COALESCE(SUM(do.qty_produced),0)
        FROM daily_outputs do
        JOIN order_sizes os ON os.id=do.order_size_id
        WHERE os.order_id=? AND do.stage_id=? AND do.log_date<=?
    ");
    $stq->execute([$orderId, $st['id'], $date]);
    $stageTotals[$st['id']] = (int)$stq->fetchColumn();
}

// Total target — prefer the order-level target_pairs set by admin; fall back to sum of sizes
$totalTarget = (!empty($order['target_pairs']) && (int)$order['target_pairs'] > 0)
    ? (int)$order['target_pairs']
    : array_sum(array_column($sizes, 'target_qty'));

// Consistency chart data — show Mon–Fri of the current week
$weekDays = [];
$today = strtotime('today');
$dayOfWeek = (int)date('N', $today); // 1=Mon ... 7=Sun
$monday = $today - (($dayOfWeek - 1) * 86400);
for ($i = 0; $i < 5; $i++) {
    $weekDays[] = date('Y-m-d', $monday + ($i * 86400));
}
$chartRaw = $pdo->prepare("SELECT log_date, total_pairs FROM daily_order_totals WHERE order_id=? AND log_date BETWEEN ? AND ?");
$chartRaw->execute([$orderId, $weekDays[0], $weekDays[4]]);
$chartIndexed = array_column($chartRaw->fetchAll(), 'total_pairs', 'log_date');
$chartData = [];
foreach ($weekDays as $wd) {
    $chartData[] = ['log_date' => $wd, 'total_pairs' => $chartIndexed[$wd] ?? 0];
}

// Recent output entries for this date (last 15) — from activity log
$recentEntries = $pdo->prepare("
    SELECT al.log_date, os.size_label, s.name as stage,
           al.action, al.qty_change as qty_produced,
           u.full_name as entered_by_name, al.confirmed_by,
           COALESCE(al.subtract_reason,'') as subtract_reason,
           al.entered_at
    FROM output_activity_log al
    JOIN order_sizes os ON os.id = al.order_size_id
    JOIN stages s ON s.id = al.stage_id
    JOIN users u ON u.id = al.entered_by
    WHERE os.order_id = ?
    ORDER BY al.entered_at DESC LIMIT 15
");
try {
    $recentEntries->execute([$orderId]);
    $recentEntries = $recentEntries->fetchAll();
} catch(Exception $e) { $recentEntries = []; }

// Announcement
$announce = $pdo->query("SELECT * FROM announcements WHERE is_active=1 ORDER BY created_at DESC LIMIT 1")->fetch();

// Days remaining (working days only: Mon–Fri, excluding PH holidays)
$daysLeft = 0;
if ($order && !empty($order['deadline'])) {
    $daysLeft = countWorkingDaysUntilDeadline($order['deadline']);
}

// Handle add qty (supervisor/admin)
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($user['role'] === 'admin' || $user['role'] === 'supervisor')) {
    $sizeId     = (int)($_POST['size_id']      ?? 0);
    $stageId    = (int)($_POST['stage_id']     ?? 0);
    $qty        = (int)($_POST['qty']          ?? 0);
    $logDate    = $_POST['log_date']            ?? date('Y-m-d');
    $confirmedBy = trim($_POST['confirmed_by'] ?? '');
    $action     = $_POST['action']             ?? 'add';
    $subtractReason = trim($_POST['subtract_reason'] ?? '');

    // Ensure confirmed_by column exists (safe to run every time)
    try { $pdo->exec("ALTER TABLE daily_outputs ADD COLUMN confirmed_by VARCHAR(120) NULL"); } catch(Exception $e){}

    // Ensure activity log table exists
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS output_activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_size_id INT NOT NULL,
        stage_id INT NOT NULL,
        log_date DATE NOT NULL,
        action ENUM('add','minus') NOT NULL DEFAULT 'add',
        qty_change INT NOT NULL,
        confirmed_by VARCHAR(120),
        subtract_reason VARCHAR(255) NULL,
        entered_by INT NOT NULL,
        entered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE output_activity_log ADD COLUMN subtract_reason VARCHAR(255) NULL"); } catch(Exception $e){}

    // Validate that size_id actually belongs to the current active order
    $validSize = false;
    if ($sizeId && $orderId) {
        $chk = $pdo->prepare("SELECT id FROM order_sizes WHERE id=? AND order_id=?");
        $chk->execute([$sizeId, $orderId]);
        $validSize = (bool)$chk->fetch();
    }
    // Validate that stage_id exists
    $validStage = false;
    if ($stageId) {
        $chkStage = $pdo->prepare("SELECT id FROM stages WHERE id=?");
        $chkStage->execute([$stageId]);
        $validStage = (bool)$chkStage->fetch();
    }

    if ($validSize && $validStage && $qty >= 0) {
        if ($action === 'minus') {
            // Get cumulative qty across ALL dates for this size+stage (same as what the display shows)
            $cur = $pdo->prepare("SELECT COALESCE(SUM(qty_produced),0) FROM daily_outputs WHERE order_size_id=? AND stage_id=?");
            $cur->execute([$sizeId, $stageId]);
            $curQty = (int)$cur->fetchColumn();
            $actualMinus = min($qty, $curQty); // how much actually subtracted
            $remaining   = max(0, $curQty - $qty);

            // Distribute the subtraction across existing rows (newest first) until $qty is consumed
            $rows = $pdo->prepare("SELECT id, log_date, qty_produced FROM daily_outputs WHERE order_size_id=? AND stage_id=? AND qty_produced > 0 ORDER BY log_date DESC");
            $rows->execute([$sizeId, $stageId]);
            $toDeduct = $qty;
            foreach ($rows->fetchAll() as $row) {
                if ($toDeduct <= 0) break;
                $deduct  = min($toDeduct, (int)$row['qty_produced']);
                $newRowQty = (int)$row['qty_produced'] - $deduct;
                $pdo->prepare("UPDATE daily_outputs SET qty_produced=?, entered_by=?, confirmed_by=? WHERE id=?")
                    ->execute([$newRowQty, $user['id'], $confirmedBy, $row['id']]);
                $toDeduct -= $deduct;
            }
            // Log the minus action against today's log_date for the activity log
            $pdo->prepare("INSERT INTO output_activity_log (order_size_id, stage_id, log_date, action, qty_change, confirmed_by, subtract_reason, entered_by) VALUES (?,?,?,'minus',?,?,?,?)")
                ->execute([$sizeId, $stageId, $logDate, $actualMinus, $confirmedBy, $subtractReason ?: null, $user['id']]);
        } else {
            $pdo->prepare("
                INSERT INTO daily_outputs (order_size_id, stage_id, log_date, qty_produced, entered_by, confirmed_by)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE qty_produced = qty_produced + VALUES(qty_produced), confirmed_by = VALUES(confirmed_by)
            ")->execute([$sizeId, $stageId, $logDate, $qty, $user['id'], $confirmedBy]);
            // Log the add action
            $pdo->prepare("INSERT INTO output_activity_log (order_size_id, stage_id, log_date, action, qty_change, confirmed_by, entered_by) VALUES (?,?,?,'add',?,?,?)")
                ->execute([$sizeId, $stageId, $logDate, $qty, $confirmedBy, $user['id']]);
        }
        $newTotal = $pdo->prepare("
            SELECT COALESCE(SUM(do.qty_produced),0)
            FROM daily_outputs do JOIN order_sizes os ON os.id=do.order_size_id
            WHERE os.order_id=? AND do.stage_id=? AND do.log_date=?
        ");
        $newTotal->execute([$orderId, $finId, $logDate]);
        $newTotal = (int)$newTotal->fetchColumn();
        $pdo->prepare("
            INSERT INTO daily_order_totals (order_id, log_date, total_pairs) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE total_pairs = ?
        ")->execute([$orderId, $logDate, $newTotal, $newTotal]);
        header("Location: index.php?date=$logDate&saved=1"); exit;
    } else {
        // Invalid size_id or stage_id — set an error message instead of crashing
        if (!$validSize) {
            $msg = 'Error: The selected size does not belong to the current active order. Please reload the page and try again.';
        } elseif (!$validStage) {
            $msg = 'Error: The selected stage is invalid. Please reload the page and try again.';
        } else {
            $msg = 'Error: Invalid quantity or missing fields.';
        }
    }
}
if (isset($_GET['saved'])) $msg = 'Quantity updated successfully!';

// Role definitions: 'admin' = full access, 'supervisor' = production access (output input + inventory)
$isAdmin      = ($user['role'] === 'admin');
$isProduction = ($user['role'] === 'supervisor');
$canEdit      = $isAdmin || $isProduction; // both can input production output

// Load confirmers (team leaders)
$confirmersFile = __DIR__ . '/config/confirmers.json';
$confirmers = file_exists($confirmersFile) ? (json_decode(file_get_contents($confirmersFile), true)['names'] ?? []) : [];

// Load global subtract PIN
$subtractPinFile = __DIR__ . '/config/subtract_pin.json';
$subtractPinHash = file_exists($subtractPinFile) ? (json_decode(file_get_contents($subtractPinFile), true)['pin_hash'] ?? '') : '';
$subtractPinSet  = !empty($subtractPinHash);

// Load running model settings
$modelSettingsFile = __DIR__ . '/config/running_model.json';
$modelSettings = file_exists($modelSettingsFile) ? json_decode(file_get_contents($modelSettingsFile), true) : [];
$modelName     = $modelSettings['name']  ?? '';
$modelImageFile = $modelSettings['image'] ?? ''; // just the filename
// Migrate old format (path stored instead of filename) — strip any directory prefix
if ($modelImageFile && str_contains($modelImageFile, '/')) {
    $modelImageFile = basename($modelImageFile);
    $modelSettings['image'] = $modelImageFile;
    file_put_contents($modelSettingsFile, json_encode($modelSettings));
}
// index.php is at root, so URL path is: uploads/model/filename
$modelImageUrl  = $modelImageFile ? 'uploads/model/' . $modelImageFile : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Quilla — Production Monitoring</title>

<style>
@import url('https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800;900&family=Barlow:wght@400;500;600;700&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#1a3a8f;--blue-dark:#122a6b;--blue-mid:#1e47b0;--blue-light:#2557d6;
  --gold:#f5c518;--gold2:#e8b500;
  --green:#22c55e;--red:#dc2626;--purple:#7c3aed;--orange:#f97316;
  --bg:#0d1e52;
  --main:#eef2fb;--white:#fff;
  --border:#c8d5f0;--text:#0d1e52;--text2:#4a5b8a;
  --card-bg:#fff;
}
body{font-family:'Barlow',sans-serif;background:var(--main);color:var(--text);min-height:100vh}

/* NAV */
.nav{
  background:linear-gradient(90deg,var(--blue-dark) 0%,var(--blue-mid) 100%);
  color:#fff;display:flex;align-items:center;justify-content:space-between;
  padding:0 28px;height:62px;position:sticky;top:0;z-index:100;
  border-bottom:3px solid var(--blue-light);
  box-shadow:0 2px 16px rgba(13,30,82,.25);
}
.nav-logo{display:flex;align-items:center;height:62px}
.nav-logo img{height:48px;width:auto;object-fit:contain;display:block}
.nav-right{display:flex;align-items:center;gap:12px;font-size:13px}
.nav-user{
  background:rgba(255,255,255,.1);padding:6px 14px;border-radius:20px;
  font-size:12px;border:1px solid rgba(255,255,255,.2);font-weight:600;
}
.nav-link{
  color:rgba(255,255,255,.65);text-decoration:none;padding:7px 14px;
  border-radius:6px;transition:.2s;font-size:12px;font-weight:700;
  letter-spacing:.5px;text-transform:uppercase;border:1px solid transparent;
}
.nav-link:hover{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.2)}

/* LAYOUT */
.container{max-width:1400px;margin:0 auto;padding:28px 24px}
.page-title{
  font-family:'Barlow Condensed',sans-serif;font-size:30px;font-weight:900;
  letter-spacing:1px;text-transform:uppercase;color:var(--blue-dark);
  margin-bottom:22px;display:flex;align-items:center;gap:10px;
  padding-bottom:12px;border-bottom:3px solid var(--blue-light);
  justify-content:center;
}

/* TOP CARDS */
.top-cards{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:18px;margin-bottom:28px}

/* RUNNING MODEL CARD */
.card-model{position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s}
.card-model::before{background:linear-gradient(90deg,var(--blue-dark),var(--blue-light)) !important}
.card-model:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(26,58,143,.14)}
.model-img-frame{
  width:160px;height:140px;border-radius:12px;overflow:hidden;
  border:2px solid var(--border);background:#f0f4fc;
  display:flex;align-items:center;justify-content:center;
  margin:8px auto 0;flex-shrink:0;
}
.model-img-frame img{width:100%;height:100%;object-fit:cover;display:block}
.model-name-tag{
  font-family:'Barlow Condensed',sans-serif;font-size:18px;font-weight:800;
  color:var(--blue-dark);text-align:center;margin-top:8px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;
}
.model-no-img{
  font-size:36px;line-height:1;
}
.card{
  background:var(--card-bg);border-radius:12px;padding:20px 22px;
  border:1px solid var(--border);
  box-shadow:0 2px 12px rgba(26,58,143,.08);
  position:relative;overflow:hidden;
  transition:transform .2s,box-shadow .2s;
}
.card:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(26,58,143,.14)}
.card::before{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:linear-gradient(90deg,var(--blue),var(--blue-light));
}
.card-target::before{background:linear-gradient(90deg,var(--),#102663)}
.card-deadline::before{background:linear-gradient(90deg,var(--blue-dark),var(--blue-light))}
.card-chart::before{background:linear-gradient(90deg,var(--blue-dark),var(--blue-light))}
.card-label{font-size:10px;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;color:var(--text2);margin-bottom:10px}
.card-pairs{font-family:'Barlow Condensed',sans-serif;font-size:56px;font-weight:900;color:var(--blue-dark);line-height:1;margin:4px 0}
.badge-completed{display:inline-block;background:var(--green);color:#fff;font-size:12px;font-weight:700;padding:4px 14px;border-radius:20px;margin-top:8px}
.deadline-val{font-family:'Barlow Condensed',sans-serif;font-size:32px;font-weight:800;color:var(--blue-dark);display:flex;align-items:center;gap:10px;margin-top:6px}
.days-badge{font-size:20px;color:var(--text2);margin-top:6px;font-weight:600}

/* MINI BAR CHART */
.mini-bar-wrap{width:100%;margin-top:8px}
.mini-bar-canvas{width:100%;height:90px;display:block}

/* PROGRESS BAR */
.prog-track{background:#d7e2ff;border-radius:8px;height:8px;overflow:hidden;margin-top:10px}
.prog-fill{background:linear-gradient(90deg,var(--blue-light),var(--gold));height:100%;border-radius:8px;transition:width .5s}
.prog-label{font-size:11px;color:var(--blue-light);margin-top:4px;font-weight:700}

/* ALERT */
.alert{
  position:relative;overflow:hidden;
  background:linear-gradient(135deg,#1a3a8f 0%,#1e47b0 50%,#f5c518 100%);
  border-radius:14px;margin-bottom:22px;
  box-shadow:0 6px 28px rgba(26,58,143,.28),0 2px 8px rgba(0,0,0,.10);
  animation:alertPop .5s cubic-bezier(.34,1.56,.64,1);
}
.alert-inner{
  display:flex;align-items:stretch;gap:0;
}
.alert-icon-col{
  background:rgba(245,197,24,.18);
  border-right:2px solid rgba(245,197,24,.35);
  padding:18px 20px;
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;
  flex-shrink:0;min-width:72px;
}
.alert-icon-col .alert-megaphone{font-size:34px;line-height:1;animation:megaphoneShake 1.8s ease-in-out infinite;}
.alert-icon-col .alert-live{font-size:9px;font-weight:900;letter-spacing:1.5px;color:#f5c518;text-transform:uppercase;background:rgba(245,197,24,.2);padding:2px 7px;border-radius:20px;border:1px solid rgba(245,197,24,.4)}
.alert-content{padding:16px 20px 16px 18px;flex:1;min-width:0}
.alert-title-row{display:flex;align-items:center;gap:10px;margin-bottom:6px}
.alert-label{font-size:9px;font-weight:900;letter-spacing:2px;text-transform:uppercase;
  color:#f5c518;background:rgba(245,197,24,.15);border:1px solid rgba(245,197,24,.3);
  padding:2px 9px;border-radius:20px;flex-shrink:0}
.alert-title{font-family:'Barlow Condensed',sans-serif;font-size:20px;font-weight:900;
  color:#fff;text-transform:uppercase;letter-spacing:.5px;line-height:1.1}
.alert-body-wrap{overflow:hidden;white-space:nowrap}
.alert-body{display:inline-block;font-size:14px;color:rgba(255,255,255,.92);font-weight:600;
  line-height:1.5;white-space:nowrap;padding-right:60px;
  animation:scrollText 18s linear infinite;}

/* Flashing red dot */
.alert-dot{width:8px;height:8px;border-radius:50%;background:#ef4444;flex-shrink:0;
  animation:dotBlink 1s ease-in-out infinite}
/* Border pulse */
.alert::before{content:'';position:absolute;inset:0;border-radius:14px;
  border:2px solid rgba(245,197,24,.0);
  animation:borderPulse 2s ease-in-out infinite;pointer-events:none}

@keyframes alertPop{from{opacity:0;transform:translateY(-16px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes megaphoneShake{0%,100%{transform:rotate(-8deg)}50%{transform:rotate(8deg)}}
@keyframes scrollText{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}
@keyframes dotBlink{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.3;transform:scale(.7)}}
@keyframes borderPulse{0%,100%{border-color:rgba(245,197,24,0)}50%{border-color:rgba(245,197,24,.5)}}
.success-msg{
  background:linear-gradient(90deg,#16a34a,#15803d);
  color:#fff;border-radius:8px;
  padding:11px 18px;font-size:13px;font-weight:600;
  margin-bottom:20px;letter-spacing:.3px;
  display:flex;align-items:center;gap:8px;
  box-shadow:0 4px 14px rgba(22,163,74,.3);
  animation:fadeInDown .35s ease, fadeOut .6s ease 2.4s forwards;
}
@keyframes fadeInDown{
  from{opacity:0;transform:translateY(-12px)}
  to{opacity:1;transform:translateY(0)}
}
@keyframes fadeOut{
  from{opacity:1;transform:translateY(0)}
  to{opacity:0;transform:translateY(-8px);pointer-events:none}
}

/* DATE BAR */
.date-bar{
  background:var(--card-bg);border:1px solid var(--border);border-radius:12px;
  padding:13px 20px;display:flex;align-items:center;justify-content:space-between;
  margin-bottom:20px;box-shadow:0 2px 10px rgba(26,58,143,.06);
}
.date-label{font-size:14px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.date-input{
  border:1.5px solid var(--border);border-radius:6px;
  padding:7px 12px;font-size:13px;outline:none;
  font-family:'Barlow',sans-serif;transition:.2s;
}
.date-input:focus{border-color:var(--blue-light);box-shadow:0 0 0 3px rgba(37,87,214,.12)}

/* TABLE */
.tbl-wrap{
  background:var(--card-bg);border:1px solid var(--border);
  border-radius:12px;overflow:hidden;
  box-shadow:0 2px 10px rgba(26,58,143,.06);
}

.tbl-header,
.tbl-row,
.tbl-footer{display:grid;grid-template-columns:100px 120px repeat(<?= count($stages) ?>,1fr);gap:0}

.tbl-header{
  background:linear-gradient(90deg,var(--blue-dark) 0%,var(--blue-mid) 100%);
  border-bottom:2px solid var(--gold);
}
.th{
  padding:10px 8px;font-size:12px;font-weight:700;color:rgba(255,255,255,.85);
  text-align:center;letter-spacing:.8px;text-transform:uppercase;
  font-family:'Barlow Condensed',sans-serif;
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;
}
.th:first-child,.th:nth-child(2){text-align:left;align-items:flex-start;padding-left:14px}

.tbl-row{border-bottom:1px solid #edf1fb;align-items:stretch;transition:background .15s}
.tbl-row:last-child{border-bottom:none}
.tbl-row:hover .td:first-child,
.tbl-row:hover .td:nth-child(2){background:#f0f4fc}
.td{padding:8px 6px;text-align:center;display:flex;align-items:center;justify-content:center}
.td:first-child{padding-left:14px;justify-content:flex-start}
.td:nth-child(2){padding-left:10px;justify-content:flex-start}

/* Stage column box */
.tbl-row .td.stage-col,
.tbl-row .td.stage-col-top{
  border-left:1.5px solid var(--border);
  border-right:1.5px solid var(--border);
  background:#f8faff;
}
.tbl-footer .footer-td.stage-col{
  border-left:1.5px solid var(--border);
  border-right:1.5px solid var(--border);
  border-bottom:2px solid var(--border);
  background:#f8faff;
  padding:10px 8px;
}

/* PILLS */
.target-pill{
  background:var(--blue-dark);color:#fff;font-family:'Barlow Condensed',sans-serif;
  font-size:15px;font-weight:700;padding:5px 12px;border-radius:6px;min-width:50px;text-align:center;
}
.size-pill{
  background:linear-gradient(90deg,var(--blue),var(--blue-light));color:#fff;
  font-size:11px;font-weight:700;padding:5px 10px;border-radius:5px;white-space:nowrap;
}

/* QTY CELL */
.qty-cell{position:relative;width:100%;display:flex;align-items:center;gap:4px;justify-content:center}
.qty-val{background:#dcfce7;border-radius:6px;padding:6px 10px;font-size:14px;font-weight:800;color:#166534;min-width:38px;text-align:center}
.qty-zero{background:#e8eef8;border-radius:6px;padding:6px 10px;font-size:14px;font-weight:600;color:#8ea3c8;min-width:38px;text-align:center}
.qty-dash{font-size:18px;color:#d1d5db;font-weight:300}

/* ADD BUTTON (inline) */
.add-inline{
  background:transparent;border:1.5px dashed var(--blue-light);
  color:var(--blue-light);border-radius:6px;width:26px;height:26px;
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;font-size:15px;font-weight:800;transition:.18s;flex-shrink:0;padding:0;
}
.add-inline:hover{background:var(--blue-light);color:#fff;border-style:solid}

/* MINUS BUTTON (inline) */
.minus-inline{
  background:transparent;border:1.5px dashed #dc2626;
  color:#dc2626;border-radius:6px;width:26px;height:26px;
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;font-size:15px;font-weight:800;transition:.18s;flex-shrink:0;padding:0;
}
.minus-inline:hover{background:#dc2626;color:#fff;border-style:solid}
.minus-inline:disabled{opacity:.3;cursor:not-allowed;border-style:dashed}

/* STAGE TOGGLE BUTTON */
.stage-toggle{
  display:inline-flex;align-items:center;gap:4px;
  font-size:9px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;
  padding:3px 8px;border-radius:20px;border:none;cursor:pointer;
  margin-top:4px;transition:.18s;
}
.stage-toggle.enabled{background:rgba(255,255,255,.15);color:#fff;}
.stage-toggle.enabled:hover{background:#dc2626;color:#fff;}
.stage-toggle.disabled{background:#dc2626;color:#fff;}
.stage-toggle.disabled:hover{background:rgba(255,255,255,.15);color:#fff;}

/* DISABLED COLUMN OVERLAY */
.col-disabled .td.stage-col,
.col-disabled .td.stage-col-top{
  background:#f1f1f1 !important;opacity:.45;pointer-events:none;
}
.col-disabled .footer-td.stage-col{
  background:#f1f1f1 !important;opacity:.45;
}
.col-disabled-overlay{
  position:absolute;inset:0;background:repeating-linear-gradient(
    45deg,transparent,transparent 6px,rgba(200,210,240,.25) 6px,rgba(200,210,240,.25) 12px
  );pointer-events:none;border-radius:4px;
}

/* FOOTER ROW */
.tbl-footer{background:#e8eef8;border-top:2px solid var(--border);padding:8px 0;display:grid;grid-template-columns:100px 120px repeat(<?= count($stages) ?>,1fr);gap:0;align-items:stretch}
.footer-td{padding:8px 6px;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;min-height:70px}

/* COLUMN PERCENTAGE */
.col-pct-wrap{width:100%;padding:4px 2px}
.col-pct-bar{background:#dbeafe;border-radius:6px;height:8px;width:100%;display:block;overflow:hidden;margin-bottom:4px;position:relative}
.col-pct-fill{display:block;height:8px;border-radius:6px;min-width:0;transition:width .6s ease;position:absolute;left:0;top:0}
.col-pct-label{font-size:11px;font-weight:700;text-align:center;display:block}
.col-total{font-size:12px;font-weight:700;color:var(--blue-dark);margin-bottom:4px;text-align:center;display:block}

/* STAGE PROGRESS CARD */
.stage-card{
  background:#fff;border:1.5px solid var(--border);border-radius:10px;
  padding:10px 8px;width:100%;display:flex;flex-direction:column;
  align-items:center;gap:4px;
  box-shadow:0 1px 6px rgba(26,58,143,.07);
}

/* TOTAL BOX */
.total-box{
  background:linear-gradient(90deg,var(--blue),var(--blue-mid));
  color:#fff;font-size:13px;font-weight:800;
  padding:8px 14px;border-radius:8px;text-align:center;white-space:nowrap;
}

/* MODAL */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(13,30,82,.6);z-index:200;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.modal-bg.open{display:flex}
.modal{
  background:var(--white);border-radius:14px;padding:28px;width:360px;
  box-shadow:0 20px 60px rgba(13,30,82,.3);
  border-top:4px solid var(--blue);
}
.modal h3{
  font-family:'Barlow Condensed',sans-serif;font-size:20px;font-weight:900;
  margin-bottom:18px;color:var(--blue-dark);text-transform:uppercase;letter-spacing:1px;
}
.modal label{
  display:block;font-size:11px;font-weight:700;color:var(--blue-dark);
  margin-bottom:5px;margin-top:12px;text-transform:uppercase;letter-spacing:.8px;
}
.modal input,.modal select{
  width:100%;border:1.5px solid var(--border);border-radius:6px;
  padding:9px 12px;font-size:14px;font-family:'Barlow',sans-serif;
  outline:none;transition:.2s;background:#f5f8ff;
}
.modal input:focus,.modal select:focus{border-color:var(--blue-light);background:#fff;box-shadow:0 0 0 3px rgba(37,87,214,.12)}
.modal-info{background:#eff6ff;border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:12px;color:var(--blue-dark);margin-top:12px;font-weight:600}

/* VERIFY BOX */
.modal-verify-box{margin-top:14px}
.modal-verify-inner{
  background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;
  padding:12px 14px;
}
.modal-verify-title{font-size:12px;font-weight:800;color:#92400e;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px}
.modal-verify-detail{font-size:13px;color:#1a3a8f;font-weight:600;margin-bottom:10px;line-height:1.5}
.modal-verify-check{display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:600;color:#374151}
.modal-verify-check input[type=checkbox]{width:16px;height:16px;cursor:pointer;accent-color:var(--blue)}
.modal-btns{display:flex;gap:10px;margin-top:20px}
.btn-submit{
  flex:1;padding:11px;background:var(--blue);color:#fff;border:none;
  border-radius:6px;font-weight:800;cursor:pointer;font-size:13px;
  font-family:'Barlow',sans-serif;transition:.2s;text-transform:uppercase;letter-spacing:.5px;
}
.btn-submit:hover{background:var(--blue-dark);box-shadow:0 4px 12px rgba(26,58,143,.3)}
.btn-cancel{
  flex:1;padding:11px;background:#e8eef8;color:var(--blue-dark);border:none;
  border-radius:6px;font-weight:700;cursor:pointer;font-family:'Barlow',sans-serif;
}
.btn-cancel:hover{background:var(--border)}

/* IMAGE LIGHTBOX */
.model-img-link{display:block;cursor:zoom-in;position:relative}
.model-img-link::after{
  content:'🔍';position:absolute;bottom:4px;right:4px;
  background:rgba(0,0,0,.55);color:#fff;font-size:12px;
  padding:2px 5px;border-radius:5px;opacity:0;transition:opacity .2s;pointer-events:none;
}
.model-img-link:hover::after{opacity:1}
.lightbox-bg{
  display:none;position:fixed;inset:0;
  background:rgba(10,18,50,.88);z-index:300;
  align-items:center;justify-content:center;
  backdrop-filter:blur(6px);cursor:zoom-out;
}
.lightbox-bg.open{display:flex}
.lightbox-inner{
  position:relative;max-width:90vw;max-height:90vh;
  border-radius:14px;overflow:hidden;
  box-shadow:0 24px 80px rgba(0,0,0,.6);
  border:3px solid rgba(255,255,255,.15);
  cursor:default;
}
.lightbox-inner img{
  display:block;max-width:90vw;max-height:88vh;
  width:auto;height:auto;object-fit:contain;
}
.lightbox-caption{
  position:absolute;bottom:0;left:0;right:0;
  background:linear-gradient(transparent,rgba(10,18,50,.85));
  color:#fff;font-family:'Barlow Condensed',sans-serif;
  font-size:18px;font-weight:700;letter-spacing:.5px;
  padding:24px 18px 14px;text-align:center;
}
.lightbox-close{
  position:absolute;top:10px;right:12px;
  background:rgba(0,0,0,.5);border:none;color:#fff;
  font-size:20px;width:34px;height:34px;border-radius:50%;
  cursor:pointer;display:flex;align-items:center;justify-content:center;
  transition:.2s;z-index:10;
}
.lightbox-close:hover{background:rgba(220,38,38,.8);transform:scale(1.1)}

/* =============================================
   RESPONSIVE — ALL SCREEN SIZES
   ============================================= */

/* Large desktops 1400px+ */
@media (min-width:1400px) {
  .container { max-width:1600px; padding:28px 40px; }
  .top-cards { grid-template-columns:1fr 1fr 1fr 1fr; }
}

/* Standard desktops 1200–1399px */
@media (max-width:1399px) {
  .top-cards { grid-template-columns:1fr 1fr 1fr 1fr; gap:14px; }
}

/* Small desktops / large laptops 1024–1199px */
@media (max-width:1199px) {
  .container { padding:20px 18px; }
  .top-cards { grid-template-columns:1fr 1fr; gap:14px; }
  .card-pairs { font-size:44px; }
}

/* Tablets landscape 900–1023px */
@media (max-width:1023px) {
  .top-cards { grid-template-columns:1fr 1fr; gap:12px; }
  .nav { padding:0 16px; height:54px; }
  .nav-logo img { height:38px; }
  .container { padding:16px; }
  .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
  .tbl-header, .tbl-row, .tbl-footer { min-width:700px; }
  .page-title { font-size:20px; }
}

/* Tablets portrait 768–899px */
@media (max-width:899px) {
  .top-cards { grid-template-columns:1fr 1fr; gap:10px; }
  .nav-right { gap:6px; }
  .nav-user { display:none; }
  .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
  .tbl-header, .tbl-row, .tbl-footer { min-width:650px; }
  .recent-table { display:block; overflow-x:auto; white-space:nowrap; }
  .card-pairs { font-size:38px; }
  .date-bar { flex-wrap:wrap; gap:8px; }
}

/* ── MOBILE NAV HAMBURGER MENU ── */
.nav-hamburger {
  display:none;
  background:transparent;border:none;cursor:pointer;
  padding:8px;color:#fff;flex-shrink:0;
}
.nav-hamburger svg { display:block; }
.nav-mobile-overlay {
  display:none;position:fixed;inset:0;background:rgba(10,20,60,.6);
  z-index:150;backdrop-filter:blur(3px);
}
.nav-mobile-overlay.open { display:block; }
.nav-mobile-drawer {
  display:none;position:fixed;top:0;right:0;bottom:0;
  width:72vw;max-width:260px;
  background:linear-gradient(160deg,var(--blue-dark) 0%,var(--blue-mid) 100%);
  z-index:160;flex-direction:column;gap:0;
  box-shadow:-4px 0 24px rgba(0,0,0,.3);
  transform:translateX(100%);transition:transform .28s cubic-bezier(.4,0,.2,1);
}
.nav-mobile-drawer.open { display:flex;transform:translateX(0); }
.nav-mobile-header {
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 18px;border-bottom:1px solid rgba(255,255,255,.12);
}
.nav-mobile-close {
  background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);
  color:#fff;font-size:18px;width:32px;height:32px;border-radius:50%;
  cursor:pointer;display:flex;align-items:center;justify-content:center;
}
.nav-mobile-links { padding:12px 0;overflow-y:auto;flex:1; }
.nav-mobile-links a,.nav-mobile-links .nav-mobile-user {
  display:flex;align-items:center;gap:10px;
  padding:13px 18px;font-size:13px;font-weight:700;
  color:rgba(255,255,255,.85);text-decoration:none;
  border-bottom:1px solid rgba(255,255,255,.06);
  letter-spacing:.3px;transition:background .15s;
}
.nav-mobile-links a:hover { background:rgba(255,255,255,.08);color:#fff; }
.nav-mobile-user { font-size:12px;color:rgba(255,255,255,.5);cursor:default; }

/* Mobile 640–767px */
@media (max-width:767px) {
  .top-cards { grid-template-columns:1fr 1fr; gap:10px; }
  .card { padding:14px 14px; }
  .card-pairs { font-size:34px; }
  .page-title { font-size:16px; margin-bottom:14px; gap:6px; }
  .container { padding:12px; }
  .nav { padding:0 12px; height:52px; }
  .nav-logo img { height:34px; }
  /* Show hamburger, hide desktop nav links */
  .nav-hamburger { display:flex;align-items:center;justify-content:center; }
  .nav-right { display:none; }
  .modal { width:95vw; padding:20px 16px; }
  .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
  .tbl-header, .tbl-row, .tbl-footer { min-width:520px; }
  .date-bar { flex-wrap:wrap; gap:8px; padding:10px 14px; }
  .date-label { font-size:13px; flex-wrap:wrap; }
  .date-bar > div:last-child { width:100%;display:flex;align-items:center;gap:8px;flex-wrap:wrap; }
  .date-input { flex:1;min-width:120px; }
  /* Recent entries - stack as cards on mobile */
  .recent-table thead { display:none; }
  .recent-table, .recent-table tbody, .recent-table tr, .recent-table td { display:block;width:100%; }
  .recent-table tr { border-bottom:2px solid #edf1fb;padding:10px 12px;position:relative; }
  .recent-table tr:last-child { border-bottom:none; }
  .recent-table td { padding:3px 0;border:none;font-size:12px; }
  .recent-table td:before {
    content:attr(data-label);
    font-size:10px;font-weight:700;color:var(--text2);
    text-transform:uppercase;letter-spacing:.7px;
    display:block;margin-bottom:2px;
  }
  .recent-table-scroll-wrap { overflow-x:unset;overflow-y:auto; }
  .recent-table tr:hover td { background:transparent; }
  .recent-table tr:hover { background:#f7f9ff; }
}

/* Small mobile up to 639px */
@media (max-width:639px) {
  .top-cards { grid-template-columns:1fr 1fr; gap:8px; }
  .card { padding:12px 12px; }
  .card-pairs { font-size:30px; }
  .badge-completed { font-size:11px; padding:3px 10px; }
  .page-title { font-size:14px; padding-bottom:10px; }
  .container { padding:10px; }
  .modal { width:100vw; max-height:92vh; overflow-y:auto; border-radius:14px 14px 0 0; padding:20px 14px 28px; }
  .modal-bg.open { align-items:flex-end; }
  .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; border-radius:8px; }
  .tbl-header, .tbl-row, .tbl-footer { min-width:480px; }
  .nav-link { padding:5px 10px; font-size:11px; }
  .deadline-val { font-size:26px; }
  .days-badge { font-size:18px; }
  .alert-icon-col { min-width:56px; padding:14px 12px; }
  .alert-icon-col .alert-megaphone { font-size:26px; }
  .alert-title { font-size:16px; }
  .alert-body { font-size:12px; }
  .model-img-frame { width:130px; height:115px; }
  .card-label { font-size:9px; letter-spacing:1.4px; }
  /* Production table - enforce horizontal scroll with visible hint */
  .tbl-scroll-hint { display:block !important; }
}

/* Very small phones ≤ 390px */
@media (max-width:390px) {
  .top-cards { grid-template-columns:1fr; gap:8px; }
  .card-pairs { font-size:40px; }
  .container { padding:8px; }
  .tbl-header, .tbl-row, .tbl-footer { min-width:440px; }
  .page-title { font-size:13px; }
  .modal { padding:16px 10px 28px; }
  .model-img-frame { width:120px; height:100px; }
}

/* Scroll hint for small screens */
.tbl-scroll-hint {
  display:none;
  font-size:11px;font-weight:700;color:var(--blue-light);
  text-align:center;padding:6px;
  background:#eff6ff;border-bottom:1px solid var(--border);
  letter-spacing:.3px;
}
@media (max-width:639px) {
  .tbl-scroll-hint { display:block; }
}

/* RECENT ENTRIES TABLE */
.recent-wrap{
  background:#fff;border:1px solid var(--border);border-radius:12px;
  overflow:hidden;margin-top:20px;
  box-shadow:0 2px 10px rgba(26,58,143,.06);
}
.recent-table-scroll-wrap{
  max-height:calc(20 * 54px);/* ~20 rows × approx row height */
  overflow-y:auto;
  overflow-x:auto;
}
.recent-table-scroll-wrap::-webkit-scrollbar{width:6px;height:6px}
.recent-table-scroll-wrap::-webkit-scrollbar-track{background:#f1f5f9;border-radius:4px}
.recent-table-scroll-wrap::-webkit-scrollbar-thumb{background:#c8d5f0;border-radius:4px}
.recent-table-scroll-wrap::-webkit-scrollbar-thumb:hover{background:#93a9d8}
.recent-head{
  background:linear-gradient(90deg,var(--blue-dark) 0%,var(--blue-mid) 100%);
  padding:13px 18px;border-bottom:2px solid var(--gold);
  display:flex;align-items:center;gap:8px;
}
.recent-head-title{font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:1px}
.recent-table{width:100%;border-collapse:collapse}
.recent-table thead th{position:sticky;top:0;z-index:2}
.recent-table th{padding:10px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--blue-dark);background:#e8eef8;text-transform:uppercase;letter-spacing:.8px;border-bottom:2px solid var(--border)}
.recent-table td{padding:10px 14px;font-size:13px;border-bottom:1px solid #edf1fb;vertical-align:middle}
.recent-table tr:last-child td{border-bottom:none}
.recent-table tr:hover td{background:#f0f4fc}
.confirmed-badge{
  display:inline-flex;align-items:center;gap:5px;
  background:#dcfce7;color:#166534;
  font-size:11px;font-weight:700;
  padding:3px 9px;border-radius:20px;
  border:1px solid #bbf7d0;
}
.confirmed-badge .cb-dot{width:6px;height:6px;border-radius:50%;background:#22c55e;flex-shrink:0}
.unconfirmed-badge{
  display:inline-flex;align-items:center;gap:5px;
  background:#fef3c7;color:#92400e;
  font-size:11px;font-weight:700;
  padding:3px 9px;border-radius:20px;
  border:1px solid #fde68a;
}
/* ── NEW ROW HIGHLIGHT ── */
@keyframes newRowPulse{
  0%  {background:#fefce8;box-shadow:inset 0 0 0 2px #f59e0b}
  60% {background:#fefce8;box-shadow:inset 0 0 0 2px #f59e0b}
  100%{background:transparent;box-shadow:none}
}
.new-entry-row td{animation:newRowPulse 3s ease forwards}
.new-entry-badge{
  display:inline-flex;align-items:center;gap:3px;
  background:#f59e0b;color:#fff;
  font-size:9px;font-weight:800;
  padding:2px 7px;border-radius:20px;
  letter-spacing:.5px;text-transform:uppercase;
  margin-left:6px;
  animation:newBadgeFade 3s ease forwards;
}
@keyframes newBadgeFade{0%,60%{opacity:1}100%{opacity:0}}

</style>
</head>
<body>

<nav class="nav">
 <div class="nav-logo"><img src="assets/quilla_logo.jpg" alt="quilla"></div>
  <div class="nav-right">
    <?php if ($canEdit): ?>
    <a href="inventory.php" class="nav-link" style="background:rgba(245,197,24,.15);border-color:rgba(245,197,24,.4);color:var(--gold);display:inline-flex;align-items:center;gap:6px">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/><line x1="12" y1="12" x2="12" y2="12.01"/></svg>
      Inventory >>
    </a>
    <?php endif; ?>
    <a href="monitor.php" class="nav-link" target="_blank" style="background:rgba(26,163,74,.15);border-color:rgba(26,163,74,.4);color:#16a34a;display:inline-flex;align-items:center;gap:6px;font-weight:800;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
      Monitor >>
    </a>
    <?php if ($canEdit): ?>
    <a href="input_entry.php" class="nav-link" style="background:rgba(245,130,24,.18);border-color:rgba(245,130,24,.45);color:#ea6c00;display:inline-flex;align-items:center;gap:6px;font-weight:800;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      Input Entry >>
    </a>
    <?php endif; ?>
    <span class="nav-user">👤 Production Panel</span>
    <a href="logout.php" class="nav-link"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
  stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
  <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
  <polyline points="16 17 21 12 16 7" />
  <line x1="21" y1="12" x2="9" y2="12" />
</svg>Sign out</a>
  </div>
  <!-- Hamburger button — visible only on mobile -->
  <button class="nav-hamburger" id="nav-hamburger-btn" onclick="openMobileNav()" aria-label="Open menu">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
      <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>
</nav>

<!-- MOBILE DRAWER OVERLAY -->
<div class="nav-mobile-overlay" id="nav-overlay" onclick="closeMobileNav()"></div>
<div class="nav-mobile-drawer" id="nav-drawer">
  <div class="nav-mobile-header">
    <div class="nav-logo" style="height:auto"><img src="assets/quilla_logo.jpg" alt="quilla" style="height:32px"></div>
    <button class="nav-mobile-close" onclick="closeMobileNav()">✕</button>
  </div>
  <div class="nav-mobile-links">
    <div class="nav-mobile-user">👤 Production Panel</div>
    <?php if ($canEdit): ?>
    <a href="inventory.php" style="color:var(--gold)!important">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg>
      Inventory
    </a>
    <?php endif; ?>
    <a href="monitor.php" target="_blank" style="color:#4ade80!important">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
      Monitor
    </a>
    <?php if ($canEdit): ?>
    <a href="input_entry.php" style="color:#fb923c!important">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      Output Entry
    </a>
    <?php endif; ?>
    <a href="logout.php" style="color:#f87171!important">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Sign Out
    </a>
  </div>
</div>

<div class="container">
  <div class="page-title">📦 Production Daily Output — Realtime Monitoring</div>

  <?php if($msg): ?>
    <?php $isError = str_starts_with($msg, 'Error:'); ?>
    <div class="success-msg" id="successMsg" style="<?= $isError ? 'background:linear-gradient(90deg,#dc2626,#b91c1c);' : '' ?>">
      <?= $isError ? '✗' : '✓' ?> <?= htmlspecialchars($msg) ?>
    </div>
    <script>setTimeout(function(){ var el=document.getElementById('successMsg'); if(el) el.remove(); }, <?= $isError ? 6000 : 3000 ?>);</script>
  <?php endif; ?>
  <?php if($announce && date('Y-m-d', strtotime($announce['created_at'])) === date('Y-m-d')): ?>
  <div class="alert" id="announceBanner">
    <div class="alert-inner">
      <!-- Icon column -->
      <div class="alert-icon-col">
        <span class="alert-megaphone">📢</span>
        <span class="alert-live">LIVE</span>
      </div>
      <!-- Content -->
      <div class="alert-content">
        <div class="alert-title-row">
          <span class="alert-dot"></span>
          <span class="alert-label">Announcement</span>
          <span class="alert-title"><?= htmlspecialchars($announce['title']) ?></span>
        </div>
        <div class="alert-body-wrap">
          <?php
            // Duplicate text so scrolling loops seamlessly
            $bodyText = htmlspecialchars($announce['body']);
            $scrollBody = $bodyText . ' &nbsp;&nbsp;&nbsp;•&nbsp;&nbsp;&nbsp; ' . $bodyText;
          ?>
          <span class="alert-body"><?= $scrollBody ?></span>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- TOP STAT CARDS -->
  <div class="top-cards">
    <div class="card card-target">
      <div class="card-label">🎯 Target Pairs</div>
      <div class="card-pairs" id="idx-target-val"><?= number_format($totalTarget) ?></div>
      <span class="badge-completed"><span id="idx-completed-val"><?= number_format($totalCompleted) ?></span> prs Completed</span>
      <div style="font-size:10px;color:var(--text2);margin-top:3px;font-weight:600;letter-spacing:.3px">
        Based on: <?= htmlspecialchars($finStage['name'] ?? 'Last Stage') ?>
      </div>
      <?php $pctDone = round($totalCompleted/max(1,$totalTarget)*100); ?>
      <div class="prog-track"><div class="prog-fill" id="idx-target-prog-fill" style="width:<?= min(100,$pctDone) ?>%;background:var(--blue-light)"></div></div>
      <div class="prog-label" id="idx-target-prog-label"><?= $pctDone ?>% of target complete</div>
    </div>

    <!-- RUNNING MODEL CARD -->
    <div class="card card-model">
      <div class="card-label">👟 Running Model</div>
      <div class="model-img-frame">
        <?php if($modelImageUrl): ?>
          <a class="model-img-link" href="#" onclick="openLightbox(event)" data-img="<?= htmlspecialchars($modelImageUrl) ?>?v=<?= time() ?>" data-caption="<?= htmlspecialchars($modelName) ?>">
            <img src="<?= htmlspecialchars($modelImageUrl) ?>?v=<?= time() ?>" alt="Running Model">
          </a>
        <?php else: ?>
          <span class="model-no-img">👟</span>
        <?php endif; ?>
      </div>
      <div class="model-name-tag"><?= $modelName ? htmlspecialchars($modelName) : 'No model set' ?></div>
    </div>

    <div class="card card-deadline">
      <div class="card-label">🕐 Production Deadline</div>
      <div class="deadline-val">
        <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span id="idx-deadline-date"><?= $order ? date('M j, Y', strtotime($order['deadline'])) : 'N/A' ?></span>
      </div>
      <div class="days-badge"><span id="idx-deadline-days"><?= $daysLeft ?></span> days remaining</div>
    </div>

    <div class="card card-chart">
      <div class="card-label">📊 Output Consistency <?= date('M Y') ?></div>
      <?php /* bar chart data rendered via JS below */ ?>
      <div class="mini-bar-wrap">
        <canvas id="miniBar" class="mini-bar-canvas"></canvas>
      </div>
      <script>
      (function(){
        var data   = [<?= implode(',', array_column($chartData,'total_pairs')) ?>];
        var labels = [<?= implode(',', array_map(fn($cd)=>"'".date('D',strtotime($cd['log_date']))." ".date('d/m',strtotime($cd['log_date']))."'", $chartData)) ?>];
        var todayLabel = '<?= date('D d/m') ?>';
        var canvas = document.getElementById('miniBar');
        if(!canvas) return;
        var dpr = window.devicePixelRatio || 1;
        var w   = canvas.parentNode.offsetWidth || 200;
        var h   = 110;
        canvas.width  = w * dpr;
        canvas.height = h * dpr;
        canvas.style.width  = w + 'px';
        canvas.style.height = h + 'px';
        var ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);
        var n     = data.length || 1;
        var maxV  = Math.max.apply(null, data) || 1;
        var padL  = 4, padR = 4, padT = 14, padB = 30;
        var barW  = (w - padL - padR) / Math.max(n, 1);
        var gap   = barW * 0.18;
        var bw    = barW - gap;
        var chartH= h - padT - padB;
        data.forEach(function(v, i){
          var isToday = labels[i] === todayLabel;
          var barH = v > 0 ? Math.max(4, (v / maxV) * chartH) : 3;
          var x    = padL + i * barW + gap / 2;
          var y    = padT + chartH - barH;
          var grad = ctx.createLinearGradient(0, y, 0, y + barH);
          if (isToday) {
            grad.addColorStop(0, '#136b13');
            grad.addColorStop(1, '#57c757');
          } else if (v > 0) {
            grad.addColorStop(0, '#4a73e2');
            grad.addColorStop(1, '#102663');
          } else {
            grad.addColorStop(0, '#dde4f0');
            grad.addColorStop(1, '#dde4f0');
          }
          ctx.fillStyle = grad;
          ctx.globalAlpha = v > 0 ? 1 : 0.45;
          ctx.beginPath();
          if(ctx.roundRect){ ctx.roundRect(x, y, bw, barH, [2,2,0,0]); } else { ctx.rect(x, y, bw, barH); }
          ctx.fill();
          ctx.globalAlpha = 1;
          // Value on top of bar
          if (v > 0) {
            ctx.fillStyle = isToday ? '#b45309' : '#1a3a8f';
            ctx.font = 'bold ' + Math.max(6, Math.min(9, Math.floor(bw * 0.6))) + 'px Barlow,sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(v, x + bw / 2, y - 2);
          }
          // Day label (Mon, Tue...)
          var fontSize = Math.max(7, Math.min(9, Math.floor(bw * 0.52)));
          ctx.fillStyle = isToday ? '#b45309' : '#4a5b8a';
          ctx.font = 'bold ' + fontSize + 'px Barlow,sans-serif';
          ctx.textAlign = 'center';
          var parts = labels[i].split(' ');
          ctx.fillText(parts[0] || '', x + bw/2, h - 16);
          // Date label (dd/mm)
          ctx.fillStyle = isToday ? '#d97706' : '#7a8fb0';
          ctx.font = fontSize + 'px Barlow,sans-serif';
          ctx.fillText(parts[1] || '', x + bw/2, h - 4);
        });
      })();
      </script>
    </div>
  </div>

  <!-- DATE BAR -->
  <div class="date-bar">
    <div class="date-label">
      <svg width="18" height="18" fill="none" stroke="#2563eb" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      <?= date('M j, Y', strtotime($date)) ?>
      <?php if(!$isToday): ?>
        <span style="margin-left:10px;background:#f59e0b;color:#fff;font-size:10px;font-weight:800;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px">📅 History View — as of this date</span>
      <?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:10px">
      <?php if(!$isToday): ?>
        <a href="index.php" style="background:#2557d6;color:#fff;font-size:12px;font-weight:700;padding:6px 14px;border-radius:6px;text-decoration:none;white-space:nowrap">↩ Back to Today</a>
      <?php endif; ?>
      <label style="font-size:13px;color:var(--gray)">View date:</label>
      <input class="date-input" type="date" name="date" value="<?= $date ?>" max="<?= date('Y-m-d') ?>" onchange="window.location='index.php?date='+this.value">
    </div>
  </div>

  <!-- PRODUCTION TABLE -->
  <div class="tbl-wrap" id="mainTable">
    <div class="tbl-scroll-hint">👆 Swipe left/right to see all stages</div>
    <div class="tbl-header">
      <div class="th">Target Prs</div>
      <div class="th">Size</div>
      <?php foreach($stages as $st): ?>
        <div class="th" style="flex-direction:column;gap:4px;padding:8px 6px">
          <span><?= htmlspecialchars($st['name']) ?></span>
          <button class="stage-toggle enabled" id="toggle-<?= $st['id'] ?>" onclick="toggleStage(<?= $st['id'] ?>)" title="Disable this stage">
            ✓ Active
          </button>
        </div>
      <?php endforeach; ?>
    </div>

    <?php $firstRow = true; foreach($sizes as $sz): ?>
    <div class="tbl-row">
      <div class="td"><span class="target-pill"><?= $sz['target_qty'] ?></span></div>
      <div class="td"><span class="size-pill"><?= htmlspecialchars($sz['size_label']) ?></span></div>
      <?php foreach($stages as $st): ?>
        <?php
          $qty = $outputs[$sz['id']][$st['id']] ?? 0;
          $isFinishing = ($st['id'] == $finId);
          $topClass = $firstRow ? 'stage-col-top' : 'stage-col';
        ?>
        <div class="td <?= $topClass ?>" data-stage="<?= $st['id'] ?>" data-size="<?= $sz['id'] ?>" id="cell-<?= $sz['id'] ?>-<?= $st['id'] ?>"><?php unset($topClass); ?>
          <div class="qty-cell">
            <?php if($qty > 0): ?>
              <span class="qty-val"><?= $qty ?></span>
            <?php else: ?>
              <span class="qty-zero">0</span>
            <?php endif; ?>
            <?php if($canEdit && !$isFinishing && $isToday): ?>
              <button class="minus-inline"
                title="Subtract qty for <?= htmlspecialchars($sz['size_label']) ?> — <?= htmlspecialchars($st['name']) ?>"
                <?= $qty <= 0 ? 'disabled' : '' ?>
                onclick="openMinusModal(<?= $st['id'] ?>, '<?= htmlspecialchars($st['name']) ?>', <?= $sz['id'] ?>, '<?= htmlspecialchars($sz['size_label']) ?>', <?= $qty ?>)">
                −
              </button>
              <button class="add-inline"
                title="Add qty for <?= htmlspecialchars($sz['size_label']) ?> — <?= htmlspecialchars($st['name']) ?>"
                onclick="openModal(<?= $st['id'] ?>, '<?= htmlspecialchars($st['name']) ?>', <?= $sz['id'] ?>, '<?= htmlspecialchars($sz['size_label']) ?>')">
                +
              </button>
            <?php elseif($isFinishing && $canEdit && $isToday): ?>
              <button class="minus-inline"
                title="Subtract finishing qty for <?= htmlspecialchars($sz['size_label']) ?>"
                <?= $qty <= 0 ? 'disabled' : '' ?>
                onclick="openMinusModal(<?= $st['id'] ?>, '<?= htmlspecialchars($st['name']) ?>', <?= $sz['id'] ?>, '<?= htmlspecialchars($sz['size_label']) ?>', <?= $qty ?>)">
                −
              </button>
              <button class="add-inline"
                title="Add finishing qty for <?= htmlspecialchars($sz['size_label']) ?>"
                onclick="openModal(<?= $st['id'] ?>, '<?= htmlspecialchars($st['name']) ?>', <?= $sz['id'] ?>, '<?= htmlspecialchars($sz['size_label']) ?>')">
                +
              </button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php $firstRow = false; endforeach; ?>

    <!-- FOOTER: per-column completion % -->
    <div class="tbl-footer">
      <div class="footer-td" style="align-items:center;justify-content:center;text-align:center">
        <span style="font-size:10px;font-weight:800;color:var(--blue-dark);text-transform:uppercase;letter-spacing:.8px;line-height:1.5;text-align:center">Production<br>Status</span>
      </div>
      <div class="footer-td"></div>
      <?php foreach($stages as $st):
        $colTotal = $stageTotals[$st['id']] ?? 0;
        $colPct   = $totalTarget > 0 ? min(100, round($colTotal / $totalTarget * 100)) : 0;
        // Color: red<30, amber<60, green<90, teal>=90
        $barColor = $colPct >= 90 ? '#14b8a6' : ($colPct >= 60 ? '#22c55e' : ($colPct >= 30 ? '#f59e0b' : '#dc2626'));
        $isFinishing = ($st['id'] == $finId);
      ?>
      <div class="footer-td stage-col" data-stage="<?= $st['id'] ?>" id="footer-stage-<?= $st['id'] ?>">
        <?php if($isFinishing): ?>
            <div class="total-box" id="total-completed-box" style="margin-bottom:6px">Total: <span id="total-completed-val"><?= number_format($totalCompleted) ?></span> prs</div>
            <div class="col-pct-wrap" style="border-top:none">
              <div class="col-total" id="col-total-<?= $st['id'] ?>"><?= number_format($colTotal) ?> prs</div>
              <div class="col-pct-bar"><div class="col-pct-fill" id="col-fill-<?= $st['id'] ?>" style="width:<?= $colPct ?>%;background:<?= $barColor ?>"></div></div>
              <div class="col-pct-label" id="col-label-<?= $st['id'] ?>" style="color:<?= $barColor ?>"><?= $colPct ?>% done</div>
            </div>
        <?php else: ?>
            <div class="col-pct-wrap">
              <div class="col-total" id="col-total-<?= $st['id'] ?>"><?= number_format($colTotal) ?> prs</div>
              <div class="col-pct-bar"><div class="col-pct-fill" id="col-fill-<?= $st['id'] ?>" style="width:<?= $colPct ?>%;background:<?= $barColor ?>"></div></div>
              <div class="col-pct-label" id="col-label-<?= $st['id'] ?>" style="color:<?= $barColor ?>"><?= $colPct ?>% done</div>
            </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div><!-- end tbl-wrap -->

  <!-- RECENT ENTRIES -->
  <div class="recent-wrap">
    <div class="recent-head">
      <span style="font-size:16px">🕒</span>
      <div class="recent-head-title">Recent Output Entries</div>
    </div>
    <?php if(empty($recentEntries)): ?>
      <div style="padding:24px;text-align:center;color:var(--text2);font-size:13px">No entries yet for this order.</div>
    <?php else: ?>
    <div class="recent-table-scroll-wrap">
    <table class="recent-table">
      <thead>
      <tr>
        <th>Date & Time</th>
        <th>Size</th>
        <th>Stage</th>
        <th>Qty</th>
        <th>Entered By</th>
        <th>✅ Confirmed By</th>
        <th>Reason</th>
      </tr>
      </thead>
      <tbody id="recent-tbody">
      <?php foreach($recentEntries as $re): ?>
      <tr>
        <td data-label="Date & Time">
          <div style="font-weight:700;color:var(--text)"><?= date('M j, Y', strtotime($re['log_date'])) ?></div>
          <div style="display:inline-flex;align-items:center;gap:4px;margin-top:3px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:20px;padding:2px 8px">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <span style="font-size:10px;font-weight:700;color:#1d4ed8;letter-spacing:.3px">
              <?= !empty($re['entered_at']) ? date('h:i A', strtotime($re['entered_at'])) : '—' ?>
            </span>
          </div>
        </td>
        <td data-label="Size"><span style="background:linear-gradient(90deg,var(--blue),var(--blue-light));color:#fff;font-size:11px;font-weight:700;padding:3px 9px;border-radius:5px;white-space:nowrap"><?= htmlspecialchars($re['size_label']) ?></span></td>
        <td data-label="Stage" style="font-weight:600"><?= htmlspecialchars($re['stage']) ?></td>
        <td data-label="Qty">
          <?php if(($re['action'] ?? 'add') === 'minus'): ?>
            <span style="display:inline-flex;align-items:center;gap:4px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:3px 10px">
              <span style="font-size:13px;font-weight:900;color:#dc2626">−<?= $re['qty_produced'] ?></span>
              <span style="font-size:9px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.5px">subtracted</span>
            </span>
          <?php else: ?>
            <span style="display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:3px 10px">
              <span style="font-size:13px;font-weight:900;color:#16a34a">+<?= $re['qty_produced'] ?></span>
              <span style="font-size:9px;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.5px">added</span>
            </span>
          <?php endif; ?>
        </td>
        <td data-label="Entered By"><?= htmlspecialchars($re['entered_by_name']) ?></td>
        <td data-label="Confirmed By">
          <?php if(!empty($re['confirmed_by'])): ?>
            <span class="confirmed-badge">
              <span class="cb-dot"></span>
              <?= htmlspecialchars($re['confirmed_by']) ?>
            </span>
          <?php else: ?>
            <span class="unconfirmed-badge">⚠️ Not confirmed</span>
          <?php endif; ?>
        </td>
        <td data-label="Reason">
          <?php if(($re['action'] ?? 'add') === 'minus' && !empty($re['subtract_reason'])): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:3px 9px;font-size:11px;color:#9a3412;font-weight:600;max-width:180px;word-break:break-word">
              📝 <?= htmlspecialchars($re['subtract_reason']) ?>
            </span>
          <?php elseif(($re['action'] ?? 'add') === 'minus'): ?>
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

</div><!-- end container -->

<!-- ADD QTY MODAL -->
<div class="modal-bg" id="modal">
  <div class="modal">
    <h3>➕ Add Production Qty</h3>
    <form method="POST" id="modal-form" onsubmit="return validateModal()">
      <input type="hidden" name="log_date" value="<?= htmlspecialchars($date) ?>">

      <label>Stage</label>
      <input type="text" id="modal-stage-name" readonly>
      <input type="hidden" name="stage_id" id="modal-stage-id">

      <label>Size</label>
      <input type="text" id="modal-size-name" readonly>
      <input type="hidden" name="size_id" id="modal-size-id">

      <label>Quantity to Add</label>
      <input type="number" name="qty" id="modal-qty" min="1" max="9999" value="" required autofocus placeholder="Enter quantity…">

      <label>Confirmed By <span style="color:#dc2626">*</span></label>
      <select name="confirmed_by" id="modal-confirmed-by" required
        style="width:100%;border:1.5px solid var(--border);border-radius:6px;padding:9px 12px;font-size:14px;font-family:'Barlow',sans-serif;outline:none;transition:.2s;background:#f5f8ff;appearance:auto">
        <option value="">— Select Team Leader —</option>
        <?php foreach($confirmers as $cn): ?>
          <option value="<?= htmlspecialchars($cn) ?>"><?= htmlspecialchars($cn) ?></option>
        <?php endforeach; ?>
        <?php if(empty($confirmers)): ?>
          <option value="N/A">N/A (no names configured)</option>
        <?php endif; ?>
      </select>

      <div class="modal-verify-box" id="modal-verify-box" style="display:none">
        <div class="modal-verify-inner">
          <div class="modal-verify-title">⚠️ Double-Check Before Saving</div>
          <div class="modal-verify-detail" id="modal-verify-detail"></div>
          <label class="modal-verify-check">
            <input type="checkbox" id="modal-confirm-chk" onchange="toggleSave()">
            <span>I confirm this quantity is correct</span>
          </label>
        </div>
      </div>

      <div class="modal-btns">
        <button type="submit" class="btn-submit" id="modal-save-btn" disabled>✓ Save</button>
        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- MINUS QTY MODAL -->
<div class="modal-bg" id="modal-minus">
  <div class="modal">
    <h3 style="color:#dc2626">➖ Subtract Production Qty</h3>
    <form method="POST" id="modal-minus-form" onsubmit="return validateMinusModal()">
      <input type="hidden" name="log_date" value="<?= htmlspecialchars($date) ?>">
      <input type="hidden" name="action" value="minus">

      <label>Stage</label>
      <input type="text" id="minus-stage-name" readonly>
      <input type="hidden" name="stage_id" id="minus-stage-id">

      <label>Size</label>
      <input type="text" id="minus-size-name" readonly>
      <input type="hidden" name="size_id" id="minus-size-id">

      <div style="background:#fff5f5;border:1.5px solid #fca5a5;border-radius:8px;padding:10px 14px;margin-bottom:10px;font-size:13px;color:#991b1b">
        ⚠️ Current qty: <strong id="minus-current-qty">0</strong> prs. Cannot go below 0.
      </div>

      <label>Quantity to Subtract</label>
      <input type="number" name="qty" id="minus-qty" min="1" max="9999" value="" required placeholder="Enter amount to subtract…"
        style="border:1.5px solid #fca5a5" oninput="validateMinusQty()">
      <div id="minus-qty-error" style="color:#dc2626;font-size:12px;margin-top:-8px;margin-bottom:6px;display:none">
        ⚠️ Cannot subtract more than current quantity!
      </div>

      <label>Confirmed By <span style="color:#dc2626">*</span></label>
      <select name="confirmed_by" id="minus-confirmed-by" required oninput="checkMinusReady()" onchange="checkMinusReady()"
        style="width:100%;border:1.5px solid var(--border);border-radius:6px;padding:9px 12px;font-size:14px;font-family:'Barlow',sans-serif;outline:none;transition:.2s;background:#f5f8ff;appearance:auto">
        <option value="">— Select Team Leader —</option>
        <?php foreach($confirmers as $cn): ?>
          <option value="<?= htmlspecialchars($cn) ?>"><?= htmlspecialchars($cn) ?></option>
        <?php endforeach; ?>
        <?php if(empty($confirmers)): ?>
          <option value="N/A">N/A (no names configured)</option>
        <?php endif; ?>
      </select>

      <label style="margin-top:10px">Reason for Subtraction <span style="color:#dc2626">*</span></label>
      <textarea name="subtract_reason" id="minus-reason" required rows="2"
        oninput="checkMinusReady()"
        placeholder="e.g. Defective pairs, miscounted, returned to prev stage…"
        style="width:100%;border:1.5px solid #fca5a5;border-radius:6px;padding:9px 12px;font-size:13px;font-family:'Barlow',sans-serif;outline:none;resize:vertical;background:#fff5f5;box-sizing:border-box;transition:.2s"></textarea>
      <div id="minus-reason-error" style="color:#dc2626;font-size:12px;margin-top:-6px;margin-bottom:6px;display:none">
        ⚠️ Please enter a reason for subtraction.
      </div>

      <!-- PIN Verification -->
      <div style="margin-top:14px;background:#fef9f0;border:1.5px solid #fcd34d;border-radius:10px;padding:12px 14px">
        <label style="font-size:12px;font-weight:800;color:#92400e;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:8px">
          🔐 Teamleader PIN <span style="color:#dc2626">*</span>
        </label>
        <?php if(!$subtractPinSet): ?>
          <div style="font-size:12px;color:#dc2626;font-weight:600">⚠️ No PIN set. Please set a subtract PIN in the Admin Dashboard first.</div>
        <?php else: ?>
        <div style="display:flex;gap:8px;align-items:center">
          <!-- 4 individual digit boxes -->
          <div style="display:flex;gap:6px" id="pin-boxes">
            <?php for($pi=0;$pi<4;$pi++): ?>
            <input type="password" maxlength="1" inputmode="numeric" pattern="[0-9]"
              id="pin-digit-<?= $pi ?>"
              oninput="pinDigitInput(this, <?= $pi ?>)"
              onkeydown="pinDigitKey(event, <?= $pi ?>)"
              style="width:44px;height:50px;text-align:center;font-size:22px;font-weight:900;
                     border:2px solid #fcd34d;border-radius:10px;outline:none;
                     background:#fff;color:#1a3a8f;font-family:'Barlow',sans-serif;
                     transition:border-color .2s,box-shadow .2s">
            <?php endfor; ?>
          </div>
          <div id="pin-status" style="font-size:13px;font-weight:700;min-width:120px"></div>
        </div>
        <input type="hidden" id="minus-pin-verified" value="0">
        <?php endif; ?>
      </div>

      <div class="modal-btns" style="margin-top:16px">
        <button type="submit" class="btn-submit" id="minus-save-btn" disabled style="background:#dc2626;border-color:#dc2626;opacity:.45;cursor:not-allowed">➖ Subtract</button>
        <button type="button" class="btn-cancel" onclick="closeMinusModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- IMAGE LIGHTBOX -->
<div class="lightbox-bg" id="lightbox" onclick="closeLightbox()">
  <div class="lightbox-inner" onclick="event.stopPropagation()">
    <button class="lightbox-close" onclick="closeLightbox()" title="Close">✕</button>
    <img id="lightbox-img" src="" alt="Running Model">
    <div class="lightbox-caption" id="lightbox-caption"></div>
  </div>
</div>

<script>
/* ── QTY MODAL ── */
function openModal(stageId, stageName, sizeId, sizeName) {
  document.getElementById('modal-stage-id').value   = stageId;
  document.getElementById('modal-stage-name').value = stageName;
  document.getElementById('modal-size-id').value    = sizeId;
  document.getElementById('modal-size-name').value  = sizeName;
  document.getElementById('modal-qty').value        = '';
  document.getElementById('modal-confirmed-by').value = '';
  document.getElementById('modal-verify-box').style.display = 'none';
  document.getElementById('modal-confirm-chk').checked = false;
  document.getElementById('modal-save-btn').disabled = true;
  document.getElementById('modal').classList.add('open');
  setTimeout(function(){ document.getElementById('modal-qty').focus(); }, 80);

  // Show verify box when both qty and confirmer are filled
  ['modal-qty','modal-confirmed-by'].forEach(function(id){
    document.getElementById(id).addEventListener('input', showVerify);
    document.getElementById(id).addEventListener('change', showVerify);
  });
}

function showVerify() {
  var qty       = document.getElementById('modal-qty').value;
  var confirmer = document.getElementById('modal-confirmed-by').value;
  var stage     = document.getElementById('modal-stage-name').value;
  var size      = document.getElementById('modal-size-name').value;
  var box       = document.getElementById('modal-verify-box');
  var detail    = document.getElementById('modal-verify-detail');
  var chk       = document.getElementById('modal-confirm-chk');

  if (qty > 0 && confirmer) {
    detail.innerHTML =
      '📦 <strong>' + qty + ' prs</strong> for <strong>' + size + '</strong><br>' +
      '🔧 Stage: <strong>' + stage + '</strong><br>' +
      '✅ Confirmed by: <strong>' + confirmer + '</strong>';
    box.style.display = 'block';
    chk.checked = false;
    document.getElementById('modal-save-btn').disabled = true;
  } else {
    box.style.display = 'none';
    document.getElementById('modal-save-btn').disabled = true;
  }
}

function toggleSave() {
  var chk = document.getElementById('modal-confirm-chk').checked;
  document.getElementById('modal-save-btn').disabled = !chk;
}

function validateModal() {
  var qty       = parseInt(document.getElementById('modal-qty').value);
  var confirmer = document.getElementById('modal-confirmed-by').value;
  var chk       = document.getElementById('modal-confirm-chk').checked;
  if (!qty || qty < 1)   { alert('Please enter a valid quantity.'); return false; }
  if (!confirmer)         { alert('Please select a Team Leader to confirm.'); return false; }
  if (!chk)               { alert('Please tick the confirmation checkbox.'); return false; }
  return true;
}

function closeModal() {
  document.getElementById('modal').classList.remove('open');
}
document.getElementById('modal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});

/* ── IMAGE LIGHTBOX ── */
function openLightbox(e) {
  e.preventDefault();
  var link = e.currentTarget;
  document.getElementById('lightbox-img').src = link.dataset.img;
  document.getElementById('lightbox-caption').textContent = link.dataset.caption || '';
  document.getElementById('lightbox').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeLightbox() {
  document.getElementById('lightbox').classList.remove('open');
  document.body.style.overflow = '';
}

/* ── ESCAPE KEY ── */
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') { closeLightbox(); closeModal(); closeMinusModal(); }
});

/* ── MINUS MODAL ── */
function openMinusModal(stageId, stageName, sizeId, sizeName, currentQty) {
  document.getElementById('minus-stage-id').value   = stageId;
  document.getElementById('minus-stage-name').value = stageName;
  document.getElementById('minus-size-id').value    = sizeId;
  document.getElementById('minus-size-name').value  = sizeName;
  document.getElementById('minus-current-qty').textContent = currentQty;
  document.getElementById('minus-qty').max   = currentQty;
  document.getElementById('minus-qty').value = '';
  document.getElementById('minus-qty-error').style.display    = 'none';
  document.getElementById('minus-reason-error').style.display = 'none';
  document.getElementById('minus-confirmed-by').value = '';
  document.getElementById('minus-reason').value       = '';
  // Reset PIN
  for (var i = 0; i < 4; i++) {
    var d = document.getElementById('pin-digit-' + i);
    if (d) { d.value = ''; d.style.borderColor = '#fcd34d'; d.style.boxShadow = ''; }
  }
  var ps = document.getElementById('pin-status');
  if (ps) ps.innerHTML = '';
  var pv = document.getElementById('minus-pin-verified');
  if (pv) pv.value = '0';
  var btn = document.getElementById('minus-save-btn');
  btn.disabled = true; btn.style.opacity = '.45'; btn.style.cursor = 'not-allowed';
  document.getElementById('modal-minus').classList.add('open');
  setTimeout(function(){
    var d0 = document.getElementById('pin-digit-0');
    if (d0) d0.focus(); else document.getElementById('minus-qty').focus();
  }, 80);
}

function closeMinusModal() {
  document.getElementById('modal-minus').classList.remove('open');
}

/* ── PIN digit input handling ── */
function pinDigitInput(el, idx) {
  // Only allow single digit 0-9
  el.value = el.value.replace(/[^0-9]/g, '').slice(-1);
  if (el.value.length === 1 && idx < 3) {
    var next = document.getElementById('pin-digit-' + (idx + 1));
    if (next) next.focus();
  }
  // If last digit filled, verify
  if (idx === 3 && el.value.length === 1) verifyPin();
  else {
    var pv = document.getElementById('minus-pin-verified');
    if (pv) pv.value = '0';
    var ps = document.getElementById('pin-status');
    if (ps) ps.innerHTML = '';
    checkMinusReady();
  }
}

function pinDigitKey(e, idx) {
  if (e.key === 'Backspace') {
    var cur = document.getElementById('pin-digit-' + idx);
    if (cur && cur.value === '' && idx > 0) {
      var prev = document.getElementById('pin-digit-' + (idx - 1));
      if (prev) { prev.value = ''; prev.focus(); }
    }
    var pv = document.getElementById('minus-pin-verified');
    if (pv) pv.value = '0';
    checkMinusReady();
  }
}

var _pinVerifyTimer = null;
function verifyPin() {
  var pin = '';
  for (var i = 0; i < 4; i++) {
    var d = document.getElementById('pin-digit-' + i);
    pin += d ? (d.value || '') : '';
  }
  if (pin.length < 4) return;

  var statusEl = document.getElementById('pin-status');
  var pv       = document.getElementById('minus-pin-verified');
  if (statusEl) statusEl.innerHTML = '<span style="color:#92400e">⏳ Checking…</span>';

  var fd = new FormData();
  fd.append('pin', pin);
  fetch('verify_pin.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.ok) {
        if (statusEl) statusEl.innerHTML = '<span style="color:#16a34a;font-weight:800">✅ PIN correct!</span>';
        if (pv) pv.value = '1';
        // Green borders on all boxes
        for (var i = 0; i < 4; i++) {
          var d = document.getElementById('pin-digit-' + i);
          if (d) { d.style.borderColor = '#16a34a'; d.style.boxShadow = '0 0 0 3px rgba(22,163,74,.15)'; }
        }
      } else {
        if (statusEl) statusEl.innerHTML = '<span style="color:#dc2626;font-weight:800">❌ Wrong PIN</span>';
        if (pv) pv.value = '0';
        // Red borders + shake
        for (var i = 0; i < 4; i++) {
          var d = document.getElementById('pin-digit-' + i);
          if (d) { d.style.borderColor = '#dc2626'; d.style.boxShadow = '0 0 0 3px rgba(220,38,38,.15)'; }
        }
        // Clear after short delay and refocus first digit
        setTimeout(function() {
          for (var i = 0; i < 4; i++) {
            var d = document.getElementById('pin-digit-' + i);
            if (d) { d.value = ''; d.style.borderColor = '#fcd34d'; d.style.boxShadow = ''; }
          }
          if (statusEl) statusEl.innerHTML = '';
          var d0 = document.getElementById('pin-digit-0');
          if (d0) d0.focus();
        }, 1000);
      }
      checkMinusReady();
    })
    .catch(function() {
      if (statusEl) statusEl.innerHTML = '<span style="color:#dc2626">⚠️ Error</span>';
      if (pv) pv.value = '0';
      checkMinusReady();
    });
}

function checkMinusReady() {
  var qty       = parseInt(document.getElementById('minus-qty').value) || 0;
  var current   = parseInt(document.getElementById('minus-current-qty').textContent) || 0;
  var confirmer = document.getElementById('minus-confirmed-by').value.trim();
  var reason    = document.getElementById('minus-reason').value.trim();
  var pvEl      = document.getElementById('minus-pin-verified');
  var pinOk     = pvEl ? pvEl.value === '1' : true; // if no PIN set, skip check
  var btn       = document.getElementById('minus-save-btn');
  var ready     = qty >= 1 && qty <= current && confirmer !== '' && reason !== '' && pinOk;
  btn.disabled      = !ready;
  btn.style.opacity = ready ? '1' : '.45';
  btn.style.cursor  = ready ? 'pointer' : 'not-allowed';
}

function validateMinusQty() {
  var qty     = parseInt(document.getElementById('minus-qty').value) || 0;
  var current = parseInt(document.getElementById('minus-current-qty').textContent) || 0;
  var errEl   = document.getElementById('minus-qty-error');
  if (qty > current) {
    errEl.style.display = 'block';
  } else {
    errEl.style.display = 'none';
  }
  checkMinusReady();
}

function validateMinusModal() {
  var qty       = parseInt(document.getElementById('minus-qty').value);
  var current   = parseInt(document.getElementById('minus-current-qty').textContent);
  var confirmer = document.getElementById('minus-confirmed-by').value.trim();
  var reason    = document.getElementById('minus-reason').value.trim();
  var pvEl      = document.getElementById('minus-pin-verified');
  var pinOk     = pvEl ? pvEl.value === '1' : true;
  if (!qty || qty < 1)   { alert('Please enter a valid quantity.'); return false; }
  if (qty > current)     { alert('Cannot subtract more than current quantity (' + current + ' prs).'); return false; }
  if (!confirmer)        { alert('Please select a Team Leader to confirm.'); return false; }
  if (!pinOk)            { alert('Please enter the correct Supervisor PIN.'); return false; }
  if (!reason)           {
    document.getElementById('minus-reason-error').style.display = 'block';
    document.getElementById('minus-reason').focus();
    return false;
  }
  return true;
}

document.getElementById('modal-minus').addEventListener('click', function(e) {
  if (e.target === this) closeMinusModal();
});

/* ── STAGE TOGGLE ── */
var disabledStages = JSON.parse(localStorage.getItem('disabledStages') || '[]');

function applyDisabledStages() {
  disabledStages.forEach(function(id) { setStageDisabled(id, true); });
}

function setStageDisabled(stageId, disabled) {
  document.querySelectorAll('[data-stage="' + stageId + '"]').forEach(function(el) {
    if (disabled) {
      el.style.background = '#ececec';
      el.style.opacity    = '0.4';
      el.style.pointerEvents = 'none';
    } else {
      el.style.background    = '';
      el.style.opacity       = '';
      el.style.pointerEvents = '';
    }
  });
  var btn = document.getElementById('toggle-' + stageId);
  if (btn) {
    if (disabled) {
      btn.innerHTML = '✕ Disabled';
      btn.className = 'stage-toggle disabled';
      btn.title     = 'Click to enable this stage';
    } else {
      btn.innerHTML = '✓ Active';
      btn.className = 'stage-toggle enabled';
      btn.title     = 'Click to disable this stage';
    }
  }
}

function toggleStage(stageId) {
  var idx = disabledStages.indexOf(stageId);
  if (idx === -1) { disabledStages.push(stageId); setStageDisabled(stageId, true); }
  else            { disabledStages.splice(idx,1);  setStageDisabled(stageId, false); }
  localStorage.setItem('disabledStages', JSON.stringify(disabledStages));
}

applyDisabledStages();

/* ══════════════════════════════════════════════════════════
   REAL-TIME POLLING  — updates every 5 seconds
   ══════════════════════════════════════════════════════════ */
(function() {
  var INTERVAL  = 5000; // ms between polls
  var currentDate = document.getElementById('date-input')
                    ? document.getElementById('date-input').value
                    : '<?= $date ?>';
  var lastTs    = 0;
  var finId     = <?= (int)$finId ?>;
  var totalTarget = <?= (int)$totalTarget ?>;
  var canEdit   = <?= $canEdit ? 'true' : 'false' ?>;

  // Snapshot of entered_at values from PHP — used as fallback when poll.php omits entered_at
  var knownTimes = {};
  <?php foreach($recentEntries as $_re): ?>
  <?php if(!empty($_re['entered_at'])): ?>
  knownTimes[<?= json_encode($_re['size_label'].'|'.$_re['stage'].'|'.$_re['log_date']) ?>] = <?= json_encode($_re['entered_at']) ?>;
  <?php endif; ?>
  <?php endforeach; ?>

  // Track already-seen entries so only truly new ones get highlighted
  var seenEntryKeys = {};
  <?php foreach($recentEntries as $_re): ?>
  seenEntryKeys[<?= json_encode(($_re['size_label']??'').'|'.($_re['stage']??'').'|'.($_re['log_date']??'').'|'.($_re['qty_produced']??'').'|'.($_re['action']??'add')) ?>] = true;
  <?php endforeach; ?>

  // Live indicator dot
  var indicator = document.createElement('div');
  indicator.id  = 'live-dot';
  indicator.title = 'Live updates active';
  indicator.style.cssText = 'position:fixed;bottom:18px;right:18px;z-index:9999;display:flex;align-items:center;gap:7px;background:#fff;border:1.5px solid #e0e7ff;border-radius:20px;padding:6px 13px 6px 10px;font-size:12px;font-weight:700;color:#142e75;box-shadow:0 2px 12px rgba(20,46,117,.10);cursor:default;user-select:none;';
  indicator.innerHTML = '<span id="live-pulse" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 0 rgba(34,197,94,.4);animation:livePulse 1.4s infinite"></span> LIVE';
  document.body.appendChild(indicator);

  // Pulse animation
  var style = document.createElement('style');
  style.textContent = '@keyframes livePulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.5)}70%{box-shadow:0 0 0 7px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}} @keyframes flashGreen{0%,100%{background:inherit}50%{background:#dcfce7}}';
  document.head.appendChild(style);

  function barColor(pct) {
    return pct >= 90 ? '#14b8a6' : pct >= 60 ? '#22c55e' : pct >= 30 ? '#f59e0b' : '#dc2626';
  }

  function formatNum(n) {
    return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function flashCell(el) {
    el.style.transition = 'background .3s';
    el.style.background = '#dcfce7';
    setTimeout(function() { el.style.background = ''; }, 700);
  }

  function buildRecentRow(re, isNew) {
    var date  = re.log_date ? re.log_date.replace(/-/g,'/') : '';
    var timeStr = '';
    // Use entered_at from poll response; fall back to PHP-rendered snapshot if missing
    var enteredAt = re.entered_at || knownTimes[(re.size_label||'')+'|'+(re.stage||'')+'|'+(re.log_date||'')] || '';
    if (enteredAt) {
      var d = new Date(enteredAt.replace(' ', 'T'));
      if (!isNaN(d.getTime())) {
        var h = d.getHours(), m = d.getMinutes();
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        timeStr = h + ':' + (m < 10 ? '0' : '') + m + ' ' + ampm;
      }
    }
    var isMinus = (re.action === 'minus');
    var qtyHtml = isMinus
      ? '<span style="display:inline-flex;align-items:center;gap:4px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:3px 10px"><span style="font-size:13px;font-weight:900;color:#dc2626">−'+re.qty_produced+'</span><span style="font-size:9px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.5px">subtracted</span></span>'
      : '<span style="display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:3px 10px"><span style="font-size:13px;font-weight:900;color:#16a34a">+'+re.qty_produced+'</span><span style="font-size:9px;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.5px">added</span></span>';
    var confirmedHtml = re.confirmed_by
      ? '<span class="confirmed-badge"><span class="cb-dot"></span>'+escHtml(re.confirmed_by)+'</span>'
      : '<span class="unconfirmed-badge">⚠️ Not confirmed</span>';
    var reasonHtml = (isMinus && re.subtract_reason)
      ? '<span style="display:inline-flex;align-items:center;gap:5px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:3px 9px;font-size:11px;color:#9a3412;font-weight:600;max-width:180px;word-break:break-word">📝 '+escHtml(re.subtract_reason)+'</span>'
      : '<span style="color:#d1d5db;font-size:11px">—</span>';
    var dateCell = '<div style="font-weight:700;color:var(--text)">'+escHtml(date)+'</div>'
                 + (timeStr
                     ? '<div style="display:inline-flex;align-items:center;gap:4px;margin-top:3px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:20px;padding:2px 8px">'
                       + '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
                       + '<span style="font-size:10px;font-weight:700;color:#1d4ed8;letter-spacing:.3px">'+timeStr+'</span>'
                       + '</div>'
                     : '');
    var newBadge = isNew ? '<span class="new-entry-badge">&#10022; NEW</span>' : '';
    var trClass  = isNew ? ' class="new-entry-row"' : '';
    return '<tr'+trClass+'><td data-label="Date &amp; Time" style="color:var(--text2)">'+dateCell+newBadge+'</td>'
         + '<td data-label="Size"><span style="background:linear-gradient(90deg,var(--blue),var(--blue-light));color:#fff;font-size:11px;font-weight:700;padding:3px 9px;border-radius:5px;white-space:nowrap">'+escHtml(re.size_label)+'</span></td>'
         + '<td data-label="Stage" style="font-weight:600">'+escHtml(re.stage)+'</td>'
         + '<td data-label="Qty">'+qtyHtml+'</td>'
         + '<td data-label="Entered By">'+escHtml(re.entered_by_name||'')+'</td>'
         + '<td data-label="Confirmed By">'+confirmedHtml+'</td>'
         + '<td>'+reasonHtml+'</td></tr>';
  }
  function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function applyUpdate(data) {
    if (data.ts === lastTs) return; // nothing changed
    lastTs = data.ts;

    var outputs     = data.outputs      || {};
    var stageTotals = data.stageTotals  || {};
    var newTarget   = data.totalTarget  || totalTarget;
    var newCompleted= data.totalCompleted || 0;

    // ── Sync local totalTarget if server pushed a new value ──────────────
    if (data.totalTarget && data.totalTarget !== totalTarget) {
      totalTarget = data.totalTarget;
      // Update Target Pairs card
      var tval = document.getElementById('idx-target-val');
      if (tval) tval.textContent = formatNum(totalTarget);
    }

    // ── Update Deadline card if server pushed new deadline ────────────────
    if (data.deadline) {
      var parts = data.deadline.split('-');
      var dlocal = new Date(parseInt(parts[0]), parseInt(parts[1])-1, parseInt(parts[2]));
      var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      var dateStr = months[dlocal.getMonth()] + ' ' + dlocal.getDate() + ', ' + dlocal.getFullYear();
      var ddEl = document.getElementById('idx-deadline-date');
      if (ddEl) ddEl.textContent = dateStr;

      // Recalculate working days left (simple Mon-Fri count)
      var today2 = new Date(); today2.setHours(0,0,0,0);
      var dlDate = new Date(parseInt(parts[0]), parseInt(parts[1])-1, parseInt(parts[2]));
      var wdays = 0;
      var cur2 = new Date(today2);
      while (cur2 < dlDate) { cur2.setDate(cur2.getDate()+1); var wd2=cur2.getDay(); if(wd2!==0&&wd2!==6) wdays++; }
      var ddays = document.getElementById('idx-deadline-days');
      if (ddays) ddays.textContent = wdays;
    }

    // ── Update individual qty cells ──────────────────────────
    document.querySelectorAll('[id^="cell-"]').forEach(function(cell) {
      var parts   = cell.id.split('-');
      var sizeId  = parts[1];
      var stageId = parts[2];
      var qty     = (outputs[sizeId] && outputs[sizeId][stageId]) ? parseInt(outputs[sizeId][stageId]) : 0;
      var qtyCell = cell.querySelector('.qty-cell');
      if (!qtyCell) return;

      // Find current displayed value
      var valEl  = qtyCell.querySelector('.qty-val, .qty-zero');
      var curVal = valEl ? parseInt(valEl.textContent) : 0;
      if (curVal === qty) return;

      // Update value display
      if (valEl) {
        valEl.textContent = qty;
        valEl.className   = qty > 0 ? 'qty-val' : 'qty-zero';
      }
      // Update minus button disabled state
      var minusBtn = qtyCell.querySelector('.minus-inline');
      if (minusBtn) minusBtn.disabled = (qty <= 0);
      // Update minus modal onclick qty param
      if (minusBtn) {
        var oc = minusBtn.getAttribute('onclick') || '';
        minusBtn.setAttribute('onclick', oc.replace(/,\s*\d+\s*\)/, ', '+qty+')'));
      }
      flashCell(cell);
    });

    // ── Update footer stage totals ───────────────────────────
    Object.keys(stageTotals).forEach(function(stId) {
      var colTotal = stageTotals[stId] || 0;
      var colPct   = newTarget > 0 ? Math.min(100, Math.round(colTotal / newTarget * 100)) : 0;
      var color    = barColor(colPct);

      var totalEl  = document.getElementById('col-total-' + stId);
      var fillEl   = document.getElementById('col-fill-'  + stId);
      var labelEl  = document.getElementById('col-label-' + stId);

      if (totalEl) totalEl.textContent = formatNum(colTotal) + ' prs';
      if (fillEl)  { fillEl.style.width = colPct + '%'; fillEl.style.background = color; }
      if (labelEl) { labelEl.textContent = colPct + '% done'; labelEl.style.color = color; }
    });

    // ── Update total completed (finishing) ───────────────────
    var tcEl = document.getElementById('total-completed-val');
    if (tcEl) tcEl.textContent = formatNum(newCompleted);

    // ── Update Target card completed badge & progress bar ────
    var icv = document.getElementById('idx-completed-val');
    if (icv) icv.textContent = formatNum(newCompleted);
    var pct2 = totalTarget > 0 ? Math.min(100, Math.round(newCompleted / totalTarget * 100)) : 0;
    var tpf2 = document.getElementById('idx-target-prog-fill');
    if (tpf2) tpf2.style.width = pct2 + '%';
    var tpl = document.getElementById('idx-target-prog-label');
    if (tpl) tpl.textContent = pct2 + '% of target complete';

    // ── Update recent entries table ──────────────────────────
    var tbody = document.getElementById('recent-tbody');
    if (tbody && data.recentEntries && Array.isArray(data.recentEntries) && data.recentEntries.length > 0) {
      // Cache any entered_at values returned by poll so future rebuilds have them
      data.recentEntries.forEach(function(re) {
        if (re.entered_at) {
          knownTimes[(re.size_label||'')+'|'+(re.stage||'')+'|'+(re.log_date||'')] = re.entered_at;
        }
      });
      data.recentEntries.forEach(function(re) {
        if (re.entered_at) knownTimes[(re.size_label||'')+'|'+(re.stage||'')+'|'+(re.log_date||'')] = re.entered_at;
      });
      tbody.innerHTML = data.recentEntries.map(function(re) {
        var key = (re.size_label||'')+'|'+(re.stage||'')+'|'+(re.log_date||'')+'|'+(re.qty_produced||'')+'|'+(re.action||'add');
        var isNew = !seenEntryKeys[key];
        seenEntryKeys[key] = true;
        return buildRecentRow(re, isNew);
      }).join('');
    }
    // If poll returns empty/missing recentEntries, keep existing rendered rows — never wipe.
  }

  function poll() {
    var url = '/quilla_production/poll.php?date=' + encodeURIComponent(currentDate) + '&_=' + Date.now();
    fetch(url, { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        applyUpdate(data);
        document.getElementById('live-pulse').style.background = '#22c55e';
      })
      .catch(function() {
        document.getElementById('live-pulse').style.background = '#dc2626';
      });
  }

  // Listen for date picker changes so poll uses selected date
  var datePicker = document.getElementById('date-input');
  if (datePicker) {
    datePicker.addEventListener('change', function() { currentDate = this.value; lastTs = 0; poll(); });
  }

  // Start polling
  setInterval(poll, INTERVAL);
  setTimeout(poll, 500); // first poll shortly after load
})();

/* ── MOBILE NAV DRAWER ── */
function openMobileNav() {
  document.getElementById('nav-overlay').classList.add('open');
  document.getElementById('nav-drawer').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeMobileNav() {
  document.getElementById('nav-overlay').classList.remove('open');
  document.getElementById('nav-drawer').classList.remove('open');
  document.body.style.overflow = '';
}
// Close drawer on escape
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeMobileNav();
});

</script>
</body>
</html>