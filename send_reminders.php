<?php
date_default_timezone_set('Asia/Riyadh');
$db = new SQLite3('/home/tserver/billing_reminder/bills.db');

function getSetting($db, $key) {
    return $db->querySingle("SELECT value FROM settings WHERE key='".SQLite3::escapeString($key)."'") ?: '';
}

function getEffectiveBillingStart($billing_start_date, $billing_day) {
    if (!$billing_start_date) return new DateTime(date('Y-m-01'));
    $dt       = new DateTime($billing_start_date);
    $join_day = (int)$dt->format('j');
    if ($join_day > (int)$billing_day) {
        $dt->modify('first day of next month');
    } else {
        $dt = new DateTime($dt->format('Y-m').'-01');
    }
    return $dt;
}

function hasUnpaidBalance($db, $customer_id, $month_year, $monthly_fee) {
    $paid = $db->querySingle("SELECT COALESCE(SUM(amount),0) FROM collections WHERE customer_id=$customer_id AND month_year='$month_year'");
    return ($monthly_fee - floatval($paid)) > 0.01;
}

$today      = new DateTime();
$target_5   = (clone $today)->modify('+5 days');
$target_2   = (clone $today)->modify('+2 days');
$target_days = array_unique([$target_5->format('j'), $target_2->format('j')]);

$base_url   = getSetting($db, 'openwa_url');
$api_key    = getSetting($db, 'openwa_api_key');
$session_id = getSetting($db, 'openwa_session_id');
$movie      = getSetting($db, 'movie_server');
$s1n        = getSetting($db, 'support_1_name');
$s1p        = getSetting($db, 'support_1_phone');
$s2n        = getSetting($db, 'support_2_name');
$s2p        = getSetting($db, 'support_2_phone');
$s3n        = getSetting($db, 'support_3_name');
$s3p        = getSetting($db, 'support_3_phone');
$openwa_url = "$base_url/api/sessions/$session_id/messages/send-text";

echo "[".date('Y-m-d H:i:s')."] Reminder check starting\n";

$placeholders = implode(',', array_fill(0, count($target_days), '?'));
$stmt = $db->prepare("SELECT id, name, mobile, billing_day, billing_start_date, monthly_fee
    FROM customers WHERE status='active' AND billing_day IN ($placeholders)");
foreach ($target_days as $i => $day) {
    $stmt->bindValue($i + 1, (int)$day, SQLITE3_INTEGER);
}
$customers = $stmt->execute();

$sent = 0; $skipped = 0;

while ($c = $customers->fetchArray(SQLITE3_ASSOC)) {
    $fee = floatval($c['monthly_fee'] ?: 30);
    $bd  = (int)($c['billing_day'] ?: 1);

    $today_day = (int)date('j');
    if ($bd >= $today_day) {
        $upcoming_month = date('Y-m');
    } else {
        $upcoming_month = date('Y-m', strtotime('first day of next month'));
    }

    $eff_start = getEffectiveBillingStart($c['billing_start_date'], $bd);
    if (new DateTime($upcoming_month.'-01') < $eff_start) {
        echo "  SKIP (not started) {$c['name']}\n"; $skipped++; continue;
    }

    if (!hasUnpaidBalance($db, $c['id'], $upcoming_month, $fee)) {
        echo "  SKIP (paid) {$c['name']} — {$upcoming_month} already settled\n";
        $skipped++; continue;
    }

    if ($bd >= $today_day) {
        $days_left = $bd - $today_day;
    } else {
        $days_left = $bd + ((int)date('t') - $today_day);
    }

    $month_label = date('F Y', strtotime($upcoming_month.'-01'));
    $message  = "Dear {$c['name']},\n\n";
    $message .= "⏰ Your internet bill is due in $days_left days (Day {$c['billing_day']} of each month).\n";
    $message .= "Month: $month_label\n";
    $message .= "Amount: {$fee} SAR\n\n";
    $message .= "🎬 Movies: $movie\n\n";
    $message .= "📞 Support (24/7):\n";
    $message .= "  $s1n: $s1p\n  $s2n: $s2p\n  $s3n: $s3p\n\n";
    $message .= "Please pay on time to avoid service interruption. Thank you!";

    $phone  = preg_replace('/^0+/', '', $c['mobile']);
    if (!preg_match('/^966/', $phone)) $phone = '966'.$phone;
    $chatId = $phone.'@c.us';

    $ch = curl_init($openwa_url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json", "X-API-Key: $api_key"],
        CURLOPT_POSTFIELDS     => json_encode(['chatId' => $chatId, 'text' => $message]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $status = ($code >= 200 && $code < 300) ? 'SENT' : "FAIL(HTTP $code)";
    echo "  $status {$c['name']} ({$c['mobile']}) — due day {$bd}, $days_left days, fee $fee SAR\n";
    $sent++;
}

echo "[".date('Y-m-d H:i:s')."] Done. Sent: $sent, Skipped: $skipped\n";
