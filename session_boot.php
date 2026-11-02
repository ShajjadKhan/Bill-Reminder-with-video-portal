<?php
// session_boot.php — Permanent Session & Persistent Remember-Me Authentication
// Ensures login persists for 365 days across browser restarts, phone reboots, and service reloads.

if (session_status() === PHP_SESSION_NONE) {
    $sess_dir = __DIR__ . '/sessions';
    if (!is_dir($sess_dir)) {
        @mkdir($sess_dir, 0700, true);
    }
    if (is_dir($sess_dir) && is_writable($sess_dir)) {
        ini_set('session.save_path', $sess_dir);
    }

    $lifetime = 31536000; // 365 days (1 year)
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.cookie_lifetime', (string)$lifetime);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false, // HTTP access on internal network / Tailscale
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// Function to establish database connection for auth if not already open
if (!function_exists('getAuthDb')) {
    function getAuthDb() {
        global $db;
        if (isset($db) && $db instanceof SQLite3) {
            return $db;
        }
        $d = new SQLite3(__DIR__ . '/bills.db');
        $d->busyTimeout(10000);
        return $d;
    }
}

// Ensure auth_tokens table exists in SQLite
if (!function_exists('initAuthTokensTable')) {
    function initAuthTokensTable($db) {
        $db->exec("CREATE TABLE IF NOT EXISTS auth_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            selector TEXT NOT NULL UNIQUE,
            token_hash TEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            user_agent TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_auth_tokens_sel ON auth_tokens(selector)");
    }
}

// Issue a persistent remember-me cookie (1 year)
if (!function_exists('issueRememberMeCookie')) {
    function issueRememberMeCookie($db, $userId) {
        initAuthTokensTable($db);
        try {
            $selector = bin2hex(random_bytes(16));
            $validator = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $selector = md5(uniqid(mt_rand(), true));
            $validator = sha1(uniqid(mt_rand(), true)) . sha1(microtime());
        }
        $tokenHash = hash('sha256', $validator);
        $ua = SQLite3::escapeString(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));
        
        $db->exec("INSERT INTO auth_tokens (user_id, selector, token_hash, expires_at, user_agent)
                   VALUES (" . intval($userId) . ", '$selector', '$tokenHash', datetime('now', '+365 days'), '$ua')");
        
        $cookieValue = $selector . ':' . $validator;
        $expire = time() + 31536000; // 1 year
        setcookie('mahabub_remember', $cookieValue, [
            'expires'  => $expire,
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        $_COOKIE['mahabub_remember'] = $cookieValue;
    }
}

// Clear remember-me cookie and remove token from database
if (!function_exists('clearRememberMeCookie')) {
    function clearRememberMeCookie($db) {
        if (!empty($_COOKIE['mahabub_remember'])) {
            $parts = explode(':', $_COOKIE['mahabub_remember']);
            if (count($parts) === 2) {
                $sel = SQLite3::escapeString($parts[0]);
                initAuthTokensTable($db);
                $db->exec("DELETE FROM auth_tokens WHERE selector='$sel'");
            }
        }
        setcookie('mahabub_remember', '', [
            'expires'  => time() - 86400,
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        unset($_COOKIE['mahabub_remember']);
    }
}

// Auto-login via persistent cookie if session is not active
if (!isset($_SESSION['user_id']) && !empty($_COOKIE['mahabub_remember'])) {
    $parts = explode(':', $_COOKIE['mahabub_remember']);
    if (count($parts) === 2) {
        $selector = $parts[0];
        $validator = $parts[1];
        $db_auth = getAuthDb();
        initAuthTokensTable($db_auth);
        
        $sel_esc = SQLite3::escapeString($selector);
        $stmt = $db_auth->prepare("SELECT t.*, u.username, u.fullname, u.role 
                                   FROM auth_tokens t 
                                   JOIN users u ON u.id = t.user_id 
                                   WHERE t.selector = :sel AND t.expires_at > datetime('now') 
                                   LIMIT 1");
        if ($stmt) {
            $stmt->bindValue(':sel', $sel_esc, SQLITE3_TEXT);
            $res = $stmt->execute();
            $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
            if ($row) {
                $calcHash = hash('sha256', $validator);
                if (hash_equals($row['token_hash'], $calcHash)) {
                    // Valid persistent token — restore session state
                    $_SESSION = [
                        'user_id'  => $row['user_id'],
                        'role'     => $row['role'],
                        'fullname' => $row['fullname'],
                        'username' => $row['username']
                    ];
                    
                    // Slide expiration (+365 days)
                    $db_auth->exec("UPDATE auth_tokens SET expires_at=datetime('now', '+365 days') WHERE id=" . intval($row['id']));
                } else {
                    clearRememberMeCookie($db_auth);
                }
            } else {
                clearRememberMeCookie($db_auth);
            }
        }
    }
}

// Auto-upgrade existing active sessions with a persistent remember-me cookie
if (isset($_SESSION['user_id']) && empty($_COOKIE['mahabub_remember'])) {
    $db_auth = getAuthDb();
    issueRememberMeCookie($db_auth, $_SESSION['user_id']);
}
