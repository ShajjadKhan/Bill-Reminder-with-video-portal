<?php
// ============================================================
// CyberNet ISP — Unified Billing & Accounting Core Logic
// ============================================================

if (!function_exists('getSetting')) {
    function getSetting($db, $key) {
        $stmt = $db->prepare("SELECT value FROM settings WHERE key=:k");
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res->fetchArray(SQLITE3_NUM);
        return $row ? $row[0] : '';
    }
}

if (!function_exists('setSetting')) {
    function setSetting($db, $key, $value) {
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES (:k, :v)");
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $stmt->bindValue(':v', $value, SQLITE3_TEXT);
        $stmt->execute();
    }
}

if (!function_exists('sendWhatsAppMessage')) {
    function sendWhatsAppMessage($db, $mobile, $message) {
        $base = getSetting($db, 'openwa_url') ?: "http://localhost:2785";
        $key  = getSetting($db, 'openwa_api_key') ?: "dev-admin-key";
        $sid  = getSetting($db, 'openwa_session_id') ?: "8cc17322-a9d3-4b88-89ac-d4d95fb57ff4";
        if (!$sid || !$base) return false;

        $phone = preg_replace('/^0+/', '', trim($mobile));
        if (!preg_match('/^966/', $phone)) $phone = '966' . $phone;
        $chatId = $phone . '@c.us';
        $url = rtrim($base, '/') . "/api/sessions/$sid/messages/send-text";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', "X-API-Key: $key"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['chatId' => $chatId, 'text' => $message]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) return false;
        return $result ?: true;
    }
}

if (!function_exists('getEffectiveBillingStart')) {
    function getEffectiveBillingStart($billing_start_date, $billing_day) {
        if (!$billing_start_date) return new DateTime(date('Y-m-01'));
        $dt = new DateTime($billing_start_date);
        $join_day   = (int)$dt->format('j');
        $bd         = (int)$billing_day;
        $join_year  = (int)$dt->format('Y');
        $join_month = (int)$dt->format('n');

        if ($join_day <= $bd) {
            $dim = (int)cal_days_in_month(CAL_GREGORIAN, $join_month, $join_year);
            return new DateTime(sprintf('%04d-%02d-%02d', $join_year, $join_month, min($bd, $dim)));
        } else {
            $next = new DateTime(sprintf('%04d-%02d-01', $join_year, $join_month));
            $next->modify('+1 month');
            $ny = (int)$next->format('Y');
            $nm = (int)$next->format('n');
            $dim = (int)cal_days_in_month(CAL_GREGORIAN, $nm, $ny);
            return new DateTime(sprintf('%04d-%02d-%02d', $ny, $nm, min($bd, $dim)));
        }
    }
}

