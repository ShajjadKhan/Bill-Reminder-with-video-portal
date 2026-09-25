<?php
// ============================================================
// ISP BILLING PORTAL — v2.0  (fixed & polished)
// ============================================================
session_start();
date_default_timezone_set('Asia/Riyadh');

$db = new SQLite3('/home/tserver/billing_reminder/bills.db');
$db->busyTimeout(10000);
$db->exec('PRAGMA journal_mode = WAL;');
$db->exec('PRAGMA synchronous = NORMAL;');
require_once __DIR__ . '/billing_logic.php';

// ============================================================
// TABLES
// ============================================================
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY, username TEXT UNIQUE,
    password TEXT, fullname TEXT, role TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY, name TEXT, mobile TEXT UNIQUE,
    building TEXT, apartment TEXT, room TEXT,
    billing_day INTEGER DEFAULT 1,
    billing_start_date TEXT,
    monthly_fee REAL DEFAULT 30,
    status TEXT DEFAULT 'active',
    created_by INTEGER, created_at TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS collections (
    id INTEGER PRIMARY KEY, customer_id INTEGER,
    month_year TEXT, amount REAL,
    collected_by INTEGER, collected_date TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY, user_id INTEGER,
    action TEXT, details TEXT, timestamp TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS files (
    id INTEGER PRIMARY KEY, title TEXT, description TEXT,
    filename TEXT, original_name TEXT, size INTEGER,
    mime TEXT, uploaded_by INTEGER, upload_date TEXT,
    downloads INTEGER DEFAULT 0)");
$db->exec("CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY, value TEXT)");

// Safe column migrations
$col_check = $db->query("PRAGMA table_info(customers)");
$existing = [];
while ($r = $col_check->fetchArray(SQLITE3_ASSOC)) $existing[] = $r['name'];
if (!in_array('monthly_fee',        $existing)) $db->exec("ALTER TABLE customers ADD COLUMN monthly_fee REAL DEFAULT 30");
if (!in_array('billing_day',        $existing)) $db->exec("ALTER TABLE customers ADD COLUMN billing_day INTEGER DEFAULT 1");
if (!in_array('billing_start_date', $existing)) $db->exec("ALTER TABLE customers ADD COLUMN billing_start_date TEXT");

// Add is_settled to collections
$col_check2 = $db->query("PRAGMA table_info(collections)");
$existing2 = [];
while ($r2 = $col_check2->fetchArray(SQLITE3_ASSOC)) $existing2[] = $r2['name'];
if (!in_array('is_settled', $existing2)) $db->exec("ALTER TABLE collections ADD COLUMN is_settled INTEGER DEFAULT 0");

// Default settings
$defaults = [
    'openwa_url'       => 'http://10.12.14.16:2785',
    'openwa_api_key'   => 'dev-admin-key',
    'openwa_session_id'=> '8cc17322-a9d3-4b88-89ac-d4d95fb57ff4',
    'movie_server'     => 'http://10.12.14.16:8082',
    'support_1_name'   => 'Shajjad Khan',
    'support_1_phone'  => '+966597595059',
    'support_2_name'   => 'Riyad Hossain',
    'support_2_phone'  => '+966552036454',
];
foreach ($defaults as $k => $v) {
    $ek = SQLite3::escapeString($k);
    $ev = SQLite3::escapeString($v);
    $db->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('$ek','$ev')");
}

// ============================================================
// HELPERS
// ============================================================
function isLoggedIn() { return isset($_SESSION['user_id']); }
function isMaster()   { return isset($_SESSION['role']) && $_SESSION['role'] === 'master'; }
function isAdmin()    { return isset($_SESSION['role']) && in_array($_SESSION['role'], ['master','admin']); }

function logAction($db, $uid, $action, $details) {
    $a = SQLite3::escapeString($action);
    $d = SQLite3::escapeString($details);
    $db->exec("INSERT INTO audit_log (user_id,action,details,timestamp) VALUES ($uid,'$a','$d',datetime('now'))");
}
// Core billing & receipt functions provided by billing_logic.php



// ============================================================
// AJAX / API HANDLERS
// ============================================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ============================================================
// VACATION HOLD HANDLERS
// ============================================================
if (isLoggedIn() && isAdmin() && $action === 'add_hold') {
    $cid = intval($_POST['customer_id'] ?? 0);
    $start = trim($_POST['hold_start'] ?? '');
    $end = trim($_POST['hold_end'] ?? '');
    $reason = SQLite3::escapeString(trim($_POST['reason'] ?? 'Vacation'));
    if ($cid && $start) {
        $start_esc = SQLite3::escapeString($start);
        $end_val = $end ? "'" . SQLite3::escapeString($end) . "'" : 'NULL';
        $db->exec("INSERT INTO vacation_holds (customer_id, hold_start, hold_end, reason, created_by) VALUES ($cid, '$start_esc', $end_val, '$reason', {$_SESSION['user_id']})");
        logAction($db, $_SESSION['user_id'], 'ADD_HOLD', "Added vacation hold for customer $cid starting $start");
        $_SESSION['msg'] = "Vacation hold added";
    } else {
        $_SESSION['error'] = "Please select a customer and start date";
    }
    header('Location: portal.php?page=vacations'); exit;
}
if (isLoggedIn() && isAdmin() && $action === 'resume_hold') {
    $hid = intval($_POST['hold_id'] ?? 0);
    $resume_date = trim($_POST['resume_date'] ?? date('Y-m-d'));
    if (!$resume_date) $resume_date = date('Y-m-d');
    $resume_esc = SQLite3::escapeString($resume_date);
    if ($hid) {
        $db->exec("UPDATE vacation_holds SET hold_end='$resume_esc' WHERE id=$hid AND hold_end IS NULL");
        logAction($db, $_SESSION['user_id'], 'RESUME_HOLD', "Resumed vacation hold ID $hid on $resume_date");
        $_SESSION['msg'] = "Customer resumed on $resume_date";
    }
    header('Location: portal.php?page=vacations'); exit;
}
if (isLoggedIn() && isAdmin() && $action === 'edit_hold') {
    $hid = intval($_POST['hold_id'] ?? 0);
    $start = trim($_POST['hold_start'] ?? '');
    $end = trim($_POST['hold_end'] ?? '');
    $reason = SQLite3::escapeString(trim($_POST['reason'] ?? 'Vacation'));
    if ($hid && $start) {
        $start_esc = SQLite3::escapeString($start);
        $end_val = $end ? "'" . SQLite3::escapeString($end) . "'" : 'NULL';
        $db->exec("UPDATE vacation_holds SET hold_start='$start_esc', hold_end=$end_val, reason='$reason' WHERE id=$hid");
        logAction($db, $_SESSION['user_id'], 'EDIT_HOLD', "Updated vacation hold ID $hid ($start to " . ($end ?: 'ongoing') . ")");
        $_SESSION['msg'] = "Vacation hold updated successfully";
    }
    header('Location: portal.php?page=vacations'); exit;
}
if (isLoggedIn() && isAdmin() && $action === 'delete_hold') {
    $hid = intval($_POST['hold_id'] ?? 0);
    if ($hid) {
        $db->exec("DELETE FROM vacation_holds WHERE id=$hid");
        logAction($db, $_SESSION['user_id'], 'DELETE_HOLD', "Deleted vacation hold ID $hid");
        $_SESSION['msg'] = "Vacation hold removed";
    }
    header('Location: portal.php?page=vacations'); exit;
}

if ($action === 'toggle_reminders') {
    header('Content-Type: application/json');
    if (!isLoggedIn() || !isAdmin()) {
        echo json_encode(['ok'=>false, 'error'=>'Session expired or unauthorized. Please refresh and log in.']);
        exit;
    }
    $cid = intval($_POST['customer_id'] ?? 0);
    $cust = $db->querySingle("SELECT id, name, COALESCE(reminders_enabled, 1) as reminders_enabled FROM customers WHERE id=$cid", true);
    if (!$cust) {
        echo json_encode(['ok'=>false, 'error'=>'Customer not found']);
        exit;
    }
    $new_val = ((int)$cust['reminders_enabled'] === 1) ? 0 : 1;
    $db->exec("UPDATE customers SET reminders_enabled=$new_val WHERE id=$cid");
    $action_label = $new_val ? 'ENABLE_REMINDERS' : 'MUTE_REMINDERS';
    logAction($db, $_SESSION['user_id'], $action_label, ($new_val ? "Enabled" : "Muted") . " WhatsApp reminders for {$cust['name']}");
    echo json_encode(['ok'=>true, 'reminders_enabled'=>$new_val, 'name'=>$cust['name']]);
    exit;
}

if ($action === 'send_manual_reminder' && isLoggedIn()) {
    header('Content-Type: application/json');
    $cid = intval($_POST['customer_id'] ?? 0);
    $cust = $db->querySingle("SELECT *, COALESCE(reminders_enabled, 1) as reminders_enabled FROM customers WHERE id=$cid", true);
    if (!$cust) { echo json_encode(['ok'=>false,'error'=>'Customer not found']); exit; }

    if ((int)$cust['reminders_enabled'] === 0) {
        echo json_encode(['ok'=>false, 'error'=>"WhatsApp reminders are muted for {$cust['name']} upon request."]);
        exit;
    }

    // Check if customer is currently away on vacation hold
    $is_vac = $db->querySingle("SELECT COUNT(*) FROM vacation_holds WHERE customer_id=$cid AND hold_start <= date('now') AND (hold_end IS NULL OR hold_end >= date('now'))");
    if ($is_vac > 0) {
        echo json_encode(['ok'=>false, 'error'=>"{$cust['name']} is currently away on vacation hold. Reminders cannot be sent."]);
        exit;
    }

    $current_month = date('Y-m');
    $unpaid = getUnpaidMonths($db, $cid, $current_month);
    $total_unpaid = 0;
    foreach ($unpaid as $u) {
        $total_unpaid += floatval($u['due']);
    }
    $total_unpaid = round($total_unpaid, 2);

    $bal = getCustomerBalance($db, $cid);
    $account_balance = $bal ? floatval($bal['balance']) : -$total_unpaid;
    $owes = $bal ? floatval($bal['owes']) : $total_unpaid;

    $paid_this_month = (float)$db->querySingle("SELECT COALESCE(SUM(amount), 0) FROM collections WHERE customer_id=$cid AND (strftime('%Y-%m', collected_date)='$current_month' OR month_year='$current_month')");

    if (empty($unpaid) || $total_unpaid <= 0) {
        echo json_encode(['ok'=>false, 'error'=>'Customer has no unpaid months (all bills are settled)']);
        exit;
    }

    if ($account_balance >= 0) {
        echo json_encode(['ok'=>false, 'error'=>"Customer has positive balance (+{$account_balance} SAR)"]);
        exit;
    }

    if ($paid_this_month > 0 && ($total_unpaid <= 10 || $owes <= 10)) {
        echo json_encode(['ok'=>false, 'error'=>"Customer paid {$paid_this_month} SAR this month and remaining due is under 10 SAR ({$total_unpaid} SAR)"]);
        exit;
    }

    $fee = max(1, floatval($cust['monthly_fee'] ?: 30));
    $due_day = (int)($cust['due_day'] ?: $cust['billing_day'] ?: 1);
    $pay_by_day = intval($cust['pay_by_day'] ?? 10) ?: 10;
    $join_dt = new DateTime($cust['billing_start_date'] ?: date('Y-m-01'));
    $today = new DateTime(date('Y-m-d'));

    $oldest_unpaid_month = $unpaid[0]['month'];
    $oldest_dt = new DateTime($oldest_unpaid_month . '-01');
    $dim = (int)$oldest_dt->format('t');
    $safe_day = min($due_day, $dim);
    $active_until = new DateTime($oldest_unpaid_month . '-' . sprintf('%02d', $safe_day));

    $expired = $active_until < $today;
    $days_diff = abs($today->diff($active_until)->days);
    $movie_server = getSetting($db, 'movie_server') ?: 'http://10.12.14.16:8082';
    $s1n = getSetting($db, 'support_1_name'); $s1p = getSetting($db, 'support_1_phone');
    $s2n = getSetting($db, 'support_2_name'); $s2p = getSetting($db, 'support_2_phone');

    $message = buildReminderMsg($db, $cust['name'], $join_dt, $active_until, $expired, $days_diff, $total_unpaid, $pay_by_day);

    $result = sendWhatsAppMessage($db, $cust['mobile'], $message);
    if ($result) {
        logAction($db, $_SESSION['user_id'], 'MANUAL_REMINDER', "Sent manual reminder to {$cust['name']}");
        echo json_encode(['ok'=>true]);
    } else {
        echo json_encode(['ok'=>false,'error'=>'WhatsApp send failed - check OpenWA status']);
    }
    exit;
}
if ($action === 'wa_status' && isLoggedIn()) {
    header('Content-Type: application/json');
    $base = getSetting($db,'openwa_url');
    $key  = getSetting($db,'openwa_api_key');
    $sid  = getSetting($db,'openwa_session_id');
    $ch = curl_init("$base/api/sessions/$sid");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,
        CURLOPT_HTTPHEADER=>["X-API-Key: $key"]]);
    $r = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $data = $r ? json_decode($r,true) : null;
    echo json_encode(['http_code'=>$code,'session'=>$data,'session_id'=>$sid]);
    exit;
}

if ($action === 'wa_qr' && isLoggedIn()) {
    header('Content-Type: application/json');
    $base = getSetting($db,'openwa_url');
    $key  = getSetting($db,'openwa_api_key');
    $sid  = getSetting($db,'openwa_session_id');
    $ch = curl_init("$base/api/sessions/$sid/qr");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>["X-API-Key: $key"]]);
    $r = curl_exec($ch); curl_close($ch);
    echo $r ?: json_encode(['error'=>'No response from OpenWA']);
    exit;
}

if ($action === 'wa_reconnect' && isLoggedIn() && isMaster()) {
    header('Content-Type: application/json');
    $base = getSetting($db,'openwa_url');
    $key  = getSetting($db,'openwa_api_key');
    $old  = getSetting($db,'openwa_session_id');
    $log  = [];

    $ch = curl_init("$base/api/sessions/$old");
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'DELETE',CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>8,CURLOPT_HTTPHEADER=>["X-API-Key: $key"]]);
    curl_exec($ch); curl_close($ch);
    $log[] = 'Old session deleted';

    $ch = curl_init("$base/api/sessions");
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>["Content-Type: application/json","X-API-Key: $key"],
        CURLOPT_POSTFIELDS=>json_encode(['name'=>'billing-bot'])]);
    $r = curl_exec($ch); curl_close($ch);
    $created = json_decode($r, true);
    $new_id  = $created['id'] ?? null;
    if (!$new_id) { echo json_encode(['error'=>'Could not create session','raw'=>$r]); exit; }
    $log[] = "New session created: $new_id";

    $ch = curl_init("$base/api/sessions/$new_id/start");
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>["Content-Type: application/json","X-API-Key: $key"],
        CURLOPT_POSTFIELDS=>'{}']);
    curl_exec($ch); curl_close($ch);
    $log[] = 'Session started';

    setSetting($db,'openwa_session_id',$new_id);
    logAction($db,$_SESSION['user_id'],'WA_RECONNECT',"New session: $new_id");
    $log[] = 'Session ID saved to database';

    echo json_encode(['success'=>true,'new_session_id'=>$new_id,'log'=>$log]);
    exit;
}

// ============================================================
// AUTH
// ============================================================
if (isset($_GET['logout'])) { session_destroy(); header('Location: portal.php'); exit; }

if ($action === 'login') {
    $u = $db->querySingle("SELECT * FROM users WHERE username='".SQLite3::escapeString($_POST['username'])."'",true);
    if ($u && password_verify($_POST['password'],$u['password'])) {
        $_SESSION = ['user_id'=>$u['id'],'role'=>$u['role'],'fullname'=>$u['fullname'],'username'=>$u['username']];
        header('Location: portal.php'); exit;
    }
    $login_error = "Invalid username or password";
}

$page        = $_GET['page'] ?? 'dashboard';
$filter_user = isset($_GET['filter_user']) ? intval($_GET['filter_user']) : 0;

// ============================================================
// ADMIN MANAGEMENT
// ============================================================
if (isLoggedIn() && isMaster() && $action === 'save_admin') {
    $fn = SQLite3::escapeString($_POST['fullname']);
    $un = SQLite3::escapeString($_POST['username']);
    $pw = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $db->exec("INSERT INTO users (username,password,fullname,role) VALUES ('$un','$pw','$fn','admin')");
    logAction($db,$_SESSION['user_id'],'ADD_ADMIN',"Added $un");
    $_SESSION['msg'] = "Admin added successfully.";
    header('Location: portal.php?page=admins'); exit;
}
if (isLoggedIn() && isMaster() && isset($_GET['delete_admin'])) {
    $id = intval($_GET['delete_admin']);
    $a  = $db->querySingle("SELECT username FROM users WHERE id=$id AND role='admin'",true);
    if ($a) { $db->exec("DELETE FROM users WHERE id=$id"); logAction($db,$_SESSION['user_id'],'DELETE_ADMIN',"Deleted {$a['username']}"); }
    header('Location: portal.php?page=admins'); exit;
}
if (isLoggedIn() && isMaster() && $action === 'edit_master') {
    $id = intval($_POST['master_id']);
    $fn = SQLite3::escapeString($_POST['fullname']);
    $un = SQLite3::escapeString($_POST['username']);
    if (!empty($_POST['new_password'])) {
        $pw = password_hash($_POST['new_password'],PASSWORD_DEFAULT);
        $db->exec("UPDATE users SET fullname='$fn',username='$un',password='$pw' WHERE id=$id");
    } else {
        $db->exec("UPDATE users SET fullname='$fn',username='$un' WHERE id=$id");
    }
    logAction($db,$_SESSION['user_id'],'EDIT_MASTER','Updated master account');
    session_destroy(); header('Location: portal.php'); exit;
}
if (isLoggedIn() && isMaster() && $action === 'reset_password') {
    $id = intval($_POST['user_id']);
    $pw = password_hash($_POST['new_password'],PASSWORD_DEFAULT);
    $db->exec("UPDATE users SET password='$pw' WHERE id=$id");
    logAction($db,$_SESSION['user_id'],'RESET_PASSWORD',"Reset password for user $id");
    $_SESSION['msg'] = "Password reset.";
    header('Location: portal.php?page=admins'); exit;
}
if (isLoggedIn() && isMaster() && $action === 'save_support_settings') {
    $s1n = SQLite3::escapeString(trim($_POST['support_1_name'] ?? ''));
    $s1p = SQLite3::escapeString(trim($_POST['support_1_phone'] ?? ''));
    $s2n = SQLite3::escapeString(trim($_POST['support_2_name'] ?? ''));
    $s2p = SQLite3::escapeString(trim($_POST['support_2_phone'] ?? ''));
    $movie = SQLite3::escapeString(trim($_POST['movie_server'] ?? ''));
    $pay_acc = SQLite3::escapeString(trim($_POST['payment_accounts'] ?? ''));
    $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('support_1_name', '$s1n')");
    $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('support_1_phone', '$s1p')");
    $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('support_2_name', '$s2n')");
    $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('support_2_phone', '$s2p')");
    if (!empty($movie)) {
        $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('movie_server', '$movie')");
    }
    if ($pay_acc !== '') {
        $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('payment_accounts', '$pay_acc')");
    }
    logAction($db,$_SESSION['user_id'],'UPDATE_SUPPORT',"Updated support contacts & payment accounts");
    $_SESSION['msg'] = "Support & Payment settings updated successfully.";
    header('Location: portal.php?page=admins'); exit;
}

// ============================================================
// COLLECTION
// ALLOCATE ADVANCE PAYMENT - split large payment across future months
if (isLoggedIn() && (isMaster() || isAdmin()) && isset($_POST['action']) && $_POST['action'] == 'allocate_advance') {
    $customer_id = intval($_POST['customer_id']);
    $total_advance = floatval($_POST['advance_amount']);
    $customer = $db->querySingle("SELECT name, mobile, monthly_fee, billing_start_date FROM customers WHERE id=$customer_id", true);
    if (!$customer || $total_advance <= 0) { $_SESSION['error'] = "Invalid"; header('Location: portal.php?page=collections'); exit; }
    $fee = max(1, floatval($customer['monthly_fee'] ?: 30));
    $left = $total_advance;
    $months_to_cover = 0;

    // 1) Fill oldest unpaid months FIRST (gaps like Jan/Mar/May)
    $unpaid = getUnpaidMonths($db, $customer_id, date('Y-m'));
    foreach ($unpaid as $u) {
        if ($left <= 0) break;
        $pay = min($u['due'], $left);
        $db->exec("INSERT INTO collections (customer_id, month_year, amount, collected_by, collected_date) VALUES ($customer_id, '{$u['month']}', $pay, {$_SESSION['user_id']}, datetime('now'))");
        $left -= $pay;
        $months_to_cover++;
    }

    // 2) Then allocate remainder into future months after the latest recorded month
    if ($left > 0) {
        $last_col = $db->querySingle("SELECT MAX(month_year) as last_month FROM collections WHERE customer_id=$customer_id", true);
        if (is_null($last_col['last_month'])) { $start_month = new DateTime($customer['billing_start_date']); } else { $start_month = new DateTime($last_col['last_month'] . '-01'); $start_month->modify('+1 month'); }
        while ($left >= $fee) {
            $month_year = $start_month->format('Y-m');
            $db->exec("INSERT INTO collections (customer_id, month_year, amount, collected_by, collected_date) VALUES ($customer_id, '$month_year', $fee, {$_SESSION['user_id']}, datetime('now'))");
            $left -= $fee; $months_to_cover++;
            $start_month->modify('+1 month');
        }
        if ($left > 0) {
            $month_year = $start_month->format('Y-m');
            $db->exec("INSERT INTO collections (customer_id, month_year, amount, collected_by, collected_date) VALUES ($customer_id, '$month_year', $left, {$_SESSION['user_id']}, datetime('now'))");
            $left = 0;
        }
    }
    $remaining = 0;
    logAction($db, $_SESSION['user_id'], "ALLOCATE_ADVANCE", "$total_advance for {$customer['name']}");
    $_SESSION['msg'] = "Advance: $months_to_cover months + $remaining SAR";
    
    // Send WhatsApp receipt for advance payment
    if ($customer['mobile']) {
        $msg = "✅ ADVANCE PAYMENT RECEIVED\n";
        $msg .= "Customer: {$customer['name']}\n";
        $msg .= "Date: " . date("d M Y H:i") . "\n";
        $msg .= "💳 Amount: $total_advance SAR\n";
        $msg .= "📅 Covers: $months_to_cover months";
        if ($remaining > 0) $msg .= " + $remaining SAR";
        $msg .= "\n✅ Payment received. Thank you!\n";
        $msg .= "\n🎁 Free for our customers:\n🎬 Movies: http://10.12.14.16:8082\n⚽ Live Football: http://10.12.14.16:8086";
        sendWhatsAppMessage($db, $customer['mobile'], $msg);
    }
    
    header('Location: portal.php?page=collections');
    exit;
}


