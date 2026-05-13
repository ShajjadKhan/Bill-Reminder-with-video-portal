<?php
$db = new SQLite3('bills.db');
$cid = intval($_GET['cid']);
$collections = $db->query("SELECT month_year, amount, collected_date, (SELECT fullname FROM users WHERE id=collections.collected_by) as collector FROM collections WHERE customer_id=$cid ORDER BY collected_date DESC");
echo "<table class='table table-sm'><thead><tr><th>Month</th><th>Amount</th><th>Date</th><th>Collected By</th></tr></thead><tbody>";
while($c=$collections->fetchArray(SQLITE3_ASSOC)){
    echo "<tr><td>{$c['month_year']}</td><td>{$c['amount']} SAR</td><td>{$c['collected_date']}</td><td>{$c['collector']}</td></tr>";
}
echo "</tbody></table>";
?>
