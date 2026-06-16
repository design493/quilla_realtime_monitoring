<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
requireLogin();

$pdo  = getPDO();
$user = currentUser();

// Role system:
// admin      → full access (add/edit/delete materials, adjust stock, manage everything, access Admin Panel)
// supervisor → full inventory access (add/edit/delete materials, adjust stock) — no Admin Panel button
$isAdmin      = ($user['role'] === 'admin');
$isProduction = ($user['role'] === 'supervisor');
$canStock     = $isAdmin || $isProduction; // both can adjust stock
$canManage    = $isAdmin || $isProduction; // both can add/edit/delete materials

// ── CREATE TABLES IF NOT EXIST ─────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_materials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        material_name VARCHAR(150) NOT NULL,
        category VARCHAR(80) NOT NULL DEFAULT 'General',
        unit VARCHAR(40) NOT NULL DEFAULT 'pcs',
        quantity_in_stock DECIMAL(10,2) NOT NULL DEFAULT 0,
        minimum_stock DECIMAL(10,2) NOT NULL DEFAULT 0,
        unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
        supplier VARCHAR(150) NULL,
        notes TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch(Exception $e){}

// Add image column if missing (safe migration)
try { $pdo->exec("ALTER TABLE inventory_materials ADD COLUMN image_filename VARCHAR(200) NULL"); } catch(Exception $e){}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        material_id INT NOT NULL,
        action ENUM('add','remove','adjustment') NOT NULL DEFAULT 'add',
        quantity DECIMAL(10,2) NOT NULL,
        qty_before DECIMAL(10,2) NOT NULL DEFAULT 0,
        qty_after DECIMAL(10,2) NOT NULL DEFAULT 0,
        reason VARCHAR(255) NULL,
        entered_by INT NOT NULL,
        entered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch(Exception $e){}

$msg = '';
$msgType = 'success';

// POST handlers have been moved to inventory_api.php (AJAX).
// This page is now read-only on load; all mutations happen via fetch().

    // EDIT MATERIAL stub kept only to avoid parse error on old bookmark hits
    if (false) {
        $matId    = 0;
        $name     = '';
        $cat      = '';
        $unit     = '';
        $minStock = 0;
        $cost     = 0;
        $supplier = '';
        $notes    = '';

        // stub — never reached
    }

// End of legacy POST stub

// ── FETCH DATA ─────────────────────────────────────────────────────────────
$filterCat    = $_GET['cat']    ?? '';
$filterSearch = trim($_GET['q'] ?? '');
$filterStatus = $_GET['status'] ?? '';

$where = "WHERE is_active=1";
$params = [];
if ($filterCat)    { $where .= " AND category=?";          $params[] = $filterCat; }
if ($filterSearch) { $where .= " AND LOWER(material_name) LIKE LOWER(?)"; $params[] = '%'.$filterSearch.'%'; }
if ($filterStatus === 'low')   { $where .= " AND quantity_in_stock > 0 AND quantity_in_stock <= minimum_stock AND minimum_stock > 0"; }
if ($filterStatus === 'ok')    { $where .= " AND quantity_in_stock > 0 AND (quantity_in_stock > minimum_stock OR minimum_stock = 0)"; }
if ($filterStatus === 'empty') { $where .= " AND quantity_in_stock = 0"; }

$matStmt = $pdo->prepare("SELECT * FROM inventory_materials $where ORDER BY category, material_name");
$matStmt->execute($params);
$materials = $matStmt->fetchAll();