if (isLoggedIn() && isAdmin() && $action === 'add_collection_partial') {
    $customer_id = intval($_POST['customer_id']);
    $months      = $_POST['months']  ?? [];
    $amounts     = $_POST['amounts'] ?? [];
    $customer    = $db->querySingle("SELECT name,mobile,monthly_fee FROM customers WHERE id=$customer_id",true);

    $paid_items  = [];
    $total_paid  = 0;
    $cust_fee    = max(1, floatval(($customer['monthly_fee'] ?? 30) ?: 30));

    foreach ($months as $i => $month) {
        $amt = floatval($amounts[$i] ?? 0);
        $month = SQLite3::escapeString($month);
        if ($amt >= 0) {
            $prev_paid = (float)$db->querySingle("SELECT COALESCE(SUM(amount),0) FROM collections WHERE customer_id=$customer_id AND month_year='$month'");
            $total_month_paid = $prev_paid + $amt;

            // Calculate effective month fee taking vacation into account
            $dim = (int)date('t', strtotime($month . '-01'));
            $vholds = [];
            $hres = $db->query("SELECT hold_start, hold_end FROM vacation_holds WHERE customer_id=$customer_id");
            while ($h = $hres->fetchArray(SQLITE3_ASSOC)) {
                $vholds[] = ['s' => new DateTime($h['hold_start']), 'e' => $h['hold_end'] ? new DateTime($h['hold_end']) : null];
            }
            $mStart = new DateTime($month . '-01');
            $mEnd   = clone $mStart; $mEnd->modify('last day of this month');
            $billable = 0; $d = clone $mStart;
            while ($d <= $mEnd) {
                $in_hold = false;
                foreach ($vholds as $vh) {
                    if ($vh['e'] === null) { if ($d >= $vh['s']) { $in_hold = true; break; } }
                    else { if ($d >= $vh['s'] && $d <= $vh['e']) { $in_hold = true; break; } }
                }
                if (!$in_hold) $billable++;
                $d->modify('+1 day');
            }
            $effective_fee = ($billable == 0) ? 0 : round($cust_fee * $billable / $dim, 2);

            // Mark settled if total payments for this month reach effective pro-rated fee OR amount entered is 0 (explicit waiver)
            $is_settled = ($total_month_paid >= ($effective_fee - 0.05) || $amt == 0) ? 1 : 0;

            $db->exec("INSERT INTO collections (customer_id,month_year,amount,collected_by,collected_date,is_settled)
                VALUES ($customer_id,'$month',$amt,{$_SESSION['user_id']},datetime('now'),$is_settled)");
            $action_label = $is_settled ? 'SETTLE' : 'COLLECTION';
            logAction($db,$_SESSION['user_id'],$action_label,"{$customer['name']} — $month: $amt SAR".($is_settled?' (settled)':' (partial)'));
            $paid_items[] = ['month'=>$month,'amount'=>$amt,'settled'=>$is_settled];
            $total_paid  += $amt;
        }
    }

    $sent = false;
    $msg = '';
    if (!empty($paid_items)) {
        $msg  = buildReceiptMsg($db,$customer_id,$customer['name'],$_SESSION['fullname'],$paid_items,$total_paid);
        $sent = sendWhatsAppMessage($db,$customer['mobile'],$msg);
    }

    $_SESSION['last_collection'] = [
        'name'     => $customer['name'],
        'amount'   => $total_paid,
        'datetime' => date('Y-m-d H:i:s'),
        'collector'=> $_SESSION['fullname'],
        'mobile'   => $customer['mobile'],
        'receipt'  => $msg,
    ];
    $_SESSION['msg'] = "Payment of {$total_paid} SAR recorded. WhatsApp receipt " . ($sent ? 'sent successfully.' : 'could not be sent automatically.');
    header('Location: portal.php?page=collections'); exit;
}

// ============================================================
// CUSTOMER CRUD
// ============================================================
if (isLoggedIn() && isAdmin() && $action === 'save_customer') {
    $id          = intval($_POST['id'] ?? 0);
    $name        = SQLite3::escapeString(trim($_POST['name'] ?? ''));
    $mobile      = SQLite3::escapeString(trim($_POST['mobile'] ?? ''));
    $building    = SQLite3::escapeString(trim($_POST['building'] ?? ''));
    $apartment   = SQLite3::escapeString(trim($_POST['apartment'] ?? ''));
    $room        = SQLite3::escapeString(trim($_POST['room'] ?? ''));
    $billing_day = intval($_POST['billing_day']);
    $join_date   = SQLite3::escapeString(trim($_POST['billing_start_date'] ?? ''));
    $monthly_fee = max(1, floatval($_POST['monthly_fee']));
    $pay_by_day  = max(1, min(31, intval($_POST['pay_by_day'] ?: 10)));
    $reminders_enabled = isset($_POST['reminders_enabled']) ? 1 : 0;

    if ($id) {
        $db->exec("UPDATE customers SET name='$name',mobile='$mobile',building='$building',
            apartment='$apartment',room='$room',billing_day=$billing_day,
            billing_start_date='$join_date',monthly_fee=$monthly_fee,pay_by_day=$pay_by_day,
            reminders_enabled=$reminders_enabled WHERE id=$id");
        logAction($db,$_SESSION['user_id'],'EDIT_CUSTOMER',"Updated customer ID $id");
    } else {
        $db->exec("INSERT INTO customers (name,mobile,building,apartment,room,billing_day,
            billing_start_date,monthly_fee,pay_by_day,reminders_enabled,created_by,created_at)
            VALUES ('$name','$mobile','$building','$apartment','$room',
            $billing_day,'$join_date',$monthly_fee,$pay_by_day,$reminders_enabled,{$_SESSION['user_id']},datetime('now'))");
        logAction($db,$_SESSION['user_id'],'ADD_CUSTOMER',"Added $name");
    }
    header('Location: portal.php?page=customers'); exit;
}

if (isLoggedIn() && isAdmin() && isset($_GET['delete_customer'])) {
    $id = intval($_GET['delete_customer']);
    $c  = $db->querySingle("SELECT name FROM customers WHERE id=$id",true);
    $db->exec("UPDATE customers SET status='deleted' WHERE id=$id"); // Soft delete - preserves collection history
        logAction($db,$_SESSION['user_id'],'DELETE_CUSTOMER',"Deleted {$c['name']}");
    header('Location: portal.php?page=customers'); exit;
}

if (isLoggedIn() && isAdmin() && $action === 'save_promise') {
    $cid          = intval($_POST['customer_id']);
    $month_year   = SQLite3::escapeString($_POST['month_year'] ?? date('Y-m'));
    $promise_date = SQLite3::escapeString($_POST['promise_date']);
    $note         = SQLite3::escapeString($_POST['note'] ?? '');
    // Cancel any existing pending promise for this customer
    $db->exec("UPDATE promises SET status='cancelled' WHERE customer_id=$cid AND status='pending'");
    // Insert new promise
    $db->exec("INSERT INTO promises (customer_id,month_year,promise_date,note,status,created_by,created_at)
        VALUES ($cid,'$month_year','$promise_date','$note','pending',{$_SESSION['user_id']},datetime('now'))");
    $cname = $db->querySingle("SELECT name FROM customers WHERE id=$cid");
    logAction($db,$_SESSION['user_id'],'PROMISE',"$cname — promise to pay by $promise_date".($note?" ($note)":''));
    $_SESSION['msg'] = "Promise recorded for $cname — expected by $promise_date";
    header('Location: ?page=dashboard'); exit;
}

if (isLoggedIn() && isAdmin() && $action === 'cancel_promise') {
    $pid  = intval($_POST['promise_id']);
    $db->exec("UPDATE promises SET status='cancelled' WHERE id=$pid");
    $_SESSION['msg'] = "Promise cancelled";
    header('Location: ?page=dashboard'); exit;
}

if (isLoggedIn() && isMaster() && $action === 'edit_collection') {
    $id  = intval($_POST['collection_id']);
    $amt = floatval($_POST['amount']);
    $old = $db->querySingle("SELECT amount FROM collections WHERE id=$id",true);
    $db->exec("UPDATE collections SET amount=$amt WHERE id=$id");
    logAction($db,$_SESSION['user_id'],'EDIT_COLLECTION',"Collection $id: {$old['amount']}→$amt SAR");
    $_SESSION['msg'] = "Collection updated.";
    header('Location: portal.php?page=report'); exit;
}

if (isLoggedIn() && isMaster() && isset($_GET['delete_collection'])) {
    $id = intval($_GET['delete_collection']);
    $db->exec("DELETE FROM collections WHERE id=$id");
    logAction($db,$_SESSION['user_id'],'DELETE_COLLECTION',"Deleted collection $id");
    $_SESSION['msg'] = "Collection deleted.";
    header('Location: portal.php?page=report'); exit;
}

// ============================================================
// FILE MANAGEMENT
// ============================================================
if (isLoggedIn() && $action === 'upload_chunk') {
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    @ignore_user_abort(true);
    header('Content-Type: application/json');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $err = $_FILES['file']['error'] ?? 'none';
        echo json_encode(['success' => false, 'error' => 'No valid chunk received (error code: ' . $err . ')']);
        exit;
    }

    $chunk        = intval($_POST['chunk'] ?? 0);
    $total_chunks = intval($_POST['total_chunks'] ?? 1);
    $chunk_size   = intval($_POST['chunk_size'] ?? 8388608);
    if ($chunk_size <= 0) $chunk_size = 8388608;

    $session_id   = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['session_id'] ?? '');
    if (!$session_id) {
        $session_id = 'up_' . time() . '_' . rand(1000, 9999);
    }

    $original_name = trim($_POST['original_name'] ?? $_FILES['file']['name'] ?? 'movie.mp4');
    $title         = trim($_POST['title'] ?? '');
    $description   = trim($_POST['description'] ?? '');

    $temp_dir = '/mnt/bigstorage/tmp_chunks/' . $session_id;
    if (!is_dir($temp_dir)) {
        @mkdir($temp_dir, 0777, true);
        @chmod($temp_dir, 0777);
    }

    // Auto-cleanup stale chunk sessions older than 24h on first chunk of an upload
    if ($chunk === 0) {
        $base_tmp = '/mnt/bigstorage/tmp_chunks';
        if ($dh = @opendir($base_tmp)) {
            $now = time();
            while (($entry = readdir($dh)) !== false) {
                if ($entry === '.' || $entry === '..') continue;
                $entry_path = $base_tmp . '/' . $entry;
                if (is_dir($entry_path) && ($now - filemtime($entry_path) > 86400)) {
                    $files = @scandir($entry_path);
                    if ($files) {
                        foreach ($files as $f) {
                            if ($f !== '.' && $f !== '..') @unlink($entry_path . '/' . $f);
                        }
                    }
                    @rmdir($entry_path);
                }
            }
            closedir($dh);
        }
    }

    $part_file = $temp_dir . '/combined.part';

    // Stream chunk directly into part_file at its exact byte offset
    $out = fopen($part_file, 'c+b');
    if (!$out) {
        echo json_encode(['success' => false, 'error' => 'Cannot open storage part file on bigstorage']);
        exit;
    }

    flock($out, LOCK_EX);
    fseek($out, (int)($chunk * $chunk_size));
    $in = fopen($_FILES['file']['tmp_name'], 'rb');
    if ($in) {
        stream_copy_to_stream($in, $out);
        fclose($in);
    }
    fflush($out);
    flock($out, LOCK_UN);
    fclose($out);

    // Save chunk receipt marker
    touch($temp_dir . '/c_' . $chunk);

    // If last chunk, finalize
    if ($chunk + 1 >= $total_chunks) {
        // Verify all chunks 0 .. total_chunks - 1 are recorded
        $missing = [];
        for ($i = 0; $i < $total_chunks; $i++) {
            if (!file_exists($temp_dir . '/c_' . $i)) {
                $missing[] = $i;
            }
        }

        if (!empty($missing)) {
            echo json_encode([
                'success' => false,
                'error' => 'Missing ' . count($missing) . ' chunk(s), assembly incomplete.',
                'missing_chunks' => array_slice($missing, 0, 10)
            ]);
            exit;
        }

        $clean_orig = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($original_name));
        $final_filename = time() . '_' . $clean_orig;
        $final_path = '/mnt/bigstorage/files/' . $final_filename;

        // Atomic rename (instantaneous inode pointer update on same filesystem)
        if (!rename($part_file, $final_path)) {
            if (!copy($part_file, $final_path)) {
                echo json_encode(['success' => false, 'error' => 'Failed to move combined file to final destination']);
                exit;
            }
            @unlink($part_file);
        }

        // Clean up markers and session directory
        for ($i = 0; $i < $total_chunks; $i++) {
            @unlink($temp_dir . '/c_' . $i);
        }
        @unlink($part_file);
        @rmdir($temp_dir);

        $file_size = file_exists($final_path) ? filesize($final_path) : 0;
        $title_esc = SQLite3::escapeString($title ?: pathinfo($original_name, PATHINFO_FILENAME));
        $desc_esc  = SQLite3::escapeString($description);
        $fn_esc    = SQLite3::escapeString($final_filename);
        $on_esc    = SQLite3::escapeString($original_name);
        $uid       = intval($_SESSION['user_id'] ?? 1);

        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $mime_map = [
            'mp4' => 'video/mp4', 'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo', 'mov' => 'video/quicktime',
            'webm' => 'video/webm', 'ts' => 'video/mp2t'
        ];
        $mime = $mime_map[$ext] ?? 'video/mp4';

        $db->exec("INSERT INTO files (title, description, filename, original_name, size, mime, uploaded_by, upload_date)
            VALUES ('$title_esc', '$desc_esc', '$fn_esc', '$on_esc', $file_size, '$mime', $uid, datetime('now'))");
        logAction($db, $uid, 'UPLOAD_MOVIE', "Uploaded movie: $title ($original_name)");

        echo json_encode([
            'success' => true,
            'completed' => true,
            'filename' => $final_filename,
            'size' => $file_size,
            'title' => $title
        ]);
        exit;
    }

    echo json_encode(['success' => true, 'chunk' => $chunk, 'completed' => false]);
    exit;
}

if (isset($_FILES['upload_file']) && isLoggedIn()) {
    $dir  = '/mnt/bigstorage/files/';
    $title = SQLite3::escapeString($_POST['title']);
    $desc  = SQLite3::escapeString($_POST['description']);
    $file  = $_FILES['upload_file'];
    $new_name = time().'_'.basename($file['name']);
    if (move_uploaded_file($file['tmp_name'], $dir.$new_name)) {
        $on   = SQLite3::escapeString($file['name']);
        $db->exec("INSERT INTO files (title,description,filename,original_name,size,uploaded_by,upload_date)
            VALUES ('$title','$desc','$new_name','$on',{$file['size']},{$_SESSION['user_id']},datetime('now'))");
        logAction($db,$_SESSION['user_id'],'UPLOAD_FILE',"Uploaded {$file['name']}");
        $_SESSION['msg'] = "File uploaded.";
    }
    header('Location: portal.php?page=files'); exit;
}
if (isset($_GET['delete_file']) && isMaster()) {
    $id = intval($_GET['delete_file']);
    $f  = $db->querySingle("SELECT filename,original_name FROM files WHERE id=$id",true);
    if ($f) { @unlink('/mnt/bigstorage/files/'.$f['filename']); $db->exec("DELETE FROM files WHERE id=$id"); }
    header('Location: portal.php?page=files'); exit;
}
if (isset($_GET['download'])) {
    $id = intval($_GET['download']);
    $f  = $db->querySingle("SELECT filename,original_name FROM files WHERE id=$id",true);
    if ($f && file_exists('/mnt/bigstorage/files/'.$f['filename'])) {
        $db->exec("UPDATE files SET downloads=downloads+1 WHERE id=$id");
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.addslashes($f['original_name']).'"');
        readfile('/mnt/bigstorage/files/'.$f['filename']); exit;
    }
}

// ============================================================
// DATA LOADING (Optimized & Lazy Loaded by Page)
// ============================================================
$current_month  = date('Y-m');
$display_month  = $_GET['stats_month'] ?? $current_month;
$total_customers = $db->querySingle("SELECT COUNT(*) FROM customers WHERE status='active'");

$pending_customers = [];
if ($page === 'collections' || $page === 'dashboard') {
    $all_c = $db->query("SELECT id,name,mobile,building,apartment,room,billing_day,billing_start_date,monthly_fee,COALESCE(reminders_enabled, 1) as reminders_enabled, (SELECT COUNT(*) FROM vacation_holds vh WHERE vh.customer_id = customers.id AND vh.hold_start <= date('now') AND (vh.hold_end IS NULL OR vh.hold_end >= date('now'))) as is_on_vacation FROM customers WHERE status='active' ORDER BY name");
    while ($c = $all_c->fetchArray(SQLITE3_ASSOC)) {
        $unpaid = getUnpaidMonths($db,$c['id'],$current_month);
        if (!empty($unpaid)) {
            $c['unpaid']        = $unpaid;
            $c['oldest_unpaid'] = $unpaid[0]['month'];
            $pending_customers[] = $c;
        }
    }
    usort($pending_customers, fn($a,$b) => strcmp($a['oldest_unpaid'],$b['oldest_unpaid']));
    // Load promises for pending customers
    $today_str = date('Y-m-d');
    foreach ($pending_customers as &$pc) {
        $cid = $pc['id'];
        $promise = $db->querySingle("SELECT * FROM promises WHERE customer_id=$cid AND status='pending' AND promise_date >= '$today_str' ORDER BY promise_date ASC LIMIT 1", true);
        $pc['promise'] = $promise ?: null;
    }
    unset($pc);
}

if ($page === 'dashboard') {
    $collected_count = $db->querySingle("SELECT COUNT(DISTINCT customer_id) FROM collections WHERE strftime('%Y-%m',collected_date)='$display_month' AND amount>0");
    $total_amount    = $db->querySingle("SELECT COALESCE(SUM(amount),0) FROM collections WHERE strftime('%Y-%m',collected_date)='$display_month'");
    $pending_count   = count($pending_customers);
} else {
    $collected_count = 0;
    $total_amount    = 0;
    $pending_count   = 0;
}

if ($page === 'report') {
    $all_collections = $db->query("SELECT c.id,c.customer_id,cu.name AS cname,c.month_year,c.amount,c.collected_date,u.fullname AS collector
        FROM collections c JOIN customers cu ON c.customer_id=cu.id JOIN users u ON c.collected_by=u.id
        ORDER BY c.collected_date DESC LIMIT 500");
} else {
    $all_collections = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover">
<title>CyberNet ISP Billing</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#f0f4f8;--surface:#ffffff;--surface2:#f7fafc;--border:#e2e8f0;
  --text:#1a202c;--text-muted:#718096;--accent:#0ea5e9;--accent-dark:#0284c7;
  --success:#10b981;--danger:#ef4444;--warning:#f59e0b;--info:#6366f1;
  --nav-bg:#0f172a;--nav-text:#cbd5e1;--nav-active:#0ea5e9;
  --card-shadow:0 1px 3px rgba(0,0,0,.08),0 4px 16px rgba(0,0,0,.04);--radius:12px;
}
[data-theme="dark"] {
  --bg:#0d1117;--surface:#161b22;--surface2:#1c2230;--border:#30363d;
  --text:#e6edf3;--text-muted:#8b949e;--accent:#38bdf8;--accent-dark:#0ea5e9;
  --success:#34d399;--danger:#f87171;--warning:#fbbf24;--info:#818cf8;
  --nav-bg:#010409;--nav-text:#8b949e;--nav-active:#38bdf8;
  --card-shadow:0 1px 3px rgba(0,0,0,.4),0 4px 16px rgba(0,0,0,.3);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'IBM Plex Sans',sans-serif;font-size:14px;line-height:1.6;transition:background .25s,color .25s;overflow-x:hidden}
.app-wrapper{display:flex;min-height:100vh;flex-direction:column}
.topbar{background:var(--nav-bg);padding:0 20px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1000;border-bottom:1px solid rgba(255,255,255,.06);gap:12px}
.topbar-brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:#fff;flex-shrink:0}
.topbar-brand .brand-icon{width:32px;height:32px;background:var(--accent);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px}
.topbar-brand .brand-name{font-weight:700;font-size:15px;letter-spacing:.02em}
.topbar-brand .brand-name span{color:var(--accent)}
.nav-tabs-wrap{display:flex;overflow-x:auto;gap:2px;scrollbar-width:none;flex:1;justify-content:center}
.nav-tabs-wrap::-webkit-scrollbar{display:none}
.nav-tabs-wrap a{display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;color:var(--nav-text);text-decoration:none;font-size:13px;font-weight:500;white-space:nowrap;transition:all .15s;border:1px solid transparent}
.nav-tabs-wrap a:hover{background:rgba(255,255,255,.07);color:#fff}
.nav-tabs-wrap a.active{background:rgba(14,165,233,.15);color:var(--nav-active);border-color:rgba(14,165,233,.3)}
.nav-tabs-wrap a i{font-size:12px;opacity:.8}
.topbar-right{display:flex;align-items:center;gap:10px;flex-shrink:0}
.user-badge{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:4px 12px;font-size:12px;color:#cbd5e1;display:flex;align-items:center;gap:6px}
.user-badge .role{background:var(--accent);color:#fff;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700}
.btn-icon{width:34px;height:34px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:#cbd5e1;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;text-decoration:none;font-size:13px}
.btn-icon:hover{background:rgba(255,255,255,.12);color:#fff}
.main-content{flex:1;padding:20px;max-width:1600px;margin:0 auto;width:100%}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--card-shadow);overflow:visible;margin-bottom:16px}
.card-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;background:var(--surface)}
.card-header-title{font-weight:600;font-size:14px;display:flex;align-items:center;gap:8px}
.card-header-title i{color:var(--accent);font-size:13px}
.card-body{padding:16px 18px}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;display:flex;align-items:center;justify-content:space-between;box-shadow:var(--card-shadow);transition:transform .2s}
.stat-card:hover{transform:translateY(-2px)}
.stat-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)}
.stat-value{font-size:28px;font-weight:700;font-family:'IBM Plex Mono',monospace;line-height:1.1;margin-top:2px}
.stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.stat-blue{color:#3b82f6}.stat-icon-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.stat-green{color:#10b981}.stat-icon-green{background:rgba(16,185,129,.12);color:#10b981}
.stat-red{color:#ef4444}.stat-icon-red{background:rgba(239,68,68,.12);color:#ef4444}
.stat-teal{color:#0ea5e9}.stat-icon-teal{background:rgba(14,165,233,.12);color:#0ea5e9}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;position:relative}
@media(max-width:768px){
  .table-wrap::after{content:"";position:sticky;top:0;right:0;float:right;width:24px;height:100%;margin-left:-24px;background:linear-gradient(to right, transparent, rgba(0,0,0,.35));pointer-events:none;display:block}
  .table-wrap table{position:relative}
}
table{width:100%;border-collapse:collapse;font-size:13px}
thead tr{background:var(--surface2)}
thead th{padding:10px 12px;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);border-bottom:2px solid var(--border);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
tbody tr:hover{background:var(--surface2)}
tbody td{padding:10px 12px;vertical-align:middle}
.form-control,.form-select{background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-size:13px;font-family:'IBM Plex Sans',sans-serif;transition:border-color .15s,box-shadow .15s;width:100%}
.form-control:focus,.form-select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(14,165,233,.15)}
.form-label{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;display:block}
.input-group{display:flex;gap:8px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;border:1px solid transparent;transition:all .15s;text-decoration:none;white-space:nowrap}
.btn-primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-primary:hover{background:var(--accent-dark);border-color:var(--accent-dark)}
.btn-success{background:var(--success);color:#fff}
.btn-success:hover{filter:brightness(1.1)}
.btn-danger{background:var(--danger);color:#fff}
.btn-danger:hover{filter:brightness(1.1)}
.btn-warning{background:var(--warning);color:#000}
.btn-secondary{background:var(--surface2);color:var(--text);border-color:var(--border)}
.btn-secondary:hover{background:var(--border)}
.btn-whatsapp{background:#25D366;color:#fff}
.btn-whatsapp:hover{background:#128C7E}
.btn-sm{padding:4px 10px;font-size:12px;gap:4px}
.btn-xs{padding:2px 7px;font-size:11px;gap:3px;border-radius:6px}
.badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;gap:4px}
.badge-green{background:rgba(16,185,129,.15);color:var(--success)}
.badge-purple{background:rgba(168,85,247,.15);color:#a855f7}
.badge-red{background:rgba(239,68,68,.15);color:var(--danger)}
.badge-blue{background:rgba(59,130,246,.15);color:#3b82f6}
.badge-yellow{background:rgba(245,158,11,.15);color:var(--warning)}
.badge-gray{background:var(--surface2);color:var(--text-muted);border:1px solid var(--border)}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:2000;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)}
.modal-overlay.show{display:flex}
.modal-box{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.3);width:100%;max-width:600px;max-height:90vh;overflow-y:auto;animation:modalIn .2s ease}
.modal-box-lg{max-width:800px}
@keyframes modalIn{from{opacity:0;transform:translateY(16px) scale(.97)}to{opacity:1;transform:none}}
.modal-header-custom{padding:18px 20px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-header-custom h5{font-weight:700;font-size:16px;display:flex;align-items:center;gap:8px}
.modal-body-custom{padding:18px 20px}
.modal-close{width:28px;height:28px;border-radius:6px;border:none;background:var(--surface2);color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:14px}
.modal-close:hover{background:var(--danger);color:#fff}
.alert{padding:12px 16px;border-radius:10px;border:1px solid;font-size:13px;margin-bottom:14px;display:flex;align-items:flex-start;gap:10px}
.alert-info{background:rgba(99,102,241,.1);border-color:rgba(99,102,241,.3);color:var(--info)}
.alert-success{background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.3);color:var(--success)}
.alert-danger{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.3);color:var(--danger)}
.alert-warning{background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.3);color:var(--warning)}
.alert-close{margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;opacity:.6;font-size:16px;flex-shrink:0}
.alert-close:hover{opacity:1}
.search-wrap{position:relative}
.search-wrap input{padding-left:36px}
.search-wrap .search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px}
.search-results{position:absolute;top:100%;left:0;right:0;z-index:9999;background:var(--surface);border:1px solid var(--border);border-radius:10px;box-shadow:var(--card-shadow);max-height:320px;overflow-y:auto;margin-top:4px}
.search-result-item{padding:10px 14px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .1s;display:flex;align-items:center;justify-content:space-between}
.search-result-item:hover{background:var(--surface2)}
.search-result-item:last-child{border-bottom:none}
.wa-status-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px}
.wa-status-dot.connected{background:#25D366;box-shadow:0 0 8px #25D366}
.wa-status-dot.disconnected{background:var(--danger)}
.wa-status-dot.waiting{background:var(--warning);animation:blink 1s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
.qr-container{border:2px dashed var(--border);border-radius:12px;padding:20px;text-align:center;min-height:200px;display:flex;align-items:center;justify-content:center}
.qr-container img{max-width:240px;border-radius:8px}
.mono{font-family:'IBM Plex Mono',monospace}
.text-muted{color:var(--text-muted)}
.divider{border:none;border-top:1px solid var(--border);margin:14px 0}
.due-tag{font-family:'IBM Plex Mono',monospace;font-size:12px;background:rgba(239,68,68,.1);color:var(--danger);padding:2px 7px;border-radius:5px}
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--nav-bg)}
.login-card{background:#161b22;border:1px solid #30363d;border-radius:16px;padding:36px 32px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
.login-card .brand-row{text-align:center;margin-bottom:28px}
.login-card .brand-row .ico{width:56px;height:56px;background:var(--accent);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:22px;color:#fff;margin-bottom:12px}
.login-card .brand-row h2{color:#fff;font-size:20px;font-weight:700}
.login-card .brand-row p{color:#8b949e;font-size:13px}
.login-card .form-control{background:#0d1117;color:#e6edf3;border-color:#30363d}
.login-card .form-label{color:#8b949e}
.login-btn{background:var(--accent);color:#fff;width:100%;padding:10px;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;transition:background .15s}

.login-btn:hover{background:var(--accent-dark)}

/* Base Helpers for Responsive Tables */
.mobile-only-icon{display:none}
.mobile-action-label{display:none}
.cust-actions-wrap{display:inline-flex;gap:4px}
.mobile-card-actions{display:inline-flex;gap:4px}
.month-cb{width:18px;height:18px;cursor:pointer}

/* Payment & Billing History Modal Universal Styling */
#histModal .modal-box {
  background: var(--surface) !important;
  color: var(--text) !important;
  border: 1px solid var(--border) !important;
  box-shadow: 0 10px 30px rgba(0,0,0,.25) !important;
}
#histModal table {
  background: transparent !important;
  color: var(--text) !important;
}
#histModal table, #histModal td, #histModal th {
  color: var(--text) !important;
  border-color: var(--border) !important;
}
#histModal thead th {
  background: var(--surface2) !important;
  color: var(--text-muted) !important;
}

/* Reminder Toggle Buttons & Badges */
.btn-reminder-toggle {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  font-size: 11px;
  font-weight: 600;
  border-radius: 20px;
  cursor: pointer;
  transition: all .2s ease;
  border: 1px solid transparent;
}
.btn-reminder-toggle.is-active {
  background: rgba(16, 185, 129, 0.12);
  color: #10b981;
  border-color: rgba(16, 185, 129, 0.3);
}
.btn-reminder-toggle.is-active:hover {
  background: rgba(16, 185, 129, 0.22);
}
.btn-reminder-toggle.is-muted {
  background: rgba(239, 68, 68, 0.12);
  color: #ef4444;
  border-color: rgba(239, 68, 68, 0.3);
}
.btn-reminder-toggle.is-muted:hover {
  background: rgba(239, 68, 68, 0.22);
}

.badge-reminder-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 7px;
  font-size: 10px;
  font-weight: 700;
  border-radius: 12px;
  cursor: pointer;
  border: 1px solid transparent;
  transition: all .2s ease;
  flex-shrink: 0;
}
.badge-reminder-pill.is-active {
  background: rgba(16, 185, 129, 0.12);
  color: #10b981;
  border-color: rgba(16, 185, 129, 0.3);
}
.badge-reminder-pill.is-muted {
  background: rgba(239, 68, 68, 0.12);
  color: #ef4444;
  border-color: rgba(239, 68, 68, 0.3);
}

.btn-wa-muted {
  opacity: 0.65 !important;
  background: var(--surface2) !important;
  color: var(--text-muted) !important;
  border: 1px solid var(--border) !important;
}

/* Modal Switch Slider */
.switch-toggle {
  position: relative;
  display: inline-block;
  width: 44px;
  height: 24px;
}
.switch-toggle input {
  opacity: 0;
  width: 0;
  height: 0;
}
.switch-slider {
  position: absolute;
  cursor: pointer;
  inset: 0;
  background-color: #94a3b8;
  transition: .25s;
  border-radius: 24px;
}
.switch-slider:before {
  position: absolute;
  content: "";
  height: 18px;
  width: 18px;
  left: 3px;
  bottom: 3px;
  background-color: white;
  transition: .25s;
  border-radius: 50%;
  box-shadow: 0 1px 3px rgba(0,0,0,0.3);
}
.switch-toggle input:checked + .switch-slider {
  background-color: #10b981;
}
.switch-toggle input:checked + .switch-slider:before {
  transform: translateX(20px);
}
[data-theme="dark"] .switch-slider { background-color: #475569; }

/* Universal rule for hidden rows across all tables & viewports */
tr.row-hidden,
.row-hidden,
tr[style*="display: none"],
tr[style*="display:none"] {
  display: none !important;
}

/* Dark Theme Overrides */
[data-theme="dark"] .card,
[data-theme="dark"] .modal-box,
[data-theme="dark"] .search-results,
[data-theme="dark"] .search-result-item{background:var(--surface) !important;color:var(--text) !important;border-color:var(--border) !important}
[data-theme="dark"] table{color:var(--text)}
[data-theme="dark"] thead tr{background:var(--surface2)}
[data-theme="dark"] tbody tr:hover{background:var(--surface2)}
[data-theme="dark"] tbody td,[data-theme="dark"] thead th{border-color:var(--border);color:var(--text)}
[data-theme="dark"] .form-control,[data-theme="dark"] .form-select{background:var(--surface2) !important;color:var(--text) !important;border-color:var(--border) !important}
[data-theme="dark"] .btn-secondary{background:var(--surface2);color:var(--text);border-color:var(--border)}
[data-theme="dark"] .card-header{background:var(--surface);border-color:var(--border)}
[data-theme="dark"] .modal-header-custom{border-color:var(--border)}
[data-theme="dark"] .modal-close{background:var(--surface2);color:var(--text-muted)}
[data-theme="dark"] .login-wrap{background:var(--nav-bg)}

/* Animations & Filters */
@keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
.toast-msg { animation: slideInRight 0.25s ease-out; }
.filter-pill { padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; cursor: pointer; border: 1px solid var(--border); background: var(--surface2); color: var(--text-muted); transition: all .15s; display:inline-flex; align-items:center; justify-content:center; }
.filter-pill:hover, .filter-pill.active { background: var(--primary); color: #fff; border-color: var(--primary); }

/* ============================================================ */
/* RESPONSIVE LAYOUT & PHONE USABILITY                          */
/* ============================================================ */

/* Global Touch & Tap Optimization */
a, button, input, select, textarea, .filter-pill, .btn {
  touch-action: manipulation;
  -webkit-tap-highlight-color: transparent;
}

/* Floating Scroll-to-Top Button */
.btn-scroll-top {
  position: fixed;
  bottom: max(20px, env(safe-area-inset-bottom, 20px));
  right: 18px;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: var(--accent);
  color: #fff;
  border: none;
  box-shadow: 0 4px 16px rgba(0,0,0,.25);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  cursor: pointer;
  z-index: 998;
  opacity: 0;
  pointer-events: none;
  transition: opacity .25s, transform .25s;
  transform: translateY(10px);
}
.btn-scroll-top.visible {
  opacity: 0.92;
  pointer-events: auto;
  transform: translateY(0);
}
.btn-scroll-top:active {
  transform: scale(0.95);
}

/* Desktop Defaults for Responsive Card Classes */
.bal-metric-label { display: none; }
.bal-rank-pill { display: none; }
.bal-mobile-status { display: none; }
.bal-btn-call { display: none; }
.vac-card-actions { display: inline-flex; gap: 4px; align-items: center; }
.report-card-actions { display: inline-flex; gap: 4px; align-items: center; }
.vac-metric-label, .report-metric-label { display: none; }

/* Filter Pill Bar */
.bal-filter-wrap {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
  padding: 12px 14px 8px 14px;
}
.bal-pills-bar {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

/* Topbar Tablet & Mobile Wrapping */
@media(max-width: 860px) {
  .topbar {
    padding: 8px 12px;
    gap: 8px;
    height: auto;
    flex-wrap: wrap;
  }
  .topbar-brand { order: 1; }
  .topbar-right { order: 2; margin-left: auto; }
  .nav-tabs-wrap {
    order: 3;
    width: 100%;
    flex: 0 0 100%;
    border-top: 1px solid rgba(255,255,255,.08);
    padding: 8px 0 4px 0;
    justify-content: flex-start;
    margin-top: 2px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    gap: 5px;
    scrollbar-width: none;
    -ms-overflow-style: none;
  }
  .nav-tabs-wrap::-webkit-scrollbar {
    display: none;
  }
  .nav-tabs-wrap a {
    padding: 8px 13px;
    font-size: 13px;
    min-height: 38px;
    flex-shrink: 0;
  }
  .nav-tabs-wrap a.active {
    background: var(--accent) !important;
    color: #fff !important;
    font-weight: 600;
  }
  .user-badge span:first-of-type { display: none; }
  .user-badge .role { display: inline-block; }
}

/* Smartphone & Tablet Common Enhancements (max-width: 768px) */
@media(max-width: 768px) {
  /* Safe Area for iPhone Home Indicator */
  .app-wrapper {
    padding-bottom: max(20px, env(safe-area-inset-bottom, 20px));
  }

  /* Prevent iOS Safari Auto-Zoom on form input focus (must be >= 16px) */
  input, select, textarea, .form-control, .form-select {
    font-size: 16px !important;
  }
  .form-control, .form-select {
    min-height: 44px;
  }
  
  /* Touch-friendly target heights */
  .btn {
    min-height: 42px;
  }
  .btn-xs {
    min-height: 38px;
    padding: 6px 10px;
    font-size: 12px;
    gap: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
  .btn-sm {
    min-height: 42px;
    padding: 8px 14px;
    font-size: 13px;
  }
  .month-cb {
    width: 24px;
    height: 24px;
  }

  .main-content { padding: 10px 8px; }
  .card { border-radius: 12px; margin-bottom: 12px; }
  .card-body { padding: 12px; }
  .card-header { padding: 12px 14px; flex-wrap: wrap; gap: 8px; }
  
  /* --- Dashboard Stat KPI Cards Grid (Aligned on iPhone XR / Mobile) --- */
  .stats-grid {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 8px !important;
    margin-bottom: 12px !important;
  }
  .stat-card {
    padding: 10px 12px !important;
    border-radius: 10px !important;
    min-height: 72px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    box-sizing: border-box !important;
  }
  .stat-label {
    font-size: 11px !important;
    white-space: nowrap !important;
  }
  .stat-value {
    font-size: 18px !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
  }
  .stat-icon {
    width: 36px !important;
    height: 36px !important;
    font-size: 14px !important;
    flex-shrink: 0 !important;
  }

  /* Filter Month on Dashboard */
  .filter-month-form {
    display: flex !important;
    gap: 8px !important;
    width: 100% !important;
  }
  .filter-month-form select {
    flex: 1 !important;
    max-width: 100% !important;
    min-width: 0 !important;
  }
  .filter-month-form button {
    flex-shrink: 0 !important;
  }

  /* Staff Collections Table on Dashboard (Collector Names Always Visible!) */
  .staff-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    width: 100%;
  }
  .staff-table {
    width: 100% !important;
    border-collapse: collapse !important;
    min-width: 320px;
  }
  .staff-table th, .staff-table td {
    padding: 10px 8px !important;
    font-size: 13px !important;
    vertical-align: middle !important;
  }
  .staff-name {
    font-size: 13px !important;
    font-weight: 700 !important;
    color: var(--text) !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
  }
  
  .wa-grid { grid-template-columns: 1fr !important; }
  .advance-form { grid-template-columns: 1fr !important; }
  .advance-form button { width: 100%; min-height: 44px; }
  
  /* Modal Box on Mobile */
  .modal-overlay { padding: 8px !important; }
  .modal-box {
    margin: 0 auto !important;
    width: calc(100% - 12px) !important;
    max-height: 92vh !important;
    border-radius: 14px !important;
  }
  .modal-header-custom {
    padding: 12px 14px !important;
  }
  .modal-close {
    width: 40px !important;
    height: 40px !important;
    font-size: 24px !important;
  }
  .modal-body-custom {
    padding: 14px !important;
    -webkit-overflow-scrolling: touch;
  }
  .modal-box form button[type=submit] {
    width: 100%;
    min-height: 46px;
    justify-content: center;
    font-size: 15px;
    font-weight: 600;
  }

  /* Form Groups on Mobile */
  .input-group { flex-wrap: wrap; }
  .input-group > div { min-width: 100% !important; }
  .mobile-input-row { display: flex; gap: 6px; width: 100%; }
  .mobile-input-row select { flex: 0 0 100px !important; }
  .mobile-input-row input { flex: 1 !important; min-width: 0 !important; }

  /* Login Card on Mobile */
  .login-wrap { padding: 14px; }
  .login-card { padding: 24px 18px; border-radius: 14px; }

  /* Disable Sticky Float Gradient on table-wrap */
  .table-wrap::after {
    display: none !important;
    content: none !important;
  }
  .table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }

  /* Filter Pills on Mobile */
  .filter-pill {
    min-height: 38px;
    padding: 7px 14px;
    font-size: 12px;
  }
  .bal-filter-wrap {
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
    padding: 10px 10px 6px 10px;
  }
  .bal-pills-bar {
    overflow-x: auto;
    flex-wrap: nowrap;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 4px;
    scrollbar-width: none;
  }
  .bal-pills-bar::-webkit-scrollbar {
    display: none;
  }
  .bal-pills-bar .filter-pill {
    flex-shrink: 0;
  }

  /* Pending Filter Bar (Collections Page) */
  .pending-filter-bar {
    display: flex !important;
    flex-direction: column !important;
    gap: 8px !important;
    width: 100% !important;
  }
  .pending-filter-bar input {
    width: 100% !important;
    min-width: 0 !important;
  }
  .pending-pills-bar {
    display: flex !important;
    gap: 6px !important;
    width: 100% !important;
  }
  .pending-pills-bar .filter-pill {
    flex: 1 !important;
    text-align: center !important;
    justify-content: center !important;
    min-height: 36px !important;
    padding: 6px 4px !important;
    font-size: 12px !important;
  }

  /* --- RESPONSIVE COLLECTIONS (PENDING BILLS) CARD VIEW --- */
  .responsive-coll-table table, 
  .responsive-coll-table thead, 
  .responsive-coll-table tbody {
    display: block !important;
    width: 100% !important;
  }
  .responsive-coll-table thead tr {
    position: absolute !important;
    top: -9999px !important;
    left: -9999px !important;
  }
  .responsive-coll-table tbody tr {
    display: block;
    width: 100% !important;
    margin-bottom: 12px !important;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    border-radius: 12px !important;
    padding: 12px 14px !important;
    box-shadow: 0 2px 8px rgba(0,0,0,.04) !important;
    box-sizing: border-box !important;
  }
  .responsive-coll-table tbody td {
    display: block !important;
    border: none !important;
    padding: 2px 0 !important;
    white-space: normal !important;
    box-sizing: border-box !important;
  }
  .responsive-coll-table .coll-idx-cell { display: none !important; }
  .responsive-coll-table .coll-desktop-due { display: none !important; }

  .coll-name-row {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 8px !important;
    margin-bottom: 4px !important;
  }
  .coll-name-left {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    min-width: 0 !important;
    flex: 1 !important;
  }
  .coll-rank-pill {
    display: inline-flex !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    padding: 2px 6px !important;
    border-radius: 6px !important;
    background: var(--surface2) !important;
    color: var(--text-muted) !important;
    border: 1px solid var(--border) !important;
    flex-shrink: 0 !important;
  }
  .coll-cust-name {
    font-size: 15px !important;
    font-weight: 700 !important;
    color: var(--text) !important;
    word-break: break-word !important;
    line-height: 1.25 !important;
  }
  .coll-mobile-due {
    display: inline-flex !important;
    font-size: 15px !important;
    font-weight: 700 !important;
    font-family: 'IBM Plex Mono', monospace !important;
    color: var(--danger) !important;
    background: rgba(239,68,68,.08) !important;
    border: 1px solid rgba(239,68,68,.18) !important;
    padding: 3px 8px !important;
    border-radius: 6px !important;
    flex-shrink: 0 !important;
    white-space: nowrap !important;
  }
  .coll-promise-wrap {
    margin: 3px 0 5px 0 !important;
  }
  .coll-phone-link {
    color: var(--accent) !important;
    text-decoration: none !important;
    font-weight: 600 !important;
  }
  .coll-unpaid-wrap {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 5px !important;
    margin: 6px 0 !important;
    padding-top: 6px !important;
    border-top: 1px dashed var(--border) !important;
  }
  .coll-unpaid-wrap .due-tag {
    font-size: 11px !important;
  }

  /* 3 Action Buttons Equal Width Grid (iPhone XR Touch Friendly) */
  .mobile-card-actions {
    display: grid !important;
    grid-template-columns: 1fr 1fr 1fr !important;
    gap: 6px !important;
    margin-top: 8px !important;
    padding-top: 10px !important;
    border-top: 1px solid var(--border) !important;
    width: 100% !important;
  }
  .mobile-card-actions .btn {
    min-height: 40px !important;
    padding: 6px 4px !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 100% !important;
    box-sizing: border-box !important;
  }

  /* --- RESPONSIVE CUSTOMERS DIRECTORY CARD VIEW --- */
  .responsive-cust-table table, 
  .responsive-cust-table thead, 
  .responsive-cust-table tbody {
    display: block !important;
    width: 100% !important;
  }
  .responsive-cust-table thead tr {
    position: absolute !important;
    top: -9999px !important;
    left: -9999px !important;
  }
  .responsive-cust-table tbody tr {
    display: block;
    width: 100% !important;
    margin-bottom: 12px !important;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    border-radius: 12px !important;
    padding: 12px 14px !important;
    box-shadow: 0 2px 8px rgba(0,0,0,.04) !important;
    box-sizing: border-box !important;
  }
  .responsive-cust-table tbody td {
    display: block !important;
    border: none !important;
    padding: 2px 0 !important;
    white-space: normal !important;
    box-sizing: border-box !important;
  }
  .responsive-cust-table .cust-idx-cell { display: none !important; }
  .responsive-cust-table .cust-desktop-fee { display: none !important; }
  .responsive-cust-table .cust-wa-cell { display: none !important; }

  .cust-top-row {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 8px !important;
    margin-bottom: 3px !important;
  }
  .cust-name-left {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    min-width: 0 !important;
    flex: 1 !important;
  }
  .cust-rank-pill {
    display: inline-flex !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    padding: 2px 6px !important;
    border-radius: 6px !important;
    background: var(--surface2) !important;
    color: var(--text-muted) !important;
    border: 1px solid var(--border) !important;
    flex-shrink: 0 !important;
  }
  .cust-card-name {
    font-size: 15px !important;
    font-weight: 700 !important;
    color: var(--text) !important;
    word-break: break-word !important;
  }
  .cust-mobile-fee {
    display: inline-flex !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    flex-shrink: 0 !important;
  }
  .cust-phone-link {
    color: var(--accent) !important;
    font-weight: 600 !important;
    text-decoration: none !important;
  }
  .cust-actions-cell {
    margin-top: 8px !important;
    padding-top: 10px !important;
    border-top: 1px solid var(--border) !important;
  }
  .cust-actions-wrap {
    display: grid !important;
    grid-template-columns: 1.2fr 1fr 0.8fr !important;
    gap: 6px !important;
    width: 100% !important;
  }
  .cust-actions-wrap .btn {
    min-height: 40px !important;
    padding: 6px 4px !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 100% !important;
    box-sizing: border-box !important;
  }
  .cust-mob-wa {
    display: inline-flex !important;
  }

  /* --- RESPONSIVE BALANCE SHEET CARD VIEW (3-Column Grid) --- */
  .responsive-bal-table table, 
  .responsive-bal-table thead, 
  .responsive-bal-table tbody {
    display: block !important;
    width: 100% !important;
  }
  .responsive-bal-table thead tr {
    position: absolute !important;
    top: -9999px !important;
    left: -9999px !important;
  }
  .responsive-bal-table tbody tr:not(.table-total) {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr !important;
    gap: 6px !important;
    margin-bottom: 12px !important;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    border-radius: 12px !important;
    padding: 12px 14px !important;
    box-shadow: 0 2px 8px rgba(0,0,0,.04) !important;
    box-sizing: border-box !important;
  }
  .responsive-bal-table tbody tr[data-status="owing"] {
    border-left: 4px solid var(--danger) !important;
  }
  .responsive-bal-table tbody tr[data-status="settled"] {
    border-left: 4px solid var(--info) !important;
  }
  .responsive-bal-table tbody tr[data-status="credit"] {
    border-left: 4px solid var(--success) !important;
  }

  .responsive-bal-table .bal-idx-cell { display: none !important; }
  .responsive-bal-table .bal-desktop-status { display: none !important; }

  .responsive-bal-table .bal-cust-cell {
    grid-column: 1 / -1 !important;
    border: none !important;
    padding: 0 !important;
  }
  .bal-mobile-top {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    margin-bottom: 4px !important;
    width: 100% !important;
  }
  .bal-rank-pill {
    display: inline-flex !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    padding: 2px 7px !important;
    border-radius: 6px !important;
    background: var(--surface2) !important;
    color: var(--text-muted) !important;
    border: 1px solid var(--border) !important;
    flex-shrink: 0 !important;
  }
  .bal-cust-name {
    font-size: 15px !important;
    font-weight: 700 !important;
    color: var(--text) !important;
    flex: 1 !important;
    min-width: 0 !important;
    word-break: break-word !important;
  }
  .bal-mobile-status {
    display: inline-flex !important;
    font-size: 11px !important;
    padding: 3px 8px !important;
    font-weight: 700 !important;
    flex-shrink: 0 !important;
  }
  .bal-cust-addr {
    font-size: 12px !important;
    color: var(--text-muted) !important;
    margin-bottom: 4px !important;
  }
  .responsive-bal-table .bal-phone-cell {
    grid-column: 1 / -1 !important;
    border: none !important;
    border-bottom: 1px dashed var(--border) !important;
    padding: 2px 0 6px 0 !important;
    margin-bottom: 4px !important;
    font-size: 13px !important;
  }
  .bal-phone-link {
    color: var(--accent) !important;
    font-weight: 600 !important;
  }

  .responsive-bal-table .bal-metric-cell {
    display: flex !important;
    flex-direction: column !important;
    justify-content: center !important;
    background: var(--surface2) !important;
    padding: 8px 4px !important;
    border-radius: 8px !important;
    text-align: center !important;
    border: 1px solid var(--border) !important;
    box-sizing: border-box !important;
  }
  .bal-metric-label {
    display: block !important;
    font-size: 10px !important;
    text-transform: uppercase !important;
    letter-spacing: .04em !important;
    color: var(--text-muted) !important;
    margin-bottom: 2px !important;
  }
  .bal-metric-cell strong {
    font-size: 12px !important;
    font-family: 'IBM Plex Mono', monospace !important;
    word-break: break-all !important;
  }

  .responsive-bal-table .bal-owed-cell { grid-column: 1 !important; }
  .responsive-bal-table .bal-paid-cell { grid-column: 2 !important; }
  .responsive-bal-table .bal-bal-cell  { grid-column: 3 !important; }

  .responsive-bal-table .bal-action-cell {
    grid-column: 1 / -1 !important;
    border: none !important;
    padding: 0 !important;
    margin-top: 4px !important;
  }
  .bal-card-actions {
    display: grid !important;
    grid-template-columns: 2fr 1fr !important;
    gap: 6px !important;
    margin-top: 6px !important;
    padding-top: 8px !important;
    border-top: 1px solid var(--border) !important;
    width: 100% !important;
  }
  .bal-card-actions .bal-btn-wa, .bal-card-actions .bal-btn-call {
    min-height: 40px !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 100% !important;
    box-sizing: border-box !important;
  }

  /* Table Total Card on Mobile */
  .responsive-bal-table tbody tr.table-total {
    margin-top: 16px !important;
    background: var(--surface2) !important;
    border: 2px solid var(--border) !important;
    border-radius: 12px !important;
    padding: 14px !important;
    display: block;
  }
  .responsive-bal-table tbody tr.table-total td {
    display: block !important;
    padding: 4px 0 !important;
    border: none !important;
  }
  .responsive-bal-table tbody tr.table-total td:first-child {
    font-size: 15px !important;
    font-weight: 700 !important;
    border-bottom: 1px solid var(--border) !important;
    padding-bottom: 8px !important;
    margin-bottom: 6px !important;
  }

  /* Responsive Vacation Holds Card View */
  .responsive-vac-table table, 
  .responsive-vac-table thead, 
  .responsive-vac-table tbody {
    display: block !important;
    width: 100% !important;
  }
  .responsive-vac-table thead tr {
    position: absolute !important;
    top: -9999px !important;
    left: -9999px !important;
  }
  .responsive-vac-table tbody tr {
    display: block;
    margin-bottom: 12px !important;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    border-radius: 12px !important;
    padding: 14px !important;
    box-shadow: 0 2px 6px rgba(0,0,0,.04) !important;
  }
  .responsive-vac-table tbody td {
    display: block !important;
    border: none !important;
    padding: 3px 0 !important;
    white-space: normal !important;
  }
  .responsive-vac-table tbody td:first-child { display: none !important; }
  .vac-metric-label {
    display: inline-block !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    color: var(--text-muted) !important;
    text-transform: uppercase !important;
    margin-right: 6px !important;
  }
  .vac-card-actions {
    display: flex !important;
    gap: 8px !important;
    margin-top: 10px !important;
    padding-top: 10px !important;
    border-top: 1px solid var(--border) !important;
    width: 100% !important;
  }
  .vac-card-actions form {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 6px !important;
    width: 100% !important;
  }
  .vac-card-actions input[type=date] {
    flex: 1 !important;
    min-height: 40px !important;
  }
  .vac-card-actions .btn {
    min-height: 40px !important;
    justify-content: center !important;
    font-weight: 600 !important;
  }

  /* Live Search Dropdown on Mobile */
  .search-result-item {
    flex-direction: column !important;
    align-items: stretch !important;
    gap: 8px !important;
  }
  .search-result-item > div:last-child {
    display: grid !important;
    grid-template-columns: 1fr 44px !important;
    width: 100% !important;
  }
  .search-result-item > div:last-child .btn {
    min-height: 40px !important;
    justify-content: center !important;
  }

  .mobile-only-icon { display: inline-block !important; opacity: 0.6 !important; font-size: 11px !important; margin-right: 4px !important; }
  .mobile-action-label { display: inline !important; }

  /* Payment History Modal Dark Mode Contrast */
  #histModal .modal-box {
    background: var(--surface) !important;
    color: var(--text) !important;
  }
  #histModal table {
    background: transparent !important;
    color: var(--text) !important;
  }
  #histModal table, #histModal td, #histModal th {
    color: var(--text) !important;
    border-color: var(--border) !important;
  }
  #histModal thead th {
    background: var(--surface2) !important;
    color: var(--text-muted) !important;
  }

  /* Universal rule to ensure hidden rows stay hidden on mobile */
  tr.row-hidden,
  .row-hidden,
  tr[style*="display: none"],
  tr[style*="display:none"],
  .responsive-coll-table tbody tr.row-hidden,
  .responsive-cust-table tbody tr.row-hidden,
  .responsive-bal-table tbody tr.row-hidden,
  .responsive-vac-table tbody tr.row-hidden {
    display: none !important;
  }

  /* Empty state / no-match row styling on mobile */
  #custNoMatchRow,
  #pendingNoMatchRow,
  #balanceNoMatchRow,
  tr.no-match-row {
    display: block !important;
    width: 100% !important;
    background: var(--surface) !important;
    border: 1px dashed var(--border) !important;
    border-radius: 12px !important;
    padding: 18px 14px !important;
    box-sizing: border-box !important;
    text-align: center !important;
  }
  #custNoMatchRow.row-hidden,
  #pendingNoMatchRow.row-hidden,
  #balanceNoMatchRow.row-hidden,
  tr.no-match-row.row-hidden {
    display: none !important;
  }
  #custNoMatchRow td,
  #pendingNoMatchRow td,
  #balanceNoMatchRow td,
  tr.no-match-row td {
    display: block !important;
    border: none !important;
    padding: 0 !important;
    text-align: center !important;
  }
}

/* Small Smartphone Refinements (max-width: 480px) */
@media(max-width: 480px) {
  .topbar-brand .brand-name { font-size: 14px; }
  .card-header-title { font-size: 13px; }
}

/* Desktop styles for rank pills & mobile due tags */
@media (min-width: 769px) {
  .coll-rank-pill, .coll-mobile-due { display: none !important; }
  .cust-rank-pill, .cust-mobile-fee, .cust-mob-wa { display: none !important; }
  .mobile-only-icon { display: none !important; }
}
</style>
</head>
<body>
<?php if (!isLoggedIn()): ?>
<div class="login-wrap">
  <div class="login-card">
    <div class="brand-row">
      <div class="ico"><i class="fas fa-wifi"></i></div>
      <h2>CyberNet ISP</h2>
      <p>Billing Management Portal</p>
    </div>
    <?php if (isset($login_error)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= $login_error ?></div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="login">
      <div style="margin-bottom:14px">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control" placeholder="Enter username" required autofocus>
      </div>
      <div style="margin-bottom:20px">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" placeholder="Enter password" required>
      </div>
      <button type="submit" class="login-btn"><i class="fas fa-sign-in-alt"></i> Sign In</button>
    </form>
  </div>
</div>
<?php else: ?>
<div class="app-wrapper">
<nav class="topbar">
  <a href="?page=dashboard" class="topbar-brand">
    <div class="brand-icon"><i class="fas fa-wifi"></i></div>
    <div class="brand-name">Cyber<span>Net</span></div>
  </a>
  <div class="nav-tabs-wrap">
    <a href="?page=dashboard"   class="<?= $page=='dashboard'  ?'active':'' ?>"><i class="fas fa-chart-bar"></i> Dashboard</a>
    <a href="?page=collections" class="<?= $page=='collections'?'active':'' ?>"><i class="fas fa-hand-holding-usd"></i> Collections</a>
    <a href="?page=customers"   class="<?= $page=='customers'  ?'active':'' ?>"><i class="fas fa-users"></i> Customers</a>
    <a href="?page=vacations"   class="<?= $page=='vacations'  ?'active':'' ?>"><i class="fas fa-umbrella-beach"></i> Vacations</a>
    <a href="?page=files"       class="<?= $page=='files'      ?'active':'' ?>"><i class="fas fa-film"></i> Movies</a>
    <a href="?page=report"      class="<?= $page=='report'     ?'active':'' ?>"><i class="fas fa-file-invoice"></i> Report</a>
    <a href="?page=balance"     class="<?= $page=='balance'    ?'active':'' ?>"><i class="fas fa-scale-balanced"></i> Balance Sheet</a>
    <a href="whatsapp_page.php"    class="<?= $page=='whatsapp'   ?'active':'' ?>"><i class="fab fa-whatsapp"></i> WhatsApp</a>
    <?php if (isMaster()): ?>
    <a href="?page=audit"       class="<?= $page=='audit'      ?'active':'' ?>"><i class="fas fa-history"></i> Audit</a>
    <a href="?page=admins"      class="<?= $page=='admins'     ?'active':'' ?>"><i class="fas fa-user-shield"></i> Admins</a>
    <?php endif; ?>
  </div>
  <div class="topbar-right">
    <button class="btn-icon" onclick="toggleTheme()" title="Toggle dark mode"><i class="fas fa-moon" id="theme-icon"></i></button>
    <div class="user-badge">
      <i class="fas fa-circle" style="font-size:7px;color:var(--success)"></i>
      <span><?= htmlspecialchars($_SESSION['fullname']) ?></span>
      <span class="role"><?= $_SESSION['role'] ?></span>
    </div>
    <a href="?logout=1" class="btn-icon" title="Logout" style="color:#f87171"><i class="fas fa-sign-out-alt"></i></a>
  </div>
</nav>
<div class="main-content">
<?php if (isset($_SESSION['msg'])): ?>
<div class="alert alert-info" id="top-alert">
  <i class="fas fa-info-circle"></i>
  <span><?= htmlspecialchars($_SESSION['msg']) ?></span>
  <button class="alert-close" onclick="this.parentElement.remove()">×</button>
</div>
<?php unset($_SESSION['msg']); endif; ?>
<div id="toastContainer" style="position:fixed;top:20px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px;pointer-events:none"></div>
<?php if (isset($_SESSION['last_collection'])): $lc=$_SESSION['last_collection']; ?>
<div class="alert alert-success" style="display:flex;flex-direction:column;gap:8px">
  <div style="display:flex;align-items:center;gap:8px">
    <i class="fas fa-check-circle" style="font-size:18px"></i>
    <span><strong><?= htmlspecialchars($lc['name']) ?></strong> — <?= $lc['amount'] ?> SAR collected by <?= htmlspecialchars($lc['collector']) ?> on <?= $lc['datetime'] ?></span>
    <button class="alert-close" onclick="this.parentElement.parentElement.remove()" style="margin-left:auto">×</button>
  </div>
  <?php if (!empty($lc['receipt'])): 
    $safe_phone = preg_replace('/[^0-9]/','', $lc['mobile'] ?? '');
    if (!preg_match('/^966/', $safe_phone)) $safe_phone = '966' . ltrim($safe_phone, '0');
  ?>
  <div style="display:flex;gap:8px;margin-top:4px;flex-wrap:wrap">
    <button type="button" class="btn btn-xs btn-secondary" onclick="copyToClipboard(<?= json_encode($lc['receipt']) ?>)"><i class="fas fa-copy"></i> Copy Receipt Text</button>
    <a href="https://wa.me/<?= $safe_phone ?>?text=<?= urlencode($lc['receipt']) ?>" target="_blank" class="btn btn-xs btn-whatsapp"><i class="fab fa-whatsapp"></i> Open in WhatsApp Web</a>
  </div>
  <?php endif; ?>
</div>
<?php unset($_SESSION['last_collection']); endif; ?>

<?php if ($page === 'dashboard'): ?>
<div class="stats-grid">
  <div class="stat-card">
    <div><div class="stat-label">Total Customers</div><div class="stat-value stat-blue"><?= $total_customers ?></div></div>
    <div class="stat-icon stat-icon-blue"><i class="fas fa-users"></i></div>
  </div>
  <div class="stat-card">
    <div><div class="stat-label">Paid <?= date('M', strtotime($display_month)) ?></div><div class="stat-value stat-green"><?= $collected_count ?></div></div>
    <div class="stat-icon stat-icon-green"><i class="fas fa-check-circle"></i></div>
  </div>
  <div class="stat-card">
    <div><div class="stat-label">Pending <?= date('M', strtotime($display_month)) ?></div><div class="stat-value stat-red"><?= $pending_count ?></div></div>
    <div class="stat-icon stat-icon-red"><i class="fas fa-clock"></i></div>
  </div>
  <div class="stat-card">
    <div><div class="stat-label">Collected <?= date('M', strtotime($display_month)) ?></div><div class="stat-value stat-teal"><?= number_format($total_amount,2) ?></div></div>
    <div class="stat-icon stat-icon-teal"><i class="fas fa-money-bill-wave"></i></div>
  </div>
</div>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-calendar-alt"></i> Filter Month</div></div>
  <div class="card-body">
    <form method="get" class="filter-month-form" style="display:flex;gap:10px;align-items:center">
      <input type="hidden" name="page" value="dashboard">
      <select name="stats_month" class="form-select" style="max-width:240px">
        <?php for($i=0;$i<12;$i++){$m=date('Y-m',strtotime("-$i months"));$sel=($m==$display_month)?'selected':'';echo "<option value=\"$m\" $sel>".date('F Y',strtotime($m))."</option>";}?>
      </select>
      <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Show</button>
    </form>
  </div>
</div>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-users"></i> Collections by Staff — <?= date("F Y",strtotime($display_month)) ?></div></div>
  <div class="table-wrap staff-table-wrap">
    <table class="staff-table">
      <thead><tr><th>Collector Name</th><th style="text-align:center">Records</th><th style="text-align:right">Total Collected</th><th style="text-align:right">Avg / Record</th></tr></thead>
      <tbody>
      <?php $staff=$db->query("SELECT id,fullname FROM users ORDER BY fullname");$gt=0;$gc=0;
      while($s=$staff->fetchArray(SQLITE3_ASSOC)){
        $t=(float)$db->querySingle("SELECT COALESCE(SUM(amount),0) FROM collections WHERE strftime('%Y-%m',collected_date)='$display_month' AND collected_by={$s['id']}");
        $cnt=(int)$db->querySingle("SELECT COUNT(*) FROM collections WHERE strftime('%Y-%m',collected_date)='$display_month' AND collected_by={$s['id']}");
        $avg=$cnt>0?round($t/$cnt,2):0;$gt+=$t;$gc+=$cnt;
        echo "<tr><td><strong class='staff-name'><i class='fas fa-user-circle' style='color:var(--accent);margin-right:6px'></i>".htmlspecialchars($s['fullname'])."</strong></td><td style='text-align:center'><span class='badge badge-blue'>$cnt</span></td><td style='text-align:right'><span class='mono' style='color:var(--success);font-weight:700'>".number_format($t,2)." SAR</span></td><td style='text-align:right'><span class='mono'>".number_format($avg,2)." SAR</span></td></tr>";
      }?>
      <tr style="background:var(--surface2);font-weight:700">
        <td><strong class='staff-name'><i class='fas fa-calculator' style='margin-right:6px'></i>TOTAL</strong></td><td style='text-align:center'><span class="badge badge-gray"><?= $gc ?></span></td>
        <td style="text-align:right"><span class="mono" style="color:var(--accent);font-weight:700"><?= number_format($gt,2) ?> SAR</span></td>
        <td style="text-align:right"><span class="mono"><?= $gc>0?number_format($gt/$gc,2):0 ?> SAR</span></td>
      </tr>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-file-invoice-dollar"></i> Collection Report (Custom Date Range)</div></div>
  <div class="card-body">
    <form method="get" target="_blank" action="report_print.php" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <div>
        <label style="display:block;font-size:12px;margin-bottom:4px;color:var(--text-muted)">From Date</label>
        <input type="date" name="from" class="form-control" required value="<?= date('Y-m-01') ?>">
      </div>
      <div>
        <label style="display:block;font-size:12px;margin-bottom:4px;color:var(--text-muted)">To Date</label>
        <input type="date" name="to" class="form-control" required value="<?= date('Y-m-d') ?>">
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-file-pdf"></i> Generate Report / PDF</button>
    </form>
    <div style="font-size:11px;color:var(--text-muted);margin-top:8px"><i class="fas fa-info-circle"></i> Opens a printable report in a new tab. Use your browser's Print → Save as PDF to download.</div>
  </div>
</div>
<?php elseif ($page === 'collections'): ?>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-search"></i> Search Customer</div></div>
  <div class="card-body">
    <div class="search-wrap">
      <i class="fas fa-search search-icon"></i>
      <input type="search" id="collSearch" class="form-control" placeholder="Search customer (name, mobile, building, room)…" autocomplete="off">
      <div class="search-results" id="collResults" style="display:none"></div>
    </div>
  </div>

</div>
<div class="card" style="margin-bottom:20px;border-left:4px solid var(--info)">
  <div class="card-header" style="background:rgba(59,130,246,.05)">
    <div class="card-header-title"><i class="fas fa-plus-circle"></i> Record Advance Payment</div>
  </div>
  <div class="card-body">
    <form method="post" class="advance-form" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:end">
      <input type="hidden" name="action" value="allocate_advance">
      <div>
        <label style="font-size:12px;font-weight:600;color:var(--text-muted)">Customer</label>
        <div style="position:relative">
          <input type="text" id="advCustSearch" class="form-control" placeholder="Type name, mobile, room… (min 2 chars)" autocomplete="off" style="font-size:13px">
          <input type="hidden" name="customer_id" id="advCustId" required>
          <div id="advCustSug" class="search-results" style="position:absolute;top:100%;left:0;right:0;max-height:240px;overflow-y:auto;z-index:9999;display:none"></div>
        </div>
      </div>
      <div>
        <label style="font-size:12px;font-weight:600;color:var(--text-muted)">Advance Amount (SAR)</label>
        <input type="number" name="advance_amount" class="form-control" placeholder="e.g., 780" required min="1" step="1" style="font-size:13px">
      </div>
      <div style="text-align:center;font-size:12px;color:var(--text-muted)">
        <strong>Auto-splits into 30 SAR monthly payments</strong>
      </div>
      <button type="submit" class="btn btn-info"><i class="fas fa-check"></i> Allocate</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-clock"></i> Pending Bills (oldest first)</div>
    <span class="badge badge-red" id="pendingBadgeCount"><?= count($pending_customers) ?> customers</span>
  </div>
  <div style="padding: 12px 14px 4px 14px">
    <div class="pending-filter-bar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
      <input type="search" id="pendingFilterInput" class="form-control" placeholder="🔍 Search pending (name, mobile, building, room)..." style="flex:1;min-width:200px;font-size:13px" oninput="filterPendingTable()" onsearch="filterPendingTable()">
      <div class="pending-pills-bar" style="display:flex;gap:6px;flex-wrap:wrap">
        <button type="button" class="filter-pill active" onclick="setPendingFilter('all', this)">All</button>
        <button type="button" class="filter-pill" onclick="setPendingFilter('promise', this)">🤝 Promised</button>
        <button type="button" class="filter-pill" onclick="setPendingFilter('multi', this)">⚠️ 2+ Months</button>
      </div>
    </div>
  </div>
  <div class="table-wrap responsive-coll-table">
    <table id="pendingTable">
      <thead><tr><th>#</th><th>Customer</th><th>Mobile</th><th>Billing Day</th><th>Address</th><th>Unpaid Months</th><th>Total Due</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (empty($pending_customers)): ?>
        <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-muted)"><i class="fas fa-check-circle" style="color:var(--success)"></i> All bills are paid!</td></tr>
      <?php else: $i=0; foreach($pending_customers as $p): $i++;
        $total_due=array_sum(array_column($p['unpaid'],'due'));
        $months_json=json_encode(array_map(fn($u)=>['month'=>$u['month'],'month_name'=>date('F Y',strtotime($u['month'].'-01')),'due'=>$u['due']],$p['unpaid']));
        $rem_on = ((int)($p['reminders_enabled'] ?? 1) === 1);
      ?>
        <tr class="coll-card-tr" style="<?= $p['promise'] ? 'background:rgba(245,158,11,.06)' : '' ?>" data-has-promise="<?= $p['promise'] ? '1' : '0' ?>" data-unpaid-count="<?= count($p['unpaid']) ?>" data-building="<?= htmlspecialchars($p['building'] ?? '') ?>" data-room="<?= htmlspecialchars($p['room'] ?? '') ?>">
          <td class="mono coll-idx-cell" style="color:var(--text-muted)"><?= $i ?></td>
          <td class="coll-name-cell">
            <div class="coll-name-row">
              <div class="coll-name-left">
                <span class="coll-rank-pill">#<?= $i ?></span>
                <strong class="coll-cust-name"><?= htmlspecialchars($p['name']) ?></strong><?php if(!empty($p['is_on_vacation'])): ?><span class="badge badge-yellow" style="font-size:10px;padding:2px 6px;vertical-align:middle;margin-left:4px" title="Customer is currently on vacation hold"><i class="fas fa-umbrella-beach"></i> Away</span><?php endif; ?>
                <button type="button" 
                        class="badge-reminder-pill <?= $rem_on ? 'is-active' : 'is-muted' ?>" 
                        onclick="toggleReminders(<?= $p['id'] ?>, this)" 
                        id="rem-toggle-desk-<?= $p['id'] ?>"
                        title="<?= $rem_on ? 'Reminders are Active. Tap to Mute' : 'Reminders are Muted. Tap to Enable' ?>">
                  <i class="fas <?= $rem_on ? 'fa-bell' : 'fa-bell-slash' ?>"></i> 
                  <span><?= $rem_on ? 'Active' : 'Muted' ?></span>
                </button>
              </div>
              <span class="coll-mobile-due mono"><?= $total_due ?> SAR</span>
            </div>
            <?php if($p['promise']): ?>
            <div class="coll-promise-wrap"><span class="badge badge-yellow" style="font-size:10px">🤝 Pay by <?= $p['promise']['promise_date'] ?><?= $p['promise']['note'] ? ' — '.htmlspecialchars($p['promise']['note']) : '' ?></span></div>
            <?php endif; ?>
          </td>
          <td class="mono coll-phone-cell"><a href="tel:<?= $p['mobile'] ?>" class="coll-phone-link"><i class="fas fa-phone-alt mobile-only-icon"></i><?= $p['mobile'] ?></a></td>
          <td class="coll-bday-cell"><span class="badge badge-blue">Day <?= $p['billing_day'] ?></span></td>
          <td class="coll-addr-cell" style="color:var(--text-muted);font-size:12px"><i class="fas fa-map-marker-alt mobile-only-icon"></i><?= $p['building'].' '.$p['apartment'].' R'.$p['room'] ?></td>
          <td class="coll-unpaid-cell">
            <div class="coll-unpaid-wrap">
              <?php foreach($p['unpaid'] as $u): ?><span class="due-tag"><?= date('M Y',strtotime($u['month'].'-01')).' — '.$u['due'].' SAR' ?></span><?php endforeach; ?>
            </div>
          </td>
          <td class="coll-desktop-due"><strong style="color:var(--danger);font-family:'IBM Plex Mono'"><?= $total_due ?> SAR</strong></td>
          <td class="coll-actions-cell" style="white-space:nowrap">
            <div class="mobile-card-actions">
              <button type="button" class="btn btn-primary btn-xs" onclick="openCollectModal(<?= $p['id'] ?>,'<?= htmlspecialchars(addslashes($p['name'])) ?>')"><i class="fas fa-money-bill"></i> Collect</button>
              <button type="button" class="btn btn-xs" style="background:#f59e0b;color:#000" onclick="openPromiseModal(<?= $p['id'] ?>,'<?= htmlspecialchars(addslashes($p['name'])) ?>',<?= $p['promise'] ? $p['promise']['id'] : 0 ?>)"><i class="fas fa-handshake"></i> Promise</button>
              <button type="button" class="btn btn-xs <?= $rem_on ? 'btn-whatsapp' : 'btn-secondary btn-wa-muted' ?>" id="rem-wa-btn-<?= $p['id'] ?>" data-mobile="<?= $p['mobile'] ?>" onclick="sendReminderSimple('<?= $p['mobile'] ?>','<?= htmlspecialchars(addslashes($p['name'])) ?>',<?= $p['id'] ?>,this,<?= $rem_on ? 1 : 0 ?>)"><i class="<?= $rem_on ? 'fab fa-whatsapp' : 'fas fa-bell-slash' ?>"></i> <span class="mobile-action-label"><?= $rem_on ? 'WhatsApp' : 'Muted' ?></span></button>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="modal-overlay" id="collectModal">
  <div class="modal-box modal-box-lg">
    <div class="modal-header-custom">
      <h5><i class="fas fa-money-bill" style="color:var(--accent)"></i> Collect Payment</h5>
      <button class="modal-close" onclick="closeModal('collectModal')">×</button>
    </div>
    <div class="modal-body-custom">
      <form method="post" id="collectForm">
        <input type="hidden" name="action" value="add_collection_partial">
        <input type="hidden" name="customer_id" id="coll_cid">
        <div style="margin-bottom:14px;font-weight:600;color:var(--text-muted)" id="coll_name"></div>
        <div id="unpaidList"></div>
        <hr class="divider">
        <div class="alert alert-info" style="margin-bottom:14px">
          <i class="fas fa-calculator"></i>
          <span>Total to collect: <strong class="mono" id="totalAmt">0</strong> SAR</span>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Record Payment</button>
      </form>
    </div>
  </div>
</div>

<!-- Edit Vacation Hold Modal -->
<div class="modal-overlay" id="editHoldModal">
  <div class="modal-box" style="max-width:480px">
    <div class="modal-header-custom">
      <h5><i class="fas fa-edit" style="color:var(--accent)"></i> <span id="editHoldTitle">Edit Vacation Hold</span></h5>
      <button class="modal-close" onclick="closeModal('editHoldModal')">×</button>
    </div>
    <div class="modal-body-custom">
      <form method="post" id="editHoldForm">
        <input type="hidden" name="action" value="edit_hold">
        <input type="hidden" name="hold_id" id="edit_hold_id">

        <div style="margin-bottom:14px">
          <label class="form-label"><i class="fas fa-user" style="color:var(--accent);margin-right:4px"></i> Customer</label>
          <input type="text" id="edit_hold_cust_name" class="form-control" readonly style="background:var(--surface2);font-weight:600">
        </div>

        <div style="margin-bottom:14px">
          <label class="form-label"><i class="fas fa-plane-departure" style="color:var(--accent);margin-right:4px"></i> Departure Date (Start)</label>
          <input type="date" name="hold_start" id="edit_hold_start" class="form-control" required style="font-size:14px">
        </div>

        <div style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
            <label class="form-label" style="margin-bottom:0"><i class="fas fa-plane-arrival" style="color:var(--success);margin-right:4px"></i> Return Date</label>
            <button type="button" class="btn btn-secondary btn-xs" onclick="document.getElementById('edit_hold_end').value=''" style="font-size:10px;padding:2px 6px">Clear (Ongoing)</button>
          </div>
          <input type="date" name="hold_end" id="edit_hold_end" class="form-control" style="font-size:14px">
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Leave blank if return date is still unknown / ongoing.</div>
        </div>

        <div style="margin-bottom:18px">
          <label class="form-label"><i class="fas fa-comment-alt" style="color:var(--text-muted);margin-right:4px"></i> Reason / Notes</label>
          <input type="text" name="reason" id="edit_hold_reason" class="form-control" placeholder="e.g. Vacation to Bangladesh">
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid var(--border);padding-top:14px">
          <button type="button" class="btn btn-secondary" onclick="closeModal('editHoldModal')">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Promise Modal -->
<div class="modal-overlay" id="promiseModal">
  <div class="modal-box">
    <div class="modal-header-custom">
      <h5><i class="fas fa-handshake" style="color:#f59e0b"></i> Record Payment Promise</h5>
      <button class="modal-close" onclick="closeModal('promiseModal')">×</button>
    </div>
    <div class="modal-body-custom">
      <form method="post">
        <input type="hidden" name="action" value="save_promise">
        <input type="hidden" name="customer_id" id="promise_cid">
        <input type="hidden" name="promise_id" id="promise_pid">
        <div style="margin-bottom:14px;font-weight:600" id="promise_name"></div>
        <div style="margin-bottom:12px">
          <label class="form-label">Promise to Pay By</label>
          <input type="date" name="promise_date" id="promise_date" class="form-control" required>
        </div>
        <div style="margin-bottom:16px">
          <label class="form-label">Note <small style="font-weight:400;text-transform:none">(optional)</small></label>
          <input type="text" name="note" id="promise_note" class="form-control" placeholder="e.g. Will pay after salary, waiting for transfer...">
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button type="submit" class="btn" style="background:#f59e0b;color:#000"><i class="fas fa-handshake"></i> Save Promise</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('promiseModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php elseif ($page === 'vacations'): ?>
<?php
$active_vac_count = $db->querySingle("SELECT COUNT(*) FROM vacation_holds WHERE hold_end IS NULL OR hold_end >= date('now')");
$total_holds_count = $db->querySingle("SELECT COUNT(*) FROM vacation_holds");
?>
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-umbrella-beach" style="color:#f59e0b"></i> Vacation Holds Management</div>
    <div style="display:flex;gap:8px">
      <span class="badge badge-yellow"><?= intval($active_vac_count) ?> currently away</span>
      <span class="badge badge-gray"><?= intval($total_holds_count) ?> total records</span>
    </div>
  </div>
  <div class="card-body">
    <?php if (isMaster() || isAdmin()): ?>
    <div style="background:rgba(59,130,246,.05);padding:14px;border-radius:10px;margin-bottom:20px;border:1px solid rgba(59,130,246,.15)">
      <h4 style="margin:0 0 12px 0;font-size:14px;display:flex;align-items:center;gap:6px">
        <i class="fas fa-plane-departure" style="color:var(--accent)"></i> Add Customer Vacation Hold
      </h4>
      <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)) auto;gap:12px;align-items:end">
        <div>
          <label style="display:block;font-size:11px;font-weight:600;margin-bottom:4px;color:var(--text-muted)">Customer</label>
          <div style="position:relative">
            <input type="text" id="holdCustSearch" class="form-control" placeholder="Type name, mobile, room… (min 2 chars)" autocomplete="off" style="font-size:13px" required>
            <input type="hidden" name="customer_id" id="holdCustId" required>
            <div id="holdCustSug" class="search-results" style="position:absolute;top:100%;left:0;right:0;max-height:240px;overflow-y:auto;z-index:9999;display:none"></div>
          </div>
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:600;margin-bottom:4px;color:var(--text-muted)">Departure Date (Start)</label>
          <input type="date" name="hold_start" class="form-control" required style="font-size:13px" value="<?= date('Y-m-d') ?>">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:600;margin-bottom:4px;color:var(--text-muted)">Return Date (Leave blank if unknown)</label>
          <input type="date" name="hold_end" class="form-control" style="font-size:13px" placeholder="Return date">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:600;margin-bottom:4px;color:var(--text-muted)">Reason / Notes</label>
          <input type="text" name="reason" class="form-control" style="font-size:13px" placeholder="e.g. Vacation to Bangladesh">
        </div>
        <div>
          <input type="hidden" name="action" value="add_hold">
          <button type="submit" class="btn btn-primary" style="height:38px"><i class="fas fa-plus"></i> Save Hold</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div class="table-wrap responsive-vac-table">
      <table>
        <thead><tr>
          <th>#</th>
          <th>Customer</th>
          <th>Period</th>
          <th>Status</th>
          <th>Reason</th>
          <?php if(isMaster() || isAdmin()): ?><th style="text-align:center">Actions</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php 
        $holds = $db->query("SELECT vh.*, c.name, c.mobile, c.building, c.room FROM vacation_holds vh JOIN customers c ON vh.customer_id=c.id ORDER BY (vh.hold_end IS NULL OR vh.hold_end >= date('now')) DESC, vh.hold_start DESC");
        $idx = 0;
        if ($holds):
          while ($h = $holds->fetchArray(SQLITE3_ASSOC)):
            $idx++;
            $is_away = (!$h['hold_end'] || $h['hold_end'] >= date('Y-m-d'));
            $days_away = (new DateTime())->diff(new DateTime($h['hold_start']))->days;
        ?>
        <?php 
            $hold_start_dt = new DateTime($h['hold_start']);
            $hold_end_dt = $h['hold_end'] ? new DateTime($h['hold_end']) : null;
            $today_ref = new DateTime(date('Y-m-d'));
            if ($hold_end_dt) {
                $calc_days = $hold_start_dt->diff($hold_end_dt)->days + 1;
            } else {
                $calc_days = max(1, $hold_start_dt->diff($today_ref)->days + 1);
            }
        ?>
        <tr style="<?= $is_away ? 'background:rgba(245,158,11,.05)' : '' ?>" class="vac-row-tr">
          <td class="mono" style="color:var(--text-muted)"><?= $idx ?></td>
          <td>
            <strong><?= htmlspecialchars($h['name']) ?></strong>
            <br><small class="mono" style="color:var(--text-muted)"><?= $h['mobile'] ?> • <?= $h['building'].' R'.$h['room'] ?></small>
          </td>
          <td>
            <strong><?= date('d M Y', strtotime($h['hold_start'])) ?></strong> → 
            <?= $h['hold_end'] ? date('d M Y', strtotime($h['hold_end'])) : '<span class="badge badge-yellow">Ongoing</span>' ?>
            <span style="font-size:11px;color:var(--text-muted);display:block;margin-top:2px"><?= $calc_days ?> days held</span>
          </td>
          <td>
            <?php if ($is_away): ?>
              <span class="badge badge-yellow">🏖️ Away (<?= $calc_days ?>d)</span>
            <?php else: ?>
              <span class="badge badge-gray">✓ Returned (<?= $calc_days ?>d)</span>
            <?php endif; ?>
          </td>
          <td style="color:var(--text-muted);font-size:13px"><?= htmlspecialchars($h['reason'] ?: 'Vacation') ?></td>
          <?php if(isMaster() || isAdmin()): ?>
          <td style="text-align:center;white-space:nowrap">
            <div style="display:inline-flex;gap:4px;align-items:center;flex-wrap:wrap;justify-content:center">
              <button type="button" class="btn btn-secondary btn-xs" onclick="openEditHoldModal(<?= $h['id'] ?>, '<?= htmlspecialchars(addslashes($h['name'])) ?>', '<?= $h['hold_start'] ?>', '<?= $h['hold_end'] ?? '' ?>', '<?= htmlspecialchars(addslashes($h['reason'] ?? '')) ?>')" title="Edit Dates or Reason"><i class="fas fa-edit"></i> Edit</button>
              <?php if (!$h['hold_end']): ?>
              <form method="post" style="display:inline-flex;gap:4px;align-items:center">
                <input type="hidden" name="action" value="resume_hold">
                <input type="hidden" name="hold_id" value="<?= $h['id'] ?>">
                <input type="date" name="resume_date" value="<?= date('Y-m-d') ?>" class="form-control" style="font-size:11px;width:125px;padding:3px 6px" title="Return date">
                <button type="submit" class="btn btn-primary btn-xs" title="Set return date and resume"><i class="fas fa-play"></i> Resume</button>
              </form>
              <?php endif; ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="delete_hold">
                <input type="hidden" name="hold_id" value="<?= $h['id'] ?>">
                <button type="submit" class="btn btn-danger btn-xs" onclick="return confirm('Delete this vacation hold record entirely?')"><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </td>
          <?php endif; ?>
        </tr>
        <?php endwhile;
          if ($idx === 0): ?>
          <tr><td colspan="6" style="padding:28px;text-align:center;color:var(--text-muted)"><i class="fas fa-umbrella-beach" style="font-size:24px;display:block;margin-bottom:8px;opacity:.4"></i> No vacation holds on record</td></tr>
        <?php endif; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif ($page === 'customers'): ?>
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-search"></i> Search</div>
    <button class="btn btn-success btn-sm" onclick="openModal('custModal');clearCustForm()"><i class="fas fa-plus"></i> Add Customer</button>
  </div>
  <div class="card-body">
    <div class="search-wrap" style="position:relative">
      <i class="fas fa-search search-icon"></i>
      <input type="search" id="custSearch" class="form-control" placeholder="Search by name, 05/966 mobile, building, room… (min 2 chars)" autocomplete="off" style="padding-right:32px">
      <button type="button" id="custSearchClear" style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);border:none;background:none;color:var(--text-muted);cursor:pointer;font-size:14px;padding:4px" title="Clear search"><i class="fas fa-times-circle"></i></button>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-users"></i> All Customers</div>
    <span class="badge badge-blue" id="custCountBadge"><?= $total_customers ?> active</span>
  </div>
  <div class="table-wrap responsive-cust-table">
    <table>
      <thead><tr><th>#</th><th>Name</th><th>Mobile</th><th>Address</th><th>Billing Day</th><th>Pay By</th><th>Monthly Fee</th><th>Connected</th><th>Reminders</th><th>Actions</th></tr></thead>
      <tbody id="custTbody">
      <?php $i=0;$custs=$db->query("SELECT *, COALESCE(reminders_enabled, 1) as reminders_enabled, (SELECT COUNT(*) FROM vacation_holds vh WHERE vh.customer_id = customers.id AND vh.hold_start <= date('now') AND (vh.hold_end IS NULL OR vh.hold_end >= date('now'))) as is_on_vacation FROM customers WHERE status='active' ORDER BY name");
      while($c=$custs->fetchArray(SQLITE3_ASSOC)){$i++;
        $rem_on = ((int)$c['reminders_enabled'] === 1);
      ?>
        <tr class="cust-card-tr" data-n="<?= htmlspecialchars($c['name']) ?>" data-m="<?= $c['mobile'] ?>" data-b="<?= htmlspecialchars($c['building'] ?? '') ?>" data-apt="<?= htmlspecialchars($c['apartment'] ?? '') ?>" data-r="<?= htmlspecialchars($c['room'] ?? '') ?>">
          <td class="mono cust-idx-cell" style="color:var(--text-muted)"><?= $i ?></td>
          <td class="cust-name-cell">
            <div class="cust-top-row">
              <div class="cust-name-left">
                <span class="cust-rank-pill">#<?= $i ?></span>
                <strong class="cust-card-name"><?= htmlspecialchars($c['name']) ?></strong><?php if(!empty($c['is_on_vacation'])): ?><span class="badge badge-yellow" style="font-size:10px;padding:2px 6px;vertical-align:middle;margin-left:4px" title="Customer is currently away on vacation"><i class="fas fa-umbrella-beach"></i> Vacation</span><?php endif; ?>
                <button type="button" 
                        class="badge-reminder-pill <?= $rem_on ? 'is-active' : 'is-muted' ?>" 
                        onclick="toggleReminders(<?= $c['id'] ?>, this)" 
                        id="rem-toggle-mob-<?= $c['id'] ?>"
                        title="<?= $rem_on ? 'Reminders are Active. Tap to Mute' : 'Reminders are Muted. Tap to Enable' ?>">
                  <i class="fas <?= $rem_on ? 'fa-bell' : 'fa-bell-slash' ?>"></i> 
                  <span><?= $rem_on ? 'Active' : 'Muted' ?></span>
                </button>
              </div>
              <span class="badge badge-green mono cust-mobile-fee"><?= floatval($c['monthly_fee']?:30) ?> SAR</span>
            </div>
            <a href="#" class="cust-hist-link" style="font-size:11px;color:var(--accent)" onclick="showHistory(<?= $c['id'] ?>,'<?= htmlspecialchars(addslashes($c['name'])) ?>')"><i class="fas fa-history"></i> History</a>
          </td>
          <td class="mono cust-phone-cell"><a href="tel:<?= $c['mobile'] ?>" class="cust-phone-link"><i class="fas fa-phone-alt mobile-only-icon"></i><?= $c['mobile'] ?></a></td>
          <td class="cust-addr-cell" style="color:var(--text-muted);font-size:12px"><i class="fas fa-map-marker-alt mobile-only-icon"></i><?= trim($c['building'].' '.$c['apartment'].' R'.$c['room']) ?></td>
          <td class="cust-bday-cell"><span class="badge badge-blue">Day <?= $c['billing_day'] ?></span></td>
          <td class="cust-payby-cell"><span class="badge badge-purple">Pay By <?= intval($c['pay_by_day'] ?: 10) ?></span></td>
          <td class="cust-desktop-fee"><span class="badge badge-green mono"><?= floatval($c['monthly_fee']?:30) ?> SAR</span></td>
          <td class="cust-start-cell">
            <?php if (empty($c['billing_start_date'])): ?>
              <span class="badge badge-red" title="Missing connection date - billing will be wrong!"><i class="fas fa-triangle-exclamation"></i> MISSING</span>
            <?php else: ?>
              <span class="mono" style="font-size:12px"><i class="fas fa-calendar-day mobile-only-icon"></i><?= date('d M Y', strtotime($c['billing_start_date'])) ?></span>
            <?php endif; ?>
          </td>
          <td class="cust-wa-cell" style="text-align:center">
            <button type="button" 
                    class="btn-reminder-toggle <?= $rem_on ? 'is-active' : 'is-muted' ?>" 
                    onclick="toggleReminders(<?= $c['id'] ?>, this)" 
                    id="rem-toggle-desk-<?= $c['id'] ?>"
                    title="<?= $rem_on ? 'Reminders are Active. Click to Mute' : 'Reminders are Muted. Click to Enable' ?>">
              <i class="fas <?= $rem_on ? 'fa-bell' : 'fa-bell-slash' ?>"></i> 
              <span><?= $rem_on ? 'Active' : 'Muted' ?></span>
            </button>
          </td>
          <td class="cust-actions-cell" style="white-space:nowrap">
            <div class="cust-actions-wrap">
              <button type="button" 
                      class="btn btn-whatsapp btn-xs cust-mob-wa <?= !$rem_on ? 'btn-wa-muted' : '' ?>" 
                      id="rem-wa-btn-<?= $c['id'] ?>" 
                      data-mobile="<?= $c['mobile'] ?>"
                      onclick="sendReminderSimple('<?= $c['mobile'] ?>','<?= htmlspecialchars(addslashes($c['name'])) ?>',<?= $c['id'] ?>,this,<?= $rem_on ? 1 : 0 ?>)"><i class="<?= $rem_on ? 'fab fa-whatsapp' : 'fas fa-bell-slash' ?>"></i> <span><?= $rem_on ? 'WA' : 'Muted' ?></span></button>
              <button type="button" class="btn btn-secondary btn-xs" onclick="editCust(<?= $c['id'] ?>,'<?= htmlspecialchars(addslashes($c['name'])) ?>','<?= $c['mobile'] ?>','<?= htmlspecialchars(addslashes($c['building'] ?? '')) ?>','<?= htmlspecialchars(addslashes($c['apartment'] ?? '')) ?>','<?= htmlspecialchars(addslashes($c['room'] ?? '')) ?>',<?= $c['billing_day'] ?>,'<?= $c['billing_start_date'] ?>',<?= floatval($c['monthly_fee']?:30) ?>,<?= intval($c['pay_by_day'] ?: 10) ?>,<?= $rem_on ? 1 : 0 ?>)"><i class="fas fa-edit"></i> Edit</button>
              <a href="?delete_customer=<?= $c['id'] ?>&page=customers" class="btn btn-danger btn-xs" onclick="return confirm('Deactivate <?= htmlspecialchars(addslashes($c['name'])) ?>? Payment history will be kept.')"><i class="fas fa-trash"></i></a>
            </div>
          </td>
        </tr>
      <?php }?>
      </tbody>
    </table>
  </div>
</div>
<div class="modal-overlay" id="custModal">
  <div class="modal-box" style="max-width:580px">
    <div class="modal-header-custom">
      <h5><i class="fas fa-user-plus" style="color:var(--success)"></i> <span id="custModalTitle">Add Customer</span></h5>
      <button class="modal-close" onclick="closeModal('custModal')">×</button>
    </div>
    <div class="modal-body-custom">
      <form method="post" id="custForm">
        <input type="hidden" name="action" value="save_customer">
        <input type="hidden" name="id" id="cust_id">
        
        <div style="margin-bottom:14px">
          <label class="form-label"><i class="fas fa-user" style="color:var(--accent);margin-right:4px"></i> Full Name</label>
          <input type="text" name="name" id="cust_name" class="form-control" placeholder="Customer full name" required style="font-size:14px;padding:9px 12px">
        </div>

        <div style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
            <label class="form-label" style="margin-bottom:0"><i class="fab fa-whatsapp" style="color:#25D366;margin-right:4px"></i> Mobile / WhatsApp Number</label>
            <span style="font-size:11px;color:var(--text-muted);font-weight:500">Saudi or International</span>
          </div>
          <div class="mobile-input-row" style="display:flex;gap:8px;align-items:stretch">
            <select id="cust_country_code" class="form-control" style="flex:0 0 125px;font-size:13px;font-weight:600;padding:9px 8px;cursor:pointer">
              <option value="966" selected>🇸🇦 +966</option>
              <option value="880">🇧🇩 +880</option>
              <option value="91">🇮🇳 +91</option>
              <option value="92">🇵🇰 +92</option>
              <option value="977">🇳🇵 +977</option>
              <option value="63">🇵🇭 +63</option>
              <option value="20">🇪🇬 +20</option>
              <option value="">🌐 Other</option>
            </select>
            <div style="flex:1;position:relative">
              <input type="tel" name="mobile" id="cust_mobile" class="form-control" placeholder="5X XXX XXXX (or 05XXXXXXXX)" required style="font-size:15px;font-weight:600;font-family:'IBM Plex Mono',monospace;letter-spacing:0.5px;padding:9px 14px;height:42px;width:100%">
            </div>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:5px;line-height:1.4"><i class="fas fa-info-circle"></i> Type 9 digits (e.g. <strong>591495334</strong>) or 10 digits with 0 (e.g. <strong>0591495334</strong>). Country code will be formatted automatically.</div>
        </div>

        <div class="input-group" style="margin-bottom:14px">
          <div style="flex:1"><label class="form-label"><i class="fas fa-building" style="color:var(--text-muted);margin-right:3px"></i> Building</label><input type="text" name="building" id="cust_building" class="form-control" placeholder="Building name/No"></div>
          <div style="flex:1"><label class="form-label"><i class="fas fa-door-closed" style="color:var(--text-muted);margin-right:3px"></i> Apartment</label><input type="text" name="apartment" id="cust_apartment" class="form-control" placeholder="Apt #"></div>
          <div style="flex:1"><label class="form-label"><i class="fas fa-bed" style="color:var(--text-muted);margin-right:3px"></i> Room</label><input type="text" name="room" id="cust_room" class="form-control" placeholder="Room #"></div>
        </div>

        <div class="input-group" style="margin-bottom:14px">
          <div style="flex:1"><label class="form-label"><i class="fas fa-calendar-day" style="color:var(--accent);margin-right:3px"></i> Billing Day</label><input type="number" name="billing_day" id="cust_billing_day" class="form-control" min="1" max="31" placeholder="e.g. 25" required></div>
          <div style="flex:1"><label class="form-label"><i class="fas fa-money-bill-wave" style="color:var(--success);margin-right:3px"></i> Monthly Fee (SAR)</label><input type="number" name="monthly_fee" id="cust_fee" class="form-control" min="1" step="0.5" value="30" required></div>
          <div style="flex:1"><label class="form-label"><i class="fas fa-clock" style="color:var(--warning);margin-right:3px"></i> Pay By Day</label><input type="number" name="pay_by_day" id="cust_pay_by_day" class="form-control" min="1" max="31" value="10" required></div>
        </div>

        <div style="margin-bottom:18px">
          <label class="form-label"><i class="fas fa-plug" style="color:var(--info);margin-right:3px"></i> Date Connection Started <small style="font-weight:400;text-transform:none;color:var(--text-muted)">(actual installation date, e.g. 2025-04-25)</small></label>
          <input type="date" name="billing_start_date" id="cust_start" class="form-control" required style="font-size:13px;padding:8px 12px">
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px"><i class="fas fa-info-circle"></i> If joined after billing day, first bill automatically starts the following month.</div>
        </div>

        <div style="margin-bottom:18px;background:var(--surface2);padding:12px 14px;border-radius:10px;border:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
          <div>
            <div style="font-weight:600;font-size:13px;display:flex;align-items:center;gap:6px">
              <i class="fab fa-whatsapp" style="color:#25D366"></i> WhatsApp Reminders
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Turn off to prevent this customer from receiving any automated or manual reminder messages.</div>
          </div>
          <label class="switch-toggle" style="position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0">
            <input type="checkbox" name="reminders_enabled" id="cust_reminders_enabled" value="1" checked>
            <span class="switch-slider"></span>
          </label>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid var(--border);padding-top:14px">
          <button type="button" class="btn btn-secondary" onclick="closeModal('custModal')">Cancel</button>
          <button type="submit" class="btn btn-success" onclick="return combineMobile()"><i class="fas fa-save"></i> Save Customer</button>
        </div>
      </form>
      <script>
      function combineMobile() {
        var cc = document.getElementById("cust_country_code") ? document.getElementById("cust_country_code").value : '';
        var mob = document.getElementById("cust_mobile");
        if (!mob) return true;
        var raw = mob.value.trim().replace(/\s+/g, '').replace(/[-+()]/g, '');
        if (!raw) return true;
        if (cc === '966') {
          if (raw.startsWith('966')) raw = raw.slice(3);
          if (raw.startsWith('0')) raw = raw.slice(1);
          mob.value = '966' + raw;
        } else if (cc) {
          if (raw.startsWith(cc)) raw = raw.slice(cc.length);
          if (raw.startsWith('0')) raw = raw.slice(1);
          mob.value = cc + raw;
        } else {
          mob.value = raw;
        }
        return true;
      }
      </script>
    </div>
  </div>
</div>






<?php elseif ($page === 'report'): ?>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-file-invoice"></i> All Collections</div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>Customer</th><th>Month</th><th>Amount</th><th>Collected Date</th><th>Collected By</th><?php if(isMaster()):?><th>Actions</th><?php endif;?></tr></thead>
      <tbody>
      <?php while($col=$all_collections->fetchArray(SQLITE3_ASSOC)): ?>
        <tr>
          <td class="mono" style="color:var(--text-muted)"><?= $col['id'] ?></td>
          <td><strong><?= htmlspecialchars($col['cname']) ?></strong></td>
          <td class="mono"><?= $col['month_year'] ?></td>
          <td><span class="badge badge-green mono"><?= $col['amount'] ?> SAR</span></td>
          <td style="font-size:12px;color:var(--text-muted)"><?= $col['collected_date'] ?></td>
          <td><?= htmlspecialchars($col['collector']) ?></td>
          <?php if(isMaster()): ?>
          <td style="white-space:nowrap">
            <form method="post" style="display:inline-flex;gap:4px;align-items:center">
              <input type="hidden" name="action" value="edit_collection">
              <input type="hidden" name="collection_id" value="<?= $col['id'] ?>">
              <input type="number" name="amount" value="<?= $col['amount'] ?>" step="0.5" style="width:80px" class="form-control">
              <button type="submit" class="btn btn-primary btn-xs"><i class="fas fa-save"></i></button>
            </form>
            <a href="?reallocate_collection=<?= $col['id'] ?>&page=report" class="btn btn-warning btn-xs" onclick="return confirm('Split this lump entry into monthly chunks?')"><i class="fas fa-sitemap"></i></a>
            <a href="?delete_collection=<?= $col['id'] ?>&page=report" class="btn btn-danger btn-xs" onclick="return confirm('Delete this record?')"><i class="fas fa-trash"></i></a>
          </td>
          <?php endif; ?>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($page === 'balance'): ?>
<?php
$cust_rows = [];
$grand_owed = 0;
$grand_paid = 0;
$owing_count = 0;
$settled_count = 0;
$credit_count = 0;

$custs = $db->query("SELECT id, name, mobile, building, apartment, room FROM customers WHERE status='active'");
while ($c = $custs->fetchArray(SQLITE3_ASSOC)) {
    $bal = getCustomerBalance($db, $c["id"]);
    $grand_owed += $bal["total_owed"];
    $grand_paid += $bal["total_paid"];
    if ($bal["balance"] < 0) {
        $owing_count++;
    } elseif ($bal["balance"] > 0) {
        $credit_count++;
    } else {
        $settled_count++;
    }
    $c["bal"] = $bal;
    $cust_rows[] = $c;
}

// Sort: Highest amount balance owing first (lowest/most negative balance), then alphabetically by name
usort($cust_rows, function($a, $b) {
    if ($a["bal"]["balance"] != $b["bal"]["balance"]) {
        return ($a["bal"]["balance"] < $b["bal"]["balance"]) ? -1 : 1;
    }
    return strcasecmp($a["name"], $b["name"]);
});
?>
<div class="card">
  <div class="card-header">
    <div class="card-header-title">
      <i class="fas fa-scale-balanced"></i> Customer Balance Sheet
      <span style="font-size:12px;font-weight:normal;color:var(--text-muted);margin-left:8px">(Highest owing first)</span>
    </div>
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
      <span class="badge badge-red"><?= $owing_count ?> owing</span>
      <span class="badge badge-blue"><?= $settled_count ?> settled</span>
      <span class="badge badge-green"><?= $credit_count ?> credit</span>
      <span class="badge" style="background:var(--surface2);border:1px solid var(--border);color:var(--text-muted)"><?= count($cust_rows) ?> active</span>
    </div>
  </div>
  <div class="bal-filter-wrap">
    <input type="search" id="balanceSearch" class="form-control" placeholder="🔍 Search by name, phone, building, room…" style="font-size:14px" oninput="filterBalance(this.value)" onsearch="filterBalance(this.value)">
    <div class="bal-pills-bar">
      <button type="button" class="filter-pill balance-filter-pill active" onclick="setBalanceFilter('all', this)">All (<?= count($cust_rows) ?>)</button>
      <button type="button" class="filter-pill balance-filter-pill" onclick="setBalanceFilter('owing', this)">⚠️ Owing (<?= $owing_count ?>)</button>
      <button type="button" class="filter-pill balance-filter-pill" onclick="setBalanceFilter('settled', this)">✓ Settled (<?= $settled_count ?>)</button>
      <button type="button" class="filter-pill balance-filter-pill" onclick="setBalanceFilter('credit', this)">★ Credit (<?= $credit_count ?>)</button>
    </div>
  </div>
  <div class="table-wrap responsive-bal-table" style="max-height:75vh;overflow-y:auto">
    <table id="balanceTable">
      <thead><tr>
        <th>#</th>
        <th>Customer</th>
        <th>Mobile</th>
        <th>Total Owed (SAR)</th>
        <th>Total Paid (SAR)</th>
        <th>Balance</th>
        <th>Status</th>
        <th>Follow-up</th>
      </tr></thead>
      <tbody>
      <?php 
      $idx = 0;
      foreach ($cust_rows as $c): 
        $bal = $c["bal"];
        $idx++;
        $status = $bal["balance"] > 0 ? 'CREDIT' : ($bal["balance"] == 0 ? 'SETTLED' : 'OWING');
        $status_color = $bal["balance"] > 0 ? 'badge-green' : ($bal["balance"] == 0 ? 'badge-blue' : 'badge-red');
      ?>
        <tr style="<?= $bal['balance'] < 0 ? 'background:rgba(239,68,68,.04)' : ($bal['balance'] > 0 ? 'background:rgba(34,197,94,.04)' : '') ?>" data-status="<?= strtolower($status) ?>">
          <td class="mono bal-idx-cell" style="color:var(--text-muted)"><?= $idx ?></td>
          <td class="bal-cust-cell">
            <div class="bal-mobile-top">
              <span class="bal-rank-pill">#<?= $idx ?></span>
              <a href="javascript:void(0)" onclick="showHistory(<?= $c['id'] ?>,'<?= htmlspecialchars(addslashes($c['name'])) ?>')" class="bal-cust-name-link" style="color:var(--text);text-decoration:none;font-weight:700;cursor:pointer" title="Click to view billing & transaction history"><?= htmlspecialchars($c["name"]) ?></a>
              <span class="badge <?= $status_color ?> bal-mobile-status"><?= $status ?></span>
            </div>
            <?php if (!empty($c["building"]) || !empty($c["room"])): ?>
              <div class="bal-cust-addr">
                <i class="fas fa-map-marker-alt mobile-only-icon"></i><?= htmlspecialchars(trim(($c["building"] ?? "") . " " . ($c["apartment"] ?? "") . " " . (!empty($c["room"]) ? "R" . $c["room"] : ""))) ?>
              </div>
            <?php endif; ?>
          </td>
          <td class="mono bal-phone-cell">
            <a href="tel:<?= $c["mobile"] ?>" class="bal-phone-link"><i class="fas fa-phone-alt mobile-only-icon"></i><?= $c["mobile"] ?></a>
          </td>
          <td class="mono bal-metric-cell bal-owed-cell">
            <span class="bal-metric-label">Owed</span>
            <strong><?= number_format($bal["total_owed"], 2) ?> SAR</strong>
          </td>
          <td class="mono bal-metric-cell bal-paid-cell">
            <span class="bal-metric-label">Paid</span>
            <a href="javascript:void(0)" onclick="showHistory(<?= $c['id'] ?>,'<?= htmlspecialchars(addslashes($c['name'])) ?>')" style="color:var(--success);text-decoration:none;cursor:pointer" title="Click to view payment breakdown">
              <strong style="color:var(--success)"><?= number_format($bal["total_paid"], 2) ?> SAR</strong>
              <i class="fas fa-receipt" style="font-size:10px;opacity:.7;margin-left:3px"></i>
            </a>
          </td>
          <td class="mono bal-metric-cell bal-bal-cell">
            <span class="bal-metric-label">Balance</span>
            <?php if ($bal['balance'] > 0): ?>
              <strong style="color:var(--success)">+<?= number_format($bal["balance"], 2) ?> SAR</strong>
            <?php elseif ($bal['balance'] < 0): ?>
              <strong style="color:var(--danger)"><?= number_format($bal["balance"], 2) ?> SAR</strong>
            <?php else: ?>
              <strong style="color:var(--text-muted)">0.00 SAR</strong>
            <?php endif; ?>
          </td>
          <td class="bal-desktop-status"><span class="badge <?= $status_color ?>"><?= $status ?></span></td>
          <td class="bal-action-cell">
            <div class="bal-card-actions">
              <button type="button" class="btn btn-secondary btn-xs bal-btn-hist" onclick="showHistory(<?= $c['id'] ?>,'<?= htmlspecialchars(addslashes($c['name'])) ?>')" title="View Billing & Transaction History"><i class="fas fa-history"></i> History</button>
              <?php if ($bal["balance"] < 0): ?>
                <button type="button" class="btn btn-whatsapp btn-xs bal-btn-wa" onclick="sendReminderSimple('<?= $c['mobile'] ?>','<?= htmlspecialchars(addslashes($c['name'])) ?>',<?= $c['id'] ?>)" title="Send WhatsApp reminder"><i class="fab fa-whatsapp"></i> <span class="mobile-action-label">WhatsApp Reminder</span></button>
                <a href="tel:<?= $c['mobile'] ?>" class="btn btn-secondary btn-xs bal-btn-call" title="Call Customer"><i class="fas fa-phone"></i> Call</a>
              <?php else: ?>
                <a href="tel:<?= $c['mobile'] ?>" class="btn btn-secondary btn-xs bal-btn-call" title="Call Customer"><i class="fas fa-phone"></i> Call</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <tr class="table-total">
        <td colspan="3"><strong>GRAND TOTAL</strong></td>
        <td class="mono"><span class="bal-metric-label">Total Owed</span><strong><?= number_format($grand_owed, 2) ?> SAR</strong></td>
        <td class="mono"><span class="bal-metric-label">Total Paid</span><strong style="color:var(--success)"><?= number_format($grand_paid, 2) ?> SAR</strong></td>
        <td class="mono"><span class="bal-metric-label">Net Balance</span><?php 
          $grand_net = round($grand_paid - $grand_owed, 2);
          if ($grand_net > 0): ?>
            <strong style="color:var(--success)">+<?= number_format($grand_net, 2) ?> SAR</strong>
          <?php elseif ($grand_net < 0): ?>
            <strong style="color:var(--danger)"><?= number_format($grand_net, 2) ?> SAR</strong>
          <?php else: ?>
            <strong style="color:var(--text-muted)">0.00 SAR</strong>
          <?php endif; ?></td>
        <td></td>
        <td></td>
      </tr>
      </tbody>
    </table>
  </div>
  <div class="card-footer" style="background:rgba(0,0,0,.02);padding:12px;font-size:12px;color:var(--text-muted)">
    <strong>Legend:</strong> 
    <span style="margin-right:20px"><span class="badge badge-red" style="font-size:10px">OWING</span> = Unpaid balance remains (highest debt at top)</span>
    <span style="margin-right:20px"><span class="badge badge-blue" style="font-size:10px">SETTLED</span> = Current balance is zero</span>
    <span><span class="badge badge-green" style="font-size:10px">CREDIT</span> = Overpaid (has prepaid months)</span>
  </div>
</div>


<?php elseif ($page === 'files'): ?>
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-film" style="color:var(--accent)"></i> Upload Movie to Local Server</div>
    <span class="badge badge-blue">Chunked Upload • No Size Limit</span>
  </div>
  <div class="card-body">
    <form id="chunkUploadForm" onsubmit="handleMovieUpload(event)">
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)) auto;gap:12px;align-items:end">
        <div>
          <label class="form-label">Movie Title</label>
          <input type="text" id="movieTitle" class="form-control" placeholder="e.g. Inception (2010)" required>
        </div>
        <div>
          <label class="form-label">Description / Genre</label>
          <input type="text" id="movieDesc" class="form-control" placeholder="Action, Sci-Fi, Bangla...">
        </div>
        <div>
          <label class="form-label">Video File (.mp4, .mkv, .avi, etc.)</label>
          <input type="file" id="movieFile" class="form-control" accept="video/*,.mkv,.mp4,.avi,.mov,.webm,.ts" required onchange="onMovieFileSelected(this)">
        </div>
        <div>
          <button type="submit" id="movieUploadBtn" class="btn btn-primary" style="height:38px"><i class="fas fa-cloud-upload-alt"></i> Upload Movie</button>
        </div>
      </div>
      <div id="movieProgressWrap" style="display:none;margin-top:14px;background:var(--surface2);padding:14px;border-radius:10px;border:1px solid var(--border)">
        <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:600;margin-bottom:6px">
          <span id="movieUploadStatus"><i class="fas fa-spinner fa-spin"></i> Uploading chunks...</span>
          <span id="movieUploadPct" style="color:var(--accent);font-family:'IBM Plex Mono'">0%</span>
        </div>
        <div style="background:rgba(0,0,0,.2);height:12px;border-radius:6px;overflow:hidden;margin-bottom:6px">
          <div id="movieProgressBar" style="width:0%;height:100%;background:linear-gradient(90deg, #3b82f6, #10b981);transition:width .2s"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);font-family:'IBM Plex Mono'">
          <span id="movieUploadDetails">0 MB / 0 MB</span>
          <span id="movieUploadSpeed">0 MB/s • ETA: Calculating...</span>
        </div>
        <div style="margin-top:8px;text-align:right">
          <button type="button" class="btn btn-secondary btn-xs" onclick="cancelMovieUpload()"><i class="fas fa-times"></i> Cancel</button>
        </div>
      </div>
    </form>
  </div>
