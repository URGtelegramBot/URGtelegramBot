<?php
if (!function_exists('_get_user_coin_column')) {
    /**
     * پیدا کردن یا ایجاد ستون سکه در جدول users.
     * این تابع با PDO کار می‌کند و هر دو driver های sqlite و mysql را پشتیبانی می‌کند.
     * برمی‌گرداند نام ستون سکه (string) برای استفاده در کوئری‌ها.
     */
    function _get_user_coin_column($pdo) {
        try {
            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Exception $e) {
            // اگر نتوانیم driver را بخوانیم، فرض mysql
            $driver = 'mysql';
        }
        // بررسی اسامی متداول ستون‌ها
        $candidates = ['coins', 'coin', 'balance', 'wallet'];
        $names = [];
        try {
            if (strpos($driver, 'sqlite') !== false) {
                $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
                $names = array_column($cols, 'name');
            } elseif (strpos($driver, 'mysql') !== false) {
                $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
                // در MySQL نام ستون در key 'Field' است
                $names = array_map(function($c){ return $c['Field']; }, $cols);
            } else {
                // fallback to mysql show columns attempt
                $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
                $names = array_map(function($c){ return $c['Field']; }, $cols);
            }
        } catch (Exception $e) {
            // اگر خواندن metadata خطا داد، لاگ و ادامه می‌دهیم (فرض هیچ ستونی نیست)
            error_log("Cannot read users table info: " . $e->getMessage());
            $names = [];
        }
        foreach ($candidates as $c) {
            if (in_array($c, $names)) return $c;
        }
        // اگر هیچکدام وجود نداشت، ستون 'coins' را بسازیم (با syntax مخصوص driver)
        try {
            if (strpos($driver, 'sqlite') !== false) {
                $pdo->exec("ALTER TABLE users ADD COLUMN coins REAL DEFAULT 0");
            } elseif (strpos($driver, 'mysql') !== false) {
                // MySQL: DOUBLE یا DECIMAL مناسب است
                $pdo->exec("ALTER TABLE users ADD COLUMN coins DOUBLE DEFAULT 0");
            } else {
                // fallback to mysql style
                $pdo->exec("ALTER TABLE users ADD COLUMN coins DOUBLE DEFAULT 0");
            }
            return 'coins';
        } catch (Exception $e) {
            // اگر اضافه کردن ستون موفق نشد، لاگ کن و یک نام ستونی برای جلوگیری از خطا برگردان
            error_log("Failed to add coins column: " . $e->getMessage());
            // اگر توانستیم نام ستون‌های فعلی را بگیریم، یکیشون را برگردان
            if (!empty($names)) return $names[0];
            // نهایتاً 'coins' را برگردان (ممکن است بعدا خط بزند اما از فراخوانی‌های بعدی جلوگیری می‌کند)
            return 'coins';
        }
    }
}
if (!function_exists('_log_coin_history')) {
    /**
     * ثبت تاریخچه تغییرات سکه.
     * اگر جدول coin_history وجود نداشته باشد، سعی در ایجاد آن می‌کند.
     */
    function _log_coin_history($pdo, $user_id, $change, $old_balance = null, $new_balance = null, $reason = '') {
        try {
            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Exception $e) {
            $driver = 'mysql';
        }
        try {
            // بررسی وجود جدول
            if (strpos($driver, 'sqlite') !== false) {
                $chk = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='coin_history'")->fetchAll();
                if (empty($chk)) {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS coin_history (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            user_id INTEGER,
                            change REAL,
                            old_balance REAL,
                            new_balance REAL,
                            reason TEXT,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        )
                    ");
                }
            } elseif (strpos($driver, 'mysql') !== false) {
                $chk = $pdo->query("SHOW TABLES LIKE 'coin_history'")->fetchAll();
                if (empty($chk)) {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS coin_history (
                            id BIGINT AUTO_INCREMENT PRIMARY KEY,
                            user_id BIGINT,
                            `change` DOUBLE,
                            old_balance DOUBLE,
                            new_balance DOUBLE,
                            reason TEXT,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                    ");
                }
            } else {
                // fallback mysql
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS coin_history (
                        id BIGINT AUTO_INCREMENT PRIMARY KEY,
                        user_id BIGINT,
                        `change` DOUBLE,
                        old_balance DOUBLE,
                        new_balance DOUBLE,
                        reason TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            }
            // درج رکورد تاریخچه
            $stmt = $pdo->prepare("INSERT INTO coin_history (user_id, change, old_balance, new_balance, reason) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $change, $old_balance, $new_balance, $reason]);
        } catch (Exception $e) {
            error_log("Coin history insert error for {$user_id}: " . $e->getMessage());
            // در صورت خطا، سکوت می‌کنیم (نباید کل عملیات را خراب کند)
        }
    }
}
// This script handles all Telegram bot updates.
// It is designed to be highly responsive and robust for a standard hosting environment.
// Prevent script from ending abruptly and set unlimited execution time.
ignore_user_abort(true);
set_time_limit(0);
// ========================================================================
// SECTION 1: CONFIGURATION & CONSTANTS
// ========================================================================
// --- Main Configuration ---
define('TOKEN', '8515986739:AAER1xsv0O7wa3TiX48JHJvOaeQ4zrz_7eo'); // Your bot token
define('MYSQL_HOST', 'localhost');
define('MYSQL_USER', 'urgteleg_URG');
define('MYSQL_PASS', 'amirabas1387');
define('MYSQL_DB', 'urgteleg_membership_telegrambot');
define('ADMIN_ID', 8448826198); // Your numeric admin ID
define('ADMIN_USERNAME', '@DriveUrg'); // Your admin username for display
// --- User States (using strings for clarity) ---
const STATE_DEFAULT = 'default';
const STATE_AWAITING_LANGUAGE = 'awaiting_language';
const STATE_AWAITING_CHANNEL_ID = 'awaiting_channel_id';
const STATE_AWAITING_MEMBER_COUNT = 'awaiting_member_count';
const STATE_AWAITING_BONUS_COINS = 'awaiting_bonus_coins';
const STATE_AWAITING_TICKET_TEXT = 'awaiting_ticket_text';
const STATE_AWAITING_COINS_AMOUNT = 'awaiting_coins_amount';
const STATE_AWAITING_RECEIPT = 'awaiting_receipt';
const STATE_AWAITING_GIFT_USER_ID = 'awaiting_gift_user_id';
const STATE_AWAITING_GIFT_AMOUNT = 'awaiting_gift_amount';
const STATE_AWAITING_REPORT_REASON = 'awaiting_report_reason';
const STATE_CHECKING_MEMBERSHIP = 'checking_membership';
const STATE_AWAITING_BADGE_CHOICE = 'awaiting_badge_choice';
// ========================================================================
// SECTION 2: DATABASE SETUP & SETTINGS
// ========================================================================
$bot_settings = []; // Global variable for settings
function get_pdo() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . MYSQL_HOST . ';dbname=' . MYSQL_DB . ';charset=utf8mb4';
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 30, // افزایش تایم‌اوت
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ];
            $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, $options);
            // === تنظیمات حیاتی برای MySQL ===
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = 30');
            $pdo->exec('SET SESSION wait_timeout = 28800');
            $pdo->exec('SET SESSION interactive_timeout = 28800');
        } catch (PDOException $e) {
            error_log("PDO connection error: " . $e->getMessage());
            die("Database connection failed.");
        }
    }
    return $pdo;
}
/**
 * Runs database schema migrations to ensure all necessary tables and columns exist.
 */
