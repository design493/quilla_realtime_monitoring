<?php
/**
 * inventory_api.php — AJAX endpoint for inventory actions
 * Returns JSON. No redirects, no full page reloads.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
requireLogin();

header('Content-Type: application/json');

$pdo  = getPDO();
$user = currentUser();

$isAdmin      = ($user['role'] === 'admin');
$isProduction = ($user['role'] === 'supervisor');
$canManage    = $isAdmin || $isProduction;

if (!$canManage) {
    echo json_encode(['ok' => false, 'msg' => 'Permission denied.']);
    exit;
}

$action = $_POST['action'] ?? '';

// ── Helper: fetch fresh stats ────────────────────────────────────────────────
function fetchStats($pdo) {
    $totalMats  = (int)$pdo->query("SELECT COUNT(*) FROM inventory_materials WHERE is_active=1")->fetchColumn();
    $lowStock   = (int)$pdo->query("SELECT COUNT(*) FROM inventory_materials WHERE is_active=1 AND quantity_in_stock <= minimum_stock AND minimum_stock > 0 AND quantity_in_stock > 0")->fetchColumn();
    $outOfStock = (int)$pdo->query("SELECT COUNT(*) FROM inventory_materials WHERE is_active=1 AND quantity_in_stock = 0")->fetchColumn();
    $totalValue = (float)$pdo->query("SELECT COALESCE(SUM(quantity_in_stock * unit_cost),0) FROM inventory_materials WHERE is_active=1")->fetchColumn();
    return [
        'total'     => $totalMats,
        'in_stock'  => $totalMats - $lowStock - $outOfStock,
        'low'       => $lowStock,
        'out'       => $outOfStock,
        'value'     => $totalValue,
    ];
}

// ── Helper: fetch all active materials ordered by category ───────────────────
function fetchAllMaterials($pdo) {
    $s = $pdo->query("SELECT * FROM inventory_materials WHERE is_active=1 ORDER BY category, material_name");
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
function fetchMaterial($pdo, $id) {
    $s = $pdo->prepare("SELECT * FROM inventory_materials WHERE id=?");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC);
}

// ── Helper: fetch recent transactions (last 20) ──────────────────────────────
function fetchRecentTx($pdo) {
    $s = $pdo->prepare("
        SELECT t.*, m.material_name, m.unit, u.full_name
        FROM inventory_transactions t
        JOIN inventory_materials m ON m.id = t.material_id
        JOIN users u ON u.id = t.entered_by
        ORDER BY t.entered_at DESC LIMIT 20
    ");
    $s->execute();
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

// ────────────────────────────────────────────────────────────────────────────
// ADD MATERIAL
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'add_material') {
    $name     = trim($_POST['material_name'] ?? '');
    $cat      = trim($_POST['category']      ?? 'General');
    $unit     = trim($_POST['unit']          ?? 'pcs');
    $qty      = (float)($_POST['quantity_in_stock'] ?? 0);
    $minStock = (float)($_POST['minimum_stock']     ?? 0);
    $cost     = (float)($_POST['unit_cost']         ?? 0);
    $supplier = trim($_POST['supplier'] ?? '');
    $notes    = trim($_POST['notes']    ?? '');

    if (!$name) {
        echo json_encode(['ok' => false, 'msg' => 'Material name is required.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO inventory_materials
        (material_name, category, unit, quantity_in_stock, minimum_stock, unit_cost, supplier, notes, created_by)
        VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$name, $cat, $unit, $qty, $minStock, $cost, $supplier ?: null, $notes ?: null, $user['id']]);
    $newId = $pdo->lastInsertId();

    if ($qty > 0) {
        $pdo->prepare("INSERT INTO inventory_transactions (material_id, action, quantity, qty_before, qty_after, reason, entered_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$newId, 'add', $qty, 0, $qty, 'Initial stock entry', $user['id']]);
    }

    echo json_encode([
        'ok'           => true,
        'msg'          => "✔ Material '{$name}' added successfully.",
        'stats'        => fetchStats($pdo),
        'material'     => fetchMaterial($pdo, $newId),
        'all_materials'=> fetchAllMaterials($pdo),
        'recent_tx'    => fetchRecentTx($pdo),
    ]);
    exit;
}

// ────────────────────────────────────────────────────────────────────────────
// EDIT MATERIAL
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'edit_material') {
    $matId    = (int)($_POST['material_id'] ?? 0);
    $name     = trim($_POST['material_name'] ?? '');
    $cat      = trim($_POST['category']      ?? 'General');
    $unit     = trim($_POST['unit']          ?? 'pcs');
    $minStock = (float)($_POST['minimum_stock'] ?? 0);
    $cost     = (float)($_POST['unit_cost']     ?? 0);
    $supplier = trim($_POST['supplier'] ?? '');
    $notes    = trim($_POST['notes']    ?? '');

    if (!$matId || !$name) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid input.']);
        exit;
    }

    $pdo->prepare("UPDATE inventory_materials SET material_name=?, category=?, unit=?, minimum_stock=?, unit_cost=?, supplier=?, notes=? WHERE id=?")
        ->execute([$name, $cat, $unit, $minStock, $cost, $supplier ?: null, $notes ?: null, $matId]);

    echo json_encode([
        'ok'       => true,
        'msg'      => '✔ Material updated.',
        'stats'    => fetchStats($pdo),
        'material' => fetchMaterial($pdo, $matId),
    ]);
    exit;
}

// ────────────────────────────────────────────────────────────────────────────
// ADJUST STOCK
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'adjust_stock') {
    $matId  = (int)($_POST['material_id'] ?? 0);
    $adjAct = $_POST['adj_action'] ?? 'add';
    $adjQty = (float)($_POST['adj_qty'] ?? 0);
    $reason = trim($_POST['adj_reason'] ?? '');

    if (!$matId || $adjQty <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid input.']);
        exit;
    }

    $cur = $pdo->prepare("SELECT quantity_in_stock FROM inventory_materials WHERE id=?");
    $cur->execute([$matId]);
    $curQty = (float)($cur->fetchColumn() ?: 0);

    $newQty = ($adjAct === 'add') ? $curQty + $adjQty : max(0, $curQty - $adjQty);

    $pdo->prepare("UPDATE inventory_materials SET quantity_in_stock=? WHERE id=?")
        ->execute([$newQty, $matId]);
    $pdo->prepare("INSERT INTO inventory_transactions (material_id, action, quantity, qty_before, qty_after, reason, entered_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$matId, $adjAct === 'add' ? 'add' : 'remove', $adjQty, $curQty, $newQty, $reason ?: null, $user['id']]);

    echo json_encode([
        'ok'       => true,
        'msg'      => '✔ Stock updated successfully.',
        'stats'    => fetchStats($pdo),
        'material' => fetchMaterial($pdo, $matId),
        'recent_tx'=> fetchRecentTx($pdo),
    ]);
    exit;
}

// ────────────────────────────────────────────────────────────────────────────
// DELETE MATERIAL
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'delete_material') {
    $matId = (int)($_POST['material_id'] ?? 0);
    if (!$matId) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid input.']);
        exit;
    }

    $s = $pdo->prepare("SELECT material_name FROM inventory_materials WHERE id=?");
    $s->execute([$matId]);
    $mName = $s->fetchColumn();

    // Delete image file if exists
    $imgQ = $pdo->prepare("SELECT image_filename FROM inventory_materials WHERE id=?");
    $imgQ->execute([$matId]);
    $imgFile = $imgQ->fetchColumn();
    if ($imgFile) {
        $imgPath = __DIR__ . '/uploads/inventory/' . $imgFile;
        if (file_exists($imgPath)) unlink($imgPath);
    }

    $pdo->prepare("DELETE FROM inventory_transactions WHERE material_id=?")->execute([$matId]);
    $pdo->prepare("DELETE FROM inventory_materials WHERE id=?")->execute([$matId]);

    echo json_encode([
        'ok'         => true,
        'msg'        => "✔ Material '{$mName}' deleted.",
        'deleted_id' => $matId,
        'stats'      => fetchStats($pdo),
        'recent_tx'  => fetchRecentTx($pdo),
    ]);
    exit;
}

// ────────────────────────────────────────────────────────────────────────────
// UPLOAD IMAGE  (multipart/form-data — handled separately, still returns JSON)
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'upload_image') {
    $matId = (int)($_POST['material_id'] ?? 0);
    if (!$matId || empty($_FILES['material_image']['tmp_name'])) {
        echo json_encode(['ok' => false, 'msg' => 'No file or invalid ID.']);
        exit;
    }

    $uploadDir = __DIR__ . '/uploads/inventory/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext     = strtolower(pathinfo($_FILES['material_image']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','webp'];

    if (!in_array($ext, $allowed) || $_FILES['material_image']['size'] > 5*1024*1024) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid file. Use JPG/PNG/WEBP under 5MB.']);
        exit;
    }

    // Delete old image
    $old = $pdo->prepare("SELECT image_filename FROM inventory_materials WHERE id=?");
    $old->execute([$matId]);
    $oldFile = $old->fetchColumn();
    if ($oldFile && file_exists($uploadDir . $oldFile)) unlink($uploadDir . $oldFile);

    $filename = 'mat_' . $matId . '_' . time() . '.' . $ext;
    move_uploaded_file($_FILES['material_image']['tmp_name'], $uploadDir . $filename);
    $pdo->prepare("UPDATE inventory_materials SET image_filename=? WHERE id=?")->execute([$filename, $matId]);

    echo json_encode([
        'ok'       => true,
        'msg'      => '✔ Image uploaded successfully.',
        'img_url'  => 'uploads/inventory/' . $filename,
        'material' => fetchMaterial($pdo, $matId),
    ]);
    exit;
}

// ────────────────────────────────────────────────────────────────────────────
// DELETE IMAGE
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'delete_image') {
    $matId = (int)($_POST['material_id'] ?? 0);
    if (!$matId) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid input.']);
        exit;
    }

    $old = $pdo->prepare("SELECT image_filename FROM inventory_materials WHERE id=?");
    $old->execute([$matId]);
    $oldFile = $old->fetchColumn();
    $uploadDir = __DIR__ . '/uploads/inventory/';
    if ($oldFile && file_exists($uploadDir . $oldFile)) unlink($uploadDir . $oldFile);
    $pdo->prepare("UPDATE inventory_materials SET image_filename=NULL WHERE id=?")->execute([$matId]);

    echo json_encode([
        'ok'       => true,
        'msg'      => '✔ Image removed.',
        'material' => fetchMaterial($pdo, $matId),
    ]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);