</div>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-film"></i> Movie Library</div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Title</th><th>Filename</th><th>Size (MB)</th><th>Downloads</th><th>Actions</th></tr></thead>
      <tbody>
      <?php $files=$db->query("SELECT * FROM files ORDER BY id DESC");
      while($f=$files->fetchArray(SQLITE3_ASSOC)){$sz=round($f['size']/1048576,2);?>
        <tr>
          <td><strong><?= htmlspecialchars($f['title']) ?></strong><?php if($f['description']):?><br><small style="color:var(--text-muted)"><?= htmlspecialchars($f['description']) ?></small><?php endif;?></td>
          <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($f['original_name']) ?></td>
          <td class="mono"><?= $sz ?></td>
          <td><span class="badge badge-gray"><?= $f['downloads'] ?></span></td>
          <td style="white-space:nowrap">
            <a href="?download=<?= $f['id'] ?>" class="btn btn-success btn-xs"><i class="fas fa-download"></i> Download</a>
            <?php if(isMaster()):?><a href="?delete_file=<?= $f['id'] ?>&page=files" class="btn btn-danger btn-xs" onclick="return confirm('Delete file?')"><i class="fas fa-trash"></i></a><?php endif;?>
          </td>
        </tr>
      <?php }?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($page === 'whatsapp'): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px" class="wa-grid">
  <div class="card">
    <div class="card-header">
      <div class="card-header-title"><i class="fab fa-whatsapp" style="color:#25D366"></i> Connection Status</div>
      <button class="btn btn-secondary btn-sm" onclick="checkWaStatus()"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
    <div class="card-body">
      <div id="wa-status-display"><div style="color:var(--text-muted);padding:20px 0;text-align:center"><i class="fas fa-spinner fa-spin"></i> Checking status…</div></div>
      <hr class="divider">
      <div style="font-size:12px;color:var(--text-muted)">
        <div><strong>OpenWA URL:</strong> <span class="mono"><?= htmlspecialchars(getSetting($db,'openwa_url')) ?></span></div>
        <div style="margin-top:4px"><strong>Session ID:</strong> <span class="mono" id="session-id-display"><?= htmlspecialchars(getSetting($db,'openwa_session_id')) ?></span></div>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-qrcode" style="color:var(--accent)"></i> Scan QR / Reconnect</div></div>
    <div class="card-body">
      <p style="color:var(--text-muted);font-size:13px;margin-bottom:14px">If WhatsApp is disconnected, click <strong>Reconnect</strong> to create a new session, then scan the QR code with your phone.</p>
      <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
        <?php if(isMaster()):?>
        <button class="btn btn-primary" onclick="doReconnect()"><i class="fas fa-sync-alt"></i> Reconnect WhatsApp</button>
        <?php endif;?>
        <button class="btn btn-secondary" onclick="fetchQR()"><i class="fas fa-qrcode"></i> Show QR Code</button>
      </div>
      <div class="qr-container" id="qr-container">
        <div style="color:var(--text-muted);text-align:center"><i class="fas fa-qrcode" style="font-size:40px;opacity:.3;display:block;margin-bottom:8px"></i><span style="font-size:12px">QR code will appear here</span></div>
      </div>
      <div id="wa-log" style="margin-top:12px;font-size:12px;color:var(--text-muted)"></div>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-paper-plane"></i> Send Test Message</div></div>
  <div class="card-body">
    <div class="input-group">
      <div style="flex:1"><label class="form-label">Phone (without leading 0)</label><input type="text" id="test-phone" class="form-control" placeholder="e.g. 555xxxxxxx"></div>
      <div style="flex:2"><label class="form-label">Message</label><input type="text" id="test-msg" class="form-control" value="Hello from CyberNet billing portal ✅"></div>
      <div style="align-self:flex-end"><button class="btn btn-whatsapp" onclick="sendTestMsg()"><i class="fab fa-whatsapp"></i> Send</button></div>
    </div>
    <div id="test-result" style="margin-top:10px;font-size:13px"></div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-bullhorn" style="color:#f59e0b"></i> Broadcast Message</div>
  </div>
  <div class="card-body">
    <div style="margin-bottom:12px">
      <label class="form-label">Message — use {name} to personalize</label>
      <textarea id="broadcast-msg" class="form-control" rows="4" placeholder="Dear {name}, watch football live tonight! Link: http://10.12.14.16:8086/live - CyberNet"></textarea>
    </div>
    <div style="margin-bottom:14px">
      <span style="font-size:12px;color:var(--text-muted)" id="bc-count"></span>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
      <button class="btn btn-whatsapp" onclick="startBroadcast()"><i class="fab fa-whatsapp"></i> Send to All</button>
      <button class="btn btn-secondary" onclick="document.getElementById('broadcast-msg').value=''">Clear</button>
    </div>
    <div id="bc-progress" style="display:none;margin-bottom:8px">
      <div style="background:var(--surface2);border-radius:8px;overflow:hidden;height:10px;margin-bottom:6px">
        <div id="bc-bar" style="height:100%;background:#25D366;width:0%;transition:width .3s"></div>
      </div>
      <div id="bc-status" style="font-size:12px;color:var(--text-muted)"></div>
    </div>
    <div id="bc-log" style="max-height:200px;overflow-y:auto;font-size:12px;margin-top:8px"></div>
  </div>