function run_migrations() {
    $pdo = get_pdo();
    // Settings table to store configuration dynamically
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (`key` VARCHAR(255) PRIMARY KEY, value TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Core tables for users, channels, orders, etc.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            user_id BIGINT PRIMARY KEY, language VARCHAR(5) DEFAULT 'fa', coins DOUBLE DEFAULT 0,
            state VARCHAR(50) DEFAULT 'default', user_data TEXT DEFAULT '{}', is_suspended TINYINT DEFAULT 0,
            referrer_id BIGINT, referrals INT DEFAULT 0, referral_coins DOUBLE DEFAULT 0,
            warnings INT DEFAULT 0, notifications TEXT DEFAULT '{\"order_progress\":true, \"broadcast\":true, \"system_warnings\":true}',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_collect_time DATETIME,
            xp BIGINT DEFAULT 0, level INT DEFAULT 1,
            is_vip TINYINT DEFAULT 0, vip_expires_at DATETIME,
            is_activated TINYINT DEFAULT 0, last_daily_gift_time DATETIME, profile_badge VARCHAR(10) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS channels (channel_id BIGINT PRIMARY KEY, owner_user_id BIGINT NOT NULL, title VARCHAR(255), invite_link VARCHAR(255), is_group TINYINT DEFAULT 0, FOREIGN KEY (owner_user_id) REFERENCES users(user_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS orders (order_id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, channel_id BIGINT NOT NULL, required_users INT, current_count INT DEFAULT 0, is_active TINYINT DEFAULT 1, is_boosted TINYINT DEFAULT 0, created_at DATETIME, bonus_coins BIGINT DEFAULT 0, auto_renew TINYINT DEFAULT 0, FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE, FOREIGN KEY (channel_id) REFERENCES channels(channel_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS order_members (order_id BIGINT, member_user_id BIGINT, PRIMARY KEY(order_id, member_user_id), FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE, FOREIGN KEY (member_user_id) REFERENCES users(user_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS purchases (purchase_id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, coins_requested BIGINT, price INT, status VARCHAR(50) DEFAULT 'pending', photo_file_id VARCHAR(255), created_at DATETIME, order_number VARCHAR(50), admin_message_id BIGINT, type VARCHAR(50) DEFAULT 'coins', related_order_id BIGINT, FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS tickets (ticket_id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, username VARCHAR(255), text TEXT, status VARCHAR(50) DEFAULT 'open', created_at DATETIME, admin_message_id BIGINT, user_message_id BIGINT, FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS coin_history (history_id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, amount BIGINT NOT NULL, reason VARCHAR(255), created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS channel_joins (user_id BIGINT NOT NULL, channel_id BIGINT NOT NULL, joined_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, channel_id), FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS channel_reports (report_id BIGINT AUTO_INCREMENT PRIMARY KEY, channel_id BIGINT NOT NULL, reporter_user_id BIGINT NOT NULL, reason TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, status VARCHAR(50) DEFAULT 'pending', FOREIGN KEY (reporter_user_id) REFERENCES users(user_id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
        // ----------------------------
    // Extra migrations: new columns
    // ----------------------------
    // (adds ban_count, banned_until, is_deleted for users;
    // attachments for tickets; is_banned for channels)
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $colnames = array_map(function($c){ return $c['Field']; }, $cols);
    if (!in_array('ban_count', $colnames)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN ban_count INT DEFAULT 0");
    }
    if (!in_array('banned_until', $colnames)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN banned_until DATETIME");
    }
    if (!in_array('is_deleted', $colnames)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_deleted TINYINT DEFAULT 0");
    }
    $cols = $pdo->query("SHOW COLUMNS FROM tickets")->fetchAll(PDO::FETCH_ASSOC);
    $colnames = array_map(function($c){ return $c['Field']; }, $cols);
    if (!in_array('attachments', $colnames)) {
        $pdo->exec("ALTER TABLE tickets ADD COLUMN attachments TEXT");
    }
    $cols = $pdo->query("SHOW COLUMNS FROM channels")->fetchAll(PDO::FETCH_ASSOC);
    $colnames = array_map(function($c){ return $c['Field']; }, $cols);
    if (!in_array('is_banned', $colnames)) {
        $pdo->exec("ALTER TABLE channels ADD COLUMN is_banned TINYINT DEFAULT 0");
    }
    // Membership check cooldown column (جدید - برای کول‌داون ۲ ساعته بررسی عضویت)
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $colnames = array_map(function($c){ return $c['Field']; }, $cols);
    if (!in_array('last_membership_check', $colnames)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_membership_check DATETIME DEFAULT NULL");
    }
    // Create user_blacklist table to persist channels a user has permanently skipped
    // (so the bot never shows that channel/order to that user again)
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_blacklist (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        channel_id BIGINT NOT NULL,
        order_id BIGINT,
        reason TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, channel_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Robustly check for and add new columns to existing tables
    $all_columns = [
        'users' => [
            'last_collect_time DATETIME', 'xp BIGINT DEFAULT 0', 'level INT DEFAULT 1',
            'is_vip TINYINT DEFAULT 0', 'vip_expires_at DATETIME', 'is_activated TINYINT DEFAULT 0',
            'last_daily_gift_time DATETIME',
            'profile_badge VARCHAR(10) DEFAULT NULL'
        ],
        'orders' => [
            'is_boosted TINYINT DEFAULT 0', 'bonus_coins BIGINT DEFAULT 0', 'auto_renew TINYINT DEFAULT 0'
        ],
        'purchases' => ['type VARCHAR(50) DEFAULT "coins"', 'related_order_id BIGINT'],
        'tickets' => ['user_message_id BIGINT']
    ];
    foreach ($all_columns as $table => $columns) {
        $cols = $pdo->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_ASSOC);
        $colnames = array_map(function($c){ return $c['Field']; }, $cols);
        foreach ($columns as $column_def) {
            $column_name = explode(' ', $column_def)[0];
            if (!in_array($column_name, $colnames)) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $column_def;");
            }
        }
    }
}
// ---------- توابع کمکی برای اطمینان از ستون‌ها و لاگ ----------
function _ensure_user_columns($pdo) {
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_map(function($c){ return $c['Field']; }, $cols);
    $to_add = [
        'ban_count' => 'INT DEFAULT 0',
        'banned_until' => 'DATETIME',
        'is_suspended' => 'TINYINT DEFAULT 0',
        'is_deleted' => 'TINYINT DEFAULT 0',
        'warnings' => 'INT DEFAULT 0',
        'is_vip' => 'TINYINT DEFAULT 0'
    ];
    foreach ($to_add as $col => $type) {
        if (!in_array($col, $names)) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN {$col} {$type}"); } catch (Exception $e) { error_log("Add user col {$col} failed: ".$e->getMessage()); }
        }
    }
    // ---- FIX: Ensure 'status' column exists where code expects it ----
    // برخی از بخش‌های برنامه فرض می‌کنند ستون status در این جداول وجود دارد.
    // درصورتی‌که در DB فعلی نباشد، آن را اضافه می‌کنیم.
    $tables_with_status = [
        'order_members' => "VARCHAR(50) DEFAULT 'joined'",
        'purchases' => "VARCHAR(50) DEFAULT 'pending'",
        'tickets' => "VARCHAR(50) DEFAULT 'open'"
    ];
    foreach ($tables_with_status as $tbl => $colDef) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM {$tbl}")->fetchAll(PDO::FETCH_ASSOC);
            $names = array_map(function($c){ return $c['Field']; }, $cols);
            if (!in_array('status', $names)) {
                $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN status {$colDef}");
            }
        } catch (Exception $e) {
            // اگر جدول موجود نبود یا ALTER مجاز نبود لاگ کنیم و ادامه دهیم
            error_log("Add status column to {$tbl} failed: " . $e->getMessage());
        }
    }
    // ensure coin column (this تابع خودش ایجاد می‌کند در صورت نبود)
    _get_user_coin_column($pdo);
}
function _ensure_channel_columns($pdo) {
    $cols = $pdo->query("SHOW COLUMNS FROM channels")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_map(function($c){ return $c['Field']; }, $cols);
    if (!in_array('is_banned', $names)) {
        try { $pdo->exec("ALTER TABLE channels ADD COLUMN is_banned TINYINT DEFAULT 0"); } catch (Exception $e) { error_log("Add channel col is_banned failed: ".$e->getMessage()); }
    }
}
function _ensure_order_columns($pdo) {
    $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_map(function($c){ return $c['Field']; }, $cols);
    // چند ستون معمولی که ممکن است لازم باشن
    $want = ['required_users'=>'INT DEFAULT 0', 'title'=>'VARCHAR(255)', 'admin_id'=>'BIGINT'];
    foreach ($want as $col => $type) {
        if (!in_array($col, $names)) {
            try { $pdo->exec("ALTER TABLE orders ADD COLUMN {$col} {$type}"); } catch (Exception $e) { error_log("Add order col {$col} failed: ".$e->getMessage()); }
        }
    }
}
/**
 * Loads all settings from the database into a global variable.
 */
function load_bot_settings() {
    global $bot_settings;
    $pdo = get_pdo();
    // ** REVERTED: Restored original coin economy values **
    $defaults = [
        'CARD_NUMBER' => '0000-0000-0000-0000', 'CARD_HOLDER' => 'نام دارنده',
        'COIN_PRICE' => 500, 'BOOST_PRICE' => 10000, 'COIN_MULTIPLIER' => 100,
        'JOIN_REWARD' => 50, 'ORDER_COST_PER_MEMBER' => 100, 'WELCOME_GIFT' => 500,
        'REFERRAL_REWARD' => 200, 'LEAVE_PENALTY' => 500, 'OWNER_COMPENSATION' => 200,
        'MAX_WARNINGS' => 3, 'JOIN_COOLDOWN' => 2, 'LEADERBOARD_LIMIT' => 10,
        'LEADERBOARD_MIN_USERS' => 1000, 'HISTORY_LIMIT' => 10, 'PAGINATION_LIMIT' => 5,
        'VIP_PRICE_TOMAN' => 35000, 'VIP_MONTHLY_COIN_GIFT' => 2000,
    ];
    $stmt = $pdo->query("SELECT `key`, value FROM settings");
    $db_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $bot_settings = array_merge($defaults, $db_settings);
    // Ensure numeric values are correctly typed
    foreach ($bot_settings as $key => &$value) {
        if (is_numeric($value)) {
            $value = ctype_digit($value) ? (int)$value : (float)$value;
        }
    }
}
// Initialize database and settings on script start
run_migrations();
load_bot_settings();
// ========================================================================
// SECTION 3: CORE API & HELPER FUNCTIONS
// ========================================================================
function api_request($method, $params = []) {
    $url = 'https://api.telegram.org/bot' . TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("cURL error: " . curl_error($ch));
        return ['ok' => false, 'error_code' => curl_errno($ch), 'description' => curl_error($ch)];
    }
    curl_close($ch);
    $response = json_decode($result, true);
    if ($response === null) {
        // اگر پاسخ JSON نیست، آن را لاگ کن و خروجی خام را برگردان
        error_log("Telegram API non-JSON response for method {$method}: " . substr($result ?? '', 0, 200));
        return ['ok' => false, 'description' => $result];
    }
    if (isset($response['ok']) && $response['ok'] === false) {
        $desc = $response['description'] ?? '';
        // مواردی که برای answerCallbackQuery معمول و قابل چشم‌پوشی هستند:
        if ($method === 'answerCallbackQuery') {
            if (stripos($desc, 'query is too old') !== false
                || stripos($desc, 'response timeout expired') !== false
                || stripos($desc, 'query id is invalid') !== false
            ) {
                // لاگ به عنوان debug و سپس چشم‌پوشی — این خطاها طبیعی‌اند وقتی callback دیر پاسخ داده شده
                error_log("Ignored old callback query error: {$desc}");
                return $response;
            }
        }
        // پیام "message is not modified" هم معمولاً اشکال نیست
        if (stripos($desc, 'message is not modified') === false) {
            error_log("Telegram API error for method {$method}: " . $desc);
        }
    }
    return $response;
}
/**
 * Formats the integer coin value into a displayable decimal format.
 * ** REVERTED: Restored original function to handle coin display logic **
 */
function format_coins($coins) {
    global $bot_settings;
    if (empty($bot_settings['COIN_MULTIPLIER'])) {
        return '0';
    }
    return rtrim(rtrim(number_format($coins / $bot_settings['COIN_MULTIPLIER'], 2), '0'), '.');
}
/**
 * Generates a unique order number for purchases.
 */
function generate_order_number() {
    $pdo = get_pdo();
    do {
        try {
            $order_number = str_pad(random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        } catch (Exception $e) {
            $order_number = str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE order_number = ?");
        $stmt->execute([$order_number]);
    } while ($stmt->fetchColumn() > 0);
    return $order_number;
}
/**
 * Extracts a valid public channel/group username or link.
 */
function extract_channel_id($input) {
    $input = trim($input);
    if (strpos($input, '+') !== false || strpos($input, 'joinchat') !== false) {
        return null;
    }
    if (preg_match('/^@([a-zA-Z0-9_]{5,32})$/', $input, $matches)) {
        return '@' . $matches[1];
    }
    if (preg_match('/^https?:\/\/(t\.me|telegram\.me)\/([a-zA-Z0-9_]{5,32})\/?$/', $input, $matches)) {
        return '@' . $matches[2];
    }
    return null;
}
/**
 * Gets the bot's own user ID.
 */
function get_bot_id() {
    static $bot_id = null;
    if ($bot_id === null) {
        $me = api_request('getMe');
        $bot_id = $me['result']['id'] ?? 0;
    }
    return $bot_id;
}
/**
 * Gets the bot's own username.
 */
function get_bot_username() {
    static $bot_username = null;
    if ($bot_username === null) {
        $me = api_request('getMe');
        $bot_username = $me['result']['username'] ?? '';
    }
    return $bot_username;
}
/**
 * A wrapper for api_request('editMessageText').
 */
function edit_message($chat_id, $message_id, $text, $extra_params = []) {
    $params = array_merge(['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text], $extra_params);
    return api_request('editMessageText', $params);
}
/**
 * A wrapper for api_request('sendMessage').
 */
function send_message($chat_id, $text, $extra_params = []) {
    $params = array_merge(['chat_id' => $chat_id, 'text' => $text], $extra_params);
    return api_request('sendMessage', $params);
}
// ========================================================================
// SECTION 4: DATABASE INTERACTION FUNCTIONS
// ========================================================================
function _update_user_coins_and_history(PDO $pdo, $user_id, $amount, $reason) {
    if ($amount == 0) return;
    $max_retries = 5;
    $attempt = 0;
    $started_tx = false;
    while (true) {
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started_tx = true;
            }
            $stmt = $pdo->prepare("UPDATE users SET coins = coins + ? WHERE user_id = ?");
            $stmt->execute([$amount, $user_id]);
            $stmt = $pdo->prepare("INSERT INTO coin_history (user_id, amount, reason, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$user_id, $amount, $reason]);
            if ($started_tx && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return;
        } catch (PDOException $e) {
            $attempt++;
            $msg = $e->getMessage();
            // در صورت خطای lock/wait timeout تلاش مجدد کن
            if ((stripos($msg, 'lock') !== false || stripos($msg, 'timeout') !== false) && $attempt <= $max_retries) {
                if ($started_tx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                    $started_tx = false;
                }
                // backoff افزایشی (50ms * attempt)
                usleep(50000 * $attempt);
                continue;
            }
            if ($started_tx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } catch (Exception $e) {
            if ($started_tx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
/**
 * Execute a prepared statement with retries on lock/timeout.
 * Returns PDOStatement on success, false on repeated failure.
 */
function db_prepare_execute_with_retry(PDO $pdo, $sql, $params = [], $max_retries = 6, $base_delay_ms = 120) {
    $attempt = 0;
    while (true) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $attempt++;
            $msg = $e->getMessage();
            // retry on lock/timeout
            if ($attempt >= $max_retries || (stripos($msg, 'lock') === false && stripos($msg, 'timeout') === false)) {
                error_log("DB execute failed (no retry or max reached): {$msg} | SQL: {$sql}");
                return false;
            }
            $sleep_ms = $base_delay_ms * $attempt;
            error_log("DB lock/timeout, retry {$attempt}/{$max_retries} after {$sleep_ms}ms. SQL: {$sql}");
            usleep($sleep_ms * 1000);
            continue;
        }
    }
}
/**
 * Helper specifically for DELETE on order_members with retry + logging.
 * Returns true on success, false if ultimately failed.
 */
function safe_delete_order_member(PDO $pdo, $order_id, $member_id) {
    $sql = "DELETE FROM order_members WHERE order_id = ? AND member_user_id = ?";
    $res = db_prepare_execute_with_retry($pdo, $sql, [$order_id, $member_id]);
    if ($res === false) {
        error_log("safe_delete_order_member failed for order {$order_id}, member {$member_id}");
        return false;
    }
    return true;
}
/**
 * Helper for safe UPDATE of order current_count (uses same SQL as original).
 * Returns true on success, false otherwise.
 */
function safe_decrement_order_current_count(PDO $pdo, $order_id) {
    $sql = "UPDATE orders SET current_count = GREATEST(current_count - 1, 0) WHERE order_id = ?";
    $res = db_prepare_execute_with_retry($pdo, $sql, [$order_id]);
    if ($res === false) {
        error_log("safe_decrement_order_current_count failed for order {$order_id}");
        return false;
    }
    return true;
}
/**
 * Retrieves a user from the database or creates a new one if they don't exist.
 */
function get_or_create_user($user_id) {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    if ($user) {
        // VIP Expiration Check
        if ($user['is_vip'] && $user['vip_expires_at'] && strtotime($user['vip_expires_at']) < time()) {
            $pdo->prepare("UPDATE users SET is_vip = 0, vip_expires_at = NULL WHERE user_id = ?")->execute([$user_id]);
            $user['is_vip'] = 0;
            send_message($user_id, get_message('vip_expired', $user['language']));
        }
        $user['is_new'] = false;
    } else {
        // Create a new user with 0 coins. The welcome gift is given upon activation.
        $stmt = $pdo->prepare("INSERT INTO users (user_id, coins, created_at) VALUES (?, 0, CURRENT_TIMESTAMP)");
        $stmt->execute([$user_id]);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        $user['is_new'] = true;
    }
    $user['user_data'] = json_decode($user['user_data'] ?? '{}', true) ?: [];
    $user['notifications'] = json_decode($user['notifications'] ?? '{"order_progress":true, "broadcast":true, "system_warnings":true}', true) ?: [];
    return $user;
}
function set_user_state($user_id, $state, $data = []) {
    $pdo = get_pdo();
    $max_attempts = 6;
    $attempt = 0;
    while (true) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET state = ?, user_data = ? WHERE user_id = ?");
            $stmt->execute([$state, json_encode($data), $user_id]);
            // موفقیت — خروج
            return true;
        } catch (PDOException $e) {
            $attempt++;
            $msg = $e->getMessage();
            // اگر خطای lock/wait timeout است، با backoff مجدداً تلاش کن
            if ((stripos($msg, 'lock') !== false || stripos($msg, 'timeout') !== false) && $attempt < $max_attempts) {
                // backoff افزایشی (100ms * attempt) — کمی طولانی‌تر تا مسابقه‌ی قفل کمتر شود
                usleep(100000 * $attempt);
                continue;
            }
            // اگر خطای دیگری بود یا تلاشها تمام شد، فقط لاگ کن و **استثناء را پرتاب نکن** تا پیغام عمومی برای کاربر ارسال نشود
            error_log("set_user_state error (user {$user_id}): " . $e->getMessage());
            // بازگشت false به عنوان نشانهٔ عدم موفقیت (سازگار با فراخوانی‌هایی که نتیجه را چک نمی‌کنند)
            return false;
        }
    }
}
/**
 * Creates a new order for a user, now with bonus coins.
 */
function create_order($user_id, $channel_id, $member_count, $bonus_coins = 0) {
    $pdo = get_pdo();
    $user = get_or_create_user($user_id);
    $base_cost_per_member = $GLOBALS['bot_settings']['ORDER_COST_PER_MEMBER'];
    if ($user['is_vip']) {
        $base_cost_per_member *= 0.95;
    }
    $cost = ($member_count * $base_cost_per_member) + $bonus_coins;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT coins FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $current_coins = (float)$stmt->fetchColumn();
        if ($current_coins < $cost) {
            $pdo->rollBack();
            return false;
        }
        _update_user_coins_and_history($pdo, $user_id, -$cost, "reason_create_order");
    
        add_xp($user_id, floor($member_count / 10));
        $stmt = $pdo->prepare("INSERT INTO orders (user_id, channel_id, required_users, bonus_coins, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([$user_id, $channel_id, $member_count, $bonus_coins]);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Order creation error: " . $e->getMessage());
        return false;
    }
}
// ========================================================================
// SECTION 5: MESSAGE & KEYBOARD GENERATION
// ========================================================================
/**
 * Retrieves a localized message string.
 */
function get_message($key, $lang = 'fa') {
    static $messages = null;
    if ($messages === null) {
        // ** FIX: Removed all markdown characters like '*' from messages **
        $messages = [
            'welcome_new_user' => ["fa" => "🌟 به ربات عضوگیر خوش آمدید!\n\nاین ربات به شما کمک می‌کند تا اعضای کانال و گروه خود را افزایش دهید.", "en" => "🌟 Welcome to the Membership Bot!\nThis bot helps you increase your channel and group members."],
            'activation_success' => ["fa" => "✅ حساب شما با موفقیت فعال شد!\n\n🎁 {gift} سکه به عنوان هدیه خوش‌آمدگویی به شما تعلق گرفت!", "en" => "✅ Your account has been successfully activated!\n\n🎁 You received {gift} coins as a welcome gift!"],
            'daily_gift_btn' => ['fa' => '🎁 هدیه روزانه', 'en' => '🎁 Daily Gift'],
            'daily_gift_claimed' => ["fa" => "🎉 شما {amount} سکه به عنوان هدیه روزانه دریافت کردید!\n\nموجودی جدید: {new_balance} سکه.", "en" => "🎉 You received {amount} coins as your daily gift!\n\nNew balance: {new_balance} coins."],
            'daily_gift_already_claimed' => ["fa" => "❌ شما قبلاً هدیه روزانه خود را دریافت کرده‌اید. لطفاً {time_left} دیگر دوباره تلاش کنید.", "en" => "❌ You have already claimed your daily gift. Please try again in {time_left}."],
            'reason_daily_gift' => ['fa' => 'هدیه روزانه', 'en' => 'Daily Gift'],
            'purchase_approved_coins' => ["fa" => "✅ خرید {coins} سکه شما تایید شد.\n\nموجودی جدید شما: {new_balance} سکه.", "en" => "✅ Your purchase of {coins} coins has been approved.\n\nYour new balance: {new_balance} coins."],
            'purchase_approved_boost' => ["fa" => "✅ درخواست بوست سفارش #{order_id} شما تایید و فعال شد.", "en" => "✅ Your boost request for order #{order_id} has been approved and activated."],
            'purchase_approved_vip' => ["fa" => "✅ اشتراک ویژه شما فعال شد و تا ۳۰ روز آینده معتبر است. از مزایای خود لذت ببرید!", "en" => "✅ Your VIP subscription is now active for 30 days. Enjoy the benefits!"],
            'purchase_rejected_generic' => ["fa" => "❌ درخواست شما با شماره پیگیری {order_number} توسط ادمین رد شد. برای پیگیری با پشتیبانی در تماس باشید.", "en" => "❌ Your request with tracking number {order_number} was rejected by the admin. Please contact support for follow-up."],
            'ticket_received_admin' => ["fa" => "🎫 تیکت جدید از کاربر {user_id}:\n\n{text}\n\nبرای پاسخ، روی همین پیام ریپلای کنید.", "en" => "🎫 New ticket from user {user_id}:\n\n{text}\n\nTo reply, simply reply to this message."],
            'help_text' => [
                "fa" => "📘 راهنمای کامل ربات — ساده و گام‌به‌گام\n\nسلام! خوش‌آمدید — این راهنما طوری نوشته شده که حتی اگر از ربات و تلگرام چیزی ندانید، با یک بار خواندن بتوانید از همهٔ امکانات استفاده کنید.\n\n۱) شروع سریع\n• ارسال /start برای فعال شدن حساب. پس از فعال‌سازی منوهای اصلی نمایش داده می‌شوند.\n\n۲) منوی اصلی — دکمه‌ها و معنی ساده‌شان\n• ➕ ثبت سفارش عضو : ساخت سفارش برای افزایش اعضای کانال/گروه (گام‌به‌گام).\n• 💰 جمع‌آوری سکه : روش‌های رایگان برای کسب سکه (عضویت‌ها و سکه روزانه).\n• 📊 سفارش‌های من : دنبال‌کردن وضعیت سفارش‌ها، مشاهده پیشرفت و جزئیات.\n• 💵 خرید سکه : خرید سریع سکه در صورت نیاز.\n• 🖇️ زیرمجموعه‌گیری : لینک دعوت شما و مقدار سکه‌ای که بابت هر دعوت می‌گیرید.\n• ✨ حساب ویژه (VIP) : اگر اشتراک VIP دارید مزایا و ویژگی‌های آن در این بخش است.\n• 📜 تاریخچه سکه‌ها : لیست تراکنش‌ها و تغییرات موجودی.\n• ⚙️ تنظیمات : تغییر زبان، اعلان‌ها و تنظیمات شخصی.\n• 📞 پشتیبانی : ارسال تیکت به تیم پشتیبانی (برای مشکلات و پیگیری سفارش).\n• ➕ افزودن کانال/گروه جدید : قبل از ثبت سفارش، باید کانال/گروه خود را اضافه کنید.\n• 🔍 بررسی عضویت‌ها : چک کردن وضعیت اعضای اضافه‌شده توسط ربات.\n• 🚀 بوست سفارش : افزایش اولویت یا سرعت اجرای سفارش (اگر سرویس فعال باشد).\n• 🚫 لغو سفارش : لغو سفارش فعلی (در صورت امکان و مطابق قوانین).\n\n۳) نحوهٔ سادهٔ ثبت سفارش (گام‌به‌گام)\n1. اگر کانالتان را اضافه نکرده‌اید، «➕ افزودن کانال/گروه جدید» را بزنید و دنبال مراحل باشید (ربات باید ادمین کانال باشد).\n2. موجودی‌تان را در «💰 جمع‌آوری سکه» یا «💵 خرید سکه» تأمین کنید؛ همچنین «🎁 هدیه روزانه» را هر روز بگیرید.\n3. «➕ ثبت سفارش عضو» را انتخاب کنید: کانال را انتخاب، تعداد اعضا را وارد و سرعت/گزینه‌های اضافی (مانند بوست یا سکهٔ پاداش) را مشخص کنید.\n4. سفارش ثبت می‌شود — وضعیتش را در «📊 سفارش‌های من» ببینید. در صورت نیاز می‌توانید «🚫 لغو سفارش» کنید.\n5. بعد از تکمیلی سفارش با دکمه «🔍 بررسی عضویت‌ها» وضعیت خروجی کاربران را چک کنید و سکه کسانی که بیرون امدند را پس بگیرید.\n\n۴) نکات مهم و حل مشکلات متداول\n• ربات باید ادمین کانال/گروه باشد: بدون دسترسی مدیریت، سفارش اجرا نخواهد شد. (دسترسی:مدیریت اعضا)\nبرای اموزش تصویری اضافه کردن ربات به کانال/گروه این کامند را ارسال کنید\n/HelpAddBot - راهنمای اضافه کردن ربات در کانال/گروه\n• اگر اعضا اضافه نشدند: اول از همه بررسی کنید ربات ادمین است، سپس لینک/شناسهٔ کانال درست باشد؛ اگر مشکل ادامه داشت به «📞 پشتیبانی» تیکت بزنید و شمارهٔ سفارش را بفرستید.\n• بازگشت سکه/مرجوعی: فقط در مشکلات فنی و طبق بررسی پشتیبانی انجام می‌شود؛ برای پیگیری، اطلاعات سفارش را در تیکت قرار دهید.\n• VIP چیست؟: اشتراک ویژه معمولاً اولویت/هدایا/سقف‌های بالاتر می‌دهد — جزئیات و خرید در بخش «✨ حساب ویژه (VIP)» است.\n\n۵) تیکت زدن (راهنمای تهیه گزارش برای پشتیبانی)\n• در بخش «📞 پشتیبانی» روی ایجاد تیکت بزنید.\n• در متن: شمارهٔ سفارش (اگر دارید)، لینک کانال/گروه، شرح کوتاه مشکل و در صورت امکان تصویر (اسکرین‌شات) قرار دهید.\n\n۶) سوالات کوتاه (FAQ)\nQ: چرا سفارش اجرا نمی‌شود؟\nA: معمول‌ترین دلیل‌ها: ربات ادمین نیست، کانال خصوصی یا لینک اشتباه است یا قوانین تلگرام مانع شده. ابتدا دسترسی‌ها را چک کنید سپس تیکت بزنید.\n\nQ: مدت تکمیل سفارش چقدر است؟\nA: بستگی به اندازه سفارش، سرعت انتخابی و ترافیک دارد — وضعیت دقیق را در «📊 سفارش‌های من» می‌بینید.\n\nQ: برای عضویت‌های سریع باید چی کار کنم؟\nA: می‌توانید از «🚀 بوست سفارش» یا افزودن سکهٔ پاداش هنگام ثبت سفارش استفاده کنید تا اجرا سریع‌تر یا اولویت‌بندی شود.\n\nبا ارسال /help همیشه همین متن نمایش داده می‌شود.",
                "en" => "Complete bot guide (English summary)\n\nUse /start to activate your account. Main buttons: Add Channel, Collect Coins, Order Members, My Orders, Buy Coins, Referrals, VIP, History, Settings, Support. Steps: add your channel (bot must be admin), collect or buy coins, create an order, track it in My Orders. If problems, open a ticket with your order ID. Use /HelpAddBot for setup tutorial."
            ],
            'help_add_bot_text' => [
                "fa" => "🛠 راهنمای اضافه کردن ربات به کانال/گروه:\n\n1. وارد کانال یا گروه شوید.\n2. به بخش «مدیریت» یا «اطلاعات کانال/گروه» بروید و گزینه «افزودن عضو» یا «Add Members» را انتخاب کنید.\n3. @URGtelegramBot را جستجو و اضافه کنید.\n4. سپس از بخش «مدیران / Administrators» گزینه «افزودن مدیر» یا «Add Administrator» را انتخاب کنید.\n5. ربات را انتخاب کنید و ان را ادمین کنید.\n\nتصویر راهنما ارسال می‌شود و زیر آن همین راهنما به‌صورت متن نمایش داده خواهد شد.",
                "en" => "How to add the bot to your channel/group as admin:\n\n1. Open your channel/group settings.\n2. Add the bot as a member (search for @URGtelegramBot and add it.\n3. Promote the bot to an administrator: Channel/Group Info -> Administrators -> Add Administrator -> Select the bot and admin it. -> Save.\n\nA help image will be sent, followed by the same textual instructions."
            ],
            'referral_info' => ["fa" => "🖇️ با دعوت دوستان خود به ربات، به ازای هر نفر {reward} سکه دریافت کنید!\n\nتعداد زیرمجموعه‌های شما: {referrals} نفر\nدرآمد کل از این طریق: {coins} سکه\n\nلینک دعوت شما:\n{link}", "en" => "🖇️ Invite your friends and get {reward} coins for each one!\n\nYour referrals: {referrals} users\nTotal earnings: {coins} coins\n\nYour referral link:\n{link}"],
            'leaderboard_item' => ["fa" => "{rank}. {user_mention} - {coins} سکه", "en" => "{rank}. {user_mention} - {coins} coins"],
            'ask_bonus_coins' => ["fa" => "🚀 برای افزایش سرعت و اولویت سفارش، چه مقدار سکه اضافی مایلید به این سفارش تخصیص دهید؟\n\nاین مبلغ بین تمام اعضای جدید تقسیم خواهد شد و پاداش عضویت در کانال شما را افزایش می‌دهد.\n\nحداکثر مجاز: {max_bonus} سکه\n(برای رد کردن، 0 را وارد کنید)", "en" => "🚀 To increase order speed and priority, how many extra coins would you like to allocate to this order?\n\nThis amount will be divided among all new members, increasing their reward for joining.\n\nMax allowed: {max_bonus} coins\n(Enter 0 to skip)"],
            'bonus_too_high' => ["fa" => "❌ مقدار سکه اضافی نمی‌تواند بیشتر از هزینه اصلی سفارش ({max_bonus} سکه) باشد. لطفاً عدد کمتری وارد کنید.", "en" => "❌ The bonus amount cannot be higher than the base order cost ({max_bonus} coins). Please enter a smaller number."],
            'profile_text' => ["fa" => "👤 پروفایل شما {vip_badge}\n\nشناسه: {user_id}\nسطح: {level} ({xp}/{next_level_xp} XP)\nموجودی: {coins} سکه\nزیرمجموعه‌ها: {referrals} نفر\nدرآمد از زیرمجموعه: {ref_coins} سکه\nتاریخ عضویت: {date}", "en" => "👤 Your Profile {vip_badge}\n\nID: {user_id}\nLevel: {level} ({xp}/{next_level_xp} XP)\nBalance: {coins} coins\nReferrals: {referrals} users\nReferral Earnings: {ref_coins} coins\nMember since: {date}"],
            'vip_menu_text' => [
                "fa" => "✨ حساب کاربری ویژه (VIP)\n\nبا ارتقاء حساب خود به سطح ویژه، از مزایای زیر بهره‌مند شوید:\n\n- دریافت دو برابر (۴ سکه) پاداش برای هر زیرمجموعه\n- افزایش ۳۰٪ پاداش عضویت در کانال‌ها\n- تخفیف ۵٪ در ثبت تمام سفارش‌ها\n- اولویت بالاتر سفارش‌های شما در لیست عضویت\n- حذف محدودیت زمانی بین عضویت‌ها\n- دریافت ۲۰ سکه هدیه ماهانه\n- نمایش نشان ویژه ⭐ کنار نام شما\n- قابلیت تنظیم نشان پروفایل دلخواه (emoji)\n\nهزینه اشتراک: {price} تومان برای ۳۰ روز",
                "en" => "✨ VIP Account\n\nUpgrade to a VIP account and enjoy these benefits:\n\n- Receive Double (4 coins) reward for each referral\n- 30% More Join Reward\n- 5% Discount on All Orders\n- Higher Priority for Your Orders\n- No Cooldown Between Joins\n- Receive 20 Coins Monthly Gift\n- Get a ⭐ Badge Next to Your Name\n- Ability to set a custom profile badge (emoji)\n\nSubscription Cost: {price} Toman for 30 days"
            ],
            'auto_unbanned' => [
    'fa' => "✅ حساب شما از حالت مسدودی خارج شد و می‌توانید دوباره از ربات استفاده کنید.\nمنوی شما اکنون به منوی اصلی بازگردانده شد.",
    'en' => "✅ Your account has been unbanned and you can use the bot again.\nYour menu has been returned to the main menu."
],
            'levels_and_rewards_btn' => ['fa' => '🏆 سطوح و جوایز', 'en' => '🏆 Levels & Rewards'],
            'levels_info_text' => ["fa" => "🏆 لیست سطوح و جوایز\n\nبا فعالیت در ربات، امتیاز (XP) کسب کرده و با رسیدن به سطوح جدید، جوایز زیر را دریافت کنید:\n\n{levels_list}", "en" => "🏆 Levels & Rewards List\n\nGain XP by being active in the bot and receive the following rewards for reaching new levels:\n\n{levels_list}"],
            'join_channel_prompt_with_counter' => ["fa" => "💰 سکه این مرحله: {session_coins}\n🪙 کل موجودی: {total_coins}\n\n📢 برای دریافت {reward} سکه، در کانال زیر عضو شوید و سپس دکمه '✅ عضو شدم' را بزنید.\n\nکانال: {channel_title} {owner_badge}\n🔗 لینک عضویت: {invite_link}", "en" => "💰 Coins this session: {session_coins}\n🪙 Total balance: {total_coins}\n\n📢 To receive {reward} coins, join the channel below, then press the '✅ I Joined' button.\n\nChannel: {channel_title} {owner_badge}\n🔗 Join Link: {invite_link}"],
            'ticket_sent' => ['fa' => '✅ تیکت شما با موفقیت برای ادمین ارسال شد. به محض دریافت پاسخ، از طریق همین ربات به شما اطلاع داده خواهد شد.', 'en' => '✅ Your ticket has been successfully sent to the admin. You will be notified via this bot as soon as you receive a reply.'],
            'ticket_status_waiting' => ['fa' => 'وضعیت: ⏳ در انتظار پاسخ', 'en' => 'Status: ⏳ Awaiting Reply'],
            'ticket_status_answered' => ["fa" => "وضعیت: ✅ پاسخ داده شد\n\nپاسخ ادمین:\n{admin_reply}", "en" => "Status: ✅ Answered\n\nAdmin's Reply:\n{admin_reply}"],
            'receipt_sent' => ["fa" => "✅ فیش شما برای تایید به ادمین ارسال شد. لطفاً صبور باشید. نتیجه از طریق همین ربات به شما اطلاع داده خواهد شد.", "en" => "✅ Your receipt was sent to the admin for approval. Please be patient. You will be notified of the result via the bot."],
            'main_menu' => ["fa" => "🏠 منوی اصلی\n💰 موجودی شما: {coins} سکه\n\nبرای راهنمایی دستور /help را ارسال کنید.", "en" => "🏠 Main Menu\n💰 Your balance: {coins} coins\n\nSend /help for guidance."],
            'ask_language' => ["fa" => "لطفاً زبان خود را انتخاب کنید:\n\nPlease select your language:", "en" => "Please select your language:\n\nلطفاً زبان خود را انتخاب کنید:"],
            'add_members' => ['fa' => '➕ ثبت سفارش عضو', 'en' => '➕ Order Members'],
            'collect_coins' => ['fa' => '💰 جمع‌آوری سکه', 'en' => '💰 Collect Coins'],
            'my_orders_btn' => ['fa' => '📊 سفارش‌های من', 'en' => '📊 My Orders'],
            'account_btn' => ['fa' => '👤 حساب کاربری', 'en' => '👤 My Account'],
            'buy_coins_btn' => ['fa' => '💵 خرید سکه', 'en' => '💵 Buy Coins'],
            'referrals_btn' => ['fa' => '🖇️ زیرمجموعه‌گیری', 'en' => '🖇️ Referrals'],
            'vip_account_btn' => ['fa' => '✨ حساب ویژه (VIP)', 'en' => '✨ VIP Account'],
            'account_menu' => ["fa" => "👤 حساب کاربری\n\nاز این بخش می‌توانید اطلاعات حساب، تنظیمات و سایر موارد را مدیریت کنید.", "en" => "👤 My Account\n\nHere you can manage your account information, settings, and more."],
            'profile_btn' => ['fa' => '📈 پروفایل و آمار', 'en' => '📈 Profile & Stats'],
            'gift_coins_btn' => ['fa' => '🎁 هدیه دادن سکه', 'en' => '🎁 Gift Coins'],
            'settings_btn' => ['fa' => '⚙️ تنظیمات', 'en' => '⚙️ Settings'],
            'support_btn' => ['fa' => '📞 پشتیبانی', 'en' => '📞 Support'],
            'settings_menu' => ["fa" => "⚙️ تنظیمات\n\nتنظیمات حساب کاربری خود را مدیریت کنید.", "en" => "⚙️ Settings\n\nManage your account settings."],
            'change_lang_btn' => ['fa' => '🌐 تغییر زبان', 'en' => '🌐 Change Language'],
            'notifications_btn' => ['fa' => '🔔 تنظیمات اعلان‌ها', 'en' => '🔔 Notifications'],
            'boost_order_btn' => ['fa' => '🚀 بوست سفارش', 'en' => '🚀 Boost Order'],
            'back' => ['fa' => '➡️ بازگشت', 'en' => 'Back'],
            'back_to_main_menu' => ['fa' => '🏠 بازگشت به منوی اصلی', 'en' => '🏠 Back to Main Menu'],
            'back_to_account_menu' => ['fa' => '👤 بازگشت به حساب کاربری', 'en' => '👤 Back to Account'],
            'cancel_operation' => ['fa' => '🚫 لغو عملیات', 'en' => '🚫 Cancel Operation'],
            'loading' => ['fa' => '⏳ در حال بارگذاری...', 'en' => '⏳ Loading...'],
            'checking_membership' => ['fa' => '⏳ در حال بررسی عضویت‌ها... این فرآیند ممکن است بسته به تعداد اعضا کمی طول بکشد. لطفاً صبور باشید.', 'en' => '⏳ Checking memberships... This might take a while depending on the number of members. Please be patient.'],
            'error_generic' => ['fa' => '❌ یک خطای غیرمنتظره رخ داد. لطفاً دوباره تلاش کنید.', 'en' => '❌ An unexpected error occurred. Please try again.'],
            'invalid_positive_number' => ['fa' => '❌ لطفاً یک عدد صحیح و مثبت وارد کنید.', 'en' => '❌ Please enter a valid positive number.'],
            'suspended_message' => ["fa" => "🚫 حساب شما به دلیل دریافت {warnings} اخطار مسدود شده است.\n\nدلیل: خروج مکرر از کانال‌ها\nبرای درخواست بررسی و رفع مسدودیت، به آیدی ادمین پیام ارسال کنید\n @DriveURG", "en" => "🚫 Your account has been suspended due to {warnings} warnings.\n\nReason: Leaving channels repeatedly.\nUse the button below to request a review."],
            'request_unban_btn' => ['fa' => 'درخواست بازبینی', 'en' => 'Request Review'],
                        'why_banned_btn' => ['fa' => '❓ چرا مسدود شدم', 'en' => '❓ Why was I banned?'],
            'unban_request_sent' => ['fa' => '✅ درخواست شما برای بازبینی ارسال شد. ادمین به زودی آن را بررسی خواهد کرد.', "en" => "✅ Your request for review has been sent. The admin will check it soon."],
            'my_channels_menu' => ["fa" => "🗂 کانال‌های شما\n\nیک کانال را برای ثبت سفارش انتخاب کنید یا کانال جدیدی اضافه نمایید.", "en" => "🗂 Your Channels\n\nSelect a channel to place an order or add a new one."],
            'add_new_channel_btn' => ['fa' => '➕ افزودن کانال/گروه جدید', 'en' => '➕ Add New Channel/Group'],
            'ask_channel_id' => ["fa" => "🔗 ربات باید در کانال/گروه شما ادمین باشد.\n\nلطفاً یوزرنیم کانال/گروه خود را ارسال کنید.\n\n✅ فرمت‌های صحیح:\n@my_channel\nhttps://t.me/my_channel\n\n❌ فرمت‌های نامعتبر:\nلینک‌های خصوصی (مانند t.me/+...) به هیچ وجه پذیرفته نمی‌شوند.", "en" => "🔗 The bot must be an admin in your channel/group.\n\nPlease send your channel/group username.\n\n✅ Correct formats:\n@my_channel\nhttps://t.me/my_channel\n\n❌ Invalid formats:\nPrivate links (like t.me/+...) are not accepted at all."],
            'channel_added' => ["fa" => "✅ کانال/گروه {title} با موفقیت اضافه شد!", "en" => "✅ Channel/Group {title} added successfully!"],
            'channel_deleted' => ["fa" => "🗑️ کانال {title} حذف شد.", "en" => "🗑️ Channel {title} deleted."],
            'channel_deleted_no_access' => ["fa" => "⚠️ کانال {title} به دلیل عدم دسترسی ربات (حذف ادمینی) حذف شد.", "en" => "⚠️ Channel {title} was removed because the bot lost admin access."],
            'channel_removed_auto' => ["fa" => "⚠️ سفارش شما برای کانال {title} به دلیل عدم دسترسی ربات (حذف ادمینی یا نامعتبر بودن لینک) به صورت خودکار حذف شد و {refund} سکه به شما بازگردانده شد.", "en" => "⚠️ Your order for channel {title} was automatically removed due to the bot losing access (removed as admin or invalid link), and {refund} coins have been refunded to you."],
            'channel_not_found' => ['fa' => '❌ کانال/گروه یافت نشد، لینک نامعتبر است یا ربات به آن دسترسی ندارد. لطفاً از عمومی بودن و صحیح بودن لینک/آیدی اطمینان حاصل کنید.', 'en' => '❌ Channel/Group not found, the link is invalid, or the bot cannot access it. Please ensure it is public and the link/ID is correct.'],
            'bot_not_admin' => ['fa' => '❌ ربات ادمین نیست! لطفاً ربات را به کانال/گروه اضافه کرده و دسترسی‌های لازم را به آن بدهید.', 'en' => '❌ Bot is not an admin! Please add the bot to the channel/group and grant necessary permissions.'],
            'user_not_admin' => ['fa' => '❌ شما ادمین این کانال/گروه نیستید!', 'en' => '❌ You are not an admin of this channel/group!'],
            'channel_already_registered' => ['fa' => '❌ این کانال قبلاً توسط کاربر دیگری ثبت شده است!', 'en' => '❌ This channel is already registered by another user!'],
            'channel_has_active_order' => ['fa' => '❌ این کانال در حال حاضر یک سفارش فعال دارد. شما نمی‌توانید سفارش جدیدی ثبت کنید.', 'en' => '❌ This channel already has an active order. You cannot create a new one.'],
            'referral_notification' => ["fa" => "🎉 یک زیرمجموعه جدید اضافه شد و {reward} سکه به موجودی شما اضافه گردید!", "en" => "🎉 A new referral joined and you received {reward} coins!"],
            'reason_referral' => ["fa" => "جایزه زیرمجموعه", "en" => "Referral reward"],
            'reason_welcome_gift' => ["fa" => "هدیه خوش‌آمدگویی", "en" => "Welcome gift"],
            'ask_member_count' => ["fa" => "کانال انتخاب شده: {title}\nموجودی شما: {coins} سکه\nهزینه هر عضو: {cost_per_member} سکه\n\n🔢 تعداد عضو مورد نیاز را وارد کنید:", "en" => "Selected channel: {title}\nYour balance: {coins} coins\nCost per member: {cost_per_member} coin\n\n🔢 Enter the number of members needed:"],
            'order_created' => ["fa" => "✅ سفارش شما با موفقیت ثبت شد!\n\nپیشرفت سفارش‌های خود را می‌توانید از منوی '📊 سفارش‌های من' پیگیری کنید.", "en" => "✅ Your order has been placed successfully!\n\nYou can track your orders from the '📊 My Orders' menu."],
            'not_enough_coins' => ["fa" => "❌ سکه کافی ندارید!\n\nموجودی شما: {coins} سکه\nهزینه سفارش: {cost} سکه\n\nلطفاً تعداد کمتری را وارد کنید یا سکه بخرید.", "en" => "❌ Not enough coins!\n\nYour balance: {coins} coins\nOrder cost: {cost} coins\n\nPlease enter a smaller amount or buy more coins."],
            'order_completed' => ["fa" => "🎉 سفارش شما برای کانال {title} با موفقیت تکمیل شد!", "en" => "🎉 Your order for channel {title} has been completed!"],
            'order_auto_renewed' => ["fa" => "🔄 سفارش شما برای کانال {title} تکمیل و به صورت خودکار تمدید شد.", "en" => "🔄 Your order for channel {title} was completed and has been auto-renewed."],
            'my_orders_list_header' => ["fa" => "📊 لیست سفارش‌های شما", "en" => "📊 Your Orders List"],
            'check_all_membership_btn' => ['fa' => '🔍 بررسی عضویت‌ها', 'en' => '🔍 Check Memberships'],
            'no_orders_user' => ['fa' => '⛔️ شما هیچ سفارشی ثبت نکرده‌اید.', 'en' => '⛔️ You have no orders.'],
            'order_list_item' => ["fa" => "🆔 {order_id} | 📢 {title}\n📈 {progress_bar} {progress}% ({current}/{required})\n💰 پاداش هر عضو: {reward} سکه\n🗓️ ثبت: {date} | وضعیت: {status}", "en" => "ID: {order_id} | 📢 {title}\n📈 {progress_bar} {progress}% ({current}/{required})\n💰 Reward/member: {reward} coins\n🗓️ Date: {date} | Status: {status}"],
            'active' => ['fa' => 'فعال', 'en' => 'Active'],
            'completed' => ['fa' => 'تکمیل شده', 'en' => 'Completed'],
            'order_cancelled' => ["fa" => '🚫 سفارش لغو شد! {refund} سکه بازپرداخت شد.', "en" => '🚫 Order cancelled! {refund} coins refunded.'],
            'cancel_order_btn' => ['fa' => '🚫 لغو سفارش', 'en' => '🚫 Cancel Order'],
            'toggle_auto_renew_btn' => ['fa' => '🔄 تمدید خودکار: {status}', 'en' => '🔄 Auto-Renew: {status}'],
            'no_orders_to_join' => ['fa' => '⛔️ در حال حاضر هیچ سفارش فعالی برای عضویت وجود ندارد. لطفاً بعداً دوباره تلاش کنید.', 'en' => '⛔️ There are no active orders to join right now. Please try again later.'],
            'retry_btn' => ['fa' => '🔄 تلاش مجدد', 'en' => '🔄 Retry'],
            'confirm_join_btn' => ['fa' => '✅ عضو شدم', 'en' => '✅ I Joined'],
            'skip_btn' => ['fa' => ' رد کردن ➡️', 'en' => 'Skip ➡️'],
'left_penalty_message' => [
    'fa' => "⚠️ شما کانال «{title}» را ترک کردید.\nبه عنوان جریمه، {penalty} سکه از حساب شما کسر شد.",
    'en' => "⚠️ You left the channel \"{title}\".\nAs a penalty, {penalty} coins were deducted from your account."
],
            'join_success_alert' => ["fa" => "✅ +{reward} سکه! در حال یافتن کانال بعدی...", "en" => "✅ +{reward} coins! Finding next channel..."],
            'join_cooldown' => ["fa" => "⏳ لطفاً کمی صبر کنید و دوباره دکمه را بزنید.", "en" => "⏳ Please wait a moment and press the button again."],
            'channel_invalid_admin' => ["fa" => "⚠️ متاسفانه کانال {title} دیگر در دسترس نیست. سفارش آن لغو شد.", "en" => "⚠️ Unfortunately, the channel {title} is no longer available. Its order has been cancelled."],
            'already_joined_rewarded' => ["fa" => "☑️ شما قبلاً پاداش عضویت در این کانال را دریافت کرده‌اید.", "en" => "☑️ You have already received the reward for joining this channel."],
            'not_joined' => ['fa' => '❌ شما هنوز در کانال/گروه عضو نشده‌اید!', 'en' => '❌ You have not joined the channel/group yet!'],
            'warning_message' => ["fa" => "⚠️ شما از کانال {title} خارج شدید! {penalty} سکه کسر شد.\nتعداد اخطارها: {warnings}/{max_warnings}", "en" => "⚠️ You left the channel {title}! {penalty} coins were deducted.\nWarnings: {warnings}/{max_warnings}"],
            'membership_check_result' => ["fa" => "🔍 بررسی عضویت‌ها تکمیل شد.\n\nتعداد {left_count} عضو خارج شده یافت شد و مبلغ {coins_added} سکه به شما بازگردانده شد.", "en" => "🔍 Membership check completed.\n\nFound {left_count} members who left, and {coins_added} coins have been returned to you."],
            'buy_coins_prompt' => ["fa" => "موجودی شما: {coins} سکه\n💵 تعداد سکه مورد نظر برای خرید را وارد کنید:", "en" => "Your balance: {coins} coins\n💵 Enter the number of coins to buy:"],
            'purchase_info' => ["fa" => "💸 قیمت نهایی: {price} تومان\n💳 شماره کارت: {card}\n👤 نام دارنده: {holder}\n🪙 تعداد سکه: {coins}\n🔢 شماره سفارش: {order_number}\n\nلطفاً پس از واریز، عکس واضح فیش پرداختی را ارسال کنید.", "en" => "💸 Final Price: {price} Toman\n💳 Card Number: {card}\n👤 Holder Name: {holder}\n🪙 Coins: {coins}\n🔢 Order Number: {order_number}\n\nPlease send a clear photo of the payment receipt after the transaction."],
            'admin_purchase_notify' => ["fa" => "💰 خرید جدید از کاربر: {user_id}\n\nتعداد سکه: {coins}\nقیمت: {price} تومان\nشماره سفارش: {order_number}\n\nبرای تایید این فیش، روی همین پیام ریپلای کرده و کلمه `اره` را ارسال کنید. برای رد کردن، `نه` را بفرستید.", "en" => "💰 New purchase from user: {user_id}\n\nCoins: {coins}\nPrice: {price} Toman\nOrder Number: {order_number}\n\nTo approve, reply to this message with `yes`. To reject, reply with `no`."],
            'must_be_photo' => ['fa' => '❌ لطفاً فقط عکس فیش پرداختی را ارسال کنید.', 'en' => '❌ Please send only a photo of the receipt.'],
            'coin_history_btn' => ['fa' => '📜 تاریخچه سکه‌ها', 'en' => '📜 Coin History'],
            'leaderboard_btn' => ['fa' => '🏆 جدول برترین‌ها', 'en' => '🏆 Leaderboard'],
            'coin_history_text' => ["fa" => "📜 ۱۰ تراکنش آخر سکه‌های شما:\n\n{history_list}", "en" => "📜 Your last 10 coin transactions:\n\n{history_list}"],
            'no_history' => ['fa' => 'هیچ تراکنشی برای نمایش وجود ندارد.', 'en' => 'No transactions to display.'],
            'history_item' => ["fa" => "{date}: {amount_str} سکه ({reason})", "en" => "{date}: {amount_str} coins ({reason})"],
            'leaderboard_text' => ["fa" => "🏆 جدول برترین کاربران (بر اساس موجودی سکه):\n\n{leaderboard_list}", "en" => "🏆 Top Users Leaderboard (by coin balance):\n\n{leaderboard_list}"],
            'leaderboard_unavailable' => ['fa' => 'ℹ️ جدول برترین‌ها پس از رسیدن تعداد کاربران ربات به ۱۰۰۰ نفر نمایش داده خواهد شد.', 'en' => 'ℹ️ The leaderboard will be available after the bot reaches 1000 users.'],
            'notification_settings_text' => ["fa" => "🔔 تنظیمات اعلان‌ها\n\nمی‌توانید مشخص کنید کدام اعلان‌ها را از ربات دریافت کنید.", "en" => "🔔 Notification Settings\n\nYou can specify which notifications to receive from the bot."],
            'notif_order_progress_btn' => ["fa" => "📈 پیشرفت سفارش: {status}", "en" => "📈 Order Progress: {status}"],
            'notif_broadcast_btn' => ["fa" => "📣 پیام همگانی: {status}", "en" => "📣 Broadcasts: {status}"],
            'notif_system_warnings_btn' => ["fa" => "⚠️ هشدارهای سیستمی: {status}", "en" => "⚠️ System Warnings: {status}"],
            'status_on' => ['fa' => 'فعال ✅', 'en' => 'ON ✅'],
            'status_off' => ['fa' => 'غیرفعال ❌', 'en' => 'OFF ❌'],
            'ask_gift_user_id' => [
    "fa" => "🎁 به چه کسی می‌خواهید سکه هدیه دهید؟\n\nلطفاً **شناسه عددی (ID)** گیرنده را ارسال کنید.\n\nبرای دریافت شناسه عددی خود یا دیگران:\n1️⃣ وارد ربات @userinfobot شوید.\n2️⃣ دکمه Start را بزنید.\n3️⃣ مقدار مقابل `Id:` را کپی کرده و ارسال کنید.\n\nمثال:\n• 12345678\n\nلطفاً از کیبورد پایین برای لغو عملیات استفاده کنید.",
    "en" => "🎁 Who do you want to gift coins to?\n\nPlease send the recipient's **numeric Telegram ID** only.\n\nTo find it:\n1️⃣ Open @userinfobot\n2️⃣ Press Start\n3️⃣ Copy the number after `Id:` and send it here.\n\nExample:\n• 12345678\n\nUse the keyboard below to cancel the process."
],
            'ask_gift_amount' => ["fa" => "💰 چه مقدار سکه به کاربر {target_user_id} هدیه می‌دهید؟\n\nموجودی شما: {coins} سکه", "en" => "💰 How many coins will you gift to user {target_user_id}?\n\nYour balance: {coins} coins"],
            'gift_sent' => ["fa" => "✅ {amount} سکه با موفقیت به کاربر {target_user_id} هدیه داده شد.", "en" => "✅ Successfully gifted {amount} coins to user {target_user_id}."],
            'gift_received' => ["fa" => "🎁 شما {amount} سکه از طرف کاربر {sender_id} هدیه گرفتید!", "en" => "🎁 You received a gift of {amount} coins from user {sender_id}!"],
            'invalid_user_id' => ['fa' => '❌ شناسه کاربری نامعتبر است یا کاربر در ربات عضو نیست.', 'en' => '❌ Invalid user ID or the user is not in the bot.'],
            'cant_gift_self' => ['fa' => '❌ شما نمی‌توانید به خودتان سکه هدیه دهید!', 'en' => '❌ You cannot gift coins to yourself!'],
            'new_referral' => ["fa" => '✅ یک زیرمجموعه جدید از طریق لینک شما وارد ربات شد! {reward} سکه دریافت کردید.', "en" => '✅ A new referral joined via your link! You received {reward} coins.'],
            'ask_ticket_text' => ["fa" => "لطفاً متن تیکت یا پیام خود را برای ادمین بنویسید:", "en" => "Please write your ticket or message for the admin:"],
            'admin_reply_prefix' => ["fa" => "✉️ پاسخ ادمین به تیکت شما:\n\n", "en" => "✉️ Admin reply to your ticket:\n\n"],
            'ask_boost_order_id' => ['fa' => "🚀 کدام سفارش فعال خود را می‌خواهید بوست کنید؟\n\nبوست کردن باعث می‌شود سفارش شما در اولویت نمایش به کاربران قرار بگیرد و سریع‌تر انجام شود.\n\nلطفاً از لیست زیر انتخاب کنید. اگر سفارشی در لیست نیست، یعنی فعال نمی‌باشد.", 'en' => "🚀 Which active order do you want to boost?"],
            'no_active_orders_to_boost' => ['fa' => '❌ شما هیچ سفارش فعالی برای بوست کردن ندارید.', 'en' => '❌ You have no active orders to boost.'],
            'boost_purchase_info' => ["fa" => "🚀 هزینه بوست کردن سفارش #{order_id} مبلغ {price} تومان است.\n\nلطفاً پس از واریز به کارت زیر، عکس واضح فیش پرداختی را ارسال کنید.\n\n💳 شماره کارت: {card}\n👤 نام دارنده: {holder}\n🔢 شماره پیگیری: {order_number}", "en" => "🚀 The cost to boost order #{order_id} is {price} Toman..."],
            'boost_receipt_sent' => ["fa" => "✅ فیش شما برای تایید بوست سفارش برای ادمین ارسال شد. لطفاً صبور باشید.", "en" => "✅ Your receipt for the order boost was sent to the admin for approval. Please be patient."],
            'admin_boost_notify' => ["fa" => "🚀 درخواست بوست جدید از کاربر: {user_id}\n\nبرای سفارش: #{order_id}\nقیمت: {price} تومان\nشماره پیگیری: {order_number}\n\nبرای تایید این فیش، روی همین پیام ریپلای کرده و کلمه `اره` را ارسال کنید. برای رد کردن، `نه` را بفرستید.", "en" => "🚀 New boost request from user: {user_id}..."],
            'report_channel_btn' => ['fa' => '🚩 گزارش تخلف', 'en' => '🚩 Report Channel'],
            'ask_report_reason' => ['fa' => '🚩 لطفاً دلیل گزارش خود را برای کانال "{title}" بنویسید (مثلا: محتوای نامناسب, کلاهبرداری و...):', 'en' => '🚩 Please write the reason for reporting the channel "{title}":'],
            'report_submitted' => ['fa' => '✅ گزارش شما با موفقیت ثبت شد و توسط ادمین بررسی خواهد شد. از همکاری شما متشکریم.', 'en' => '✅ Your report was submitted successfully and will be reviewed by the admin. Thank you.'],
            'reason_welcome_gift' => ['fa' => 'هدیه خوش‌آمدگویی', 'en' => 'Welcome Gift'],
            'reason_join_reward' => ['fa' => 'عضویت در کانال', 'en' => 'Channel Join'],
            'reason_create_order' => ['fa' => 'ثبت سفارش', 'en' => 'Create Order'],
            'reason_cancel_order' => ['fa' => 'لغو سفارش', 'en' => 'Cancel Order'],
            'reason_purchase' => ['fa' => 'خرید سکه', 'en' => 'Coin Purchase'],
            'reason_referral' => ['fa' => 'پاداش زیرمجموعه', 'en' => 'Referral Bonus'],
            'reason_leave_penalty' => ['fa' => 'جریمه خروج', 'en' => 'Leave Penalty'],
            'reason_refund_left' => ['fa' => 'جبران عضو خارج شده', 'en' => 'Leaver Compensation'],
            'reason_gift_sent' => ['fa' => 'هدیه ارسالی', 'en' => 'Gift Sent'],
            'reason_gift_received' => ['fa' => 'هدیه دریافتی', 'en' => 'Gift Received'],
            'reason_level_up' => ['fa' => 'جایزه ارتقاء سطح', 'en' => 'Level Up Bonus'],
            'reason_vip_gift' => ['fa' => 'هدیه اشتراک ویژه', 'en' => 'VIP Subscription Gift'],
            'level_up_message' => ["fa" => "🎉 تبریک! شما به سطح {level} رسیدید!\n\n{reward_text}", "en" => "🎉 Congratulations! You reached level {level}!\n\n{reward_text}"],
            'purchase_vip_btn' => ['fa' => '💳 خرید اشتراک ویژه', 'en' => '💳 Purchase VIP Subscription'],
            'vip_purchase_info' => ["fa" => "✨ هزینه اشتراک ویژه (VIP) به مدت ۳۰ روز، مبلغ {price} تومان است.\n\nلطفاً پس از واریز به کارت زیر، عکس واضح فیش پرداختی را ارسال کنید.\n\n💳 شماره کارت: {card}\n👤 نام دارنده: {holder}\n🔢 شماره پیگیری: {order_number}", "en" => "✨ The cost for a 30-day VIP subscription is {price} Toman..."],
            'vip_receipt_sent' => ["fa" => "✅ فیش شما برای فعال‌سازی اشتراک ویژه برای ادمین ارسال شد. لطفاً صبور باشید.", "en" => "✅ Your receipt for the VIP subscription was sent to the admin for approval. Please be patient."],
            'admin_vip_notify' => ["fa" => "✨ درخواست اشتراک VIP جدید از کاربر: {user_id}\n\nقیمت: {price} تومان\nشماره پیگیری: {order_number}\n\nبرای تایید این فیش، روی همین پیام ریپلای کرده و کلمه `اره` را ارسال کنید. برای رد کردن، `نه` را بفرستید.", "en" => "✨ New VIP subscription request from user: {user_id}..."],
            'vip_activated' => ["fa" => "✅ اشتراک ویژه شما فعال شد و تا ۳۰ روز آینده معتبر است. از مزایای خود لذت ببرید!", "en" => "✅ Your VIP subscription is now active and valid for the next 30 days. Enjoy your benefits!"],
            'vip_already_active' => ["fa" => "✨ شما در حال حاضر عضو ویژه هستید.\nاشتراک شما در تاریخ {date} به پایان می‌رسد.", "en" => "✨ You are already a VIP member.\nYour subscription ends on {date}."],
            'vip_expired' => ["fa" => "⚠️ اشتراک ویژه شما به پایان رسید. برای استفاده مجدد از مزایا، لطفاً آن را تمدید کنید.", "en" => "⚠️ Your VIP subscription has expired. Please renew it to continue enjoying the benefits."],
            'vip_set_badge_btn' => ['fa' => '🎨 تنظیم نشان پروفایل', 'en' => '🎨 Set Profile Badge'],
            'vip_ask_badge' => ['fa' => '🎨 لطفاً یک نشان برای پروفایل خود انتخاب کنید:', 'en' => '🎨 Please choose a badge for your profile:'],
            'badge_set_success' => ['fa' => '✅ نشان پروفایل شما با موفقیت به {badge} تغییر یافت.', 'en' => '✅ Your profile badge was successfully changed to {badge}.'],
        ];
    }
    return $messages[$key][$lang] ?? $messages[$key]['en'] ?? $key;
}
/**
 * Generates the main keyboard for the user.
 */
function get_main_keyboard($user) {
    $lang = $user['language'];
        if ($user['is_suspended']) {
        return [[['text' => get_message('why_banned_btn', $lang)]], [['text' => get_message('support_btn', $lang)]]];
    }
    return [
        [['text' => get_message('add_members', $lang)], ['text' => get_message('collect_coins', $lang)]],
        [['text' => get_message('my_orders_btn', $lang)], ['text' => get_message('account_btn', $lang)]],
        [['text' => get_message('buy_coins_btn', $lang)], ['text' => get_message('referrals_btn', $lang)]],
        [['text' => get_message('vip_account_btn', $lang)]],
    ];
}
/**
 * Generates a simple cancel keyboard.
 */
function get_cancel_keyboard($lang) {
    return [[['text' => get_message('cancel_operation', $lang)]]];
}
// ========================================================================
// SECTION 6: COIN COLLECTION & VALIDATION LOGIC
// ========================================================================
/**
 * Invalidates an order, refunds the owner, and notifies them.
 */
function invalidate_order($order_id) {
    global $bot_settings;
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT o.*, c.title, c.owner_user_id FROM orders o JOIN channels c ON o.channel_id = c.channel_id WHERE o.order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    if (!$order || !$order['is_active']) return false;
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE orders SET is_active = 0 WHERE order_id = ?")->execute([$order_id]);
    
        $owner = get_or_create_user($order['owner_user_id']);
        $cost_per_member = $bot_settings['ORDER_COST_PER_MEMBER'];
        if ($owner['is_vip']) {
            $cost_per_member *= 0.95;
        }
        $remaining_members = $order['required_users'] - $order['current_count'];
        $bonus_per_member = ($order['required_users'] > 0) ? ($order['bonus_coins'] / $order['required_users']) : 0;
        $refund = ($remaining_members * $cost_per_member) + ($remaining_members * $bonus_per_member);
        if ($refund > 0) {
            _update_user_coins_and_history($pdo, $order['owner_user_id'], $refund, 'reason_cancel_order');
        }
        $pdo->commit();
    
        if ($owner && ($owner['notifications']['system_warnings'] ?? true)) {
            $msg_raw = get_message('channel_removed_auto', $owner['language']);
            $msg_with_values = str_replace(['{title}', '{refund}'], [$order['title'], format_coins($refund)], $msg_raw);
            send_message($order['owner_user_id'], $msg_with_values);
        }
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Failed to invalidate order {$order_id}: " . $e->getMessage());
        return false;
    }
}
/**
 * Check validity of an order's channel (getChat) and if invalid,
 * invalidate the order (and notify owner) immediately.
 * Returns true if order is valid, false if invalid (and removed).
 */
function check_order_validity_and_cleanup($order_id) {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT o.*, c.channel_id, c.title, c.owner_user_id FROM orders o JOIN channels c ON o.channel_id = c.channel_id WHERE o.order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    if (!$order) return false;
    $channel_id = $order['channel_id'];
    $chat_info = api_request('getChat', ['chat_id' => $channel_id]);
    if (!($chat_info['ok'] ?? false)) {
        // کانال/گروه در دسترس نیست — حذف سفارش و اطلاع صاحب کانال
        invalidate_order($order_id);
        if (!empty($order['owner_user_id'])) {
            $msg = "❌ سفارش شما (کانال: {$order['title']}) به‌دلیل لینک/دسترسی نامعتبر حذف شد.";
            send_message($order['owner_user_id'], $msg);
        }
        return false;
    }
    // اگر chat info برگشت، کانال معتبر در نظر گرفته می‌شود
    return true;
}
/**
 * Retrieves the next valid channel for a user using a weighted random selection.
 */
function get_next_joinable_channel($user, $skipped_order_id = null) {
    $pdo = get_pdo();
    $query = "
        SELECT o.order_id, c.channel_id, c.title, c.invite_link, o.bonus_coins, o.required_users, o.is_boosted, o.user_id as owner_user_id
        FROM orders o
        JOIN channels c ON o.channel_id = c.channel_id
        LEFT JOIN channel_joins j ON j.channel_id = c.channel_id AND j.user_id = :user_id
        LEFT JOIN user_blacklist ub ON ub.channel_id = c.channel_id AND ub.user_id = :user_id
        WHERE o.is_active = 1
          AND o.user_id != :user_id
          AND j.user_id IS NULL
          AND ub.user_id IS NULL
    ";
    $params = [':user_id' => $user['user_id']];
    if ($skipped_order_id !== null) {
        $query .= " AND o.order_id != :skipped_order_id";
        $params[':skipped_order_id'] = $skipped_order_id;
    }
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
    if (empty($orders)) return null;
    $weighted_orders = [];
    $total_weight = 0;
    foreach ($orders as $order) {
        $base_cost = $order['required_users'] * $GLOBALS['bot_settings']['ORDER_COST_PER_MEMBER'];
        // Weight is 1 (base) + percentage of bonus coins relative to base cost
        $weight = 1 + ($base_cost > 0 ? ($order['bonus_coins'] / $base_cost) : 0);
    
        // Boosted orders get a massive, fixed weight advantage
        if ($order['is_boosted']) {
            $weight = 1000;
        }
        $weighted_orders[] = ['order' => $order, 'weight' => $weight];
        $total_weight += $weight;
    }
    // Weighted random selection
    $rand = mt_rand() / mt_getrandmax() * $total_weight;
    foreach ($weighted_orders as $weighted_order) {
        $rand -= $weighted_order['weight'];
        if ($rand <= 0) {
            return $weighted_order['order'];
        }
    }
    // Fallback to the last order just in case of floating point inaccuracies
    return end($weighted_orders)['order'];
}
/**
 * Displays a channel for the user to join, now with owner's badges.
 */
function handle_collect_coins($user, $chat_id, $message_id = null, $skipped_order_id = null, $is_new_session = false) {
    global $bot_settings;
    if ($is_new_session) {
        $user['user_data']['session_coins'] = 0;
        set_user_state($user['user_id'], STATE_DEFAULT, $user['user_data']);
    }
    $order = get_next_joinable_channel($user, $skipped_order_id);
    if (!$order) {
        $msg = get_message('no_orders_to_join', $user['language']);
        $keyboard = [[['text' => get_message('retry_btn', $user['language']), 'callback_data' => 'collect_coins_retry']], [['text' => get_message('back_to_main_menu', $user['language']), 'callback_data' => 'back_main_menu']]];
        $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
        if ($message_id) {
            edit_message($chat_id, $message_id, $msg, ['reply_markup' => $reply_markup]);
        } else {
            send_message($chat_id, $msg, ['reply_markup' => $reply_markup]);
        }
        return;
    }
    $session_coins = $user['user_data']['session_coins'] ?? 0;
    $user_coins = $user['coins'] ?? 0;
    $base_reward = get_user_join_reward($user);
    $bonus_reward = ($order['required_users'] > 0) ? floor($order['bonus_coins'] / $order['required_users']) : 0;
    $total_reward = $base_reward + $bonus_reward;
    // Get owner's badges
    $owner = get_or_create_user($order['owner_user_id']);
    $owner_badge = get_user_badge($owner);
    $msg_raw = get_message('join_channel_prompt_with_counter', $user['language']);
    $msg_with_values = str_replace(
        ['{session_coins}', '{total_coins}', '{reward}', '{channel_title}', '{owner_badge}', '{invite_link}'],
        [format_coins($session_coins), format_coins($user_coins), format_coins($total_reward), $order['title'], $owner_badge, $order['invite_link']],
        $msg_raw
    );
    $keyboard = [
        [
            ['text' => get_message('confirm_join_btn', $user['language']), 'callback_data' => "join_confirm_{$order['order_id']}"],
            ['text' => get_message('skip_btn', $user['language']), 'callback_data' => "skip_channel_{$order['order_id']}"]
        ],
        [
            ['text' => get_message('report_channel_btn', $user['language']), 'callback_data' => "report_channel_{$order['channel_id']}_{$order['order_id']}"]
        ]
    ];
    $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
    if ($message_id) {
        edit_message($chat_id, $message_id, $msg_with_values, ['reply_markup' => $reply_markup, 'disable_web_page_preview' => true]);
    } else {
        send_message($chat_id, $msg_with_values, ['reply_markup' => $reply_markup, 'disable_web_page_preview' => true]);
    }
}
/**
 * Handles the user's confirmation of joining a channel.
 */
function handle_join_confirmation($user, $chat_id, $message_id, $order_id, $callback_query_id) {
    global $bot_settings;
    $pdo = get_pdo();
    $now = time();
    $last_click = isset($user['last_collect_time']) ? strtotime($user['last_collect_time']) : 0;
    $cooldown = $user['is_vip'] ? 0 : $bot_settings['JOIN_COOLDOWN'];
    if ($now - $last_click < $cooldown) {
        api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => get_message('join_cooldown', $user['language']), 'show_alert' => true]);
        return;
    }
    $stmt_order = $pdo->prepare("SELECT o.*, c.title, c.channel_id FROM orders o JOIN channels c ON o.channel_id = c.channel_id WHERE o.order_id = ?");
    $stmt_order->execute([$order_id]);
    $order = $stmt_order->fetch();
    if (!$order || !$order['is_active']) {
        api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
        handle_collect_coins($user, $chat_id, $message_id);
        return;
    }
    $stmt_check_join = $pdo->prepare("SELECT 1 FROM channel_joins WHERE user_id = ? AND channel_id = ?");
    $stmt_check_join->execute([$user['user_id'], $order['channel_id']]);
    if ($stmt_check_join->fetchColumn()) {
        api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => get_message('already_joined_rewarded', $user['language']), 'show_alert' => true]);
        handle_collect_coins($user, $chat_id, $message_id, $order['order_id']);
        return;
    }
    $chat_member = api_request('getChatMember', ['chat_id' => $order['channel_id'], 'user_id' => $user['user_id']]);
    if (!($chat_member['ok'] ?? false)) {
        api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
        invalidate_order($order_id);
        handle_collect_coins($user, $chat_id, $message_id, $order['order_id']);
        return;
    }
    if (!in_array($chat_member['result']['status'], ['member', 'administrator', 'creator'])) {
        api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => get_message('not_joined', $user['language']), 'show_alert' => true]);
        return;
    }
    $base_reward = get_user_join_reward($user);
    $bonus_reward = ($order['required_users'] > 0) ? floor($order['bonus_coins'] / $order['required_users']) : 0;
    $total_reward = $base_reward + $bonus_reward;
    $pdo->beginTransaction();
    try {
        _update_user_coins_and_history($pdo, $user['user_id'], $total_reward, 'reason_join_reward');
        add_xp($user['user_id'], 1);
        $pdo->prepare("INSERT IGNORE INTO channel_joins (user_id, channel_id, joined_at) VALUES (?, ?, CURRENT_TIMESTAMP)")->execute([$user['user_id'], $order['channel_id']]);
        $pdo->prepare("INSERT IGNORE INTO order_members (order_id, member_user_id) VALUES (?, ?)")->execute([$order_id, $user['user_id']]);
        $pdo->prepare("UPDATE orders SET current_count = current_count + 1 WHERE order_id = ?")->execute([$order_id]);
    
        $session_coins = ($user['user_data']['session_coins'] ?? 0) + $total_reward;
        $user['user_data']['session_coins'] = $session_coins;
        $pdo->prepare("UPDATE users SET last_collect_time = CURRENT_TIMESTAMP, user_data = ? WHERE user_id = ?")->execute([json_encode($user['user_data']), $user['user_id']]);
    
        $pdo->commit();
        if (($order['current_count'] + 1) >= $order['required_users']) {
            $pdo->prepare("UPDATE orders SET is_active = 0, is_boosted = 0 WHERE order_id = ?")->execute([$order_id]);
            $owner = get_or_create_user($order['user_id']);
            if ($owner && ($owner['notifications']['order_progress'] ?? true)) {
                $owner_msg_raw = get_message('order_completed', $owner['language']);
                $owner_msg_with_values = str_replace('{title}', $order['title'], $owner_msg_raw);
                send_message($owner['user_id'], $owner_msg_with_values);
            }
            if ($order['auto_renew']) {
                $bonus_coins_for_renew = $order['bonus_coins'];
                if (create_order($order['user_id'], $order['channel_id'], $order['required_users'], $bonus_coins_for_renew)) {
                    if ($owner && ($owner['notifications']['order_progress'] ?? true)) {
                        send_message($order['user_id'], str_replace('{title}', $order['title'], get_message('order_auto_renewed', $owner['language'])));
                    }
                }
            }
        }
    
        $alert_msg_raw = get_message('join_success_alert', $user['language']);
        $alert_msg = str_replace('{reward}', format_coins($total_reward), $alert_msg_raw);
        api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => $alert_msg]);
    
        $updated_user = get_or_create_user($user['user_id']);
        handle_collect_coins($updated_user, $chat_id, $message_id);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Join confirmation DB error for user {$user['user_id']}: " . $e->getMessage());
        api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => get_message('error_generic', $user['language']), 'show_alert' => true]);
    }
}
// ========================================================================
// SECTION 7: FEATURE HANDLERS & BOT LOGIC
// ========================================================================
function check_compulsory_memberships($user, $chat_id) {
    // ایمن‌سازی اولیه
    if (!is_array($user) || empty($user['user_id'])) return true;
    // مدیر (ADMIN) را عبور می‌دهیم
    if (defined('ADMIN_ID') && $user['user_id'] == ADMIN_ID) return true;
    // دسترسی به دیتابیس — اگر خطا بود فرض می‌کنیم هیچ اجباری‌ای وجود ندارد
    try {
        $pdo = get_pdo();
    } catch (Exception $e) {
        return true;
    }
// --- helper: بررسی وجود جدول در دیتابیس (MySQL) ---
if (!function_exists('table_exists')) {
    function table_exists($pdo, $table) {
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            $res = $stmt->fetchAll();
            return !empty($res);
        } catch (Exception $e) {
            // اگر هر خطایی پیش آمد، فرض می‌کنیم جدول نیست
            return false;
        }
    }
}
    // اطمینان از وجود جدولِ پیگیریِ کاربرانِ هر کانال اجباری (یک‌بار ایجاد می‌شود)
