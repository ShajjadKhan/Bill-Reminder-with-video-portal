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

if (!function_exists('getCustomerAccountDetails')) {
    function getCustomerAccountDetails($db, $customer_id, $current_month = null) {
        $c = $db->querySingle("SELECT billing_start_date, billing_day, monthly_fee FROM customers WHERE id=" . intval($customer_id), true);
        if (!$c) return null;

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

        // Collections
        $res = $db->query("SELECT id, month_year, amount, is_settled FROM collections WHERE customer_id=" . intval($customer_id) . " ORDER BY month_year ASC, id ASC");
        $colls_by_month = [];
        $total_cash_paid = 0;
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $amt = floatval($r['amount']);
            $settled = intval($r['is_settled']);
            $my = $r['month_year'];
            if (!isset($colls_by_month[$my])) {
                $colls_by_month[$my] = ['total' => 0, 'settled' => 0];
            }
            $colls_by_month[$my]['total'] += $amt;
            if ($settled == 1) $colls_by_month[$my]['settled'] = 1;
            if ($amt > 0) $total_cash_paid += $amt;
        }

        // Vacation holds
        $vholds = [];
        $hres = $db->query("SELECT hold_start, hold_end FROM vacation_holds WHERE customer_id=" . intval($customer_id));
        while ($h = $hres->fetchArray(SQLITE3_ASSOC)) {
            $vholds[] = [
                's' => new DateTime($h['hold_start']),
                'e' => $h['hold_end'] ? new DateTime($h['hold_end']) : null
            ];
        }

        // Calculate monthly expected fees
        $month_details = [];
        $total_owed = 0;
        $billable_days_total = 0;

        foreach ($months as $m) {
            $mStart = new DateTime($m . '-01');
            $mEnd   = clone $mStart;
            $mEnd->modify('last day of this month');
            $dim = (int)$mEnd->format('j');

            $billable = 0;
            $d = clone $mStart;
            while ($d <= $mEnd) {
                $in_hold = false;
                foreach ($vholds as $vh) {
                    if ($vh['e'] === null) {
                        if ($d >= $vh['s']) { $in_hold = true; break; }
                    } else {
                        if ($d >= $vh['s'] && $d <= $vh['e']) { $in_hold = true; break; }
                    }
                }
                if (!$in_hold) $billable++;
                $d->modify('+1 day');
            }

            if ($billable == 0) {
                // Full vacation month
                $month_details[$m] = ['fee' => 0, 'billable' => 0, 'dim' => $dim, 'waived' => false];
                continue;
            }

            $mfee = round($fee * $billable / $dim, 2);
            $p = $colls_by_month[$m] ?? null;

            // If explicitly marked settled, effective fee owed cannot exceed what was paid for this month
            if ($p && $p['settled'] == 1 && $p['total'] < $mfee) {
                $eff_fee = $p['total'];
                $month_details[$m] = ['fee' => $eff_fee, 'billable' => $billable, 'dim' => $dim, 'waived' => true, 'orig_fee' => $mfee];
                $total_owed += $eff_fee;
            } else {
                $month_details[$m] = ['fee' => $mfee, 'billable' => $billable, 'dim' => $dim, 'waived' => false];
                $total_owed += $mfee;
            }
            $billable_days_total += $billable;
        }

        $total_owed = round($total_owed, 2);
        $total_paid = round($total_cash_paid, 2);
        $balance = round($total_paid - $total_owed, 2);

        // Advance / unallocated credits (payments in months not in $months, e.g. future or pre-start)
        $excess_credit = 0;
        foreach ($colls_by_month as $my => $p) {
            if (!isset($month_details[$my])) {
                $excess_credit += $p['total'];
            }
        }

        // Targeted payments for due months
        $unpaid_list = [];
        foreach ($month_details as $m => $info) {
            $fee_due = $info['fee'];
            $p = $colls_by_month[$m] ?? null;
            $targeted_paid = $p ? $p['total'] : 0;

            if ($info['waived']) {
                continue;
            }

            if ($targeted_paid >= ($fee_due - 0.05)) {
                $surplus = $targeted_paid - $fee_due;
                if ($surplus > 0.05) {
                    $excess_credit += $surplus;
                }
            } else {
                $rem_due = round($fee_due - $targeted_paid, 2);
                $unpaid_list[$m] = ['month' => $m, 'fee' => $fee_due, 'paid' => $targeted_paid, 'rem_due' => $rem_due];
            }
        }

        // Apply excess credit to remaining unpaid months in chronological order
        $final_unpaid = [];
        foreach ($unpaid_list as $m => $u) {
            if ($excess_credit >= ($u['rem_due'] - 0.05)) {
                $excess_credit -= $u['rem_due'];
            } else {
                $still_due = round($u['rem_due'] - max(0, $excess_credit), 2);
                $excess_credit = 0;
                if ($still_due > 0.05) {
                    $final_unpaid[] = [
                        'month' => $m,
                        'due'   => $still_due,
                        'fee'   => $u['fee'],
                        'paid'  => round($u['fee'] - $still_due, 2)
                    ];
                }
            }
        }

        return [
            'total_owed'    => $total_owed,
            'total_paid'    => $total_paid,
            'balance'       => $balance,
            'billable_days' => $billable_days_total,
            'daily_rate'    => round($fee / 30, 4),
            'has_credit'    => $balance >= 0,
            'owes'          => abs(min(0, $balance)),
            'unpaid'        => $final_unpaid
        ];
    }
}