</div>
<script>
window._bcCustomers = <?php
  $all_bc=$db->query("SELECT id,name,mobile FROM customers WHERE status='active' AND COALESCE(reminders_enabled, 1)=1");
  $bc_arr=[];
  while($r=$all_bc->fetchArray(SQLITE3_ASSOC)) $bc_arr[]=$r;
  echo json_encode($bc_arr);
?>;
document.getElementById('bc-count').textContent = window._bcCustomers.length + ' customers will receive this message';
function startBroadcast(){
  var msg=document.getElementById('broadcast-msg').value.trim();
  if(!msg){alert('Please enter a message');return;}
  var customers=window._bcCustomers||[];
  if(!customers.length){alert('No customers found');return;}
  if(!confirm('Send to '+customers.length+' customers?'))return;
  var cfg=getWaConfig();
  var sent=0,failed=0,idx=0;
  document.getElementById('bc-progress').style.display='block';
  document.getElementById('bc-log').innerHTML='';
  function sendNext(){
    if(idx>=customers.length){
      document.getElementById('bc-status').textContent='Done! Sent: '+sent+', Failed: '+failed;
      return;
    }
    var c=customers[idx++];
    var pct=Math.round((idx/customers.length)*100);
    document.getElementById('bc-bar').style.width=pct+'%';
    document.getElementById('bc-status').textContent='Sending to '+c.name+' ('+idx+'/'+customers.length+')...';
    var text=msg.replace(/{name}/g,c.name);
    var phone=c.mobile.replace(/^0+/,'');
    if(!/^966/.test(phone))phone='966'+phone;
    var chatId=phone+'@c.us';
    fetch(cfg.url+'/api/sessions/'+cfg.sid+'/messages/send-text',{
      method:'POST',
      headers:{'Content-Type':'application/json','X-API-Key':cfg.key},
      body:JSON.stringify({chatId:chatId,text:text})
    }).then(function(r){
      if(r.ok){sent++;document.getElementById('bc-log').innerHTML+='<div style="color:var(--success)">OK '+c.name+'</div>';}
      else{failed++;document.getElementById('bc-log').innerHTML+='<div style="color:var(--danger)">FAIL '+c.name+'</div>';}
    }).catch(function(){
      failed++;
      document.getElementById('bc-log').innerHTML+='<div style="color:var(--danger)">ERR '+c.name+'</div>';
    }).finally(function(){setTimeout(sendNext,1200);});
  }
  sendNext();
}
</script>