try {
    // MySQL-compatible schema
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS compulsory_channel_members (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            channel_id VARCHAR(191) NOT NULL,
            user_id BIGINT NOT NULL,
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_channel_user (channel_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    // اگر نتوانیم جدول را بسازیم، ادامه می‌دهیم اما شمارنده‌ها به‌روز نمی‌شوند
    error_log('compulsory table create error: '.$e->getMessage());
}
    // بارگذاری کانال‌های اجباریِ فعال
    try {
        $stmt = $pdo->query("SELECT * FROM compulsory_channels WHERE is_active = 1 ORDER BY id ASC");
        $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // جدول وجود نداشته باشد یا مشکلی باشد -> نادیده می‌گیریم
        return true;
    }
    if (empty($channels)) return true;
    $now = time();
    $missing = [];
    foreach ($channels as $c) {
        // فیلتر تاریخ انقضاء (در صورت وجود)
        $time_limit = isset($c['time_limit']) ? $c['time_limit'] : (isset($c['time_limit_until']) ? $c['time_limit_until'] : null);
        if ($time_limit && strtotime($time_limit) < $now) continue; // منقضی شده -> نادیده
        // اگر محدودیت تعداد کاربر (user_limit) تعریف شده و فعلاً پر است، دیگر این کانال را اجباری درنظر نگیریم
        $user_limit = isset($c['user_limit']) && intval($c['user_limit']) > 0 ? intval($c['user_limit']) : 0;
        $current_users = isset($c['current_users']) ? intval($c['current_users']) : 0;
        if ($user_limit > 0 && $current_users >= $user_limit) {
            // از آن‌جا که ظرفیت پر شده است، دیگر این کانال را جزِ پرکردنی‌ها نپرس
            continue;
        }
        $chat_ref = $c['channel_id']; // ممکن است @username یا -100... باشد
        // تماس به تلگرام برای بررسی عضویت کاربر
        $res = api_request('getChatMember', ['chat_id' => $chat_ref, 'user_id' => $user['user_id']]);
        $joined = false;
        if (!empty($res['ok'])) {
            $status = $res['result']['status'] ?? 'left';
            if (!in_array($status, ['left', 'kicked'])) $joined = true;
        }
        if (!$joined) {
            $title = isset($c['title']) && $c['title'] !== '' ? $c['title'] : $chat_ref;
            $link = isset($c['invite_link']) && $c['invite_link'] !== '' ? $c['invite_link'] : $chat_ref;
            if (strpos($link, 'http') !== 0 && strpos($link, '@') === 0) {
                $link = "https://t.me/" . ltrim($link, '@');
            }
            $missing[] = ['id' => $c['id'], 'title' => $title, 'link' => $link];
        } else {
            // اگر عضو است، تلاش می‌کنیم او را در جدول پیگیری ثبت کنیم تا دوبار شمارش نشود
            try {
    // MySQL: از ON DUPLICATE KEY استفاده کن (اگر قبلاً بود، joined_at را بروزرسانی می‌کنیم)
    $ins = $pdo->prepare("INSERT INTO compulsory_channel_members (channel_id, user_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE joined_at = CURRENT_TIMESTAMP");
    $ins->execute([$c['channel_id'], $user['user_id']]);
    $is_new = ($ins->rowCount() > 0); // rowCount() در MySQL برای INSERT ON DUPLICATE 1 یا 2 برمی‌گرداند اگر insert/update شود
    // اگر رکورد جدید اضافه شده بود، current_users را افزایش بده
    if ($is_new) {
        $upd = $pdo->prepare("UPDATE compulsory_channels SET current_users = current_users + 1 WHERE channel_id = ?");
        $upd->execute([$c['channel_id']]);
    } else {
        // fallback: اگر rowCount() برای درایور به درستی مقدار نداد، یک SELECT ساده انجام بده
        if (table_exists($pdo, 'compulsory_channel_members')) {
            $chk = $pdo->prepare("SELECT 1 FROM compulsory_channel_members WHERE channel_id = ? AND user_id = ? LIMIT 1");
            $chk->execute([$c['channel_id'], $user['user_id']]);
            if ($chk->rowCount() == 0) {
                // اگر رکوردی وجود نداشت (یعنی INSERT واقعاً موفقیت‌آمیز بوده ولی rowCount خوانده نشده)، افزایش بده
                $upd = $pdo->prepare("UPDATE compulsory_channels SET current_users = current_users + 1 WHERE channel_id = ?");
                $upd->execute([$c['channel_id']]);
            }
        } else {
            // جدول پیگیری وجود ندارد؛ فقط لاگ کن و ادامه بده
            error_log('compulsory_channel_members table missing — skipping tracking for channel ' . $c['channel_id']);
        }
    }
} catch (Exception $e) {
    // لاگ خطا و ادامه (قبلاً هم همین رفتار بود)
    error_log('compulsory_channel_members insert error: ' . $e->getMessage());
}
        }
    }
if (!empty($missing)) {
    // متن پیام برای کاربر
    $text = "✳️ برای ادامهٔ استفاده از ربات لطفاً ابتدا در کانال(های) زیر عضو شوید:\n\n";
    foreach ($missing as $m) {
        $text .= "• " . $m['title'] . "\n" . $m['link'] . "\n\n";
    }
    $text .= "پس از عضویت، روی دکمهٔ «بررسی مجدد» بزنید تا وضعیت شما کنترل شود.";
    // ساخت کیبورد با دکمه‌های URL برای هر کانال
    $inline_keyboard = [];
    foreach ($missing as $m) {
        $inline_keyboard[] = [['text' => $m['title'], 'url' => $m['link']]];
    }
    // یک دکمهٔ بررسی مجدد به صورت callback اضافه می‌کنیم
    $inline_keyboard[] = [['text' => 'بررسی مجدد', 'callback_data' => 'compulsory_check']];
    // تنظیم حالت کاربر تا بدانیم منتظر تایید عضویتِ اجباری است
    // (در این پروژه ثابتِ از پیش تعریف شده برای حالت‌ها وجود دارد،
    // ولی اینجا برای ایمنی مستقیماً رشته می‌گذاریـم تا نیازی به تعریف ثابت جدید نباشد)
    set_user_state($user['user_id'], 'awaiting_compulsory_membership');
    send_message($chat_id, $text, ['reply_markup' => json_encode(['inline_keyboard' => $inline_keyboard])]);
    return false;
}
    return true; // همه چیز اوکی
}
if (!function_exists('handle_start')) {
    /**
 * بررسیِ عضویت‌های اجباری.
 * اگر کاربر عضو همه کانال‌ها نباشد، لیستی از کانال‌های مورد نیاز را برای او می‌فرستد
 * و مقدار false برمی‌گرداند تا پردازش فعلی متوقف شود.
 */
function handle_start($user, $chat_id, $text = '/start') {
    $pdo = get_pdo();
// ---- START: ذخیرهٔ رفرال در ابتدای شروع ----
    // پشتیبانی از فرمت‌های مختلفِ /start:
    // "/start 12345" یا "/start=12345" یا "/start12345" (و همچنین /start%2012345)
    $incoming = trim($text);
if (preg_match('/^\/start(?:[ _=]|%20)?(\d+)/i', $incoming, $matches)) {
    $referrer_id = (int)$matches[1];
    // فقط اگر معرفِ جدیدی وجود دارد و هنوز referrer ثبت نشده است و معرف با خودِ کاربر برابر نیست
    // توجه: قبلاً شرط شامل بررسی is_activated بود که در برخی حالت‌ها (مثلاً وقتی
    // عضویتِ اجباری غیرفعال است یا وضعیت کاربر ناهمگن است) باعث می‌شد معرف ثبت نشود.
    // با این تغییر فقط اگر رکورد معرف خالی باشد و مقدار جدید معتبر باشد، ذخیره انجام خواهد شد.
    if (!empty($referrer_id) && empty($user['referrer_id']) && $referrer_id != $user['user_id']) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET referrer_id = ? WHERE user_id = ?");
            $stmt->execute([$referrer_id, $user['user_id']]);
        } catch (Exception $e) {
            // اگر آپدیت با خطا مواجه شد لاگ کن اما جریان را متوقف نکن
            error_log("Failed to save referrer for {$user['user_id']}: " . $e->getMessage());
        }
        // بارگذاری مجددِ کاربر تا اطلاعات جدید (referrer_id) در $user منعکس شود
        $user = get_or_create_user($user['user_id']);
    }
}
    // ---- END: ذخیرهٔ رفرال ----
    // ---- START: اگر کاربر /start ساده فرستاد، هر فرایندی را لغو و به حالت پیش‌فرض بازگردان ----
    if (trim($text) === '/start') {
        // ریستِ حالت کاربر تا هر فرایندی که در حال انجام است کنسل شود
        set_user_state($user['user_id'], STATE_DEFAULT);
    }
    // ---- END: ریست با /start ----
    // ---- START: بررسی عضویت‌های اجباری (همان‌جا نگه داشته شده) ----
    if (!check_compulsory_memberships($user, $chat_id)) {
        return; // اگر عضو نبود، پیام اطلاع‌رسانی قبلاً فرستاده شده؛ پردازش فعلی متوقف می‌شود
    }
    // ---- END: بررسی عضویت‌های اجباری ----
        if ($user['is_new'] || !$user['is_activated']) {
            // New user flow: ask for language
            set_user_state($user['user_id'], STATE_AWAITING_LANGUAGE);
            send_message($chat_id, get_message('welcome_new_user', $user['language']));
            $inline_keyboard = [[['text' => 'فارسی 🇮🇷', 'callback_data' => 'set_lang_fa'], ['text' => 'English 🇬🇧', 'callback_data' => 'set_lang_en']]];
            send_message($chat_id, get_message('ask_language'), ['reply_markup' => json_encode(['inline_keyboard' => $inline_keyboard])]);
        } else {
            // Existing user: show main menu
            set_user_state($user['user_id'], STATE_DEFAULT);
            $coins = format_coins($user['coins']);
            $msg_raw = get_message('main_menu', $user['language']);
            $msg_with_values = str_replace('{coins}', $coins, $msg_raw);
            $reply_markup = json_encode(['keyboard' => get_main_keyboard($user), 'resize_keyboard' => true]);
            send_message($chat_id, $msg_with_values, ['reply_markup' => $reply_markup]);
        }
    }
}
function activate_user_and_grant_rewards($user_id) {
    $pdo = get_pdo();
    $user = get_or_create_user($user_id);
    $lang = $user['language'] ?? 'fa';
    // Skip if already activated
    if ($user['is_activated']) return;
    // Activate the user
    $stmt = $pdo->prepare("UPDATE users SET is_activated = 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);
    // Grant welcome gift to the new user
    global $bot_settings;
    $welcome_gift = $bot_settings['WELCOME_GIFT'];
    _update_user_coins_and_history($pdo, $user_id, $welcome_gift, get_message('reason_welcome_gift', $lang));
    // Notify the new user about activation and gift
    $activation_msg = str_replace('{gift}', format_coins($welcome_gift), get_message('activation_success', $lang));
    send_message($user_id, $activation_msg);
    // If there is a referrer, grant referral reward
    if (!empty($user['referrer_id'])) {
        $referrer_id = $user['referrer_id'];
        $referrer = get_or_create_user($referrer_id);
        $referrer_lang = $referrer['language'] ?? 'fa';
        $referral_reward = $bot_settings['REFERRAL_REWARD'];
        if ($referrer['is_vip']) {
            $referral_reward *= 2; // Double reward for VIP referrers
        }
        // Update referrer's coins and history
        _update_user_coins_and_history($pdo, $referrer_id, $referral_reward, get_message('reason_referral', $referrer_lang));
        // Update referrer's referral stats
        $stmt = $pdo->prepare("UPDATE users SET referrals = referrals + 1, referral_coins = referral_coins + ? WHERE user_id = ?");
        $stmt->execute([$referral_reward, $referrer_id]);
        // Notify the referrer
        $notification_msg = str_replace('{reward}', format_coins($referral_reward), get_message('referral_notification', $referrer_lang));
        send_message($referrer_id, $notification_msg);
    }
}
// --- Other function handlers ---
if (!function_exists('handle_help_command')) {
    function handle_help_command($user, $chat_id) {
        $help_text = get_message('help_text', $user['language']);
        send_message($chat_id, $help_text);
    }
}
if (!function_exists('handle_my_channels')) {
    function handle_my_channels($user, $chat_id, $message_id = null) {
        set_user_state($user['user_id'], STATE_DEFAULT);
        $pdo = get_pdo();
        $stmt = $pdo->prepare("SELECT c.channel_id, c.title, o.order_id as active_order_id FROM channels c LEFT JOIN orders o ON c.channel_id = o.channel_id AND o.is_active = 1 WHERE c.owner_user_id = ?");
        $stmt->execute([$user['user_id']]);
        $channels = $stmt->fetchAll();
        $inline_keyboard = [];
        foreach ($channels as $channel) {
            $icon = $channel['active_order_id'] ? '📊' : '📌';
            $inline_keyboard[] = [['text' => "$icon {$channel['title']}", 'callback_data' => "ch_select_{$channel['channel_id']}"], ['text' => '🗑️', 'callback_data' => "ch_delete_{$channel['channel_id']}"]];
        }
        $inline_keyboard[] = [['text' => get_message('add_new_channel_btn', $user['language']), 'callback_data' => 'ch_add_new']];
        $inline_keyboard[] = [['text' => get_message('back_to_main_menu', $user['language']), 'callback_data' => 'back_main_menu']];
        $reply_markup = json_encode(['inline_keyboard' => $inline_keyboard]);
        $msg = get_message('my_channels_menu', $user['language']);
        if ($message_id) {
            edit_message($chat_id, $message_id, $msg, ['reply_markup' => $reply_markup]);
        } else {
            send_message($chat_id, $msg, ['reply_markup' => $reply_markup]);
        }
    }
}
if (!function_exists('handle_help_add_bot')) {
    function handle_help_add_bot($user, $chat_id) {
        $photo_path = __DIR__ . '/Help.png';
        // تلاش برای ارسال عکس اگر وجود داشته باشد
        if (file_exists($photo_path)) {
            try {
                // ارسال فایل محلی با CURLFile (اگر محیط شما پشتیبانی کند)
                api_request('sendPhoto', ['chat_id' => $chat_id, 'photo' => new CURLFile($photo_path)]);
            } catch (Exception $e) {
                // اگر ارسال عکس با خطا روبرو شد، به‌جای آن متن را ارسال می‌کنیم
            }
        }
        // سپس متن راهنما را زیر عکس (یا به تنهایی) ارسال می‌کنیم
        send_message($chat_id, get_message('help_add_bot_text', $user['language']));
    }
}
if (!function_exists('handle_channel_input')) {
function handle_channel_input($user, $chat_id, $text) {
    // اگر کاربر در حین انتظار برای آیدی کانال دستور راهنما فرستاد،
    // راهنما (عکس + متن) را ارسال کن و وضعیت کاربر را تغییر نده.
    $trimmed = trim($text);
    if (stripos($trimmed, '/HelpAddBot') === 0 || stripos($trimmed, '/helpaddbot') === 0) {
        // تابع موجود که عکس + متن راهنما را ارسال می‌کند
        handle_help_add_bot($user, $chat_id);
        // بازگشت بدون تغییر وضعیت تا کاربر همچنان بتواند آیدی کانال را ارسال کند
        return;
    }
    $api_chat_id = extract_channel_id($text);
    if (!$api_chat_id) {
        send_message($chat_id, get_message('channel_not_found', $user['language']));
        return;
    }
        $bot_member = api_request('getChatMember', ['chat_id' => $api_chat_id, 'user_id' => get_bot_id()]);
        if (!($bot_member['ok'] ?? false) || !in_array($bot_member['result']['status'], ['administrator', 'creator'])) {
            $msg = get_message('bot_not_admin', $user['language']);
            // اضافه کردن دستور کمکی /HelpAddBot زیر پیام
            if (($user['language'] ?? '') === 'fa') {
                $msg .= "\n\n/HelpAddBot\nبرای راهنمای اضافه کردن ربات به کانال یا گروه روی این دستور بزنید.";
            } else {
                $msg .= "\n\n/HelpAddBot\nPress this command for instructions to add the bot to your channel or group.";
            }
            send_message($chat_id, $msg);
            return;
        }
        $user_member = api_request('getChatMember', ['chat_id' => $api_chat_id, 'user_id' => $user['user_id']]);
        if (!($user_member['ok'] ?? false) || !in_array($user_member['result']['status'], ['administrator', 'creator'])) {
            send_message($chat_id, get_message('user_not_admin', $user['language']));
            return;
        }
        $chat_info = api_request('getChat', ['chat_id' => $api_chat_id]);
        if (!($chat_info['ok'] ?? false)) {
            send_message($chat_id, get_message('channel_not_found', $user['language']));
            return;
        }
        $result = $chat_info['result'];
        $title = $result['title'] ?? null;
        if(empty($title)){
            send_message($chat_id, "❌ ربات نتوانست نام کانال/گروه را دریافت کند. لطفاً مطمئن شوید کانال شما یک نام معتبر دارد.");
            return;
        }
        $invite_link = $result['invite_link'] ?? ($result['username'] ? "https://t.me/{$result['username']}" : null);
        $db_channel_id = $result['id'];
        $is_group = $result['type'] === 'group' || $result['type'] === 'supergroup';
    
        if (!$invite_link) {
            if ($is_group) {
                 $invite_link_res = api_request('exportChatInviteLink', ['chat_id' => $db_channel_id]);
                 if ($invite_link_res['ok']) {
                     $invite_link = $invite_link_res['result'];
                 }
            }
            if (!$invite_link) {
                 send_message($chat_id, "❌ ربات نتوانست لینک دعوتی برای این گروه ایجاد کند. لطفاً مطمئن شوید ربات دسترسی 'دعوت کاربران از طریق لینک' را دارد.", ['reply_markup' => get_cancel_keyboard($user['language'])]);
                 return;
            }
        }
        $pdo = get_pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT owner_user_id FROM channels WHERE channel_id = ?");
            $stmt->execute([$db_channel_id]);
            $owner = $stmt->fetchColumn();
            if ($owner && $owner != $user['user_id']) {
                send_message($chat_id, get_message('channel_already_registered', $user['language']));
                $pdo->rollBack();
                return;
            }
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO channels (channel_id, owner_user_id, title, invite_link, is_group) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$db_channel_id, $user['user_id'], $title, $invite_link, $is_group]);
            $pdo->commit();
            $msg_raw = get_message('channel_added', $user['language']);
            $msg_with_values = str_replace('{title}', $title, $msg_raw);
            send_message($chat_id, $msg_with_values);
            handle_my_channels($user, $chat_id);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Add channel error: " . $e->getMessage());
            send_message($chat_id, get_message('error_generic', $user['language']));
        }
    }
}
if (!function_exists('handle_member_count')) {
    function handle_member_count($user, $chat_id, $text) {
        $user_data = $user['user_data'];
        $channel_id = $user_data['selected_channel'] ?? null;
        if (!$channel_id) {
            send_message($chat_id, get_message('error_generic', $user['language']));
            handle_start($user, $chat_id);
            return;
        }
        if (!is_numeric($text) || (int)$text <= 0) {
            send_message($chat_id, get_message('invalid_positive_number', $user['language']));
            return;
        }
        $member_count = (int)$text;
    
        $user_data['member_count'] = $member_count;
        set_user_state($user['user_id'], STATE_AWAITING_BONUS_COINS, $user_data);
    
        $cost_per_member = $GLOBALS['bot_settings']['ORDER_COST_PER_MEMBER'];
        if ($user['is_vip']) $cost_per_member *= 0.95;
        $max_bonus = format_coins($member_count * $cost_per_member);
        $msg = str_replace('{max_bonus}', $max_bonus, get_message('ask_bonus_coins', $user['language']));
        send_message($chat_id, $msg);
    }
}
if (!function_exists('handle_bonus_coins_input')) {
    function handle_bonus_coins_input($user, $chat_id, $text) {
        global $bot_settings;
        $user_data = $user['user_data'];
        $channel_id = $user_data['selected_channel'] ?? null;
        $member_count = $user_data['member_count'] ?? null;
        if (!$channel_id || !$member_count) {
            send_message($chat_id, get_message('error_generic', $user['language']));
            handle_start($user, $chat_id);
            return;
        }
        if (!is_numeric($text) || (int)$text < 0) {
            send_message($chat_id, get_message('invalid_positive_number', $user['language']));
            return;
        }
        $bonus_coins_formatted = (float)$text;
        $bonus_coins = $bonus_coins_formatted * $bot_settings['COIN_MULTIPLIER'];
        $cost_per_member = $bot_settings['ORDER_COST_PER_MEMBER'];
        if ($user['is_vip']) $cost_per_member *= 0.95;
        $base_cost = $member_count * $cost_per_member;
        if ($bonus_coins > $base_cost) {
            $msg = str_replace('{max_bonus}', format_coins($base_cost), get_message('bonus_too_high', $user['language']));
            send_message($chat_id, $msg);
            return;
        }
        $total_cost = $base_cost + $bonus_coins;
        if ($user['coins'] < $total_cost) {
            $msg_raw = get_message('not_enough_coins', $user['language']);
            $msg_with_values = str_replace(['{coins}', '{cost}'], [format_coins($user['coins']), format_coins($total_cost)], $msg_raw);
            send_message($chat_id, $msg_with_values);
            handle_start($user, $chat_id);
            return;
        }
        if (create_order($user['user_id'], $channel_id, $member_count, $bonus_coins)) {
            send_message($chat_id, get_message('order_created', $user['language']));
        } else {
            send_message($chat_id, get_message('error_generic', $user['language']));
        }
        set_user_state($user['user_id'], STATE_DEFAULT);
        handle_start(get_or_create_user($user['user_id']), $chat_id);
    }
}
if (!function_exists('handle_my_orders')) {
    function handle_my_orders($user, $chat_id, $message_id = null, $page = 1) {
        global $bot_settings;
        set_user_state($user['user_id'], STATE_DEFAULT);
        $pdo = get_pdo();
        $offset = ($page - 1) * $bot_settings['PAGINATION_LIMIT'];
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?");
        $stmt->execute([$user['user_id']]);
        $total_orders = $stmt->fetchColumn();
        $inline_keyboard = [];
        $text = get_message('my_orders_list_header', $user['language']) . "\n\n";
        if ($total_orders == 0) {
            $text .= get_message('no_orders_user', $user['language']);
        } else {
            $stmt = $pdo->prepare("SELECT o.*, c.title FROM orders o JOIN channels c ON o.channel_id = c.channel_id WHERE o.user_id = ? ORDER BY o.created_at DESC LIMIT ? OFFSET ?");
            $stmt->execute([$user['user_id'], $bot_settings['PAGINATION_LIMIT'], $offset]);
            $orders = $stmt->fetchAll();
            foreach ($orders as $order) {
                $progress = $order['required_users'] > 0 ? round(($order['current_count'] / $order['required_users']) * 100) : 100;
                $progress_bar = str_repeat('🟩', round($progress / 10)) . str_repeat('⬜️', 10 - round($progress / 10));
            
                $base_reward = get_user_join_reward($user);
                $bonus_reward = ($order['required_users'] > 0) ? floor($order['bonus_coins'] / $order['required_users']) : 0;
                $total_reward = $base_reward + $bonus_reward;
                $item_text_raw = get_message('order_list_item', $user['language']);
                $item_text_with_values = str_replace(
                    ['{order_id}', '{title}', '{progress_bar}', '{progress}', '{current}', '{required}', '{reward}', '{date}', '{status}'],
                    [
                        $order['order_id'], $order['title'], $progress_bar, $progress,
                        $order['current_count'], $order['required_users'], format_coins($total_reward),
                        date('Y-m-d', strtotime($order['created_at'])),
                        $order['is_active'] ? get_message('active', $user['language']) : get_message('completed', $user['language'])
                    ],
                    $item_text_raw
                );
                $text .= $item_text_with_values . "\n\n";
                $buttons = [];
                if ($order['is_active']) {
                    $buttons[] = ['text' => get_message('cancel_order_btn', $user['language']), 'callback_data' => "order_cancel_{$order['order_id']}"];
                    $renew_status = $order['auto_renew'] ? get_message('status_on', $user['language']) : get_message('status_off', $user['language']);
                    $buttons[] = ['text' => str_replace('{status}', $renew_status, get_message('toggle_auto_renew_btn', $user['language'])), 'callback_data' => "order_renew_{$order['order_id']}"];
                }
                if (!empty($buttons)) {
                    $inline_keyboard[] = $buttons;
                }
            }
            $total_pages = ceil($total_orders / $bot_settings['PAGINATION_LIMIT']);
            $pagination_buttons = [];
            if ($page > 1) {
                $pagination_buttons[] = ['text' => '⬅️ قبلی', 'callback_data' => "order_page_" . ($page - 1)];
            }
            if ($page < $total_pages) {
                $pagination_buttons[] = ['text' => 'بعدی ➡️', 'callback_data' => "order_page_" . ($page + 1)];
            }
            if (!empty($pagination_buttons)) {
                $inline_keyboard[] = $pagination_buttons;
            }
        }
    
        $stmt_active = $pdo->prepare("SELECT 1 FROM orders WHERE user_id = ? AND is_active = 1 LIMIT 1");
        $stmt_active->execute([$user['user_id']]);
        if ($stmt_active->fetchColumn()) {
             $inline_keyboard[] = [['text' => get_message('boost_order_btn', $user['language']), 'callback_data' => 'boost_start']];
        }
        $inline_keyboard[] = [['text' => get_message('check_all_membership_btn', $user['language']), 'callback_data' => 'check_all_membership']];
        $inline_keyboard[] = [['text' => get_message('back_to_main_menu', $user['language']), 'callback_data' => 'back_main_menu']];
    
        $reply_markup = json_encode(['inline_keyboard' => $inline_keyboard]);
        if ($message_id) {
            edit_message($chat_id, $message_id, $text, ['reply_markup' => $reply_markup]);
        } else {
            send_message($chat_id, $text, ['reply_markup' => $reply_markup]);
        }
    }
}
if (!function_exists('handle_account_menu')) {
    function handle_account_menu($user, $chat_id, $message_id = null) {
        set_user_state($user['user_id'], STATE_DEFAULT);
        $lang = $user['language'];
        $inline_keyboard = [
            [['text' => get_message('profile_btn', $lang), 'callback_data' => 'account_profile']],
            [['text' => get_message('daily_gift_btn', $lang), 'callback_data' => 'account_daily_gift']],
            [['text' => get_message('levels_and_rewards_btn', $lang), 'callback_data' => 'account_levels']],
            [['text' => get_message('gift_coins_btn', $lang), 'callback_data' => 'account_gift']],
            [['text' => get_message('settings_btn', $lang), 'callback_data' => 'account_settings']],
            [['text' => get_message('support_btn', $lang), 'callback_data' => 'account_support']],
            [['text' => get_message('back_to_main_menu', $lang), 'callback_data' => 'back_main_menu']],
        ];
        $reply_markup = json_encode(['inline_keyboard' => $inline_keyboard]);
        $msg = get_message('account_menu', $lang);
        if ($message_id) {
            edit_message($chat_id, $message_id, $msg, ['reply_markup' => $reply_markup]);
        } else {
            send_message($chat_id, $msg, ['reply_markup' => $reply_markup]);
        }
    }
}
if (!function_exists('handle_profile')) {
    function handle_profile($user, $chat_id, $message_id) {
        $next_level_xp = get_xp_for_level($user['level'] + 1);
        $vip_badge = get_user_badge($user);
        $msg_raw = get_message('profile_text', $user['language']);
        $msg_with_values = str_replace(
            ['{vip_badge}', '{user_id}', '{level}', '{xp}', '{next_level_xp}', '{coins}', '{referrals}', '{ref_coins}', '{date}'],
            [
                $vip_badge, $user['user_id'], $user['level'], $user['xp'], $next_level_xp,
                format_coins($user['coins']), $user['referrals'],
                format_coins($user['referral_coins']), date('Y-m-d', strtotime($user['created_at']))
            ],
            $msg_raw
        );
        $inline_keyboard = [
            [['text' => get_message('coin_history_btn', $user['language']), 'callback_data' => 'account_history'], ['text' => get_message('leaderboard_btn', $user['language']), 'callback_data' => 'account_leaderboard']],
            [['text' => get_message('back_to_account_menu', $user['language']), 'callback_data' => 'account_main']]
        ];
        edit_message($chat_id, $message_id, $msg_with_values, ['reply_markup' => json_encode(['inline_keyboard' => $inline_keyboard])]);
    }
}
if (!function_exists('handle_settings_submenu')) {
    function handle_settings_submenu($user, $chat_id, $message_id) {
        set_user_state($user['user_id'], STATE_DEFAULT);
        $lang = $user['language'];
        $inline_keyboard = [
            [['text' => get_message('change_lang_btn', $lang), 'callback_data' => 'settings_lang']],
            [['text' => get_message('notifications_btn', $lang), 'callback_data' => 'settings_notif']],
            [['text' => get_message('back_to_account_menu', $lang), 'callback_data' => 'account_main']]
        ];
        $reply_markup = json_encode(['inline_keyboard' => $inline_keyboard]);
        $msg = get_message('settings_menu', $lang);
        edit_message($chat_id, $message_id, $msg, ['reply_markup' => $reply_markup]);
    }
}
if (!function_exists('handle_referrals')) {
    function handle_referrals($user, $chat_id) {
        global $bot_settings;
        set_user_state($user['user_id'], STATE_DEFAULT);
        $bot_username = get_bot_username();
        $link = "https://t.me/$bot_username?start={$user['user_id']}";
    
        $reward = $bot_settings['REFERRAL_REWARD'];
        if ($user['is_vip']) {
            $reward *= 2;
        }
        $msg_raw = get_message('referral_info', $user['language']);
        $msg_with_values = str_replace(
            ['{reward}', '{referrals}', '{coins}', '{link}'],
            [
                format_coins($reward),
                $user['referrals'],
                format_coins($user['referral_coins']),
                $link
            ],
            $msg_raw
        );
        send_message($chat_id, $msg_with_values);
    }
}
if (!function_exists('handle_ticket_command')) {
    function handle_ticket_command($user, $chat_id, $text) {
        $message_text = trim(substr($text, strlen('/ticket')));
        if (empty($message_text)) {
            set_user_state($user['user_id'], STATE_AWAITING_TICKET_TEXT);
            send_message($chat_id, get_message('ask_ticket_text', $user['language']), ['reply_markup' => json_encode(['keyboard' => get_cancel_keyboard($user['language']), 'resize_keyboard' => true])]);
        } else {
            send_ticket_to_admin($user, $chat_id, $message_text);
        }
    }
}
if (!function_exists('send_ticket_to_admin')) {
function send_ticket_to_admin($user, $chat_id, $message_or_text) {
    $pdo = get_pdo();
    $pdo->beginTransaction();
    try {
        $user_info = api_request('getChat', ['chat_id' => $user['user_id']]);
        $username = $user_info['result']['username'] ?? 'none';
        // آماده‌سازی متن و ضمائم (attachments)
        $attachments = [];
        $text = '';
        if (is_array($message_or_text)) {
            $m = $message_or_text;
            // عکس (photo) — بزرگترین کیفیت را می‌گیریم
            if (isset($m['photo']) && is_array($m['photo'])) {
                $photo = end($m['photo']);
                $attachments[] = ['type' => 'photo', 'file_id' => $photo['file_id']];
            }
            if (isset($m['document'])) {
                $attachments[] = ['type' => 'document', 'file_id' => $m['document']['file_id'], 'filename' => ($m['document']['file_name'] ?? '')];
            }
            if (isset($m['voice'])) {
                $attachments[] = ['type' => 'voice', 'file_id' => $m['voice']['file_id']];
            }
            if (isset($m['audio'])) {
                $attachments[] = ['type' => 'audio', 'file_id' => $m['audio']['file_id']];
            }
            if (isset($m['video'])) {
                $attachments[] = ['type' => 'video', 'file_id' => $m['video']['file_id']];
            }
            // متن/کپشن
            if (isset($m['caption'])) $text = $m['caption'];
            elseif (isset($m['text'])) $text = $m['text'];
        } else {
            $text = $message_or_text;
        }
        // ذخیره تیکت همراه با attachments (JSON)
        $stmt = $pdo->prepare("INSERT INTO tickets (user_id, username, text, attachments, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([$user['user_id'], $username, $text, json_encode($attachments)]);
        $ticket_id = $pdo->lastInsertId();
        // پیام اولیه به ادمین (شامل متن)
        $admin_msg_raw = get_message('ticket_received_admin', 'fa');
        $admin_msg_with_values = str_replace(['{user_id}', '{text}'], [$user['user_id'], $text ?: '(بدون متن)'], $admin_msg_raw);
        $res = send_message(ADMIN_ID, $admin_msg_with_values);
        // ارسال ضمائم به ادمین (هر کدام با کپشن حاوی ticket_id و user_id)
        if (!empty($attachments)) {
            foreach ($attachments as $att) {
                switch ($att['type']) {
                    case 'photo':
                        api_request('sendPhoto', ['chat_id' => ADMIN_ID, 'photo' => $att['file_id'], 'caption' => "تیکت #{$ticket_id} از کاربر {$user['user_id']}"]);
                        break;
                    case 'document':
                        api_request('sendDocument', ['chat_id' => ADMIN_ID, 'document' => $att['file_id'], 'caption' => "تیکت #{$ticket_id} از کاربر {$user['user_id']}"]);
                        break;
                    case 'voice':
                        api_request('sendVoice', ['chat_id' => ADMIN_ID, 'voice' => $att['file_id'], 'caption' => "تیکت #{$ticket_id} از کاربر {$user['user_id']}"]);
                        break;
                    case 'audio':
                        api_request('sendAudio', ['chat_id' => ADMIN_ID, 'audio' => $att['file_id'], 'caption' => "تیکت #{$ticket_id} از کاربر {$user['user_id']}"]);
                        break;
                    case 'video':
                        api_request('sendVideo', ['chat_id' => ADMIN_ID, 'video' => $att['file_id'], 'caption' => "تیکت #{$ticket_id} از کاربر {$user['user_id']}"]);
                        break;
                    default:
                        // ignore unknown
                        break;
                }
            }
        }
        // پیام تایید به کاربر
        $user_res = send_message($chat_id, get_message('ticket_sent', $user['language']));
        if ($res['ok'] && $user_res['ok']) {
            $admin_message_id = $res['result']['message_id'];
            $user_message_id = $user_res['result']['message_id'];
            $stmt = $pdo->prepare("UPDATE tickets SET admin_message_id = ?, user_message_id = ? WHERE ticket_id = ?");
            $stmt->execute([$admin_message_id, $user_message_id, $ticket_id]);
        }
        $pdo->commit();
        handle_start($user, $chat_id);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Ticket creation error: " . $e->getMessage());
        send_message($chat_id, get_message('error_generic', $user['language']));
    }
}
}
if (!function_exists('handle_coin_history')) {
    function handle_coin_history($user, $chat_id, $message_id) {
        global $bot_settings;
        set_user_state($user['user_id'], STATE_DEFAULT);
        $pdo = get_pdo();
        $stmt = $pdo->prepare("SELECT * FROM coin_history WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$user['user_id'], $bot_settings['HISTORY_LIMIT']]);
        $history = $stmt->fetchAll();
        $text = "";
        if (empty($history)) {
            $text = get_message('no_history', $user['language']);
        } else {
            foreach ($history as $item) {
                $amount_str = ($item['amount'] > 0 ? '+' : '') . format_coins($item['amount']);
                $reason = get_message($item['reason'], $user['language']);
                $date = date('Y-m-d H:i', strtotime($item['created_at']));
                $item_raw = get_message('history_item', $user['language']);
                $item_with_values = str_replace(['{date}', '{amount_str}', '{reason}'], [$date, $amount_str, $reason], $item_raw);
                $text .= $item_with_values . "\n";
            }
        }
        $header = get_message('coin_history_text', $user['language']);
        $final_text = str_replace('{history_list}', $text, $header);
        $inline_keyboard = [[['text' => get_message('back_to_account_menu', $user['language']), 'callback_data' => 'account_main']]];
        edit_message($chat_id, $message_id, $final_text, ['reply_markup' => json_encode(['inline_keyboard' => $inline_keyboard])]);
    }
}
if (!function_exists('handle_leaderboard')) {
    function handle_leaderboard($user, $chat_id, $message_id) {
        global $bot_settings;
        set_user_state($user['user_id'], STATE_DEFAULT);
        $pdo = get_pdo();
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        $total_users = $stmt->fetchColumn();
        if ($total_users < $bot_settings['LEADERBOARD_MIN_USERS']) {
            $inline_keyboard = [[['text' => get_message('back_to_account_menu', $user['language']), 'callback_data' => 'account_main']]];
            edit_message($chat_id, $message_id, get_message('leaderboard_unavailable', $user['language']), ['reply_markup' => json_encode(['inline_keyboard' => $inline_keyboard])]);
            return;
        }
        $stmt = $pdo->prepare("SELECT user_id, coins, level, is_vip, profile_badge FROM users ORDER BY coins DESC LIMIT ?");
        $stmt->execute([$bot_settings['LEADERBOARD_LIMIT']]);
        $top_users = $stmt->fetchAll();
        $text = "";
        $rank_icons = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];
        foreach ($top_users as $index => $top_user_data) {
            $badge = get_user_badge($top_user_data);
        
            $user_info = api_request('getChat', ['chat_id' => $top_user_data['user_id']]);
            $user_name = "کاربر {$top_user_data['user_id']}"; // Fallback
            if ($user_info['ok'] && !empty($user_info['result']['first_name'])) {
                $user_name = htmlspecialchars($user_info['result']['first_name']);
            }
            $user_mention = "{$user_name} {$badge}";
            $rank = $rank_icons[$index] ?? ($index + 1) . '.';
            $item_raw = get_message('leaderboard_item', $user['language']);
            $item_with_values = str_replace(['{rank}', '{user_mention}', '{coins}'], [$rank, $user_mention, format_coins($top_user_data['coins'])], $item_raw);
            $text .= $item_with_values . "\n";
        }
        $header = get_message('leaderboard_text', $user['language']);
        $final_text = str_replace('{leaderboard_list}', $text, $header);
        $inline_keyboard = [[['text' => get_message('back_to_account_menu', $user['language']), 'callback_data' => 'account_main']]];
        edit_message($chat_id, $message_id, $final_text, ['reply_markup' => json_encode(['inline_keyboard' => $inline_keyboard])]);
    }
}
if (!function_exists('handle_gift_coins_start')) {
    function handle_gift_coins_start($user, $chat_id, $message_id) {
        set_user_state($user['user_id'], STATE_AWAITING_GIFT_USER_ID);
        $msg = get_message('ask_gift_user_id', $user['language']);
        $reply_markup = json_encode(['keyboard' => get_cancel_keyboard($user['language']), 'resize_keyboard' => true]);
        send_message($chat_id, "لطفا از کیبورد پایین برای لغو عملیات استفاده کنید.", ['reply_markup' => $reply_markup]);
        $inline_keyboard = [[['text' => get_message('back_to_account_menu', $user['language']), 'callback_data' => 'account_main']]];
        edit_message($chat_id, $message_id, $msg, ['reply_markup' => json_encode(['inline_keyboard' => $inline_keyboard])]);
    }
}
function handle_gift_user_id_input($user, $chat_id, $text) {
        $text = trim($text);
        // فقط اجازه ID عددی بده
        if (!preg_match('/^\d+$/', $text)) {
            send_message($chat_id, get_message('invalid_user_id', $user['language']));
            return;
        }
        $target_user_id = (int)$text;
        // جلوگیری از ارسال به خود کاربر
        if ($target_user_id == $user['user_id']) {
            send_message($chat_id, get_message('cant_gift_self', $user['language']));
            return;
        }
        // بررسی وجود گیرنده در دیتابیس
        try {
            $pdo = get_pdo();
            $stmt = $pdo->prepare("SELECT 1 FROM users WHERE user_id = ? LIMIT 1");
            $stmt->execute([$target_user_id]);
            if (!$stmt->fetch()) {
                send_message($chat_id, get_message('invalid_user_id', $user['language']));
                return;
            }
        } catch (Exception $e) {
            send_message($chat_id, get_message('invalid_user_id', $user['language']));
            return;
        }
        // همه چیز اوکی — ادامه دریافت مقدار سکه
        set_user_state($user['user_id'], STATE_AWAITING_GIFT_AMOUNT, ['target_user_id' => $target_user_id]);
        $msg_raw = get_message('ask_gift_amount', $user['language']);
        $msg_with_values = str_replace(['{target_user_id}', '{coins}'], [$target_user_id, format_coins($user['coins'])], $msg_raw);
        send_message($chat_id, $msg_with_values, [
            'reply_markup' => json_encode([
                'keyboard' => get_cancel_keyboard($user['language']),
                'resize_keyboard' => true
            ])
        ]);
    }
if (!function_exists('handle_gift_amount_input')) {
    function handle_gift_amount_input($user, $chat_id, $text) {
        global $bot_settings;
        if (!is_numeric($text) || (float)$text <= 0) {
            send_message($chat_id, get_message('invalid_positive_number', $user['language']));
            return;
        }
        $amount_formatted = (float)$text;
        $amount = $amount_formatted * $bot_settings['COIN_MULTIPLIER'];
        if ($amount > $user['coins']) {
            send_message($chat_id, get_message('not_enough_coins', $user['language']));
            return;
        }
        $target_user_id = $user['user_data']['target_user_id'];
        $pdo = get_pdo();
        $pdo->beginTransaction();
        try {
            _update_user_coins_and_history($pdo, $user['user_id'], -$amount, 'reason_gift_sent');
            _update_user_coins_and_history($pdo, $target_user_id, $amount, 'reason_gift_received');
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            send_message($chat_id, get_message('error_generic', $user['language']));
            return;
        }
        $msg_raw = get_message('gift_sent', $user['language']);
        $msg_with_values = str_replace(['{amount}', '{target_user_id}'], [format_coins($amount), $target_user_id], $msg_raw);
        send_message($chat_id, $msg_with_values);
        $target_user = get_or_create_user($target_user_id);
        if ($target_user) {
            $notify_msg_raw = get_message('gift_received', $target_user['language']);
            $notify_msg_with_values = str_replace(['{amount}', '{sender_id}'], [format_coins($amount), $user['user_id']], $notify_msg_raw);
            send_message($target_user_id, $notify_msg_with_values, ['disable_notification' => true]);
        }
        handle_start($user, $chat_id);
    }
}
if (!function_exists('handle_notifications_menu')) {
    function handle_notifications_menu($user, $chat_id, $message_id) {
        set_user_state($user['user_id'], STATE_DEFAULT);
        $lang = $user['language'];
        $settings = $user['notifications'];
        $status_progress = ($settings['order_progress'] ?? true) ? get_message('status_on', $lang) : get_message('status_off', $lang);
        $status_broadcast = ($settings['broadcast'] ?? true) ? get_message('status_on', $lang) : get_message('status_off', $lang);
        $status_system = ($settings['system_warnings'] ?? true) ? get_message('status_on', $lang) : get_message('status_off', $lang);
        $inline_keyboard = [
            [['text' => str_replace('{status}', $status_progress, get_message('notif_order_progress_btn', $lang)), 'callback_data' => 'notif_toggle_order_progress']],
            [['text' => str_replace('{status}', $status_broadcast, get_message('notif_broadcast_btn', $lang)), 'callback_data' => 'notif_toggle_broadcast']],
            [['text' => str_replace('{status}', $status_system, get_message('notif_system_warnings_btn', $lang)), 'callback_data' => 'notif_toggle_system_warnings']],
            [['text' => get_message('back', $lang), 'callback_data' => 'account_settings']],
        ];
        edit_message($chat_id, $message_id, get_message('notification_settings_text', $lang), ['reply_markup' => json_encode(['inline_keyboard' => $inline_keyboard])]);
    }
}
if (!function_exists('handle_toggle_notification')) {
    function handle_toggle_notification($user, $chat_id, $message_id, $setting_key) {
        $settings = $user['notifications'];
        $settings[$setting_key] = !($settings[$setting_key] ?? true);
        $pdo = get_pdo();
        $stmt = $pdo->prepare("UPDATE users SET notifications = ? WHERE user_id = ?");
        $stmt->execute([json_encode($settings), $user['user_id']]);
        $updated_user = get_or_create_user($user['user_id']);
        handle_notifications_menu($updated_user, $chat_id, $message_id);
    }
}
if (!function_exists('handle_buy_coins')) {
    function handle_buy_coins($user, $chat_id) {
        set_user_state($user['user_id'], STATE_AWAITING_COINS_AMOUNT);
        $msg_raw = get_message('buy_coins_prompt', $user['language']);
        $msg_with_values = str_replace('{coins}', format_coins($user['coins']), $msg_raw);
        send_message($chat_id, $msg_with_values, ['reply_markup' => json_encode(['keyboard' => get_cancel_keyboard($user['language']), 'resize_keyboard' => true])]);
    }
}
if (!function_exists('handle_coins_amount_input')) {
    function handle_coins_amount_input($user, $chat_id, $text) {
        global $bot_settings;
        if (!is_numeric($text) || (float)$text <= 0) {
            send_message($chat_id, get_message('invalid_positive_number', $user['language']));
            return;
        }
        $coins_to_buy_formatted = (float)$text;
        $price = $coins_to_buy_formatted * $bot_settings['COIN_PRICE'];
        $coins_amount = $coins_to_buy_formatted * $bot_settings['COIN_MULTIPLIER'];
        $order_number = generate_order_number();
        $pdo = get_pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO purchases (user_id, coins_requested, price, order_number, created_at, type) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, 'coins')");
            $stmt->execute([$user['user_id'], $coins_amount, $price, $order_number]);
            $purchase_id = $pdo->lastInsertId();
            $pdo->commit();
            set_user_state($user['user_id'], STATE_AWAITING_RECEIPT, ['purchase_id' => $purchase_id, 'type' => 'coins']);
            $msg_raw = get_message('purchase_info', $user['language']);
            $msg_with_values = str_replace(
                ['{price}', '{card}', '{holder}', '{coins}', '{order_number}'],
                [
                    number_format($price),
                    $bot_settings['CARD_NUMBER'],
                    $bot_settings['CARD_HOLDER'],
                    $coins_to_buy_formatted,
                    $order_number
                ],
                $msg_raw
            );
            send_message($chat_id, $msg_with_values, ['reply_markup' => json_encode(['keyboard' => get_cancel_keyboard($user['language']), 'resize_keyboard' => true])]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Purchase creation error: " . $e->getMessage());
            send_message($chat_id, get_message('error_generic', $user['language']));
        }
    }
}
if (!function_exists('handle_receipt_photo')) {
    function handle_receipt_photo($user, $chat_id, $message) {
        $purchase_id = $user['user_data']['purchase_id'] ?? null;
        $type = $user['user_data']['type'] ?? 'coins';
        if (!$purchase_id) return;
        $file_id = end($message['photo'])['file_id'];
        $pdo = get_pdo();
        $stmt = $pdo->prepare("SELECT * FROM purchases WHERE purchase_id = ? AND user_id = ? AND status = 'pending'");
        $stmt->execute([$purchase_id, $user['user_id']]);
        $purchase = $stmt->fetch();
        if (!$purchase) return;
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE purchases SET photo_file_id = ? WHERE purchase_id = ?")->execute([$file_id, $purchase_id]);
            api_request('sendPhoto', ['chat_id' => ADMIN_ID, 'photo' => $file_id]);
            if ($type === 'boost') {
                $admin_msg = str_replace(['{user_id}', '{order_id}', '{price}', '{order_number}'], [$user['user_id'], $purchase['related_order_id'], number_format($purchase['price']), $purchase['order_number']], get_message('admin_boost_notify', 'fa'));
                $user_msg = get_message('boost_receipt_sent', $user['language']);
            } elseif ($type === 'vip') {
                $admin_msg = str_replace(['{user_id}', '{price}', '{order_number}'], [$user['user_id'], number_format($purchase['price']), $purchase['order_number']], get_message('admin_vip_notify', 'fa'));
                $user_msg = get_message('vip_receipt_sent', $user['language']);
            } else {
                $admin_msg = str_replace(['{user_id}', '{coins}', '{price}', '{order_number}'], [$user['user_id'], format_coins($purchase['coins_requested']), number_format($purchase['price']), $purchase['order_number']], get_message('admin_purchase_notify', 'fa'));
                $user_msg = get_message('receipt_sent', $user['language']);
            }
        
            $res = send_message(ADMIN_ID, $admin_msg);
            if ($res['ok']) {
                $pdo->prepare("UPDATE purchases SET admin_message_id = ? WHERE purchase_id = ?")->execute([$res['result']['message_id'], $purchase_id]);
            }
            $pdo->commit();
            send_message($chat_id, $user_msg);
            handle_start(get_or_create_user($user['user_id']), $chat_id);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Receipt photo error: " . $e->getMessage());
        }
    }
}
// Replace the entire handle_check_all_membership function with this updated version
if (!function_exists('handle_check_all_membership')) {
    function handle_check_all_membership($user, $chat_id, $message_id, $callback_query_id) {
        $pdo = get_pdo();
        global $bot_settings;
        $user_id = $user['user_id'];
        $lang = $user['language'] ?? 'fa';
        // محتوای پاسخ‌ها و لاگ
        $now_ts = time();
        // یک helper محلی برای اجرای امن کوئری‌های PDO با retry در صورت "database is locked"
        $safe_prepare_execute = function ($sql, $params = [], $max_retries = 10, $base_delay_ms = 200) use ($pdo) {
            $attempt = 0;
            while (true) {
                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    return $stmt;
                } catch (PDOException $e) {
                    $attempt++;
                    $msg = $e->getMessage();
                    if ($attempt >= $max_retries || stripos($msg, 'database is locked') === false) {
                        error_log("DB execute failed (no retry or max reached): {$msg} | SQL: {$sql}");
                        return false;
                    }
                    $sleep_ms = $base_delay_ms * $attempt;
                    error_log("DB locked, retry {$attempt}/{$max_retries} after {$sleep_ms}ms. SQL: {$sql}");
                    usleep($sleep_ms * 1000);
                }
            }
        };
        // helperهای سطح بالا برای Delete/Update خاص
        $safe_delete_order_member = function ($order_id, $member_id) use ($safe_prepare_execute) {
            $sql = "DELETE FROM order_members WHERE order_id = ? AND member_user_id = ?";
            $res = $safe_prepare_execute($sql, [$order_id, $member_id]);
            return $res !== false;
        };
        $safe_decrement_order_current_count = function ($order_id) use ($safe_prepare_execute) {
            $sql = "UPDATE orders SET current_count = GREATEST(current_count - 1, 0) WHERE order_id = ?";
            $res = $safe_prepare_execute($sql, [$order_id]);
            return $res !== false;
        };
        // === قفل اولیه: جلوگیری از اجرای همزمان و کول‌داون ۲ ساعته ===
        $cooldown_seconds = 7200;
        $lock_sql = "UPDATE users SET last_membership_check = CURRENT_TIMESTAMP WHERE user_id = ? AND (last_membership_check IS NULL OR last_membership_check < DATE_SUB(NOW(), INTERVAL ? SECOND))";
        $lock_stmt = $pdo->prepare($lock_sql);
        if (!$lock_stmt->execute([$user_id, $cooldown_seconds]) || $lock_stmt->rowCount() == 0) {
            try {
                $ts_stmt = $pdo->prepare("SELECT last_membership_check FROM users WHERE user_id = ?");
                $ts_stmt->execute([$user_id]);
                $last_check = $ts_stmt->fetchColumn();
            } catch (Exception $e) {
                $last_check = null;
            }
            if ($last_check) {
                $elapsed = time() - strtotime($last_check);
                $remaining = max(0, $cooldown_seconds - $elapsed);
                $hours = floor($remaining / 3600);
                $minutes = floor(($remaining % 3600) / 60);
                $seconds = $remaining % 60;
                $time_text = "";
                if ($hours > 0) $time_text .= "{$hours} ساعت ";
                if ($minutes > 0 || $hours > 0) $time_text .= "{$minutes} دقیقه ";
                $time_text .= "{$seconds} ثانیه";
                $remaining_text = "⏳ زمان بررسی بعدی هنوز نرسیده است.\n"
                                . "لطفاً کمی صبر کنید.\n\n"
                                . "⏰ زمان باقی‌مانده: {$time_text}";
            } else {
                $remaining_text = "⏳ بررسی عضویت در حال انجام است یا اخیراً انجام شده!\nلطفاً مدتی بعد دوباره تلاش کنید.";
            }
            api_request('answerCallbackQuery', [
                'callback_query_id' => $callback_query_id,
                'text' => $remaining_text,
                'show_alert' => true
            ]);
            return;
        }
        // گرفتن تمام سفارش‌ها (فعال و غیرفعال) + عنوان کانال برای پیام اخطار
        $stmt = $pdo->prepare("SELECT o.order_id, o.channel_id, o.required_users, o.current_count, o.bonus_coins, o.is_active, c.title AS channel_title FROM orders o JOIN channels c ON o.channel_id = c.channel_id WHERE o.user_id = ?");
        $stmt->execute([$user_id]);
        $orders = $stmt->fetchAll();
        if (empty($orders)) {
            edit_message($chat_id, $message_id, "📊 شما هیچ سفارشی (فعال یا غیرفعال) ندارید.");
            api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
            return;
        }
        // محاسبه تعداد کل اعضای ثبت‌شده
        $total_to_check = 0;
        $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM order_members WHERE order_id = ?");
        foreach ($orders as $order) {
            $cnt_stmt->execute([$order['order_id']]);
            $total_to_check += (int)$cnt_stmt->fetchColumn();
        }
        if ($total_to_check == 0) {
            edit_message($chat_id, $message_id, "هیچ عضوی برای بررسی وجود ندارد.");
            return;
        }
        // زمان تخمینی
        $estimated_seconds = ceil($total_to_check * 0.35);
        $estimated_minutes = max(1, ceil($estimated_seconds / 60));
        // --- پیام اول (اطلاعات و تخمین) ---
        $info_text = "شروع بررسی عضویت برای تمام سفارش‌های شما (فعال و غیرفعال)...\n\n"
                   . "تعداد کل اعضای ثبت‌شده: {$total_to_check}\n"
                   . "زمان تخمینی تکمیل: حدود {$estimated_minutes} دقیقه\n\n"
                   . "در حال بررسی... شروع شد.";
        $info_res = send_message($chat_id, $info_text);
        $info_msg_id = $info_res['result']['message_id'] ?? null;
        // --- پیام دوم (پیام درصد که مکرراً آپدیت می‌شود) ---
        $progress_text_init = "در حال بررسی... 0% (0 از {$total_to_check})";
        $progress_res = send_message($chat_id, $progress_text_init);
        $progress_msg_id = $progress_res['result']['message_id'] ?? null;
        // پاک کردن پیام قبلی کال‌بک اگر لازم بود
        if ($message_id && $message_id != $info_msg_id && $message_id != $progress_msg_id) {
            @api_request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);
        }
        // متغیرهای وضعیت و گزارش
        $checked = 0;
        $left_found = 0;
        $total_refund = 0;
        $check_errors = [];
        $db_lock_failures = 0;
        $cost_per_member = (float)($bot_settings['ORDER_COST_PER_MEMBER'] ?? 0);
        if (!empty($user['is_vip'])) $cost_per_member *= 0.95;
        $compensation = (int)$bot_settings['OWNER_COMPENSATION']; // جبران از تنظیمات
        $penalty = (int)($bot_settings['LEAVE_PENALTY'] ?? 0);
        // صف برای retry (ذخیره اطلاعات لازم برای اخطار کامل)
        $locked_queue = [];
        // پارامترهای بروز رسانی پیام پیشرفت
        $last_edit_time = time();
        $last_percent = -1;
        // تابع کمکی برای به‌روزرسانی پیام درصد
        $maybe_update_progress = function () use (&$checked, $total_to_check, $chat_id, &$progress_msg_id, &$last_edit_time, &$last_percent) {
            if (!$progress_msg_id) return;
            $percent = (int) floor(($checked / max(1, $total_to_check)) * 100);
            $now = time();
            if ($percent !== $last_percent && ($percent - $last_percent >= 1 || $now - $last_edit_time >= 2)) {
                $text = "در حال بررسی... {$percent}% ({$checked} از {$total_to_check})";
                @edit_message($chat_id, $progress_msg_id, $text);
                $last_edit_time = $now;
                $last_percent = $percent;
            }
        };
        // شروع حلقه اصلی بررسی سفارش‌ها و اعضا
        try {
            foreach ($orders as $order) {
                $order_id = $order['order_id'];
                $channel_id = $order['channel_id'];
                $channel_title = $order['channel_title'] ?? 'کانال';
                $bonus_per = ($order['bonus_coins'] > 0 && $order['required_users'] > 0) ? ($order['bonus_coins'] / $order['required_users']) : 0;
                $is_active = (bool)$order['is_active'];
                $members_stmt = $pdo->prepare("SELECT member_user_id FROM order_members WHERE order_id = ?");
                $members_stmt->execute([$order_id]);
                $members = $members_stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($members as $member_id) {
                    usleep(350000); // 350ms
                    try {
                        $res = api_request('getChatMember', [
                            'chat_id' => $channel_id,
                            'user_id' => $member_id
                        ]);
                    } catch (Exception $e) {
                        $check_errors[] = "خطای شبکه هنگام بررسی عضو {$member_id}";
                        $checked++;
                        $maybe_update_progress();
                        continue;
                    }
                    if (!is_array($res) || empty($res['ok'])) {
                        $check_errors[] = "خطای API برای عضو {$member_id}";
                        $checked++;
                        $maybe_update_progress();
                        continue;
                    }
                    $status = strtolower($res['result']['status'] ?? '');
                    $is_left = in_array($status, ['left', 'kicked', 'restricted', 'left']);
                    if ($is_left) {
                        $deleted = $safe_delete_order_member($order_id, $member_id);
                        if (!$deleted) {
                            $db_lock_failures++;
                            $locked_queue[] = [
                                'order_id' => $order_id,
                                'member_id' => $member_id,
                                'channel_title' => $channel_title,
                                'bonus_per' => $bonus_per,
                                'is_active' => $is_active
                            ];
                        } else {
                            $left_found++;
                            $refund_amount = $cost_per_member + $bonus_per + $compensation;
                            if ($refund_amount > 0) {
                                _update_user_coins_and_history($pdo, $user_id, $refund_amount, 'reason_refund_left');
                                $total_refund += $refund_amount;
                            }
                            if ($penalty > 0) {
                                _update_user_coins_and_history($pdo, $member_id, -$penalty, 'reason_leave_penalty');
                            }
                            // افزایش اخطار + پیام اخطار + مسدودسازی خودکار
                            $pdo->prepare("UPDATE users SET warnings = warnings + 1 WHERE user_id = ?")->execute([$member_id]);
                            $warnings = (int)$pdo->query("SELECT warnings FROM users WHERE user_id = {$member_id}")->fetchColumn();
                            $warning_msg = str_replace(
                                ['{title}', '{penalty}', '{warnings}', '{max_warnings}'],
                                [$channel_title, format_coins($penalty), $warnings, $MAX_WARNINGS],
                                get_message('warning_message', $lang)
                            );
                            @send_message($member_id, $warning_msg);
                            if ($warnings >= $MAX_WARNINGS) {
                                $ban_count = (int)$pdo->query("SELECT ban_count FROM users WHERE user_id = {$member_id}")->fetchColumn();
                                $duration = match($ban_count) {
                                    0 => 12*3600,
                                    1 => 24*3600,
                                    2 => 48*3600,
                                    default => 72*3600
                                };
                                $banned_until = date('Y-m-d H:i:s', time() + $duration);
                                $pdo->prepare("UPDATE users SET is_suspended = 1, banned_until = ?, ban_count = ? WHERE user_id = ?")
                                    ->execute([$banned_until, $ban_count + 1, $member_id]);
                                $suspend_msg = str_replace('{warnings}', $warnings, get_message('suspended_message', $lang));
                                @send_message($member_id, $suspend_msg);
                            }
                            if ($is_active) {
                                $safe_decrement_order_current_count($order_id);
                            }
                        }
                    }
                    $checked++;
                    $maybe_update_progress();
                }
            }
            // ── retry برای موارد لوک شده (در همین جلسه، حداکثر 5 بار) ──
            if (!empty($locked_queue)) {
                $check_errors[] = "بعضی اعضا به دلیل قفل دیتابیس در صف پردازش قرار گرفتند و چند دقیقه دیگر دوباره تلاش می‌شود.";
                $max_retry_loops = 5;
                $retry_loop_count = 0;
                while (!empty($locked_queue) && $retry_loop_count < $max_retry_loops) {
                    $retry_loop_count++;
                    sleep(60);
                    foreach ($locked_queue as $k => $item) {
                        $deleted = $safe_delete_order_member($item['order_id'], $item['member_id']);
                        if ($deleted) {
                            $left_found++;
                            $refund_amount = $cost_per_member + $item['bonus_per'] + $compensation;
                            if ($refund_amount > 0) {
                                _update_user_coins_and_history($pdo, $user_id, $refund_amount, 'reason_refund_left');
                                $total_refund += $refund_amount;
                            }
                            if ($penalty > 0) {
                                _update_user_coins_and_history($pdo, $item['member_id'], -$penalty, 'reason_leave_penalty');
                            }
                            $pdo->prepare("UPDATE users SET warnings = warnings + 1 WHERE user_id = ?")->execute([$item['member_id']]);
                            $warnings = (int)$pdo->query("SELECT warnings FROM users WHERE user_id = {$item['member_id']}")->fetchColumn();
                            $warning_msg = str_replace(
                                ['{title}', '{penalty}', '{warnings}', '{max_warnings}'],
                                [$item['channel_title'], format_coins($penalty), $warnings, $MAX_WARNINGS],
                                get_message('warning_message', $lang)
                            );
                            @send_message($item['member_id'], $warning_msg);
                            if ($warnings >= $MAX_WARNINGS) {
                                $ban_count = (int)$pdo->query("SELECT ban_count FROM users WHERE user_id = {$item['member_id']}")->fetchColumn();
                                $duration = match($ban_count) {
                                    0 => 12*3600,
                                    1 => 24*3600,
                                    2 => 48*3600,
                                    default => 72*3600
                                };
                                $banned_until = date('Y-m-d H:i:s', time() + $duration);
                                $pdo->prepare("UPDATE users SET is_suspended = 1, banned_until = ?, ban_count = ban_count + 1 WHERE user_id = ?")
                                    ->execute([$banned_until, $ban_count + 1, $item['member_id']]);
                            }
                            if ($item['is_active']) {
                                $safe_decrement_order_current_count($item['order_id']);
                            }
                            unset($locked_queue[$k]);
                        }
                    }
                    $maybe_update_progress();
                }
            }
            // ── پیام نهایی (همیشه نمایش داده می‌شود) ──
            $final_text = "✅ بررسی عضویت به پایان رسید.\n\n"
                        . "تعداد اعضای خارج شده: {$left_found}\n"
                        . "مبلغ جبران واریز شده: " . format_coins($total_refund) . " سکه";
            if (!empty($locked_queue)) {
                $final_text .= "\n\n⚠️ " . count($locked_queue) . " عضو به دلیل قفل دیتابیس پردازش نشدند و در بررسی بعدی (حداکثر ۲ ساعت دیگر) به صورت خودکار جبران می‌شوند.";
            }
            if (!empty($check_errors)) {
                $final_text .= "\n\nℹ️ برخی موارد جزئی با موفقیت پردازش شدند.";
            }
            // حذف پیام پیشرفت و نمایش نتیجه
            if ($progress_msg_id) {
                @api_request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $progress_msg_id]);
            }
            if ($info_msg_id) {
                edit_message($chat_id, $info_msg_id, $final_text);
            } else {
                send_message($chat_id, $final_text);
            }
            api_request('answerCallbackQuery', [
                'callback_query_id' => $callback_query_id,
                'text' => 'بررسی با موفقیت به پایان رسید.',
                'show_alert' => false
            ]);
            // پاک کردن کول‌داون اگر خیلی طولانی شد
            $pdo->exec("UPDATE users SET last_membership_check = NULL WHERE user_id = {$user_id} AND last_membership_check < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
        } catch (Exception $e) {
            error_log("Critical error in membership check: " . $e->getMessage());
            send_message($chat_id, "❌ خطای سیستمی رخ داد. بررسی متوقف شد.");
        }
    }
}
function handle_daily_gift($user, $chat_id, $message_id) {
    $now = new DateTime();
    $last_claim_time = isset($user['last_daily_gift_time']) ? new DateTime($user['last_daily_gift_time']) : null;
    if ($last_claim_time) {
        $next_claim_time = (clone $last_claim_time)->add(new DateInterval('PT24H'));
        if ($now < $next_claim_time) {
            $time_left = $now->diff($next_claim_time);
            $time_left_str = $time_left->format('%H ساعت و %I دقیقه');
            $msg = str_replace('{time_left}', $time_left_str, get_message('daily_gift_already_claimed', $user['language']));
            edit_message($chat_id, $message_id, $msg);
            return;
        }
    }
    // Weighted random prize logic
    $prizes = [
    100 => 3,
    200 => 3,
    300 => 2,
    400 => 2,
    500 => 1,
    600 => 1
];
    $rand_max = array_sum($prizes);
    $rand = mt_rand(1, $rand_max);
    $reward_coins = 0;
    foreach ($prizes as $amount => $weight) {
        if ($rand <= $weight) {
            $reward_coins = $amount;
            break;
        }
        $rand -= $weight;
    }
    $pdo = get_pdo();
    $pdo->beginTransaction();
    try {
        _update_user_coins_and_history($pdo, $user['user_id'], $reward_coins, 'reason_daily_gift');
        $pdo->prepare("UPDATE users SET last_daily_gift_time = CURRENT_TIMESTAMP WHERE user_id = ?")->execute([$user['user_id']]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Daily gift error for user {$user['user_id']}: " . $e->getMessage());
        edit_message($chat_id, $message_id, get_message('error_generic', $user['language']));
        return;
    }
    $updated_user = get_or_create_user($user['user_id']);
    $msg = str_replace(
        ['{amount}', '{new_balance}'],
        [format_coins($reward_coins), format_coins($updated_user['coins'])],
        get_message('daily_gift_claimed', $user['language'])
    );
    $keyboard = [[['text' => get_message('back_to_account_menu', $user['language']), 'callback_data' => 'account_main']]];
    edit_message($chat_id, $message_id, $msg, ['reply_markup' => json_encode(['inline_keyboard' => $keyboard])]);
}
function handle_boost_order_start($user, $chat_id) {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT o.order_id, c.title FROM orders o JOIN channels c ON o.channel_id = c.channel_id WHERE o.user_id = ? AND o.is_active = 1 AND o.is_boosted = 0");
    $stmt->execute([$user['user_id']]);
    $orders = $stmt->fetchAll();
    if (empty($orders)) {
        send_message($chat_id, get_message('no_active_orders_to_boost', $user['language']));
        return;
    }
    $inline_keyboard = [];
    foreach ($orders as $order) {
        $inline_keyboard[] = [['text' => "#{$order['order_id']} - {$order['title']}", 'callback_data' => "boost_select_{$order['order_id']}"]];
    }
    $inline_keyboard[] = [['text' => get_message('back', $user['language']), 'callback_data' => 'my_orders']];
    send_message($chat_id, get_message('ask_boost_order_id', $user['language']), ['reply_markup' => json_encode(['inline_keyboard' => $inline_keyboard])]);
}
function handle_boost_order_selection($user, $chat_id, $message_id, $order_id) {
    global $bot_settings;
    $price = $bot_settings['BOOST_PRICE'];
    $order_number = "B" . time();
    $pdo = get_pdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO purchases (user_id, price, order_number, type, related_order_id, created_at) VALUES (?, ?, ?, 'boost', ?, CURRENT_TIMESTAMP)");
        $stmt->execute([$user['user_id'], $price, $order_number, $order_id]);
        $purchase_id = $pdo->lastInsertId();
        $pdo->commit();
    
        set_user_state($user['user_id'], STATE_AWAITING_RECEIPT, ['purchase_id' => $purchase_id, 'type' => 'boost']);
    
        $msg = str_replace(['{order_id}', '{price}', '{card}', '{holder}', '{order_number}'], [$order_id, number_format($price), $bot_settings['CARD_NUMBER'], $bot_settings['CARD_HOLDER'], $order_number], get_message('boost_purchase_info', $user['language']));
    
        edit_message($chat_id, $message_id, $msg);
        send_message($chat_id, "برای لغو عملیات خرید، از دکمه زیر استفاده کنید.", ['reply_markup' => json_encode(['keyboard' => get_cancel_keyboard($user['language']), 'resize_keyboard' => true])]);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Boost purchase creation error: " . $e->getMessage());
        send_message($chat_id, get_message('error_generic', $user['language']));
    }
}
function handle_report_channel_start($user, $chat_id, $message_id, $channel_id, $order_id) {
    $pdo = get_pdo();
    $title = $pdo->query("SELECT title FROM channels WHERE channel_id = $channel_id")->fetchColumn();
    if ($title) {
        set_user_state($user['user_id'], STATE_AWAITING_REPORT_REASON, ['channel_id' => $channel_id, 'order_id' => $order_id]);
        $msg = str_replace('{title}', $title, get_message('ask_report_reason', $user['language']));
    
        edit_message($chat_id, $message_id, $msg, ['reply_markup' => json_encode(['inline_keyboard' => []])]);
        send_message($chat_id, "لطفا دلیل گزارش را بنویسید. برای لغو از کیبورد زیر استفاده کنید.", ['reply_markup' => json_encode(['keyboard' => get_cancel_keyboard($user['language']), 'resize_keyboard' => true])]);
    }
}
function handle_report_reason_input($user, $chat_id, $text) {
    $channel_id = $user['user_data']['channel_id'] ?? null;
    $order_id = $user['user_data']['order_id'] ?? null;
    if ($channel_id && !empty($text)) {
        $pdo = get_pdo();
        $stmt = $pdo->prepare("INSERT INTO channel_reports (channel_id, reporter_user_id, reason) VALUES (?, ?, ?)");
        $stmt->execute([$channel_id, $user['user_id'], $text]);
        send_message($chat_id, get_message('report_submitted', $user['language']));
        set_user_state($user['user_id'], STATE_DEFAULT);
        handle_collect_coins(get_or_create_user($user['user_id']), $chat_id, null, $order_id, true);
    } else {
        set_user_state($user['user_id'], STATE_DEFAULT);
        handle_start(get_or_create_user($user['user_id']), $chat_id);
    }
}
function handle_vip_menu($user, $chat_id, $message_id = null) {
    global $bot_settings;
    if ($user['is_vip']) {
        $expire_date = date('Y-m-d', strtotime($user['vip_expires_at']));
        $msg = str_replace('{date}', $expire_date, get_message('vip_already_active', $user['language']));
        $keyboard = [
            [['text' => get_message('vip_set_badge_btn', $user['language']), 'callback_data' => 'vip_badge_menu']],
            [['text' => get_message('back_to_main_menu', $user['language']), 'callback_data' => 'back_main_menu']]
        ];
    } else {
        $price = number_format($bot_settings['VIP_PRICE_TOMAN']);
        $msg = str_replace('{price}', $price, get_message('vip_menu_text', $user['language']));
        $keyboard = [
            [['text' => get_message('purchase_vip_btn', $user['language']), 'callback_data' => 'vip_purchase']],
            [['text' => get_message('back_to_main_menu', $user['language']), 'callback_data' => 'back_main_menu']]
        ];
    }
    if ($message_id) {
        edit_message($chat_id, $message_id, $msg, ['reply_markup' => json_encode(['inline_keyboard' => $keyboard])]);
    } else {
        send_message($chat_id, $msg, ['reply_markup' => json_encode(['inline_keyboard' => $keyboard])]);
    }
}
function handle_vip_purchase($user, $chat_id, $message_id) {
    global $bot_settings;
    $price = $bot_settings['VIP_PRICE_TOMAN'];
    $order_number = "V" . time();
    $pdo = get_pdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO purchases (user_id, price, order_number, type, created_at) VALUES (?, ?, ?, 'vip', CURRENT_TIMESTAMP)");
        $stmt->execute([$user['user_id'], $price, $order_number]);
        $purchase_id = $pdo->lastInsertId();
        $pdo->commit();
    
        set_user_state($user['user_id'], STATE_AWAITING_RECEIPT, ['purchase_id' => $purchase_id, 'type' => 'vip']);
    
        $msg = str_replace(['{price}', '{card}', '{holder}', '{order_number}'], [number_format($price), $bot_settings['CARD_NUMBER'], $bot_settings['CARD_HOLDER'], $order_number], get_message('vip_purchase_info', $user['language']));
    
        edit_message($chat_id, $message_id, $msg);
        send_message($chat_id, "برای لغو عملیات خرید، از دکمه زیر استفاده کنید.", ['reply_markup' => json_encode(['keyboard' => get_cancel_keyboard($user['language']), 'resize_keyboard' => true])]);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("VIP purchase creation error: " . $e->getMessage());
        send_message($chat_id, get_message('error_generic', $user['language']));
    }
}
function handle_badge_selection_menu($user, $chat_id, $message_id) {
    $badges = ['💎', '🚀', '👑', '🔥'];
    $inline_keyboard = [];
    $row = [];
    foreach ($badges as $badge) {
        $row[] = ['text' => $badge, 'callback_data' => 'badge_select_' . urlencode($badge)];
    }
    $inline_keyboard[] = $row;
    $inline_keyboard[] = [['text' => get_message('back', $user['language']), 'callback_data' => 'vip_menu']];
    $msg = get_message('vip_ask_badge', $user['language']);
    edit_message($chat_id, $message_id, $msg, ['reply_markup' => json_encode(['inline_keyboard' => $inline_keyboard])]);
}
// ========================================================================
// SECTION 8: XP & LEVELING SYSTEM
// ========================================================================
/**
 * Gets the total XP required to reach a certain level.
 */