if (!function_exists('getUnpaidMonths')) {
    function getUnpaidMonths($db, $customer_id, $current_month = null) {
        $acct = getCustomerAccountDetails($db, $customer_id, $current_month);
        return $acct ? $acct['unpaid'] : [];
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
                'e' => $h['hold_end'] ? new DateTime($h['hold_end']) : null
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
                if ($h['e'] === null) {
                    if ($cur >= $h['s']) { $in_hold = true; break; }
                } else {
                    if ($cur >= $h['s'] && $cur <= $h['e']) { $in_hold = true; break; }
                }
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
        if ($s1n && $s1p) $msg .= "  $s1n: $s1p\n";
        if ($s2n && $s2p) $msg .= "  $s2n: $s2p\n";
        $msg .= "\nThank you for your payment! 🙏";
        return $msg;
    }
}


if (!function_exists("getDefaultPaymentAccountsText")) {
    function getDefaultPaymentAccountsText() {
        return "💳 Payment Accounts / طرق الدفع:

" .
               "1️⃣ Shajjad Khan:
" .
               "📱 +966597595059 (STC Pay, Barq, AlinmaPay)
" .
               "🏦 Alinma Bank IBAN:
" .
               "SA3305000068206538133000

" .
               "2️⃣ Riyad Hossain:
" .
               "📱 +966552036454 (STC Pay, Barq)
" .
               "🏦 Alinma Bank IBAN:
" .
               "SA5805000068207720354000";
    }
}

if (!function_exists("getPaymentAccountsText")) {
    function getPaymentAccountsText($db) {
        $custom = getSetting($db, "payment_accounts");
        if (!empty(trim($custom))) {
            return trim($custom);
        }
        return getDefaultPaymentAccountsText();
    }
}

if (!function_exists("buildReminderMsg")) {
    function buildReminderMsg($db, $customer_name, $join_date, $active_until, $expired, $days_diff, $total_due, $pay_by_day) {
        $movie_server = getSetting($db, "movie_server") ?: "http://10.12.14.16:8082";
        $s1n = getSetting($db, "support_1_name"); $s1p = getSetting($db, "support_1_phone");
        $s2n = getSetting($db, "support_2_name"); $s2p = getSetting($db, "support_2_phone");

        $message = "📶 CYBERNET ACCOUNT STATUS
";
        $message .= "Assalamu Alaikum {$customer_name}!

";
        if ($join_date instanceof DateTime) {
            $message .= "📅 Connected since: " . $join_date->format("d M Y") . "
";
        }
        if ($expired) {
            $message .= "⚠️ Your service expired on: " . $active_until->format("d M Y") . " ($days_diff days ago)

";
            $message .= "💰 Please recharge $total_due SAR to continue service
";
        } else {
            $message .= "✅ Your service is active until: " . $active_until->format("d M Y") . "

";
            $message .= "💰 Please recharge $total_due SAR before it ends
";
        }
        $message .= "📆 Pay by: Day $pay_by_day of this month

";
        $message .= getPaymentAccountsText($db) . "

";
        $message .= "🎬 Movies: $movie_server
";
        $message .= "⚽ Live Football: http://10.12.14.16:8086

";
        $message .= "📞 Support (24/7):
";
        if ($s1n && $s1p) $message .= "$s1n: $s1p
";
        if ($s2n && $s2p) $message .= "$s2n: $s2p
";
        $message .= "
Please recharge on time to avoid service interruption. 🙏
";
        return $message;
    }
}
