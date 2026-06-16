<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
requireLogin();

header('Content-Type: application/json');

$pdo  = getPDO();
$date = $_GET['date'] ?? date('Y-m-d');

// Active order
$order   = $pdo->query("SELECT * FROM production_orders WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
$orderId = $order['id'] ?? 0;

// Timestamp — detect ANY change: activity log entries OR direct daily_outputs mutations
// Uses the MAX of:
//   1. output_activity_log.entered_at   (entries via the panel / index.php)
//   2. daily_outputs.updated_at         (direct upserts that skip the activity log)
//   3. A hash of current stage totals   (catches bulk / backdated edits with no new timestamp)
$tsQ = $pdo->prepare("
    SELECT COALESCE(MAX(UNIX_TIMESTAMP(al.entered_at)), 0)
    FROM output_activity_log al
    JOIN order_sizes os ON os.id = al.order_size_id
    WHERE os.order_id = ?
");
$tsQ->execute([$orderId]);
$tsFromLog = (int)$tsQ->fetchColumn();

// Also check daily_outputs updated_at if the column exists (graceful fallback)
$tsFromOutputs = 0;
try {
    $doQ = $pdo->prepare("
        SELECT COALESCE(MAX(UNIX_TIMESTAMP(do.updated_at)), 0)
        FROM daily_outputs do
        JOIN order_sizes os ON os.id = do.order_size_id
        WHERE os.order_id = ?
    ");
    $doQ->execute([$orderId]);
    $tsFromOutputs = (int)$doQ->fetchColumn();
} catch (Exception $e) { /* updated_at column may not exist — ignore */ }

// Also include a lightweight hash of current cumulative stage totals so that
// ANY qty change (even with same timestamp) triggers a dashboard refresh.
$hashQ = $pdo->prepare("
    SELECT COALESCE(SUM(do.qty_produced * do.stage_id), 0)
    FROM daily_outputs do
    JOIN order_sizes os ON os.id = do.order_size_id
    WHERE os.order_id = ? AND do.log_date <= ?
");
$hashQ->execute([$orderId, $date]);
$stageHash = (int)$hashQ->fetchColumn();

// Combine into a single change-detector value
$ts = max($tsFromLog, $tsFromOutputs) * 10000 + ($stageHash % 10000);

// Active stages
$stages = $pdo->query("SELECT * FROM stages WHERE COALESCE(is_active,1)=1 ORDER BY sort_order")->fetchAll();

// Finishing stage id
$finStage = $pdo->query("SELECT id FROM stages WHERE name='Finishing' LIMIT 1")->fetch();
$finId    = $finStage['id'] ?? 0;

// All sizes for this order
$sizesQ = $pdo->prepare("SELECT id FROM order_sizes WHERE order_id=?");
$sizesQ->execute([$orderId]);
$sizeIds = array_column($sizesQ->fetchAll(), 'id');

// Cumulative outputs up to selected date — progressive totals
$outputs = [];
if ($sizeIds) {
    $in  = implode(',', array_map('intval', $sizeIds));
    $rows = $pdo->prepare("
        SELECT order_size_id, stage_id, SUM(qty_produced) as qty_produced
        FROM daily_outputs
        WHERE order_size_id IN ($in) AND log_date <= ?
        GROUP BY order_size_id, stage_id
    ");
    $rows->execute([$date]);
    foreach ($rows->fetchAll() as $r) {
        $outputs[$r['order_size_id']][$r['stage_id']] = (int)$r['qty_produced'];
    }
}

// Cumulative stage totals up to selected date
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

// Total target
$totalTarget = 0;
if ($orderId) {
    $tq = $pdo->prepare("SELECT COALESCE(SUM(target_qty),0) FROM order_sizes WHERE order_id=?");
    $tq->execute([$orderId]);
    $totalTarget = (int)$tq->fetchColumn();
}

// Total completed (Finishing stage, cumulative up to selected date)
$totalCompleted = 0;
if ($orderId && $finId) {
    $cq = $pdo->prepare("
        SELECT COALESCE(SUM(do.qty_produced),0)
        FROM daily_outputs do
        JOIN order_sizes os ON os.id=do.order_size_id
        WHERE os.order_id=? AND do.stage_id=? AND do.log_date<=?
    ");
    $cq->execute([$orderId, $finId, $date]);
    $totalCompleted = (int)$cq->fetchColumn();
}

// Recent entries (activity log)
$recentEntries = [];
try {
    $rq = $pdo->prepare("
        SELECT al.log_date, os.size_label, s.name as stage,
               al.action, al.qty_change as qty_produced,
               u.full_name as entered_by_name,
               COALESCE(al.confirmed_by,'') as confirmed_by,
               COALESCE(al.subtract_reason,'') as subtract_reason,
               al.entered_at
        FROM output_activity_log al
        JOIN order_sizes os ON os.id = al.order_size_id
        JOIN stages s       ON s.id  = al.stage_id
        JOIN users u        ON u.id  = al.entered_by
        WHERE os.order_id = ?
        ORDER BY al.entered_at DESC
        LIMIT 50
    ");
    $rq->execute([$orderId]);
    $recentEntries = $rq->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recentEntries = [];
}

echo json_encode([
    'ts'             => $ts,
    'outputs'        => $outputs,
    'stageTotals'    => $stageTotals,
    'totalTarget'    => $totalTarget,
    'totalCompleted' => $totalCompleted,
    'recentEntries'  => $recentEntries,
    'todayStage'     => (function() use ($pdo, $orderId) {
        $rows = $pdo->prepare("
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
        $rows->execute([$orderId]);
        return $rows->fetchAll(PDO::FETCH_ASSOC);
    })(),
]);