function get_xp_for_level($level) {
    if ($level <= 1) return 0;
    return (int)(10 * pow($level, 2.5));
}
/**
 * Adds XP to a user's account and checks for level-ups.
 * Can accept a PDO object to run within an existing transaction.
 */
function add_xp($user_id, $xp_to_add, $pdo = null) {
    if ($xp_to_add <= 0) return;
    $use_external_pdo = ($pdo !== null);
    if (!$use_external_pdo) {
        $pdo = get_pdo();
    }
    $pdo->prepare("UPDATE users SET xp = xp + ? WHERE user_id = ?")->execute([$xp_to_add, $user_id]);
    $user = get_or_create_user($user_id);
    check_for_level_up($user, $pdo);
}
/**
 * Checks if a user has enough XP to level up and handles the rewards.
 * Can accept a PDO object to run within an existing transaction.
 */
function check_for_level_up($user, $pdo = null) {
    $use_external_pdo = ($pdo !== null);
    if (!$use_external_pdo) {
        $pdo = get_pdo();
    }
    $next_level_xp = get_xp_for_level($user['level'] + 1);
    if ($user['xp'] >= $next_level_xp) {
        $new_level = $user['level'] + 1;
        $pdo->prepare("UPDATE users SET level = ? WHERE user_id = ?")->execute([$new_level, $user['user_id']]);
    
        $reward_text = "";
        $reward_amount_formatted = 0;
        if ($new_level == 5) {
            $reward_amount_formatted = 10;
        } elseif ($new_level == 10) {
            $reward_amount_formatted = 20;
        } elseif ($new_level >= 20 && $new_level % 10 == 0) {
            $reward_amount_formatted = 10 + $new_level;
        }
        if ($reward_amount_formatted > 0) {
            $reward_coins = $reward_amount_formatted * $GLOBALS['bot_settings']['COIN_MULTIPLIER'];
            _update_user_coins_and_history($pdo, $user['user_id'], $reward_coins, 'reason_level_up');
            $reward_text .= "شما " . $reward_amount_formatted . " سکه جایزه گرفتید!\n";
        }
        $other_reward = get_level_reward_text($new_level, $user['language']);
        if ($other_reward) {
            $reward_text .= $other_reward;
        }
        if (!empty(trim($reward_text))) {
            $msg = str_replace(['{level}', '{reward_text}'], [$new_level, trim($reward_text)], get_message('level_up_message', $user['language']));
            send_message($user['user_id'], $msg);
        }
        check_for_level_up(get_or_create_user($user['user_id']), $pdo);
    }
}
// ========================================================================
// SECTION 9: VIP & REWARD HELPERS
// ========================================================================
/**
 * Gets the join reward for a user, considering VIP and level status.
 */
