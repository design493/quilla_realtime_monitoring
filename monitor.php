<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/ph_working_days.php';
requireLogin();

$pdo  = getPDO();
$user = currentUser();

// Active order
$order   = $pdo->query("SELECT * FROM production_orders WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
$orderId = $order['id'] ?? 0;

// Sizes
$sizes = $pdo->prepare("SELECT * FROM order_sizes WHERE order_id=? ORDER BY sort_order");
$sizes->execute([$orderId]);
$sizes = $sizes->fetchAll();

// Stages (active only)
$stages = $pdo->query("SELECT * FROM stages WHERE COALESCE(is_active,1)=1 ORDER BY sort_order")->fetchAll();

// Last active stage (used as completion metric)
$finStage = $pdo->query("SELECT id, name FROM stages WHERE COALESCE(is_active,1)=1 ORDER BY sort_order DESC LIMIT 1")->fetch();
$finId    = $finStage['id'] ?? 5;

// Cumulative outputs (all time)
$outRows = $pdo->prepare("
    SELECT do.order_size_id, do.stage_id, SUM(do.qty_produced) as qty_produced
    FROM daily_outputs do
    WHERE do.order_size_id IN (SELECT id FROM order_sizes WHERE order_id = ?)
    GROUP BY do.order_size_id, do.stage_id
");
$outRows->execute([$orderId]);
$outputs = [];
foreach ($outRows->fetchAll() as $r) {
    $outputs[$r['order_size_id']][$r['stage_id']] = (int)$r['qty_produced'];
}

// Per-stage totals
$stageTotals = [];
foreach ($stages as $st) {
    $stq = $pdo->prepare("
        SELECT COALESCE(SUM(do.qty_produced),0)
        FROM daily_outputs do JOIN order_sizes os ON os.id=do.order_size_id
        WHERE os.order_id=? AND do.stage_id=?
    ");
    $stq->execute([$orderId, $st['id']]);
    $stageTotals[$st['id']] = (int)$stq->fetchColumn();
}

// Total completed (Finishing)
$totalCompleted = $stageTotals[$finId] ?? 0;
// Prefer the order-level target_pairs set by admin; fall back to sum of sizes
$totalTarget    = (!empty($order['target_pairs']) && (int)$order['target_pairs'] > 0)
    ? (int)$order['target_pairs']
    : array_sum(array_column($sizes, 'target_qty'));
$progressPct    = $totalTarget > 0 ? min(100, round($totalCompleted / $totalTarget * 100)) : 0;

// Days remaining (working days only: Mon–Fri, excluding PH holidays)
$daysLeft = 0;
if ($order && !empty($order['deadline'])) {
    $daysLeft = countWorkingDaysUntilDeadline($order['deadline']);
}

// Running model
$modelSettings = [];
$mf = __DIR__ . '/config/running_model.json';
if (file_exists($mf)) $modelSettings = json_decode(file_get_contents($mf), true) ?? [];
$modelName     = $modelSettings['name']  ?? '';
$modelImageFile = $modelSettings['image'] ?? '';
if ($modelImageFile && str_contains($modelImageFile, '/')) $modelImageFile = basename($modelImageFile);
$modelImageUrl  = $modelImageFile ? 'uploads/model/' . $modelImageFile : '';

// Announcement — only show if created TODAY
$announce = $pdo->query("SELECT * FROM announcements WHERE DATE(created_at) = CURDATE() ORDER BY created_at DESC LIMIT 1")->fetch();

// Today's date display
$today = date('l, F j, Y');

// Weekly output consistency (Mon-Fri of current week, last active stage)
$weekDays = [];
$weekStart = strtotime('monday this week');
for ($i = 0; $i < 5; $i++) {
    $dayTs    = $weekStart + ($i * 86400);
    $dayDate  = date('Y-m-d', $dayTs);
    $dayLabel = date('D', $dayTs);
    $dayShort = date('d/m', $dayTs);
    $dayQty = 0;
    try {
        $dq = $pdo->prepare("
            SELECT COALESCE(SUM(do.qty_produced),0)
            FROM daily_outputs do
            JOIN order_sizes os ON os.id = do.order_size_id
            WHERE os.order_id = ? AND do.stage_id = ? AND do.log_date = ?
        ");
        $dq->execute([$orderId, $finId, $dayDate]);
        $dayQty = (int)$dq->fetchColumn();
    } catch(Exception $e) {}
    $weekDays[] = ['label'=>$dayLabel,'short'=>$dayShort,'qty'=>$dayQty,'date'=>$dayDate,'ts'=>$dayTs];
}
$weekMax = max(array_column($weekDays,'qty') ?: [1]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Production Monitor — <?= htmlspecialchars($modelName ?: ($order['order_code'] ?? 'Live')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Barlow+Condensed:wght@600;700;800;900&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:        #eef2fb;
  --panel:     #ffffff;
  --panel2:    #f5f8ff;
  --border:    #c8d5f0;
  --blue:      #1a3a8f;
  --blue-dark: #122a6b;
  --blue-mid:  #1e47b0;
  --blue-lite: #2557d6;
  --gold:      #f5c518;
  --gold2:     #e8b500;
  --green:     #16a34a;
  --green-bg:  #dcfce7;
  --red:       #dc2626;
  --red-bg:    #fee2e2;
  --text:      #0d1e52;
  --text2:     #4a5b8a;
  --accent:    #1e47b0;
  --shadow:    0 2px 12px rgba(26,58,143,.08);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
  height: 100%;
  background: var(--bg);
  color: var(--text);
  font-family: 'Barlow', sans-serif;
  overflow: hidden;
}
body.windowed { overflow: auto; }

/* ── TOP BAR ── */
.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 24px;
  height: 60px;
  background: linear-gradient(90deg, var(--blue-dark) 0%, var(--blue) 60%, var(--blue-dark) 100%);
  border-bottom: 3px solid var(--gold);
  position: relative;
  z-index: 10;
  flex-shrink: 0;
  box-shadow: 0 3px 16px rgba(26,58,143,.18);
}
.topbar-brand {
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 22px;
  font-weight: 900;
  letter-spacing: 3px;
  color: #fff;
  text-transform: uppercase;
  display: flex;
  align-items: center;
  gap: 10px;
}
.logo{text-align:center;margin-bottom:32px}
.nav-logo {
  display: flex;
  align-items: center;
  height: 60px;
  overflow: hidden;
}
.nav-logo img {
  height: 42px;
  width: auto;
  max-width: 180px;
  object-fit: contain;
  display: block;
}
.topbar-center {
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 3px;
  color: rgba(255,255,255,.55);
  text-transform: uppercase;
}
.topbar-right {
  display: flex;
  align-items: center;
  gap: 10px;
}
.clock {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 26px;
  color: var(--gold);
  letter-spacing: 2px;
  min-width: 90px;
  text-align: right;
}
.date-lbl {
  font-size: 10px;
  color: rgba(255,255,255,.5);
  font-weight: 600;
  text-align: right;
  line-height: 1.3;
  letter-spacing: .4px;
}

.btn-fs {
  background: var(--gold);
  border: none;
  color: #1a1200;
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 12px;
  font-weight: 900;
  letter-spacing: 1.5px;
  padding: 7px 16px;
  border-radius: 7px;
  cursor: pointer;
  transition: background .2s, transform .1s;
  text-transform: uppercase;
}
.btn-fs:hover { background: var(--gold2); transform: translateY(-1px); }

.btn-back {
  background: rgba(255,255,255,.12);
  border: 1px solid rgba(255,255,255,.2);
  color: rgba(255,255,255,.8);
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 1px;
  padding: 7px 14px;
  border-radius: 7px;
  cursor: pointer;
  text-decoration: none;
  transition: background .2s;
  text-transform: uppercase;
}
.btn-back:hover { background: rgba(255,255,255,.2); color: #fff; }

/* ── LAYOUT ── */
.monitor-wrap {
  display: flex;
  flex-direction: column;
  height: calc(100vh - 60px);
  padding: 14px 18px 10px;
  gap: 12px;
  overflow: hidden;
}
body.windowed .monitor-wrap { height: auto; overflow: visible; }

/* ── STATS ROW ── */
.stats-row {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr 1fr;
  gap: 12px;
  flex-shrink: 0;
}
.stat-card {
  background: var(--panel);
  border: 1.5px solid var(--border);
  border-radius: 14px;
  padding: 14px 18px 12px;
  position: relative;
  overflow: hidden;
  box-shadow: var(--shadow);
}
.stat-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 4px;
  border-radius: 14px 14px 0 0;
}
.stat-card.gold::before  { background: linear-gradient(90deg, var(--gold), var(--gold2)); }
.stat-card.blue::before  { background: linear-gradient(90deg, var(--blue), var(--blue-lite)); }
.stat-card.green::before { background: linear-gradient(90deg, #15803d, var(--green)); }
.stat-card.red::before   { background: linear-gradient(90deg, #b91c1c, var(--red)); }

.stat-label {
  font-size: 9px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: var(--text2);
  margin-bottom: 5px;
}
.stat-val {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 44px;
  line-height: 1;
  letter-spacing: 1px;
}
.stat-card.gold  .stat-val { color: #b8860b; }
.stat-card.blue  .stat-val { color: var(--blue); }
.stat-card.green .stat-val { color: var(--green); }
.stat-card.red   .stat-val { color: var(--red); }

.stat-sub {
  font-size: 11px;
  color: var(--text2);
  margin-top: 3px;
  font-weight: 600;
}

/* Progress bar */
.prog-bar-wrap {
  margin-top: 9px;
  background: #e8edf8;
  border-radius: 999px;
  height: 8px;
  overflow: hidden;
}
.prog-bar-fill {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, var(--green), #4ade80);
  transition: width 1s ease;
}

/* Model card */
.model-card { display: flex; align-items: center; gap: 12px; }
.model-img {
  width: 50px; height: 50px;
  border-radius: 10px;
  object-fit: cover;
  border: 2px solid var(--border);
  box-shadow: 0 2px 8px rgba(26,58,143,.12);
  flex-shrink: 0;
}
.model-no-img {
  width: 50px; height: 50px;
  border-radius: 10px;
  background: var(--panel2);
  border: 2px dashed var(--border);
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; flex-shrink: 0;
}
.model-name {
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 20px; font-weight: 900; letter-spacing: .5px;
  color: var(--blue); text-transform: uppercase;
}
.model-code {
  font-size: 11px; color: var(--text2); font-weight: 600; letter-spacing: .5px;
}

/* ── MAIN ROW ── */
.main-row {
  display: grid;
  grid-template-columns: 1fr 290px 230px;
  gap: 12px;
  flex: 1;
  min-height: 0;
}

/* ── CONSISTENCY CHART PANEL ── */
.chart-panel {
  background: var(--panel);
  border: 1.5px solid var(--border);
  border-radius: 14px;
  display: flex; flex-direction: column;
  overflow: hidden;
  box-shadow: var(--shadow);
}
.chart-body {
  flex: 1;
  padding: 14px 16px 10px;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
}
.chart-bars {
  display: flex;
  align-items: flex-end;
  gap: 10px;
  height: 140px;
  padding-bottom: 0;
}
.chart-col {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  gap: 4px;
}
.chart-bar-wrap {
  width: 100%;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  height: 120px;
}
.chart-bar {
  width: 80%;
  border-radius: 6px 6px 0 0;
  min-height: 4px;
  transition: height .6s cubic-bezier(.23,1,.32,1);
  position: relative;
}
.chart-bar.today {
  background: linear-gradient(180deg, #f5c518 0%, #e8a500 100%);
  box-shadow: 0 4px 12px rgba(245,197,24,.4);
}
.chart-bar.past {
  background: linear-gradient(180deg, #1e47b0 0%, #122a6b 100%);
  opacity: .65;
}
.chart-bar.future {
  background: #e8edf8;
}
.chart-qty {
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 13px; font-weight: 900;
  color: var(--text);
  min-height: 16px;
  text-align: center;
}
.chart-qty.today-qty { color: #b8860b; }
.chart-lbl {
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 12px; font-weight: 800;
  color: var(--text2);
  letter-spacing: .5px;
}
.chart-lbl.today-lbl { color: var(--blue); }
.chart-date {
  font-size: 9px; font-weight: 600;
  color: #aab4cc;
  letter-spacing: .3px;
}
.chart-divider {
  height: 2px;
  background: linear-gradient(90deg, transparent, var(--border), transparent);
  margin: 8px 0 0;
}

/* ── TABLE PANEL ── */
.table-panel {
  background: var(--panel);
  border: 1.5px solid var(--border);
  border-radius: 14px;
  display: flex; flex-direction: column;
  overflow: hidden;
  box-shadow: var(--shadow);
}
.panel-header {
  padding: 11px 18px 9px;
  border-bottom: 1.5px solid var(--border);
  display: flex; align-items: center; gap: 10px;
  flex-shrink: 0;
  background: linear-gradient(90deg, var(--blue-dark), var(--blue));
}
.panel-title {
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 12px; font-weight: 900;
  letter-spacing: 2.5px; text-transform: uppercase;
  color: rgba(255,255,255,.75);
}

.live-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: #4ade80;
  box-shadow: 0 0 6px #4ade80;
  animation: pulse-dot 1.5s ease-in-out infinite;
  flex-shrink: 0;
}
@keyframes pulse-dot {
  0%,100% { opacity: 1; transform: scale(1); }
  50%      { opacity: .4; transform: scale(.7); }
}

.table-scroll { overflow-y: auto; flex: 1; }
.table-scroll::-webkit-scrollbar { width: 4px; }
.table-scroll::-webkit-scrollbar-track { background: #f0f4ff; }
.table-scroll::-webkit-scrollbar-thumb { background: #c8d5f0; border-radius: 4px; }

table.out-table { width: 100%; border-collapse: collapse; }
table.out-table thead th {
  position: sticky; top: 0; z-index: 3;
  background: #f0f4ff;
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 11px; font-weight: 800;
  letter-spacing: 1.5px; text-transform: uppercase;
  color: var(--text2);
  padding: 10px 14px; text-align: center;
  border-bottom: 1.5px solid var(--border);
  white-space: nowrap;
}
table.out-table thead th:first-child { text-align: left; }
table.out-table tbody tr {
  border-bottom: 1px solid #eef2fb;
  transition: background .15s;
}
table.out-table tbody tr:hover { background: #f5f8ff; }
table.out-table tbody td {
  padding: 10px 14px;
  font-size: 14px; font-weight: 600;
  text-align: center; color: var(--text);
}
table.out-table tbody td:first-child {
  text-align: left;
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 16px; font-weight: 900; letter-spacing: .5px;
  color: var(--blue);
  background: linear-gradient(90deg, #eef3ff 80%, #e4ebff);
  border-left: 4px solid var(--blue-lite);
  position: sticky; left: 0; z-index: 1;
}

/* Highlight TARGET column (2nd td) */
table.out-table tbody td:nth-child(2) {
  background: #fffbea;
  border-left: 3px solid #f5c518;
  color: #92400e !important;
}

/* Sticky header matching for SIZE and TARGET */
table.out-table thead th:first-child {
  background: #dce6ff;
  border-left: 4px solid var(--blue-lite);
  position: sticky; left: 0; z-index: 4;
}
table.out-table thead th:nth-child(2) {
  background: #fef9e0;
  border-left: 3px solid #f5c518;
}

/* ── DONE ROW — size completed in last stage ── */
tr.row-done {
  background: linear-gradient(90deg, #f0fdf4, #dcfce7) !important;
  border-bottom: 1px solid #86efac !important;
}
tr.row-done:hover { background: linear-gradient(90deg, #dcfce7, #bbf7d0) !important; }
tr.row-done td:first-child {
  background: linear-gradient(90deg, #dcfce7 80%, #bbf7d0) !important;
  border-left: 4px solid #16a34a !important;
  color: #15803d !important;
}
tr.row-done td:nth-child(2) {
  background: #d1fae5 !important;
  border-left: 3px solid #16a34a !important;
  color: #15803d !important;
}
.row-done-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  background: linear-gradient(135deg, #16a34a, #15803d);
  color: #fff;
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 9px; font-weight: 900;
  letter-spacing: 1.5px; text-transform: uppercase;
  padding: 2px 7px;
  border-radius: 20px;
  margin-left: 6px;
  vertical-align: middle;
  box-shadow: 0 2px 6px rgba(22,163,74,.35);
  animation: pop-in .4s cubic-bezier(.175,.885,.32,1.275) both;
}


.qty-cell.zero { color: #c8d5f0 !important; }
.qty-cell.high { color: var(--green); }
.qty-cell.mid  { color: var(--blue-mid); }

/* ── STAGE-COMPLETE cell (target met for this size & stage, but row not fully done) ── */
.qty-cell.stage-hit {
  position: relative;
  color: var(--green) !important;
  font-weight: 700;
}
.stage-check {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px; height: 16px;
  background: linear-gradient(135deg, #16a34a, #15803d);
  color: #fff;
  border-radius: 50%;
  font-size: 9px;
  font-weight: 900;
  margin-left: 5px;
  vertical-align: middle;
  box-shadow: 0 1px 4px rgba(22,163,74,.45);
  animation: pop-in .35s cubic-bezier(.175,.885,.32,1.275) both;
  flex-shrink: 0;
}
/* When entire row is done, hide the per-stage check bubbles */
tr.row-done .stage-check { display: none; }
/* When entire row is done, keep cells green but no extra marker */
tr.row-done .qty-cell.stage-hit { color: #15803d !important; }

.stage-th { color: var(--blue) !important; }

tr.totals-row td {
  background: #fffbea;
  border-top: 2px solid #fde68a;
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 18px; font-weight: 900;
  color: #92400e !important;
  text-align: center;
}
.stage-done-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: linear-gradient(135deg, #16a34a, #15803d);
  color: #fff;
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 10px;
  font-weight: 900;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  padding: 3px 8px;
  border-radius: 20px;
  margin-top: 5px;
  box-shadow: 0 2px 8px rgba(22,163,74,.4);
  animation: pop-in .4s cubic-bezier(.175,.885,.32,1.275) both;
}
@keyframes pop-in {
  from { opacity:0; transform: scale(.6); }
  to   { opacity:1; transform: scale(1); }
}
tr.totals-row td.stage-done-cell {
  vertical-align: top;
  padding-top: 8px;
}
tr.totals-row td:first-child {
  color: var(--text2) !important;
  font-size: 10px; font-weight: 800;
  letter-spacing: 1px; text-transform: uppercase;
  background: linear-gradient(90deg, #dce6ff 80%, #cfdaff) !important;
  border-left: 4px solid var(--blue-lite);
  position: sticky; left: 0; z-index: 1;
}
tr.totals-row td:nth-child(2) {
  background: #fef3c7 !important;
  border-left: 3px solid #f5c518;
}

/* ── SIDE PANEL ── */
.side-panel {
  background: var(--panel);
  border: 1.5px solid var(--border);
  border-radius: 14px;
  display: flex; flex-direction: column;
  overflow: hidden;
  box-shadow: var(--shadow);
}
.recent-list { overflow-y: auto; flex: 1; padding: 4px 0; }
.recent-list::-webkit-scrollbar { width: 3px; }
.recent-list::-webkit-scrollbar-thumb { background: #c8d5f0; border-radius: 3px; }

.recent-item {
  padding: 9px 14px;
  border-bottom: 1px solid #eef2fb;
  animation: slideIn .25s ease;
}
@keyframes slideIn {
  from { opacity:0; transform: translateX(8px); }
  to   { opacity:1; transform: translateX(0); }
}
.ri-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 3px; }
.ri-size {
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 14px; font-weight: 900;
  color: var(--blue); letter-spacing: .4px;
}
.ri-qty {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 17px; letter-spacing: 1px;
  padding: 1px 8px; border-radius: 6px;
  font-weight: 900;
}
.ri-qty.add   { background: var(--green-bg); color: var(--green); }
.ri-qty.minus { background: var(--red-bg); color: var(--red); }
.ri-stage { font-size: 10px; color: var(--blue-mid); font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
.ri-by    { font-size: 10px; color: var(--text2); font-weight: 500; margin-top: 1px; }
.ri-time  { font-size: 10px; color: var(--text2); text-align: right; }

/* ── TICKER ── */
.ticker-wrap {
  background: linear-gradient(90deg, var(--blue-dark), var(--blue), var(--blue-dark));
  height: 52px; display: flex; align-items: center;
  overflow: hidden; border-radius: 8px;
  flex-shrink: 0; border: 2px solid var(--blue-mid);
}
.ticker-label {
  background: var(--gold);
  color: #1a1200;
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 16px; font-weight: 900;
  letter-spacing: 2.5px; padding: 0 20px;
  height: 100%; display: flex; align-items: center;
  white-space: nowrap; flex-shrink: 0;
  text-transform: uppercase;
  gap: 8px;
}
.ticker-track { overflow: hidden; flex: 1; height: 100%; display: flex; align-items: center; }
.ticker-text {
  font-family: 'Barlow Condensed', sans-serif;
  font-size: 22px; font-weight: 800;
  color: #ffffff;
  white-space: nowrap; letter-spacing: 1px;
  animation: ticker-scroll 55s linear infinite;
  padding-left: 100%;
  text-shadow: 0 1px 4px rgba(0,0,0,.3);
}
@keyframes ticker-scroll {
  0%   { transform: translateX(0); }
  100% { transform: translateX(-100%); }
}

/* ── REFRESH BADGE ── */
.refresh-badge {
  font-size: 10px; color: rgba(255,255,255,.5);
  font-weight: 600; letter-spacing: .4px;
  display: flex; align-items: center; gap: 5px;
  margin-left: auto;
}
.refresh-spinner {
  width: 10px; height: 10px;
  border: 1.5px solid rgba(255,255,255,.3);
  border-top-color: var(--gold);
  border-radius: 50%; display: inline-block;
}
.refresh-spinner.spinning { animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
  <div class="topbar-brand">
    <div class="nav-logo"><img src="assets/quilla_logo.jpg" alt="quilla"></div>
  </div>
  <div class="topbar-center">
    ⚡ Real-time Production Output
  </div>
  <div class="topbar-right">
    <div>
      <div class="date-lbl"><?= date('l') ?></div>
      <div class="date-lbl"><?= date('M j, Y') ?></div>
    </div>
    <div class="clock" id="clock">--:--</div>
    <a href="index.php" class="btn-back">← Edit</a>
    <button class="btn-fs" id="btn-fullscreen" onclick="toggleFullscreen()">⛶ Fullscreen</button>
  </div>
</div>

<!-- MAIN MONITOR WRAP -->
<div class="monitor-wrap" id="monitorWrap">

  <!-- STATS ROW -->
  <div class="stats-row">

    <!-- Completed -->
    <div class="stat-card green">
      <div class="stat-label">✅ Total Completed (<?= htmlspecialchars($finStage['name'] ?? 'Last Stage') ?>)</div>
      <div class="stat-val" id="s-completed"><?= number_format($totalCompleted) ?></div>
      <div class="stat-sub">out of <?= number_format($totalTarget) ?> target pairs</div>
      <div class="prog-bar-wrap" style="margin-top:10px">
        <div class="prog-bar-fill" id="prog-fill" style="width:<?= $progressPct ?>%"></div>
      </div>
    </div>

    <!-- Progress % -->
    <div class="stat-card gold">
      <div class="stat-label">📊 Progress</div>
      <div class="stat-val" id="s-pct"><?= $progressPct ?>%</div>
      <div class="stat-sub"><?= number_format($totalTarget - $totalCompleted) ?> pairs remaining</div>
    </div>

    <!-- Days left -->
    <div class="stat-card <?= $daysLeft <= 3 ? 'red' : 'blue' ?>">
      <div class="stat-label">⏳ Deadline</div>
      <div class="stat-val"><?= $daysLeft ?> <span style="font-size:18px;letter-spacing:0">days</span></div>
      <div class="stat-sub"><?= $order ? date('M j, Y', strtotime($order['deadline'])) : '—' ?></div>
    </div>

    <!-- Running model -->
    <div class="stat-card blue" style="padding:12px 16px">
      <div class="stat-label">👟 Running Model</div>
      <div class="model-card" style="margin-top:6px">
        <?php if($modelImageUrl): ?>
          <img src="<?= htmlspecialchars($modelImageUrl) ?>" class="model-img" alt="model">
        <?php else: ?>
          <div class="model-no-img">👟</div>
        <?php endif; ?>
        <div>
          <div class="model-name"><?= htmlspecialchars($modelName ?: 'No Model') ?></div>
          <div class="model-code"><?= htmlspecialchars($order['order_code'] ?? '—') ?></div>
        </div>
      </div>
    </div>

  </div><!-- /stats-row -->

  <!-- MAIN ROW -->
  <div class="main-row">

    <!-- OUTPUT TABLE -->
    <div class="table-panel">
      <div class="panel-header">
        <div class="live-dot"></div>
        <div class="panel-title">Production Output by Size &amp; Stage</div>
        <div class="refresh-badge" style="margin-left:auto">
          <span class="refresh-spinner" id="spinner"></span>
          <span id="refresh-countdown">Auto-refresh in 30s</span>
        </div>
      </div>
      <div class="table-scroll">
        <table class="out-table" id="out-table">
          <thead>
            <tr>
              <th>Size</th>
              <th>Target Prs</th>
              <?php foreach($stages as $st): ?>
              <th class="stage-th"><?= htmlspecialchars($st['name']) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody id="out-tbody">
            <?php foreach($sizes as $sz):
              $rowTotal   = 0;
              $lastStageQty = $outputs[$sz['id']][$finId] ?? 0;
              $rowDone    = $lastStageQty >= $sz['target_qty'] && $sz['target_qty'] > 0;
            ?>
            <tr class="<?= $rowDone ? 'row-done' : '' ?>">
              <td>
                <?= htmlspecialchars($sz['size_label']) ?>
                <?php if($rowDone): ?>
                  <span class="row-done-badge">✔ DONE</span>
                <?php endif; ?>
              </td>
              <td class="qty-cell mid"><?= number_format($sz['target_qty']) ?></td>
              <?php foreach($stages as $st):
                $qty    = $outputs[$sz['id']][$st['id']] ?? 0;
                $rowTotal += $qty;
                $stageHit = !$rowDone && $sz['target_qty'] > 0 && $qty >= $sz['target_qty'];
                $cls = $qty === 0 ? 'zero' : ($qty >= $sz['target_qty'] ? ($rowDone ? 'high' : 'stage-hit') : 'mid');
              ?>
              <td class="qty-cell <?= $cls ?>">
                <?= $qty > 0 ? number_format($qty) : '—' ?>
                <?php if($stageHit): ?>
                  <span class="stage-check">✔</span>
                <?php endif; ?>
              </td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="totals-row">
              <td>TOTAL</td>
              <td class="qty-cell"><?= number_format($totalTarget) ?></td>
              <?php foreach($stages as $st):
                $stTotal = $stageTotals[$st['id']] ?? 0;
                $isDone  = $totalTarget > 0 && $stTotal >= $totalTarget;
              ?>
              <td class="qty-cell<?= $isDone ? ' stage-done-cell' : '' ?>">
                <?= number_format($stTotal) ?>
                <?php if($isDone): ?>
                <br><span class="stage-done-badge">✔ DONE</span>
                <?php endif; ?>
              </td>
              <?php endforeach; ?>
            </tr>
          </tfoot>
        </table>
      </div>
    </div><!-- /table-panel -->

    <!-- SIDE PANEL: Recent Entries -->
    <div class="side-panel">
      <div class="panel-header">
        <div class="live-dot"></div>
        <div class="panel-title">Recent Entries</div>
      </div>
      <div class="recent-list" id="recent-list">
        <?php
        $recentEntries = $pdo->prepare("
            SELECT al.log_date, os.size_label, s.name as stage,
                   al.action, al.qty_change as qty,
                   u.full_name as entered_by_name, al.confirmed_by,
                   al.entered_at
            FROM output_activity_log al
            JOIN order_sizes os ON os.id = al.order_size_id
            JOIN stages s ON s.id = al.stage_id
            JOIN users u ON u.id = al.entered_by
            WHERE os.order_id = ?
            ORDER BY al.entered_at DESC LIMIT 20
        ");
        try {
            $recentEntries->execute([$orderId]);
            $entries = $recentEntries->fetchAll();
        } catch(Exception $e) { $entries = []; }
        foreach ($entries as $re):
            $isMinus = ($re['action'] === 'minus');
            $timeAgo = date('h:i A', strtotime($re['entered_at']));
        ?>
        <div class="recent-item">
          <div class="ri-top">
            <span class="ri-size"><?= htmlspecialchars($re['size_label']) ?></span>
            <span class="ri-qty <?= $isMinus ? 'minus' : 'add' ?>">
              <?= $isMinus ? '−' : '+' ?><?= number_format($re['qty']) ?>
            </span>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:flex-end">
            <div>
              <div class="ri-stage"><?= htmlspecialchars($re['stage']) ?></div>
              <div class="ri-by">by <?= htmlspecialchars($re['entered_by_name']) ?><?= $re['confirmed_by'] ? ' · ' . htmlspecialchars($re['confirmed_by']) : '' ?></div>
            </div>
            <div class="ri-time"><?= $timeAgo ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($entries)): ?>
        <div style="padding:30px;text-align:center;color:var(--text2);font-size:13px">No entries yet today</div>
        <?php endif; ?>
      </div>
    </div><!-- /side-panel -->

    <!-- CHART PANEL: Output Consistency -->
    <div class="chart-panel">
      <div class="panel-header">
        <div class="live-dot"></div>
        <div class="panel-title">📊 Output Consistency <?= date('M Y') ?></div>
      </div>
      <div class="chart-body">
        <div class="chart-bars">
          <?php
          $todayDate = date('Y-m-d');
          foreach ($weekDays as $wd):
            $isToday  = ($wd['date'] === $todayDate);
            $isFuture = ($wd['ts'] > strtotime($todayDate));
            $barClass = $isToday ? 'today' : ($isFuture ? 'future' : 'past');
            $heightPct = $weekMax > 0 ? max(3, round($wd['qty'] / $weekMax * 100)) : 3;
          ?>
          <div class="chart-col">
            <div class="chart-qty <?= $isToday ? 'today-qty' : '' ?>">
              <?= $wd['qty'] > 0 ? $wd['qty'] : '' ?>
            </div>
            <div class="chart-bar-wrap">
              <div class="chart-bar <?= $barClass ?>" style="height:<?= $heightPct ?>%"></div>
            </div>
            <div class="chart-lbl <?= $isToday ? 'today-lbl' : '' ?>"><?= $wd['label'] ?></div>
            <div class="chart-date"><?= $wd['short'] ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="chart-divider"></div>
      </div>
    </div><!-- /chart-panel -->

  </div><!-- /main-row -->

  <!-- ANNOUNCEMENT TICKER -->
  <?php if($announce): ?>
  <div class="ticker-wrap">
    <div class="ticker-label">📢 Announcement</div>
    <div class="ticker-track">
      <div class="ticker-text">
        <?= htmlspecialchars($announce['title']) ?>
        <?= $announce['body'] ? ' — ' . htmlspecialchars($announce['body']) : '' ?>
        &nbsp;&nbsp;&nbsp;&nbsp;★&nbsp;&nbsp;&nbsp;&nbsp;
        <?= htmlspecialchars($announce['title']) ?>
        <?= $announce['body'] ? ' — ' . htmlspecialchars($announce['body']) : '' ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /monitor-wrap -->

<script>
// ── CLOCK ──
function updateClock() {
  var now = new Date();
  var h = now.getHours();
  var ampm = h >= 12 ? 'PM' : 'AM';
  h = h % 12 || 12;
  var m = now.getMinutes().toString().padStart(2,'0');
  var s = now.getSeconds().toString().padStart(2,'0');
  document.getElementById('clock').textContent = h + ':' + m + ':' + s + ' ' + ampm;
}
updateClock();
setInterval(updateClock, 1000);

// ── FULLSCREEN ──
function toggleFullscreen() {
  var btn = document.getElementById('btn-fullscreen');
  if (!document.fullscreenElement) {
    document.documentElement.requestFullscreen().then(function() {
      btn.textContent = '✕ Exit Fullscreen';
      document.body.classList.remove('windowed');
    }).catch(function() {
      // Fallback: just make it look big
      btn.textContent = '✕ Exit Fullscreen';
    });
  } else {
    document.exitFullscreen().then(function() {
      btn.textContent = '⛶ Fullscreen';
    });
  }
}
document.addEventListener('fullscreenchange', function() {
  if (!document.fullscreenElement) {
    document.getElementById('btn-fullscreen').textContent = '⛶ Fullscreen';
  }
});

// ── AUTO-REFRESH with countdown ──
var refreshInterval = 30;
var countdown = refreshInterval;
var spinnerEl   = document.getElementById('spinner');
var countdownEl = document.getElementById('refresh-countdown');

var timer = setInterval(function() {
  countdown--;
  if (countdown <= 0) {
    spinnerEl.classList.add('spinning');
    countdownEl.textContent = 'Refreshing…';
    setTimeout(function() { location.reload(); }, 400);
  } else {
    countdownEl.textContent = 'Auto-refresh in ' + countdown + 's';
  }
}, 1000);

// Allow manual refresh by clicking countdown
countdownEl.style.cursor = 'pointer';
countdownEl.addEventListener('click', function() {
  spinnerEl.classList.add('spinning');
  countdownEl.textContent = 'Refreshing…';
  setTimeout(function() { location.reload(); }, 300);
});
</script>
</body>
</html>