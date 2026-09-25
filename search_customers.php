<?php
header('Content-Type: application/json; charset=utf-8');

$raw_q = trim($_GET['q'] ?? '');
if (mb_strlen($raw_q) < 2) {
    echo json_encode([]);
    exit;
}

$db = new SQLite3(__DIR__ . '/bills.db');

$tokens = preg_split('/\s+/', $raw_q, -1, PREG_SPLIT_NO_EMPTY);
if (empty($tokens)) {
    echo json_encode([]);
    exit;
}

$where_clauses = ["status = 'active'"];
$params = [];

$virtual_searchable = "(
    name || ' ' || 
    mobile || ' ' || 
    (CASE 
        WHEN mobile LIKE '966%' THEN '0' || SUBSTR(mobile, 4) 
        WHEN mobile LIKE '0%' THEN '966' || SUBSTR(mobile, 2) 
        ELSE '' 
    END) || ' ' || 
    (CASE 
        WHEN mobile LIKE '966%' THEN SUBSTR(mobile, 4) 
        WHEN mobile LIKE '0%' THEN SUBSTR(mobile, 2) 
        ELSE '' 
    END) || ' ' || 
    COALESCE(building, '') || ' ' || 
    COALESCE(apartment, '') || ' ' || 
    COALESCE(room, '')
)";

foreach ($tokens as $idx => $token) {
    $param_name = ':tok_' . $idx;
    $clean_tok = str_replace(['%', '_'], '', $token);
    if ($clean_tok === '') continue;
    
    $digits = preg_replace('/\D/', '', $token);
    if (strlen($digits) >= 2) {
        $param_digit = ':dig_' . $idx;
        $where_clauses[] = "($virtual_searchable LIKE $param_name OR mobile LIKE $param_digit)";
        $params[$param_name] = '%' . $clean_tok . '%';
        $params[$param_digit] = '%' . $digits . '%';
    } else {
        $where_clauses[] = "$virtual_searchable LIKE $param_name";
        $params[$param_name] = '%' . $clean_tok . '%';
    }
}

$where_sql = implode(' AND ', $where_clauses);
$clean_all = str_replace(['%', '_'], '', $raw_q);
$digits_all = preg_replace('/\D/', '', $raw_q);

$sql = "SELECT id, name, mobile, building, apartment, room, billing_day, billing_start_date, monthly_fee, status, COALESCE(reminders_enabled, 1) as reminders_enabled, (SELECT COUNT(*) FROM vacation_holds vh WHERE vh.customer_id = customers.id AND vh.hold_start <= date('now') AND (vh.hold_end IS NULL OR vh.hold_end >= date('now'))) as is_on_vacation
        FROM customers
        WHERE $where_sql
        ORDER BY 
            CASE 
                WHEN LOWER(name) = LOWER(:rank_exact) THEN 1
                WHEN LOWER(name) LIKE LOWER(:rank_prefix) THEN 2
                WHEN mobile LIKE :rank_digit_prefix THEN 3
                WHEN (CASE WHEN mobile LIKE '966%' THEN '0' || SUBSTR(mobile, 4) ELSE mobile END) LIKE :rank_local_prefix THEN 4
                WHEN LOWER(name) LIKE LOWER(:rank_any) THEN 5
                ELSE 6
            END,
            name ASC
        LIMIT 30";

$stmt = $db->prepare($sql);
foreach ($params as $pName => $pVal) {
    $stmt->bindValue($pName, $pVal, SQLITE3_TEXT);
}
$stmt->bindValue(':rank_exact', $clean_all, SQLITE3_TEXT);
$stmt->bindValue(':rank_prefix', $clean_all . '%', SQLITE3_TEXT);
$stmt->bindValue(':rank_digit_prefix', ($digits_all ?: '___') . '%', SQLITE3_TEXT);
$stmt->bindValue(':rank_local_prefix', ($digits_all ?: '___') . '%', SQLITE3_TEXT);
$stmt->bindValue(':rank_any', '%' . $clean_all . '%', SQLITE3_TEXT);

$res = $stmt->execute();
$data = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $data[] = $row;
}
echo json_encode($data);
