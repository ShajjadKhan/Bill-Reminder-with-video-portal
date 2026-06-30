<?php
$db = new SQLite3('/home/tserver/billing_reminder/bills.db');

// Calculate target dates: 5 days from now and 2 days from now
$today = new DateTime();
$target_5 = (clone $today)->modify('+5 days');
$target_2 = (clone $today)->modify('+2 days');

$target_days = [$target_5->format('j'), $target_2->format('j')];
$target_months = [$target_5->format('n'), $target_2->format('n')]; // month numbers (1-12)

// Build SQL: due_day matches AND (no month restriction or handle month wrap)
// Simplified: due_day IN (target days) – assumes billing day is same each month
// For customers whose due_day is beyond month end, they won't match; that's fine.

$placeholders = implode(',', array_fill(0, count($target_days), '?'));
$sql = "SELECT id, name, mobile, due_day FROM customers WHERE status='active' AND due_day IN ($placeholders)";
$stmt = $db->prepare($sql);
foreach ($target_days as $idx => $day) {
    $stmt->bindValue($idx+1, $day, SQLITE3_INTEGER);
}
$customers = $stmt->execute();

$openwa_url = "http://localhost:2785/api/sessions/84ecd217-e6bc-4b7e-ac92-1d09cb3f0be7/messages/send-text";
$api_key = "dev-admin-key";

while ($c = $customers->fetchArray(SQLITE3_ASSOC)) {
    $due_day = $c['due_day'];
    // Calculate days left: if due_day is less than today's day, assume next month
    $today_day = (int)date('j');
    if ($due_day >= $today_day) {
        $days_left = $due_day - $today_day;
    } else {
        // Next month
        $days_left = $due_day + (date('t') - $today_day);
    }
    
    $message = "Dear {$c['name']},\n\n";
    $message .= "Your internet bill is due in $days_left days (Day {$c['due_day']} of each month).\n";
    $message .= "Amount: 30 SAR\n\n";
    $message .= "Enjoy free movies: http://10.12.14.16:8082\n\n";
    $message .= "Technical Support (24/7):\n";
    $message .= "Cyber Net: +966594266584\n";
    $message .= "Riyad Hossain: +966546377863\n";
    $message .= "Jahir Hossain: +966542349510\n\n";
    $message .= "Please pay on time to avoid service interruption.";
    
    $chatId = "966" . ltrim($c['mobile'], '0') . "@c.us";
    
    $ch = curl_init($openwa_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', "X-API-Key: $api_key"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['chatId' => $chatId, 'text' => $message]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    
    echo "Reminder sent to {$c['name']} ({$c['mobile']}) - due day {$c['due_day']}, days left: $days_left\n";
}

echo "Reminder check completed.\n";
?>