// Categories for filter
$cats = $pdo->query("SELECT DISTINCT category FROM inventory_materials WHERE is_active=1 ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

// Summary stats
$totalMats   = (int)$pdo->query("SELECT COUNT(*) FROM inventory_materials WHERE is_active=1")->fetchColumn();
$lowStock    = (int)$pdo->query("SELECT COUNT(*) FROM inventory_materials WHERE is_active=1 AND quantity_in_stock <= minimum_stock AND minimum_stock > 0 AND quantity_in_stock > 0")->fetchColumn();
$outOfStock  = (int)$pdo->query("SELECT COUNT(*) FROM inventory_materials WHERE is_active=1 AND quantity_in_stock = 0")->fetchColumn();
$totalValue  = (float)$pdo->query("SELECT COALESCE(SUM(quantity_in_stock * unit_cost),0) FROM inventory_materials WHERE is_active=1")->fetchColumn();

// Recent transactions
$recentTx = $pdo->prepare("
    SELECT t.*, m.material_name, m.unit, u.full_name
    FROM inventory_transactions t
    JOIN inventory_materials m ON m.id = t.material_id
    JOIN users u ON u.id = t.entered_by
    ORDER BY t.entered_at DESC LIMIT 20
");
$recentTx->execute();
$recentTx = $recentTx->fetchAll();

// Predefined categories for shoe production
$predefinedCats = ['Upper Material', 'Sole', 'Hardware', 'Adhesive', 'Thread', 'Packaging', 'Chemicals', 'Tools', 'General', 'canvas', 'Genuine Leather', 'Alcantara Leather', 'Synthetic Leather','Lining','Canvas w/ Foam'];
$predefinedUnits = ['pcs', 'pairs', 'meters', 'yards', 'rolls', 'liters', 'kg', 'grams', 'boxes', 'sheets', 'sets', 'bucket', 'can', 'Gallons', 'Bottle'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Quilla — Inventory</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800;900&family=Barlow:wght@400;500;600;700&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#1a3a8f;--blue-dark:#122a6b;--blue-mid:#1e47b0;--blue-light:#2557d6;
  --gold:#f5c518;--gold2:#e8b500;
  --green:#22c55e;--red:#dc2626;--orange:#f59e0b;--purple:#7c3aed;
  --bg:#0d1e52;--sidebar:#112068;
  --main:#eef2fb;--white:#fff;
  --border:#c8d5f0;--text:#0d1e52;--text2:#4a5b8a;
  --card-bg:#fff;
}
body{font-family:'Barlow',sans-serif;background:var(--main);color:var(--text);min-height:100vh}

/* ── HEADER ── */
.top-bar{
  background:linear-gradient(90deg,var(--blue-dark) 0%,var(--blue-mid) 100%);
  padding:14px 28px;display:flex;align-items:center;justify-content:space-between;
  border-bottom:3px solid var(--gold);position:sticky;top:0;z-index:100;
  box-shadow:0 2px 16px rgba(13,30,82,.25);
}
.top-bar-brand{display:flex;align-items:center;gap:14px}
.top-bar-title{
  font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:900;
  color:#fff;text-transform:uppercase;letter-spacing:2px;
}
.top-bar-sub{font-size:10px;font-weight:700;color:rgba(255,255,255,.45);text-transform:uppercase;letter-spacing:2px;margin-top:1px}
.top-bar-right{display:flex;align-items:center;gap:12px}
.top-bar-user{font-size:12px;color:rgba(255,255,255,.6);font-weight:600}
.top-bar-user strong{color:#fff;font-size:13px}
.tb-role{background:var(--gold);color:var(--blue-dark);font-size:10px;font-weight:800;padding:2px 9px;border-radius:20px;text-transform:uppercase;letter-spacing:1px}
.btn-back{
  display:inline-flex;align-items:center;gap:7px;
  background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);
  color:rgba(255,255,255,.8);border-radius:6px;padding:7px 14px;
  font-size:11px;font-weight:700;text-decoration:none;text-transform:uppercase;
  letter-spacing:.5px;transition:.2s;
}
.btn-back:hover{background:rgba(255,255,255,.18);color:#fff}

/* ── MAIN LAYOUT ── */
.main{max-width:1400px;margin:0 auto;padding:28px}

/* ── SUCCESS / ERROR MSG ── */
.flash{
  border-radius:8px;padding:12px 18px;font-size:13px;font-weight:600;
  margin-bottom:20px;display:flex;align-items:center;gap:9px;
  animation:fadeInDown .35s ease, fadeOut .6s ease 2.4s forwards;
}
.flash-success{background:linear-gradient(90deg,#16a34a,#15803d);color:#fff;box-shadow:0 4px 14px rgba(22,163,74,.3)}
.flash-error{background:linear-gradient(90deg,#dc2626,#b91c1c);color:#fff;box-shadow:0 4px 14px rgba(220,38,38,.3)}
@keyframes fadeInDown{from{opacity:0;transform:translateY(-12px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeOut{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(-8px);pointer-events:none}}

/* ── STAT CARDS ── */
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
.stat{
  background:var(--card-bg);border-radius:12px;padding:20px;
  border:1px solid var(--border);position:relative;overflow:hidden;
  box-shadow:0 2px 12px rgba(26,58,143,.08);transition:transform .2s,box-shadow .2s;
}
.stat:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(26,58,143,.14)}
.stat::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.stat.s-blue::before{background:linear-gradient(90deg,var(--blue),var(--blue-light))}
.stat.s-green::before{background:linear-gradient(90deg,#16a34a,#22c55e)}
.stat.s-orange::before{background:linear-gradient(90deg,#d97706,#f59e0b)}
.stat.s-red::before{background:linear-gradient(90deg,#b91c1c,#dc2626)}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:12px}
.stat.s-blue .stat-icon{background:#dbeafe}
.stat.s-green .stat-icon{background:#dcfce7}
.stat.s-orange .stat-icon{background:#fef3c7}
.stat.s-red .stat-icon{background:#fee2e2}
.stat-val{font-size:34px;font-weight:900;color:var(--blue-dark);line-height:1;font-family:'Barlow Condensed',sans-serif}
.stat-label{font-size:11px;color:var(--text2);margin-top:5px;font-weight:700;text-transform:uppercase;letter-spacing:1px}

/* ── SECTION TITLE ── */
h2.sec-title{
  font-family:'Barlow Condensed',sans-serif;
  font-size:20px;font-weight:900;letter-spacing:1px;text-transform:uppercase;
  color:var(--blue-dark);margin-bottom:16px;
  display:flex;align-items:center;gap:10px;
  padding-bottom:10px;border-bottom:3px solid var(--blue-light);
}

/* ── FILTER BAR ── */
.filter-bar{
  background:var(--card-bg);border:1px solid var(--border);border-radius:10px;
  padding:14px 18px;margin-bottom:18px;
  display:flex;align-items:center;gap:12px;flex-wrap:wrap;
  box-shadow:0 2px 8px rgba(26,58,143,.06);
}
.filter-bar input,.filter-bar select{
  padding:8px 12px;border:1.5px solid var(--border);border-radius:6px;
  font-size:12px;outline:none;transition:.2s;font-family:'Barlow',sans-serif;
  background:#fff;color:var(--text);
}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--blue-light);box-shadow:0 0 0 3px rgba(37,87,214,.12)}
.filter-bar input{min-width:220px}
.filter-lbl{font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;white-space:nowrap}
.filter-tag{
  display:inline-flex;align-items:center;gap:5px;
  background:#dbeafe;color:var(--blue);border-radius:5px;
  padding:4px 10px;font-size:11px;font-weight:700;
}

/* ── PANEL ── */
.panel{
  background:var(--card-bg);border:1px solid var(--border);
  border-radius:12px;overflow:hidden;margin-bottom:24px;
  box-shadow:0 2px 10px rgba(26,58,143,.06);
}
.panel-head{
  padding:14px 20px;border-bottom:2px solid var(--blue);
  display:flex;align-items:center;justify-content:space-between;
  background:linear-gradient(90deg,var(--blue) 0%,var(--blue-mid) 100%);
}
.panel-title{font-size:13px;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:1px;display:flex;align-items:center;gap:8px}
.panel-count{background:rgba(255,255,255,.2);color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}

/* ── TABLE ── */
table{width:100%;border-collapse:collapse}
th{
  padding:10px 14px;text-align:left;font-size:11px;font-weight:700;
  color:var(--blue-dark);background:#e8eef8;
  text-transform:uppercase;letter-spacing:.8px;border-bottom:2px solid var(--border);
  white-space:nowrap;
}
td{padding:11px 14px;font-size:13px;border-bottom:1px solid #edf1fb;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#f5f7ff}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:5px;font-size:11px;font-weight:700;letter-spacing:.3px}
.badge-ok{background:#dcfce7;color:#15803d}
.badge-low{background:#fef3c7;color:#92400e}
.badge-out{background:#fee2e2;color:#dc2626}
.badge-cat{background:#ede9fe;color:#5b21b6}
.badge-blue{background:#dbeafe;color:#1e40af}

/* ── STOCK INDICATOR ── */
.stock-bar-wrap{display:flex;align-items:center;gap:8px;min-width:120px}
.stock-bar{flex:1;height:6px;background:#e8eef8;border-radius:10px;overflow:hidden}
.stock-bar-fill{height:100%;border-radius:10px;transition:width .4s}

/* ── BUTTONS ── */
.btn{
  padding:8px 18px;border:none;border-radius:6px;
  font-size:11px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;
  cursor:pointer;transition:.2s;font-family:'Barlow',sans-serif;
  display:inline-flex;align-items:center;gap:6px;
}
.btn-primary{background:var(--blue);color:#fff}
.btn-primary:hover{background:var(--blue-dark);box-shadow:0 4px 12px rgba(26,58,143,.3)}
.btn-success{background:#16a34a;color:#fff}
.btn-success:hover{background:#15803d;box-shadow:0 4px 12px rgba(22,163,74,.3)}
.btn-warning{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.btn-warning:hover{background:#fde047;color:#713f12}
.btn-danger{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5}
.btn-danger:hover{background:#dc2626;color:#fff}
.btn-sm{padding:5px 10px;font-size:10px}
.btn-add-main{
  background:linear-gradient(90deg,var(--blue),var(--blue-light));color:#fff;
  padding:10px 22px;border:none;border-radius:8px;
  font-size:12px;font-weight:800;letter-spacing:1px;text-transform:uppercase;
  cursor:pointer;transition:.2s;font-family:'Barlow',sans-serif;
  display:inline-flex;align-items:center;gap:8px;
  box-shadow:0 4px 14px rgba(26,58,143,.3);
}
.btn-add-main:hover{box-shadow:0 6px 20px rgba(26,58,143,.4);transform:translateY(-1px)}

/* ── MODALS ── */
.modal-bg{
  display:none;position:fixed;inset:0;
  background:rgba(13,30,82,.7);z-index:300;
  align-items:center;justify-content:center;backdrop-filter:blur(5px);
}
.modal-bg.open{display:flex}
.modal{
  background:#fff;border-radius:16px;padding:28px;
  width:540px;max-width:95vw;max-height:90vh;overflow-y:auto;
  box-shadow:0 24px 60px rgba(13,30,82,.35);position:relative;
}
.modal.modal-wide{width:680px}
.modal-header{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:20px;padding-bottom:14px;border-bottom:2px solid var(--border);
}
.modal-title{
  font-family:'Barlow Condensed',sans-serif;font-size:20px;font-weight:900;
  color:var(--blue-dark);text-transform:uppercase;letter-spacing:1px;
  display:flex;align-items:center;gap:8px;
}
.modal-close{
  background:none;border:none;cursor:pointer;color:var(--text2);
  font-size:22px;line-height:1;transition:.2s;padding:2px 6px;border-radius:4px;
}
.modal-close:hover{background:#fee2e2;color:#dc2626}
.modal-top{height:4px;position:absolute;top:0;left:0;right:0;border-radius:16px 16px 0 0}
.modal-top-blue{background:linear-gradient(90deg,var(--blue),var(--blue-light))}
.modal-top-red{background:linear-gradient(90deg,#dc2626,#b91c1c)}
.modal-top-green{background:linear-gradient(90deg,#15803d,#22c55e)}
.modal-top-orange{background:linear-gradient(90deg,#d97706,#f59e0b)}

/* ── FORM INSIDE MODAL ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px}
.form-grid.col1{grid-template-columns:1fr}
.form-grid.col3{grid-template-columns:1fr 1fr 1fr}
.fg{display:flex;flex-direction:column;gap:5px}
.fg.span2{grid-column:1/-1}
.fg label{font-size:11px;font-weight:700;color:var(--blue-dark);text-transform:uppercase;letter-spacing:.8px}
.fg input,.fg select,.fg textarea{
  padding:9px 12px;border:1.5px solid var(--border);
  border-radius:6px;font-size:13px;outline:none;transition:.2s;
  font-family:'Barlow',sans-serif;background:#fff;
}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--blue-light);box-shadow:0 0 0 3px rgba(37,87,214,.12)}
.fg textarea{resize:vertical;min-height:60px}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;padding-top:14px;border-top:1px solid var(--border);margin-top:4px}

/* ── DEL CONFIRM MODAL ── */
.modal-del .modal-title{color:#dc2626}
.del-warn{
  background:#fff5f5;border:1px solid #fecaca;border-radius:8px;
  padding:12px 14px;font-size:13px;color:#991b1b;
  margin:12px 0 20px;line-height:1.5;
  display:flex;gap:10px;align-items:flex-start;
}
.del-warn svg{flex-shrink:0;margin-top:1px}

/* ── ADJUST MODAL ── */
.adj-tabs{display:flex;gap:0;margin-bottom:18px;border-radius:8px;overflow:hidden;border:1.5px solid var(--border)}
.adj-tab{
  flex:1;padding:10px;text-align:center;cursor:pointer;font-size:12px;
  font-weight:800;text-transform:uppercase;letter-spacing:.5px;
  background:#f5f7ff;color:var(--text2);border:none;transition:.2s;
  font-family:'Barlow',sans-serif;
}
.adj-tab.active-add{background:#dcfce7;color:#16a34a}
.adj-tab.active-remove{background:#fee2e2;color:#dc2626}
.adj-qty-big{
  font-size:38px;font-weight:900;color:var(--blue-dark);
  font-family:'Barlow Condensed',sans-serif;
  text-align:center;padding:6px 0 14px;line-height:1;
}
.adj-cur-stock{
  text-align:center;font-size:12px;color:var(--text2);font-weight:600;
  margin-bottom:14px;
}

/* ── IMAGE THUMBNAIL ── */
.mat-img-wrap{
  width:52px;height:52px;border-radius:8px;overflow:hidden;
  border:2px solid var(--border);background:#f0f4fc;
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;cursor:pointer;position:relative;
}
.mat-img-wrap img{width:100%;height:100%;object-fit:cover}
.mat-img-placeholder{
  font-size:22px;opacity:.35;
}
.mat-img-wrap .img-overlay{
  display:none;position:absolute;inset:0;
  background:rgba(26,58,143,.6);
  align-items:center;justify-content:center;
  font-size:9px;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:.5px;
  flex-direction:column;gap:2px;border-radius:6px;
}
.mat-img-wrap:hover .img-overlay{display:flex}

/* ── IMG MODAL ── */
.img-preview-big{
  width:100%;max-height:320px;object-fit:contain;border-radius:8px;
  border:1px solid var(--border);background:#f5f7ff;display:block;margin-bottom:14px;
}
.img-drop-zone{
  border:2px dashed var(--border);border-radius:10px;
  padding:28px 20px;text-align:center;cursor:pointer;
  transition:.2s;background:#f8faff;
}
.img-drop-zone:hover,.img-drop-zone.drag-over{border-color:var(--blue-light);background:#eff4ff}
.img-drop-zone input[type=file]{display:none}
.img-drop-icon{font-size:32px;margin-bottom:8px;opacity:.5}
.img-drop-text{font-size:12px;font-weight:700;color:var(--text2)}
.img-drop-hint{font-size:11px;color:#a0aec0;margin-top:4px}
.img-preview-thumb{
  width:100%;height:160px;object-fit:contain;border-radius:8px;
  border:1px solid #c8d5f0;margin-top:12px;display:none;background:#f0f4fc;
}


/* ── CATEGORY GROUP ROW ── */
.cat-group-row td{
  background:linear-gradient(90deg,#eef2fb 0%,#f5f7ff 100%);
  padding:7px 14px !important;
  border-top:2px solid var(--border);
  border-bottom:1px solid var(--border);
  cursor:pointer;user-select:none;
}
.cat-group-row:hover td{ background:linear-gradient(90deg,#e2e9f8 0%,#eef2fb 100%); }
.cat-group-label{
  display:inline-flex;align-items:center;gap:7px;
  font-family:'Barlow Condensed',sans-serif;
  font-size:13px;font-weight:900;letter-spacing:1.5px;
  text-transform:uppercase;color:var(--blue-dark);
  width:100%;
}
.cat-group-row:first-child td{ border-top:none; }
.cat-chevron{
  margin-left:auto;transition:transform .22s ease;
  display:inline-flex;align-items:center;justify-content:center;
  background:var(--blue);color:#fff;border-radius:5px;
  width:22px;height:22px;flex-shrink:0;
  box-shadow:0 1px 4px rgba(26,58,143,.25);
}
.cat-chevron svg{ stroke:#fff; }
.cat-group-row.collapsed .cat-chevron{ transform:rotate(-90deg); }
.cat-group-row.collapsed + tr:not(.cat-group-row),
.cat-group-row.collapsed ~ tr:not(.cat-group-row){ /* handled by JS */ }

/* ── SMOOTH CATEGORY ROW ANIMATION ── */
tr.cat-row-animating > td {
  overflow: hidden;
  transition: padding-top .28s ease, padding-bottom .28s ease, max-height .28s ease;
  max-height: 200px;
  padding-top: 11px;
  padding-bottom: 11px;
}
tr.cat-row-animating.cat-row-collapsing > td {
  max-height: 0 !important;
  padding-top: 0 !important;
  padding-bottom: 0 !important;
}

.tx-add{color:#15803d;font-weight:700}
.tx-remove{color:#dc2626;font-weight:700}
.empty-state{
  padding:40px 20px;text-align:center;
  color:var(--text2);font-size:14px;font-weight:600;
}
.empty-state-icon{font-size:40px;margin-bottom:10px;opacity:.5}

/* ── TOAST NOTIFICATION (same style as input_entry.php) ── */
.inv-toast{
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
  animation:invToastPop .35s cubic-bezier(.175,.885,.32,1.275) forwards;
  pointer-events:auto;
  cursor:pointer;
}
.inv-toast-icon-wrap{
  width:64px;height:64px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:32px;
  margin-bottom:6px;
}
.inv-toast-title{font-size:20px;font-weight:900;letter-spacing:-.3px}
.inv-toast-sub{font-size:13px;font-weight:600;opacity:.75;margin-top:2px}
.inv-toast.inv-toast-success{
  background:#fff;
  border:3px solid #16a34a;
  color:#14532d;
}
.inv-toast.inv-toast-success .inv-toast-icon-wrap{
  background:linear-gradient(135deg,#16a34a,#22c55e);
  box-shadow:0 6px 20px rgba(22,163,74,.35);
}
.inv-toast.inv-toast-error{
  background:#fff;
  border:3px solid #dc2626;
  color:#7f1d1d;
}
.inv-toast.inv-toast-error .inv-toast-icon-wrap{
  background:linear-gradient(135deg,#dc2626,#ef4444);
  box-shadow:0 6px 20px rgba(220,38,38,.35);
}
.inv-toast-overlay{
  position:fixed;inset:0;
  background:rgba(13,30,82,.25);
  backdrop-filter:blur(3px);
  z-index:9998;
  opacity:0;
  animation:invFadeIn .3s ease forwards;
}
@keyframes invToastPop{
  0%  {opacity:0;transform:translate(-50%,-50%) scale(.7)}
  100%{opacity:1;transform:translate(-50%,-50%) scale(1)}
}
@keyframes invFadeIn{
  from{opacity:0}to{opacity:1}
}
@keyframes invToastOut{
  0%  {opacity:1;transform:translate(-50%,-50%) scale(1)}
  100%{opacity:0;transform:translate(-50%,-50%) scale(.8)}
}

/* ── RESPONSIVE ── */
@media(max-width:900px){
  .stat-grid{grid-template-columns:repeat(2,1fr)}
  .form-grid{grid-template-columns:1fr}
  .form-grid.col3{grid-template-columns:1fr 1fr}
  .top-bar{padding:12px 16px}
  .main{padding:16px}
}
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="top-bar">
  <div class="top-bar-brand">
    <div>
      <div class="top-bar-title">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2.5" style="flex-shrink:0"><path d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="12.01"/></svg>
        Inventory
      </div>
      <div class="top-bar-sub">Shoe Production Materials</div>
    </div>
  </div>
  <div class="top-bar-right">
    <div class="top-bar-user">
      Logged in as <strong><?= htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'User') ?></strong>
    </div>
    <?php if ($isAdmin): ?>
      <span class="tb-role">Admin</span>
    <?php else: ?>
      <span class="tb-role" style="background:rgba(255,255,255,.2);color:#fff">
        <?= htmlspecialchars(ucfirst($user['role'])) ?>
      </span>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
      <a href="admin/dashboard.php" class="btn-back" style="background:rgba(245,197,24,.25);border-color:rgba(245,197,24,.6);color:var(--gold);font-weight:800">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        Dashboard
      </a>
    <?php elseif ($isProduction): ?>
      <a href="index.php" class="btn-back" style="background:rgba(245,197,24,.25);border-color:rgba(245,197,24,.6);color:var(--gold);font-weight:800">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        Back
      </a>
    <?php endif; ?>

  </div>
</div>

<div class="main">

  <!-- FLASH -->
  <?php if ($msg): ?>
    <div class="flash <?= strpos($msg,'✔') !== false ? 'flash-success' : 'flash-error' ?>" id="flashMsg">
      <?= strpos($msg,'✔') !== false
        ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>'
        : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' ?>
      <?= htmlspecialchars($msg) ?>
    </div>
    <script>setTimeout(function(){ var el=document.getElementById('flashMsg'); if(el) el.remove(); }, 3000);</script>
  <?php endif; ?>


  <!-- STAT CARDS -->
  <div class="stat-grid">
    <div class="stat s-blue">
      <div class="stat-icon">📦</div>
      <div class="stat-val" id="stat-total"><?= $totalMats ?></div>
      <div class="stat-label">Total Materials</div>
    </div>
    <div class="stat s-green">
      <div class="stat-icon">✅</div>
      <div class="stat-val" id="stat-instock"><?= $totalMats - $lowStock - $outOfStock ?></div>
      <div class="stat-label">In Stock</div>
    </div>
    <div class="stat s-orange">
      <div class="stat-icon">⚠️</div>
      <div class="stat-val" id="stat-low"><?= $lowStock ?></div>
      <div class="stat-label">Low Stock</div>
    </div>
    <div class="stat s-red">
      <div class="stat-icon">🚫</div>
      <div class="stat-val" id="stat-out"><?= $outOfStock ?></div>
      <div class="stat-label">Out of Stock</div>
    </div>
  </div>

  <!-- INVENTORY TABLE -->
  <h2 class="sec-title">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
    Materials Inventory
    <?php if ($canManage): ?> <!-- Admin + Production: add material -->
      <button class="btn-add-main" onclick="openAddModal()" style="margin-left:auto;font-size:11px">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Material
      </button>
    <?php endif; ?>
  </h2>

  <!-- FILTER BAR -->
  <form method="GET" action="inventory.php" id="filter-form">
    <div class="filter-bar">
      <span class="filter-lbl">Filter:</span>
      <input type="text" name="q" id="filter-search" placeholder="🔍  Search material name..." value="<?= htmlspecialchars($filterSearch) ?>" autocomplete="off">
      <select name="cat" onchange="this.form.submit()">
        <option value="">All Categories</option>
        <?php foreach($cats as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>" <?= $filterCat===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="ok"    <?= $filterStatus==='ok'   ?'selected':'' ?>>✅ In Stock</option>
        <option value="low"   <?= $filterStatus==='low'  ?'selected':'' ?>>⚠️ Low Stock</option>
        <option value="empty" <?= $filterStatus==='empty'?'selected':'' ?>>🚫 Out of Stock</option>
      </select>
      <?php if ($filterSearch || $filterCat || $filterStatus): ?>
        <a href="inventory.php" class="btn btn-warning" style="text-decoration:none">Clear</a>
        <span class="filter-tag">
          <?= count($materials) ?> result<?= count($materials)!=1?'s':'' ?>
        </span>
      <?php endif; ?>
    </div>
  </form>
  <script>
  (function(){
    var input   = document.getElementById('filter-search');
    var form    = document.getElementById('filter-form');
    var timer   = null;
    if (!input || !form) return;

    // Always restore focus to search bar after page reload (including when empty)
    input.focus();
    var len = input.value.length;
    input.setSelectionRange(len, len);

    input.addEventListener('input', function(){
      clearTimeout(timer);
      timer = setTimeout(function(){ form.submit(); }, 500);
    });
  })();
  </script>

  <div class="panel">
    <div class="panel-head">
      <div class="panel-title">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/></svg>
        Stock List
        <span class="panel-count" id="panel-count"><?= count($materials) ?></span>
      </div>
      <?php if ($canManage): ?> <!-- Admin + Production: add material header btn -->
      <button class="btn btn-success btn-sm" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff" onclick="openAddModal()">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Material
      </button>
      <?php endif; ?>
    </div>
    <?php if (empty($materials)): ?>
      <div class="empty-state">
        <div class="empty-state-icon">📦</div>
        <div>No materials found.</div>
        <?php if ($canManage): ?>
          <div style="margin-top:10px">
            <button class="btn btn-primary" onclick="openAddModal()">Add your first material</button>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
    <div style="overflow-x:auto;overflow-y:auto;max-height:730px">
    <table>
      <thead style="position:sticky;top:0;z-index:2">
        <tr>
          <th>#</th>
          <th>Image</th>
          <th>Material Name</th>
          <th>Category</th>
          <th>Unit</th>
          <th style="text-align:center">In Stock</th>
          <th style="text-align:center">Min Stock</th>
          <th style="text-align:center">Status</th>
          <th style="text-align:center">Unit Cost</th>
          <th>Supplier</th>
          <?php if ($canManage): ?><th style="text-align:center">Actions</th><?php endif; ?>
        </tr>
      </thead>
      <tbody id="inv-tbody">
        <?php
        $lastCat = null;
        foreach($materials as $i => $mat):
          $qty = (float)$mat['quantity_in_stock'];
          $min = (float)$mat['minimum_stock'];
          if ($qty == 0)            { $statusBadge = 'badge-out'; $statusLabel = '🚫 Out of Stock'; }
          elseif ($min>0 && $qty<=$min) { $statusBadge = 'badge-low'; $statusLabel = '⚠️ Low Stock'; }
          else                          { $statusBadge = 'badge-ok';  $statusLabel = '✅ In Stock'; }
          $ceiling = max($qty, $min * 2, 1);
          $pct = $qty > 0 ? min(100, round(($qty / $ceiling) * 100)) : 0;
          $barColor = $qty==0 ? '#dc2626' : ($min>0&&$qty<=$min ? '#f59e0b' : '#22c55e');
          $colSpan = $canManage ? 11 : 10;
          if ($mat['category'] !== $lastCat):
            $lastCat = $mat['category'];
        ?>
        <tr class="cat-group-row" data-cat="<?= htmlspecialchars($mat['category']) ?>" onclick="toggleCategory(this)">
          <td colspan="<?= $colSpan ?>">
            <span class="cat-group-label">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
              <?= htmlspecialchars($mat['category']) ?>
              <span class="cat-chevron"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg></span>
            </span>
          </td>
        </tr>
        <?php endif; ?>
        <tr data-mat-id="<?= $mat['id'] ?>">
          <td style="color:var(--text2);font-size:12px"><?= $i+1 ?></td>
          <td>
            <?php
              $imgFile = $mat['image_filename'] ?? '';
              $imgUrl  = $imgFile ? 'uploads/inventory/' . htmlspecialchars($imgFile) : '';
            ?>
            <?php if ($canManage): ?>
            <div class="mat-img-wrap" onclick="openImgModal(<?= $mat['id'] ?>, '<?= htmlspecialchars(addslashes($mat['material_name'])) ?>', '<?= $imgUrl ?>')" title="Click to manage photo">
              <?php if ($imgUrl): ?>
                <img src="<?= $imgUrl ?>" alt="<?= htmlspecialchars($mat['material_name']) ?>">
              <?php else: ?>
                <span class="mat-img-placeholder">👟</span>
              <?php endif; ?>
              <div class="img-overlay">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                <?= $imgUrl ? 'Change' : 'Upload' ?>
              </div>
            </div>
            <?php else: ?>
            <div class="mat-img-wrap" style="cursor:default">
              <?php if ($imgUrl): ?>
                <img src="<?= $imgUrl ?>" alt="<?= htmlspecialchars($mat['material_name']) ?>">
              <?php else: ?>
                <span class="mat-img-placeholder">👟</span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-weight:700;color:var(--blue-dark)"><?= htmlspecialchars($mat['material_name']) ?></div>
            <?php if ($mat['notes']): ?>
              <div style="font-size:11px;color:var(--text2);margin-top:2px"><?= htmlspecialchars(mb_strimwidth($mat['notes'],0,60,'…')) ?></div>
            <?php endif; ?>
          </td>
          <td><span class="badge badge-cat"><?= htmlspecialchars($mat['category']) ?></span></td>
          <td style="color:var(--text2);font-size:12px;font-weight:600"><?= htmlspecialchars($mat['unit']) ?></td>
          <td style="text-align:center">
            <div class="stock-bar-wrap" style="justify-content:center">
              <strong style="font-family:'Barlow Condensed',sans-serif;font-size:18px;color:var(--blue-dark);min-width:36px">
                <?= number_format($qty, 0, '.', ',') ?>
              </strong>
              <div class="stock-bar">
                <div class="stock-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
              </div>
            </div>
          </td>
          <td style="font-size:12px;color:var(--text2);font-weight:600;text-align:center">
            <?= $min > 0 ? number_format($min, 0, '.', ',') : '<span style="color:#c8d5f0">—</span>' ?>
          </td>
          <td><span class="badge <?= $statusBadge ?>"><?= $statusLabel ?></span></td>
          <td style="font-size:13px;font-weight:700;color:var(--blue-dark);text-align:center">
            <?= $mat['unit_cost'] > 0 ? '₱'.number_format((float)$mat['unit_cost'],2) : '<span style="color:#c8d5f0;font-size:11px">N/A</span>' ?>
          </td>
          <td style="font-size:12px;color:var(--text2)">
            <?= $mat['supplier'] ? htmlspecialchars($mat['supplier']) : '<span style="color:#c8d5f0">—</span>' ?>
          </td>
          <?php if ($canManage): ?>
          <td>
            <div style="display:flex;gap:5px;align-items:center;justify-content:center">
              <button class="btn btn-sm" style="background:#ede9fe;color:#5b21b6;border:1px solid #ddd6fe;padding:6px 8px"
                onclick="openImgModal(<?= $mat['id'] ?>, '<?= htmlspecialchars(addslashes($mat['material_name'])) ?>', '<?= $imgUrl ?>')"
                title="Manage photo">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
              </button>
              <button class="btn btn-success btn-sm" style="padding:6px 8px"
                onclick="openAdjustModal(<?= $mat['id'] ?>, '<?= htmlspecialchars(addslashes($mat['material_name'])) ?>', <?= $qty ?>, '<?= htmlspecialchars($mat['unit']) ?>')"
                title="Adjust stock">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              </button>
              <button class="btn btn-warning btn-sm" style="padding:6px 8px"
                onclick='openEditModal(<?= json_encode($mat) ?>)'
                title="Edit material">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>
              <button class="btn btn-danger btn-sm" style="padding:6px 8px"
                onclick="openDeleteModal(<?= $mat['id'] ?>, '<?= htmlspecialchars(addslashes($mat['material_name'])) ?>')"
                title="Delete material">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
              </button>
            </div>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- RECENT TRANSACTIONS -->
  <h2 class="sec-title" style="margin-top:8px">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    Recent Transactions
  </h2>
  <div class="panel">
    <div class="panel-head">
      <div class="panel-title">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Activity Log
        <span class="panel-count">Last 20</span>
      </div>
    </div>
    <?php if (empty($recentTx)): ?>
      <div class="empty-state">
        <div class="empty-state-icon">📋</div>
        <div>No transactions yet.</div>
      </div>
    <?php else: ?>
    <div style="overflow-x:auto;overflow-y:auto;max-height:730px">
    <table>
      <thead style="position:sticky;top:0;z-index:2">
        <tr>
          <th>Date & Time</th>
          <th>Material</th>
          <th>Action</th>
          <th>Quantity</th>
          <th>Before → After</th>
          <th>Reason</th>
          <th>By</th>
        </tr>
      </thead>
      <tbody id="tx-tbody">
        <?php foreach($recentTx as $tx):
          $d = new DateTime($tx['entered_at']);
        ?>
        <tr>
          <td>
            <div style="font-weight:700;font-size:12px"><?= $d->format('M d, Y') ?></div>
            <div style="font-size:11px;color:var(--text2)"><?= $d->format('h:i A') ?></div>
          </td>
          <td>
            <span style="font-weight:700;color:var(--blue-dark)"><?= htmlspecialchars($tx['material_name']) ?></span>
            <span style="font-size:11px;color:var(--text2);margin-left:4px">(<?= htmlspecialchars($tx['unit']) ?>)</span>
          </td>
          <td>
            <?php if($tx['action']==='add'): ?>
              <span class="badge badge-ok">➕ Added</span>
            <?php elseif($tx['action']==='remove'): ?>
              <span class="badge badge-out">➖ Removed</span>
            <?php else: ?>
              <span class="badge badge-blue">🔄 Adjustment</span>
            <?php endif; ?>
          </td>
          <td>
            <span style="font-family:'Barlow Condensed',sans-serif;font-size:18px;font-weight:900;
              color:<?= $tx['action']==='add'?'#15803d':'#dc2626' ?>">
              <?= $tx['action']==='add'?'+':'-' ?><?= number_format((float)$tx['quantity'],2) ?>
            </span>
          </td>
          <td style="font-size:12px;font-weight:600;color:var(--text2)">
            <?= number_format((float)$tx['qty_before'],2) ?>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin:0 3px"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            <strong style="color:var(--blue-dark)"><?= number_format((float)$tx['qty_after'],2) ?></strong>
          </td>
          <td style="font-size:12px;color:var(--text2)">
            <?= $tx['reason'] ? htmlspecialchars($tx['reason']) : '<span style="color:#c8d5f0">—</span>' ?>
          </td>
          <td style="font-size:12px;font-weight:700;color:var(--blue-dark)"><?= htmlspecialchars($tx['full_name']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /.main -->

<?php if ($canManage): ?>

<!-- ══ ADD MATERIAL MODAL ══════════════════════════════════════════════════ -->
<div class="modal-bg" id="addModal">
  <div class="modal modal-wide">
    <div class="modal-top modal-top-blue"></div>
    <div class="modal-header">
      <div class="modal-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add New Material
      </div>
      <button class="modal-close" onclick="closeModal('addModal')">✕</button>
    </div>
    <form id="add-form">
      <input type="hidden" name="action" value="add_material">
      <div class="form-grid">
        <div class="fg span2">
          <label>Material Name *</label>
          <input type="text" name="material_name" required placeholder="e.g. Leather Upper — Black, Size A">
        </div>
        <div class="fg">
          <label>Category *</label>
          <select name="category" required>
            <?php foreach($predefinedCats as $pc): ?>
              <option value="<?= htmlspecialchars($pc) ?>"><?= htmlspecialchars($pc) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label>Unit *</label>
          <select name="unit" required>
            <?php foreach($predefinedUnits as $pu): ?>
              <option value="<?= htmlspecialchars($pu) ?>"><?= htmlspecialchars($pu) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label>Initial Quantity in Stock</label>
          <input type="number" name="quantity_in_stock" value="0" min="0" step="0.01">
        </div>
        <div class="fg">
          <label>Minimum Stock Level</label>
          <input type="number" name="minimum_stock" value="0" min="0" step="0.01" placeholder="Trigger low stock alert">
        </div>
        <div class="fg">
          <label>Unit Cost (₱)</label>
          <input type="number" name="unit_cost" value="0" min="0" step="0.01">
        </div>
        <div class="fg">
          <label>Supplier</label>
          <input type="text" name="supplier" placeholder="Supplier name (optional)">
        </div>
        <div class="fg span2">
          <label>Notes</label>
          <textarea name="notes" placeholder="Optional notes, specifications, color codes…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-warning" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          Save Material
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ EDIT MATERIAL MODAL ════════════════════════════════════════════════ -->
<div class="modal-bg" id="editModal">
  <div class="modal modal-wide">
    <div class="modal-top modal-top-orange"></div>
    <div class="modal-header">
      <div class="modal-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--orange)" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Edit Material
      </div>
      <button class="modal-close" onclick="closeModal('editModal')">✕</button>
    </div>
    <form id="edit-form">
      <input type="hidden" name="action" value="edit_material">
      <input type="hidden" name="material_id" id="edit_material_id">
      <div class="form-grid">
        <div class="fg span2">
          <label>Material Name *</label>
          <input type="text" name="material_name" id="edit_name" required>
        </div>
        <div class="fg">
          <label>Category</label>
          <select name="category" id="edit_category">
            <?php foreach($predefinedCats as $pc): ?>
              <option value="<?= htmlspecialchars($pc) ?>"><?= htmlspecialchars($pc) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label>Unit</label>
          <select name="unit" id="edit_unit">
            <?php foreach($predefinedUnits as $pu): ?>
              <option value="<?= htmlspecialchars($pu) ?>"><?= htmlspecialchars($pu) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label>Minimum Stock Level</label>
          <input type="number" name="minimum_stock" id="edit_min_stock" min="0" step="0.01">
        </div>
        <div class="fg">
          <label>Unit Cost (₱)</label>
          <input type="number" name="unit_cost" id="edit_unit_cost" min="0" step="0.01">
        </div>
        <div class="fg">
          <label>Supplier</label>
          <input type="text" name="supplier" id="edit_supplier">
        </div>
        <div class="fg span2">
          <label>Notes</label>
          <textarea name="notes" id="edit_notes"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-warning" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          Update Material
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ ADJUST STOCK MODAL ════════════════════════════════════════════════ -->
<div class="modal-bg" id="adjModal">
  <div class="modal">
    <div class="modal-top modal-top-green"></div>
    <div class="modal-header">
      <div class="modal-title" id="adj-modal-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Adjust Stock
      </div>
      <button class="modal-close" onclick="closeModal('adjModal')">✕</button>
    </div>

    <div class="adj-tabs">
      <button class="adj-tab active-add" id="tab-add" onclick="setAdjAction('add')">➕ Add Stock</button>
      <button class="adj-tab" id="tab-remove" onclick="setAdjAction('remove')">➖ Remove Stock</button>
    </div>

    <div class="adj-cur-stock" id="adj-cur-label">Current stock: —</div>

    <form id="adj-form">
      <input type="hidden" name="action" value="adjust_stock">
      <input type="hidden" name="material_id" id="adj_material_id">
      <input type="hidden" name="adj_action" id="adj_action_input" value="add">

      <div class="form-grid col1">
        <div class="fg">
          <label id="adj-qty-label">Quantity to Add</label>
          <input type="number" name="adj_qty" id="adj_qty_input" required min="0.01" step="0.01"
            style="text-align:center;font-size:26px;font-weight:900;font-family:'Barlow Condensed',sans-serif;padding:14px"
            placeholder="0">
        </div>
        <div class="fg">
          <label>Reason / Note <span style="color:var(--text2);font-weight:400;text-transform:none">(optional)</span></label>
          <input type="text" name="adj_reason" placeholder="e.g. New delivery, Used in production…">
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-warning" onclick="closeModal('adjModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="adj-submit-btn">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          Apply
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ DELETE CONFIRM MODAL ══════════════════════════════════════════════ -->
<div class="modal-bg" id="deleteModal">
  <div class="modal modal-del">
    <div class="modal-top modal-top-red"></div>
    <div class="modal-header">
      <div class="modal-title" style="color:#dc2626">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
        Delete Material
      </div>
      <button class="modal-close" onclick="closeModal('deleteModal')">✕</button>
    </div>

    <div class="del-warn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      <div>
        You are about to permanently delete <strong id="del-mat-name">this material</strong> and all its transaction history. <br>
        <span style="font-size:11px;margin-top:4px;display:block;opacity:.8">This action cannot be undone.</span>
      </div>
    </div>

    <form id="del-form">
      <input type="hidden" name="action" value="delete_material">
      <input type="hidden" name="material_id" id="del_material_id">
      <div class="modal-footer">
        <button type="button" class="btn btn-warning" onclick="closeModal('deleteModal')">Cancel</button>
        <button type="submit" class="btn btn-danger" style="background:#dc2626;color:#fff;border:none">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
          Yes, Delete
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ IMAGE MANAGE MODAL ════════════════════════════════════════════════ -->
<div class="modal-bg" id="imgModal">
  <div class="modal" style="width:460px">
    <div class="modal-top" id="img-modal-top" style="background:linear-gradient(90deg,var(--blue),var(--blue-light))"></div>
    <div class="modal-header">
      <div class="modal-title" style="font-size:16px" id="img-modal-title">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2.5"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        Material Photo
      </div>
      <button class="modal-close" onclick="closeModal('imgModal')">✕</button>
    </div>

    <!-- Current image display -->
    <div id="img-current-wrap" style="display:none;margin-bottom:14px;text-align:center">
      <img id="img-current-preview" src="" alt="" class="img-preview-big">
      <form id="img-del-form" style="display:inline">
        <input type="hidden" name="action" value="delete_image">
        <input type="hidden" name="material_id" id="img-del-material-id">
        <button type="button" id="img-del-btn" class="btn btn-danger btn-sm" style="margin-top:4px">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
          Remove Photo
        </button>
      </form>
    </div>

    <!-- Upload form -->
    <form id="img-upload-form" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_image">
      <input type="hidden" name="material_id" id="img-upload-material-id">

      <div class="img-drop-zone" id="img-drop-zone" onclick="document.getElementById('img-file-input').click()">
        <div class="img-drop-icon">📷</div>
        <div class="img-drop-text">Click to choose a photo</div>
        <div class="img-drop-hint">JPG, PNG, WEBP — max 5 MB</div>
        <input type="file" name="material_image" id="img-file-input" accept="image/*">
        <img id="img-upload-preview" class="img-preview-thumb" src="" alt="Preview">
      </div>

      <div class="modal-footer" style="margin-top:14px">
        <button type="button" class="btn btn-warning" onclick="closeModal('imgModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="img-upload-btn" disabled>
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
          Upload Photo
        </button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<script>
// ── Modal helpers ────────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-bg').forEach(function(bg) {
  bg.addEventListener('click', function(e) {
    if (e.target === bg) bg.classList.remove('open');
  });
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') document.querySelectorAll('.modal-bg.open').forEach(function(m){ m.classList.remove('open'); });
});

function openAddModal() { openModal('addModal'); }

function openEditModal(mat) {
  document.getElementById('edit_material_id').value  = mat.id;
  document.getElementById('edit_name').value          = mat.material_name;
  document.getElementById('edit_min_stock').value     = mat.minimum_stock;
  document.getElementById('edit_unit_cost').value     = mat.unit_cost;
  document.getElementById('edit_supplier').value      = mat.supplier || '';
  document.getElementById('edit_notes').value         = mat.notes || '';
  var catSel  = document.getElementById('edit_category');
  var unitSel = document.getElementById('edit_unit');
  for (var i=0; i<catSel.options.length; i++) {
    if (catSel.options[i].value === mat.category) { catSel.selectedIndex = i; break; }
  }
  for (var i=0; i<unitSel.options.length; i++) {
    if (unitSel.options[i].value === mat.unit) { unitSel.selectedIndex = i; break; }
  }
  openModal('editModal');
}

var adjAction = 'add';
function openAdjustModal(id, name, curQty, unit) {
  document.getElementById('adj_material_id').value = id;
  document.getElementById('adj-modal-title').innerHTML =
    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> '
    + escHtml(name);
  document.getElementById('adj-cur-label').textContent =
    'Current stock: ' + Number(curQty).toLocaleString(undefined, {minimumFractionDigits:2}) + ' ' + unit;
  document.getElementById('adj_qty_input').value = '';
  setAdjAction('add');
  openModal('adjModal');
}

function setAdjAction(action) {
  adjAction = action;
  document.getElementById('adj_action_input').value = action;
  var tabAdd    = document.getElementById('tab-add');
  var tabRemove = document.getElementById('tab-remove');
  var topDiv    = document.querySelector('#adjModal .modal-top');
  var submitBtn = document.getElementById('adj-submit-btn');
  var label     = document.getElementById('adj-qty-label');
  tabAdd.className    = 'adj-tab' + (action==='add' ? ' active-add' : '');
  tabRemove.className = 'adj-tab' + (action==='remove' ? ' active-remove' : '');
  if (action === 'add') {
    topDiv.className    = 'modal-top modal-top-green';
    submitBtn.style.background = '#16a34a';
    label.textContent   = 'Quantity to Add';
  } else {
    topDiv.className    = 'modal-top modal-top-red';
    submitBtn.style.background = '#dc2626';
    label.textContent   = 'Quantity to Remove';
  }
}

function openDeleteModal(id, name) {
  document.getElementById('del_material_id').value = id;
  document.getElementById('del-mat-name').textContent = '"' + name + '"';
  openModal('deleteModal');
}

function openImgModal(id, name, imgUrl) {
  document.getElementById('img-upload-material-id').value = id;
  document.getElementById('img-del-material-id').value    = id;
  document.getElementById('img-modal-title').innerHTML =
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2.5"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg> '
    + escHtml(name);
  document.getElementById('img-file-input').value = '';
  var thumb = document.getElementById('img-upload-preview');
  thumb.style.display = 'none'; thumb.src = '';
  document.getElementById('img-upload-btn').disabled = true;
  var curWrap = document.getElementById('img-current-wrap');
  var curPrev = document.getElementById('img-current-preview');
  if (imgUrl) {
    curPrev.src = imgUrl;
    curWrap.style.display = 'block';
  } else {
    curWrap.style.display = 'none';
  }
  openModal('imgModal');
}

// ── Flash message (for delete / adjust / errors) ─────────────────────────────
function showFlash(msg) {
  var old = document.getElementById('flashMsg');
  if (old) old.remove();
  var isOk = msg.indexOf('✔') !== -1;
  var el = document.createElement('div');
  el.id = 'flashMsg';
  el.className = 'flash ' + (isOk ? 'flash-success' : 'flash-error');
  el.innerHTML = (isOk
    ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>'
    : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>')
    + escHtml(msg);
  var main = document.querySelector('.main');
  main.insertBefore(el, main.firstChild);
  setTimeout(function(){ if (el.parentNode) el.remove(); }, 3000);
}

// ── Toast notification (for Add Material & Edit Material saves) ───────────────
function showToast(msg, isOk, title) {
  // Remove any existing toast
  var oldOverlay = document.getElementById('invToastOverlay');
  var oldToast   = document.getElementById('invToastBox');
  if (oldOverlay) oldOverlay.remove();
  if (oldToast)   oldToast.remove();

  title = title || (isOk ? 'Na-save na!' : 'May Mali!');

  var overlay = document.createElement('div');
  overlay.className = 'inv-toast-overlay';
  overlay.id = 'invToastOverlay';

  var toast = document.createElement('div');
  toast.id = 'invToastBox';
  toast.className = 'inv-toast ' + (isOk ? 'inv-toast-success' : 'inv-toast-error');
  toast.innerHTML =
    '<div class="inv-toast-icon-wrap">' + (isOk ? '✓' : '✕') + '</div>'
    + '<div class="inv-toast-title">' + escHtml(title) + '</div>'
    + '<div class="inv-toast-sub">' + escHtml(msg) + '</div>';

  document.body.appendChild(overlay);
  document.body.appendChild(toast);

  function dismissToast() {
    toast.style.animation   = 'invToastOut .3s ease forwards';
    overlay.style.animation = 'invToastOut .3s ease forwards';
    setTimeout(function(){
      if (toast.parentNode)   toast.remove();
      if (overlay.parentNode) overlay.remove();
    }, 320);
  }

  // Auto-dismiss after 2.8s
  var autoTimer = setTimeout(dismissToast, 1500);

  // Click to dismiss early
  overlay.addEventListener('click', function(){ clearTimeout(autoTimer); dismissToast(); });
  toast.addEventListener('click',   function(){ clearTimeout(autoTimer); dismissToast(); });
}

// ── Update stat cards ─────────────────────────────────────────────────────────
function updateStats(s) {
  if (!s) return;
  document.getElementById('stat-total').textContent   = s.total;
  document.getElementById('stat-instock').textContent = s.in_stock;
  document.getElementById('stat-low').textContent     = s.low;
  document.getElementById('stat-out').textContent     = s.out;
}

// ── Build a single table row HTML from a material object ─────────────────────
function buildMatRow(mat, idx, canManage) {
  var qty = parseFloat(mat.quantity_in_stock) || 0;
  var min = parseFloat(mat.minimum_stock)    || 0;
  var statusBadge, statusLabel;
  if (qty === 0)               { statusBadge = 'badge-out'; statusLabel = '🚫 Out of Stock'; }
  else if (min>0 && qty<=min)  { statusBadge = 'badge-low'; statusLabel = '⚠️ Low Stock'; }
  else                         { statusBadge = 'badge-ok';  statusLabel = '✅ In Stock'; }
  var ceiling   = Math.max(qty, min*2, 1);
  var pct       = qty > 0 ? Math.min(100, Math.round((qty/ceiling)*100)) : 0;
  var barColor  = qty===0 ? '#dc2626' : (min>0&&qty<=min ? '#f59e0b' : '#22c55e');
  var imgFile   = mat.image_filename || '';
  var imgUrl    = imgFile ? 'uploads/inventory/' + imgFile : '';
  var nameEsc   = escHtml(mat.material_name);
  var nameSafe  = escAttr(mat.material_name);
  var matJson   = escAttr(JSON.stringify(mat));

  var imgCell = '';
  if (canManage) {
    imgCell = '<div class="mat-img-wrap" onclick="openImgModal('+mat.id+',\''+nameSafe+'\',\''+imgUrl+'\')" title="Click to manage photo">'
      + (imgUrl ? '<img src="'+imgUrl+'" alt="'+nameEsc+'">' : '<span class="mat-img-placeholder">👟</span>')
      + '<div class="img-overlay"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>'+(imgUrl?'Change':'Upload')+'</div>'
      + '</div>';
  } else {
    imgCell = '<div class="mat-img-wrap" style="cursor:default">'
      + (imgUrl ? '<img src="'+imgUrl+'" alt="'+nameEsc+'">' : '<span class="mat-img-placeholder">👟</span>')
      + '</div>';
  }

  var notesHtml = mat.notes
    ? '<div style="font-size:11px;color:var(--text2);margin-top:2px">'+escHtml(mat.notes.substring(0,60))+(mat.notes.length>60?'…':'')+'</div>'
    : '';

  var qtyFmt = Number(qty).toLocaleString(undefined, {maximumFractionDigits:0});
  var minFmt = min > 0 ? Number(min).toLocaleString(undefined, {maximumFractionDigits:0}) : '<span style="color:#c8d5f0">—</span>';
  var costFmt = parseFloat(mat.unit_cost) > 0
    ? '₱'+Number(parseFloat(mat.unit_cost)).toLocaleString(undefined, {minimumFractionDigits:2,maximumFractionDigits:2})
    : '<span style="color:#c8d5f0;font-size:11px">N/A</span>';

  var actionsHtml = '';
  if (canManage) {
    actionsHtml = '<td><div style="display:flex;gap:5px;align-items:center;justify-content:center">'
      + '<button class="btn btn-sm" style="background:#ede9fe;color:#5b21b6;border:1px solid #ddd6fe;padding:6px 8px" onclick="openImgModal('+mat.id+',\''+nameSafe+'\',\''+imgUrl+'\')" title="Manage photo"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg></button>'
      + '<button class="btn btn-success btn-sm" style="padding:6px 8px" onclick="openAdjustModal('+mat.id+',\''+nameSafe+'\','+qty+',\''+escAttr(mat.unit)+'\')" title="Adjust stock"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></button>'
      + '<button class="btn btn-warning btn-sm" style="padding:6px 8px" onclick=\'openEditModal('+JSON.stringify(mat)+')\' title="Edit material"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>'
      + '<button class="btn btn-danger btn-sm" style="padding:6px 8px" onclick="openDeleteModal('+mat.id+',\''+nameSafe+'\')" title="Delete material"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>'
      + '</div></td>';
  }

  return '<tr data-mat-id="'+mat.id+'">'
    + '<td style="color:var(--text2);font-size:12px">'+(idx+1)+'</td>'
    + '<td>'+imgCell+'</td>'
    + '<td><div style="font-weight:700;color:var(--blue-dark)">'+nameEsc+'</div>'+notesHtml+'</td>'
    + '<td><span class="badge badge-cat">'+escHtml(mat.category)+'</span></td>'
    + '<td style="color:var(--text2);font-size:12px;font-weight:600">'+escHtml(mat.unit)+'</td>'
    + '<td style="text-align:center"><div class="stock-bar-wrap" style="justify-content:center"><strong style="font-family:\'Barlow Condensed\',sans-serif;font-size:18px;color:var(--blue-dark);min-width:36px">'+qtyFmt+'</strong><div class="stock-bar"><div class="stock-bar-fill" style="width:'+pct+'%;background:'+barColor+'"></div></div></div></td>'
    + '<td style="font-size:12px;color:var(--text2);font-weight:600;text-align:center">'+minFmt+'</td>'
    + '<td><span class="badge '+statusBadge+'">'+statusLabel+'</span></td>'
    + '<td style="font-size:13px;font-weight:700;color:var(--blue-dark);text-align:center">'+costFmt+'</td>'
    + '<td style="font-size:12px;color:var(--text2)">'+(mat.supplier ? escHtml(mat.supplier) : '<span style="color:#c8d5f0">—</span>')+'</td>'
    + actionsHtml
    + '</tr>';
}

// ── Build a transaction row from tx object ────────────────────────────────────
function buildTxRow(tx) {
  var d  = new Date(tx.entered_at.replace(' ','T'));
  var mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
  var dd = String(d.getDate()).padStart(2,'0');
  var yy = d.getFullYear();
  var hr = d.getHours(); var mi = String(d.getMinutes()).padStart(2,'0');
  var ap = hr>=12?'PM':'AM'; hr = hr%12||12;
  var dateStr = mo+' '+dd+', '+yy;
  var timeStr = hr+':'+mi+' '+ap;

  var actionBadge = tx.action==='add'
    ? '<span class="badge badge-ok">➕ Added</span>'
    : tx.action==='remove'
      ? '<span class="badge badge-out">➖ Removed</span>'
      : '<span class="badge badge-blue">🔄 Adjustment</span>';
  var sign  = tx.action==='add' ? '+' : '-';
  var color = tx.action==='add' ? '#15803d' : '#dc2626';
  var qtyBefore = Number(tx.qty_before).toFixed(2);
  var qtyAfter  = Number(tx.qty_after).toFixed(2);
  var qty       = Number(tx.quantity).toFixed(2);

  return '<tr>'
    + '<td><div style="font-weight:700;font-size:12px">'+dateStr+'</div><div style="font-size:11px;color:var(--text2)">'+timeStr+'</div></td>'
    + '<td><span style="font-weight:700;color:var(--blue-dark)">'+escHtml(tx.material_name)+'</span><span style="font-size:11px;color:var(--text2);margin-left:4px">('+escHtml(tx.unit)+')</span></td>'
    + '<td>'+actionBadge+'</td>'
    + '<td><span style="font-family:\'Barlow Condensed\',sans-serif;font-size:18px;font-weight:900;color:'+color+'">'+sign+qty+'</span></td>'
    + '<td style="font-size:12px;font-weight:600;color:var(--text2)">'+qtyBefore+'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin:0 3px"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg><strong style="color:var(--blue-dark)">'+qtyAfter+'</strong></td>'
    + '<td style="font-size:12px;color:var(--text2)">'+(tx.reason ? escHtml(tx.reason) : '<span style="color:#c8d5f0">—</span>')+'</td>'
    + '<td style="font-size:12px;font-weight:700;color:var(--blue-dark)">'+escHtml(tx.full_name)+'</td>'
    + '</tr>';
}

// ── Refresh transactions table ────────────────────────────────────────────────
function refreshTxTable(txList) {
  if (!txList) return;
  var tbody = document.getElementById('tx-tbody');
  if (!tbody) return;
  tbody.innerHTML = txList.map(buildTxRow).join('');
}

// ── Rebuild full tbody with category group headers ────────────────────────────
function rebuildTable(materials) {
  var tbody = document.getElementById('inv-tbody');
  if (!tbody) return;
  if (!materials || materials.length === 0) {
    tbody.innerHTML = '<tr><td colspan="11" class="empty-state" style="padding:40px 20px;text-align:center;color:var(--text2);font-size:14px;font-weight:600"><div style="font-size:40px;opacity:.5;margin-bottom:10px">📦</div><div>No materials found.</div></td></tr>';
    var pc = document.getElementById('panel-count');
    if (pc) pc.textContent = 0;
    return;
  }
  var html = '';
  var lastCat = null;
  var colSpan = _canManage ? 11 : 10;
  materials.forEach(function(mat, idx) {
    if (mat.category !== lastCat) {
      lastCat = mat.category;
      html += '<tr class="cat-group-row" data-cat="'+escAttr(mat.category)+'" onclick="toggleCategory(this)"><td colspan="'+colSpan+'">'
        + '<span class="cat-group-label"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>'
        + escHtml(mat.category)
        + '<span class="cat-chevron"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg></span>'
        + '</span></td></tr>';
    }
    html += buildMatRow(mat, idx, _canManage);
  });
  tbody.innerHTML = html;
  var pc = document.getElementById('panel-count');
  if (pc) pc.textContent = materials.length;
  reapplyCollapsed();
}

// ── Generic AJAX form submit ──────────────────────────────────────────────────
var _canManage = <?= json_encode((bool)$canManage) ?>;

function ajaxForm(formEl, onSuccess) {
  var fd  = new FormData(formEl);
  var btn = formEl.querySelector('[type=submit]');
  if (btn) { btn.disabled = true; btn.style.opacity = '.6'; }

  fetch('inventory_api.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (btn) { btn.disabled = false; btn.style.opacity = ''; }
      showFlash(data.msg || (data.ok ? '✔ Done.' : '✖ Error.'));
      if (data.ok && onSuccess) onSuccess(data);
    })
    .catch(function(){
      if (btn) { btn.disabled = false; btn.style.opacity = ''; }
      showFlash('✖ Network error. Please try again.');
    });
}

// ── ADD MATERIAL ──────────────────────────────────────────────────────────────
document.getElementById('add-form').addEventListener('submit', function(e) {
  e.preventDefault();
  var fd  = new FormData(this);
  var btn = this.querySelector('[type=submit]');
  if (btn) { btn.disabled = true; btn.style.opacity = '.6'; }
  var formEl = this;

  fetch('inventory_api.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (btn) { btn.disabled = false; btn.style.opacity = ''; }
      var isOk = data.ok;
      var matName = fd.get('material_name') || '';
      var toastTitle = isOk ? 'Na-save na!' : 'May Mali!';
      var toastMsg   = data.msg || (isOk ? '✔ Material added.' : '✖ Error.');
      showToast(toastMsg, isOk, toastTitle);
      if (isOk) {
        closeModal('addModal');
        formEl.reset();
        updateStats(data.stats);
        rebuildTable(data.all_materials || null);
        if (!data.all_materials) {
          var tbody = document.getElementById('inv-tbody');
          if (tbody && data.material) {
            var idx = tbody.querySelectorAll('tr[data-mat-id]').length;
            tbody.insertAdjacentHTML('beforeend', buildMatRow(data.material, idx, _canManage));
            var pc = document.getElementById('panel-count');
            if (pc) pc.textContent = tbody.querySelectorAll('tr[data-mat-id]').length;
          }
        }
        refreshTxTable(data.recent_tx);
      }
    })
    .catch(function(){
      if (btn) { btn.disabled = false; btn.style.opacity = ''; }
      showToast('✖ Network error. Please try again.', false, 'May Mali!');
    });
});

// ── EDIT MATERIAL ─────────────────────────────────────────────────────────────
document.getElementById('edit-form').addEventListener('submit', function(e) {
  e.preventDefault();
  var fd  = new FormData(this);
  var btn = this.querySelector('[type=submit]');
  if (btn) { btn.disabled = true; btn.style.opacity = '.6'; }

  fetch('inventory_api.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (btn) { btn.disabled = false; btn.style.opacity = ''; }
      var isOk = data.ok;
      var toastTitle = isOk ? 'Na-update na!' : 'May Mali!';
      var toastMsg   = data.msg || (isOk ? '✔ Material updated.' : '✖ Error.');
      showToast(toastMsg, isOk, toastTitle);
      if (isOk) {
        closeModal('editModal');
        updateStats(data.stats);
        var mat = data.material;
        var tbody = document.getElementById('inv-tbody');
        var existing = tbody ? tbody.querySelector('tr[data-mat-id="'+mat.id+'"]') : null;
        if (existing) {
          var idx = Array.from(tbody.querySelectorAll('tr')).indexOf(existing);
          existing.outerHTML = buildMatRow(mat, idx, _canManage);
        }
      }
    })
    .catch(function(){
      if (btn) { btn.disabled = false; btn.style.opacity = ''; }
      showToast('✖ Network error. Please try again.', false, 'May Mali!');
    });
});

// ── ADJUST STOCK ──────────────────────────────────────────────────────────────
document.getElementById('adj-form').addEventListener('submit', function(e) {
  e.preventDefault();
  ajaxForm(this, function(data) {
    closeModal('adjModal');
    updateStats(data.stats);
    var mat = data.material;
    var tbody = document.getElementById('inv-tbody');
    var existing = tbody ? tbody.querySelector('tr[data-mat-id="'+mat.id+'"]') : null;
    if (existing) {
      var idx = Array.from(tbody.querySelectorAll('tr')).indexOf(existing);
      existing.outerHTML = buildMatRow(mat, idx, _canManage);
    }
    refreshTxTable(data.recent_tx);
  });
});

// ── DELETE MATERIAL ───────────────────────────────────────────────────────────
document.getElementById('del-form').addEventListener('submit', function(e) {
  e.preventDefault();
  ajaxForm(this, function(data) {
    closeModal('deleteModal');
    updateStats(data.stats);
    var tbody = document.getElementById('inv-tbody');
    if (tbody) {
      var row = tbody.querySelector('tr[data-mat-id="'+data.deleted_id+'"]');
      if (row) {
        // If this was the only item in its category group, also remove the group header
        var prev = row.previousElementSibling;
        var next = row.nextElementSibling;
        var prevIsCatRow = prev && prev.classList.contains('cat-group-row');
        var nextIsCatRow = !next || next.classList.contains('cat-group-row');
        if (prevIsCatRow && nextIsCatRow) prev.remove();
        row.remove();
      }
      // Re-number only data rows (skip cat-group-rows)
      var dataRows = tbody.querySelectorAll('tr[data-mat-id]');
      dataRows.forEach(function(r, i){
        var first = r.querySelector('td:first-child');
        if (first) first.textContent = i+1;
      });
      var pc = document.getElementById('panel-count');
      if (pc) pc.textContent = dataRows.length;
    }
    refreshTxTable(data.recent_tx);
  });
});

// ── DELETE IMAGE ──────────────────────────────────────────────────────────────
document.getElementById('img-del-btn').addEventListener('click', function() {
  if (!confirm('Remove this photo?')) return;
  var fd = new FormData();
  fd.append('action', 'delete_image');
  fd.append('material_id', document.getElementById('img-del-material-id').value);

  fetch('inventory_api.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
      showFlash(data.msg || (data.ok ? '✔ Done.' : '✖ Error.'));
      if (data.ok) {
        var mat = data.material;
        // Hide current image wrap in modal
        document.getElementById('img-current-wrap').style.display = 'none';
        document.getElementById('img-current-preview').src = '';
        // Update the thumbnail in the table
        updateImgInRow(mat);
        closeModal('imgModal');
      }
    });
});

// ── UPLOAD IMAGE ──────────────────────────────────────────────────────────────
document.getElementById('img-upload-form').addEventListener('submit', function(e) {
  e.preventDefault();
  ajaxForm(this, function(data) {
    closeModal('imgModal');
    var mat = data.material;
    var tbody = document.getElementById('inv-tbody');
    var existing = tbody ? tbody.querySelector('tr[data-mat-id="'+mat.id+'"]') : null;
    if (existing) {
      var idx = Array.from(tbody.querySelectorAll('tr')).indexOf(existing);
      existing.outerHTML = buildMatRow(mat, idx, _canManage);
    }
  });
});

function updateImgInRow(mat) {
  var tbody = document.getElementById('inv-tbody');
  var existing = tbody ? tbody.querySelector('tr[data-mat-id="'+mat.id+'"]') : null;
  if (existing) {
    var idx = Array.from(tbody.querySelectorAll('tr')).indexOf(existing);
    existing.outerHTML = buildMatRow(mat, idx, _canManage);
  }
}

// ── Image file input preview ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  var fileInput = document.getElementById('img-file-input');
  if (!fileInput) return;
  fileInput.addEventListener('change', function() {
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e) {
      var thumb = document.getElementById('img-upload-preview');
      thumb.src = e.target.result;
      thumb.style.display = 'block';
      document.getElementById('img-upload-btn').disabled = false;
      var dropZone = document.getElementById('img-drop-zone');
      dropZone.style.borderColor = 'var(--blue-light)';
      dropZone.style.background  = '#eff4ff';
    };
    reader.readAsDataURL(file);
  });
  var dropZone = document.getElementById('img-drop-zone');
  if (!dropZone) return;
  dropZone.addEventListener('dragover',  function(e){ e.preventDefault(); this.classList.add('drag-over'); });
  dropZone.addEventListener('dragleave', function()  { this.classList.remove('drag-over'); });
  dropZone.addEventListener('drop', function(e) {
    e.preventDefault(); this.classList.remove('drag-over');
    var file = e.dataTransfer.files[0];
    if (!file || !file.type.startsWith('image/')) return;
    var dt = new DataTransfer();
    dt.items.add(file);
    document.getElementById('img-file-input').files = dt.files;
    document.getElementById('img-file-input').dispatchEvent(new Event('change'));
  });
});

// ── Search debounce (existing) ────────────────────────────────────────────────
(function(){
  var input = document.getElementById('filter-search');
  var form  = document.getElementById('filter-form');
  var timer = null;
  if (!input || !form) return;
  input.focus();
  var len = input.value.length;
  input.setSelectionRange(len, len);
  input.addEventListener('input', function(){
    clearTimeout(timer);
    timer = setTimeout(function(){ form.submit(); }, 500);
  });
})();

// ── Collapsible category rows (smooth animation) ──────────────────────────────
var _collapsedCats = {};

function getCatRows(headerRow) {
  var rows = [];
  var sibling = headerRow.nextElementSibling;
  while (sibling && !sibling.classList.contains('cat-group-row')) {
    rows.push(sibling);
    sibling = sibling.nextElementSibling;
  }
  return rows;
}

function toggleCategory(headerRow) {
  var cat = headerRow.getAttribute('data-cat');
  var isCollapsed = headerRow.classList.toggle('collapsed');
  _collapsedCats[cat] = isCollapsed;
  var rows = getCatRows(headerRow);

  if (isCollapsed) {
    // Animate collapse
    rows.forEach(function(row) {
      row.classList.add('cat-row-animating');
      row.classList.remove('cat-row-collapsing');
      // Force reflow so transition fires
      void row.offsetHeight;
      row.classList.add('cat-row-collapsing');
    });
    // After transition ends, fully hide
    var last = rows[rows.length - 1];
    if (last) {
      last.addEventListener('transitionend', function handler() {
        last.removeEventListener('transitionend', handler);
        rows.forEach(function(row) {
          row.style.display = 'none';
          row.classList.remove('cat-row-animating', 'cat-row-collapsing');
        });
      });
    }
  } else {
    // Show then animate expand
    rows.forEach(function(row) {
      row.style.display = '';
      row.classList.add('cat-row-animating', 'cat-row-collapsing');
    });
    // Force reflow then remove collapsing to trigger expand
    rows.forEach(function(row) { void row.offsetHeight; });
    rows.forEach(function(row) { row.classList.remove('cat-row-collapsing'); });
    var last2 = rows[rows.length - 1];
    if (last2) {
      last2.addEventListener('transitionend', function handler2() {
        last2.removeEventListener('transitionend', handler2);
        rows.forEach(function(row) { row.classList.remove('cat-row-animating'); });
      });
    }
  }
}

// Re-apply collapsed state after rebuildTable
function reapplyCollapsed() {
  Object.keys(_collapsedCats).forEach(function(cat) {
    if (!_collapsedCats[cat]) return;
    var tbody = document.getElementById('inv-tbody');
    if (!tbody) return;
    var rows = tbody.querySelectorAll('.cat-group-row[data-cat]');
    rows.forEach(function(hdr) {
      if (hdr.getAttribute('data-cat') === cat) {
        hdr.classList.add('collapsed');
        var sib = hdr.nextElementSibling;
        while (sib && !sib.classList.contains('cat-group-row')) {
          sib.style.display = 'none';
          sib = sib.nextElementSibling;
        }
      }
    });
  });
}

function escHtml(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function escAttr(s) {
  return String(s||'').replace(/'/g,'&#39;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>