function get_user_join_reward($user) {
    $base_reward = $GLOBALS['bot_settings']['JOIN_REWARD'];
    if ($user['is_vip']) {
        $base_reward *= 1.30;
    }
    if ($user['level'] >= 50) $base_reward *= 1.05;
    elseif ($user['level'] >= 25) $base_reward *= 1.02;
    return (int)round($base_reward);
}
/**
 * Generates the user's badge string based on VIP, custom badge, and level.
 */
function get_user_badge($user) {
    $badge = '';
    if ($user['is_vip']) $badge .= '⭐';
    if (!empty($user['profile_badge'])) {
        $badge .= ' ' . $user['profile_badge'];
    } else {
        if ($user['level'] >= 50) $badge .= ' 🥇';
        elseif ($user['level'] >= 40) $badge .= ' 🥈';
        elseif ($user['level'] >= 20) $badge .= ' 🥉';
    }
    return trim($badge);
}
/**
 * Gets the non-coin reward text for a specific level.
 */
function get_level_reward_text($level, $lang) {
    $rewards = [
        6 => "قابلیت 'تمدید خودکار سفارش' برای شما فعال شد!",
        10 => "یک کوپن تخفیف ۵٪ برای خرید بعدی سکه دریافت کردید!",
        15 => "یک بوست ۲۴ ساعته افزایش ۱۰٪ پاداش عضویت برای شما فعال شد.",
        20 => "نشان افتخار برنزی (🥉) در پروفایل شما نمایش داده می‌شود.",
        25 => "پاداش عضویت شما برای همیشه ۲٪ افزایش یافت.",
        30 => "یک کوپن تخفیف ۱۰٪ برای خرید بعدی سکه دریافت کردید!",
        40 => "نشان افتخار نقره‌ای (🥈) در پروفایل شما نمایش داده می‌شود.",
        50 => "پاداش عضویت شما برای همیشه ۵٪ افزایش یافت و نشان طلایی (🥇) گرفتید."
    ];
    return $rewards[$level] ?? null;
}
/**
 * Displays the list of all levels and their rewards.
 */
