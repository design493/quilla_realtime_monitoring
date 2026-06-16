<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
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
requireLogin();

$pdo  = getPDO();
$user = currentUser();

// Only supervisors and admins can access this page
$isAdmin      = ($user['role'] === 'admin');
$isProduction = ($user['role'] === 'supervisor');
$canEdit      = $isAdmin || $isProduction;

// Active order
$order   = $pdo->query("SELECT * FROM production_orders WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
$orderId = $order['id'] ?? 0;

// Sizes
$sizes = [];
if ($orderId) {
    $st = $pdo->prepare("SELECT * FROM order_sizes WHERE order_id=? ORDER BY sort_order");
    $st->execute([$orderId]);
    $sizes = $st->fetchAll();
}

// Stages
$stages = $pdo->query("SELECT * FROM stages WHERE COALESCE(is_active,1)=1 ORDER BY sort_order")->fetchAll();

// Confirmers
$confirmersFile = __DIR__ . '/config/confirmers.json';
$confirmers = file_exists($confirmersFile) ? (json_decode(file_get_contents($confirmersFile), true)['names'] ?? []) : [];

// Subtract PIN
$subtractPinFile = __DIR__ . '/config/subtract_pin.json';
$subtractPinHash = file_exists($subtractPinFile) ? (json_decode(file_get_contents($subtractPinFile), true)['pin_hash'] ?? '') : '';
$subtractPinSet  = !empty($subtractPinHash);

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $sizeId         = (int)($_POST['size_id']         ?? 0);
    $stageId        = (int)($_POST['stage_id']        ?? 0);
    $qty            = (int)($_POST['qty']             ?? 0);
    $logDate        = $_POST['log_date']               ?? date('Y-m-d');
    $confirmedBy    = trim($_POST['confirmed_by']      ?? '');
    $action         = $_POST['action']                 ?? 'add';
    $subtractReason = trim($_POST['subtract_reason']   ?? '');

    // Ensure columns exist
    try { $pdo->exec("ALTER TABLE daily_outputs ADD COLUMN confirmed_by VARCHAR(120) NULL"); } catch(Exception $e){}
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS output_activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_size_id INT NOT NULL, stage_id INT NOT NULL,
        log_date DATE NOT NULL, action ENUM('add','minus') NOT NULL DEFAULT 'add',
        qty_change INT NOT NULL, confirmed_by VARCHAR(120),
        subtract_reason VARCHAR(255) NULL, entered_by INT NOT NULL,
        entered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE output_activity_log ADD COLUMN subtract_reason VARCHAR(255) NULL"); } catch(Exception $e){}

    $validSize  = false;
    $validStage = false;
    if ($sizeId && $orderId) {
        $chk = $pdo->prepare("SELECT id FROM order_sizes WHERE id=? AND order_id=?");
        $chk->execute([$sizeId, $orderId]);
        $validSize = (bool)$chk->fetch();
    }
    if ($stageId) {
        $chkS = $pdo->prepare("SELECT id FROM stages WHERE id=?");
        $chkS->execute([$stageId]);
        $validStage = (bool)$chkS->fetch();
    }

    $finStage = $pdo->query("SELECT id FROM stages WHERE name='Finishing' LIMIT 1")->fetch();
    $finId    = $finStage['id'] ?? 5;

    if ($validSize && $validStage && $qty > 0) {
        if ($action === 'minus') {
            // Double-check: fetch current total strictly for this exact size+stage combination
            $cur = $pdo->prepare("SELECT COALESCE(SUM(qty_produced),0) FROM daily_outputs WHERE order_size_id=? AND stage_id=?");
            $cur->execute([$sizeId, $stageId]);
            $curQty      = (int)$cur->fetchColumn();
            $actualMinus = min($qty, $curQty);

            if ($actualMinus > 0) {
                // Fetch rows strictly for this size+stage only, ordered newest first
                $rows = $pdo->prepare("SELECT id, qty_produced FROM daily_outputs WHERE order_size_id=? AND stage_id=? AND qty_produced > 0 ORDER BY log_date DESC, id DESC");
                $rows->execute([$sizeId, $stageId]);
                $toDeduct = $actualMinus;
                foreach ($rows->fetchAll() as $row) {
                    if ($toDeduct <= 0) break;
                    $deduct    = min($toDeduct, (int)$row['qty_produced']);
                    $newRowQty = (int)$row['qty_produced'] - $deduct;
                    // Strictly match id AND order_size_id AND stage_id to prevent cross-size updates
                    $upd = $pdo->prepare("UPDATE daily_outputs SET qty_produced=?, entered_by=?, confirmed_by=? WHERE id=? AND order_size_id=? AND stage_id=?");
                    $upd->execute([$newRowQty, $user['id'], $confirmedBy, $row['id'], $sizeId, $stageId]);
                    $toDeduct -= $deduct;
                }
            }
            $pdo->prepare("INSERT INTO output_activity_log (order_size_id,stage_id,log_date,action,qty_change,confirmed_by,subtract_reason,entered_by) VALUES (?,?,?,'minus',?,?,?,?)")
                ->execute([$sizeId,$stageId,$logDate,$actualMinus,$confirmedBy,$subtractReason ?: null,$user['id']]);
            $msg = "✅ Na-bawas na ang $actualMinus pairs!";
        } else {
            $pdo->prepare("INSERT INTO daily_outputs (order_size_id,stage_id,log_date,qty_produced,entered_by,confirmed_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE qty_produced=qty_produced+VALUES(qty_produced), confirmed_by=VALUES(confirmed_by)")
                ->execute([$sizeId,$stageId,$logDate,$qty,$user['id'],$confirmedBy]);
            $pdo->prepare("INSERT INTO output_activity_log (order_size_id,stage_id,log_date,action,qty_change,confirmed_by,entered_by) VALUES (?,?,?,'add',?,?,?)")
                ->execute([$sizeId,$stageId,$logDate,$qty,$confirmedBy,$user['id']]);
            $msg = "✅ Na-save na! $qty pairs na-record.";
        }

        // Recalculate daily total
        $nt = $pdo->prepare("SELECT COALESCE(SUM(do.qty_produced),0) FROM daily_outputs do JOIN order_sizes os ON os.id=do.order_size_id WHERE os.order_id=? AND do.stage_id=? AND do.log_date=?");
        $nt->execute([$orderId,$finId,$logDate]);
        $nt = (int)$nt->fetchColumn();
        $pdo->prepare("INSERT INTO daily_order_totals (order_id,log_date,total_pairs) VALUES (?,?,?) ON DUPLICATE KEY UPDATE total_pairs=?")
            ->execute([$orderId,$logDate,$nt,$nt]);

        // Store as session flash so refresh won't re-show it
        $_SESSION['flash_msg']  = $msg;
        $_SESSION['flash_type'] = 'success';
        header("Location: input_entry.php"); exit;
    } else {
        $_SESSION['flash_msg']  = '⚠️ May mali. Siguraduhing kumpleto ang lahat ng field at ang qty ay higit sa 0.';
        $_SESSION['flash_type'] = 'error';
        header("Location: input_entry.php"); exit;
    }
}