if (!function_exists('getUnpaidMonths')) {
    function getUnpaidMonths($db, $customer_id, $current_month = null) {
        $c = $db->querySingle("SELECT billing_start_date, billing_day, monthly_fee FROM customers WHERE id=" . intval($customer_id), true);
        if (!$c) return [];

        $fee   = max(1, floatval($c['monthly_fee'] ?: 30));
        $bd    = (int)($c['billing_day'] ?: 1);
        $today = new DateTime();
        $start = getEffectiveBillingStart($c['billing_start_date'], $bd);

        $months = [];
        $cur    = clone $start;
        $limit  = 60;
        $i      = 0;

        while ($cur <= $today && $i++ < $limit) {
            $months[]   = $cur->format('Y-m');
            $next_year  = (int)$cur->format('Y');
            $next_month = (int)$cur->format('n') + 1;
            if ($next_month > 12) { $next_month = 1; $next_year++; }
            $days_in_target_month = (int)cal_days_in_month(CAL_GREGORIAN, $next_month, $next_year);
            $safe_day = min($bd, $days_in_target_month);
            $cur = new DateTime(sprintf('%04d-%02d-%02d', $next_year, $next_month, $safe_day));
        }

        $res = $db->query("SELECT month_year, SUM(amount) as total, MAX(is_settled) as settled FROM collections WHERE customer_id=" . intval($customer_id) . " GROUP BY month_year");
        $paid = [];
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $paid[$r['month_year']] = ['total' => floatval($r['total']), 'settled' => intval($r['settled'])];
        }

        $vholds = [];
        $hres = $db->query("SELECT hold_start, hold_end FROM vacation_holds WHERE customer_id=" . intval($customer_id));
        while ($h = $hres->fetchArray(SQLITE3_ASSOC)) {
            $vholds[] = [
                's' => new DateTime($h['hold_start']),
                'e' => $h['hold_end'] ? new DateTime($h['hold_end']) : new DateTime(date('Y-m-d'))
            ];
        }

        $unpaid = [];
        foreach ($months as $m) {
            $mStart = new DateTime($m . '-01');
            $mEnd   = clone $mStart;
            $mEnd->modify('last day of this month');
            $dim = (int)$mEnd->format('j');

            // Count billable days in this month
            $billable = 0;
            $d = clone $mStart;
            while ($d <= $mEnd) {
                $in_hold = false;
                foreach ($vholds as $vh) {
                    if ($d >= $vh['s'] && $d <= $vh['e']) { $in_hold = true; break; }
                }
                if (!$in_hold) $billable++;
                $d->modify('+1 day');
            }

            if ($billable == 0) continue; // Full month on vacation

            $mfee = round($fee * $billable / $dim, 2);
            $p = $paid[$m] ?? null;

            if ($p && ($p['settled'] == 1 || ($p['total'] >= $mfee))) {
                continue; // Paid or settled
            }

            $paid_amt = $p ? $p['total'] : 0;
            $due = round($mfee - $paid_amt, 2);
            if ($due > 0) {
                $unpaid[] = ['month' => $m, 'due' => $due, 'fee' => $mfee, 'paid' => $paid_amt];
            }
        }
        return $unpaid;
    }
}

if (!function_exists('getCustomerBalance')) {
    function getCustomerBalance($db, $customer_id) {
        $cu = $db->querySingle("SELECT billing_start_date, monthly_fee, billing_day FROM customers WHERE id=" . intval($customer_id), true);
        if (!$cu) return null;

        $fee = max(1, floatval($cu['monthly_fee'] ?: 30));
        $daily_rate = round($fee / 30, 4);
        $start = new DateTime($cu['billing_start_date'] ?: date('Y-m-01'));
        $today = new DateTime(date('Y-m-d'));

        // Vacation holds
        $holds_res = $db->query("SELECT hold_start, hold_end FROM vacation_holds WHERE customer_id=" . intval($customer_id));
        $holds = [];
        while ($h = $holds_res->fetchArray(SQLITE3_ASSOC)) {
            $holds[] = [
                's' => new DateTime($h['hold_start']),
                'e' => $h['hold_end'] ? new DateTime($h['hold_end']) : new DateTime(date('Y-m-d'))
            ];
        }

        // Billable days from start date to yesterday inclusive
        $billable_days = 0;
        $cur = clone $start;
        $yesterday = clone $today;
        $yesterday->modify('-1 day');

        while ($cur <= $yesterday) {
            $in_hold = false;
            foreach ($holds as $h) {
                if ($cur >= $h['s'] && $cur <= $h['e']) { $in_hold = true; break; }
            }
            if (!$in_hold) $billable_days++;
            $cur->modify('+1 day');
        }

        $total_owed = round($billable_days * $daily_rate, 2);
        $total_paid = (float)$db->querySingle("SELECT COALESCE(SUM(amount), 0) FROM collections WHERE customer_id=" . intval($customer_id) . " AND amount > 0");
        $balance = round($total_paid - $total_owed, 2);

        return [
            'total_owed'    => $total_owed,
            'total_paid'    => $total_paid,
            'balance'       => $balance,
            'billable_days' => $billable_days,
            'daily_rate'    => $daily_rate,
            'has_credit'    => $balance >= 0,
            'owes'          => abs(min(0, $balance))
        ];
    }
}