function handle_levels_page($user, $chat_id, $message_id) {
    $levels_list = "";
    for ($i = 1; $i <= 50; $i++) {
        $reward_text = "";
        $reward_amount_formatted = 0;
        if ($i == 5) {
            $reward_amount_formatted = 10;
        } elseif ($i == 10) {
            $reward_amount_formatted = 20;
        } elseif ($i >= 20 && $i % 10 == 0) {
            $reward_amount_formatted = 10 + $i;
        }
    
        if ($reward_amount_formatted > 0) {
            $reward_text .= "جایزه: {$reward_amount_formatted} سکه";
        }
        $other_reward = get_level_reward_text($i, $user['language']);
        if ($other_reward) {
            if (!empty($reward_text)) $reward_text .= " + ";
            $reward_text .= $other_reward;
        }
        if (!empty($reward_text)) {
            $levels_list .= "سطح {$i}: {$reward_text}\n";
        }
    }
    $msg = str_replace('{levels_list}', $levels_list, get_message('levels_info_text', $user['language']));
    $keyboard = [[['text' => get_message('back_to_account_menu', $user['language']), 'callback_data' => 'account_main']]];
    edit_message($chat_id, $message_id, $msg, ['reply_markup' => json_encode(['inline_keyboard' => $keyboard])]);
}
// ========================================================================
// SECTION 10: MAIN EXECUTION BLOCK
// ========================================================================
function normalize_text($text) {
    return trim(preg_replace('/\s+/', ' ', $text));
}
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) {
    exit();
}
$user_id_for_log = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? 'unknown';
$chat_id = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
try {
if (isset($update['message'])) {
    $message = $update['message'];
    $user_id = $message['from']['id'];
    $chat_id = $message['chat']['id'];
    $text    = $message['text'] ?? '';

    // <<<=== فقط در چت خصوصی ادامه بده ===>>>
    if (($message['chat']['type'] ?? '') !== 'private') {
        exit(); // در گروه/کانال/سوپرگروه هیچ کاری نکن
    }
    
        if (isset($message['reply_to_message']) && $user_id == ADMIN_ID) {
            $pdo = get_pdo();
            $replied_message_id = $message['reply_to_message']['message_id'];
            $admin_reply_text = trim($text);
            $stmt_purchase = $pdo->prepare("SELECT * FROM purchases WHERE admin_message_id = ? AND status = 'pending'");
            $stmt_purchase->execute([$replied_message_id]);
            $purchase = $stmt_purchase->fetch();
            if ($purchase) {
                $target_user = get_or_create_user($purchase['user_id']);
                if (strtolower($admin_reply_text) === 'اره') {
                    $pdo->beginTransaction();
                    try {
                        $user_message = '';
                        if ($purchase['type'] === 'boost') {
                            $pdo->prepare("UPDATE orders SET is_boosted = 1 WHERE order_id = ?")->execute([$purchase['related_order_id']]);
                            $user_message = str_replace('{order_id}', $purchase['related_order_id'], get_message('purchase_approved_boost', $target_user['language']));
                        } elseif ($purchase['type'] === 'vip') {
                            $new_expire_date = date('Y-m-d H:i:s', strtotime('+30 days'));
                            $pdo->prepare("UPDATE users SET is_vip = 1, vip_expires_at = ? WHERE user_id = ?")->execute([$new_expire_date, $purchase['user_id']]);
                            _update_user_coins_and_history($pdo, $purchase['user_id'], $bot_settings['VIP_MONTHLY_COIN_GIFT'], 'reason_vip_gift');
                            $user_message = get_message('purchase_approved_vip', $target_user['language']);
                        } else { // 'coins'
                            _update_user_coins_and_history($pdo, $purchase['user_id'], $purchase['coins_requested'], 'reason_purchase');
                            $updated_target_user = get_or_create_user($purchase['user_id']);
                            $user_message = str_replace(
                                ['{coins}', '{new_balance}'],
                                [format_coins($purchase['coins_requested']), format_coins($updated_target_user['coins'])],
                                get_message('purchase_approved_coins', $target_user['language'])
                            );
                        }
                        $pdo->prepare("UPDATE purchases SET status = 'approved' WHERE purchase_id = ?")->execute([$purchase['purchase_id']]);
                        $pdo->commit();
                        send_message($purchase['user_id'], $user_message);
                        edit_message(ADMIN_ID, $replied_message_id, $message['reply_to_message']['text'] . "\n\n✅ تایید شد.");
                    } catch (Exception $e) { $pdo->rollBack(); error_log("Admin approval error: " . $e->getMessage()); }
                } elseif (strtolower($admin_reply_text) === 'نه') {
                    $pdo->prepare("UPDATE purchases SET status = 'rejected' WHERE purchase_id = ?")->execute([$purchase['purchase_id']]);
                    $msg = str_replace('{order_number}', $purchase['order_number'], get_message('purchase_rejected_generic', $target_user['language']));
                    send_message($purchase['user_id'], $msg);
                    edit_message(ADMIN_ID, $replied_message_id, $message['reply_to_message']['text'] . "\n\n❌ رد شد.");
                }
                exit();
            }
            $stmt_ticket = $pdo->prepare("SELECT * FROM tickets WHERE admin_message_id = ? AND status != 'closed'");
            $stmt_ticket->execute([$replied_message_id]);
            $ticket = $stmt_ticket->fetch();
            if ($ticket) {
                $pdo->prepare("UPDATE tickets SET status = 'answered' WHERE ticket_id = ?")->execute([$ticket['ticket_id']]);
                send_message($ticket['user_id'], get_message('admin_reply_prefix', 'fa') . $admin_reply_text);
                edit_message(ADMIN_ID, $replied_message_id, $message['reply_to_message']['text'] . "\n\n✅ پاسخ شما ارسال شد.");
                if ($ticket['user_message_id']) {
                    $new_text = get_message('ticket_status_answered', 'fa');
                    $new_text = str_replace('{admin_reply}', $admin_reply_text, $new_text);
                    edit_message($ticket['user_id'], $ticket['user_message_id'], $new_text);
                }
                exit();
            }
        }
// --- Admin commands: /many و /many_back (private to ADMIN_ID) ---
if (($message['chat']['type'] ?? '') === 'private' && $user_id == ADMIN_ID) {
    if ($incoming_text === '/many') {
        $pdo = get_pdo();
        $threshold = isset($bot_settings['MANY_THRESHOLD']) ? (float)$bot_settings['MANY_THRESHOLD'] : 50.0;
        try {
            // دریافت مجموعِ سکه‌هایی که در 24 ساعت گذشته با دلایل member_left دریافت شده‌اند
            $stmt = $pdo->prepare("
                SELECT user_id, SUM(amount) AS total
                FROM coin_history
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                  AND reason IN ('reason_member_left','reason_member_left_fallback')
                GROUP BY user_id
                HAVING total >= ?
                ORDER BY total DESC
            ");
            $stmt->execute([$threshold]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                send_message(ADMIN_ID, "هیچ کاربری در ۲۴ ساعت گذشته با الگوی مورد نظر سکه زیادی دریافت نکرده است (آستانه: {$threshold}).");
                return;
            }
            // ایجاد batch
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO many_batches (admin_id) VALUES (?)")->execute([ADMIN_ID]);
            $batch_id = $pdo->lastInsertId();
            $total_deducted = 0;
            $affected = 0;
            $getCoinsStmt = $pdo->prepare("SELECT coins FROM users WHERE user_id = ?");
            $updateUserStmt = $pdo->prepare("UPDATE users SET coins = coins - ? WHERE user_id = ?");
            $insertManyRow = $pdo->prepare("INSERT INTO many_rows (batch_id, user_id, amount) VALUES (?,?,?)");
            $insertHistory = $pdo->prepare("INSERT INTO coin_history (user_id, amount, reason, created_at) VALUES (?,?,?, CURRENT_TIMESTAMP)");
            foreach ($rows as $r) {
                $uid = (int)$r['user_id'];
                $claimed = (float)$r['total'];
                $getCoinsStmt->execute([$uid]);
                $current = (float)$getCoinsStmt->fetchColumn();
                $deduct = min($claimed, max(0.0, $current)); // هرگز بیشتر از موجودی کسر نکنیم
                if ($deduct <= 0) continue;
                $updateUserStmt->execute([$deduct, $uid]);
                $insertManyRow->execute([$batch_id, $uid, $deduct]);
                $insertHistory->execute([$uid, -$deduct, 'reason_many_deduction']);
                $total_deducted += $deduct;
                $affected++;
            }
            $pdo->commit();
            send_message(ADMIN_ID, "عملیات /many انجام شد.\nتعداد کاربرانی که سکه از آن‌ها کسر شد: {$affected}\nمجموع سکه کسر شده: " . format_coins($total_deducted) . "\nبرای بازگرداندن این batch از دستور /many_back استفاده کنید.");
        } catch (Exception $e) {
            if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
            error_log("Admin /many error: " . $e->getMessage());
            send_message(ADMIN_ID, "خطا در اجرای /many: " . $e->getMessage());
        }
        return;
    }
    if ($incoming_text === '/many_back') {
        $pdo = get_pdo();
        try {
            // آخرین batch که هنوز بازگردانده نشده است را پیدا کن
            $stmt = $pdo->prepare("SELECT batch_id FROM many_batches WHERE admin_id = ? AND restored_at IS NULL ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([ADMIN_ID]);
            $batch_id = $stmt->fetchColumn();
            if (!$batch_id) {
                send_message(ADMIN_ID, "هیچ batch معلقی برای بازگردانی یافت نشد.");
                return;
            }
            $pdo->beginTransaction();
            $getRows = $pdo->prepare("SELECT user_id, amount FROM many_rows WHERE batch_id = ?");
            $getRows->execute([$batch_id]);
            $rows = $getRows->fetchAll(PDO::FETCH_ASSOC);
            $total_restored = 0;
            $restored_count = 0;
            $updateUserStmt = $pdo->prepare("UPDATE users SET coins = coins + ? WHERE user_id = ?");
            $insertHistory = $pdo->prepare("INSERT INTO coin_history (user_id, amount, reason, created_at) VALUES (?,?,?, CURRENT_TIMESTAMP)");
            foreach ($rows as $r) {
                $uid = (int)$r['user_id'];
                $amt = (float)$r['amount'];
                if ($amt <= 0) continue;
                $updateUserStmt->execute([$amt, $uid]);
                $insertHistory->execute([$uid, $amt, 'reason_many_restore']);
                $total_restored += $amt;
                $restored_count++;
            }
            // علامت‌گذاری batch به‌عنوان بازگردانده شده
            $pdo->prepare("UPDATE many_batches SET restored_at = CURRENT_TIMESTAMP WHERE batch_id = ?")->execute([$batch_id]);
            $pdo->commit();
            send_message(ADMIN_ID, "بازگردانی انجام شد.\nتعداد کاربران: {$restored_count}\nمجموع سکه بازگردانده شده: " . format_coins($total_restored));
        } catch (Exception $e) {
            if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
            error_log("Admin /many_back error: " . $e->getMessage());
            send_message(ADMIN_ID, "خطا در اجرای /many_back: " . $e->getMessage());
        }
        return;
    }
}
        $user = get_or_create_user($user_id);
        if (!$user) exit("User creation/retrieval failed.");
        $state = $user['state'];
        $lang = $user['language'];
        if ($user['is_suspended']) {
    // اگر دلیل درخواست رفع مسدودیت فرستاده شد، به ادمین اطلاع بدید
        // --- New: show ban details when user presses "چرا مسدود شدم" ---
    if ($text == get_message('why_banned_btn', $lang)) {
        $warnings = (int)($user['warnings'] ?? 0);
        $ban_count = (int)($user['ban_count'] ?? 0);
        $banned_until = !empty($user['banned_until']) ? $user['banned_until'] : null;
        // compute remaining time text
        $remain_text = 'نامشخص';
        if (!empty($banned_until)) {
            $remain_ts = strtotime($banned_until) - time();
            if ($remain_ts <= 0) {
                $remain_text = "کمتر از ۱ دقیقه";
            } else {
                $parts = [];
                $days = floor($remain_ts / 86400);
                $hours = floor(($remain_ts % 86400) / 3600);
                $minutes = floor(($remain_ts % 3600) / 60);
                if ($days > 0) $parts[] = "{$days} روز";
                if ($hours > 0) $parts[] = "{$hours} ساعت";
                if ($minutes > 0) $parts[] = "{$minutes} دقیقه";
                if (empty($parts)) $parts[] = "کمتر از ۱ دقیقه";
                $remain_text = implode(' و ', $parts);
            }
        }
        // show the ban pattern (the same rules that bot uses)
        $pattern_text = "قوانین مسدودسازی (الگو):\n"
            . "- رسیدن به ۲۰ اخطار => ۱۲ ساعت\n"
            . "- رسیدن به ۳۵ اخطار => ۲۴ ساعت\n"
            . "- رسیدن به ۵۰ اخطار => ۴۸ ساعت\n"
            . "- رسیدن به ۶۵ اخطار => ۷۲ ساعت\n"
            . "- از ۷۵ به بعد: هر +۱۰ اخطار => ۷۲ ساعت";
        $msg = "🔍 اطلاعات مسدودی\n\n"
            . "• تعداد اخطارها: {$warnings}\n"
            . "• دفعات مسدودی قبلی: {$ban_count}\n"
            . "• زمان باقی‌مانده تا رفع مسدودی: {$remain_text}\n\n"
            . $pattern_text . "\n\n"
            . "اگر فکر می‌کنید مسدودی اشتباه است یا اطلاعات بیشتری لازم است، از دکمهٔ تماس با پشتیبانی استفاده کنید.";
        send_message($chat_id, $msg);
        exit();
    }
    // --- end new block ---
    if ($text == get_message('request_unban_btn', $lang)) {
        send_message(ADMIN_ID, "کاربر {$user_id} درخواست رفع مسدودیت دارد.");
        send_message($chat_id, get_message('unban_request_sent', $lang));
        exit();
    }
    // بررسی زمان پایان مسدودیت (banned_until)
    $now_ts = time();
    $banned_until_ts = 0;
    if (!empty($user['banned_until'])) {
        $banned_until_ts = strtotime($user['banned_until']);
    }
    if ($banned_until_ts > $now_ts) {
        $remaining = $banned_until_ts - $now_ts;
        $days = floor($remaining / 86400);
        $hours = floor(($remaining % 86400) / 3600);
        $minutes = floor(($remaining % 3600) / 60);
        $parts = [];
        if ($days > 0) $parts[] = "{$days} روز";
        if ($hours > 0) $parts[] = "{$hours} ساعت";
        if ($minutes > 0) $parts[] = "{$minutes} دقیقه";
        if (empty($parts)) $parts[] = "کمتر از ۱ دقیقه";
        $remain_text = implode(' و ', $parts);
        send_message($chat_id, "🔒 حساب شما در حال حاضر مسدود است.\nمدت باقی‌مانده: {$remain_text}.\n\nاگر مایلید درخواست بازبینی بفرستید: " . get_message('request_unban_btn', $lang));
        exit();
    } else {
try {
    $pdo = get_pdo();
    // رفع مسدودی و بازگشت state به 'default' تا منوی اصلی نمایش داده شود
    $pdo->prepare("UPDATE users SET is_suspended = 0, banned_until = NULL, state = 'default' WHERE user_id = ?")
        ->execute([$user['user_id']]);
    // به‌روزرسانی متغیر محلی $user تا ادامهٔ پردازش فعلی منطبق باشد
    $user['is_suspended'] = 0;
    $user['banned_until'] = null;
    $user['state'] = 'default';
    // پیام اطلاع‌رسانی به کاربر و ارسال کیبورد منوی اصلی
    $unmsg = get_message('auto_unbanned', $user['language']);
    send_message($user['user_id'], $unmsg, [
        'reply_markup' => json_encode([
            'keyboard' => get_main_keyboard($user),
            'resize_keyboard' => true
        ])
    ]);
} catch (Exception $e) {
    error_log("Auto-unban error for {$user['user_id']}: " . $e->getMessage());
}
        // ادامهٔ پردازش معمول (نیازی به exit() چون رفع شد)
    }
}
// -------------------------
// Admin "/info(...)" handler
// -------------------------
if ($user['user_id'] == ADMIN_ID && isset($text) && is_string($text)) {
    $trim = trim($text);
    // کمک: آموزش اضافه کردن کانال/سفارش
    if (strtolower($trim) === '/info+' || strtolower($trim) === 'info+') {
        $help = "راهنمای سریع افزودن کانال/گروه/سفارش:\n\n";
        $help .= "/info+add(channel)[<channel_id>][<admin_user_id>]\nمثال:\n/info+add(channel)[-1000189191][818292991]\n\n";
        $help .= "برای سفارش:\n/info+add(order)[<order_id>][<channel_id>]\n\n";
        $help .= "پس از اضافه کردن می‌توانید با /info(<id>) مدیریت کنید.";
        send_message($chat_id, $help);
        exit();
    }
    // الگوی اصلی: /info(12345) یا /info(12345) ...
            if (preg_match('/^\/?info\((\-?\d+)\)(?:\{([^}]+)\})?\s*(.*)$/is', $trim, $m)) {
                // $m[1] => target id
                // $m[2] => optional field to change (مثل level, coins, title, ...)
                // $m[3] => optional value (مثل +10 یا newvalue)
                $target_id = $m[1];
                $req_field = isset($m[2]) ? trim($m[2]) : '';
                $req_value = isset($m[3]) ? trim($m[3]) : '';
                $pdo = get_pdo();
                // helper: ارسال عکس پروفایل با استفاده از API تلگرام
                $send_photo = function($chat_id, $file_id, $caption = '') use ($bot_token) {
                    if (empty($file_id) || empty($bot_token)) return false;
                    $url = "https://api.telegram.org/bot{$bot_token}/sendPhoto?chat_id=" . urlencode($chat_id) .
                           "&photo=" . urlencode($file_id) .
                           "&caption=" . urlencode($caption) .
                           "&parse_mode=HTML";
                    @file_get_contents($url);
                    return true;
                };
                // اطمینان از ستون‌ها (اگر تو پروژه شما توابع دارند، همین‌ها را فراخوانی می‌کنیم)
                _ensure_user_columns($pdo);
                _ensure_channel_columns($pdo);
                _ensure_order_columns($pdo);
                // دانلود اطلاعات تلگرام برای یک شناسه (user/chat)
                $fetch_telegram_info = function($id) use ($bot_token) {
                    if (empty($bot_token)) return null;
                    $ref = $id;
                    if (is_string($ref) && preg_match('/^[A-Za-z0-9_]+$/', $ref)) $ref = "@{$ref}";
                    $getChatUrl = "https://api.telegram.org/bot{$bot_token}/getChat?chat_id=" . urlencode($ref);
                    $raw = @file_get_contents($getChatUrl);
                    if ($raw === false) return null;
                    $json = json_decode($raw, true);
                    if (empty($json['ok']) || empty($json['result'])) return null;
                    return $json['result'];
                };
                // helper: دستورالعمل ارسال هدر اطلاعات (برای کاربر/کانال/سفارش)
                $send_info_header = function($chat_id, $title, $assoc_array, $tg_info = null) use ($send_photo) {
                    $lines = [];
                    // اضافه کردن اطلاعات تلگرام اول (نام، یوزرنیم و ...)
                    if (is_array($tg_info)) {
                        if (!empty($tg_info['first_name']) || !empty($tg_info['last_name'])) {
                            $name = trim(($tg_info['first_name'] ?? '') . ' ' . ($tg_info['last_name'] ?? ''));
                            $lines[] = "▸ name: " . htmlspecialchars($name);
                        }
                        if (!empty($tg_info['username'])) {
                            $lines[] = "▸ username: @" . htmlspecialchars($tg_info['username']);
                        }
                        if (isset($tg_info['is_bot'])) {
                            $lines[] = "▸ is_bot: " . ($tg_info['is_bot'] ? 'yes' : 'no');
                        }
                    }
                    // سپس فیلدهای دیتابیس
                    foreach ($assoc_array as $col => $val) {
                        if ($val === null || $val === '') $val = '—';
                        // نشان دادن مقادیر پیچیده (مثل JSON) به صورت امن
                        if (is_string($val) && (strpos($val, '{') === 0 || strpos($val, '[') === 0)) {
                            $val_disp = htmlspecialchars($val);
                        } else {
                            $val_disp = htmlspecialchars((string)$val);
                        }
                        $lines[] = "▸ {$col}: {$val_disp}";
                    }
                    $info_text = "<b>{$title}</b>\n" . implode("\n", $lines);
                    // اگر اطلاعات تلگرام شامل عکس بود، تلاش کن عکس رو بفرستی
                    if (is_array($tg_info)) {
                        // کاربر: getUserProfilePhotos نداریم مستقیم؛ ولی getChat ممکنه photo داشته باشه
                        if (!empty($tg_info['photo'])) {
                            // photo ممکنه شامل small_file_id / big_file_id باشد
                            $file_id = $tg_info['photo']['big_file_id'] ?? $tg_info['photo']['small_file_id'] ?? null;
                            if ($file_id) {
                                $send_photo($chat_id, $file_id, $info_text);
                                return;
                            }
                        }
                    }
                    // در غیر این صورت فقط متن را ارسال کن
                    send_message($chat_id, $info_text);
                };
                // ---------------------------------------------------------
                // 1) بررسی اینکه آیا این آی‌دی متعلق به یک کاربر هست یا کانال یا سفارش
                // ---------------------------------------------------------
                // کاربران
                $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
                $stmt->execute([$target_id]);
                $target_user = $stmt->fetch(PDO::FETCH_ASSOC);
                // کانال/گروه
                $stmt = $pdo->prepare("SELECT * FROM channels WHERE channel_id = ?");
                $stmt->execute([$target_id]);
                $channel = $stmt->fetch(PDO::FETCH_ASSOC);
                // سفارش
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_id = ?");
                $stmt->execute([$target_id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                // Helper برای فرمت سکه (اگر تابع format_coins موجود نباشه از مقدار خام استفاده می‌کنه)
                $format_coins_safe = function($v) {
                    if (function_exists('format_coins')) return format_coins($v);
                    return (string)$v;
                };
                // =========================================================
                // A) اگر user یافت شد
                // =========================================================
                if ($target_user) {
                    // اگر نه فیلدی برای تغییر داده شده و نه مقدار => فقط نمایش اطلاعات کامل + عکس پروفایل + راهنما
                    if ($req_field === '') {
                        $tg_info = $fetch_telegram_info($target_id);
                        // اگر ستون coins وجود داشته باشه و نیاز به نمایش بصری داشتیم، convert کنیم اگر لازم (نمایش با format_coins)
                        if (isset($target_user['coins'])) {
                            $target_user['coins'] = $format_coins_safe($target_user['coins']);
                        }
                        $send_info_header($chat_id, "اطلاعات کاربر {$target_id}", $target_user, $tg_info);
                        // سپس راهنما
                        $msg = "مدیریت کاربر: {$target_id}\n\n";
                        $msg .= "/info({$target_id}){field} value\nمثال:\n";
                        $msg .= "/info({$target_id}){level} +10 — افزایش level به اندازه 10\n";
                        $msg .= "/info({$target_id}){coins} +5 — افزودن 5 سکه (مقدار انسانی)\n";
                        $msg .= "/info({$target_id}){coins} 10 — تنظیم موجودی برابر 10 (مقدار انسانی)\n";
                        $msg .= "/info({$target_id}){is_vip} 1 — فعال کردن VIP\n";
                        $msg .= "/info({$target_id}){bio} متن جدید — تغییر بیو/توضیحات\n";
                        $msg .= "/info({$target_id})unban — رفع مسدودی (قدیمی)\n";
                        $msg .= "/info({$target_id})ban — مسدودسازی (قدیمی)\n";
                        send_message($chat_id, $msg);
                        exit();
                    }
                    // اگر فیلد برای ویرایش ارسال شده: پردازش بروزرسانی
                    $field = $req_field;
                    $value = $req_value;
                    // shortcut: اگر کاربر خواسته دستوری قدیمی مثل unban/ban/remove/restore... اجرا شود،
                    // نگهداریم همان منطق قبلی
                    if (preg_match('/^unban$/i', $field) || (mb_strtolower($field) === 'unban' && $value === '')) {
                        $pdo->prepare("UPDATE users SET is_suspended = 0, banned_until = NULL WHERE user_id = ?")->execute([$target_id]);
                        send_message($chat_id, "✅ کاربر {$target_id} رفع مسدودیت شد.");
                        send_message($target_id, "✅ حساب شما از طرف پشتیبانی باز شد.");
                        exit();
                    }
                    if (preg_match('/^ban$/i', $field) || (mb_strtolower($field) === 'ban' && $value === '')) {
                        // همان منطق ban قبلی
                        $pdo->beginTransaction();
                        try {
                            $stmt = $pdo->prepare("SELECT COALESCE(ban_count,0) AS bc FROM users WHERE user_id = ?"); $stmt->execute([$target_id]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
                            $ban_count = (int)($row['bc'] ?? 0);
                            $new_count = $ban_count + 1;
                            // دوره‌های مسدودسازی مطابق خواسته:
switch ($ban_count) {
    case 0:
        $duration_seconds = 12 * 3600; // 12 ساعت
        break;
    case 1:
        $duration_seconds = 24 * 3600; // 24 ساعت
        break;
    case 2:
        $duration_seconds = 2 * 24 * 3600; // 48 ساعت (2 روز)
        break;
    default:
        $duration_seconds = 3 * 24 * 3600; // 72 ساعت (3 روز)
        break;
}
                            $banned_until = date('Y-m-d H:i:s', time() + $duration_seconds);
                            $pdo->prepare("UPDATE users SET is_suspended = 1, banned_until = ?, ban_count = ? WHERE user_id = ?")
                                ->execute([$banned_until, $new_count, $target_id]);
                            $pdo->commit();
                            send_message($chat_id, "✅ کاربر {$target_id} مسدود شد تا {$banned_until} (ban_count={$new_count}).");
                            send_message($target_id, "🔒 حساب شما توسط پشتیبانی مسدود شد تا {$banned_until}.");
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            error_log("Ban error for {$target_id}: ".$e->getMessage());
                            send_message($chat_id, "❌ خطا در مسدودسازی کاربر.");
                        }
                        exit();
                    }
                    // عمومی: اگر field == coins => از منطق سکه استفاده کن (پشتیبانی از +/-, absolute)
                    if (mb_strtolower($field) === 'coins') {
                        // استفاده از ضریب جهت ذخیره (دریافت مقدار انسانی و تبدیل به واحد داخلی)
                        global $bot_settings;
                        $coin_col = 'coins';
                        if ($value === '') {
                            send_message($chat_id, "❌ مقدار برای {$field} مشخص نشده.");
                            exit();
                        }
                        $pdo->beginTransaction();
                        try {
                            // خواندن مقدار فعلی
                            $stmt = $pdo->prepare("SELECT COALESCE({$coin_col},0) AS c FROM users WHERE user_id = ?");
                            $stmt->execute([$target_id]);
                            $row = $stmt->fetch(PDO::FETCH_ASSOC);
                            $current = isset($row['c']) ? (float)$row['c'] : 0.0;
                            if (strlen($value) > 0 && ($value[0] === '+' || $value[0] === '-')) {
                                $delta_human = (float)$value;
                                $delta = $delta_human * ($bot_settings['COIN_MULTIPLIER'] ?? 1);
                                $new = $current + $delta;
                                $pdo->prepare("UPDATE users SET {$coin_col} = ? WHERE user_id = ?")->execute([$new, $target_id]);
                                _log_coin_history($pdo, $target_id, $delta, $current, $new, 'admin_delta');
                                $pdo->commit();
                                send_message($chat_id, "✅ سکهٔ کاربر {$target_id} با مقدار " . $format_coins_safe($delta) . " (افزایش/کاهش) انجام شد. موجودی جدید: " . $format_coins_safe($new));
                                send_message($target_id, "💰 حساب شما به مقدار " . $format_coins_safe($delta) . " سکه توسط مدیریت تغییر کرد.\nموجودی جدید: " . $format_coins_safe($new) . " سکه");
                                exit();
                            } else {
                                // مقدار مطلق انسانی
                                $value_human = (float)$value;
                                $value_internal = $value_human * ($bot_settings['COIN_MULTIPLIER'] ?? 1);
                                $pdo->prepare("UPDATE users SET {$coin_col} = ? WHERE user_id = ?")->execute([$value_internal, $target_id]);
                                _log_coin_history($pdo, $target_id, $value_internal - $current, $current, $value_internal, 'admin_set');
                                $pdo->commit();
                                send_message($chat_id, "✅ موجودیِ کاربر {$target_id} برابر " . $format_coins_safe($value_internal) . " سکه تنظیم شد.");
                                send_message($target_id, "💰 موجودی شما توسط مدیریت برابر " . $format_coins_safe($value_internal) . " سکه تنظیم شد.");
                                exit();
                            }
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            error_log("Coin update error for {$target_id}: " . $e->getMessage());
                            send_message($chat_id, "❌ خطا در ویرایش موجودی کاربر. جزئیات در لاگ ثبت شد.");
                            exit();
                        }
                    }
                    // سایر فیلدها: اگر مقدار با + یا - آغاز شده و مقدار فعلی عددی است => افزایش/کاهش
                    if ($req_value !== '') {
                        // خواندن مقدار فعلی فیلد (اگر وجود داشته باشه)
                        $stmt = $pdo->prepare("SELECT {$field} FROM users WHERE user_id = ? LIMIT 1");
                        $ok = @$stmt->execute([$target_id]);
                        $row = $ok ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
                        if ($row !== false && array_key_exists($field, $row)) {
                            $current_val = $row[$field];
                            // اگر مقدار فعلی عددی و مقداری که کاربر فرستاد با + یا - شروع میشه => جمع/تفریق کن
                            if (strlen($req_value) > 0 && ($req_value[0] === '+' || $req_value[0] === '-') && is_numeric($current_val)) {
                                $delta = (float)$req_value;
                                $new = (float)$current_val + $delta;
                                $pdo->prepare("UPDATE users SET {$field} = ? WHERE user_id = ?")->execute([$new, $target_id]);
                                send_message($chat_id, "✅ فیلد {$field} برای کاربر {$target_id} به‌روزرسانی شد. مقدار جدید: {$new}");
                                exit();
                            } else {
                                // در غیر این صورت مقدار را به صورت رشته/عدد قرار می‌دهیم (کاستینگ ساده)
                                // اگر مقدار فعلی عددی و مقدار ارسالی عدد است، عددی کنیم
                                if (is_numeric($current_val) && is_numeric($req_value)) {
                                    $new = $req_value + 0;
                                } else {
                                    $new = $req_value;
                                }
                                $pdo->prepare("UPDATE users SET {$field} = ? WHERE user_id = ?")->execute([$new, $target_id]);
                                send_message($chat_id, "✅ فیلد {$field} برای کاربر {$target_id} تنظیم شد. مقدار جدید: " . htmlspecialchars((string)$new));
                                exit();
                            }
                        } else {
                            // فیلد وجود ندارد => تلاش برای اضافه کردن آن به جدول (در صورت امکان)
                            send_message($chat_id, "⚠️ فیلد '{$field}' در جدول users پیدا نشد.");
                            exit();
                        }
                    } else {
                        send_message($chat_id, "❌ شما فیلد یا مقدار برای ویرایش ارسال نکردید. قالب: /info({$target_id}){field} value");
                        exit();
                    }
                } // end if $target_user
                // =========================================================
                // B) اگر کانال/گروه پیدا شد
                // =========================================================
                if ($channel) {
                    if ($req_field === '') {
                        $tg_info = $fetch_telegram_info($target_id);
                        $send_info_header($chat_id, "اطلاعات کانال/گروه {$target_id}", $channel, $tg_info);
                        // راهنما
                        $msg = "مدیریت کانال/گروه: {$target_id}\n";
                        $msg .= "/info({$target_id}){title} [اسم جدید]\n";
                        $msg .= "/info({$target_id}){is_banned} 1/0\n";
                        $msg .= "/info({$target_id}){remove} — حذف (مثال قدیمی)\n";
                        send_message($chat_id, $msg);
                        exit();
                    }
                    // ویرایش فیلد در جدول channels
                    $field = $req_field;
                    $value = $req_value;
                    // اگر field متنی و بدون مقدار فرستاده شده (مثال remove) => همان عملیات قدیمی
                    if (mb_strtolower($field) === 'remove') {
                        $pdo->beginTransaction();
                        try {
                            $pdo->prepare("DELETE FROM orders WHERE channel_id = ?")->execute([$target_id]);
                            $pdo->prepare("DELETE FROM channels WHERE channel_id = ?")->execute([$target_id]);
                            $pdo->commit();
                            send_message($chat_id, "✅ کانال {$target_id} و سفارش‌های مرتبط حذف شدند.");
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            error_log("Channel remove error for {$target_id}: ".$e->getMessage());
                            send_message($chat_id, "❌ خطا در حذف کانال.");
                        }
                        exit();
                    }
                    // عمومی: اگر مقدار با + یا - باشه و فیلد عددی => افزایش/کاهش
                    $stmt = $pdo->prepare("SELECT {$field} FROM channels WHERE channel_id = ? LIMIT 1");
                    $ok = @$stmt->execute([$target_id]);
                    $row = $ok ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
                    if ($row !== false && array_key_exists($field, $row)) {
                        $current_val = $row[$field];
                        if (strlen($value) > 0 && ($value[0] === '+' || $value[0] === '-') && is_numeric($current_val)) {
                            $delta = (float)$value;
                            $new = (float)$current_val + $delta;
                            $pdo->prepare("UPDATE channels SET {$field} = ? WHERE channel_id = ?")->execute([$new, $target_id]);
                            send_message($chat_id, "✅ فیلد {$field} برای کانال {$target_id} به‌روزرسانی شد. مقدار جدید: {$new}");
                            exit();
                        } else {
                            $new = (is_numeric($current_val) && is_numeric($value)) ? ($value + 0) : $value;
                            $pdo->prepare("UPDATE channels SET {$field} = ? WHERE channel_id = ?")->execute([$new, $target_id]);
                            send_message($chat_id, "✅ فیلد {$field} برای کانال {$target_id} تنظیم شد. مقدار جدید: " . htmlspecialchars((string)$new));
                            exit();
                        }
                    } else {
                        send_message($chat_id, "⚠️ فیلد '{$field}' در جدول channels پیدا نشد.");
                        exit();
                    }
                } // end if $channel
                // =========================================================
                // C) اگر سفارش پیدا شد
                // =========================================================
                if ($order) {
                    if ($req_field === '') {
                        // نمایش همه ستون‌های سفارش با هدر
                        $send_info_header($chat_id, "اطلاعات سفارش {$target_id}", $order, null);
                        // راهنما
                        $msg = "مدیریت سفارش: {$target_id}\n";
                        $msg .= "/info({$target_id}){title} [نام جدید]\n";
                        $msg .= "/info({$target_id}){required_users} [تعداد]\n";
                        $msg .= "/info({$target_id})remove — حذف سفارش\n";
                        send_message($chat_id, $msg);
                        exit();
                    }
                    $field = $req_field;
                    $value = $req_value;
                    if (mb_strtolower($field) === 'remove') {
                        $pdo->prepare("DELETE FROM orders WHERE order_id = ?")->execute([$target_id]);
                        send_message($chat_id, "✅ سفارش {$target_id} حذف شد.");
                        exit();
                    }
                    $stmt = $pdo->prepare("SELECT {$field} FROM orders WHERE order_id = ? LIMIT 1");
                    $ok = @$stmt->execute([$target_id]);
                    $row = $ok ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
                    if ($row !== false && array_key_exists($field, $row)) {
                        $current_val = $row[$field];
                        if (strlen($value) > 0 && ($value[0] === '+' || $value[0] === '-') && is_numeric($current_val)) {
                            $delta = (float)$value;
                            $new = (float)$current_val + $delta;
                            $pdo->prepare("UPDATE orders SET {$field} = ? WHERE order_id = ?")->execute([$new, $target_id]);
                            send_message($chat_id, "✅ فیلد {$field} برای سفارش {$target_id} به‌روزرسانی شد. مقدار جدید: {$new}");
                            exit();
                        } else {
                            $new = (is_numeric($current_val) && is_numeric($value)) ? ($value + 0) : $value;
                            $pdo->prepare("UPDATE orders SET {$field} = ? WHERE order_id = ?")->execute([$new, $target_id]);
                            send_message($chat_id, "✅ فیلد {$field} برای سفارش {$target_id} تنظیم شد. مقدار جدید: " . htmlspecialchars((string)$new));
                            exit();
                        }
                    } else {
                        send_message($chat_id, "⚠️ فیلد '{$field}' در جدول orders پیدا نشد.");
                        exit();
                    }
                } // end if $order
                // اگر هیچکدوم نبود
                send_message($chat_id, "شناسهٔ {$target_id} در کاربران/کانال‌ها/سفارش‌ها پیدا نشد.");
                exit();
            }
}
        if (normalize_text($text) === normalize_text(get_message('cancel_operation', $lang))) {
            handle_start(get_or_create_user($user_id), $chat_id); exit();
        }
        if (isset($message['photo']) && $state === STATE_AWAITING_RECEIPT) {
            handle_receipt_photo($user, $chat_id, $message); exit();
        } elseif ($state === STATE_AWAITING_RECEIPT) {
            send_message($chat_id, get_message('must_be_photo', $lang)); exit();
        }
        switch ($state) {
            case STATE_AWAITING_CHANNEL_ID: handle_channel_input($user, $chat_id, $text); exit();
            case STATE_AWAITING_MEMBER_COUNT: handle_member_count($user, $chat_id, $text); exit();
            case STATE_AWAITING_BONUS_COINS: handle_bonus_coins_input($user, $chat_id, $text); exit();
            case STATE_AWAITING_COINS_AMOUNT: handle_coins_amount_input($user, $chat_id, $text); exit();
            case STATE_AWAITING_TICKET_TEXT: send_ticket_to_admin($user, $chat_id, $message); exit();
            case STATE_AWAITING_GIFT_USER_ID: handle_gift_user_id_input($user, $chat_id, $text); exit();
            case STATE_AWAITING_GIFT_AMOUNT: handle_gift_amount_input($user, $chat_id, $text); exit();
            case STATE_AWAITING_REPORT_REASON: handle_report_reason_input($user, $chat_id, $text); exit();
        }
    
        $clean_text = normalize_text($text);
    
        $button_handlers = [
            normalize_text(get_message('add_members', $lang)) => 'handle_my_channels',
            normalize_text(get_message('collect_coins', $lang)) => function($user, $chat_id) {
                handle_collect_coins($user, $chat_id, null, null, true);
            },
            normalize_text(get_message('my_orders_btn', $lang)) => 'handle_my_orders',
            normalize_text(get_message('buy_coins_btn', $lang)) => 'handle_buy_coins',
            normalize_text(get_message('account_btn', $lang)) => 'handle_account_menu',
            normalize_text(get_message('referrals_btn', $lang)) => 'handle_referrals',
            normalize_text(get_message('vip_account_btn', $lang)) => 'handle_vip_menu',
        ];
        if (isset($button_handlers[$clean_text])) {
            $button_handlers[$clean_text]($user, $chat_id);
        } elseif (strpos($text, '/start') === 0) {
            handle_start($user, $chat_id, $text);
        } elseif (strpos($text, '/ticket') === 0) {
            handle_ticket_command($user, $chat_id, $text);
        } elseif (strpos($text, '/HelpAddBot') === 0) {
            handle_help_add_bot($user, $chat_id);
        } elseif (strpos($text, '/help') === 0) {
            handle_help_command($user, $chat_id);
        } else {
             handle_start($user, $chat_id);
        }
} elseif (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $user_id  = $callback['from']['id'];
    $chat_id  = $callback['message']['chat']['id'] ?? null;
    $msg_type = $callback['message']['chat']['type'] ?? '';

    // اگر کال‌بک از گروه/کانال بود → کاملاً نادیده بگیر
    if ($msg_type !== 'private') {
        api_request('answerCallbackQuery', [
            'callback_query_id' => $callback['id'],
            'text'             => '' // بدون پیام هم میشه، فقط برای جلوگیری از خطا
        ]);
        exit();
    }
        $message_id = $callback['message']['message_id'] ?? null;
        $data = $callback['data'];
        $callback_query_id = $callback['id'];
        $user = get_or_create_user($user_id);
    // ---- START: handler دکمه "من عضو شدم — بررسی مجدد" (نمونه مقاوم‌تر) ----
if (isset($callback_data) && $callback_data === 'compulsory_check') {
    // دوباره چک می‌کنیم اگر همه عضویت‌ها کامل بود، تایید کنیم
    if (check_compulsory_memberships($user, $chat_id)) {
        // پاسخ به callback
        api_request('answerCallbackQuery', [
            'callback_query_id' => $callback_query_id,
            'text' => '✅ عضویّت شما تأیید شد. در حال ادامهٔ مراحل...',
            'show_alert' => false
        ]);
        // تلاش برای حذف پیام قبلی (پیامی که دکمه‌ها را داشت) تا تمیز شود
        $prev_msg_id = $callback_query['message']['message_id'] ?? null;
        if ($prev_msg_id) {
            @api_request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $prev_msg_id]);
        }
        // بارگذاری دوبارهٔ کاربر (تا از آخرین مقادیر DB مطمئن شویم)
        $user = get_or_create_user($user_id);
        // اگر کاربر جدید است یا هنوز فعال نشده است، مستقیم انتخاب زبان را نمایش می‌دهیم
        // (این باعث می‌شود که پس از انتخاب زبان، activate_user_and_grant_rewards اجرا شود
        // و جایزهٔ معرف به درستی واریز گردد)
        if (!empty($user['is_new']) || empty($user['is_activated'])) {
            set_user_state($user['user_id'], STATE_AWAITING_LANGUAGE);
            // پیام خوش‌آمد و کیبورد انتخاب زبان (همان ساختارِ قبلی)
            send_message($chat_id, get_message('welcome_new_user', $user['language'] ?? 'fa'));
            $inline_keyboard = [
                [['text' => 'فارسی 🇮🇷', 'callback_data' => 'set_lang_fa']],
                [['text' => 'English 🇬🇧', 'callback_data' => 'set_lang_en']]
            ];
            send_message($chat_id, get_message('ask_language', $user['language'] ?? 'fa'), [
                'reply_markup' => json_encode(['inline_keyboard' => $inline_keyboard])
            ]);
            return; // کار این callback تمام شد
        }
        // در غیر این صورت (کاربر قبلاً فعال بوده) منوی اصلی را نمایش بده (همان رفتار قبلی)
        if (function_exists('handle_start')) {
            try {
                handle_start($user, $chat_id, '/start');
            } catch (ArgumentCountError $e) {
                try {
                    handle_start($user, $chat_id);
                } catch (Throwable $e) {
                    // fallback: منوی ساده
                    send_message($chat_id, "✅ عضویّت شما تأیید شد.\n\nدر ادامه منوی اصلی را مشاهده می‌کنید.");
                }
            }
        } else {
            // fallback ساده
            send_message($chat_id, "✅ عضویّت شما تأیید شد.\n\nدر ادامه منوی اصلی را مشاهده می‌کنید.");
        }
    } else {
        // اگر هنوز عضو نشده است، پاسخ کوتاه بدهیم تا کاربر بداند
        api_request('answerCallbackQuery', [
            'callback_query_id' => $callback_query_id,
            'text' => '⚠️ هنوز عضو همهٔ کانال‌ها نیستید. لطفاً ابتدا عضو شوید و سپس دوباره بررسی کنید.',
            'show_alert' => false
        ]);
    }
}
    // ---- END: handler دکمه "من عضو شدم — بررسی مجدد" ----
        if (!$user) { api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]); exit(); }
    
        $parts = explode('_', $data, 4);
        $action = $parts[0]; $param1 = $parts[1] ?? ''; $param2 = $parts[2] ?? ''; $param3 = $parts[3] ?? '';
        switch ($action) {
            case 'join':
                if ($param1 === 'confirm') handle_join_confirmation($user, $chat_id, $message_id, (int)$param2, $callback_query_id);
                break;
            case 'skip':
                // سریعاً callback را جواب بده
                api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                if ($param1 === 'channel') {
                    $pdo = get_pdo();
                    $order_id = (int)$param2;
                    // گرفتن اطلاعات سفارش و کانال
                    $stmt = $pdo->prepare("SELECT o.order_id, o.channel_id AS order_channel_id, o.user_id AS owner_user_id, c.invite_link FROM orders o JOIN channels c ON o.channel_id = c.channel_id WHERE o.order_id = ?");
                    $stmt->execute([$order_id]);
                    $order = $stmt->fetch();
                    // اگر سفارش پیدا نشد، صفحه جمع‌آوری را ریفرش کن
                    if (!$order) {
                        handle_collect_coins($user, $chat_id, $message_id);
                        break;
                    }
                    // 1) اضافه کردن کاربر به لیست سیاه (تا دیگر آن کانال برایش نمایش پیدا نکند)
                    try {
                        $ins = $pdo->prepare("INSERT IGNORE INTO user_blacklist (user_id, channel_id, order_id, reason) VALUES (?, ?, ?, ?)");
                        $ins->execute([$user['user_id'], $order['order_channel_id'], $order_id, 'user_rejected']);
                    } catch (Exception $e) {
                        error_log("Failed to insert into user_blacklist: " . $e->getMessage());
                    }
                    // 2) بررسیِ دقیق‌تر لینک دعوت (اگر وجود داشت)
                    $link_broken = false;
                    $invite_link = $order['invite_link'] ?? null;
                    if ($invite_link) {
                        // تلاش سریع با cURL برای بررسی وضعیت HTTP (timeout کوتاه)
                        $ok = false;
                        try {
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $invite_link);
                            curl_setopt($ch, CURLOPT_NOBODY, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_exec($ch);
                            $http_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                            $curl_err = curl_errno($ch);
                            curl_close($ch);
                            if ($curl_err || $http_code >= 400 || $http_code == 0) {
                                $link_broken = true;
                            } else {
                                $ok = true;
                            }
                        } catch (Exception $e) {
                            // اگر cURL خطا داد، به عنوان نشانه‌ مشکل، flag را نزنیم مگر getChat هم بگوید خراب است
                            error_log("Invite link check error for order {$order_id}: " . $e->getMessage());
                        }
                    }
                    // 3) بررسی سریع وضعیت کانال از طریق getChat (همان‌طور که قبلاً انجام می‌شد)
                    $valid = check_order_validity_and_cleanup($order_id);
                    // اگر یکی از بررسی‌ها بگوید لینک/کانال خراب است -> حذف کامل سفارش و حذف رکورد کانال و اطلاع صاحب کانال
                    if (!$valid || $link_broken) {
                        // ابتدا تلاش برای غیرفعال/مرجوع هزینه سفارش
                        invalidate_order($order_id);
                        // پاک کردن رکورد کانال از جدول channels (اگر خواستید این را نگه دارید، می‌توان این خط را حذف کرد)
                        try {
                            $del = $pdo->prepare("DELETE FROM channels WHERE channel_id = ?");
                            $del->execute([$order['order_channel_id']]);
                        } catch (Exception $e) {
                            error_log("Failed to delete channel {$order['order_channel_id']}: " . $e->getMessage());
                        }
                        // اطلاع به صاحب کانال درباره حذف و دلیل
                        if (!empty($order['owner_user_id'])) {
                            $reason_text = $link_broken ? "لینک دعوت خراب/منقضی یا در دسترس نبود." : "کانال/دسترسی کانال نامعتبر اعلام شد.";
                            $msg = "❌ سفارش شما (کانال: {$order['order_channel_id']}) حذف شد به‌دلیل: {$reason_text}";
                            send_message($order['owner_user_id'], $msg);
                        }
                        // به کاربرِ فعلی هم (اگر لازم است) می‌توان اطلاع داد، اما ما در اینجا فقط صفحهٔ جمع‌آوری را دوباره می‌سازیم
                        handle_collect_coins($user, $chat_id, $message_id);
                        break;
                    }
                    // در حالت عادی (کانال سالم است) — فقط به کاربر صفحهٔ بعدی را نشان بده
                    handle_collect_coins($user, $chat_id, $message_id, $order_id);
                }
                break;
            case 'collect':
                if ($param1 === 'coins' && $param2 === 'retry') {
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                    handle_collect_coins($user, $chat_id, $message_id, null, true);
                }
                break;
            case 'back':
                if ($param1 === 'main' && $param2 === 'menu') {
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                    if ($message_id) api_request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);
                    handle_start($user, $chat_id);
                }
                break;
            case 'my':
                 if ($param1 === 'orders') {
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                    handle_my_orders($user, $chat_id, $message_id);
                }
                break;
            case 'set':
                if ($param1 === 'lang') {
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                    get_pdo()->prepare("UPDATE users SET language = ? WHERE user_id = ?")->execute([$param2, $user_id]);
                    if ($message_id) api_request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);
                
                    // Reload user after language update to get fresh data
                    $user = get_or_create_user($user_id);
                
                    activate_user_and_grant_rewards($user_id);
                    handle_start($user, $chat_id);
                }
                break;
            case 'ch':
                $pdo = get_pdo();
                if ($param1 === 'add' && $param2 === 'new') {
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                    set_user_state($user_id, STATE_AWAITING_CHANNEL_ID);
                    if ($message_id) api_request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);
                    send_message($chat_id, get_message('ask_channel_id', $user['language']), ['reply_markup' => json_encode(['keyboard' => get_cancel_keyboard($user['language']), 'resize_keyboard' => true])]);
                } elseif ($param1 === 'select') {
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                    $channel_id = (int)$param2;
                    if ($pdo->query("SELECT 1 FROM orders WHERE channel_id = $channel_id AND is_active = 1")->fetchColumn()) {
                        api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => get_message('channel_has_active_order', $user['language']), 'show_alert' => true]);
                    } else {
                        $title = $pdo->query("SELECT title FROM channels WHERE channel_id = $channel_id")->fetchColumn();
                        set_user_state($user_id, STATE_AWAITING_MEMBER_COUNT, ['selected_channel' => $channel_id]);
                        if ($message_id) api_request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);
                    
                        $cost_per_member = $bot_settings['ORDER_COST_PER_MEMBER'];
                        if($user['is_vip']) $cost_per_member *= 0.95;
                        $msg = str_replace(['{title}', '{coins}', '{cost_per_member}'], [$title, format_coins($user['coins']), format_coins($cost_per_member)], get_message('ask_member_count', $user['language']));
                        send_message($chat_id, $msg, ['reply_markup' => json_encode(['keyboard' => get_cancel_keyboard($user['language']), 'resize_keyboard' => true])]);
                    }
                } elseif ($param1 === 'delete') {
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                    $pdo->prepare("DELETE FROM channels WHERE channel_id = ? AND owner_user_id = ?")->execute([(int)$param2, $user_id]);
                    handle_my_channels($user, $chat_id, $message_id);
                }
                break;
            case 'order':
                if ($param1 === 'page') {
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                    handle_my_orders($user, $chat_id, $message_id, (int)$param2);
                } elseif ($param1 === 'cancel') {
                    $order_id = (int)$param2;
                    $pdo = get_pdo();
                    $order = $pdo->query("SELECT * FROM orders WHERE order_id = $order_id AND user_id = {$user['user_id']} AND is_active = 1")->fetch();
                    if ($order) {
                        $cost_per_member = $bot_settings['ORDER_COST_PER_MEMBER'];
                        if($user['is_vip']) $cost_per_member *= 0.95;
                        $remaining_members = $order['required_users'] - $order['current_count'];
                        $bonus_per_member = ($order['required_users'] > 0) ? ($order['bonus_coins'] / $order['required_users']) : 0;
                        $refund = ($remaining_members * $cost_per_member) + ($remaining_members * $bonus_per_member);
                        $pdo->beginTransaction();
                        try {
                            if ($refund > 0) _update_user_coins_and_history($pdo, $user_id, $refund, 'reason_cancel_order');
                            $pdo->prepare("UPDATE orders SET is_active = 0 WHERE order_id = ?")->execute([$order_id]);
                            $pdo->commit();
                            api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => str_replace('{refund}', format_coins($refund), get_message('order_cancelled', $user['language']))]);
                            handle_my_orders(get_or_create_user($user_id), $chat_id, $message_id);
                        } catch (Exception $e) { $pdo->rollBack(); api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => get_message('error_generic', $user['language']), 'show_alert' => true]); }
                    } else { api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]); }
                } elseif ($param1 === 'renew') {
                    $order_id = (int)$param2;
                    $pdo = get_pdo();
                    $current_status = $pdo->query("SELECT auto_renew FROM orders WHERE order_id = $order_id AND user_id = {$user['user_id']}")->fetchColumn();
                    if ($current_status !== false && $user['level'] >= 6) {
                        $pdo->prepare("UPDATE orders SET auto_renew = ? WHERE order_id = ?")->execute([!$current_status, $order_id]);
                        handle_my_orders(get_or_create_user($user_id), $chat_id, $message_id);
                    } else {
                        api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => 'برای فعالسازی این ویژگی باید به سطح ۶ برسید.', 'show_alert' => true]);
                    }
                }
                break;
            case 'check':
                if ($param1 === 'all' && $param2 === 'membership') handle_check_all_membership($user, $chat_id, $message_id, $callback_query_id);
                break;
            case 'account':
                api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                $router = [
                    'main' => 'handle_account_menu', 'profile' => 'handle_profile',
                    'history' => 'handle_coin_history', 'leaderboard' => 'handle_leaderboard',
                    'gift' => 'handle_gift_coins_start', 'settings' => 'handle_settings_submenu',
                    'levels' => 'handle_levels_page',
                    'daily' => function ($user, $chat_id, $message_id) use ($param2) {
                        if($param2 === 'gift') handle_daily_gift($user, $chat_id, $message_id);
                    },
                    'support' => function($user, $chat_id, $message_id) {
                        if ($message_id) api_request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);
                        set_user_state($user['user_id'], STATE_AWAITING_TICKET_TEXT);
                        send_message($chat_id, get_message('ask_ticket_text', $user['language']), ['reply_markup' => json_encode(['keyboard' => get_cancel_keyboard($user['language']), 'resize_keyboard' => true])]);
                    }
                ];
                if (isset($router[$param1])) $router[$param1]($user, $chat_id, $message_id);
                break;
            case 'settings':
                api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                if ($param1 === 'lang') {
                    $keyboard = [[['text' => 'فارسی 🇮🇷', 'callback_data' => 'set_lang_fa'], ['text' => 'English 🇬🇧', 'callback_data' => 'set_lang_en']], [['text' => get_message('back', $user['language']), 'callback_data' => 'account_settings']]];
                    edit_message($chat_id, $message_id, get_message('ask_language'), ['reply_markup' => json_encode(['inline_keyboard' => $keyboard])]);
                } elseif ($param1 === 'notif') {
                    handle_notifications_menu($user, $chat_id, $message_id);
                }
                break;
            case 'notif':
                if ($param1 === 'toggle') {
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                    handle_toggle_notification($user, $chat_id, $message_id, $param2);
                }
                break;
            case 'boost':
                if ($param1 === 'start') {
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                    if ($message_id) api_request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);
                    handle_boost_order_start($user, $chat_id);
                } elseif ($param1 === 'select') {
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                    handle_boost_order_selection($user, $chat_id, $message_id, (int)$param2);
                }
                break;
            case 'vip':
                if ($param1 === 'purchase') {
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                    handle_vip_purchase($user, $chat_id, $message_id);
                } elseif ($param1 === 'menu') {
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                    handle_vip_menu($user, $chat_id, $message_id);
                } elseif ($param1 === 'badge' && $param2 === 'menu') {
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                    handle_badge_selection_menu($user, $chat_id, $message_id);
                }
                break;
            case 'badge':
                if ($param1 === 'select') {
                    $badge = urldecode($param2);
                    get_pdo()->prepare("UPDATE users SET profile_badge = ? WHERE user_id = ?")->execute([$badge, $user_id]);
                    $msg = str_replace('{badge}', $badge, get_message('badge_set_success', $user['language']));
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => $msg]);
                    handle_vip_menu(get_or_create_user($user_id), $chat_id, $message_id);
                }
                break;
            case 'report':
                api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                if ($param1 === 'channel') handle_report_channel_start($user, $chat_id, $message_id, (int)$param2, (int)$param3);
                break;
            case 'cancel':
                if ($param1 === 'report') {
                    api_request('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
                    set_user_state($user['user_id'], STATE_DEFAULT);
                    handle_collect_coins($user, $chat_id, $message_id, $user['user_data']['order_id'] ?? null);
                }
                break;
        }
    }
} catch (Exception $e) {
    error_log("Unhandled Exception for user {$user_id_for_log}: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    if (isset($chat_id)) {
        send_message($chat_id, get_message('error_generic', 'fa'));
    }
}

?>