<?php elseif ($page === 'audit' && isMaster()): ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-filter"></i> Filter by User</div></div>
  <div class="card-body">
    <form method="get" style="display:flex;gap:10px">
      <input type="hidden" name="page" value="audit">
      <select name="filter_user" class="form-select" style="width:220px">
        <option value="0">All Users</option>
        <?php $us=$db->query("SELECT id,fullname FROM users ORDER BY fullname");
        while($u=$us->fetchArray(SQLITE3_ASSOC)){$sel=($filter_user==$u['id'])?'selected':'';echo "<option value='{$u['id']}' $sel>".htmlspecialchars($u['fullname'])."</option>";}?>
      </select>
      <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
      <a href="?page=audit" class="btn btn-secondary">Clear</a>
    </form>
  </div>
</div>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-history"></i> Audit Log</div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Timestamp</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
      <tbody>
      <?php
      if($filter_user>0) $logs=$db->query("SELECT l.*,u.fullname FROM audit_log l JOIN users u ON l.user_id=u.id WHERE l.user_id=$filter_user ORDER BY l.timestamp DESC LIMIT 500");
      else $logs=$db->query("SELECT l.*,u.fullname FROM audit_log l JOIN users u ON l.user_id=u.id ORDER BY l.timestamp DESC LIMIT 500");
      while($log=$logs->fetchArray(SQLITE3_ASSOC)):?>
        <tr>
          <td class="mono" style="font-size:11px;color:var(--text-muted)"><?= $log['timestamp'] ?></td>
          <td><?= htmlspecialchars($log['fullname']) ?></td>
          <td><span class="badge badge-blue"><?= htmlspecialchars($log['action']) ?></span></td>
          <td style="font-size:12px"><?= htmlspecialchars($log['details']) ?></td>
        </tr>
      <?php endwhile;?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($page === 'admins' && isMaster()): ?>
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-user-shield"></i> Admin Users</div>
    <button class="btn btn-success btn-sm" onclick="openModal('addAdminModal')"><i class="fas fa-plus"></i> Add Admin</button>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Actions</th></tr></thead>
      <tbody>
      <?php $admins=$db->query("SELECT id,fullname,username,role FROM users");
      while($a=$admins->fetchArray(SQLITE3_ASSOC)):?>
        <tr>
          <td><strong><?= htmlspecialchars($a['fullname']) ?></strong></td>
          <td class="mono"><?= htmlspecialchars($a['username']) ?></td>
          <td><span class="badge <?= $a['role']==='master'?'badge-yellow':'badge-blue' ?>"><?= $a['role'] ?></span></td>
          <td>
            <?php if($a['role']==='master'):?>
              <button class="btn btn-secondary btn-xs" onclick="editMaster(<?= $a['id'] ?>,'<?= htmlspecialchars(addslashes($a['fullname'])) ?>','<?= htmlspecialchars(addslashes($a['username'])) ?>')"><i class="fas fa-edit"></i> Edit</button>
            <?php else:?>
              <button class="btn btn-warning btn-xs" onclick="resetPass(<?= $a['id'] ?>)"><i class="fas fa-key"></i> Reset Password</button>
              <a href="?delete_admin=<?= $a['id'] ?>&page=admins" class="btn btn-danger btn-xs" onclick="return confirm('Delete this admin?')"><i class="fas fa-trash"></i></a>
            <?php endif;?>
          </td>
        </tr>
      <?php endwhile;?>
      </tbody>
    </table>
  </div>
