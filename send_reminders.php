<?php
$db = new SQLite3('/home/tserver/billing_reminder/bills.db');
date_default_timezone_set('Asia/Riyadh');

$today = new DateTime();
$target_5 = (clone $today)->modify('+5 days');
$target_2 = (clone $today)->modify('+2 days');
$target_days = array_unique([$target_5->format('j'), $target_2->format('j')]);
$current_month = $today->format('Y-m');

$openwa_url = "http://localhost:2785/api/sessions/5ecb7afd-213d-4c73-9ea0-e922d02e5ecf/messages/send-text";
$api_key = "dev-admin-key";

$customers = $db->query("SELECT id, name, mobile, due_day, billing_start_date FROM customers WHERE status='active'");

$sent = 0;
$skipped = 0;

while ($c = $customers->fetchArray(SQLITE3_ASSOC)) {
    // Check if due_day matches target days
    $due_day = (int)$c['due_day'];
    // Handle due_day 31 - treat as last day of month
    $days_in_month = (int)date('t');
    $effective_due = min($due_day, $days_in_month);
    
    if (!in_array($effective_due, $target_days)) continue;

    // Calculate total paid vs total owed across all months
    $start = new DateTime($c['billing_start_date']);
    $now = new DateTime(date('Y-m-01'));
    $period = new DatePeriod($start, new DateInterval('P1M'), $now->modify('+1 month'));
    
    $total_owed = 0;
    $total_paid = 0;
    $unpaid_months = [];
    
    foreach ($period as $dt) {
        $month_year = $dt->format('Y-m');
        $total_owed += 30;
        $paid = (float)$db->querySingle("SELECT COALESCE(SUM(amount),0) FROM collections WHERE customer_id={$c['id']} AND month_year='$month_year'");
        $total_paid += $paid;
        $due = 30 - $paid;
        if ($due > 0) {
            $unpaid_months[] = $dt->format('F Y') . ": $due SAR";
        }
    }

    $balance = $total_paid - $total_owed;

    // Skip if customer has credit (paid ahead) or no unpaid months
    if ($balance >= 0 || empty($unpaid_months)) {
        echo "SKIPPED (paid/credit): {$c['name']}\n";
        $skipped++;
        continue;
    }

    $days_left = $effective_due - (int)$today->format('j');
    if ($days_left < 0) $days_left = $effective_due + ($days_in_month - (int)$today->format('j'));

    $total_due = abs($balance);
    $months_text = implode("\n", array_map(fn($m) => "- $m", $unpaid_months));

    $message = "Dear {$c['name']},\n\n";
    $message .= "Your internet bill is due in $days_left days (Day {$c['due_day']}).\n\n";
    $message .= "Unpaid months:\n$months_text\n\n";
    $message .= "Total due: $total_due SAR\n\n";
    $message .= "Enjoy free movies: http://10.12.14.16:8082\n\n";
    $message .= "Technical Support (24/7):\n";
    $message .= "Cyber Net: +966594266584\n";
    $message .= "Riyad Hossain: +966546377863\n";
    $message .= "Jahir Hossain: +966542349510\n\n";
    $message .= "Please pay on time to avoid service interruption.";

    $message .= "\n\n" . "═══════════════════════════════════" . "\n";
    $message .= "🎁 EXCLUSIVE BENEFITS FOR CUSTOMERS:\n";
    $message .= "═══════════════════════════════════\n\n";
    $message .= "🎬 *Movies & Shows*\n";
    $message .= "Free access to movies, series & entertainment\n";
    $message .= "Link: http://10.12.14.16:8082\n\n";
    $message .= "⚽ *Live Football*\n";
    $message .= "Watch live matches, replays & sports\n";
    $message .= "Link: http://10.12.14.16:8086\n";

    $phone = ltrim(trim($c['mobile']), '0');
    if (!preg_match('/^966/', $phone)) $phone = '966' . $phone;
    $chatId = $phone . "@c.us";

    // DISABLED FOR TESTING - uncomment to enable
    
    $ch = curl_init($openwa_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', "X-API-Key: $api_key"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['chatId' => $chatId, 'text' => $message]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    

    echo "SENT to {$c['name']} ({$c['mobile']}) - due day {$c['due_day']}, days left: $days_left, total due: $total_due SAR\n";
    $sent++;
}

echo "\nDone. Would send: $sent | Skipped (paid): $skipped\n";
?>