// Read and clear flash message (won't reappear on refresh)
$msg     = '';
$msgType = 'success';
if (!empty($_SESSION['flash_msg'])) {
    $msg     = $_SESSION['flash_msg'];
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Today's log — last 10 entries for this user's session
$recentEntries = [];
try {
    $re = $pdo->prepare("
        SELECT al.log_date, os.size_label, s.name as stage,
               al.action, al.qty_change as qty, u.full_name,
               al.confirmed_by, al.entered_at
        FROM output_activity_log al
        JOIN order_sizes os ON os.id=al.order_size_id
        JOIN stages s ON s.id=al.stage_id
        JOIN users u ON u.id=al.entered_by
        WHERE os.order_id=?
        ORDER BY al.entered_at DESC LIMIT 10
    ");
    $re->execute([$orderId]);
    $recentEntries = $re->fetchAll();
} catch(Exception $e){}
?>
<!DOCTYPE html>
<html lang="fil">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>I-Enter ang Output — Quilla Production</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}

:root{
  --blue:#1a3a8f;
  --blue-light:#2557d6;
  --purple:#6c3fc5;
  --green:#16a34a;
  --red:#dc2626;
  --bg:#dde4f0;
  --white:#fff;
  --border:#d0d8eb;
  --text:#0d1e52;
  --text2:#6b7a9e;
  --label:#7a869a;
  --input-bg:#f4f6fb;
  --shadow:0 8px 40px rgba(13,30,82,.18);
}

body{
  font-family:'Inter',sans-serif;
  background:var(--bg);
  color:var(--text);
  min-height:100vh;
  display:flex;
  flex-direction:column;
}

/* ── NAV ── */
.nav{
  background:linear-gradient(90deg,#122a6b,#1e47b0);
  color:#fff;
  display:flex;align-items:center;justify-content:space-between;
  padding:0 28px;height:64px;
  position:sticky;top:0;z-index:100;
  box-shadow:0 2px 16px rgba(13,30,82,.3);
}
.nav-logo{display:flex;align-items:center;height:64px}
.nav-logo img{height:48px;width:auto;object-fit:contain;display:block}
.nav-logo-text{font-size:20px;font-weight:800;color:#fff;letter-spacing:-.3px}
.nav-right{display:flex;align-items:center;gap:10px}
.nav-user{
  background:rgba(255,255,255,.12);
  border:1px solid rgba(255,255,255,.2);
  border-radius:30px;
  padding:7px 16px;
  font-size:13px;font-weight:700;
  display:flex;align-items:center;gap:6px;
}
.nav-link{
  color:rgba(255,255,255,.8);
  text-decoration:none;
  padding:7px 14px;
  border-radius:8px;
  font-size:13px;font-weight:600;
  transition:.18s;
}
.nav-link:hover{background:rgba(255,255,255,.14);color:#fff}

/* ── PAGE WRAPPER ── */
.page-wrap{
  flex:1;
  display:flex;
  align-items:flex-start;
  justify-content:center;
  padding:40px 20px 60px;
}

/* ── TWO-COLUMN SHELL ── */
.two-col{
  display:grid;
  grid-template-columns:500px 1fr;
  gap:24px;
  width:100%;
  max-width:1000px;
  align-items:stretch;
}

/* ── MAIN CARD ── */
.main-card{
  background:#fff;
  border-radius:20px;
  box-shadow:var(--shadow);
  width:100%;
  padding:36px 36px 32px;
}

/* ── CARD HEADER ── */
.card-header{
  display:flex;align-items:center;gap:12px;
  margin-bottom:28px;
}
.card-header-icon{
  font-size:22px;color:var(--purple);font-weight:800;
}
.card-header h1{
  font-size:20px;font-weight:800;
  color:var(--text);
  letter-spacing:-.3px;
  text-transform:uppercase;
}

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
.toast-icon{font-size:48px;line-height:1;margin-bottom:4px}
.toast-title{font-size:20px;font-weight:900;letter-spacing:-.3px}
.toast-sub{font-size:13px;font-weight:600;opacity:.75;margin-top:2px}
.toast.success{
  background:#fff;
  border:3px solid #16a34a;
  color:#14532d;
}
.toast.success .toast-icon-wrap{
  width:64px;height:64px;border-radius:50%;
  background:linear-gradient(135deg,#16a34a,#22c55e);
  display:flex;align-items:center;justify-content:center;
  font-size:32px;
  box-shadow:0 6px 20px rgba(22,163,74,.35);
  margin-bottom:6px;
}
.toast.error{
  background:#fff;
  border:3px solid #dc2626;
  color:#7f1d1d;
}
.toast.error .toast-icon-wrap{
  width:64px;height:64px;border-radius:50%;
  background:linear-gradient(135deg,#dc2626,#ef4444);
  display:flex;align-items:center;justify-content:center;
  font-size:32px;
  box-shadow:0 6px 20px rgba(220,38,38,.35);
  margin-bottom:6px;
}
.toast-overlay{
  position:fixed;inset:0;
  background:rgba(13,30,82,.25);
  backdrop-filter:blur(3px);
  z-index:9998;
  opacity:0;
  animation:fadeIn .3s ease forwards;
}
@keyframes toastPop{
  0%  {opacity:0;transform:translate(-50%,-50%) scale(.7)}
  100%{opacity:1;transform:translate(-50%,-50%) scale(1)}
}
@keyframes fadeIn{
  from{opacity:0}to{opacity:1}
}
@keyframes toastOut{
  0%  {opacity:1;transform:translate(-50%,-50%) scale(1)}
  100%{opacity:0;transform:translate(-50%,-50%) scale(.8)}
}


/* ── FIELD ── */
.field{margin-bottom:18px}
.field-label{
  display:block;
  font-size:11px;font-weight:700;
  color:var(--label);
  letter-spacing:.8px;
  text-transform:uppercase;
  margin-bottom:7px;
}
.field-label .req{color:var(--red);margin-left:2px}

.field-input{
  width:100%;
  background:var(--input-bg);
  border:1.5px solid var(--border);
  border-radius:10px;
  padding:13px 16px;
  font-size:15px;
  font-weight:500;
  font-family:'Inter',sans-serif;
  color:var(--text);
  outline:none;
  transition:.18s;
  appearance:auto;
}
.field-input:focus{
  border-color:var(--blue-light);
  background:#fff;
  box-shadow:0 0 0 3px rgba(37,87,214,.10);
}
.field-input.err-border{
  border-color:var(--red) !important;
  background:#fff5f5 !important;
  box-shadow:0 0 0 3px rgba(220,38,38,.12) !important;
}
.inline-err{
  margin-top:6px;
  padding:7px 12px;
  background:#fef2f2;
  border:1.5px solid #fca5a5;
  border-radius:8px;
  color:#b91c1c;
  font-size:12px;
  font-weight:700;
  display:flex;
  align-items:center;
  gap:6px;
  animation:errPop .2s cubic-bezier(.175,.885,.32,1.275);
}
@keyframes errPop{
  from{opacity:0;transform:translateY(-4px)}
  to{opacity:1;transform:translateY(0)}
}
.field-input::placeholder{color:#b0bad4;font-weight:400}

/* Qty row — input + stepper buttons */
.qty-row{
  display:flex;align-items:center;gap:0;
  background:var(--input-bg);
  border:1.5px solid var(--border);
  border-radius:10px;
  overflow:hidden;
  transition:.18s;
}
.qty-row:focus-within{border-color:var(--blue-light);background:#fff;box-shadow:0 0 0 3px rgba(37,87,214,.10)}
.qty-btn{
  background:transparent;
  border:none;
  color:var(--blue);
  font-size:22px;font-weight:800;
  width:50px;height:52px;
  cursor:pointer;
  transition:.15s;
  font-family:'Inter',sans-serif;
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;
  user-select:none;
}
.qty-btn:hover{background:#e6ecfa;color:var(--blue-light)}
.qty-btn:active{background:#d0daf5}
.qty-num{
  flex:1;
  border:none;outline:none;
  text-align:center;
  font-size:22px;font-weight:700;
  color:var(--text);
  font-family:'Inter',sans-serif;
  background:transparent;
  padding:0 8px;
  height:52px;
  min-width:0;
}

/* ── ACTION TOGGLE ── */
.action-row{
  display:grid;grid-template-columns:1fr 1fr;
  gap:12px;margin-bottom:18px;
  padding-top:12px;
}
/* ── ACTION CARDS ── */
.action-card{
  border:2.5px solid var(--border);
  border-radius:12px;
  background:var(--input-bg);
  padding:18px 12px 14px;
  text-align:center;
  cursor:pointer;
  transition:all .2s ease;
  position:relative;
  font-family:'Inter',sans-serif;
  user-select:none;
}
.action-card .ac-icon{
  font-size:28px;display:block;margin-bottom:7px;
  transition:transform .2s ease;
}
.action-card .ac-label{
  font-size:15px;font-weight:800;color:var(--text);
  letter-spacing:.3px;display:block;
}
.action-card .ac-sub{
  font-size:11px;color:var(--text2);font-weight:500;
  display:block;margin-top:3px;
}

/* Hover (unselected) */
.action-card:hover{
  border-color:#94a3b8;
  background:#e8edf8;
  transform:translateY(-1px);
}

/* ── ADD selected — solid green ── */
.action-card.sel-add{
  border-color:#15803d;
  background: #18c900;
  box-shadow:0 6px 20px rgba(22,163,74,.40);
  transform:translateY(-2px);
}
.action-card.sel-add .ac-icon{
  transform:scale(1.2);
  filter:drop-shadow(0 2px 4px rgba(0,0,0,.2));
}
.action-card.sel-add .ac-label{
  color:#fff;
  font-size:16px;
  text-shadow:0 1px 3px rgba(0,0,0,.2);
}
.action-card.sel-add .ac-sub{color:rgba(255,255,255,.8)}
.action-card.sel-add::after{
  content:'✓ SELECTED';
  position:absolute;top:-10px;left:50%;transform:translateX(-50%);
  background:#15803d;color:#fff;
  font-size:9px;font-weight:800;letter-spacing:1px;
  padding:2px 8px;border-radius:20px;
  white-space:nowrap;
  box-shadow:0 2px 6px rgba(21,128,61,.4);
}

/* ── SUBTRACT selected — solid red ── */
.action-card.sel-minus{
  border-color:#991b1b;
  background:linear-gradient(135deg,#dc2626,#991b1b);
  box-shadow:0 6px 20px rgba(220,38,38,.40);
  transform:translateY(-2px);
}
.action-card.sel-minus .ac-icon{
  transform:scale(1.2);
  filter:drop-shadow(0 2px 4px rgba(0,0,0,.2));
}
.action-card.sel-minus .ac-label{
  color:#fff;
  font-size:16px;
  text-shadow:0 1px 3px rgba(0,0,0,.2);
}
.action-card.sel-minus .ac-sub{color:rgba(255,255,255,.8)}
.action-card.sel-minus::after{
  content:'✓ SELECTED';
  position:absolute;top:-10px;left:50%;transform:translateX(-50%);
  background:#991b1b;color:#fff;
  font-size:9px;font-weight:800;letter-spacing:1px;
  padding:2px 8px;border-radius:20px;
  white-space:nowrap;
  box-shadow:0 2px 6px rgba(153,27,27,.4);
}

/* Pulse animation on select */
@keyframes cardPop{
  0%  {transform:scale(1)   translateY(0)}
  40% {transform:scale(1.04)translateY(-3px)}
  100%{transform:scale(1)   translateY(-2px)}
}
.action-card.sel-add,
.action-card.sel-minus{animation:cardPop .25s ease forwards}

/* ── DIVIDER ── */
.divider{height:1px;background:var(--border);margin:4px 0 20px}

/* ── SUBTRACT REASON ── */
.sub-reason{display:none;animation:slideDown .2s ease}
.sub-reason.show{display:block}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}

/* ── BUTTON ROW ── */
.btn-row{
  display:flex;gap:10px;margin-top:26px;
}
.btn-save{
  flex:1;
  padding:15px;
  background:var(--blue);
  color:#fff;border:none;
  border-radius:10px;
  font-size:15px;font-weight:700;
  font-family:'Inter',sans-serif;
  cursor:pointer;
  transition:.18s;
  display:flex;align-items:center;justify-content:center;gap:8px;
  letter-spacing:-.1px;
}
.btn-save:hover{background:#122a6b;box-shadow:0 4px 16px rgba(26,58,143,.28)}
.btn-save:active{transform:scale(.98)}
.btn-save:disabled{opacity:.5;cursor:not-allowed}
.btn-save.danger{background:var(--red)}
.btn-save.danger:hover{background:#991b1b}

.btn-cancel{
  padding:15px 22px;
  background:#f1f4fb;
  color:var(--text2);border:1.5px solid var(--border);
  border-radius:10px;
  font-size:15px;font-weight:600;
  font-family:'Inter',sans-serif;
  cursor:pointer;
  transition:.18s;
  text-decoration:none;
  display:flex;align-items:center;justify-content:center;
}
.btn-cancel:hover{background:#e4e9f5;color:var(--text)}

/* ── SUMMARY STRIP ── */
.summary-strip{
  background:linear-gradient(90deg,#122a6b,#1e47b0);
  border-radius:12px;
  padding:14px 20px;
  display:flex;align-items:center;justify-content:space-evenly;
  gap:10px;
  margin-bottom:24px;
  flex-wrap:wrap;
}
.sum-item{text-align:center;color:#fff}
.sum-num{font-size:22px;font-weight:800}
.sum-lbl{font-size:10px;font-weight:600;opacity:.7;text-transform:uppercase;letter-spacing:.5px;margin-top:1px}

/* ── RECENT LOG ── */
.recent-card{
  background:#fff;
  border-radius:16px;
  box-shadow:0 4px 24px rgba(13,30,82,.10);
  width:100%;
  overflow:hidden;
  display:flex;
  flex-direction:column;
  height:100%;
}
.recent-hd{
  background:linear-gradient(90deg,#122a6b,#2557d6);
  color:#fff;padding:14px 20px;
  display:flex;align-items:center;gap:8px;
  font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;
  flex-shrink:0;
}
.log-scroll{
  overflow-y:auto;
  flex:1;
}
.log-scroll::-webkit-scrollbar{width:5px}
.log-scroll::-webkit-scrollbar-track{background:#f0f3fb}
.log-scroll::-webkit-scrollbar-thumb{background:#c8d5f0;border-radius:4px}
.log-row{
  display:flex;align-items:center;gap:12px;
  padding:13px 18px;
  border-bottom:1px solid #f0f3fb;
  transition:background .12s;
}
.log-row:last-child{border-bottom:none}
.log-row:hover{background:#f7f9ff}
.log-dot{
  width:36px;height:36px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  font-size:18px;flex-shrink:0;
}
.log-dot.add  {background:#dcfce7}
.log-dot.minus{background:#fef2f2}
.log-info{flex:1;min-width:0}
.log-main{font-size:14px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.log-sub {font-size:12px;color:var(--text2);font-weight:500;margin-top:1px}
.log-qty{font-size:16px;font-weight:800;flex-shrink:0}
.log-qty.add  {color:var(--green)}
.log-qty.minus{color:var(--red)}

/* ── PIN MODAL ── */
.pin-overlay{
  display:none;position:fixed;inset:0;
  background:rgba(13,30,82,.55);z-index:300;
  align-items:center;justify-content:center;
  backdrop-filter:blur(4px);
}
.pin-overlay.open{display:flex}
.pin-box{
  background:#fff;border-radius:18px;
  padding:30px 28px;width:320px;
  box-shadow:0 20px 60px rgba(13,30,82,.3);
  text-align:center;
}
.pin-box h3{font-size:18px;font-weight:800;color:var(--text);margin-bottom:6px}
.pin-box p{font-size:13px;color:var(--text2);font-weight:500;margin-bottom:18px}
.pin-input{
  width:100%;border:2px solid var(--border);border-radius:10px;
  padding:14px;font-size:26px;font-weight:700;
  text-align:center;letter-spacing:6px;
  font-family:'Inter',sans-serif;outline:none;
  color:var(--text);background:var(--input-bg);
}
.pin-input:focus{border-color:var(--blue-light);box-shadow:0 0 0 3px rgba(37,87,214,.10);background:#fff}
.pin-err{color:var(--red);font-size:13px;font-weight:600;min-height:18px;margin-top:8px}
.pin-btns{display:flex;gap:8px;margin-top:16px}
.pin-ok{flex:1;padding:13px;background:var(--blue);color:#fff;border:none;border-radius:9px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif}
.pin-cn{flex:1;padding:13px;background:#f1f4fb;color:var(--text2);border:1.5px solid var(--border);border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif}

/* ── NO ORDER / NO ACCESS ── */
.empty-state{text-align:center;padding:50px 20px}
.empty-state .ei{font-size:56px;margin-bottom:12px}
.empty-state h2{font-size:20px;font-weight:700;color:var(--text2)}
.empty-state p{font-size:14px;color:var(--text2);margin-top:6px;font-weight:500}

/* ── RESPONSIVE ── */
@media(max-width:900px){
  .two-col{
    grid-template-columns:1fr;
    max-width:500px;
  }
  .recent-card{
    height:auto;
    max-height:400px;
  }
}
@media(max-width:540px){
  .main-card{padding:24px 18px 22px;border-radius:14px}
  .nav{padding:0 14px}
  .page-wrap{padding:20px 10px 50px}
  .nav-user{display:none}
  .summary-strip{padding:12px 14px}
}
</style>
</head>
<body>

<!-- NAV -->
<nav class="nav">
  <div class="nav-logo">
    <img src="assets/quilla_logo.jpg" onerror="this.style.display='none'" alt="Quilla">
  </div>
  <div class="nav-right">
    <span class="nav-user">👤 <?= htmlspecialchars($user['full_name']) ?></span>
    <a class="nav-link" href="index.php">📊 Dashboard</a>
    <a class="nav-link" href="logout.php">Logout</a>
  </div>
</nav>

<div class="page-wrap">
  <div class="two-col">

  <!-- LEFT: msg + summary + form -->
  <div>

  <?php if ($msg):
    $isError = ($msgType === 'error');
    $icon    = $isError ? '✕' : '✓';
    $title   = $isError ? 'May Mali!' : (strpos($msg, 'bawas') !== false ? 'Na-bawas na!' : 'Na-save na!');
    $sub     = htmlspecialchars($msg);
  ?>
  <div class="toast-overlay" id="toastOverlay"></div>
  <div class="toast <?= $isError ? 'error' : 'success' ?>" id="toastBox">
    <div class="<?= $isError ? 'error' : 'success' ?> toast-icon-wrap"><?= $icon ?></div>
    <div class="toast-title"><?= $title ?></div>
    <div class="toast-sub"><?= $sub ?></div>
  </div>
  <?php endif; ?>

  <?php if (!$orderId): ?>
    <div class="main-card">
      <div class="empty-state">
        <div class="ei">📭</div>
        <h2>Walang aktibong order</h2>
        <p>Makipag-ugnayan sa admin para mag-set ng bagong production order.</p>
      </div>
    </div>

  <?php elseif (!$canEdit): ?>
    <div class="main-card">
      <div class="empty-state">
        <div class="ei">⛔</div>
        <h2>Walang access</h2>
        <p>Kailangan ng supervisor o admin account para makapag-input ng production.</p>
      </div>
    </div>

  <?php else:
    // Summary numbers
    $finStageRow2 = $pdo->query("SELECT id FROM stages WHERE name='Finishing' LIMIT 1")->fetch();
    $finId2       = $finStageRow2['id'] ?? 5;
    $todayQ2      = $pdo->prepare("SELECT COALESCE(SUM(do.qty_produced),0) FROM daily_outputs do JOIN order_sizes os ON os.id=do.order_size_id WHERE os.order_id=? AND do.stage_id=? AND do.log_date=CURDATE()");
    $todayQ2->execute([$orderId,$finId2]);
    $todayFinished2 = (int)$todayQ2->fetchColumn();
    $totalTarget2   = array_sum(array_column($sizes,'target_qty'));
    $daysLeft2      = $order ? countWorkingDaysUntilDeadline($order['deadline']) : 0;
  ?>

  <!-- SUMMARY STRIP -->
  <div class="summary-strip">
    <div class="sum-item">
      <div class="sum-num"><?= number_format($todayFinished2) ?></div>
      <div class="sum-lbl">✅ Tapos Ngayon</div>
    </div>
    <div class="sum-item">
      <div class="sum-num"><?= number_format($totalTarget2) ?></div>
      <div class="sum-lbl">🎯 Target Pairs</div>
    </div>
    <div class="sum-item">
      <div class="sum-num"><?= $daysLeft2 ?></div>
      <div class="sum-lbl">📅 Araw Natitira</div>
    </div>
  </div>

  <!-- MAIN FORM CARD -->
  <div class="main-card">

    <!-- Header -->
    <div class="card-header">
      <span class="card-header-icon">+</span>
      <h1>Add Production Qty</h1>
    </div>

    <form method="POST" action="input_entry.php" id="mainForm">
      <input type="hidden" name="log_date" value="<?= date('Y-m-d') ?>">

      <!-- ACTION -->
      <div class="field">
        <label class="field-label">Action</label>
        <div class="action-row">
          <div class="action-card" id="lbl-add" onclick="toggleAction('add')">
            <span class="ac-icon">➕</span>
            <span class="ac-label">ADD</span>
            <span class="ac-sub">Mag-dagdag ng output</span>
          </div>
          <div class="action-card" id="lbl-minus" onclick="toggleAction('minus')">
            <span class="ac-icon">➖</span>
            <span class="ac-label">SUBTRACT</span>
            <span class="ac-sub">I-correct ang record</span>
          </div>
        </div>
        <input type="hidden" name="action" id="actionInput" value="">
      </div>

      <!-- STAGE -->
      <div class="field">
        <label class="field-label" for="stageSelect">Stage</label>
        <select name="stage_id" id="stageSelect" class="field-input" required>
          <option value="">— Piliin ang proseso —</option>
          <?php foreach ($stages as $st): ?>
          <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- SIZE -->
      <div class="field">
        <label class="field-label" for="sizeSelect">Size</label>
        <select name="size_id" id="sizeSelect" class="field-input" required>
          <option value="">— Piliin ang size —</option>
          <?php foreach ($sizes as $sz): ?>
          <option value="<?= $sz['id'] ?>">Size <?= htmlspecialchars($sz['size_label']) ?> (target: <?= number_format($sz['target_qty']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- QUANTITY -->
      <div class="field">
        <label class="field-label">Quantity to Add / Subtract</label>
        <div class="qty-row">
          <button type="button" class="qty-btn" id="qtyMinus">−</button>
          <input type="number" name="qty" id="qtyInput"
            class="qty-num" value="1" min="1" max="9999" required
            inputmode="numeric" pattern="[0-9]*">
          <button type="button" class="qty-btn" id="qtyPlus">+</button>
        </div>
      </div>

      <!-- CONFIRMED BY -->
      <?php if (!empty($confirmers)): ?>
      <div class="field">
        <label class="field-label" for="confirmerSelect">Confirmed By <span class="req">*</span></label>
        <select name="confirmed_by" id="confirmerSelect" class="field-input">
          <option value="">— Select Team Leader —</option>
          <?php foreach ($confirmers as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
      <input type="hidden" name="confirmed_by" value="">
      <?php endif; ?>

      <!-- SUBTRACT REASON -->
      <div class="sub-reason field" id="subReasonWrap">
        <div class="divider"></div>
        <label class="field-label" for="subtractReason">Reason for Subtraction <span class="req">*</span></label>
        <input type="text" name="subtract_reason" id="subtractReason"
          class="field-input"
          placeholder="Halimbawa: Mali ang narecord, sira ang pairs...">
        <?php if ($subtractPinSet): ?>
        <div style="margin-top:10px;background:#fffbeb;border:1.5px solid #fde68a;border-radius:8px;padding:11px 14px;font-size:13px;color:#92400e;font-weight:600;">
          🔐 Kailangan ng supervisor PIN para sa subtract.
        </div>
        <?php endif; ?>
      </div>

      <!-- BUTTONS -->
      <div class="btn-row">
        <button type="button" class="btn-save" id="submitBtn" onclick="handleSubmit()">
          <span id="submitIcon">✓</span>
          <span id="submitText">SAVE</span>
        </button>
        <a href="index.php" class="btn-cancel">Cancel</a>
      </div>

    </form>
  </div><!-- /main-card -->

  <?php endif; ?>

  </div><!-- /left col -->

  <!-- RIGHT: Recent Records -->
  <?php if (!empty($recentEntries)): ?>
  <div class="recent-card">
    <div class="recent-hd">
      <span>📜</span> Pinakabagong Records
    </div>
    <div class="log-scroll">
    <?php foreach ($recentEntries as $re):
      $isMinus = ($re['action'] === 'minus');
      $t = $re['entered_at'] ? date('g:i A', strtotime($re['entered_at'])) : '';
    ?>
    <div class="log-row">
      <div class="log-dot <?= $isMinus ? 'minus' : 'add' ?>">
        <?= $isMinus ? '➖' : '✅' ?>
      </div>
      <div class="log-info">
        <div class="log-main"><?= htmlspecialchars($re['stage']) ?> — Size <?= htmlspecialchars($re['size_label']) ?></div>
        <div class="log-sub"><?= htmlspecialchars($re['full_name']) ?><?= $re['confirmed_by'] ? ' · ✓ '.htmlspecialchars($re['confirmed_by']) : '' ?><?= $t ? ' · '.$t : '' ?></div>
      </div>
      <div class="log-qty <?= $isMinus ? 'minus' : 'add' ?>">
        <?= $isMinus ? '−' : '+' ?><?= number_format($re['qty']) ?>
      </div>
    </div>
    <?php endforeach; ?>
    </div><!-- /log-scroll -->
  </div>
  <?php else: ?>
  <div class="recent-card" style="min-height:180px;display:flex;flex-direction:column">
    <div class="recent-hd"><span>📜</span> Pinakabagong Records</div>
    <div style="flex:1;display:flex;align-items:center;justify-content:center;color:var(--text2);font-size:14px;font-weight:600;padding:30px">
      Wala pang records ngayon.
    </div>
  </div>
  <?php endif; ?>

  </div><!-- /two-col -->
</div><!-- /page-wrap -->

<!-- PIN MODAL -->
<?php if (($subtractPinSet ?? false) && $canEdit): ?>
<div class="pin-overlay" id="pinModal">
  <div class="pin-box">
    <div style="font-size:40px;margin-bottom:10px">🔐</div>
    <h3>Supervisor PIN</h3>
    <p>Ilagay ang PIN para makapag-subtract.</p>
    <input type="password" class="pin-input" id="pinInput"
      maxlength="8" placeholder="••••"
      inputmode="numeric" pattern="[0-9]*"
      oninput="document.getElementById('pinErr').textContent=''">
    <div class="pin-err" id="pinErr"></div>
    <div class="pin-btns">
      <button class="pin-cn" onclick="closePinModal()">Cancel</button>
      <button class="pin-ok" onclick="verifyPin()">Confirm</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
var currentAction = '';
var pinVerified   = false;
var subtractPinSet = <?= ($subtractPinSet ?? false) ? 'true' : 'false' ?>;

/* ── ACTION TOGGLE (select & deselect) ── */
function toggleAction(a) {
  var lblAdd   = document.getElementById('lbl-add');
  var lblMinus = document.getElementById('lbl-minus');
  var inp      = document.getElementById('actionInput');
  var wrap     = document.getElementById('subReasonWrap');
  var btn      = document.getElementById('submitBtn');

  // If already selected — deselect
  if (currentAction === a) {
    currentAction = '';
    inp.value = '';
    lblAdd.className   = 'action-card';
    lblMinus.className = 'action-card';
    if (wrap) wrap.classList.remove('show');
    pinVerified = false;
    btn.className = 'btn-save';
    document.getElementById('submitIcon').textContent = '✓';
    document.getElementById('submitText').textContent = 'SAVE';
    return;
  }

  // Select new action
  currentAction = a;
  inp.value = a;
  lblAdd.className   = 'action-card' + (a==='add'   ? ' sel-add'   : '');
  lblMinus.className = 'action-card' + (a==='minus' ? ' sel-minus' : '');
  if (wrap) wrap.classList.toggle('show', a === 'minus');
  if (a !== 'minus') pinVerified = false;
  btn.className = 'btn-save' + (a === 'minus' ? ' danger' : '');
  document.getElementById('submitIcon').textContent = a === 'minus' ? '−' : '✓';
  document.getElementById('submitText').textContent = a === 'minus' ? 'SUBTRACT' : 'SAVE';
}

/* ── QTY STEPPER ── */
function changeQty(d) {
  var inp = document.getElementById('qtyInput');
  inp.value = Math.max(1, Math.min(9999, (parseInt(inp.value)||0) + d));
}
var _ht, _hi;
function startHold(d) {
  changeQty(d);
  _ht = setTimeout(function(){ _hi = setInterval(function(){ changeQty(d*5); }, 80); }, 450);
}
function stopHold() { clearTimeout(_ht); clearInterval(_hi); }

['mousedown','touchstart'].forEach(function(ev) {
  document.getElementById('qtyPlus') .addEventListener(ev, function(e){ if(ev==='touchstart'){e.preventDefault();} startHold(1);  }, {passive:false});
  document.getElementById('qtyMinus').addEventListener(ev, function(e){ if(ev==='touchstart'){e.preventDefault();} startHold(-1); }, {passive:false});
});
['mouseup','mouseleave','touchend'].forEach(function(ev) {
  document.getElementById('qtyPlus') .addEventListener(ev, stopHold);
  document.getElementById('qtyMinus').addEventListener(ev, stopHold);
});

/* ── SUBMIT ── */
function handleSubmit() {
  if (!currentAction) { showFieldError(null, '⚠️ Piliin muna ang Action: ADD o SUBTRACT!'); return; }

  var stageVal = document.getElementById('stageSelect').value;
  if (!stageVal) { showFieldError('stageSelect', '⚠️ Piliin muna ang Stage!'); return; }

  var sizeVal  = document.getElementById('sizeSelect').value;
  if (!sizeVal)  { showFieldError('sizeSelect', '⚠️ Piliin muna ang Size!'); return; }

  var qty = parseInt(document.getElementById('qtyInput').value) || 0;
  if (qty < 1) { showFieldError('qtyInput', '⚠️ Ang quantity ay dapat higit sa 0!'); return; }

  var confirmerEl = document.getElementById('confirmerSelect');
  if (confirmerEl && !confirmerEl.value) { showFieldError('confirmerSelect', '⚠️ Piliin ang Confirmed By (Team Leader)!'); return; }

  if (currentAction === 'minus') {
    var rEl = document.getElementById('subtractReason');
    if (rEl && !rEl.value.trim()) { showFieldError('subtractReason', '⚠️ Ilagay ang dahilan ng pagbabawas!'); return; }
    if (subtractPinSet && !pinVerified) { openPinModal(); return; }
  }

  document.getElementById('submitBtn').disabled = true;
  document.getElementById('submitText').textContent = 'Saving...';
  document.getElementById('mainForm').submit();
}

function showFieldError(fieldId, msg) {
  // Remove any existing error bubbles
  document.querySelectorAll('.inline-err').forEach(function(e){ e.remove(); });
  document.querySelectorAll('.field-input.err-border').forEach(function(e){ e.classList.remove('err-border'); });

  var el = fieldId ? document.getElementById(fieldId) : null;
  var err = document.createElement('div');
  err.className = 'inline-err';
  err.textContent = msg;

  if (el) {
    el.classList.add('err-border');
    el.parentNode.insertBefore(err, el.nextSibling);
    el.focus();
    // Auto-remove on change
    el.addEventListener('change', function() {
      el.classList.remove('err-border');
      if (err.parentNode) err.remove();
    }, { once: true });
    el.addEventListener('input', function() {
      el.classList.remove('err-border');
      if (err.parentNode) err.remove();
    }, { once: true });
  } else {
    // No field — show below the action row
    var actionRow = document.querySelector('.action-row');
    if (actionRow) actionRow.parentNode.insertBefore(err, actionRow.nextSibling);
    else alert(msg);
  }
  // Auto-dismiss after 3s
  setTimeout(function(){ if(err.parentNode) err.remove(); }, 3000);
}

/* ── PIN ── */
function openPinModal() {
  document.getElementById('pinInput').value = '';
  document.getElementById('pinErr').textContent = '';
  document.getElementById('pinModal').classList.add('open');
  setTimeout(function(){ document.getElementById('pinInput').focus(); }, 150);
}
function closePinModal() {
  document.getElementById('pinModal').classList.remove('open');
}
function verifyPin() {
  var pin = document.getElementById('pinInput').value.trim();
  if (!pin) { document.getElementById('pinErr').textContent = 'Ilagay ang PIN.'; return; }
  fetch('verify_pin.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'pin='+encodeURIComponent(pin)
  })
  .then(function(r){ return r.json(); })
  .then(function(d) {
    if (d.ok) { pinVerified = true; closePinModal(); document.getElementById('mainForm').submit(); }
    else { document.getElementById('pinErr').textContent = '❌ Mali ang PIN. Subukan ulit.'; document.getElementById('pinInput').value=''; document.getElementById('pinInput').focus(); }
  })
  .catch(function(){ pinVerified=true; closePinModal(); document.getElementById('mainForm').submit(); });
}
document.getElementById('pinInput') && document.getElementById('pinInput').addEventListener('keydown',function(e){ if(e.key==='Enter') verifyPin(); });

/* ── AUTO-DISMISS MSG ── */
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
  // Auto-dismiss after 2.8s
  setTimeout(dismissToast, 2800);
  // Click overlay to dismiss early
  if (toastOverlay) toastOverlay.addEventListener('click', dismissToast);
  toastBox.addEventListener('click', dismissToast);
}
</script>
</body>
</html>