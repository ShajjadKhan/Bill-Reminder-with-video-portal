<?php
session_start();
$db = new SQLite3('bills.db');
$db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT UNIQUE, password TEXT, fullname TEXT, role TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS customers (id INTEGER PRIMARY KEY, name TEXT, mobile TEXT UNIQUE, building TEXT, apartment TEXT, room TEXT, due_day INTEGER, billing_start_date TEXT, billing_day INTEGER, custom_due_date TEXT, status TEXT DEFAULT 'active', created_by INTEGER, created_at TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS collections (id INTEGER PRIMARY KEY, customer_id INTEGER, month_year TEXT, amount REAL, collected_by INTEGER, collected_date TEXT, rescheduled BOOLEAN DEFAULT 0)");
$db->exec("CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, details TEXT, timestamp TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS files (id INTEGER PRIMARY KEY, title TEXT, description TEXT, filename TEXT, original_name TEXT, size INTEGER, mime TEXT, uploaded_by INTEGER, upload_date TEXT, downloads INTEGER DEFAULT 0)");

date_default_timezone_set('Asia/Riyadh');
function isLoggedIn() { return isset($_SESSION['user_id']); }
function isMaster() { return isset($_SESSION['role']) && $_SESSION['role'] == 'master'; }
function isAdmin() { return isset($_SESSION['role']) && $_SESSION['role'] == 'admin'; }
function logAction($db, $user_id, $action, $details) { $db->exec("INSERT INTO audit_log (user_id, action, details, timestamp) VALUES ($user_id, '$action', '$details', datetime('now'))"); }

function sendWhatsAppMessage($mobile, $message) {
    $phone = ltrim($mobile, '0');
    $chatId = "966" . $phone . "@c.us";
    $url = "http://10.12.14.16:2785/api/sessions/63bd1d8f-7ed7-45f4-8e25-821626752eaf/messages/send-text";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-Key: dev-admin-key'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['chatId' => $chatId, 'text' => $message]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

if (isset($_GET['logout'])) { session_destroy(); header('Location: portal.php'); exit; }
if (isset($_POST['action']) && $_POST['action'] == 'login') {
    $user = $db->querySingle("SELECT * FROM users WHERE username = '" . SQLite3::escapeString($_POST['username']) . "'", true);
    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id']; $_SESSION['role'] = $user['role']; $_SESSION['fullname'] = $user['fullname']; $_SESSION['username'] = $user['username'];
        header('Location: portal.php'); exit;
    } else { $error = "Invalid credentials"; }
}

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$filter_user = isset($_GET['filter_user']) ? intval($_GET['filter_user']) : 0;

if (isLoggedIn() && isMaster() && isset($_POST['action']) && $_POST['action'] == 'save_admin') {
    $fullname = SQLite3::escapeString($_POST['fullname']);
    $username = SQLite3::escapeString($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $db->exec("INSERT INTO users (username, password, fullname, role) VALUES ('$username', '$password', '$fullname', 'admin')");
    logAction($db, $_SESSION['user_id'], "ADD_ADMIN", "Added admin $username");
    $_SESSION['msg'] = "Admin added";
    header('Location: portal.php?page=admins');
    exit;
}

if (isLoggedIn() && isMaster() && isset($_GET['delete_admin'])) {
    $id = intval($_GET['delete_admin']);
    $admin = $db->querySingle("SELECT username FROM users WHERE id=$id AND role='admin'", true);
    if ($admin) {
        $db->exec("DELETE FROM users WHERE id=$id");
        logAction($db, $_SESSION['user_id'], "DELETE_ADMIN", "Deleted admin {$admin['username']}");
    }
    header('Location: portal.php?page=admins');
    exit;
}

if (isLoggedIn() && isMaster() && isset($_POST['action']) && $_POST['action'] == 'edit_master') {
    $id = intval($_POST['master_id']);
    $fullname = SQLite3::escapeString($_POST['fullname']);
    $username = SQLite3::escapeString($_POST['username']);
    $new_password = $_POST['new_password'];
    if (!empty($new_password)) {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $db->exec("UPDATE users SET fullname='$fullname', username='$username', password='$hashed' WHERE id=$id");
    } else {
        $db->exec("UPDATE users SET fullname='$fullname', username='$username' WHERE id=$id");
    }
    logAction($db, $_SESSION['user_id'], "EDIT_MASTER", "Updated master account");
    $_SESSION['msg'] = "Master updated. Please login again.";
    session_destroy();
    header('Location: portal.php');
    exit;
}

if (isLoggedIn() && isMaster() && isset($_POST['action']) && $_POST['action'] == 'reset_password') {
    $id = intval($_POST['user_id']);
    $new_pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    $db->exec("UPDATE users SET password='$new_pass' WHERE id=$id");
    logAction($db, $_SESSION['user_id'], "RESET_PASSWORD", "Reset password for user ID $id");
    $_SESSION['msg'] = "Password reset";
    header('Location: portal.php?page=admins');
    exit;
}

if (isLoggedIn() && (isMaster() || isAdmin()) && isset($_POST['action']) && $_POST['action'] == 'add_collection_partial') {
    $customer_id = intval($_POST['customer_id']);
    $months = $_POST['months'] ?? [];
    $amounts = $_POST['amounts'] ?? [];
    $customer = $db->querySingle("SELECT name, mobile, billing_start_date FROM customers WHERE id=$customer_id", true);
    
    $whatsapp_msg = "[PAYMENT RECEIPT]\n\n";
    $whatsapp_msg .= "Customer: {$customer['name']}\n";
    $whatsapp_msg .= "Date: " . date("Y-m-d H:i:s") . "\n";
    $whatsapp_msg .= "Collected by: {$_SESSION['fullname']}\n\n";
    $whatsapp_msg .= "Payment Details:\n";
    $total_paid = 0;
    
    foreach ($months as $index => $month) {
        $amount = floatval($amounts[$index]);
        if ($amount > 0) {
            $db->exec("INSERT INTO collections (customer_id, month_year, amount, collected_by, collected_date) 
                       VALUES ($customer_id, '$month', $amount, {$_SESSION['user_id']}, datetime('now'))");
            logAction($db, $_SESSION['user_id'], "COLLECTION", "Collected $amount SAR from {$customer['name']} for month: $month");
            $whatsapp_msg .= "- PAID: " . date('F Y', strtotime($month)) . ": $amount SAR\n";
            $total_paid += $amount;
        } elseif ($amount == 0) {
            $db->exec("INSERT INTO collections (customer_id, month_year, amount, collected_by, collected_date) 
                       VALUES ($customer_id, '$month', 0, {$_SESSION['user_id']}, datetime('now'))");
            logAction($db, $_SESSION['user_id'], "WAIVE_MONTH", "Waived $month for {$customer['name']}");
            $whatsapp_msg .= "- WAIVED: " . date('F Y', strtotime($month)) . ": 0 SAR\n";
        }
    }
    
    $whatsapp_msg .= "\nTotal paid today: $total_paid SAR\n\n";
    $whatsapp_msg .= "Remaining Balance:\n";
    
    $start = new DateTime($customer['billing_start_date']);
    $now = new DateTime(date('Y-m-01'));
    $period = new DatePeriod($start, new DateInterval('P1M'), $now->modify('+1 month'));
    foreach ($period as $dt) {
        $month_year = $dt->format('Y-m');
        $collected = $db->querySingle("SELECT SUM(amount) FROM collections WHERE customer_id=$customer_id AND month_year='$month_year'");
        $due = 30 - ($collected ?: 0);
        if ($due > 0) {
            $whatsapp_msg .= "- UNPAID: " . $dt->format('F Y') . ": $due SAR\n";
        }
    }
    
    $whatsapp_msg .= "\nMovies: http://10.12.14.16:8082\n\n";
    $whatsapp_msg .= "Technical Support (24/7):\n";
    $whatsapp_msg .= "Tel: Cyber Net +966594266584\n";
    $whatsapp_msg .= "Tel: Riyad Hossain +966546377863\n";
    $whatsapp_msg .= "Tel: Jahir Hossain +966542349510\n\n";
    $whatsapp_msg .= "Thank you for your payment!";
    
    sendWhatsAppMessage($customer['mobile'], $whatsapp_msg);
    
    $_SESSION['last_collection'] = [
        'name' => $customer['name'],
        'mobile' => $customer['mobile'],
        'amount' => $total_paid,
        'whatsapp_msg' => $whatsapp_msg,
        'datetime' => date('Y-m-d H:i:s'),
        'collector' => $_SESSION['fullname']
    ];
    $_SESSION['msg'] = "Payment recorded successfully. WhatsApp confirmation sent.";
    header('Location: portal.php?page=collections');
    exit;
}

if (isLoggedIn() && (isMaster() || isAdmin()) && isset($_POST['action']) && $_POST['action'] == 'save_customer') {
    $id = $_POST['id'] ?? 0;
    $name = SQLite3::escapeString($_POST['name']);
    $mobile = SQLite3::escapeString($_POST['mobile']);
    $building = SQLite3::escapeString($_POST['building']);
    $apartment = SQLite3::escapeString($_POST['apartment']);
    $room = SQLite3::escapeString($_POST['room']);
    $billing_day = intval($_POST['billing_day']);
    $billing_start_date = SQLite3::escapeString($_POST['billing_start_date']);
    $billing_start_date = date('Y-m-01', strtotime($billing_start_date));
    
    if ($id) {
        $db->exec("UPDATE customers SET name='$name', mobile='$mobile', building='$building', apartment='$apartment', room='$room', due_day=$billing_day, billing_day=$billing_day, billing_start_date='$billing_start_date' WHERE id=$id");
        logAction($db, $_SESSION['user_id'], "EDIT_CUSTOMER", "Customer ID $id updated");
    } else {
        $db->exec("INSERT INTO customers (name, mobile, building, apartment, room, due_day, billing_day, billing_start_date, created_by, created_at) VALUES ('$name', '$mobile', '$building', '$apartment', '$room', $billing_day, $billing_day, '$billing_start_date', {$_SESSION['user_id']}, datetime('now'))");
        logAction($db, $_SESSION['user_id'], "ADD_CUSTOMER", "Added $name");
    }
    header('Location: portal.php?page=customers');
    exit;
}

if (isLoggedIn() && (isMaster() || isAdmin()) && isset($_GET['delete_customer'])) {
    $id = intval($_GET['delete_customer']);
    $cust = $db->querySingle("SELECT name FROM customers WHERE id=$id", true);
    $db->exec("DELETE FROM customers WHERE id=$id");
    $db->exec("DELETE FROM collections WHERE customer_id=$id");
    logAction($db, $_SESSION['user_id'], "DELETE_CUSTOMER", "Deleted customer {$cust['name']}");
    header('Location: portal.php?page=customers');
    exit;
}

if (isLoggedIn() && isMaster() && isset($_POST['action']) && $_POST['action'] == 'edit_collection') {
    $id = intval($_POST['collection_id']);
    $new_amount = floatval($_POST['amount']);
    $old = $db->querySingle("SELECT amount FROM collections WHERE id=$id", true);
    $db->exec("UPDATE collections SET amount=$new_amount WHERE id=$id");
    logAction($db, $_SESSION['user_id'], "EDIT_COLLECTION", "Changed collection ID $id from {$old['amount']} to $new_amount");
    $_SESSION['msg'] = "Collection updated";
    header('Location: portal.php?page=report');
    exit;
}

if (isLoggedIn() && isMaster() && isset($_GET['delete_collection'])) {
    $id = intval($_GET['delete_collection']);
    $db->exec("DELETE FROM collections WHERE id=$id");
    logAction($db, $_SESSION['user_id'], "DELETE_COLLECTION", "Deleted collection ID $id");
    $_SESSION['msg'] = "Collection deleted";
    header('Location: portal.php?page=report');
    exit;
}

if (isset($_FILES['upload_file']) && isLoggedIn()) {
    $upload_dir = '/mnt/bigstorage/files/';
    $title = SQLite3::escapeString($_POST['title']);
    $description = SQLite3::escapeString($_POST['description']);
    $file = $_FILES['upload_file'];
    $original_name = $file['name'];
    $size = $file['size'];
    $timestamp = time();
    $new_filename = $timestamp . '_' . basename($original_name);
    
    if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_filename)) {
        $db->exec("INSERT INTO files (title, description, filename, original_name, size, uploaded_by, upload_date) 
                   VALUES ('$title', '$description', '$new_filename', '$original_name', $size, {$_SESSION['user_id']}, datetime('now'))");
        logAction($db, $_SESSION['user_id'], "UPLOAD_FILE", "Uploaded $original_name");
        $_SESSION['msg'] = "File uploaded";
    }
    header('Location: portal.php?page=files');
    exit;
}

if (isset($_GET['delete_file']) && isMaster()) {
    $id = intval($_GET['delete_file']);
    $file = $db->querySingle("SELECT filename, original_name FROM files WHERE id=$id", true);
    if ($file) {
        unlink('/mnt/bigstorage/files/' . $file['filename']);
        $db->exec("DELETE FROM files WHERE id=$id");
        logAction($db, $_SESSION['user_id'], "DELETE_FILE", "Deleted {$file['original_name']}");
    }
    header('Location: portal.php?page=files');
    exit;
}

if (isset($_GET['download'])) {
    $id = intval($_GET['download']);
    $file = $db->querySingle("SELECT filename, original_name FROM files WHERE id=$id", true);
    if ($file && file_exists('/mnt/bigstorage/files/' . $file['filename'])) {
        $db->exec("UPDATE files SET downloads = downloads + 1 WHERE id=$id");
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
        readfile('/mnt/bigstorage/files/' . $file['filename']);
        exit;
    }
}

$current_month = date('Y-m');
$display_month = isset($_GET['stats_month']) ? $_GET['stats_month'] : $current_month;

function getUnpaidMonths($db, $customer_id, $current_month) {
    $cust = $db->querySingle("SELECT billing_start_date FROM customers WHERE id=$customer_id", true);
    if (!$cust) return [];
    $start = new DateTime($cust['billing_start_date']);
    $now = new DateTime($current_month . '-01');
    $months = [];
    $period = new DatePeriod($start, new DateInterval('P1M'), $now->modify('+1 month'));
    foreach ($period as $dt) {
        $months[] = $dt->format('Y-m');
    }
    
    $collections = $db->query("SELECT month_year, SUM(amount) as total FROM collections WHERE customer_id=$customer_id GROUP BY month_year");
    $paid_map = [];
    while ($col = $collections->fetchArray(SQLITE3_ASSOC)) {
        $paid_map[$col['month_year']] = floatval($col['total']);
    }
    
    $unpaid = [];
    foreach ($months as $month) {
        $paid = isset($paid_map[$month]) ? $paid_map[$month] : 0;
        $due = 30 - $paid;
        if ($due > 0) {
            $unpaid[] = ['month' => $month, 'due' => $due];
        }
    }
    return $unpaid;
}

$pending_customers = [];
$all_customers = $db->query("SELECT id, name, mobile, building, apartment, room, due_day, billing_start_date FROM customers WHERE status='active'");
while ($cust = $all_customers->fetchArray(SQLITE3_ASSOC)) {
    $unpaid = getUnpaidMonths($db, $cust['id'], $current_month);
    if (!empty($unpaid)) {
        $cust['unpaid'] = $unpaid;
        $cust['oldest_unpaid'] = $unpaid[0]['month'];
        $pending_customers[] = $cust;
    }
}
usort($pending_customers, function($a, $b) {
    return strcmp($a['oldest_unpaid'], $b['oldest_unpaid']);
});

$total_customers = $db->querySingle("SELECT COUNT(*) FROM customers WHERE status='active'");

$collected_count = $db->querySingle("SELECT COUNT(DISTINCT customer_id) FROM collections WHERE strftime('%Y-%m', collected_date) = '$display_month' AND amount>0");
$total_amount_collected = $db->querySingle("SELECT SUM(amount) FROM collections WHERE strftime('%Y-%m', collected_date) = '$display_month'");
$pending_count = $total_customers - $collected_count;

$customers = $db->query("SELECT * FROM customers WHERE status='active' ORDER BY name");
$files = $db->query("SELECT * FROM files ORDER BY id DESC");

if ($filter_user > 0) {
    $audit_log = $db->query("SELECT l.*, u.fullname FROM audit_log l JOIN users u ON l.user_id=u.id WHERE l.user_id=$filter_user ORDER BY l.timestamp DESC LIMIT 500");
} else {
    $audit_log = $db->query("SELECT l.*, u.fullname FROM audit_log l JOIN users u ON l.user_id=u.id ORDER BY l.timestamp DESC LIMIT 500");
}
$all_users_for_filter = $db->query("SELECT id, fullname FROM users ORDER BY fullname");

$all_collections = $db->query("SELECT c.id, c.customer_id, cust.name as customer_name, c.month_year, c.amount, c.collected_date, u.fullname as collector FROM collections c JOIN customers cust ON c.customer_id=cust.id JOIN users u ON c.collected_by=u.id ORDER BY c.collected_date DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>ISP Billing Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f7fa; transition: all 0.2s; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; width: 100%; overflow-x: hidden; }
        body.dark { background: #0f0f12; color: #eef2ff; }
        body.dark .card, body.dark .modal-content, body.dark .list-group-item { background: #1a1a2e; color: #eef2ff; border-color: #2d2d44; }
        body.dark .table { color: #eef2ff; background: #16162a; }
        body.dark .table td, body.dark .table th { border-color: #2d2d44; background: #16162a; color: #eef2ff; }
        body.dark .table-striped > tbody > tr:nth-of-type(odd) > * { background-color: #1f1f35; }
        body.dark .nav-tabs .nav-link { color: #eef2ff; }
        body.dark .nav-tabs .nav-link.active { background: #1a1a2e; border-color: #2d2d44; color: #fff; }
        body.dark .form-control, body.dark .form-select { background: #2d2d44; color: #eef2ff; border-color: #3d3d5c; }
        body.dark .form-control::placeholder { color: #a0a0c0; }
        body.dark .alert-info { background: #1e3a5f; color: #eef2ff; border-color: #2d4a6e; }
        body.dark .alert-success { background: #14532d; color: #eef2ff; }
        .container-fluid { width: 100%; padding: 0 15px; }
        @media (min-width: 1400px) { .container-fluid { max-width: 100%; } }
        .card-stats { transition: 0.3s; border-radius: 10px; cursor: pointer; }
        .table-responsive { overflow-x: auto; }
        .nav-tabs { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .nav-tabs .nav-link { white-space: nowrap; }
        .dark-mode-toggle { cursor: pointer; }
        .whatsapp-btn { background: #25D366; color: white; border: none; }
        .whatsapp-btn:hover { background: #128C7E; }
        .stats-card { border-radius: 15px; transition: transform 0.2s; }
        .stats-card:hover { transform: translateY(-5px); }
        @media (max-width: 768px) {
            .table-responsive table { font-size: 12px; }
            .btn-sm { padding: 0.2rem 0.4rem; font-size: 10px; }
            h4 { font-size: 1.2rem; }
            .display-6 { font-size: 1.5rem; }
        }
    </style>
</head>
<body>
<div class="container-fluid mt-2 mt-md-3">
<?php if (!isLoggedIn()): ?>
<div class="row justify-content-center"><div class="col-md-4"><div class="card"><div class="card-header bg-primary text-white">Login</div><div class="card-body"><?php if(isset($error)) echo "<div class='alert alert-danger'>$error</div>"; ?><form method="post"><input type="hidden" name="action" value="login"><div class="mb-3"><label>Username</label><input type="text" name="username" class="form-control" required></div><div class="mb-3"><label>Password</label><input type="password" name="password" class="form-control" required></div><button type="submit" class="btn btn-primary w-100">Login</button></form></div></div></div></div>
<?php else: ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h4 class="mb-2 mb-md-0"><i class="fas fa-wifi"></i> ISP Billing System</h4>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <i class="fas fa-moon dark-mode-toggle btn btn-secondary btn-sm" style="cursor:pointer" onclick="toggleDarkMode()"></i>
        <span class="badge bg-secondary p-2"><?php echo htmlspecialchars($_SESSION['fullname']); ?> (<?php echo $_SESSION['role']; ?>)</span>
        <a href="?logout=1" class="btn btn-danger btn-sm">Logout</a>
    </div>
</div>

<?php if(isset($_SESSION['msg'])): ?><div class="alert alert-info alert-dismissible fade show"><?php echo $_SESSION['msg']; unset($_SESSION['msg']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if(isset($_SESSION['last_collection'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <strong>Payment received from <?php echo $_SESSION['last_collection']['name']; ?>: <?php echo $_SESSION['last_collection']['amount']; ?> SAR</strong><br>
  Collected by: <?php echo $_SESSION['last_collection']['collector']; ?> on <?php echo $_SESSION['last_collection']['datetime']; ?>.<br>
  WhatsApp confirmation sent automatically.
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['last_collection']); endif; ?>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link <?php echo $page == 'dashboard' ? 'active' : ''; ?>" href="?page=dashboard"><i class="fas fa-chart-line"></i> Dashboard</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $page == 'collections' ? 'active' : ''; ?>" href="?page=collections"><i class="fas fa-hand-holding-usd"></i> Collections</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $page == 'customers' ? 'active' : ''; ?>" href="?page=customers"><i class="fas fa-users"></i> Customers</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $page == 'files' ? 'active' : ''; ?>" href="?page=files"><i class="fas fa-video"></i> Movies</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $page == 'report' ? 'active' : ''; ?>" href="?page=report"><i class="fas fa-file-alt"></i> Collection Report</a></li>
    <?php if(isMaster()): ?>
    <li class="nav-item"><a class="nav-link <?php echo $page == 'audit' ? 'active' : ''; ?>" href="?page=audit"><i class="fas fa-history"></i> Audit Log</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $page == 'admins' ? 'active' : ''; ?>" href="?page=admins"><i class="fas fa-user-shield"></i> Admins</a></li>
    <?php endif; ?>
</ul>

<!-- DASHBOARD -->
<?php if($page == 'dashboard'): ?>
<div class="row mb-4 g-3">
    <div class="col-md-3 col-6"><div class="card text-white bg-primary stats-card shadow"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h6 class="card-title mb-0">Total Customers</h6><p class="display-6 mb-0 fw-bold"><?php echo $total_customers; ?></p></div><i class="fas fa-users fa-2x opacity-50"></i></div></div></div></div>
    <div class="col-md-3 col-6"><div class="card text-white bg-success stats-card shadow"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h6 class="card-title mb-0">Paid (<?php echo date('M', strtotime($display_month)); ?>)</h6><p class="display-6 mb-0 fw-bold"><?php echo $collected_count; ?></p></div><i class="fas fa-check-circle fa-2x opacity-50"></i></div></div></div></div>
    <div class="col-md-3 col-6"><div class="card text-white bg-danger stats-card shadow"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h6 class="card-title mb-0">Pending (<?php echo date('M', strtotime($display_month)); ?>)</h6><p class="display-6 mb-0 fw-bold"><?php echo $pending_count; ?></p></div><i class="fas fa-clock fa-2x opacity-50"></i></div></div></div></div>
    <div class="col-md-3 col-6"><div class="card text-white bg-info stats-card shadow"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h6 class="card-title mb-0">Total Collected</h6><p class="display-6 mb-0 fw-bold"><?php echo number_format($total_amount_collected ?: 0, 2); ?> SAR</p></div><i class="fas fa-chart-line fa-2x opacity-50"></i></div></div></div></div>
</div>
<div class="card mb-3"><div class="card-body"><form method="get" class="row g-2"><input type="hidden" name="page" value="dashboard"><div class="col-8 col-md-4"><select name="stats_month" class="form-select"><?php for($i=0;$i<12;$i++){$m=date('Y-m',strtotime("-$i months"));$sel=($m==$display_month)?'selected':'';echo "<option value=\"$m\" $sel>".date('F Y',strtotime($m))."</option>";}?></select></div><div class="col-4 col-md-2"><button type="submit" class="btn btn-primary w-100">Show</button></div></form></div></div>
<div class="card mb-3"><div class="card-header bg-secondary text-white"><h5 class="mb-0"><i class="fas fa-users"></i> Collections by Staff - <?php echo date("F Y", strtotime($display_month)); ?> (by collection date)</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-dark"><tr><th><i class="fas fa-user-circle"></i> Staff Name</th><th class="text-end"><i class="fas fa-money-bill-wave"></i> Total Collected (SAR)</th><th class="text-center"><i class="fas fa-receipt"></i> Number of Collections</th><th class="text-center"><i class="fas fa-chart-line"></i> Average per Collection</th></tr></thead><tbody><?php $staff = $db->query("SELECT id, fullname FROM users ORDER BY fullname"); $grand_total = 0; $grand_count = 0; while($s = $staff->fetchArray(SQLITE3_ASSOC)): $total = $db->querySingle("SELECT COALESCE(SUM(amount), 0) FROM collections WHERE strftime('%Y-%m', collected_date) = '$display_month' AND collected_by={$s['id']}"); $count = $db->querySingle("SELECT COUNT(*) FROM collections WHERE strftime('%Y-%m', collected_date) = '$display_month' AND collected_by={$s['id']}"); $avg = $count > 0 ? round($total / $count, 2) : 0; $grand_total += $total; $grand_count += $count; ?><tr><td><strong><?php echo htmlspecialchars($s['fullname']); ?></strong></td><td class="text-end fw-bold text-success"><?php echo number_format($total, 2); ?> SAR</a></td><td class="text-center"><span class="badge bg-info"><?php echo $count; ?></span></td><td class="text-center"><?php echo number_format($avg, 2); ?> SAR</a></tr><?php endwhile; ?><tr class="table-secondary fw-bold"><td><strong>TOTAL</strong></a></td><td class="text-end text-primary"><?php echo number_format($grand_total, 2); ?> SAR</a></td><td class="text-center"><span class="badge bg-dark"><?php echo $grand_count; ?></span></a></td><td class="text-center"><?php echo $grand_count > 0 ? number_format($grand_total / $grand_count, 2) : 0; ?> SAR</a></tr></tbody></table></div></div></div>
<?php endif; ?>

<!-- COLLECTIONS -->
<?php if($page == 'collections'): ?>
<div class="card mb-3"><div class="card-header bg-primary text-white">Search Customer</div><div class="card-body"><input type="text" id="collectionSearch" class="form-control" placeholder="Type name, mobile, building, room..."><div id="collectionSearchResults" class="mt-2"></div></div></div>
<div class="card"><div class="card-header bg-warning text-dark">Pending Customers (Oldest Unpaid First)</div><div class="card-body"><div class="table-responsive"><table class="table table-bordered"><thead class="table-dark"><tr><th>#</th><th>Name</th><th>Mobile</th><th>Billing Day</th><th>Address</th><th>Unpaid Months</th><th>Total Due</th><th>Action</th></tr></thead><tbody><?php $idx=0; foreach($pending_customers as $p): $idx++; $total_due = array_sum(array_column($p['unpaid'], 'due')); $months_json = json_encode(array_map(function($u){ return ['month_name' => date('F Y', strtotime($u['month'])), 'due' => $u['due']]; }, $p['unpaid'])); ?><tr><td style="text-align:center"><?php echo $idx; ?></a></td><td><?php echo htmlspecialchars($p['name']); ?></a></td><td><?php echo $p['mobile']; ?></a><td><strong>Day <?php echo $p['due_day']; ?></strong></a><td><?php echo $p['building'].' '.$p['apartment'].' R'.$p['room']; ?></a><td><?php foreach($p['unpaid'] as $u) echo date('M Y', strtotime($u['month']))." (".$u['due']." SAR)<br>"; ?></a><td><?php echo $total_due; ?> SAR</a><td><button class="btn btn-sm btn-primary" onclick="openCollectionModal(<?php echo $p['id']; ?>,'<?php echo htmlspecialchars($p['name']); ?>')"><i class="fas fa-money-bill"></i> Collect</button> <button onclick="sendReminder('<?php echo $p['mobile']; ?>', '<?php echo htmlspecialchars($p['name']); ?>', <?php echo $total_due; ?>, <?php echo htmlspecialchars($months_json, ENT_QUOTES); ?>)" class="btn btn-sm whatsapp-btn"><i class="fab fa-whatsapp"></i> Remind</button> </a></td><?php endforeach; if($idx==0): ?><tr><td colspan="8" class="text-center">No pending customers - all bills paid!</a></tr><?php endif; ?></tbody></table></div></div></div>

<div class="modal fade" id="collectionModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5>Collect Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form method="post" id="partialCollectionForm"><input type="hidden" name="action" value="add_collection_partial"><input type="hidden" name="customer_id" id="coll_customer_id"><h6 id="coll_customer_name"></h6><div id="unpaidMonthsList"></div><hr><div class="alert alert-info">Total to pay: <span id="totalAmount">0</span> SAR</div><button type="submit" class="btn btn-primary">Record Payment</button></form></div></div></div></div>
<?php endif; ?>

<!-- CUSTOMERS -->
<?php if($page == 'customers'): ?>
<div class="card mb-3"><div class="card-header bg-primary text-white">Search Customer</div><div class="card-body"><input type="text" id="customerSearch" class="form-control" placeholder="Type name, mobile, building, room..."><div id="customerSearchResults" class="mt-2"></div></div></div>
<div class="d-flex justify-content-between mb-3"><h5>All Customers</h5><button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#customerModal" onclick="clearCustomerForm()"><i class="fas fa-plus"></i> Add Customer</button></div>
<div class="table-responsive"><table class="table table-bordered table-striped"><thead class="table-dark"><tr><th>#</th><th>Name</th><th>Mobile</th><th>Address</th><th>Billing Day</th><th>WhatsApp</th><th>Action</th></tr></thead><tbody id="customersTableBody"><?php $i=0; $custs=$db->query("SELECT * FROM customers WHERE status='active' ORDER BY name"); while($c=$custs->fetchArray(SQLITE3_ASSOC)){$i++;?> <tr data-name="<?php echo htmlspecialchars($c['name']); ?>" data-mobile="<?php echo $c['mobile']; ?>" data-building="<?php echo $c['building']; ?>" data-room="<?php echo $c['room']; ?>"><td><?php echo $i; ?></a><td><?php echo htmlspecialchars($c['name']); ?><br><small><a href="#" onclick="showHistory(<?php echo $c['id']; ?>,'<?php echo htmlspecialchars($c['name']); ?>')">View History</a></small></a><td><?php echo $c['mobile']; ?></a><td><?php echo $c['building'].' '.$c['apartment'].' R'.$c['room']; ?></a><td>Day <?php echo $c['billing_day']; ?></a><td><button onclick="sendReminderSimple('<?php echo $c['mobile']; ?>', '<?php echo htmlspecialchars($c['name']); ?>')" class="btn btn-sm whatsapp-btn"><i class="fab fa-whatsapp"></i></button></a><td><button class="btn btn-sm btn-info" onclick="editCust(<?php echo $c['id']; ?>,'<?php echo htmlspecialchars($c['name']); ?>','<?php echo $c['mobile']; ?>','<?php echo $c['building']; ?>','<?php echo $c['apartment']; ?>','<?php echo $c['room']; ?>',<?php echo $c['billing_day']; ?>,'<?php echo $c['billing_start_date']; ?>')"><i class="fas fa-edit"></i></button> <a href="?delete_customer=<?php echo $c['id']; ?>&page=customers" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a></a></tr><?php }?></tbody></table></div>

<div class="modal fade" id="customerModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-success text-white"><h5>Customer</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form method="post"><input type="hidden" name="action" value="save_customer"><input type="hidden" name="id" id="cust_id"><input type="text" name="name" id="cust_name" class="form-control mb-2" placeholder="Full Name" required><input type="text" name="mobile" id="cust_mobile" class="form-control mb-2" placeholder="Mobile Number" required><input type="text" name="building" id="cust_building" class="form-control mb-2" placeholder="Building"><input type="text" name="apartment" id="cust_apartment" class="form-control mb-2" placeholder="Apartment"><input type="text" name="room" id="cust_room" class="form-control mb-2" placeholder="Room"><input type="number" name="billing_day" id="cust_billing_day" class="form-control mb-2" placeholder="Billing Day (1-31)" required><input type="date" name="billing_start_date" id="cust_start_date" class="form-control mb-2" required><button type="submit" class="btn btn-success">Save Customer</button></form></div></div></div></div>

<div class="modal fade" id="historyModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-info text-white"><h5>Payment History</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div id="historyContent"></div></div></div></div></div>
<?php endif; ?>

<!-- COLLECTION REPORT -->
<?php if($page == 'report'): ?>
<div class="card"><div class="card-header bg-info text-white">All Collections (Who Collected From Whom)</div><div class="card-body"><div class="table-responsive"><table class="table table-bordered table-striped"><thead class="table-dark"><tr><th>ID</th><th>Customer</th><th>Month (Owed)</th><th>Amount (SAR)</th><th>Collected Date</th><th>Collected By</th><?php if(isMaster()): ?><th>Actions</th><?php endif; ?> </a></thead><tbody><?php while($col=$all_collections->fetchArray(SQLITE3_ASSOC)): ?><tr><td><?php echo $col['id']; ?></a><td><?php echo htmlspecialchars($col['customer_name']); ?></a><td><?php echo $col['month_year']; ?></a><td><?php echo $col['amount']; ?> SAR</a><td><?php echo $col['collected_date']; ?></a><td><?php echo htmlspecialchars($col['collector']); ?></a><?php if(isMaster()): ?><td><form method="post" style="display:inline-block"><input type="hidden" name="action" value="edit_collection"><input type="hidden" name="collection_id" value="<?php echo $col['id']; ?>"><input type="number" name="amount" value="<?php echo $col['amount']; ?>" step="1" style="width:70px" class="form-control form-control-sm d-inline-block"><button type="submit" class="btn btn-sm btn-primary">Edit</button></form> <a href="?delete_collection=<?php echo $col['id']; ?>&page=report" class="btn btn-sm btn-danger" onclick="return confirm('Delete this collection?')">Delete</a></a><?php endif; ?> </a></tr><?php endwhile; ?></tbody></table></div></div></div>
<?php endif; ?>

<!-- FILES -->
<?php if($page == 'files'): ?>
<div class="card mb-3"><div class="card-header bg-dark text-white">Upload Movie/File</div><div class="card-body"><form action="portal.php?page=files" method="post" enctype="multipart/form-data"><div class="row g-2"><div class="col-md-3"><input type="text" name="title" class="form-control" placeholder="Title" required></div><div class="col-md-3"><textarea name="description" class="form-control" placeholder="Description"></textarea></div><div class="col-md-3"><input type="file" name="upload_file" class="form-control" required></div><div class="col-md-3"><button type="submit" class="btn btn-primary w-100">Upload</button></div></div></form></div></div>
<div class="table-responsive"><table class="table table-bordered table-striped"><thead class="table-dark"><tr><th>Title</th><th>Original Name</th><th>Size (MB)</th><th>Downloads</th><th>Action</th></tr></thead><tbody><?php while($f=$files->fetchArray(SQLITE3_ASSOC)){$size=round($f['size']/1048576,2);?><tr><td><?php echo htmlspecialchars($f['title']); ?></a><td><?php echo htmlspecialchars($f['original_name']); ?></a><td><?php echo $size; ?></a><td><?php echo $f['downloads']; ?></a><td><a href="?download=<?php echo $f['id']; ?>" class="btn btn-sm btn-success"><i class="fas fa-download"></i> Download</a> <?php if(isMaster()){ ?><a href="?delete_file=<?php echo $f['id']; ?>&page=files" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a><?php } ?> </a></td><?php }?></tbody></table></div>
<?php endif; ?>

<!-- AUDIT LOG with User Filter -->
<?php if($page == 'audit' && isMaster()): ?>
<div class="card mb-3"><div class="card-header bg-secondary text-white">Filter by User</div><div class="card-body"><form method="get" class="row g-2"><input type="hidden" name="page" value="audit"><div class="col-8 col-md-4"><select name="filter_user" class="form-select"><option value="0">All Users</option><?php $users=$db->query("SELECT id, fullname FROM users ORDER BY fullname"); while($u=$users->fetchArray(SQLITE3_ASSOC)){$selected=($filter_user==$u['id'])?'selected':'';echo "<option value=\"{$u['id']}\" $selected>".htmlspecialchars($u['fullname'])."</option>";}?></select></div><div class="col-4 col-md-2"><button type="submit" class="btn btn-primary w-100">Filter</button></div><div class="col-4 col-md-2"><a href="?page=audit&filter_user=0" class="btn btn-secondary w-100">Clear</a></div></form></div></div>
<div class="card"><div class="card-header bg-secondary text-white">Audit Log - All System Changes</div><div class="card-body"><div class="table-responsive"><table class="table table-bordered table-striped"><thead class="table-dark"><tr><th>Timestamp</th><th>User</th><th>Action</th><th>Details</th></tr></thead><tbody><?php while($log=$audit_log->fetchArray(SQLITE3_ASSOC)): ?><tr><td><?php echo $log['timestamp']; ?></a><td><?php echo htmlspecialchars($log['fullname']); ?></a><td><?php echo $log['action']; ?></a><td><?php echo htmlspecialchars($log['details']); ?></a></tr><?php endwhile; ?></tbody></table></div></div></div>
<?php endif; ?>

<!-- ADMINS PAGE -->
<?php if($page == 'admins' && isMaster()): ?>
<div class="card mb-3"><div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center"><span>Admin Users</span><button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#addAdminModal">+ Add Admin</button></div><div class="card-body"><div class="table-responsive"><table class="table table-bordered"><thead class="table-dark"><tr><th>Name</th><th>Username</th><th>Role</th><th>Actions</th></tr></thead><tbody><?php $admins=$db->query("SELECT id,fullname,username,role FROM users"); while($a=$admins->fetchArray(SQLITE3_ASSOC)): ?><tr><td><?php echo htmlspecialchars($a['fullname']); ?></a><td><?php echo htmlspecialchars($a['username']); ?></a><td><?php echo $a['role']; ?></a><td><?php if($a['role'] == 'master'): ?><button class="btn btn-sm btn-info" onclick="editMaster(<?php echo $a['id']; ?>, '<?php echo htmlspecialchars($a['fullname']); ?>', '<?php echo htmlspecialchars($a['username']); ?>')">Edit Master</button><?php else: ?><button class="btn btn-sm btn-warning" onclick="resetPass(<?php echo $a['id']; ?>)">Reset Password</button> <a href="?delete_admin=<?php echo $a['id']; ?>&page=admins" class="btn btn-sm btn-danger" onclick="return confirm('Delete this admin?')">Delete</a><?php endif; ?></a></tr><?php endwhile; ?></tbody></table></div></div></div>

<div class="modal fade" id="addAdminModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-success text-white"><h5>Add New Admin</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form method="post"><input type="hidden" name="action" value="save_admin"><div class="mb-2"><input type="text" name="fullname" class="form-control" placeholder="Full Name" required></div><div class="mb-2"><input type="text" name="username" class="form-control" placeholder="Username" required></div><div class="mb-2"><input type="password" name="password" class="form-control" placeholder="Password" required></div><button type="submit" class="btn btn-success">Add Admin</button></form></div></div></div></div>

<div class="modal fade" id="editMasterModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-info text-white"><h5>Edit Master Admin</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form method="post"><input type="hidden" name="action" value="edit_master"><input type="hidden" name="master_id" id="master_id"><div class="mb-2"><input type="text" name="fullname" id="master_fullname" class="form-control" placeholder="Full Name" required></div><div class="mb-2"><input type="text" name="username" id="master_username" class="form-control" placeholder="Username" required></div><div class="mb-2"><label>New Password (leave blank to keep current)</label><input type="password" name="new_password" class="form-control" placeholder="Enter new password"></div><button type="submit" class="btn btn-info">Update Master</button></form></div></div></div></div>

<div class="modal fade" id="resetPassModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-warning"><h5>Reset Password</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form method="post"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" id="reset_user_id"><input type="password" name="new_password" class="form-control" placeholder="New Password" required><button type="submit" class="btn btn-warning mt-2">Reset</button></form></div></div></div></div>
<?php endif; ?>

<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleDarkMode() { document.body.classList.toggle('dark'); localStorage.setItem('darkMode', document.body.classList.contains('dark') ? 'enabled' : 'disabled'); }
if (localStorage.getItem('darkMode') === 'enabled') document.body.classList.add('dark');

function sendReminder(mobile, name, totalDue, monthsList) {
    let monthsText = "";
    if (monthsList && monthsList.length > 0) {
        monthsList.forEach(function(m) {
            monthsText += "- " + m.month_name + ": " + m.due + " SAR\n";
        });
    }
    
    let message = "Dear " + name + ",\n\nYou have unpaid bills:\n" + monthsText + "\nTotal due: " + totalDue + " SAR\nPlease pay soon.\n\nEnjoy free movies: http://10.12.14.16:8082\n\nTechnical Support:\nCyber Net: +966594266584\nRiyad Hossain: +966546377863\nJahir Hossain: +966542349510";
    let phone = mobile.replace(/^0+/, "");
    
    fetch("http://10.12.14.16:2785/api/sessions/63bd1d8f-7ed7-45f4-8e25-821626752eaf/messages/send-text", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-API-Key": "dev-admin-key"
        },
        body: JSON.stringify({
            chatId: "966" + phone + "@c.us",
            text: message
        })
    })
    .then(response => response.json())
    .then(data => {
        alert("✓ WhatsApp reminder sent to " + name);
    })
    .catch(error => {
        alert("✗ Failed to send. Check OpenWA.");
    });
}

function sendReminderSimple(mobile, name) {
    let message = "Dear " + name + ",\n\nYour internet bill is due. Please pay on time.\n\nEnjoy free movies: http://10.12.14.16:8082\n\nTechnical Support:\nCyber Net: +966594266584\nRiyad Hossain: +966546377863\nJahir Hossain: +966542349510";
    let phone = mobile.replace(/^0+/, "");
    
    fetch("http://10.12.14.16:2785/api/sessions/63bd1d8f-7ed7-45f4-8e25-821626752eaf/messages/send-text", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-API-Key": "dev-admin-key"
        },
        body: JSON.stringify({
            chatId: "966" + phone + "@c.us",
            text: message
        })
    })
    .then(response => response.json())
    .then(data => {
        alert("✓ WhatsApp reminder sent to " + name);
    })
    .catch(error => {
        alert("✗ Failed to send. Check OpenWA.");
    });
}

const collSearch = document.getElementById('collectionSearch');
if (collSearch) {
    collSearch.addEventListener('input', function() {
        let query = this.value.trim();
        let resultsDiv = document.getElementById('collectionSearchResults');
        if (query.length < 2) { resultsDiv.style.display = 'none'; return; }
        fetch('search_customers.php?q=' + encodeURIComponent(query))
            .then(r => r.json()).then(data => {
                if (data.length === 0) { resultsDiv.innerHTML = '<div class="alert alert-warning">No customers found</div>'; resultsDiv.style.display = 'block'; return; }
                let html = '<div class="list-group">';
                data.forEach(cust => {
                    html += '<div class="list-group-item"><strong>' + cust.name + '</strong><br><small>Mobile: ' + cust.mobile + ' | Building: ' + cust.building + ' | Room: ' + cust.room + '</small><br>';
                    html += '<button class="btn btn-sm btn-primary mt-1" onclick="openCollectionModal(' + cust.id + ',\'' + cust.name.replace(/'/g, "\\'") + '\')">Collect Bill</button> ';
                    html += '<button onclick="sendReminderSimple(\'' + cust.mobile + '\', \'' + cust.name.replace(/'/g, "\\'") + '\')" class="btn btn-sm whatsapp-btn mt-1">WhatsApp</button></div>';
                });
                html += '</div>';
                resultsDiv.innerHTML = html;
                resultsDiv.style.display = 'block';
            });
    });
}

const custSearch = document.getElementById('customerSearch');
if (custSearch) {
    custSearch.addEventListener('input', function() {
        let query = this.value.toLowerCase().trim();
        let rows = document.querySelectorAll('#customersTableBody tr');
        let resultsDiv = document.getElementById('customerSearchResults');
        let matchCount = 0;
        rows.forEach(row => {
            let name = row.getAttribute('data-name')?.toLowerCase() || '';
            let mobile = row.getAttribute('data-mobile')?.toLowerCase() || '';
            let building = row.getAttribute('data-building')?.toLowerCase() || '';
            let room = row.getAttribute('data-room')?.toLowerCase() || '';
            if (name.includes(query) || mobile.includes(query) || building.includes(query) || room.includes(query)) {
                row.style.display = '';
                matchCount++;
            } else {
                row.style.display = 'none';
            }
        });
        if (query.length > 0 && matchCount === 0) {
            resultsDiv.innerHTML = '<div class="alert alert-warning mt-2">No customers found</div>';
            resultsDiv.style.display = 'block';
        } else {
            resultsDiv.style.display = 'none';
        }
    });
}

function openCollectionModal(id, name) {
    document.getElementById('coll_customer_id').value = id;
    document.getElementById('coll_customer_name').innerHTML = 'Customer: ' + name;
    fetch('get_unpaid.php?cid=' + id).then(r => r.json()).then(data => {
        let html = '<table class="table table-bordered"><thead><tr><th>Select</th><th>Month</th><th>Due (SAR)</th><th>Amount to Pay (SAR)</th></tr></thead><tbody>';
        data.forEach((m, idx) => {
            html += '<tr><td><input type="checkbox" name="months[]" value="' + m.month + '" onchange="updateTotal()" class="month-checkbox" checked></td>';
            html += '<td><strong>' + new Date(m.month + '-01').toLocaleDateString('en', { year:'numeric', month:'long' }) + '</strong></td>';
            html += '<td>' + m.due + ' SAR</a></td>';
            html += '<td><input type="number" name="amounts[]" class="form-control amount-input" value="' + m.due + '" step="1" onchange="updateTotal()" style="width:120px"></a></td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        document.getElementById('unpaidMonthsList').innerHTML = html;
        updateTotal();
        new bootstrap.Modal(document.getElementById('collectionModal')).show();
    });
}

function updateTotal() { let total = 0; document.querySelectorAll('.amount-input').forEach(input => { total += parseFloat(input.value) || 0; }); document.getElementById('totalAmount').innerText = total; }
function showHistory(id,name){ fetch('get_history.php?cid='+id).then(r=>r.text()).then(html=>{ document.getElementById('historyContent').innerHTML=html; new bootstrap.Modal(document.getElementById('historyModal')).show(); }); }
function clearCustomerForm(){ document.getElementById('cust_id').value='';document.getElementById('cust_name').value='';document.getElementById('cust_mobile').value='';document.getElementById('cust_building').value='';document.getElementById('cust_apartment').value='';document.getElementById('cust_room').value='';document.getElementById('cust_billing_day').value='';document.getElementById('cust_start_date').value=''; }
function editCust(id,name,mobile,building,apartment,room,day,start){ document.getElementById('cust_id').value=id;document.getElementById('cust_name').value=name;document.getElementById('cust_mobile').value=mobile;document.getElementById('cust_building').value=building;document.getElementById('cust_apartment').value=apartment;document.getElementById('cust_room').value=room;document.getElementById('cust_billing_day').value=day;document.getElementById('cust_start_date').value=start;new bootstrap.Modal(document.getElementById('customerModal')).show(); }
function editMaster(id, fullname, username) { document.getElementById('master_id').value = id; document.getElementById('master_fullname').value = fullname; document.getElementById('master_username').value = username; new bootstrap.Modal(document.getElementById('editMasterModal')).show(); }
function resetPass(id){ document.getElementById('reset_user_id').value=id; new bootstrap.Modal(document.getElementById('resetPassModal')).show(); }
</script>
</body>
</html>