</div>
<div class="modal-overlay" id="addAdminModal">
  <div class="modal-box">
    <div class="modal-header-custom"><h5><i class="fas fa-user-plus" style="color:var(--success)"></i> Add Admin</h5><button class="modal-close" onclick="closeModal('addAdminModal')">×</button></div>
    <div class="modal-body-custom">
      <form method="post">
        <input type="hidden" name="action" value="save_admin">
        <div style="margin-bottom:10px"><label class="form-label">Full Name</label><input type="text" name="fullname" class="form-control" required></div>
        <div style="margin-bottom:10px"><label class="form-label">Username</label><input type="text" name="username" class="form-control" required></div>
        <div style="margin-bottom:14px"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div>
        <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Create Admin</button>
      </form>
    </div>
  </div>
</div>
<div class="modal-overlay" id="editMasterModal">
  <div class="modal-box">
    <div class="modal-header-custom"><h5><i class="fas fa-user-cog" style="color:var(--info)"></i> Edit Master Account</h5><button class="modal-close" onclick="closeModal('editMasterModal')">×</button></div>
    <div class="modal-body-custom">
      <form method="post">
        <input type="hidden" name="action" value="edit_master">
        <input type="hidden" name="master_id" id="master_id">
        <div style="margin-bottom:10px"><label class="form-label">Full Name</label><input type="text" name="fullname" id="master_fn" class="form-control" required></div>
        <div style="margin-bottom:10px"><label class="form-label">Username</label><input type="text" name="username" id="master_un" class="form-control" required></div>
        <div style="margin-bottom:14px"><label class="form-label">New Password <small style="font-weight:400">(leave blank to keep)</small></label><input type="password" name="new_password" class="form-control"></div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
      </form>
    </div>
  </div>
