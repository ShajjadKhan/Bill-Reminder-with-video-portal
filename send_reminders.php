<?php
$db = new SQLite3('/home/tserver/billing_reminder/bills.db');
$today = (int)date('j');
$in_5_days = $today + 5;
$in_2_days = $today + 2;

$customers = $db->query("SELECT id, name, mobile, due_day FROM customers WHERE status='active' AND due_day IN ($in_5_days, $in_2_days)");

$openwa_url = "http://localhost:2785/api/sessions/63bd1d8f-7ed7-45f4-8e25-821626752eaf/messages/send-text";
$api_key = "dev-admin-key";

while ($c = $customers->fetchArray(SQLITE3_ASSOC)) {
    $days_left = $c['due_day'] - $today;
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
    curl_exec($ch);
    curl_close($ch);
    
    echo "Reminder sent to {$c['name']} ({$c['mobile']})\n";
}
?>