if (!function_exists('getServerAds')) {
    function getServerAds() {
        return [
            'movie' => [
                'name' => '🎬 Movies & Shows',
                'url'  => 'http://10.12.14.16:8082',
                'desc' => 'Free access to movies, series & entertainment'
            ],
            'football' => [
                'name' => '⚽ Live Football',
                'url'  => 'http://10.12.14.16:8086',
                'desc' => 'Watch live matches, replays & sports updates'
            ]
        ];
    }
}

if (!function_exists('formatServerAds')) {
    function formatServerAds() {
        $servers = getServerAds();
        $msg = "\n🎁 EXCLUSIVE BENEFITS FOR OUR CUSTOMERS:\n";
        foreach ($servers as $s) {
            $msg .= "\n{$s['name']}\n{$s['desc']}\nAccess: {$s['url']}\n";
        }
        return $msg;
    }
}

if (!function_exists('buildReceiptMsg')) {
    function buildReceiptMsg($db, $customer_id, $customer_name, $collector, $paid_items, $total_paid) {
        $current_month = date('Y-m');
        $movie = getSetting($db, 'movie_server') ?: 'http://10.12.14.16:8082';
        $s1n   = getSetting($db, 'support_1_name');
        $s1p   = getSetting($db, 'support_1_phone');
        $s2n   = getSetting($db, 'support_2_name');
        $s2p   = getSetting($db, 'support_2_phone');

        $msg  = "✅ PAYMENT RECEIPT\n\n";
        $msg .= "Customer : $customer_name\n";
        $msg .= "Date     : " . date("d M Y H:i") . "\n";
        $msg .= "Collected: $collector\n\n";
        $msg .= "💳 Payment Details:\n";
        foreach ($paid_items as $item) {
            $label = $item['amount'] == 0 ? 'WAIVED' : 'PAID';
            $msg .= "  $label " . date('M Y', strtotime($item['month'] . '-01')) . ": {$item['amount']} SAR\n";
        }
        $msg .= "\nTotal paid today: $total_paid SAR\n\n";

        $remaining = getUnpaidMonths($db, $customer_id, $current_month);
        if (!empty($remaining)) {
            $msg .= "⚠️ Remaining Balance:\n";
            foreach ($remaining as $r) {
                $msg .= "  " . date('M Y', strtotime($r['month'] . '-01')) . ": {$r['due']} SAR\n";
            }
        } else {
            $msg .= "✅ All bills are paid. Thank you!\n";
        }

        $bal = getCustomerBalance($db, $customer_id);
        if ($bal) {
            $msg .= "\n📊 Account Balance:\n";
            if ($bal["balance"] > 0) {
                $prepaid_months = intval($bal["balance"] / 30);
                $msg .= "  Credit: +" . number_format($bal["balance"], 2) . " SAR";
                if ($prepaid_months > 0) $msg .= " ($prepaid_months month" . ($prepaid_months > 1 ? "s" : "") . " prepaid)";
                $msg .= "\n";
            } elseif ($bal["balance"] == 0) {
                $msg .= "  Fully settled ✅\n";
            } else {
                $msg .= "  Due: " . number_format(abs($bal["balance"]), 2) . " SAR\n";
            }
        }

        $msg .= "\n🎁 Free for our customers:\n";
        $msg .= "  🎬 Movies: $movie\n";
        $msg .= "  ⚽ Live Football: http://10.12.14.16:8086\n\n";
        $msg .= "📞 Support (24/7):\n";
        $msg .= "  $s1n: $s1p\n";
        if ($s2n && $s2p) $msg .= "  $s2n: $s2p\n";
        $msg .= "\nThank you for your payment! 🙏";
        return $msg;
    }
}