</div>
<div class="modal-overlay" id="resetPassModal">
  <div class="modal-box">
    <div class="modal-header-custom"><h5><i class="fas fa-key" style="color:var(--warning)"></i> Reset Password</h5><button class="modal-close" onclick="closeModal('resetPassModal')">×</button></div>
    <div class="modal-body-custom">
      <form method="post">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" id="reset_uid">
        <div style="margin-bottom:14px"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" required></div>
        <button type="submit" class="btn btn-warning"><i class="fas fa-key"></i> Reset</button>
      </form>
    </div>
  </div>
</div>

<div class="card" style="margin-top:20px">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-headset" style="color:var(--accent)"></i> Billing Reminders & Support Contact</div>
  </div>
  <div class="card-body">
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px">
      These contact details and payment accounts are included in automated WhatsApp daily reminders, manual reminders, and payment receipts.
    </p>
    <form method="post" style="max-width:620px">
      <input type="hidden" name="action" value="save_support_settings">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div>
          <label class="form-label">Support 1 Contact Name</label>
          <input type="text" name="support_1_name" class="form-control" value="<?= htmlspecialchars(getSetting($db, 'support_1_name')) ?>" placeholder="e.g. Shajjad Khan" required>
        </div>
        <div>
          <label class="form-label">Support 1 Phone Number</label>
          <input type="text" name="support_1_phone" class="form-control" value="<?= htmlspecialchars(getSetting($db, 'support_1_phone')) ?>" placeholder="e.g. +966597595059" required>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div>
          <label class="form-label">Support 2 Contact Name</label>
          <input type="text" name="support_2_name" class="form-control" value="<?= htmlspecialchars(getSetting($db, 'support_2_name')) ?>" placeholder="e.g. Riyad Hossain">
        </div>
        <div>
          <label class="form-label">Support 2 Phone Number</label>
          <input type="text" name="support_2_phone" class="form-control" value="<?= htmlspecialchars(getSetting($db, 'support_2_phone')) ?>" placeholder="e.g. +966552036454">
        </div>
      </div>
      <div style="margin-bottom:14px">
        <label class="form-label">Payment Accounts (WhatsApp Reminder)</label>
        <textarea name="payment_accounts" class="form-control" rows="10" style="font-family:monospace;font-size:13px;line-height:1.4"><?= htmlspecialchars(getPaymentAccountsText($db)) ?></textarea>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px"><i class="fas fa-info-circle"></i> Sent in WhatsApp reminders so customers know how to pay (STC Pay, Barq, Alinma Bank IBAN).</div>
      </div>
      <div style="margin-bottom:16px">
        <label class="form-label">Movie Server URL</label>
        <input type="text" name="movie_server" class="form-control" value="<?= htmlspecialchars(getSetting($db, 'movie_server')) ?>" placeholder="http://10.12.14.16:8082">
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Support & Payment Settings</button>
    </form>
  </div>
</div>
<?php endif; ?>
</div>
</div>
<?php endif; ?>
<script>
const savedTheme=localStorage.getItem('theme')||'light';
document.documentElement.setAttribute('data-theme',savedTheme);
function updateThemeIcon(){const t=document.documentElement.getAttribute('data-theme');const ic=document.getElementById('theme-icon');if(ic)ic.className=t==='dark'?'fas fa-sun':'fas fa-moon';}
updateThemeIcon();
function toggleTheme(){const cur=document.documentElement.getAttribute('data-theme');const next=cur==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',next);localStorage.setItem('theme',next);updateThemeIcon();}
function openModal(id){
  const el = document.getElementById(id);
  if(el){ el.classList.add('show'); document.body.style.overflow = 'hidden'; }
}
function closeModal(id){
  const el = document.getElementById(id);
  if(el){ el.classList.remove('show'); document.body.style.overflow = ''; }
}
document.addEventListener('click',function(e){
  if(e.target.classList.contains('modal-overlay')){
    e.target.classList.remove('show');
    document.body.style.overflow = '';
  }
});
setTimeout(()=>{const a=document.getElementById('top-alert');if(a)a.remove()},5000);
function clearCustForm(){
  ['cust_id','cust_name','cust_mobile','cust_building','cust_apartment','cust_room','cust_start'].forEach(id=>{
    const el=document.getElementById(id);if(el)el.value='';
  });
  const cc=document.getElementById('cust_country_code');if(cc)cc.value='966';
  const bd=document.getElementById('cust_billing_day');if(bd)bd.value='';
  const fee=document.getElementById('cust_fee');if(fee)fee.value='30';
  const pb=document.getElementById('cust_pay_by_day');if(pb)pb.value='10';
  const rem=document.getElementById('cust_reminders_enabled');if(rem)rem.checked=true;
  const t=document.getElementById('custModalTitle');if(t)t.textContent='Add Customer';
}

function editCust(id,name,mobile,building,apartment,room,day,start,fee,payBy,remindersEnabled){
  document.getElementById('cust_id').value=id;
  document.getElementById('cust_name').value=name;
  document.getElementById('cust_building').value=building||'';
  document.getElementById('cust_apartment').value=apartment||'';
  document.getElementById('cust_room').value=room||'';
  document.getElementById('cust_billing_day').value=day;
  document.getElementById('cust_start').value=start||'';
  document.getElementById('cust_fee').value=fee||'30';
  const pb=document.getElementById('cust_pay_by_day');
  if(pb) pb.value=payBy||'10';
  const rem=document.getElementById('cust_reminders_enabled');
  if(rem) rem.checked = (remindersEnabled !== undefined ? (parseInt(remindersEnabled) === 1) : true);

  const ccSelect=document.getElementById('cust_country_code');
  const mobInput=document.getElementById('cust_mobile');
  let mobStr=String(mobile||'').trim();

  if(mobStr.startsWith('966')){
    if(ccSelect) ccSelect.value='966';
    if(mobInput) mobInput.value='0'+mobStr.slice(3);
  } else if(mobStr.startsWith('880')){
    if(ccSelect) ccSelect.value='880';
    if(mobInput) mobInput.value=mobStr.slice(3);
  } else if(mobStr.startsWith('91')&&mobStr.length>10){
    if(ccSelect) ccSelect.value='91';
    if(mobInput) mobInput.value=mobStr.slice(2);
  } else if(mobStr.startsWith('92')&&mobStr.length>10){
    if(ccSelect) ccSelect.value='92';
    if(mobInput) mobInput.value=mobStr.slice(2);
  } else if(mobStr.startsWith('05')){
    if(ccSelect) ccSelect.value='966';
    if(mobInput) mobInput.value=mobStr;
  } else {
    if(ccSelect) ccSelect.value='';
    if(mobInput) mobInput.value=mobStr;
  }

  document.getElementById('custModalTitle').textContent='Edit Customer';
  openModal('custModal');
}

const custSearch=document.getElementById('custSearch');
const custSearchClear=document.getElementById('custSearchClear');
if(custSearch){
  const custRows=document.querySelectorAll('#custTbody tr');
  const countBadge=document.getElementById('custCountBadge');
  const totalCount=custRows.length;

  function doCustFilter(){
    const raw=custSearch.value.trim().toLowerCase();
    if(custSearchClear) custSearchClear.style.display=raw?'block':'none';

    if(!raw){
      custRows.forEach(r=>{
        r.classList.remove('row-hidden');
        r.style.removeProperty('display');
      });
      if(countBadge){countBadge.textContent=totalCount+' active';countBadge.className='badge badge-blue';}
      const noRow=document.getElementById('custNoMatchRow');
      if(noRow){
        noRow.classList.add('row-hidden');
        noRow.style.setProperty('display','none','important');
      }
      return;
    }

    const tokens=raw.split(/\s+/).filter(Boolean);
    let visibleCount=0;

    custRows.forEach(row=>{
      const n=(row.dataset.n||'').toLowerCase();
      const m=(row.dataset.m||'').toLowerCase();
      const b=(row.dataset.b||'').toLowerCase();
      const apt=(row.dataset.apt||'').toLowerCase();
      const r=(row.dataset.r||'').toLowerCase();

      let mLocal=m, mIntl=m, mShort=m;
      if(m.startsWith('966')){
        mLocal='0'+m.slice(3);
        mShort=m.slice(3);
      } else if(m.startsWith('0')){
        mIntl='966'+m.slice(1);
        mShort=m.slice(1);
      }

      const searchBlob=`${n} ${m} ${mLocal} ${mIntl} ${mShort} ${b} ${apt} ${r}`;

      const matches=tokens.every(tok=>{
        const dig=tok.replace(/\D/g,'');
        if(dig.length>=2&&(m.includes(dig)||mLocal.includes(dig)||mIntl.includes(dig)||mShort.includes(dig))){
          return true;
        }
        return searchBlob.includes(tok);
      });

      if(matches){
        row.classList.remove('row-hidden');
        row.style.removeProperty('display');
        visibleCount++;
      } else {
        row.classList.add('row-hidden');
        row.style.setProperty('display','none','important');
      }
    });

    if(countBadge){
      countBadge.textContent=`${visibleCount} of ${totalCount} matching`;
      countBadge.className=visibleCount>0?'badge badge-green':'badge badge-yellow';
    }

    let noRow=document.getElementById('custNoMatchRow');
    if(visibleCount===0){
      if(!noRow){
        const tbody=document.getElementById('custTbody');
        if(tbody){
          noRow=document.createElement('tr');
          noRow.id='custNoMatchRow';
          noRow.innerHTML=`<td colspan="10" style="text-align:center;padding:24px;color:var(--text-muted)"><i class="fas fa-search" style="font-size:22px;opacity:0.4;display:block;margin-bottom:8px"></i>No customers matching "<strong>${raw.replace(/</g,'&lt;')}</strong>"</td>`;
          tbody.appendChild(noRow);
        }
      } else {
        noRow.classList.remove('row-hidden');
        noRow.style.removeProperty('display');
        const strongEl = noRow.querySelector('strong');
        if(strongEl) strongEl.textContent=raw;
      }
    } else if(noRow){
      noRow.classList.add('row-hidden');
      noRow.style.setProperty('display','none','important');
    }
  }

  custSearch.addEventListener('input',doCustFilter);
  custSearch.addEventListener('search',doCustFilter);
  if(custSearchClear){
    custSearchClear.addEventListener('click',function(){
      custSearch.value='';
      doCustFilter();
      custSearch.focus();
    });
  }
}

const collSearch=document.getElementById('collSearch');
if(collSearch){
  let debounce;
  let abortCtrl=null;

  collSearch.addEventListener('input',function(){
    clearTimeout(debounce);
    const q=this.value.trim();
    const res=document.getElementById('collResults');
    if(!res) return;

    if(q.length<2){
      res.style.display='none';
      res.innerHTML='';
      if(abortCtrl) abortCtrl.abort();
      return;
    }

    res.innerHTML='<div style="padding:12px 14px;color:var(--text-muted);font-size:12px"><i class="fas fa-spinner fa-spin" style="margin-right:6px"></i>Searching customer…</div>';
    res.style.display='block';

    debounce=setTimeout(()=>{
      if(abortCtrl) abortCtrl.abort();
      abortCtrl=new AbortController();

      fetch('search_customers.php?q='+encodeURIComponent(q),{signal:abortCtrl.signal})
        .then(r=>r.json())
        .then(data=>{
          if(!data||!data.length){
            res.innerHTML='<div style="padding:14px;text-align:center;color:var(--text-muted);font-size:13px"><i class="fas fa-search" style="opacity:0.5;margin-right:6px"></i>No active customers matching "<strong>'+q.replace(/</g,'&lt;')+'</strong>"</div>';
            res.style.display='block';
            return;
          }
          res.innerHTML=data.map(c=>`
            <div class="search-result-item" onclick="openCollectModal(${c.id},'${c.name.replace(/'/g,"\\'")}');document.getElementById('collResults').style.display='none'" style="padding:10px 14px;cursor:pointer">
              <div style="flex:1;min-width:0;padding-right:12px">
                <div style="font-weight:600;font-size:14px;color:var(--text);display:flex;align-items:center;gap:6px">
                  <span>${c.name}</span>
                  <span class="badge badge-blue" style="font-size:10px">Day ${c.billing_day||'—'}</span>${parseInt(c.reminders_enabled||1)===0 ? ' <span class="badge badge-yellow" style="font-size:10px"><i class="fas fa-bell-slash"></i> Muted</span>' : ''}${parseInt(c.is_on_vacation||0) > 0 ? ' <span class="badge badge-yellow" style="font-size:10px"><i class="fas fa-umbrella-beach"></i> Vacation</span>' : ''}
                </div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                  <span class="mono" style="color:var(--accent)"><i class="fas fa-phone-alt" style="font-size:10px;margin-right:3px"></i>${c.mobile}</span>
                  <span>·</span>
                  <span><i class="fas fa-map-marker-alt" style="font-size:10px;margin-right:3px"></i>${(c.building||'')+' '+(c.apartment||'')+' '+(c.room?'R'+c.room:'')}</span>
                  <span>·</span>
                  <span class="mono" style="color:var(--success)">${c.monthly_fee||30} SAR</span>
                </div>
              </div>
              <div style="display:flex;gap:6px;flex-shrink:0" onclick="event.stopPropagation()">
                <button type="button" class="btn btn-primary btn-sm" onclick="openCollectModal(${c.id},'${c.name.replace(/'/g,"\\'")}');document.getElementById('collResults').style.display='none'"><i class='fas fa-money-bill-wave'></i> Collect</button>
                <button type="button" class="btn btn-whatsapp btn-sm" title="Send WhatsApp Reminder" onclick="sendReminderSimple('${c.mobile}','${c.name.replace(/'/g,"\\'")}',${c.id},this)"><i class='fab fa-whatsapp'></i></button>
              </div>
            </div>
          `).join('');
          res.style.display='block';
        })
        .catch(err=>{
          if(err.name==='AbortError') return;
          res.innerHTML='<div style="padding:12px;text-align:center;color:var(--danger)">Error fetching results</div>';
        });
    },180);
  });

  document.addEventListener('click',e=>{
    const res=document.getElementById('collResults');
    if(res&&!collSearch.contains(e.target)&&!res.contains(e.target)){
      res.style.display='none';
    }
  });

  collSearch.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
      const res=document.getElementById('collResults');
      if(res) res.style.display='none';
    }
  });
  collSearch.addEventListener('search', function(){
    if(!this.value.trim()){
      const res=document.getElementById('collResults');
      if(res){ res.style.display='none'; res.innerHTML=''; }
    }
  });
}
function openEditHoldModal(hid, name, start, end, reason) {
  document.getElementById('edit_hold_id').value = hid;
  document.getElementById('edit_hold_cust_name').value = name;
  document.getElementById('edit_hold_start').value = start;
  document.getElementById('edit_hold_end').value = end || '';
  document.getElementById('edit_hold_reason').value = reason || 'Vacation';
  document.getElementById('editHoldTitle').textContent = 'Edit Hold: ' + name;
  openModal('editHoldModal');
}

function openPromiseModal(cid,name,pid){
  document.getElementById('promise_cid').value=cid;
  document.getElementById('promise_pid').value=pid;
  document.getElementById('promise_name').textContent='Customer: '+name;
  // Default date = 7 days from now
  var d=new Date(); d.setDate(d.getDate()+7);
  document.getElementById('promise_date').value=d.toISOString().split('T')[0];
  document.getElementById('promise_note').value='';
  openModal('promiseModal');
}
function openCollectModal(cid,name){document.getElementById('coll_cid').value=cid;document.getElementById('coll_name').textContent='Customer: '+name;document.getElementById('unpaidList').innerHTML='<div style="padding:20px;text-align:center;color:var(--text-muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';openModal('collectModal');fetch('get_unpaid.php?cid='+cid).then(r=>r.json()).then(data=>{if(!data.length){document.getElementById('unpaidList').innerHTML='<div class="alert alert-success"><i class="fas fa-check-circle"></i> All bills are paid for this customer!</div>';document.getElementById('totalAmt').textContent='0';return;}let html='<div class="table-wrap"><table><thead><tr><th>✓ Settle</th><th>Month</th><th>Due</th><th>Amount Collected (SAR)</th></tr></thead><tbody>';data.forEach((m,i)=>{html+=`<tr><td><input type="checkbox" name="months[]" value="${m.month}" class="month-cb" checked onchange="updateTotal()" title="Check to settle this month"></td><td><strong>${new Date(m.month+'-02').toLocaleDateString('en',{year:'numeric',month:'long'})}</strong></td><td><span class="badge badge-red mono">${m.due} SAR</span></td><td><input type="number" name="amounts[]" class="form-control amt-input" value="${m.due}" min="0" step="0.5" style="width:120px" onchange="updateTotal()"></td></tr>`;});html+='</tbody></table></div>';document.getElementById('unpaidList').innerHTML=html;updateTotal();});}
function updateTotal(){let total=0;document.querySelectorAll('.amt-input').forEach(inp=>{const row=inp.closest('tr');const cb=row?.querySelector('.month-cb');if(!cb||cb.checked)total+=parseFloat(inp.value)||0;});document.getElementById('totalAmt').textContent=total.toFixed(2);}
function showHistory(cid,name){
  const t = document.getElementById('histModalTitle');
  if (t) t.innerHTML = '<i class="fas fa-history" style="color:var(--info)"></i> ' + (name ? name + ' — History' : 'Payment History');
  document.getElementById('histContent').innerHTML='<div style="text-align:center;padding:28px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin" style="margin-right:6px"></i> Loading payment records…</div>';
  openModal('histModal');
  fetch('get_history.php?cid='+cid)
    .then(r=>r.text())
    .then(html=>{document.getElementById('histContent').innerHTML=html;})
    .catch(()=>{document.getElementById('histContent').innerHTML='<div style="padding:20px;text-align:center;color:var(--danger)">Failed to load payment history.</div>';});
}
function getWaConfig(){return{url:'<?= getSetting($db,"openwa_url") ?>',key:'<?= getSetting($db,"openwa_api_key") ?>',sid:document.getElementById('session-id-display')?.textContent||'<?= getSetting($db,"openwa_session_id") ?>'};}
function sendWaMsg(mobile,message){const cfg=getWaConfig();let phone=mobile.replace(/^0+/,'');if(!/^966/.test(phone))phone='966'+phone;const chatId=phone+'@c.us';const url=`${cfg.url}/api/sessions/${cfg.sid}/messages/send-text`;return fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':cfg.key},body:JSON.stringify({chatId,text:message})});}


let movieUploadAbortController = null;
let movieUploadCancelled = false;

function onMovieFileSelected(input) {
  const file = input.files[0];
  if (!file) return;
  const titleInput = document.getElementById('movieTitle');
  if (!titleInput.value.trim()) {
    const cleanName = file.name.replace(/\.[^/.]+$/, "").replace(/[._-]/g, " ");
    titleInput.value = cleanName;
  }
}

function cancelMovieUpload() {
  if (confirm('Cancel this movie upload?')) {
    movieUploadCancelled = true;
    if (movieUploadAbortController) movieUploadAbortController.abort();
    document.getElementById('movieProgressWrap').style.display = 'none';
    document.getElementById('movieUploadBtn').disabled = false;
    showToast('Upload cancelled', 'error');
  }
}

async function handleMovieUpload(e) {
  e.preventDefault();
  const fileInput = document.getElementById('movieFile');
  const file = fileInput.files[0];
  if (!file) { showToast('Please select a file', 'error'); return; }

  const title = document.getElementById('movieTitle').value.trim();
  const desc = document.getElementById('movieDesc').value.trim();

  const chunkSize = 16 * 1024 * 1024; // 16MB chunk for high throughput
  const totalChunks = Math.ceil(file.size / chunkSize);
  const sessionId = 'up_' + Date.now() + '_' + Math.floor(Math.random() * 10000);

  const progWrap = document.getElementById('movieProgressWrap');
  const bar = document.getElementById('movieProgressBar');
  const pctEl = document.getElementById('movieUploadPct');
  const statEl = document.getElementById('movieUploadStatus');
  const detailsEl = document.getElementById('movieUploadDetails');
  const speedEl = document.getElementById('movieUploadSpeed');
  const btn = document.getElementById('movieUploadBtn');

  progWrap.style.display = 'block';
  btn.disabled = true;
  movieUploadCancelled = false;
  bar.style.width = '0%';
  pctEl.textContent = '0%';
  statEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing upload (0/' + totalChunks + ')...';

  const startTime = Date.now();
  let uploadedBytes = 0;

  for (let chunkIdx = 0; chunkIdx < totalChunks; chunkIdx++) {
    if (movieUploadCancelled) break;

    const start = chunkIdx * chunkSize;
    const end = Math.min(start + chunkSize, file.size);
    const chunkBlob = file.slice(start, end);

    const fd = new FormData();
    fd.append('action', 'upload_chunk');
    fd.append('file', chunkBlob, file.name);
    fd.append('chunk', chunkIdx);
    fd.append('total_chunks', totalChunks);
    fd.append('chunk_size', chunkSize);
    fd.append('file_size', file.size);
    fd.append('session_id', sessionId);
    fd.append('title', title);
    fd.append('description', desc);
    fd.append('original_name', file.name);

    movieUploadAbortController = new AbortController();

    let attempt = 0;
    let success = false;

    while (attempt < 3 && !success && !movieUploadCancelled) {
      attempt++;
      try {
        if (chunkIdx + 1 === totalChunks) {
          statEl.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Finalizing movie (${chunkIdx + 1}/${totalChunks})...`;
        }
        const resp = await fetch('portal.php', {
          method: 'POST',
          body: fd,
          signal: movieUploadAbortController.signal
        });
        const text = await resp.text();
        let data;
        try {
          data = JSON.parse(text);
        } catch (e) {
          throw new Error('HTTP ' + resp.status + ': ' + text.slice(0, 100));
        }

        if (data.success) {
          success = true;
          uploadedBytes = end;

          const pct = Math.round((uploadedBytes / file.size) * 100);
          bar.style.width = pct + '%';
          pctEl.textContent = pct + '%';

          const uploadedMB = (uploadedBytes / (1024 * 1024)).toFixed(1);
          const totalMB = (file.size / (1024 * 1024)).toFixed(1);
          detailsEl.textContent = `${uploadedMB} MB / ${totalMB} MB`;

          const elapsedSec = (Date.now() - startTime) / 1000;
          const bytesPerSec = elapsedSec > 0 ? uploadedBytes / elapsedSec : 0;
          const mbPerSec = (bytesPerSec / (1024 * 1024)).toFixed(1);
          const remainingSec = bytesPerSec > 0 ? Math.round((file.size - uploadedBytes) / bytesPerSec) : 0;
          const etaMin = Math.floor(remainingSec / 60);
          const etaSec = remainingSec % 60;
          speedEl.textContent = `${mbPerSec} MB/s • ETA: ${etaMin}m ${etaSec}s`;
          if (chunkIdx + 1 < totalChunks) {
            statEl.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Uploading chunk ${chunkIdx + 1} of ${totalChunks}...`;
          }

          if (data.completed) {
            statEl.innerHTML = '<i class="fas fa-check-circle" style="color:var(--success)"></i> Upload Complete!';
            bar.style.width = '100%';
            pctEl.textContent = '100%';
            showToast('✅ Movie uploaded successfully: ' + title, 'success');
            setTimeout(() => { window.location.reload(); }, 1200);
            return;
          }
        } else {
          throw new Error(data.error || 'Chunk error');
        }
      } catch (err) {
        if (movieUploadCancelled) return;
        if (attempt >= 3) {
          statEl.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:var(--danger)"></i> Upload failed';
          showToast('❌ Upload failed on chunk ' + (chunkIdx + 1) + ': ' + err.message, 'error');
          btn.disabled = false;
          return;
        }
        await new Promise(r => setTimeout(r, 1000));
      }
    }
  }

  btn.disabled = false;
}

function showToast(msg, type = 'success') {
  const c = document.getElementById('toastContainer');
  if (!c) { alert(msg); return; }
  const t = document.createElement('div');
  t.className = 'toast-msg';
  t.style.cssText = `pointer-events:auto;min-width:260px;max-width:380px;background:${type==='success'?'#10b981':'#ef4444'};color:#fff;padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 6px 16px rgba(0,0,0,0.25);display:flex;align-items:center;gap:10px;`;
  t.innerHTML = `<span>${msg}</span><button style="margin-left:auto;background:none;border:none;color:#fff;font-size:16px;cursor:pointer" onclick="this.parentElement.remove()">×</button>`;
  c.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .4s'; setTimeout(() => t.remove(), 400); }, 3500);
}

function copyToClipboard(text) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(text).then(() => showToast('📋 Receipt text copied to clipboard!'));
  } else {
    const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
    showToast('📋 Receipt text copied!');
  }
}

let activePendingFilter = 'all';
function setPendingFilter(type, btn) {
  activePendingFilter = type;
  document.querySelectorAll('.filter-pill').forEach(el => el.classList.remove('active'));
  btn.classList.add('active');
  filterPendingTable();
}

function filterPendingTable() {
  const q = (document.getElementById('pendingFilterInput')?.value || '').toLowerCase().trim();
  const rows = document.querySelectorAll('#pendingTable tbody tr:not(#pendingNoMatchRow)');
  let visibleCount = 0;
  const tokens = q ? q.split(/\s+/).filter(Boolean) : [];

  rows.forEach(r => {
    const text = r.textContent.toLowerCase();
    const hasPromise = r.getAttribute('data-has-promise') === '1';
    const unpaidCount = parseInt(r.getAttribute('data-unpaid-count') || '1');
    const bldg = (r.getAttribute('data-building') || '').toLowerCase();
    const room = (r.getAttribute('data-room') || '').toLowerCase();
    
    let passFilter = true;
    if (activePendingFilter === 'promise') passFilter = hasPromise;
    else if (activePendingFilter === 'multi') passFilter = (unpaidCount > 1);

    let passQuery = true;
    if (tokens.length > 0) {
      passQuery = tokens.every(tok => {
        if (text.includes(tok) || bldg.includes(tok) || room.includes(tok)) return true;
        const dig = tok.replace(/\D/g, '');
        if (dig.length >= 2) {
          if (dig.startsWith('05') && text.includes('966' + dig.slice(1))) return true;
          if (dig.startsWith('9665') && text.includes('0' + dig.slice(3))) return true;
          if (dig.startsWith('5') && (text.includes('0' + dig) || text.includes('966' + dig))) return true;
        }
        return false;
      });
    }

    if (passFilter && passQuery) {
      r.classList.remove('row-hidden');
      r.style.removeProperty('display');
      visibleCount++;
    } else {
      r.classList.add('row-hidden');
      r.style.setProperty('display', 'none', 'important');
    }
  });

  const badge = document.getElementById('pendingBadgeCount');
  if (badge) badge.textContent = visibleCount + ' shown';

  let noRow = document.getElementById('pendingNoMatchRow');
  if (visibleCount === 0 && (q || activePendingFilter !== 'all')) {
    if (!noRow) {
      const tbody = document.querySelector('#pendingTable tbody');
      if (tbody) {
        noRow = document.createElement('tr');
        noRow.id = 'pendingNoMatchRow';
        noRow.innerHTML = `<td colspan="8" style="text-align:center;padding:24px;color:var(--text-muted)"><i class="fas fa-search" style="font-size:22px;opacity:0.4;display:block;margin-bottom:8px"></i>No pending bills matching current search/filter</td>`;
        tbody.appendChild(noRow);
      }
    } else {
      noRow.classList.remove('row-hidden');
      noRow.style.removeProperty('display');
    }
  } else if (noRow) {
    noRow.classList.add('row-hidden');
    noRow.style.setProperty('display', 'none', 'important');
  }
}

function toggleReminders(cid, btnEl) {
  if (!cid) return;
  const btn = btnEl || event?.target?.closest('button');
  const origHtml = btn ? btn.innerHTML : null;
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  }
  const fd = new FormData();
  fd.append('action', 'toggle_reminders');
  fd.append('customer_id', cid);

  fetch('portal.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (btn) btn.disabled = false;
      if (d.ok) {
        const isEnabled = (parseInt(d.reminders_enabled) === 1);
        const icon = isEnabled ? 'fa-bell' : 'fa-bell-slash';
        const label = isEnabled ? 'Active' : 'Muted';
        const addCls = isEnabled ? 'is-active' : 'is-muted';
        const remCls = isEnabled ? 'is-muted' : 'is-active';

        // Update all toggle badges for this customer (desktop & mobile)
        document.querySelectorAll(`[id="rem-toggle-desk-${cid}"], [id="rem-toggle-mob-${cid}"]`).forEach(el => {
          el.classList.remove(remCls);
          el.classList.add(addCls);
          el.innerHTML = `<i class="fas ${icon}"></i> <span>${label}</span>`;
          el.title = isEnabled ? 'Reminders are Active. Click to Mute' : 'Reminders are Muted. Click to Enable';
        });

        // Update WhatsApp action buttons for this customer
        document.querySelectorAll(`[id="rem-wa-btn-${cid}"]`).forEach(el => {
          const mob = el.getAttribute('data-mobile') || '';
          if (isEnabled) {
            el.classList.remove('btn-wa-muted');
            el.innerHTML = '<i class="fab fa-whatsapp"></i> <span class="mobile-action-label">WhatsApp</span>';
            el.title = 'Send WhatsApp Reminder';
            el.onclick = function() { sendReminderSimple(mob, d.name, cid, this, 1); };
          } else {
            el.classList.add('btn-wa-muted');
            el.innerHTML = '<i class="fas fa-bell-slash"></i> <span class="mobile-action-label">Muted</span>';
            el.title = 'Reminders are Muted';
            el.onclick = function() { sendReminderSimple(mob, d.name, cid, this, 0); };
          }
        });

        if (isEnabled) {
          showToast(`🔔 Reminders enabled for ${d.name}`, 'success');
        } else {
          showToast(`🔕 Reminders muted for ${d.name} (no messages will be sent)`, 'success');
        }
      } else {
        if (btn) btn.innerHTML = origHtml;
        showToast('❌ ' + (d.error || 'Failed to update reminder status'), 'error');
      }
    })
    .catch(() => {
      if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
      showToast('❌ Network error - check connection', 'error');
    });
}

function sendReminderSimple(mobile,name,custId,btnEl,remindersEnabled){
  if(!custId){showToast('Missing customer ID', 'error');return;}
  if(remindersEnabled === 0){
    showToast('🔕 Reminders are muted for '+name+'. Click the Muted button to turn on first.', 'error');
    return;
  }
  const btn = btnEl || event?.target?.closest('button');
  const origHtml = btn ? btn.innerHTML : null;
  if(btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
  const fd=new FormData();fd.append('action','send_manual_reminder');fd.append('customer_id',custId);
  fetch('portal.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(btn) { btn.disabled = false; btn.innerHTML = origHtml; }
    if(d.ok) showToast('✅ Reminder sent to '+name, 'success');
    else showToast('❌ '+(d.error||'Failed to send'), 'error');
  }).catch(()=>{
    if(btn) { btn.disabled = false; btn.innerHTML = origHtml; }
    showToast('❌ Network error - check connection', 'error');
  });
}
function checkWaStatus(){const el=document.getElementById('wa-status-display');if(!el)return;el.innerHTML='<div style="color:var(--text-muted)"><i class="fas fa-spinner fa-spin"></i> Checking…</div>';fetch('portal.php?action=wa_status').then(r=>r.json()).then(d=>{const s=d.session;const status=s?.status||'unknown';const dotClass=status==='CONNECTED'||status==='ready'?'connected':(status==='WAITING_QR'?'waiting':'disconnected');el.innerHTML=`<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px"><span class="wa-status-dot ${dotClass}"></span><strong style="font-size:16px">${status}</strong></div><div style="font-size:12px;color:var(--text-muted)">${s?.name?'<div>Session name: '+s.name+'</div>':''}${s?.phoneNumber?'<div>Phone: '+s.phoneNumber+'</div>':''}</div>`;}).catch(()=>{el.innerHTML='<div style="color:var(--danger)"><i class="fas fa-exclamation-triangle"></i> Could not reach OpenWA</div>';});}
function fetchQR(){document.getElementById('qr-container').innerHTML='<div style="color:var(--text-muted)"><i class="fas fa-spinner fa-spin"></i> Fetching QR…</div>';fetch('portal.php?action=wa_qr').then(r=>r.json()).then(d=>{if(d.qrCode){document.getElementById('qr-container').innerHTML=`<div><img src="${d.qrCode}" style="max-width:240px;border-radius:8px"><p style="font-size:12px;color:var(--text-muted);margin-top:8px">Scan with WhatsApp</p></div>`;}else{document.getElementById('qr-container').innerHTML='<div style="color:var(--text-muted)">No QR available. Session may already be connected, or try Reconnect first.</div>';}}).catch(()=>{document.getElementById('qr-container').innerHTML='<div style="color:var(--danger)">Error fetching QR</div>';});}
function doReconnect(){if(!confirm('This will disconnect the current WhatsApp session and create a new one. Proceed?'))return;const logEl=document.getElementById('wa-log');logEl.innerHTML='<i class="fas fa-spinner fa-spin"></i> Reconnecting…';document.getElementById('qr-container').innerHTML='<div style="color:var(--text-muted)"><i class="fas fa-spinner fa-spin"></i> Please wait…</div>';fetch('portal.php?action=wa_reconnect',{method:'POST'}).then(r=>r.json()).then(d=>{if(d.success){logEl.innerHTML=d.log.map(l=>`<div>✓ ${l}</div>`).join('');document.getElementById('session-id-display').textContent=d.new_session_id;setTimeout(fetchQR,2000);setTimeout(checkWaStatus,4000);}else{logEl.innerHTML='<span style="color:var(--danger)">Error: '+(d.error||'Unknown')+'</span>';}}).catch(()=>{logEl.innerHTML='<span style="color:var(--danger)">Network error</span>';});}
function sendTestMsg(){const phone=document.getElementById('test-phone').value.trim();const msg=document.getElementById('test-msg').value.trim();const res=document.getElementById('test-result');if(!phone||!msg){res.innerHTML='<span style="color:var(--danger)">Enter phone and message</span>';return;}res.innerHTML='<i class="fas fa-spinner fa-spin"></i> Sending…';sendWaMsg(phone,msg).then(r=>r.json()).then(d=>{res.innerHTML=`<span style="color:var(--success)">✅ Sent!</span>`;}).catch(e=>{res.innerHTML=`<span style="color:var(--danger)">❌ Error: ${e}</span>`;});}
if(document.getElementById('wa-status-display'))checkWaStatus();
function editMaster(id,fn,un){document.getElementById('master_id').value=id;document.getElementById('master_fn').value=fn;document.getElementById('master_un').value=un;openModal('editMasterModal');}
function resetPass(id){document.getElementById('reset_uid').value=id;openModal('resetPassModal');}
</script>
<script>
(function(){
  var inp = document.getElementById("advCustSearch");
  if(!inp) return;
  var sug = document.getElementById("advCustSug"), hid = document.getElementById("advCustId");
  var debounceTimer, activeController;

  inp.addEventListener("input", function() {
    clearTimeout(debounceTimer);
    var q = this.value.trim();
    hid.value = "";
    if (q.length < 2) {
      sug.style.display = "none";
      sug.innerHTML = "";
      if (activeController) activeController.abort();
      return;
    }
    sug.innerHTML = "<div style='padding:10px 14px;font-size:12px;color:var(--text-muted)'><i class='fas fa-spinner fa-spin' style='margin-right:6px'></i>Searching…</div>";
    sug.style.display = "block";

    debounceTimer = setTimeout(function() {
      if (activeController) activeController.abort();
      activeController = new AbortController();

      fetch("search_customers.php?q=" + encodeURIComponent(q), { signal: activeController.signal })
        .then(r => r.json())
        .then(d => {
          if (!d.length) {
            sug.innerHTML = "<div style='padding:10px 14px;font-size:12px;color:var(--text-muted)'>No active customer match</div>";
            sug.style.display = "block";
            return;
          }
          sug.innerHTML = d.map(x => `
            <div class='adv-sug-item' data-id='${x.id}' data-name='${x.name.replace(/'/g,"&#39;")}' style='padding:10px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center'>
              <div>
                <strong>${x.name}</strong>
                <div style='font-size:11px;color:var(--text-muted);margin-top:2px'>${x.mobile} · ${(x.building || '') + ' ' + (x.room ? 'R' + x.room : '')}</div>
              </div>
              <span class='badge badge-blue' style='font-size:10px'>Day ${x.billing_day || '—'}</span>
            </div>
          `).join("");
          sug.style.display = "block";

          sug.querySelectorAll(".adv-sug-item").forEach(el => {
            el.onclick = function() {
              hid.value = this.dataset.id;
              inp.value = this.dataset.name;
              sug.style.display = "none";
            };
            el.onmouseover = function() { this.style.background = "var(--surface2)"; };
            el.onmouseout = function() { this.style.background = ""; };
          });
        })
        .catch(err => {
          if (err.name === 'AbortError') return;
        });
    }, 180);
  });

  document.addEventListener("click", function(e) {
    if (sug && !sug.contains(e.target) && e.target !== inp) sug.style.display = "none";
  });
})();
</script>
<script>
let activeBalanceFilter = 'all';

function setBalanceFilter(type, btn) {
  activeBalanceFilter = type;
  document.querySelectorAll('.balance-filter-pill').forEach(el => el.classList.remove('active'));
  if (btn) btn.classList.add('active');
  filterBalance();
}

function filterBalance(query) {
  const q = (query !== undefined ? query : (document.getElementById('balanceSearch')?.value || '')).toLowerCase().trim();
  const table = document.getElementById('balanceTable') || document.querySelector('table');
  if (!table) return;
  const rows = table.querySelectorAll('tbody tr:not(.table-total):not(#balanceNoMatchRow)');
  const tokens = q ? q.split(/\s+/).filter(Boolean) : [];
  let visibleCount = 0;

  rows.forEach(r => {
    const text = r.textContent.toLowerCase();
    const status = r.getAttribute('data-status') || '';

    let passFilter = true;
    if (activeBalanceFilter === 'owing') passFilter = (status === 'owing');
    else if (activeBalanceFilter === 'settled') passFilter = (status === 'settled');
    else if (activeBalanceFilter === 'credit') passFilter = (status === 'credit');

    let passQuery = true;
    if (tokens.length > 0) {
      passQuery = tokens.every(tok => {
        if (text.includes(tok)) return true;
        const dig = tok.replace(/\D/g, '');
        if (dig.length >= 2) {
          if (dig.startsWith('05') && text.includes('966' + dig.slice(1))) return true;
          if (dig.startsWith('9665') && text.includes('0' + dig.slice(3))) return true;
          if (dig.startsWith('5') && (text.includes('0' + dig) || text.includes('966' + dig))) return true;
        }
        return false;
      });
    }

    if (passFilter && passQuery) {
      r.classList.remove('row-hidden');
      r.style.removeProperty('display');
      visibleCount++;
    } else {
      r.classList.add('row-hidden');
      r.style.setProperty('display', 'none', 'important');
    }
  });

  let noRow = document.getElementById('balanceNoMatchRow');
  if (visibleCount === 0 && (q || activeBalanceFilter !== 'all')) {
    if (!noRow) {
      const tbody = table.querySelector('tbody');
      if (tbody) {
        noRow = document.createElement('tr');
        noRow.id = 'balanceNoMatchRow';
        noRow.innerHTML = `<td colspan="7" style="text-align:center;padding:24px;color:var(--text-muted)"><i class="fas fa-search" style="font-size:22px;opacity:0.4;display:block;margin-bottom:8px"></i>No records matching current search/filter</td>`;
        tbody.appendChild(noRow);
      }
    } else {
      noRow.classList.remove('row-hidden');
      noRow.style.removeProperty('display');
    }
  } else if (noRow) {
    noRow.classList.add('row-hidden');
    noRow.style.setProperty('display', 'none', 'important');
  }
}
</script>
<script>
(function(){
  var inp = document.getElementById("holdCustSearch");
  if(!inp) return;
  var sug = document.getElementById("holdCustSug"), hid = document.getElementById("holdCustId");
  var debounceTimer, activeController;

  inp.addEventListener("input", function() {
    clearTimeout(debounceTimer);
    var q = this.value.trim();
    hid.value = "";
    if (q.length < 2) {
      sug.style.display = "none";
      sug.innerHTML = "";
      if (activeController) activeController.abort();
      return;
    }
    sug.innerHTML = "<div style='padding:10px 14px;font-size:12px;color:var(--text-muted)'><i class='fas fa-spinner fa-spin' style='margin-right:6px'></i>Searching…</div>";
    sug.style.display = "block";

    debounceTimer = setTimeout(function() {
      if (activeController) activeController.abort();
      activeController = new AbortController();

      fetch("search_customers.php?q=" + encodeURIComponent(q), { signal: activeController.signal })
        .then(r => r.json())
        .then(d => {
          if (!d.length) {
            sug.innerHTML = "<div style='padding:10px 14px;font-size:12px;color:var(--text-muted)'>No active customer match</div>";
            sug.style.display = "block";
            return;
          }
          sug.innerHTML = d.map(x => `
            <div class='hold-sug-item' data-id='${x.id}' data-name='${x.name.replace(/'/g,"&#39;")}' style='padding:10px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center'>
              <div>
                <strong>${x.name}</strong>
                <div style='font-size:11px;color:var(--text-muted);margin-top:2px'>${x.mobile} · ${(x.building || '') + ' ' + (x.room ? 'R' + x.room : '')}</div>
              </div>
              <span class='badge badge-blue' style='font-size:10px'>Day ${x.billing_day || '—'}</span>
            </div>
          `).join("");
          sug.style.display = "block";

          sug.querySelectorAll(".hold-sug-item").forEach(el => {
            el.onclick = function() {
              hid.value = this.dataset.id;
              inp.value = this.dataset.name;
              sug.style.display = "none";
            };
            el.onmouseover = function() { this.style.background = "var(--surface2)"; };
            el.onmouseout = function() { this.style.background = ""; };
          });
        })
        .catch(err => {
          if (err.name === 'AbortError') return;
        });
    }, 180);
  });

  document.addEventListener("click", function(e) {
    if (sug && !sug.contains(e.target) && e.target !== inp) sug.style.display = "none";
  });
})();
</script>
<button id="btnScrollTop" onclick="window.scrollTo({top:0,behavior:'smooth'})" class="btn-scroll-top" title="Scroll to top" aria-label="Scroll to top"><i class="fas fa-chevron-up"></i></button>
<div class="modal-overlay" id="histModal">
  <div class="modal-box" style="max-width:700px;width:95%">
    <div class="modal-header-custom">
      <h5 id="histModalTitle"><i class="fas fa-history" style="color:var(--info)"></i> Billing & Transaction History</h5>
      <button type="button" class="modal-close" onclick="closeModal('histModal')">×</button>
    </div>
    <div class="modal-body-custom" id="histContent" style="padding:16px"></div>
  </div>
</div>

<script>
window.addEventListener('scroll', function() {
  const btn = document.getElementById('btnScrollTop');
  if (!btn) return;
  if (window.scrollY > 280) btn.classList.add('visible');
  else btn.classList.remove('visible');
}, { passive: true });

document.addEventListener('DOMContentLoaded', function() {
  const activeTab = document.querySelector('.nav-tabs-wrap a.active');
  if (activeTab) {
    try {
      activeTab.scrollIntoView({ behavior: 'auto', inline: 'center', block: 'nearest' });
    } catch(e) {}
  }
});
</script>
</body>
</html>
