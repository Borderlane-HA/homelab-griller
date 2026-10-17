<?php
declare(strict_types=1);

ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {ini_set('session.cookie_secure','1');}
session_start();
@date_default_timezone_set(getenv('TZ') ?: 'Europe/Berlin');

const ADMIN_USER = 'Griller';
const DATA_DIR = __DIR__ . '/data';
const BUILTIN_LANG_DIR = __DIR__ . '/lang';
const UPLOAD_LANG_DIR = DATA_DIR . '/lang';
const DB_PATH = DATA_DIR . '/griller.sqlite';

if (!is_dir(DATA_DIR)) { mkdir(DATA_DIR, 0775, true); }
if (!is_dir(UPLOAD_LANG_DIR)) { mkdir(UPLOAD_LANG_DIR, 0775, true); }

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    init_db($pdo);
    return $pdo;
}

function init_db(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        icon TEXT NOT NULL DEFAULT '🔥',
        sort_order INTEGER NOT NULL DEFAULT 100,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        options_json TEXT NOT NULL DEFAULT '[]',
        allow_doneness INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 100,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE RESTRICT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        event_date TEXT,
        description TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'draft',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 100,
        active INTEGER NOT NULL DEFAULT 1,
        UNIQUE(event_id, product_id),
        FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE,
        FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS guest_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        normalized_name TEXT NOT NULL,
        pin_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS guests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        profile_id INTEGER,
        name TEXT NOT NULL,
        token TEXT NOT NULL UNIQUE,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE
    )");
    ensure_column($pdo, 'guests', 'profile_id', 'INTEGER');
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        guest_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE,
        FOREIGN KEY(guest_id) REFERENCES guests(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        event_product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 1,
        doneness TEXT NOT NULL DEFAULT '',
        options_json TEXT NOT NULL DEFAULT '[]',
        note TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'pending',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
        FOREIGN KEY(event_product_id) REFERENCES event_products(id) ON DELETE CASCADE
    )");

    ensure_column($pdo, 'events', 'inventory_enabled', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'event_products', 'stock_limit', 'INTEGER');
    ensure_column($pdo, 'order_items', 'stock_recorded', 'INTEGER NOT NULL DEFAULT 0');
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_item_components (
        item_id INTEGER NOT NULL,
        ingredient_id INTEGER NOT NULL,
        units_used INTEGER NOT NULL CHECK(units_used >= 0),
        PRIMARY KEY(item_id,ingredient_id),
        FOREIGN KEY(item_id) REFERENCES order_items(id) ON DELETE CASCADE,
        FOREIGN KEY(ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_recipes (
        product_id INTEGER NOT NULL,
        ingredient_id INTEGER NOT NULL,
        units_per_item INTEGER NOT NULL DEFAULT 1 CHECK(units_per_item > 0),
        PRIMARY KEY(product_id,ingredient_id),
        FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
        FOREIGN KEY(ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_inventory (
        event_id INTEGER NOT NULL,
        ingredient_id INTEGER NOT NULL,
        stock_quantity INTEGER NOT NULL CHECK(stock_quantity >= 0),
        PRIMARY KEY(event_id,ingredient_id),
        FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE,
        FOREIGN KEY(ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ingredients (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE COLLATE NOCASE, group_name TEXT NOT NULL DEFAULT 'Extras', active INTEGER NOT NULL DEFAULT 1)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_ingredients (product_id INTEGER NOT NULL, ingredient_id INTEGER NOT NULL, PRIMARY KEY(product_id,ingredient_id), FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE, FOREIGN KEY(ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orders_event ON orders(event_id, status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_order_items_order_status ON order_items(order_id, status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_guests_event ON guests(event_id)");
    $count = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($count === 0) {
        seed_data($pdo);
    }
    // Migrate the former comma-separated options into reusable ingredient records.
    if ((int)$pdo->query('SELECT COUNT(*) FROM ingredients')->fetchColumn() === 0) {
        foreach ($pdo->query('SELECT id,options_json FROM products') as $product) {
            foreach (decode_options((string)$product['options_json']) as $name) {
                $group = preg_match('/sauce|soße|salsa|senf|ketchup|dip/i', $name) ? 'Sauces' : 'Toppings';
                $pdo->prepare('INSERT OR IGNORE INTO ingredients(name,group_name) VALUES(?,?)')->execute([$name, $group]);
                $pdo->prepare('INSERT OR IGNORE INTO product_ingredients(product_id,ingredient_id) SELECT ?,id FROM ingredients WHERE name=?')->execute([$product['id'], $name]);
            }
        }
    }

}


function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($stmt->fetchAll() as $row) {
        if (($row['name'] ?? '') === $column) { return; }
    }
    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function seed_data(PDO $pdo): void {
    $categories = [
        ['Burger', '🍔', 10],
        ['Würstchen', '🌭', 20],
        ['Beef & Steak', '🥩', 30],
        ['Beilagen', '🥗', 40],
        ['Getränke', '🥤', 50],
        ['Saucen & Extras', '🧂', 60],
        ['Vegetarisch/Vegan', '🌱', 70],
        ['Dessert', '🍨', 80],
    ];
    $stmt = $pdo->prepare('INSERT INTO categories(name, icon, sort_order) VALUES(?,?,?)');
    foreach ($categories as $cat) { $stmt->execute($cat); }

    $catIds = [];
    foreach ($pdo->query('SELECT id, name FROM categories') as $row) { $catIds[$row['name']] = (int)$row['id']; }
    $products = [
        [$catIds['Burger'], 'Classic Burger', 'Patty im Bun – Gäste wählen Toppings selbst.', ['Käse','Zwiebeln','Gurken','Tomate','Salat','Bacon','BBQ-Sauce'], 0, 10],
        [$catIds['Burger'], 'Cheeseburger', 'Burger mit Käse als Basis.', ['Zwiebeln','Gurken','Tomate','Salat','Bacon','Jalapeños','BBQ-Sauce'], 0, 20],
        [$catIds['Würstchen'], 'Bratwurst', 'Klassiker vom Grill.', ['Senf','Ketchup','Röstzwiebeln'], 0, 10],
        [$catIds['Beef & Steak'], 'Ribeye Steak', 'Für Beef-Bestellungen mit Gargrad.', ['Kräuterbutter','BBQ-Sauce','Pfeffer'], 1, 10],
        [$catIds['Beilagen'], 'Kartoffelsalat', 'Portion als Beilage.', [], 0, 10],
        [$catIds['Beilagen'], 'Maiskolben', 'Gegrillter Maiskolben.', ['Butter','Salz','Chili'], 0, 20],
        [$catIds['Getränke'], 'Wasser', 'Still oder spritzig bitte als Notiz ergänzen.', ['still','spritzig'], 0, 10],
        [$catIds['Getränke'], 'Limonade', 'Kaltgetränk.', ['Cola','Orange','Zitrone'], 0, 20],
        [$catIds['Vegetarisch/Vegan'], 'Veggie Burger', 'Vegetarische Burger-Option.', ['Käse','Zwiebeln','Gurken','Tomate','Salat','BBQ-Sauce'], 0, 10],
        [$catIds['Dessert'], 'Gegrillte Banane', 'Dessert vom Grill.', ['Schokolade','Zimt','Vanilleeis'], 0, 10],
    ];
    $stmt = $pdo->prepare('INSERT INTO products(category_id,name,description,options_json,allow_doneness,sort_order,active) VALUES(?,?,?,?,?,?,1)');
    foreach ($products as $p) {
        $stmt->execute([$p[0], $p[1], $p[2], json_encode($p[3], JSON_UNESCAPED_UNICODE), $p[4], $p[5]]);
    }
}

function available_langs(): array {
    $langs = [];
    foreach ([BUILTIN_LANG_DIR, UPLOAD_LANG_DIR] as $dir) {
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $code = basename($file, '.json');
            if (preg_match('/^[a-z]{2,8}(-[A-Za-z0-9]{2,8})?$/', $code)) {
                $langs[$code] = strtoupper(substr($code, 0, 2));
            }
        }
    }
    ksort($langs);
    return $langs;
}

function load_lang(string $code): array {
    $baseDe = json_decode((string)file_get_contents(BUILTIN_LANG_DIR . '/de.json'), true) ?: [];
    $baseEn = json_decode((string)file_get_contents(BUILTIN_LANG_DIR . '/en.json'), true) ?: [];
    $lang = array_replace($baseDe, $baseEn);
    if ($code === 'en') { $lang = array_replace($baseDe, $baseEn); }
    $paths = [BUILTIN_LANG_DIR . '/' . $code . '.json', UPLOAD_LANG_DIR . '/' . $code . '.json'];
    foreach ($paths as $path) {
        if (is_file($path)) {
            $custom = json_decode((string)file_get_contents($path), true);
            if (is_array($custom)) { $lang = array_replace($lang, $custom); }
        }
    }
    return $lang;
}

if (isset($_GET['lang']) && preg_match('/^[a-z]{2,8}(-[A-Za-z0-9]{2,8})?$/', (string)$_GET['lang'])) {
    $_SESSION['lang'] = (string)$_GET['lang'];
}
$langCode = $_SESSION['lang'] ?? setting('default_language',(getenv('GRILLER_DEFAULT_LANGUAGE') ?: 'de'));
$LANG = load_lang($langCode);

function t(string $key, array $vars = []): string {
    global $LANG;
    $text = $LANG[$key] ?? $key;
    foreach ($vars as $k => $v) { $text = str_replace('{' . $k . '}', (string)$v, $text); }
    return $text;
}

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function is_admin(): bool { return !empty($_SESSION['admin']); }
function setting(string $key, string $default = ''): string {
    $q = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
    $q->execute([$key]); $v = $q->fetchColumn();
    return $v === false ? $default : (string)$v;
}
function put_setting(string $key, string $value): void {
    db()->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value')->execute([$key,$value]);
}
function setup_required(): bool { return setting('admin_hash') === ''; }
function admin_username(): string { return setting('admin_username', 'Griller'); }
function admin_password_valid(string $value): bool {
    $hash = setting('admin_hash');
    return $hash !== '' && password_verify($value, $hash);
}
function guest_for_event(array $event): ?array {
    $key = 'guest_token_' . $event['id'];
    if (empty($_SESSION[$key])) { return null; }
    $q = db()->prepare('SELECT * FROM guests WHERE token=? AND event_id=?');
    $q->execute([$_SESSION[$key], $event['id']]); return $q->fetch() ?: null;
}
function all_pending(int $orderId): bool {
    $q = db()->prepare("SELECT COUNT(*) FROM order_items WHERE order_id=? AND status != 'pending'");
    $q->execute([$orderId]); return (int)$q->fetchColumn() === 0;
}
function order_owner(int $orderId, array $event, array $guest): ?array {
    $q = db()->prepare('SELECT * FROM orders WHERE id=? AND event_id=? AND guest_id=?');
    $q->execute([$orderId, $event['id'], $guest['id']]); return $q->fetch() ?: null;
}
function order_eligible(int $orderId, array $event, array $guest): bool {
    $o = order_owner($orderId, $event, $guest);
    return $o && $o['status'] === 'pending' && all_pending($orderId);
}
function get_status_html(array $event, array $guest): string {
    ob_start(); show_guest_status($event, $guest); return (string)ob_get_clean();
}
function ingredients_for_product(int $productId): array {
    $q=db()->prepare('SELECT i.* FROM ingredients i JOIN product_ingredients pi ON pi.ingredient_id=i.id WHERE pi.product_id=? AND i.active=1 ORDER BY i.group_name,i.name');
    $q->execute([$productId]); return $q->fetchAll();
}
function status_summary(int $orderId): string {
    $q=db()->prepare('SELECT status,COUNT(*) AS count FROM order_items WHERE order_id=? GROUP BY status');
    $q->execute([$orderId]); $s=[]; foreach($q as $r){$s[$r['status']]=(int)$r['count'];}
    if (!empty($s['cancelled']) && count($s) === 1) return 'cancelled';
    if (!empty($s['in_progress'])) return 'in_progress';
    if (!empty($s['pending'])) return 'pending';
    return 'done';
}
function sync_order_status(int $orderId): void {
    db()->prepare('UPDATE orders SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([status_summary($orderId),$orderId]);
}

function csrf_token(): string { if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); } return $_SESSION['csrf']; }
function verify_csrf(): void { if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { http_response_code(403); exit('CSRF validation failed'); } }
function flash(?string $message = null, string $type = 'good'): ?array { if ($message !== null) { $_SESSION['flash'] = [$message,$type]; return null; } $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function route(string $r, array $params = []): string { $params = array_merge(['r' => $r], $params); return '?' . http_build_query($params); }
function redirect_to(string $r, array $params = []): never { header('Location: ' . route($r, $params)); exit; }
function require_admin(): void { if (!is_admin()) { redirect_to('admin_login'); } }
function slugify(string $title): string { $s = trim($title); $s = strtr($s, ['Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']); $s = strtolower($s); $s = preg_replace('/[^a-z0-9]+/i', '-', $s) ?: 'event'; $s = trim($s, '-'); return $s ?: 'event'; }
function unique_slug(PDO $pdo, string $title, ?int $excludeId = null): string {
    $base = slugify($title); $slug = $base; $i = 2;
    while (true) {
        $sql = 'SELECT id FROM events WHERE slug = ?' . ($excludeId ? ' AND id != ?' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute($excludeId ? [$slug, $excludeId] : [$slug]);
        if (!$stmt->fetch()) { return $slug; }
        $slug = $base . '-' . $i++;
    }
}
function status_label(string $status): string { return t($status); }
function doneness_options(): array { return ['rare'=>t('beef_rare'),'medium_rare'=>t('beef_medium_rare'),'medium'=>t('beef_medium'),'medium_well'=>t('beef_medium_well'),'well_done'=>t('beef_well_done')]; }
function decode_options(string $json): array { $a = json_decode($json, true); return is_array($a) ? array_values(array_filter(array_map('strval', $a))) : []; }
function normalize_guest_name(string $name): string { return strtolower(trim(preg_replace('/\s+/', ' ', $name) ?: $name)); }
function find_or_create_guest_profile(PDO $pdo, string $name, string $pin): int {
    $normalized = normalize_guest_name($name);
    $stmt = $pdo->prepare('SELECT * FROM guest_profiles WHERE normalized_name = ? ORDER BY created_at ASC');
    $stmt->execute([$normalized]);
    foreach ($stmt->fetchAll() as $profile) {
        if (password_verify($pin, (string)$profile['pin_hash'])) {
            $pdo->prepare('UPDATE guest_profiles SET name=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$name, $profile['id']]);
            return (int)$profile['id'];
        }
    }
    $hash = password_hash($pin, PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO guest_profiles(name,normalized_name,pin_hash) VALUES(?,?,?)')->execute([$name, $normalized, $hash]);
    return (int)$pdo->lastInsertId();
}

function render_header(string $title = ''): void {
    $langs = available_langs();
    $current = $_SESSION['lang'] ?? setting('default_language',(getenv('GRILLER_DEFAULT_LANGUAGE') ?: 'de'));
    echo '<!doctype html><html lang="' . h($current) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title ? $title . ' · ' . t('app_name') : t('app_name')) . '</title>';
    echo '<meta name="theme-color" content="#14191d"><link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css"></head><body class="theme-' . h(setting('theme','ember')) . '">';
    echo '<header class="topbar"><div class="container topbar-inner"><a class="brand" href="' . h(route('home')) . '"><img src="assets/logo.svg" alt=""><span>' . h(t('app_name')) . ' <small class="muted">' . h(t('by_pengu')) . '</small></span></a><nav class="nav">';
    echo '<form class="lang-select" method="get"><input type="hidden" name="r" value="' . h($_GET['r'] ?? 'home') . '">';
    foreach ($_GET as $k => $v) { if (!in_array($k, ['r','lang'], true) && is_scalar($v)) { echo '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">'; } }
    echo '<label class="muted" for="lang">' . h(t('language')) . '</label><select id="lang" name="lang" onchange="this.form.submit()">';
    foreach ($langs as $code => $label) { echo '<option value="' . h($code) . '" ' . ($current === $code ? 'selected' : '') . '>' . h($label) . '</option>'; }
    echo '</select></form>';
    echo '<a class="btn ghost small home-link" href="' . h(route('home')) . '">' . h(t('home')) . '</a>';
    if (is_admin()) { echo '<a class="btn dark small" href="' . h(route('admin')) . '">' . h(t('admin')) . '</a><a class="btn ghost small" href="' . h(route('admin_logout')) . '">' . h(t('logout')) . '</a>'; }
    else { echo '<a class="btn dark small" href="' . h(route(is_admin()?'admin':'admin_login')) . '">' . h(t('admin')) . '</a>'; }
    echo '</nav></div></header><main class="container">';
    $f = flash(); if ($f) { echo '<div class="notice ' . h($f[1]) . '">' . h($f[0]) . '</div>'; }
}
function render_footer(): void {
    $routeName=(string)($_GET['r']??'home');
    $section= $routeName==='home'?'home':($routeName==='event'?'guest':($routeName==='admin_kitchen'?'kitchen':'admin'));
    $allowed=explode(',',setting('footer_pages','home,guest,admin,kitchen'));
    echo '</main>';
    if(in_array($section,$allowed,true)){
        $txt=setting('footer_text',t('footer'));
        echo '<footer class="footer"><div class="container">' . nl2br(h($txt));
        if(setting('footer_show_version','0')==='1'){
            $version=is_file(__DIR__.'/VERSION')?trim((string)file_get_contents(__DIR__.'/VERSION')):'2.0.0-beta.3';
            echo ' <span class="footer-version">v' . h($version) . '</span>';
        }
        echo '</div></footer>';
    }
    $assetVersion = is_file(__DIR__ . '/VERSION') ? trim((string)file_get_contents(__DIR__ . '/VERSION')) : '2.0.0-beta.3';
    echo '<script defer src="assets/app.js?v=' . h(rawurlencode($assetVersion)) . '"></script></body></html>';
}
function admin_tabs(): void {
    echo '<div class="admin-tabs">';
    echo '<a class="btn ghost small" href="' . h(route('admin')) . '">' . h(t('events')) . '</a>';
    echo '<a class="btn ghost small" href="' . h(route('admin_categories')) . '">' . h(t('categories')) . '</a>';
    echo '<a class="btn ghost small" href="' . h(route('admin_products')) . '">' . h(t('products')) . '</a>';
    echo '<a class="btn ghost small" href="' . h(route('admin_guests')) . '">' . h(t('guests')) . '</a>';
    echo '<a class="btn ghost small" href="' . h(route('admin_ingredients')) . '">' . h(t('ingredients')) . '</a>';
    echo '<a class="btn ghost small" href="' . h(route('admin_settings')) . '">' . h(t('settings')) . '</a>';
    echo '<a class="btn ghost small" href="' . h(route('admin_lang')) . '">' . h(t('language_packs')) . '</a>';
    echo '</div>';
}

function render_sortable_script(): void {
    echo '<script>
function sortableOrder(listId){return Array.from(document.querySelectorAll("#"+listId+" [data-sort-id]")).map(function(el){return el.dataset.sortId;}).join(",");}
function initSortable(listId,inputId){
  const list=document.getElementById(listId); const input=document.getElementById(inputId); if(!list||!input){return;}
  let dragged=null;
  const update=function(){input.value=sortableOrder(listId);};
  list.querySelectorAll("[data-sort-id]").forEach(function(row){
    row.draggable=true;
    row.addEventListener("dragstart",function(e){dragged=row; row.classList.add("dragging"); e.dataTransfer.effectAllowed="move";});
    row.addEventListener("dragend",function(){row.classList.remove("dragging"); dragged=null; update();});
  });
  list.addEventListener("dragover",function(e){
    e.preventDefault(); if(!dragged){return;}
    const rows=Array.from(list.querySelectorAll("[data-sort-id]:not(.dragging)"));
    let after=null;
    for(const row of rows){const box=row.getBoundingClientRect(); if(e.clientY < box.top + box.height/2){after=row; break;}}
    list.insertBefore(dragged, after);
  });
  list.addEventListener("drop",function(e){e.preventDefault(); update();});
  list.querySelectorAll("[data-sort-id]").forEach(row => {
    const grip=row.querySelector(".drag-handle"); if (!grip) return;
    ["↑","↓"].forEach((symbol, delta) => {
      const button=document.createElement("button");button.type="button";button.className="sort-arrow";button.textContent=symbol;
      button.setAttribute("aria-label", delta ? "Move down" : "Move up");
      button.addEventListener("click",() => {
        if (delta && row.nextElementSibling)list.insertBefore(row,row.nextElementSibling.nextElementSibling);
        else if (!delta && row.previousElementSibling)list.insertBefore(row,row.previousElementSibling);
        update();
      });
      grip.appendChild(button);
    });
  });
  update();
}
</script>';
}

function public_link(array $event): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return $scheme . '://' . $host . $path . route('event', ['slug' => $event['slug']]);
}

function qr_svg(string $text, int $scale = 6, int $border = 4): string {
    try {
        $matrix = qr_matrix($text);
    } catch (Throwable $e) {
        return '<div class="notice warn">' . h(t('qr_too_long')) . '</div>';
    }
    $size = count($matrix);
    $view = $size + ($border * 2);
    $path = '';
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if (!empty($matrix[$y][$x])) { $path .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z'; }
        }
    }
    return '<svg class="qr-code" role="img" aria-label="QR code" width="' . ($view * $scale) . '" height="' . ($view * $scale) . '" viewBox="0 0 ' . $view . ' ' . $view . '" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" rx="2" fill="#fff"/><path fill="#111827" d="' . $path . '"/></svg>';
}
function qr_matrix(string $text): array {
    $specs = [
        1 => ['data'=>19, 'ec'=>7,  'blocks'=>[19]],
        2 => ['data'=>34, 'ec'=>10, 'blocks'=>[34]],
        3 => ['data'=>55, 'ec'=>15, 'blocks'=>[55]],
        4 => ['data'=>80, 'ec'=>20, 'blocks'=>[80]],
        5 => ['data'=>108,'ec'=>26, 'blocks'=>[108]],
        6 => ['data'=>136,'ec'=>18, 'blocks'=>[68,68]],
    ];
    $len = strlen($text);
    $version = 0; $spec = null;
    foreach ($specs as $v => $candidate) {
        if ((4 + 8 + ($len * 8)) <= ($candidate['data'] * 8)) { $version = $v; $spec = $candidate; break; }
    }
    if (!$spec) { throw new RuntimeException('QR text too long'); }
    $bits = [];
    qr_append_bits($bits, 0b0100, 4);
    qr_append_bits($bits, $len, 8);
    foreach (array_values(unpack('C*', $text) ?: []) as $b) { qr_append_bits($bits, (int)$b, 8); }
    $capacityBits = $spec['data'] * 8;
    qr_append_bits($bits, 0, min(4, $capacityBits - count($bits)));
    while ((count($bits) % 8) !== 0) { $bits[] = 0; }
    $data = [];
    for ($i = 0; $i < count($bits); $i += 8) {
        $b = 0;
        for ($j = 0; $j < 8; $j++) { $b = ($b << 1) | ($bits[$i + $j] ?? 0); }
        $data[] = $b;
    }
    for ($pad = 0; count($data) < $spec['data']; $pad++) { $data[] = ($pad % 2 === 0) ? 0xEC : 0x11; }

    $dataBlocks = [];
    $pos = 0;
    foreach ($spec['blocks'] as $blockLen) { $dataBlocks[] = array_slice($data, $pos, $blockLen); $pos += $blockLen; }
    $ecBlocks = [];
    foreach ($dataBlocks as $block) { $ecBlocks[] = qr_rs_remainder($block, $spec['ec']); }
    $codewords = [];
    $maxData = max($spec['blocks']);
    for ($i = 0; $i < $maxData; $i++) {
        foreach ($dataBlocks as $block) { if ($i < count($block)) { $codewords[] = $block[$i]; } }
    }
    for ($i = 0; $i < $spec['ec']; $i++) {
        foreach ($ecBlocks as $block) { $codewords[] = $block[$i]; }
    }
    $allBits = [];
    foreach ($codewords as $cw) { qr_append_bits($allBits, $cw, 8); }

    $size = 21 + (($version - 1) * 4);
    $matrix = array_fill(0, $size, array_fill(0, $size, false));
    $func = array_fill(0, $size, array_fill(0, $size, false));
    qr_draw_function_patterns($matrix, $func, $version);
    qr_draw_data($matrix, $func, $allBits);

    $best = null; $bestPenalty = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $m = $matrix;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (!$func[$y][$x] && qr_mask($mask, $x, $y)) { $m[$y][$x] = !$m[$y][$x]; }
            }
        }
        qr_draw_format_bits($m, $func, $mask);
        $penalty = qr_penalty($m);
        if ($penalty < $bestPenalty) { $bestPenalty = $penalty; $best = $m; }
    }
    return $best ?: $matrix;
}
function qr_append_bits(array &$bits, int $value, int $length): void { for ($i = $length - 1; $i >= 0; $i--) { $bits[] = ($value >> $i) & 1; } }
function qr_gf_tables(): array {
    static $tables = null;
    if ($tables !== null) { return $tables; }
    $exp = array_fill(0, 512, 0); $log = array_fill(0, 256, 0); $x = 1;
    for ($i = 0; $i < 255; $i++) { $exp[$i] = $x; $log[$x] = $i; $x <<= 1; if ($x & 0x100) { $x ^= 0x11D; } }
    for ($i = 255; $i < 512; $i++) { $exp[$i] = $exp[$i - 255]; }
    return $tables = [$exp, $log];
}
function qr_gf_mul(int $a, int $b): int { if ($a === 0 || $b === 0) { return 0; } [$exp, $log] = qr_gf_tables(); return $exp[$log[$a] + $log[$b]]; }
function qr_rs_generator(int $degree): array {
    $gen = [1]; [$exp] = qr_gf_tables();
    for ($i = 0; $i < $degree; $i++) {
        $next = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $j => $coef) { $next[$j] ^= $coef; $next[$j + 1] ^= qr_gf_mul($coef, $exp[$i]); }
        $gen = $next;
    }
    return $gen;
}
function qr_rs_remainder(array $data, int $ecLen): array {
    $gen = qr_rs_generator($ecLen); $res = array_fill(0, $ecLen, 0);
    foreach ($data as $b) {
        $factor = $b ^ $res[0]; array_shift($res); $res[] = 0;
        for ($i = 0; $i < $ecLen; $i++) { $res[$i] ^= qr_gf_mul($gen[$i + 1], $factor); }
    }
    return $res;
}
function qr_set(array &$m, array &$f, int $x, int $y, bool $dark): void { if (isset($m[$y][$x])) { $m[$y][$x] = $dark; $f[$y][$x] = true; } }
function qr_draw_finder(array &$m, array &$f, int $x, int $y): void {
    $size = count($m);
    for ($dy = -1; $dy <= 7; $dy++) { for ($dx = -1; $dx <= 7; $dx++) {
        $xx = $x + $dx; $yy = $y + $dy; if ($xx < 0 || $yy < 0 || $xx >= $size || $yy >= $size) { continue; }
        $dark = ($dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6 && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4)));
        qr_set($m, $f, $xx, $yy, $dark);
    }}
}
function qr_draw_alignment(array &$m, array &$f, int $cx, int $cy): void { for ($dy = -2; $dy <= 2; $dy++) { for ($dx = -2; $dx <= 2; $dx++) { qr_set($m, $f, $cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) === 2 || ($dx === 0 && $dy === 0)); } } }
function qr_draw_function_patterns(array &$m, array &$f, int $version): void {
    $size = count($m);
    qr_draw_finder($m, $f, 0, 0); qr_draw_finder($m, $f, $size - 7, 0); qr_draw_finder($m, $f, 0, $size - 7);
    for ($i = 0; $i < $size; $i++) { if (!$f[6][$i]) { qr_set($m, $f, $i, 6, $i % 2 === 0); } if (!$f[$i][6]) { qr_set($m, $f, 6, $i, $i % 2 === 0); } }
    if ($version > 1) { qr_draw_alignment($m, $f, $size - 7, $size - 7); }
    for ($i = 0; $i < 9; $i++) { if ($i !== 6) { qr_set($m, $f, 8, $i, false); qr_set($m, $f, $i, 8, false); } }
    for ($i = 0; $i < 8; $i++) { qr_set($m, $f, $size - 1 - $i, 8, false); qr_set($m, $f, 8, $size - 1 - $i, false); }
    qr_set($m, $f, 8, $size - 8, true);
}
function qr_draw_data(array &$m, array $f, array $bits): void {
    $size = count($m); $i = 0; $up = true;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) { $right--; }
        for ($vert = 0; $vert < $size; $vert++) {
            $y = $up ? ($size - 1 - $vert) : $vert;
            for ($dx = 0; $dx < 2; $dx++) { $x = $right - $dx; if (!$f[$y][$x]) { $m[$y][$x] = (($bits[$i++] ?? 0) === 1); } }
        }
        $up = !$up;
    }
}
function qr_mask(int $mask, int $x, int $y): bool { return match ($mask) { 0 => (($x + $y) % 2) === 0, 1 => ($y % 2) === 0, 2 => ($x % 3) === 0, 3 => (($x + $y) % 3) === 0, 4 => ((intdiv($y, 2) + intdiv($x, 3)) % 2) === 0, 5 => ((($x * $y) % 2) + (($x * $y) % 3)) === 0, 6 => (((($x * $y) % 2) + (($x * $y) % 3)) % 2) === 0, default => (((($x + $y) % 2) + (($x * $y) % 3)) % 2) === 0, }; }
function qr_draw_format_bits(array &$m, array &$f, int $mask): void {
    $size = count($m); $data = (1 << 3) | $mask; $rem = $data << 10;
    for ($i = 14; $i >= 10; $i--) { if ((($rem >> $i) & 1) !== 0) { $rem ^= 0x537 << ($i - 10); } }
    $bits = (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;
    $get = fn(int $i): bool => (($bits >> $i) & 1) !== 0;
    for ($i = 0; $i <= 5; $i++) { qr_set($m, $f, 8, $i, $get($i)); }
    qr_set($m, $f, 8, 7, $get(6)); qr_set($m, $f, 8, 8, $get(7)); qr_set($m, $f, 7, 8, $get(8));
    for ($i = 9; $i < 15; $i++) { qr_set($m, $f, 14 - $i, 8, $get($i)); }
    for ($i = 0; $i < 8; $i++) { qr_set($m, $f, $size - 1 - $i, 8, $get($i)); }
    for ($i = 8; $i < 15; $i++) { qr_set($m, $f, 8, $size - 15 + $i, $get($i)); }
    qr_set($m, $f, 8, $size - 8, true);
}
function qr_penalty(array $m): int {
    $size = count($m); $pen = 0;
    for ($axis = 0; $axis < 2; $axis++) {
        for ($i = 0; $i < $size; $i++) {
            $runColor = false; $runLen = 0;
            for ($j = 0; $j < $size; $j++) {
                $color = $axis === 0 ? $m[$i][$j] : $m[$j][$i];
                if ($j === 0 || $color !== $runColor) { if ($runLen >= 5) { $pen += 3 + ($runLen - 5); } $runColor = $color; $runLen = 1; }
                else { $runLen++; }
            }
            if ($runLen >= 5) { $pen += 3 + ($runLen - 5); }
        }
    }
    for ($y = 0; $y < $size - 1; $y++) { for ($x = 0; $x < $size - 1; $x++) { $c = $m[$y][$x]; if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) { $pen += 3; } } }
    $dark = 0; foreach ($m as $row) { foreach ($row as $c) { if ($c) { $dark++; } } }
    $percent = ($dark * 100) / ($size * $size); $pen += (int)(abs($percent - 50) / 5) * 10;
    return $pen;
}

/** Inventory is event-scoped. NULL/missing stock limits are unlimited.
 * Usage is derived from non-cancelled order lines; never decrement counters manually.
 * Caller must hold SQLite BEGIN IMMEDIATE when verifying a new order.
 */
function inventory_snapshot(int $eventId,int $excludeOrderId=0): array {
    $pdo=db();
    $q=$pdo->prepare('SELECT inventory_enabled FROM events WHERE id=?');$q->execute([$eventId]);
    $enabled=(bool)$q->fetchColumn();
    $snap=['enabled'=>$enabled,'limits'=>[],'used'=>[],'remaining'=>[], 'recipe'=>[], 'options'=>[], 'product_limits'=>[], 'product_used'=>[]];
    if(!$enabled)return $snap;
    $q=$pdo->prepare('SELECT ingredient_id,stock_quantity FROM event_inventory WHERE event_id=?');$q->execute([$eventId]);
    foreach($q as $r){$snap['limits'][(int)$r['ingredient_id']]=(int)$r['stock_quantity'];}
    foreach($pdo->query('SELECT product_id,ingredient_id,units_per_item FROM product_recipes') as $r){
        $snap['recipe'][(int)$r['product_id']][(int)$r['ingredient_id']]=(int)$r['units_per_item'];
    }
    foreach($pdo->query('SELECT pi.product_id,i.id,i.name FROM product_ingredients pi JOIN ingredients i ON i.id=pi.ingredient_id') as $r){
        $snap['options'][(int)$r['product_id']][(string)$r['name']]=(int)$r['id'];
    }
    $q=$pdo->prepare('SELECT id,stock_limit FROM event_products WHERE event_id=?');$q->execute([$eventId]);
    foreach($q as $r){if($r['stock_limit']!==null)$snap['product_limits'][(int)$r['id']]=(int)$r['stock_limit'];}
    $q=$pdo->prepare("SELECT oi.id AS item_id,oi.stock_recorded,oi.event_product_id,ep.product_id,oi.quantity,oi.options_json FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN event_products ep ON ep.id=oi.event_product_id WHERE o.event_id=? AND oi.status != 'cancelled' AND o.id != ?");
    $q->execute([$eventId,$excludeOrderId]);
    $ledger=$pdo->prepare("SELECT c.item_id,c.ingredient_id,c.units_used FROM order_item_components c JOIN order_items oi ON oi.id=c.item_id JOIN orders o ON o.id=oi.order_id WHERE o.event_id=? AND oi.status!='cancelled' AND o.id!=?");
    $ledger->execute([$eventId,$excludeOrderId]);$recorded=[];
    foreach($ledger as $entry){$recorded[(int)$entry['item_id']][(int)$entry['ingredient_id']]=(int)$entry['units_used'];}
    foreach($q as $r){
        $qty=(int)$r['quantity'];$pid=(int)$r['product_id'];$ep=(int)$r['event_product_id'];
        if((int)$r['stock_recorded']===1){
            $snap['product_used'][$ep]=($snap['product_used'][$ep]??0)+$qty;
            foreach($recorded[(int)$r['item_id']]??[] as $iid=>$units){$snap['used'][$iid]=($snap['used'][$iid]??0)+$units;}
            continue;
        }
        $snap['product_used'][$ep]=($snap['product_used'][$ep]??0)+$qty;
        foreach($snap['recipe'][$pid]??[] as $iid=>$units){$snap['used'][$iid]=($snap['used'][$iid]??0)+$qty*$units;}
        foreach(array_unique(decode_options((string)$r['options_json'])) as $name){
            $iid=$snap['options'][$pid][$name]??null;
            if($iid!==null){$snap['used'][$iid]=($snap['used'][$iid]??0)+$qty;}
        }
    }
    foreach($snap['limits'] as $iid=>$limit){$snap['remaining'][$iid]=$limit-($snap['used'][$iid]??0);}
    return $snap;
}
function inventory_product_remaining(array $snap,int $epId,int $productId): ?int {
    if(!$snap['enabled'])return null;
    $available=isset($snap['product_limits'][$epId]) ? $snap['product_limits'][$epId]-($snap['product_used'][$epId]??0) : null;
    foreach($snap['recipe'][$productId]??[] as $iid=>$units){
        if(!array_key_exists($iid,$snap['limits']))continue;
        $component=(int)floor(($snap['remaining'][$iid]??0)/$units);
        $available=$available===null?$component:min($available,$component);
    }
    return $available===null?null:max(0,$available);
}
function inventory_option_remaining(array $snap,int $productId,string $option): ?int {
    if(!$snap['enabled'])return null;
    $iid=$snap['options'][$productId][$option]??null;
    if($iid===null||!array_key_exists($iid,$snap['limits']))return null;
    return max(0,$snap['remaining'][$iid]??0);
}
function inventory_check_order(array $snap,array $chosen,array $allowed): ?string {
    if(!$snap['enabled'])return null;
    $required=[];$prodRequired=[];
    foreach($chosen as $row){
        [$ep,$qty,$doneness,$options]=$row;
        $pid=(int)$allowed[$ep]['id'];
        $prodRequired[$ep]=($prodRequired[$ep]??0)+$qty;
        foreach($snap['recipe'][$pid]??[] as $iid=>$units){$required[$iid]=($required[$iid]??0)+$qty*$units;}
        foreach(array_unique($options) as $opt){
            $iid=$snap['options'][$pid][$opt]??null;
            if($iid!==null){$required[$iid]=($required[$iid]??0)+$qty;}
        }
    }
    foreach($prodRequired as $ep=>$qty){
        if(isset($snap['product_limits'][$ep]) && $qty>($snap['product_limits'][$ep]-($snap['product_used'][$ep]??0)))
            return (string)$allowed[$ep]['name'];
    }
    foreach($required as $iid=>$amount){
        if(array_key_exists($iid,$snap['limits']) && $amount>($snap['remaining'][$iid]??0)){
            $q=db()->prepare('SELECT name FROM ingredients WHERE id=?');$q->execute([$iid]);return (string)($q->fetchColumn()?:'Ingredient');
        }
    }
    return null;
}
function inventory_row_inputs(string $kind,int $id,?int $value): string {
    return '<input class="inventory-quantity" type="number" inputmode="numeric" min="0" max="100000" name="'.$kind.'['.$id.']" value="'.h($value===null?'':$value).'" placeholder="∞" aria-label="'.h(t('inventory_stock')).'">';
}
function page_admin_inventory(): void {
    require_admin();$pdo=db();$eventId=(int)($_GET['id']??0);$event=get_event($eventId);if(!$event)redirect_to('admin');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();$enabled=isset($_POST['inventory_enabled'])?1:0;
        $ingredientInput=is_array($_POST['ingredient_stock']??null)?$_POST['ingredient_stock']:[];
        $productInput=is_array($_POST['product_stock']??null)?$_POST['product_stock']:[];
        $validate=function($values){$result=[];foreach($values as $id=>$val){$id=(int)$id;$str=trim((string)$val);if($id<=0)continue;
            if($str===''){$result[$id]=null;continue;}
            if(!preg_match('/^\d{1,6}$/',$str)|| (int)$str>100000)throw new InvalidArgumentException(t('stock_invalid'));
            $result[$id]=(int)$str;
        }return $result;};
        try{$ingredientInput=$validate($ingredientInput);$productInput=$validate($productInput);}catch(InvalidArgumentException $e){flash($e->getMessage(),'bad');redirect_to('admin_inventory',['id'=>$eventId]);}
        $pdo->beginTransaction();
        try{
            $pdo->prepare('UPDATE events SET inventory_enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$enabled,$eventId]);
            $pdo->prepare('DELETE FROM event_inventory WHERE event_id=?')->execute([$eventId]);
            foreach($ingredientInput as $iid=>$stock){if($stock!==null)$pdo->prepare('INSERT INTO event_inventory(event_id,ingredient_id,stock_quantity) SELECT ?,id,? FROM ingredients WHERE id=?')->execute([$eventId,$stock,$iid]);}
            foreach($productInput as $ep=>$stock){$pdo->prepare('UPDATE event_products SET stock_limit=? WHERE id=? AND event_id=?')->execute([$stock,$ep,$eventId]);}
            $pdo->commit();flash(t('inventory_saved'));
        }catch(Throwable $e){$pdo->rollBack();throw $e;}
        redirect_to('admin_inventory',['id'=>$eventId]);
    }
    $snap=inventory_snapshot($eventId);
    $ingredients=$pdo->query('SELECT * FROM ingredients ORDER BY group_name,name')->fetchAll();
    $stmt=$pdo->prepare('SELECT ep.id,p.name,p.id AS product_id,ep.stock_limit FROM event_products ep JOIN products p ON p.id=ep.product_id WHERE ep.event_id=? ORDER BY p.name');$stmt->execute([$eventId]);$products=$stmt->fetchAll();
    render_header(t('inventory_title'));
    echo '<section class="section"><div class="section-title"><div><span class="eyebrow">BBQ STOCK CONTROL</span><h1>'.h(t('inventory_title')).'</h1><p class="muted">'.h($event['title']).'</p></div><a class="btn ghost" href="'.h(route('admin_event',['id'=>$eventId])).'">← '.h(t('back')).'</a></div>';admin_tabs();
    echo '<form method="post"><input type="hidden" name="csrf" value="'.h(csrf_token()).'"><div class="card inventory-toggle"><div><h2>'.h(t('inventory_enable')).'</h2><p class="muted">'.h(t('inventory_hint')).'</p></div><label class="inventory-switch"><input type="checkbox" name="inventory_enabled" '.($snap['enabled']?'checked':'').'> '.h(t('active')).'</label></div>';
    echo '<div class="grid cols-2 inventory-admin-grid"><div class="card"><h3>'.h(t('inventory_ingredients')).'</h3><p class="muted">'.h(t('inventory_blank')).'</p><div class="inventory-list">';
    foreach($ingredients as $ing){$iid=(int)$ing['id'];$limit=$snap['limits'][$iid]??null;$used=$snap['used'][$iid]??0;$remaining=$limit===null?null:$limit-$used;
        echo '<label class="inventory-editor-row"><span><strong>'.h($ing['name']).'</strong><small>'.h($ing['group_name']).' · '.h(t('inventory_used')).': '.$used.($remaining===null?'':' · '.h(t('inventory_remaining')).': '.$remaining).'</small></span>'.inventory_row_inputs('ingredient_stock',$iid,$limit).'</label>';}
    echo '</div></div><div class="card"><h3>'.h(t('inventory_products')).'</h3><p class="muted">'.h(t('inventory_product_hint')).'</p><div class="inventory-list">';
    foreach($products as $prod){$ep=(int)$prod['id'];$cap=$snap['product_limits'][$ep]??null;$rem=inventory_product_remaining($snap,$ep,(int)$prod['product_id']);
        echo '<label class="inventory-editor-row"><span><strong>'.h($prod['name']).'</strong><small>'.h(t('inventory_ordered')).': '.(int)($snap['product_used'][$ep]??0).($rem===null?'':' · '.h(t('inventory_remaining')).': '.$rem).'</small></span>'.inventory_row_inputs('product_stock',$ep,$cap).'</label>';}
    echo '</div></div></div><div class="inventory-savebar"><button class="btn primary" type="submit">'.h(t('save')).' · '.h(t('inventory_title')).'</button></div></form></section>';render_footer();
}

function page_home(): void {
    $pdo = db();
    $events = $pdo->query("SELECT * FROM events WHERE status = 'public' ORDER BY COALESCE(event_date,'9999-12-31'), created_at DESC")->fetchAll();
    render_header();
    echo '<section class="hero"><div><span class="eyebrow">SCAN · ORDER · ENJOY</span><h1>' . h(t('hero_title')) . '</h1><p>' . h(t('hero_subtitle')) . '</p><div class="split-actions"><a class="btn primary" href="' . h(route(is_admin()?'admin':'admin_login')) . '">' . h(t('admin')) . '</a><a class="btn ghost" href="#events">' . h(t('open_events')) . '</a></div></div><div class="hero-card"><img src="assets/hero-grill.svg" alt=""></div></section>';
    echo '<section id="events" class="section"><div class="section-title"><h2>' . h(t('open_events')) . '</h2></div>';
    if (!$events) { echo '<div class="empty">' . h(t('no_public_events')) . '</div>'; }
    else {
        echo '<div class="public-events-grid">';
        foreach($events as $e){
            $eventDay=(string)($e['event_date']??'');$today=$eventDay===date('Y-m-d');$forecast=$eventDay?weather_day($eventDay):null;
            $formattedDate=$eventDay?(date('d.m.Y',strtotime($eventDay))):'—';
            echo '<article class="public-event-card"><div class="public-event-header"><span class="event-badge">'.($today?'● '.h(t('inventory_today')):'✦ '.h(t('public'))).'</span><span class="public-event-id">BBQ · '.h(strtoupper(substr($e['slug'],0,18))).'</span></div>';
            echo '<div class="public-event-main"><div class="event-visual"><img src="assets/hero-grill.svg" alt="" loading="lazy"></div><div><div class="public-event-date">📅 '.h($formattedDate).'</div><h3>'.h($e['title']).'</h3>';
            if($e['description'])echo '<p class="public-event-description">'.nl2br(h($e['description'])).'</p>';
            echo '</div></div>';
            if($forecast){
                echo '<div class="public-weather"><div class="weather-overview"><span class="weather-glyph" aria-hidden="true">'.h($forecast['icon']).'</span><div><small>'.h(t('weather_forecast')).'</small><strong>'.h($forecast['min']).'–'.h($forecast['max']).'°C</strong></div></div><div class="weather-mini-metrics"><div><span>☔</span><b>'.h($forecast['rain']).'%</b><small>'.h(t('inventory_rain')).'</small></div><div><span>🍃</span><b>'.h($forecast['wind']).' km/h</b><small>'.h(t('inventory_wind')).'</small></div><div><span>☀️</span><b>'.h($forecast['sun']).' h</b><small>'.h(t('inventory_sun')).'</small></div></div></div>';
            }elseif($eventDay){echo '<div class="public-weather-unavailable">☁ '.h(t('forecast_unavailable')).'</div>';}
            echo '<a class="public-event-cta" href="'.h(route('event',['slug'=>$e['slug']])).'"><span>🍽️ '.h(t('join_event')).'</span><span aria-hidden="true">→</span></a></article>';
        }
        echo '</div>';
    }
    echo '</section>';
    render_footer();
}

function page_setup(): void {
    if (!setup_required()) { redirect_to('admin_login'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $username=trim((string)($_POST['username']??'Griller'));
        $password=(string)($_POST['password']??'');
        if (preg_match('/^[\p{L}\p{N}_.-]{3,32}$/u',$username) && preg_match_all('/./us',$password)>=6 && hash_equals($password,(string)($_POST['password_confirm']??''))) {
            put_setting('admin_username',$username);
            put_setting('admin_hash',password_hash($password,PASSWORD_DEFAULT));
            put_setting('theme',in_array($_POST['theme']??'', ['ember','smoke','garden','nordic'],true)?$_POST['theme']:'ember');
            $_SESSION['admin']=true;
            file_put_contents(DATA_DIR.'/admin-configured','1');
            session_regenerate_id(true);
            redirect_to('admin');
        }
        flash(t('setup_invalid'),'bad'); redirect_to('setup');
    }
    render_header(t('setup_title'));
    echo '<section class="section setup-page"><div class="card setup-card"><span class="eyebrow">HOMELAB GRILLER · V2</span><h1>' . h(t('setup_title')) . '</h1><p class="muted">' . h(t('setup_hint')) . '</p><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><div class="field"><label>' . h(t('username')) . '</label><input required name="username" value="Griller" minlength="3" maxlength="32"></div><div class="field"><label>' . h(t('password')) . ' (6+)</label><input required minlength="6" type="password" name="password" autocomplete="new-password"></div><div class="field"><label>' . h(t('confirm_password')) . '</label><input required minlength="6" type="password" name="password_confirm" autocomplete="new-password"></div><div class="field"><label>' . h(t('theme')) . '</label><select name="theme"><option value="ember">Ember</option><option value="smoke">Smokehouse</option><option value="garden">Summer Garden</option><option value="nordic">Nordic</option></select></div><button class="primary" type="submit">' . h(t('finish_setup')) . '</button></form></div></section>';
    render_footer();
}
function page_admin_login(): void {
    if (is_admin()) { redirect_to('admin'); }
    if (setup_required()) { redirect_to('setup'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $u=trim((string)($_POST['username']??'')); $p=(string)($_POST['password']??'');
        if (hash_equals(admin_username(),$u) && admin_password_valid($p)) {
            session_regenerate_id(true); $_SESSION['admin']=true; redirect_to('admin');
        }
        flash(t('login_failed'),'bad'); redirect_to('admin_login');
    }
    render_header(t('login_title'));
    echo '<section class="section"><div class="grid cols-2 auth-layout"><div class="card"><span class="eyebrow">GRILL MASTER</span><h1>' . h(t('login_title')) . '</h1><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><div class="field"><label>' . h(t('username')) . '</label><input name="username" required value="' . h(admin_username()) . '" autocomplete="username"></div><div class="field"><label>' . h(t('password')) . '</label><input name="password" required type="password" autocomplete="current-password"></div><button class="primary" type="submit">' . h(t('login')) . '</button></form></div><div class="hero-card"><img src="assets/hero-grill.svg" alt=""></div></div></section>';
    render_footer();
}

function page_admin_logout(): void { unset($_SESSION['admin']); redirect_to('home'); }

function render_today_events(array $events): void {
    $today=date('Y-m-d');$current=array_values(array_filter($events,static fn($event)=>$event['event_date']===$today && $event['status']!=='closed'));
    if(!$current)return;
    echo '<div class="today-events"><div class="section-title compact"><div><span class="eyebrow">TODAY · LIVE</span><h2>'.h(t('events_today')).'</h2></div><span class="pill public">'.count($current).'</span></div><div class="today-cards">';
    foreach($current as $e){
       echo '<a class="today-event-card" href="'.h(route('admin_kitchen',['id'=>$e['id']])).'"><span class="today-event-icon">🔥</span><span class="today-event-body"><strong>'.h($e['title']).'</strong><small>'.h($e['guest_count']).' '.h(t('guests')).' · '.h($e['item_count']).' '.h(t('items')).'</small>'.weather_day_badge($e['event_date']).'</span><span class="today-event-arrow">→</span></a>';
    }
    echo '</div></div>';
}

function page_admin(): void {
    require_admin();
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_event') {
        verify_csrf();
        $title = trim((string)($_POST['title'] ?? '')) ?: 'Grillevent';
        $slug = unique_slug($pdo, $title);
        $stmt = $pdo->prepare('INSERT INTO events(title,slug,event_date,description,status) VALUES(?,?,?,?,?)');
        $stmt->execute([$title, $slug, $_POST['event_date'] ?: null, trim((string)($_POST['description'] ?? '')), 'public']);
        $eventId = (int)$pdo->lastInsertId();
        foreach ($pdo->query('SELECT id, sort_order FROM products WHERE active = 1') as $p) {
            $pdo->prepare('INSERT INTO event_products(event_id,product_id,sort_order,active) VALUES(?,?,?,1)')->execute([$eventId, $p['id'], $p['sort_order']]);
        }
        flash(t('event_created'));
        redirect_to('admin_event', ['id' => $eventId]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clone_event') {
        verify_csrf();
        $id = (int)($_POST['id'] ?? 0);
        $event = get_event($id);
        if ($event) {
            $title = $event['title'] . ' Copy';
            $slug = unique_slug($pdo, $title);
            $stmt = $pdo->prepare('INSERT INTO events(title,slug,event_date,description,status) VALUES(?,?,?,?,?)');
            $stmt->execute([$title, $slug, $event['event_date'], $event['description'], 'draft']);
            $newId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE events SET inventory_enabled=? WHERE id=?')->execute([(int)$event['inventory_enabled'],$newId]);
            $stockCopy=$pdo->prepare('INSERT INTO event_inventory(event_id,ingredient_id,stock_quantity) SELECT ?,ingredient_id,stock_quantity FROM event_inventory WHERE event_id=?');
            $stockCopy->execute([$newId,$id]);
            $items = $pdo->prepare('SELECT * FROM event_products WHERE event_id = ?');
            $items->execute([$id]);
            foreach ($items->fetchAll() as $item) {
                $pdo->prepare('INSERT INTO event_products(event_id,product_id,sort_order,active,stock_limit) VALUES(?,?,?,?,?)')->execute([$newId, $item['product_id'], $item['sort_order'], $item['active'],$item['stock_limit']]);
            }
            flash(t('event_cloned'));
            redirect_to('admin_event', ['id' => $newId]);
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_event') {
        verify_csrf();
        $id = (int)($_POST['id'] ?? 0);
        $event = get_event($id);
        if ($event) {
            $pdo->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
            flash(t('event_deleted'));
        }
        redirect_to('admin');
    }
    $events = $pdo->query('SELECT e.*, COUNT(DISTINCT g.id) AS guest_count, COUNT(oi.id) AS item_count FROM events e LEFT JOIN guests g ON g.event_id=e.id LEFT JOIN orders o ON o.guest_id=g.id LEFT JOIN order_items oi ON oi.order_id=o.id GROUP BY e.id ORDER BY CASE WHEN e.event_date=date(\'now\',\'localtime\') THEN 0 ELSE 1 END, CASE WHEN e.event_date>=date(\'now\',\'localtime\') THEN 0 ELSE 1 END, e.event_date DESC,e.created_at DESC')->fetchAll();
    render_header(t('admin_dashboard'));
    $dashboardStats=$pdo->query("SELECT (SELECT COUNT(*) FROM guests) AS guests, (SELECT COUNT(*) FROM order_items WHERE status='pending') AS pending, (SELECT COUNT(*) FROM order_items WHERE status='in_progress') AS in_progress, (SELECT COUNT(*) FROM order_items WHERE status='done') AS done")->fetch();
    echo '<section class="section"><div class="section-title"><h2>' . h(t('admin_dashboard')) . '</h2></div>'; admin_tabs(); echo '<div class="queue-stats admin-kpis">';foreach(['guests'=>t('guests'),'pending'=>t('pending'),'in_progress'=>t('in_progress'),'done'=>t('done')] as $key=>$label){echo '<div class="queue-stat ' . h($key) . '"><span>' . h($label) . '</span><strong>' . h($dashboardStats[$key]) . '</strong></div>';}echo '</div>'; page_weather_widget();render_today_events($events); echo '<div class="grid cols-2"><div class="card"><h3>' . h(t('new_event')) . '</h3><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="create_event"><div class="field"><label>' . h(t('title')) . '</label><input name="title" required placeholder="Sommer BBQ"></div><div class="field"><label>' . h(t('event_date')) . '</label><input name="event_date" type="date" value="' . h(date('Y-m-d')) . '"></div><div class="field"><label>' . h(t('description')) . '</label><textarea name="description" placeholder="Hinweise für Gäste"></textarea></div><input class="primary" type="submit" value="' . h(t('create')) . '"></form></div><div class="card"><h3>' . h(t('events')) . '</h3><p class="muted">' . h(t('public_hint')) . '</p><p class="muted">' . h(t('guests_hint')) . '</p></div></div>';
    echo '<div class="section"><div class="table-wrap"><table><thead><tr><th>' . h(t('title')) . '</th><th>' . h(t('event_date')) . '</th><th>' . h(t('status')) . '</th><th>' . h(t('guests')) . '</th><th>' . h(t('actions')) . '</th></tr></thead><tbody>';
    foreach ($events as $e) {
        echo '<tr><td><a class="event-name-link" href="' . h(route('admin_kitchen',['id'=>$e['id']])) . '">' . h($e['title']) . ' →</a><br><span class="muted">/' . h($e['slug']) . '</span></td><td>' . h($e['event_date'] ?: '-') . weather_day_badge($e['event_date']) . '</td><td><span class="pill ' . h($e['status']) . '">' . h(status_label($e['status'])) . '</span></td><td><strong>' . h((string)$e['guest_count']) . '</strong><br><span class="muted">' . h(t('orders')) . ': ' . h((string)$e['item_count']) . '</span></td><td class="nowrap"><div class="split-actions"><a class="btn small primary" href="' . h(route('admin_event', ['id' => $e['id']])) . '">' . h(t('edit')) . '</a><a class="btn small green" href="' . h(route('admin_kitchen', ['id' => $e['id']])) . '">' . h(t('kitchen')) . '</a><a class="btn small ghost" href="' . h(route('admin_inventory',['id'=>$e['id']])) . '">📦 ' . h(t('inventory_title')) . '</a><form class="inline-form" method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="clone_event"><input type="hidden" name="id" value="' . h($e['id']) . '"><button class="btn small ghost" type="submit">' . h(t('clone')) . '</button></form><form class="inline-form" method="post" onsubmit="return confirm(\'' . h(t('confirm_delete_event')) . '\')"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="delete_event"><input type="hidden" name="id" value="' . h($e['id']) . '"><button class="btn small red" type="submit">' . h(t('delete')) . '</button></form></div></td></tr>';
    }
    echo '</tbody></table></div></div></section>';
    render_footer();
}

function get_event(int $id): ?array { $stmt = db()->prepare('SELECT * FROM events WHERE id = ?'); $stmt->execute([$id]); $e = $stmt->fetch(); return $e ?: null; }
function get_event_by_slug(string $slug): ?array { $stmt = db()->prepare('SELECT * FROM events WHERE slug = ?'); $stmt->execute([$slug]); $e = $stmt->fetch(); return $e ?: null; }

function page_admin_event(): void {
    require_admin();
    $pdo = db();
    $id = (int)($_GET['id'] ?? 0); $event = get_event($id); if (!$event) { redirect_to('admin'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = (string)($_POST['action'] ?? 'save_event');
        if ($action === 'delete_guest') {
            $guestId = (int)($_POST['guest_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM guests WHERE id = ? AND event_id = ?');
            $stmt->execute([$guestId, $id]);
            if ($stmt->rowCount() > 0) { flash(t('guest_deleted')); }
            redirect_to('admin_event', ['id' => $id]);
        }

        $title = trim((string)($_POST['title'] ?? '')) ?: $event['title'];
        $slug = unique_slug($pdo, $title, $id);
        $status = in_array($_POST['status'] ?? 'draft', ['draft','public','closed'], true) ? $_POST['status'] : 'draft';
        $pdo->prepare('UPDATE events SET title=?, slug=?, event_date=?, description=?, status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$title, $slug, $_POST['event_date'] ?: null, trim((string)($_POST['description'] ?? '')), $status, $id]);
        $selected = array_map('intval', $_POST['product_ids'] ?? []);
        $pdo->prepare('UPDATE event_products SET active = 0 WHERE event_id = ?')->execute([$id]);
        if ($selected) {
            $sort = 10;
            foreach ($selected as $pid) {
                $pdo->prepare('INSERT INTO event_products(event_id,product_id,sort_order,active) VALUES(?,?,?,1) ON CONFLICT(event_id, product_id) DO UPDATE SET sort_order=excluded.sort_order, active=1')->execute([$id, $pid, $sort]);
                $sort += 10;
            }
        }
        flash(t('event_saved'));
        redirect_to('admin_event', ['id' => $id]);
    }
    $selectedStmt = $pdo->prepare('SELECT product_id FROM event_products WHERE event_id = ? AND active = 1'); $selectedStmt->execute([$id]); $selected = array_map('intval', array_column($selectedStmt->fetchAll(), 'product_id'));
    $products = $pdo->query('SELECT p.*, c.name AS category_name, c.icon AS category_icon FROM products p JOIN categories c ON c.id=p.category_id ORDER BY c.sort_order, p.sort_order, p.name')->fetchAll();
    $guestStmt = $pdo->prepare('SELECT g.id, g.name, g.created_at, CASE WHEN g.profile_id IS NULL THEN 0 ELSE 1 END AS has_pin, COUNT(DISTINCT o.id) AS order_count, COUNT(oi.id) AS item_count FROM guests g LEFT JOIN orders o ON o.guest_id=g.id LEFT JOIN order_items oi ON oi.order_id=o.id WHERE g.event_id=? GROUP BY g.id ORDER BY g.created_at DESC, g.name ASC');
    $guestStmt->execute([$id]);
    $guests = $guestStmt->fetchAll();
    render_header($event['title']);
    echo '<section class="section"><div class="section-title"><h2>' . h($event['title']) . '</h2><a class="btn ghost" href="' . h(route('admin')) . '">' . h(t('back')) . '</a></div>'; admin_tabs();
    $event = get_event($id);
    echo '<div class="event-admin-tools"><a class="btn primary" href="'.h(route('admin_inventory',['id'=>$id])).'">📦 '.h(t('inventory_title')).'</a><a class="btn ghost" href="'.h(route('admin_kitchen',['id'=>$id])).'">🔥 '.h(t('kitchen')).'</a></div>';
    echo '<div class="grid cols-2"><div class="card"><h3>' . h(t('edit')) . '</h3><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="save_event"><div class="field"><label>' . h(t('title')) . '</label><input name="title" value="' . h($event['title']) . '" required></div><div class="form-row"><div class="field"><label>' . h(t('event_date')) . '</label><input name="event_date" type="date" value="' . h($event['event_date']) . '"></div><div class="field"><label>' . h(t('status')) . '</label><select name="status"><option value="draft" ' . ($event['status']==='draft'?'selected':'') . '>' . h(t('draft')) . '</option><option value="public" ' . ($event['status']==='public'?'selected':'') . '>' . h(t('public')) . '</option><option value="closed" ' . ($event['status']==='closed'?'selected':'') . '>' . h(t('closed')) . '</option></select></div></div><div class="field"><label>' . h(t('description')) . '</label><textarea name="description">' . h($event['description']) . '</textarea></div><h3>' . h(t('event_products')) . '</h3><div class="grid">';
    foreach ($products as $p) {
        $checked = in_array((int)$p['id'], $selected, true) ? 'checked' : '';
        echo '<label class="check-chip"><input type="checkbox" name="product_ids[]" value="' . h($p['id']) . '" ' . $checked . '> ' . h($p['category_icon'] . ' ' . $p['name']) . ' <span class="muted">(' . h($p['category_name']) . ')</span></label>';
    }
    $link = public_link($event);
    echo '</div><br><input class="primary" type="submit" value="' . h(t('save')) . '"></form></div><div class="card"><h3>' . h(t('public_link')) . '</h3><p class="muted">' . h(t('public_hint')) . '</p><div class="qr-wrap">' . qr_svg($link) . '</div><p class="muted">' . h(t('qr_url_hint')) . '</p><div class="copybox">' . h($link) . '</div><br><div class="split-actions"><a class="btn primary" href="' . h(route('event', ['slug' => $event['slug']])) . '">' . h(t('join_event')) . '</a><a class="btn green" href="' . h(route('admin_kitchen', ['id' => $event['id']])) . '">' . h(t('kitchen')) . '</a></div></div></div>';

    echo '<div class="section"><div class="card"><div class="section-title compact"><div><h3>' . h(t('guests')) . '</h3><p class="muted">' . h(t('guest_delete_hint')) . '</p></div><span class="pill public">' . h((string)count($guests)) . '</span></div>';
    if (!$guests) {
        echo '<div class="empty">' . h(t('no_guests')) . '</div>';
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>' . h(t('guest')) . '</th><th>' . h(t('joined_at')) . '</th><th>' . h(t('orders')) . '</th><th>' . h(t('actions')) . '</th></tr></thead><tbody>';
        foreach ($guests as $g) {
            echo '<tr><td><strong>' . h($g['name']) . '</strong>' . ((int)$g['has_pin'] ? '<br><span class="pill">PIN</span>' : '') . '</td><td>' . h($g['created_at']) . '</td><td><strong>' . h((string)$g['order_count']) . '</strong><br><span class="muted">' . h(t('items')) . ': ' . h((string)$g['item_count']) . '</span></td><td><form class="inline-form" method="post" onsubmit="return confirm(\'' . h(t('confirm_delete_guest')) . '\')"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="delete_guest"><input type="hidden" name="guest_id" value="' . h($g['id']) . '"><button class="btn small red" type="submit">' . h(t('delete_guest')) . '</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></div></section>';
    render_footer();
}

function page_admin_categories(): void {
    require_admin();
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = (string)($_POST['action'] ?? 'save_category');
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'sort_categories') {
            $ids = array_filter(array_map('intval', explode(',', (string)($_POST['order'] ?? ''))));
            $sort = 10;
            foreach ($ids as $catId) {
                $pdo->prepare('UPDATE categories SET sort_order=? WHERE id=?')->execute([$sort, $catId]);
                $sort += 10;
            }
            flash(t('sort_saved'));
            redirect_to('admin_categories');
        }

        if ($action === 'delete_category' && $id > 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
            $stmt->execute([$id]);
            $productCount = (int)$stmt->fetchColumn();
            if ($productCount > 0) {
                flash(t('cannot_delete_category_with_products'), 'bad');
            } else {
                $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
                flash(t('category_deleted'));
            }
            redirect_to('admin_categories');
        }

        $name = trim((string)($_POST['name'] ?? '')) ?: 'Kategorie';
        $icon = trim((string)($_POST['icon'] ?? '🔥')) ?: '🔥';
        if ($id > 0) {
            $pdo->prepare('UPDATE categories SET name=?, icon=? WHERE id=?')->execute([$name, $icon, $id]);
        } else {
            $nextSort = ((int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) + 10 FROM categories')->fetchColumn()) ?: 10;
            $pdo->prepare('INSERT INTO categories(name,icon,sort_order) VALUES(?,?,?)')->execute([$name, $icon, $nextSort]);
        }
        flash(t('category_saved'));
        redirect_to('admin_categories');
    }
    $categories=$pdo->query('SELECT * FROM categories ORDER BY sort_order,name')->fetchAll();
    render_header(t('categories'));echo '<section class="section"><div class="section-title"><div><span class="eyebrow">MENU LIBRARY</span><h1>' . h(t('categories')) . '</h1></div></div>';admin_tabs();
    echo '<div class="card"><h3>' . h(t('add_category')) . '</h3><form method="post" class="ingredient-add"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="save_category"><div class="field"><label>' . h(t('category_name')) . '</label><input name="name" required placeholder="Burgers"></div><div class="field"><label>' . h(t('icon')) . '</label><input name="icon" value="🍔"></div><button class="primary" type="submit">' . h(t('create')) . '</button></form></div>';
    echo '<div class="section-title compact" style="margin-top:28px"><div><h2>' . h(t('category_sorting')) . '</h2><small class="muted">' . h(t('drag_sort_hint')) . '</small></div><form method="post" onsubmit="document.getElementById(\'category-order\').value=sortableOrder(\'category-list\')"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="sort_categories"><input type="hidden" name="order" id="category-order"><button class="btn primary">' . h(t('save_order')) . '</button></form></div>';
    echo '<div id="category-list" class="admin-item-list">';
    foreach($categories as $category){
        echo '<div class="admin-sort-item" data-sort-id="' . h($category['id']) . '"><span class="drag-handle">☰</span><form method="post" class="category-edit-form"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="id" value="' . h($category['id']) . '"><input name="icon" class="icon-input" value="' . h($category['icon']) . '" required><input name="name" value="' . h($category['name']) . '" required><button class="btn small primary" name="action" value="save_category">' . h(t('save')) . '</button><button class="btn small red" name="action" value="delete_category" onclick="return confirm(' . h(json_encode(t('confirm_delete_category'))) . ')">' . h(t('delete')) . '</button></form></div>';
    }
    echo '</div></section>';render_sortable_script();echo '<script>initSortable("category-list","category-order");</script>';render_footer();
}

function ingredient_picker(int $productId=0):void {
    $q=db()->prepare('SELECT ingredient_id FROM product_ingredients WHERE product_id=?');$q->execute([$productId]);$selected=array_map('intval',array_column($q->fetchAll(),'ingredient_id'));
    $all=db()->query('SELECT * FROM ingredients WHERE active=1 ORDER BY group_name,name')->fetchAll();
    echo '<div class="field ingredient-picker"><label>' . h(t('ingredients')) . '</label><p class="muted">' . h(t('select_ingredients_hint')) . '</p><input type="hidden" name="ingredient_mode" value="1"><div class="option-grid">';
    foreach($all as $ingredient) {
        echo '<label class="check-chip"><input type="checkbox" name="ingredient_ids[]" value="' . h($ingredient['id']) . '" ' . (in_array((int)$ingredient['id'],$selected,true)?'checked':'') . '> ' . h($ingredient['name']) . ' <small class="muted">' . h($ingredient['group_name']) . '</small></label>';
    }
    echo '</div><small><a href="' . h(route('admin_ingredients')) . '">+ ' . h(t('manage_ingredients')) . '</a></small></div>';
}
function recipe_picker(int $productId=0): void {
    $q=db()->prepare('SELECT ingredient_id,units_per_item FROM product_recipes WHERE product_id=?');$q->execute([$productId]);$selected=[];
    foreach($q as $row){$selected[(int)$row['ingredient_id']]=(int)$row['units_per_item'];}
    $items=db()->query('SELECT id,name,group_name FROM ingredients WHERE active=1 ORDER BY group_name,name')->fetchAll();
    echo '<div class="recipe-picker"><h4>'.h(t('recipe_title')).'</h4><p class="muted">'.h(t('recipe_hint')).'</p><div class="recipe-grid">';
    foreach($items as $it){$id=(int)$it['id'];echo '<label class="recipe-entry"><span>'.h($it['name']).'</span><small>'.h($it['group_name']).'</small><input type="number" name="recipe['.$id.']" min="0" max="100" inputmode="numeric" value="'.h($selected[$id]??0).'" aria-label="'.h(t('recipe_units')).' '.h($it['name']).'"></label>';}
    echo '</div><small class="muted">'.h(t('recipe_zero_hint')).'</small></div>';
}
function page_admin_products(): void {
    require_admin();
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = (string)($_POST['action'] ?? 'save_product');
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'sort_products') {
            $ids = array_filter(array_map('intval', explode(',', (string)($_POST['order'] ?? ''))));
            $sort = 10;
            foreach ($ids as $productId) {
                $pdo->prepare('UPDATE products SET sort_order=? WHERE id=?')->execute([$sort, $productId]);
                $sort += 10;
            }
            flash(t('sort_saved'));
            redirect_to('admin_products');
        }

        if ($action === 'delete_product' && $id > 0) {
            // Explicitly destructive administrator action: remove related items as well.
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
            $pdo->exec('DELETE FROM orders WHERE NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=orders.id)');
            $pdo->commit();flash(t('product_deleted'));redirect_to('admin_products');
        }

        $name = trim((string)($_POST['name'] ?? '')) ?: 'Produkt';
        $categoryId = (int)($_POST['category_id'] ?? 1);
        $description = trim((string)($_POST['description'] ?? ''));
        $ingredientIds = array_map('intval', (array)($_POST['ingredient_ids'] ?? []));
        $options = [];
        if ($ingredientIds) {
            $marks=implode(',',array_fill(0,count($ingredientIds),'?'));
            $q=$pdo->prepare("SELECT name FROM ingredients WHERE id IN ($marks) AND active=1 ORDER BY group_name,name");
            $q->execute($ingredientIds); $options=array_column($q->fetchAll(),'name');
        } elseif (!isset($_POST['ingredient_mode']) && isset($_POST['options'])) {
            $options=array_values(array_filter(array_map('trim',explode(',',(string)$_POST['options']))));
        }
        $allowDoneness = isset($_POST['allow_doneness']) ? 1 : 0;
        $active = isset($_POST['active']) ? 1 : 0;
        if ($id > 0) {
            $pdo->prepare('UPDATE products SET category_id=?, name=?, description=?, options_json=?, allow_doneness=?, active=? WHERE id=?')->execute([$categoryId, $name, $description, json_encode($options, JSON_UNESCAPED_UNICODE), $allowDoneness, $active, $id]);
        } else {
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) + 10 FROM products WHERE category_id=?');
            $stmt->execute([$categoryId]);
            $nextSort = ((int)$stmt->fetchColumn()) ?: 10;
            $pdo->prepare('INSERT INTO products(category_id,name,description,options_json,allow_doneness,sort_order,active) VALUES(?,?,?,?,?,?,?)')->execute([$categoryId, $name, $description, json_encode($options, JSON_UNESCAPED_UNICODE), $allowDoneness, $nextSort, $active]);
        }
        if ($id <= 0) { $id=(int)$pdo->lastInsertId(); }
        if (isset($_POST['ingredient_mode'])) {
            $pdo->prepare('DELETE FROM product_ingredients WHERE product_id=?')->execute([$id]);
            foreach(array_unique($ingredientIds) as $iid) {
                $pdo->prepare('INSERT OR IGNORE INTO product_ingredients(product_id,ingredient_id) SELECT ?,id FROM ingredients WHERE id=?')->execute([$id,$iid]);
            }
        }
        $pdo->prepare('DELETE FROM product_recipes WHERE product_id=?')->execute([$id]);
        foreach((array)($_POST['recipe']??[]) as $ingredientId=>$num){
            $iid=(int)$ingredientId;$str=trim((string)$num);
            if($iid<=0 || !preg_match('/^\d{1,3}$/',$str) || (int)$str<=0 || (int)$str>100)continue;
            $pdo->prepare('INSERT OR IGNORE INTO product_recipes(product_id,ingredient_id,units_per_item) SELECT ?,id,? FROM ingredients WHERE id=?')->execute([$id,(int)$str,$iid]);
        }
        flash(t('product_saved'));
        redirect_to('admin_products');
    }
    $categories=$pdo->query('SELECT * FROM categories ORDER BY sort_order,name')->fetchAll();
    $products=$pdo->query('SELECT p.*,c.name AS category_name,c.icon AS category_icon FROM products p JOIN categories c ON c.id=p.category_id ORDER BY c.sort_order,p.sort_order,p.name')->fetchAll();
    render_header(t('products'));
    echo '<section class="section"><div class="section-title"><div><span class="eyebrow">MENU LIBRARY</span><h1>' . h(t('products')) . '</h1><p class="muted">' . h(t('select_ingredients_hint')) . '</p></div></div>';admin_tabs();
    echo '<details class="card editor-disclosure" open><summary>＋ ' . h(t('add_product')) . '</summary><form method="post" class="product-edit-form"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="save_product"><div class="form-row"><div class="field"><label>' . h(t('product_name')) . '</label><input name="name" required placeholder="PenguBurger"></div><div class="field"><label>' . h(t('category')) . '</label><select name="category_id">';
    foreach($categories as $c){echo '<option value="' . h($c['id']) . '">' . h($c['icon'].' '.$c['name']) . '</option>';}
    echo '</select></div></div><div class="field"><label>' . h(t('product_description')) . '</label><textarea name="description" placeholder="Smoky classic burger…"></textarea></div>';ingredient_picker();recipe_picker();
    echo '<div class="split-actions"><label class="check-chip"><input type="checkbox" name="allow_doneness">' . h(t('allow_doneness')) . '</label><label class="check-chip"><input type="checkbox" name="active" checked>' . h(t('active')) . '</label></div><br><button class="primary" type="submit">' . h(t('add_product')) . '</button></form></details>';
    echo '<div class="section-title compact" style="margin-top:30px"><div><h2>' . h(t('products')) . ' <span class="pill">' . h(count($products)) . '</span></h2><small class="muted">' . h(t('drag_sort_hint')) . '</small></div><form method="post" onsubmit="document.getElementById(\'product-order\').value=sortableOrder(\'product-list\')"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="sort_products"><input type="hidden" id="product-order" name="order"><button class="btn primary" type="submit">' . h(t('save_order')) . '</button></form></div>';
    echo '<div id="product-list" class="admin-item-list">';
    foreach($products as $product){
        echo '<div data-sort-id="' . h($product['id']) . '" class="admin-sort-item"><span class="drag-handle" title="' . h(t('drag_to_sort')) . '">☰</span><details class="editor-disclosure"><summary><span class="food-icon">' . h($product['category_icon']) . '</span><span><strong>' . h($product['name']) . '</strong><small class="muted">' . h($product['category_name']) . ' · ' . ((int)$product['active']?h(t('active')):h(t('closed'))) . '</small></span><span class="edit-arrow">⌄</span></summary><form method="post" class="product-edit-form"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="id" value="' . h($product['id']) . '"><div class="form-row"><div class="field"><label>' . h(t('product_name')) . '</label><input name="name" value="' . h($product['name']) . '" required></div><div class="field"><label>' . h(t('category')) . '</label><select name="category_id">';
        foreach($categories as $c){echo '<option value="' . h($c['id']) . '" ' . ((int)$c['id']===(int)$product['category_id']?'selected':'') . '>' . h($c['icon'].' '.$c['name']) . '</option>';}
        echo '</select></div></div><div class="field"><label>' . h(t('product_description')) . '</label><textarea name="description">' . h($product['description']) . '</textarea></div>';
        ingredient_picker((int)$product['id']);recipe_picker((int)$product['id']);
        echo '<div class="split-actions"><label class="check-chip"><input type="checkbox" name="allow_doneness" ' . ($product['allow_doneness']?'checked':'') . '>' . h(t('allow_doneness')) . '</label><label class="check-chip"><input type="checkbox" name="active" ' . ($product['active']?'checked':'') . '>' . h(t('active')) . '</label></div><div class="split-actions editor-buttons"><button class="btn primary" name="action" value="save_product" type="submit">' . h(t('save')) . '</button><button class="btn red" name="action" value="delete_product" type="submit" onclick="return confirm(' . h(json_encode(t('confirm_delete_product'))) . ')">' . h(t('delete')) . '</button></div></form></details></div>';
    }
    echo '</div><div class="notice warn">' . h(t('delete_product_warning')) . '</div></section>';
    render_sortable_script();echo '<script>initSortable("product-list","product-order");</script>';render_footer();
}

function page_admin_lang(): void {
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $code = strtolower(trim((string)($_POST['code'] ?? '')));
        $ok = preg_match('/^[a-z]{2,8}(-[a-z0-9]{2,8})?$/', $code) === 1 && isset($_FILES['json_file']) && is_uploaded_file($_FILES['json_file']['tmp_name']);
        if ($ok) {
            $raw = (string)file_get_contents($_FILES['json_file']['tmp_name']);
            $json = json_decode($raw, true);
            $ok = is_array($json);
            if ($ok) {
                foreach ($json as $k => $v) { if (!is_string($k) || (!is_string($v) && !is_numeric($v))) { $ok = false; break; } }
            }
            if ($ok) {
                file_put_contents(UPLOAD_LANG_DIR . '/' . $code . '.json', json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                flash(t('language_saved'));
                redirect_to('admin_lang');
            }
        }
        flash(t('invalid_language'), 'bad'); redirect_to('admin_lang');
    }
    render_header(t('language_packs'));
    echo '<section class="section"><div class="section-title"><h2>' . h(t('language_packs')) . '</h2></div>'; admin_tabs();
    echo '<div class="grid cols-2"><div class="card"><h3>' . h(t('upload_language')) . '</h3><p class="muted">' . h(t('upload_hint')) . '</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><div class="field"><label>' . h(t('language_code')) . '</label><input name="code" placeholder="fr" required></div><div class="field"><label>' . h(t('json_file')) . '</label><input name="json_file" type="file" accept="application/json,.json" required></div><input class="primary" type="submit" value="' . h(t('upload')) . '"></form></div><div class="card"><h3>JSON Beispiel</h3><pre class="copybox">{\n  "hero_title": "Mon super barbecue",\n  "join_event": "Participer"\n}</pre></div></div></section>';
    render_footer();
}

function page_admin_settings(): void {
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action=(string)($_POST['action']??'save');
        if ($action==='tls_upload') {
            $cert=$_FILES['tls_cert']??null;$key=$_FILES['tls_key']??null;
            if(!$cert||!$key||$cert['error']!==UPLOAD_ERR_OK||$key['error']!==UPLOAD_ERR_OK
              ||$cert['size']>262144||$key['size']>65536
              ||!is_uploaded_file($cert['tmp_name'])||!is_uploaded_file($key['tmp_name'])){
                flash(t('tls_upload_invalid'),'bad');redirect_to('admin_settings');
            }
            $certBytes=(string)file_get_contents($cert['tmp_name']);$keyBytes=(string)file_get_contents($key['tmp_name']);
            $x509=@openssl_x509_read($certBytes);$private=@openssl_pkey_get_private($keyBytes);
            if(!$x509 || !$private || !openssl_x509_check_private_key($x509,$private)){
                flash(t('tls_upload_invalid'),'bad');redirect_to('admin_settings');
            }
            $dir=DATA_DIR.'/tls'; if(!is_dir($dir))mkdir($dir,0700,true);
            $newCert=$dir.'/server.crt.tmp';$newKey=$dir.'/server.key.tmp';
            if(file_put_contents($newCert,$certBytes,LOCK_EX)===false||file_put_contents($newKey,$keyBytes,LOCK_EX)===false){
                flash(t('tls_upload_invalid'),'bad');redirect_to('admin_settings');
            }
            chmod($newCert,0600);chmod($newKey,0600);
            rename($newCert,$dir.'/server.crt');rename($newKey,$dir.'/server.key');
            flash(t('tls_upload_success'));redirect_to('admin_settings');
        }
        if ($action==='password') {
            if (!admin_password_valid((string)($_POST['current_password']??'')) || preg_match_all('/./us',(string)($_POST['new_password']??''))<6) {
                flash(t('password_error'),'bad'); redirect_to('admin_settings');
            }
            put_setting('admin_hash',password_hash((string)$_POST['new_password'],PASSWORD_DEFAULT));
            session_regenerate_id(true);
            flash(t('password_changed')); redirect_to('admin_settings');
        }
        put_setting('theme',in_array($_POST['theme']??'', ['ember','smoke','garden','nordic'],true)?$_POST['theme']:'ember');
        put_setting('default_language',in_array($_POST['default_language']??'',array_keys(available_langs()),true)?$_POST['default_language']:'de');
        foreach(['weather_lat','weather_lon'] as $key) {
            $v=trim((string)($_POST[$key]??''));
            if ($v!=='' && !is_numeric($v)) { $v=''; }
            if ($v!=='' && abs((float)$v)>($key==='weather_lat'?90:180)) { $v=''; }
            put_setting($key,$v);
        }
        put_setting('weather_city',substr(trim((string)($_POST['weather_city']??'')),0,80));
        put_setting('footer_text',substr(trim((string)($_POST['footer_text']??'')),0,400));
        put_setting('footer_show_version',isset($_POST['footer_show_version'])?'1':'0');
        $pages=[];foreach(['home','guest','admin','kitchen'] as $page){if(in_array($page,(array)($_POST['footer_pages']??[]),true))$pages[]=$page;}
        put_setting('footer_pages',implode(',',$pages));
        flash(t('settings_saved')); redirect_to('admin_settings');
    }
    render_header(t('settings')); echo '<section class="section"><div class="section-title"><h1>' . h(t('settings')) . '</h1></div>'; admin_tabs();
    echo '<div class="grid cols-2"><div class="card"><h2>' . h(t('appearance')) . '</h2><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="save"><div class="field"><label>' . h(t('theme')) . '</label><div class="theme-chooser">';
    foreach(['ember'=>'Ember','smoke'=>'Smokehouse','garden'=>'Summer Garden','nordic'=>'Nordic'] as $key=>$label){echo '<label class="theme-swatch ' . h($key) . '"><input type="radio" name="theme" value="' . h($key) . '" ' . (setting('theme','ember')===$key?'checked':'') . '><span class="swatch"></span><strong>' . h($label) . '</strong></label>';}
    echo '</div></div><div class="field"><label>' . h(t('default_language')) . '</label><select name="default_language">';
    foreach(available_langs() as $code=>$name){echo '<option value="' . h($code) . '" ' . (setting('default_language','de')===$code?'selected':'') . '>' . h($name) . '</option>';}
    echo '</select></div><h3>' . h(t('weather_widget')) . '</h3><p class="muted">' . h(t('weather_hint')) . '</p><div class="field"><label>' . h(t('weather_city')) . '</label><input id="weather-city" name="weather_city" value="' . h(setting('weather_city')) . '" placeholder="Munich"> <div class="place-search"><button type="button" class="btn ghost small" id="weather-city-search" data-url="' . h(route('weather_search_api')) . '">🔎 ' . h(t('search_city')) . '</button><div class="place-results" id="weather-place-results" aria-live="polite"></div></div></div><div class="form-row"><div class="field"><label>Latitude</label><input id="weather-lat" name="weather_lat" type="number" step="any" value="' . h(setting('weather_lat')) . '" placeholder="48.14"></div><div class="field"><label>Longitude</label><input id="weather-lon" name="weather_lon" type="number" step="any" value="' . h(setting('weather_lon')) . '" placeholder="11.58"></div></div><h3>' . h(t('footer_settings')) . '</h3><div class="field"><label>' . h(t('footer_text')) . '</label><textarea name="footer_text" rows="2" maxlength="400">' . h(setting('footer_text',t('footer'))) . '</textarea></div><label class="check-chip"><input type="checkbox" name="footer_show_version" ' . (setting('footer_show_version','0')==='1'?'checked':'') . '> ' . h(t('footer_show_version')) . '</label><p class="muted">' . h(t('footer_pages')) . '</p><div class="footer-page-options">';
    foreach(['home'=>t('home'),'guest'=>t('guests'),'admin'=>t('admin'),'kitchen'=>t('kitchen')] as $key=>$label){echo '<label class="check-chip"><input type="checkbox" name="footer_pages[]" value="' . h($key) . '" ' . (in_array($key,explode(',',setting('footer_pages','home,guest,admin,kitchen')),true)?'checked':'') . '> ' . h($label) . '</label>';}
    echo '</div><br><button class="primary" type="submit">' . h(t('save')) . '</button></form></div>';
    echo '<div class="card"><h2>' . h(t('admin_access')) . '</h2><p class="muted">' . h(t('current_username')) . ': <b>' . h(admin_username()) . '</b></p><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="password"><div class="field"><label>' . h(t('current_password')) . '</label><input name="current_password" type="password" required></div><div class="field"><label>' . h(t('new_password')) . ' (6+)</label><input name="new_password" type="password" minlength="6" required></div><button type="submit" class="primary">' . h(t('change_password')) . '</button></form></div></div></section>';
    echo '<div class="section"><div class="card"><h2>🔒 ' . h(t('tls_title')) . '</h2><p class="muted">' . h(t('tls_hint')) . '</p>';
    $uploaded=is_file(DATA_DIR.'/tls/server.crt');
    echo '<p class="pill ' . ($uploaded?'public':'draft') . '">' . h($uploaded?t('tls_uploaded'):t('tls_default')) . '</p>';
    echo '<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="tls_upload"><div class="grid cols-2"><div class="field"><label>' . h(t('tls_cert')) . '</label><input type="file" name="tls_cert" accept=".pem,.crt,.txt" required></div><div class="field"><label>' . h(t('tls_key')) . '</label><input type="file" name="tls_key" accept=".pem,.key,.txt" required></div></div><button type="submit" class="primary">' . h(t('tls_upload')) . '</button></form><p class="muted tls-note">' . h(t('tls_restart')) . '</p></div></div>';
    render_footer();
}
function page_admin_ingredients(): void {
    require_admin(); $pdo=db();
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        verify_csrf(); $id=(int)($_POST['id']??0); $action=(string)($_POST['action']??'save');
        if ($action==='delete' && $id>0) { $pdo->prepare('DELETE FROM ingredients WHERE id=?')->execute([$id]); flash(t('ingredient_deleted')); redirect_to('admin_ingredients'); }
        $name=substr(trim((string)($_POST['name']??'')),0,90);
        $group=substr(trim((string)($_POST['group_name']??'')),0,60);
        if ($name!=='') {
            try {
                if ($id>0) {$pdo->prepare('UPDATE ingredients SET name=?,group_name=?,active=? WHERE id=?')->execute([$name,$group?:'Extras',isset($_POST['active'])?1:0,$id]);}
                else {$pdo->prepare('INSERT INTO ingredients(name,group_name,active) VALUES(?,?,1)')->execute([$name,$group?:'Extras']);}
                flash(t('ingredient_saved'));
            } catch (PDOException $e){flash(t('ingredient_duplicate'),'bad');}
        }
        redirect_to('admin_ingredients');
    }
    $ingredients=$pdo->query('SELECT i.*,COUNT(pi.product_id) AS product_count FROM ingredients i LEFT JOIN product_ingredients pi ON pi.ingredient_id=i.id GROUP BY i.id ORDER BY i.group_name,i.name')->fetchAll();
    render_header(t('ingredients')); echo '<section class="section"><div class="section-title"><div><span class="eyebrow">MENU LIBRARY</span><h1>' . h(t('ingredients')) . '</h1><p class="muted">' . h(t('ingredient_hint')) . '</p></div></div>'; admin_tabs();
    echo '<div class="card"><h3>' . h(t('add_ingredient')) . '</h3><form class="ingredient-add" method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><div class="field"><label>' . h(t('name')) . '</label><input name="name" placeholder="Cheddar" required></div><div class="field"><label>' . h(t('group')) . '</label><input name="group_name" list="ingredient-groups" placeholder="Toppings" required><datalist id="ingredient-groups"><option value="Toppings"><option value="Sauces"><option value="Buns"><option value="Extras"></datalist></div><button class="primary" type="submit">' . h(t('create')) . '</button></form></div>';
    echo '<div class="ingredient-list">';
    foreach($ingredients as $it){echo '<form method="post" class="ingredient-row"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="id" value="' . h($it['id']) . '"><input name="name" value="' . h($it['name']) . '" required><input name="group_name" value="' . h($it['group_name']) . '" required><label class="check-chip"><input type="checkbox" name="active" ' . ($it['active']?'checked':'') . '> ' . h(t('active')) . '</label><small class="muted">' . h($it['product_count']) . ' ' . h(t('products')) . '</small><button class="btn small ghost" name="action" value="save">' . h(t('save')) . '</button><button class="btn small red" name="action" value="delete" onclick="return confirm(' . h(json_encode(t('confirm_delete_ingredient'))) . ')" >' . h(t('delete')) . '</button></form>';}
    echo '</div></section>'; render_footer();
}
function page_admin_guests(): void {
    require_admin();$pdo=db();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();$id=(int)($_POST['profile_id']??0);
        if($id>0){
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM guests WHERE profile_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM guest_profiles WHERE id=?')->execute([$id]);
            $pdo->commit();flash(t('profile_deleted'));
        }
        redirect_to('admin_guests');
    }
    $profiles=$pdo->query('SELECT gp.id,gp.name,gp.created_at,COUNT(DISTINCT g.event_id) AS event_count,COUNT(DISTINCT o.id) AS order_count FROM guest_profiles gp LEFT JOIN guests g ON g.profile_id=gp.id LEFT JOIN orders o ON o.guest_id=g.id GROUP BY gp.id ORDER BY gp.created_at DESC')->fetchAll();
    render_header(t('guests'));echo '<section class="section"><div class="section-title"><div><span class="eyebrow">GUEST MANAGEMENT</span><h1>' . h(t('guest_profiles')) . '</h1><p class="muted">' . h(t('guest_profile_hint')) . '</p></div></div>';admin_tabs();
    if(!$profiles){echo '<div class="empty">' . h(t('no_guests')) . '</div>';}
    else {echo '<div class="admin-item-list">';foreach($profiles as $pr){echo '<div class="admin-sort-item"><div style="flex:1"><strong>' . h($pr['name']) . '</strong><p class="muted">' . h($pr['event_count']) . ' ' . h(t('events')) . ' · ' . h($pr['order_count']) . ' ' . h(t('orders')) . '</p></div><form method="post" onsubmit="return confirm(' . h(json_encode(t('confirm_delete_profile'))) . ')"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="profile_id" value="' . h($pr['id']) . '"><button class="btn red small" type="submit">' . h(t('delete')) . '</button></form></div>';}echo '</div>';}
    echo '</section>';render_footer();
}
function weather_data(): ?array {
    static $loaded=false,$weather=null;
    if($loaded) return $weather;
    $loaded=true;
    $lat=setting('weather_lat'); $lon=setting('weather_lon');
    if(!is_numeric($lat)||!is_numeric($lon)||(float)$lat>90||(float)$lat< -90||(float)$lon>180||(float)$lon< -180)return null;
    $cache=DATA_DIR.'/weather-cache-v2.json';$raw='';
    $coordinates=round((float)$lat,5).','.round((float)$lon,5);
    if(is_file($cache)){
        $saved=json_decode((string)file_get_contents($cache),true);
        if(is_array($saved)&&($saved['_coordinates']??'')===$coordinates){
            $weather=$saved;
            if(time()-filemtime($cache)<3600)return $weather;
        }
    }
    $retry=DATA_DIR.'/weather-last-attempt';
    // Avoid a stampede of outbound calls when the weather service is unavailable.
    if(is_file($retry) && time()-filemtime($retry)<300)return $weather;
    @file_put_contents($retry,(string)time(),LOCK_EX);
    $url='https://api.open-meteo.com/v1/forecast?latitude='.rawurlencode($lat).'&longitude='.rawurlencode($lon)
       .'&current=temperature_2m,wind_speed_10m,precipitation,weather_code'
       .'&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max,wind_speed_10m_max,sunshine_duration,weather_code'
       .'&hourly=temperature_2m,precipitation_probability,wind_speed_10m,weather_code'
       .'&timezone=auto&forecast_days=16';
    $ctx=stream_context_create(['http'=>['timeout'=>4,'user_agent'=>'HomeLabGriller/2.0']]);
    $fresh=@file_get_contents($url,false,$ctx);
    $value=$fresh?json_decode($fresh,true):null;
    if(is_array($value)&&isset($value['daily']['time'],$value['current'])){
        $value['_coordinates']=$coordinates;
        $weather=$value;
        @file_put_contents($cache,json_encode($value),LOCK_EX);
    }
    return $weather;
}
function weather_icon(int $code): string {
    if($code===0)return '☀️';
    if($code<=3)return '⛅';
    if($code<=48)return '🌫️';
    if($code<=67)return '🌧️';
    if($code<=77)return '🌨️';
    if($code<=82)return '🌦️';
    return '⛈️';
}
function weather_day(string $day): ?array {
    $w=weather_data();
    if(!$w || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$day))return null;
    $index=array_search($day,$w['daily']['time']??[],true);
    if($index===false)return null;
    $d=$w['daily'];
    return [
        'icon'=>weather_icon((int)($d['weather_code'][$index]??3)),
        'min'=>(int)round((float)($d['temperature_2m_min'][$index]??0)),
        'max'=>(int)round((float)($d['temperature_2m_max'][$index]??0)),
        'rain'=>(int)($d['precipitation_probability_max'][$index]??0),
        'wind'=>(int)round((float)($d['wind_speed_10m_max'][$index]??0)),
        'sun'=>(int)round((float)($d['sunshine_duration'][$index]??0)/3600),
    ];
}
function weather_day_badge(?string $day,bool $withTitle=false): string {
    if(!$day || !($w=weather_day($day)))return '';
    $label=$withTitle?'<span class="muted">'.h(t('weather_forecast')).'</span> ':'';
    return '<div class="forecast-mini">'.$label.'<span aria-hidden="true">'.$w['icon'].'</span> <strong>'.$w['min'].'–'.$w['max'].'°C</strong> <span>☔ '.$w['rain'].'%</span> <span>🌬 '.$w['wind'].' km/h</span></div>';
}
function page_weather_widget(): void {
    $w=weather_data();if(!$w)return;
    $today=weather_day(date('Y-m-d'));$current=$w['current'];
    echo '<div class="weather-mini"><span class="weather-emoji">'.weather_icon((int)($current['weather_code']??3)).'</span><div><strong>'.h(setting('weather_city',t('weather_widget'))).'</strong><small>'.h(t('weather_widget')).' · '.h(t('weather_hourly_update')).'</small></div><strong class="weather-temp">'.h(round((float)($current['temperature_2m']??0))).'°</strong><small>'.h((int)round((float)($current['wind_speed_10m']??0))).' km/h · '.h($current['precipitation']??0).' mm'.($today?' · ☔ '.$today['rain'].'%':'').'</small></div>';
}
function page_event_weather(array $event): void {
    if(empty($event['event_date']) || !($day=weather_day($event['event_date'])))return;
    echo '<div class="card weather-event-card"><div class="section-title compact"><h3>'.h(t('weather_forecast')).' · '.h($event['event_date']).'</h3><span class="pill">'.h(setting('weather_city')).'</span></div>';
    echo '<div class="forecast-kpis"><span class="forecast-hero">'.$day['icon'].' '.$day['min'].'–'.$day['max'].'°C</span><span>☔ '.h($day['rain']).'%</span><span>🌬 '.h($day['wind']).' km/h</span><span>☀ '.h($day['sun']).' h</span></div>';
    $w=weather_data();$times=$w['hourly']['time']??[];
    echo '<div class="forecast-hours">';
    foreach($times as $i=>$dateTime){
        if(substr((string)$dateTime,0,10)!==$event['event_date'])continue;
        $hour=(int)substr((string)$dateTime,11,2);
        if($hour<10||$hour>22||($hour%2)!==0)continue;
        echo '<div class="forecast-hour"><strong>'.h(substr((string)$dateTime,11,5)).'</strong><span>'.weather_icon((int)($w['hourly']['weather_code'][$i]??3)).'</span><span>'.h(round((float)($w['hourly']['temperature_2m'][$i]??0))).'°</span><small>☔ '.h($w['hourly']['precipitation_probability'][$i]??0).'%</small><small>🌬 '.h(round((float)($w['hourly']['wind_speed_10m'][$i]??0))).'</small></div>';
    }
    echo '</div><small class="muted">Open-Meteo · '.h(t('weather_hourly_update')).'</small></div>';
}
function page_weather_search_api(): void {
    require_admin();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $term=trim((string)($_GET['q']??''));
    if(strlen($term)<2||strlen($term)>80){echo json_encode(['results'=>[]]);return;}
    $url='https://geocoding-api.open-meteo.com/v1/search?name='.rawurlencode($term).'&count=8&language=en&format=json';
    $ctx=stream_context_create(['http'=>['timeout'=>4,'user_agent'=>'HomeLabGriller/2.0']]);
    $raw=@file_get_contents($url,false,$ctx);
    $data=$raw?json_decode($raw,true):null;
    $results=[];
    foreach(($data['results']??[]) as $place){
        if(!isset($place['name'],$place['latitude'],$place['longitude']))continue;
        $results[]=['name'=>substr($place['name'].(!empty($place['admin1'])?', '.$place['admin1']:'').(!empty($place['country'])?', '.$place['country']:''),0,140),
            'lat'=>(float)$place['latitude'],'lon'=>(float)$place['longitude']];
    }
    echo json_encode(['results'=>$results],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}

function page_event(): void {
    $pdo=db(); $slug=(string)($_GET['slug']??''); $event=get_event_by_slug($slug);
    if (!$event || !in_array($event['status'],['public','closed'],true)) {
        render_header(); echo '<section class="section"><div class="empty">' . h(t('no_public_events')) . '</div></section>';render_footer();return;
    }
    $tokenKey='guest_token_'.$event['id'];
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='join') {
        verify_csrf(); $name=substr(trim((string)($_POST['name']??'')),0,80);
        $pin=trim((string)($_POST['pin']??''));
        if ($name==='' || ($pin!=='' && !preg_match('/^\\d{4}$/',$pin))) {
            flash(t('invalid_pin'),'bad');redirect_to('event',['slug'=>$slug]);
        }
        $profileId=null;
        if($pin!=='') {
            $profileId=find_or_create_guest_profile($pdo,$name,$pin);
            $q=$pdo->prepare('SELECT token FROM guests WHERE event_id=? AND profile_id=? LIMIT 1');
            $q->execute([$event['id'],$profileId]);$old=$q->fetchColumn();
            if($old) {$_SESSION[$tokenKey]=(string)$old;redirect_to('event',['slug'=>$slug]);}
        }
        $token=bin2hex(random_bytes(24));
        $pdo->prepare('INSERT INTO guests(event_id,profile_id,name,token) VALUES(?,?,?,?)')->execute([$event['id'],$profileId,$name,$token]);
        $_SESSION[$tokenKey]=$token; redirect_to('event',['slug'=>$slug]);
    }
    $guest=guest_for_event($event);
    if ($_SERVER['REQUEST_METHOD']==='POST' && $guest && $event['status']==='public') {
        verify_csrf(); $action=(string)($_POST['action']??'');
        if($action==='cancel_order') {
            $orderId=(int)($_POST['order_id']??0);
            $pdo->beginTransaction();
            if(order_eligible($orderId,$event,$guest)) {
                $pdo->prepare("UPDATE order_items SET status='cancelled',updated_at=CURRENT_TIMESTAMP WHERE order_id=?")->execute([$orderId]);
                $pdo->prepare("UPDATE orders SET status='cancelled',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$orderId]);
                flash(t('order_cancelled'));
            } else {flash(t('order_locked'),'bad');}
            $pdo->commit();redirect_to('event',['slug'=>$slug]);
        }
        if($action==='order') {
            $orderId=(int)($_POST['order_id']??0);
            $items=is_array($_POST['items']??null)?$_POST['items']:[];
            $available=event_menu_items((int)$event['id']);
            $allowed=[];foreach($available as $prod){$allowed[(int)$prod['event_product_id']]=$prod;}
            $chosen=[];
            foreach($items as $epId=>$data){
                $epId=(int)$epId;if(!isset($allowed[$epId])||!is_array($data)){continue;}
                $qty=max(0,min(20,(int)($data['quantity']??0)));if($qty===0){continue;}
                $prod=$allowed[$epId]; $options=decode_options($prod['options_json']);
                $asked=is_array($data['options']??null)?$data['options']:[];
                $selected=array_values(array_intersect($options,array_map('strval',$asked)));
                $doneness=(string)($data['doneness']??'');
                if(!(int)$prod['allow_doneness'] || !array_key_exists($doneness,doneness_options())){$doneness='';}
                $chosen[]=[$epId,$qty,$doneness,$selected,substr(trim((string)($data['note']??'')),0,300)];
            }
            if(!$chosen){flash(t('no_items_selected'),'bad');redirect_to('event',['slug'=>$slug]);}
            $pdo->exec('BEGIN IMMEDIATE');
            if($orderId>0) {
                if(!order_eligible($orderId,$event,$guest)){$pdo->exec('ROLLBACK');flash(t('order_locked'),'bad');redirect_to('event',['slug'=>$slug]);}
                $pdo->prepare('DELETE FROM order_items WHERE order_id=?')->execute([$orderId]);
                $pdo->prepare("UPDATE orders SET status='pending',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$orderId]);
            } else {
                $pdo->prepare("INSERT INTO orders(event_id,guest_id,status) VALUES(?,?,'pending')")->execute([$event['id'],$guest['id']]);
                $orderId=(int)$pdo->lastInsertId();
            }
            $snap=inventory_snapshot((int)$event['id']);
            if($problem=inventory_check_order($snap,$chosen,$allowed)){
                $pdo->exec('ROLLBACK');flash(t('stock_conflict',['name'=>$problem]),'bad');redirect_to('event',['slug'=>$slug]);
            }
            $q=$pdo->prepare("INSERT INTO order_items(order_id,event_product_id,quantity,doneness,options_json,note,status,stock_recorded) VALUES(?,?,?,?,?,?,'pending',?)");
            $componentInsert=$pdo->prepare('INSERT INTO order_item_components(item_id,ingredient_id,units_used) VALUES(?,?,?)');
            foreach($chosen as $row){
                $q->execute([$orderId,$row[0],$row[1],$row[2],json_encode($row[3],JSON_UNESCAPED_UNICODE),$row[4],$snap['enabled']?1:0]);
                $itemId=(int)$pdo->lastInsertId();$pid=(int)$allowed[$row[0]]['id'];$needed=[];
                foreach($snap['recipe'][$pid]??[] as $iid=>$units){$needed[$iid]=($needed[$iid]??0)+$row[1]*$units;}
                foreach(array_unique($row[3]) as $opt){$iid=$snap['options'][$pid][$opt]??null;if($iid!==null)$needed[$iid]=($needed[$iid]??0)+$row[1];}
                if($snap['enabled'])foreach($needed as $iid=>$amount){$componentInsert->execute([$itemId,$iid,$amount]);}
            }
            $pdo->exec('COMMIT');$_SESSION['order_sent']=true;
            flash(t('order_saved'));redirect_to('event',['slug'=>$slug,'saved'=>1]);
        }
    }
    render_header($event['title']);
    echo '<section class="section guest-shell"><div class="event-title"><span class="eyebrow">' . h(t('guest_experience')) . '</span><h1>' . h($event['title']) . '</h1><p class="muted">' . h($event['event_date']??'') . ' · ' . h($event['description']) . '</p></div>';
    if($event['status']==='closed'){echo '<div class="notice warn">' . h(t('closed_hint')) . '</div>';}
    if (!$guest) {
        echo '<div class="card join-card"><div class="join-art"><img src="assets/hero-grill.svg" alt=""></div><div class="join-content"><span class="eyebrow">01 / ' . h(t('welcome')) . '</span><h2>' . h(t('join_event')) . '</h2><p class="muted">' . h(t('join_hint')) . '</p><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="join"><div class="field"><label>' . h(t('guest_name')) . '</label><input name="name" required maxlength="80" autofocus autocomplete="name" placeholder="Michi"></div><div class="field"><label>' . h(t('guest_pin')) . '</label><input name="pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" type="password" autocomplete="off" placeholder="••••"><small class="muted">' . h(t('guest_pin_hint')) . '</small></div><button class="primary btn-wide" type="submit">' . h(t('continue')) . ' →</button></form></div></div>';
    } else {
        echo '<div class="guest-greeting"><div><span class="eyebrow">' . h(t('welcome')) . '</span><h2>' . h($guest['name']) . ' 👋</h2></div><span class="pill public">' . h(t('joined')) . '</span></div>';
        echo '<div id="guest-order-status" data-poll-url="' . h(route('guest_status_api',['slug'=>$slug])) . '">';show_guest_status($event,$guest);echo '</div>';
        if($event['status']==='public') {
            $editId=(int)($_GET['edit']??0);
            if($editId>0 && !order_eligible($editId,$event,$guest)){$editId=0;flash(t('order_locked'),'bad');}
            show_guest_order_form($event,$guest,$editId);
        }
    }
    echo '</section>';render_footer();
}
function page_guest_inventory_api(): void {
    $event=get_event_by_slug((string)($_GET['slug']??''));
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private,no-store');
    if(!$event || !guest_for_event($event)){http_response_code(403);echo '{}';return;}
    $orderId=max(0,(int)($_GET['edit']??0));$guest=guest_for_event($event);
    if($orderId && !$guest || ($orderId && !order_eligible($orderId,$event,$guest)))$orderId=0;
    $snap=inventory_snapshot((int)$event['id'],$orderId);$result=['enabled'=>$snap['enabled'],'products'=>[]];
    foreach(event_menu_items((int)$event['id']) as $prod){
        $ep=(int)$prod['event_product_id'];$id=(int)$prod['id'];$options=[];
        foreach(decode_options($prod['options_json']) as $opt){$options[$opt]=inventory_option_remaining($snap,$id,$opt);}
        $result['products'][(string)$ep]=['remaining'=>inventory_product_remaining($snap,$ep,$id),'options'=>$options];
    }
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
function page_guest_status_api(): void {
    $event=get_event_by_slug((string)($_GET['slug']??''));
    if(!$event){http_response_code(404);exit;}
    $guest=guest_for_event($event);
    if(!$guest){http_response_code(403);exit;}
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    show_guest_status($event,$guest);
}

function event_menu_items(int $eventId): array {
    $stmt = db()->prepare('SELECT ep.id AS event_product_id, p.*, c.name AS category_name, c.icon AS category_icon FROM event_products ep JOIN products p ON p.id=ep.product_id JOIN categories c ON c.id=p.category_id WHERE ep.event_id=? AND ep.active=1 AND p.active=1 ORDER BY c.sort_order, ep.sort_order, p.sort_order, p.name');
    $stmt->execute([$eventId]); return $stmt->fetchAll();
}
function show_guest_order_form(array $event,array $guest,int $editId=0):void {
    $items=event_menu_items((int)$event['id']);
    if(!$items){return;}
    $previous=[];
    $snap=inventory_snapshot((int)$event['id'],$editId);
    if($editId>0){$q=db()->prepare('SELECT * FROM order_items WHERE order_id=?');$q->execute([$editId]);foreach($q as $item){$previous[(int)$item['event_product_id']]=$item;}}
    $categories=[];foreach($items as $it){$key=$it['category_name'];if(!isset($categories[$key])){$categories[$key]=$it['category_icon'];}}
    echo '<section class="order-wizard card" id="order-wizard" data-event-id="' . h($event['id']) . '" data-edit="' . h($editId) . '" data-stock-message="' . h(t('stock_conflict_general')) . '" data-stock-template="' . h(t('stock_only_left')) . '" data-stock-out="' . h(t('sold_out')) . '" data-stock-url="' . h(route('guest_inventory_api',['slug'=>$event['slug'],'edit'=>$editId])) . '"><div class="wizard-head"><div><span class="eyebrow">' . h(t('your_menu')) . '</span><h2>' . h($editId?t('edit_order'):t('build_order')) . '</h2></div><span class="step-indicator" id="step-counter">1 / 3</span></div><div class="wizard-progress"><i id="wizard-progress"></i></div>';
    echo '<form method="post" id="guest-order-form" data-order-form="1" novalidate><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="order"><input type="hidden" name="order_id" value="' . h($editId) . '">';
    echo '<div class="wizard-panel" data-step="1"><h3>' . h(t('choose_category')) . '</h3><p class="muted">' . h(t('choose_category_hint')) . '</p><div class="category-pills">';
    foreach($categories as $label=>$icon){echo '<button class="category-pill" data-category="' . h($label) . '" type="button"><span>' . h($icon) . '</span><strong>' . h($label) . '</strong><span>→</span></button>';}
    echo '</div></div>';
    echo '<div class="wizard-panel" data-step="2" hidden><div class="wizard-row"><h3>' . h(t('select_and_customize')) . '</h3><button type="button" class="btn ghost small" id="choose-other-category">' . h(t('all_categories')) . '</button></div><p class="muted">' . h(t('tap_to_add')) . '</p><div class="menu-catalog">';
    foreach($items as $item){
        $id=(int)$item['event_product_id'];$pid=(int)$item['id'];$old=$previous[$id]??[];$optOld=$old?decode_options($old['options_json']):[];
        $remaining=inventory_product_remaining($snap,$id,$pid);
        $soldOut=$remaining!==null && $remaining<=0;
        $maxQty=$remaining===null?20:min(20,max(0,$remaining));
        echo '<article class="menu-product'.($soldOut?' sold-out':'').'" data-ep-id="'.$id.'" data-category="'.h($item['category_name']).'" data-product="'.h($item['name']).'">';
        echo '<div class="menu-product-top"><div class="food-icon">'.h($item['category_icon']).'</div><div class="food-info"><h4>'.h($item['name']).'</h4><p>'.h($item['description']).'</p>';
        if($remaining!==null){echo '<span class="stock-pill '.($soldOut?'sold':'few').'">'.h($soldOut?t('sold_out'):t('stock_only_left',['count'=>$remaining])).'</span>';}
        echo '</div><div class="qty-stepper"><button class="qty-btn" type="button" data-delta="-1" aria-label="Less">−</button><input class="qty-input" type="number" name="items['.$id.'][quantity]" min="0" max="'.$maxQty.'" value="'.h($old['quantity']??0).'" inputmode="numeric" '.($soldOut?'disabled':'').'><button class="qty-btn" type="button" data-delta="1" aria-label="More" '.($soldOut?'disabled':'').'>+</button></div></div>';
        $opts=decode_options($item['options_json']);
        if($opts || (int)$item['allow_doneness']){echo '<div class="product-options"><div class="option-label">' . h(t('make_it_yours')) . '</div>';}
        if($opts){echo '<div class="option-grid">';foreach($opts as $opt){$optRem=inventory_option_remaining($snap,$pid,$opt);$optOut=$optRem!==null && $optRem<=0;
            echo '<label class="check-chip'.($optOut?' option-out':'').'"><input type="checkbox" name="items['.$id.'][options][]" value="'.h($opt).'" '.(in_array($opt,$optOld,true)?'checked':'').' '.($optOut||$soldOut?'disabled':'').' data-stock="'.h($optRem===null?'':(string)$optRem).'"><span>'.h($opt).'</span>'.($optOut?'<small>'.h(t('sold_out')).'</small>':'').'</label>';}
            echo '</div>';}

        if((int)$item['allow_doneness']){echo '<div class="field doneness-field"><label>' . h(t('doneness')) . '</label><select name="items[' . $id . '][doneness]"><option value="">—</option>';foreach(doneness_options() as $k=>$v){echo '<option value="' . h($k) . '" ' . (($old['doneness']??'')===$k?'selected':'') . '>' . h($v) . '</option>';}echo '</select></div>';}
        if($opts || (int)$item['allow_doneness']){echo '</div>';}
        echo '<div class="field notes-field"><label>' . h(t('note')) . '</label><input name="items[' . $id . '][note]" maxlength="300" value="' . h($old['note']??'') . '" placeholder="' . h(t('notes_placeholder')) . '"></div></article>';
    }
    echo '</div></div><div class="wizard-panel" data-step="3" hidden><h3>' . h(t('review_order')) . '</h3><p class="muted">' . h(t('review_hint')) . '</p><div id="order-review" class="order-review"></div></div>';
    echo '<div class="wizard-footer"><button type="button" class="btn ghost" id="wizard-back">← ' . h(t('back')) . '</button><span class="cart-counter" id="cart-counter">0 ' . h(t('items')) . '</span><button type="button" class="btn primary" id="wizard-next">' . h(t('continue')) . ' →</button><button type="submit" class="btn primary" id="wizard-submit" hidden>' . h($editId?t('save_changes'):t('send_order')) . '</button></div></form></section>';
}
function show_guest_status(array $event,array $guest):void {
    $q=db()->prepare('SELECT o.id AS oid,o.status AS order_status,o.created_at AS ordered_at,oi.id,oi.quantity,oi.doneness,oi.options_json,oi.note,oi.status,p.name AS product_name,c.icon AS category_icon FROM orders o JOIN order_items oi ON oi.order_id=o.id JOIN event_products ep ON ep.id=oi.event_product_id JOIN products p ON p.id=ep.product_id JOIN categories c ON c.id=p.category_id WHERE o.event_id=? AND o.guest_id=? ORDER BY o.id DESC,oi.id');
    $q->execute([$event['id'],$guest['id']]);$rows=$q->fetchAll();$orders=[];
    foreach($rows as $row){$oid=(int)$row['oid'];if(!isset($orders[$oid])){$orders[$oid]=['status'=>$row['order_status'],'created'=>$row['ordered_at'],'items'=>[]];}$orders[$oid]['items'][]=$row;}
    echo '<section class="status-section"><div class="section-title compact"><div><span class="eyebrow">LIVE STATUS</span><h2>' . h(t('my_orders')) . '</h2></div><span class="live-dot">' . h(t('live_updates')) . '</span></div>';
    if(!$orders){echo '<div class="empty compact-empty">' . h(t('no_orders_yet')) . '</div></section>';return;}
    foreach($orders as $id=>$order){$status=$order['status'];$activeItems=array_values(array_filter($order['items'],static fn($i)=>$i['status']!=='cancelled'));
        $readyCount=count(array_filter($activeItems,static fn($i)=>$i['status']==='done'));$totalCount=count($activeItems);
        echo '<article class="order-status-card status-' . h($status) . '" data-order-id="' . h($id) . '" data-order-status="' . h($status) . '" data-ready-count="' . h($readyCount) . '"><div class="order-card-head"><div><span class="eyebrow">#' . h(str_pad((string)$id,3,'0',STR_PAD_LEFT)) . ' · ' . h($order['created']) . '</span><h3>' . h(t($status)) . ' · ' . h(t('ready_progress',['ready'=>$readyCount,'total'=>$totalCount])) . '</h3></div><span class="order-status-icon">' . ($status==='done'?'✓':($status==='in_progress'?'♨':($status==='cancelled'?'×':'◷'))) . '</span></div><div class="order-items">';
        foreach($order['items'] as $it){$details=decode_options($it['options_json']);if($it['doneness']){$details[]=doneness_options()[$it['doneness']]??$it['doneness'];}if($it['note']){$details[]=$it['note'];}
            echo '<div class="order-item-line" data-item-status="' . h($it['status']) . '" data-item-name="' . h($it['quantity'] . '× ' . $it['product_name']) . '"><div><strong>' . h($it['quantity']) . '× ' . h($it['category_icon']) . ' ' . h($it['product_name']) . '</strong><small>' . h(implode(' · ',$details)) . '</small></div><span class="pill ' . h($it['status']) . '">' . h(t($it['status'])) . '</span></div>';
        }
        echo '</div>';
        if($status==='pending' && all_pending((int)$id) && $event['status']==='public'){
            echo '<div class="order-card-actions"><a class="btn ghost small" href="' . h(route('event',['slug'=>$event['slug'],'edit'=>$id])) . '">' . h(t('edit_order')) . '</a><form method="post" onsubmit="return confirm(' . h(json_encode(t('confirm_cancel'))) . ')"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="cancel_order"><input type="hidden" name="order_id" value="' . h($id) . '"><button type="submit" class="btn red small">' . h(t('cancel_order')) . '</button></form></div>';
        }
        echo '</article>';
    }
    echo '</section>';
}

function kitchen_items(int $eventId):array {
    $q=db()->prepare('SELECT oi.*,o.id AS order_number,g.name AS guest_name,p.name AS product_name,c.icon AS category_icon,o.created_at AS order_created FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN guests g ON g.id=o.guest_id JOIN event_products ep ON ep.id=oi.event_product_id JOIN products p ON p.id=ep.product_id JOIN categories c ON c.id=p.category_id WHERE o.event_id=? ORDER BY CASE oi.status WHEN "pending" THEN 1 WHEN "in_progress" THEN 2 WHEN "done" THEN 3 ELSE 4 END,oi.created_at ASC,oi.id ASC');
    $q->execute([$eventId]);return $q->fetchAll();
}
function render_kitchen_board(array $items):void {
    $lanes=['pending'=>[],'in_progress'=>[],'done'=>[],'cancelled'=>[]];foreach($items as $it){$lanes[$it['status']][]=$it;}
    echo '<div class="queue-stats">';
    foreach($lanes as $status=>$tickets){echo '<div class="queue-stat ' . h($status) . '"><span>' . h(t($status)) . '</span><strong>' . h(count($tickets)) . '</strong></div>';}
    echo '</div><div class="kitchen-board">';
    foreach($lanes as $status=>$tickets){echo '<section class="lane lane-' . h($status) . '" data-status-lane="' . h($status) . '"><div class="lane-head"><h3>' . h(t($status)) . '</h3><span class="pill ' . h($status) . '">' . h(count($tickets)) . '</span></div><div class="lane-content">';
        $limit=$status==='done'||$status==='cancelled'?30:100;
        foreach(array_slice($tickets,0,$limit) as $it){render_ticket($it);}
        if(count($tickets)>$limit){echo '<p class="muted">+' . h(count($tickets)-$limit) . ' ' . h(t('older_orders')) . '</p>';}
        echo '</div></section>';
    }
    echo '</div>';
}
function page_kitchen_api():void {
    require_admin();$id=(int)($_GET['id']??0);if(!get_event($id)){http_response_code(404);exit;}
    header('Content-Type: text/html; charset=utf-8');header('Cache-Control: private, no-store');render_kitchen_board(kitchen_items($id));
}
function page_kitchen_action_api(): void {
    require_admin();
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private,no-store');
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false]);return;}
    verify_csrf();$id=(int)($_GET['id']??0);$item=(int)($_POST['item_id']??0);$status=(string)($_POST['status']??'');
    if(!get_event($id)||!in_array($status,['pending','in_progress','done','cancelled'],true)){
        http_response_code(400);echo json_encode(['ok'=>false]);return;
    }
    $pdo=db();
    $q=$pdo->prepare('SELECT oi.order_id FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.id=? AND o.event_id=?');
    $q->execute([$item,$id]);$orderId=(int)$q->fetchColumn();
    if(!$orderId){http_response_code(404);echo json_encode(['ok'=>false]);return;}
    $pdo->beginTransaction();
    try{
        $pdo->prepare('UPDATE order_items SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,$item]);
        sync_order_status($orderId);$pdo->commit();
        echo json_encode(['ok'=>true,'item_id'=>$item,'status'=>$status]);
    }catch(Throwable $e){$pdo->rollBack();http_response_code(500);echo json_encode(['ok'=>false]);}
}
function page_admin_kitchen():void {
    require_admin();$pdo=db();$id=(int)($_GET['id']??0);$event=get_event($id);if(!$event){redirect_to('admin');}
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();$action=(string)($_POST['action']??'status');$itemId=(int)($_POST['item_id']??0);
        if($action==='delete_order') {
            $orderId=(int)($_POST['order_id']??0);
            $pdo->prepare('DELETE FROM orders WHERE id=? AND event_id=?')->execute([$orderId,$id]);
            flash(t('order_deleted'));redirect_to('admin_kitchen',['id'=>$id]);
        }
        $status=(string)($_POST['status']??'pending');
        if(in_array($status,['pending','in_progress','done','cancelled'],true)){
            $q=$pdo->prepare('SELECT oi.order_id FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.id=? AND o.event_id=?');$q->execute([$itemId,$id]);$orderId=(int)$q->fetchColumn();
            if($orderId){$pdo->prepare('UPDATE order_items SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,$itemId]);sync_order_status($orderId);}
        }
        redirect_to('admin_kitchen',['id'=>$id]);
    }
    render_header(t('kitchen'));echo '<section class="section kitchen-shell"><div class="section-title"><div><span class="eyebrow">KITCHEN DISPLAY</span><h1>' . h(t('kitchen')) . '</h1><p class="muted">' . h($event['title']) . '</p></div><div class="split-actions"><a href="' . h(route('admin_event',['id'=>$id])) . '" class="btn ghost">← ' . h(t('event_details')) . '</a><button class="btn ghost" id="kitchen-fullscreen" type="button">⛶ ' . h(t('fullscreen')) . '</button></div></div>';
    page_event_weather($event);
    echo '<div class="kitchen-inventory-link"><a class="btn ghost small" href="'.h(route('admin_inventory',['id'=>$id])).'">📦 '.h(t('inventory_title')).'</a></div>';
    echo '<div class="kitchen-toolbar" ><div class="field"><label>' . h(t('search_orders')) . '</label><input id="kitchen-search" type="search" placeholder="' . h(t('guest')) . ', #123, ' . h(t('products')) . '"></div><span class="live-dot">' . h(t('live_updates')) . '</span></div>';
    echo '<div id="kitchen-live-board" data-csrf="' . h(csrf_token()) . '" data-status-url="' . h(route('kitchen_action_api',['id'=>$id])) . '" data-poll-url="' . h(route('kitchen_api',['id'=>$id])) . '">';render_kitchen_board(kitchen_items($id));echo '</div></section>';render_footer();
}
function render_ticket(array $it):void {
    $opts=decode_options($it['options_json']);if($it['doneness']){$opts[]=doneness_options()[$it['doneness']]??$it['doneness'];}if($it['note']){$opts[]=$it['note'];}
    echo '<article class="ticket" draggable="true" data-item-id="' . h($it['id']) . '" data-search="' . h(strtolower($it['guest_name'].' '.$it['product_name'].' #'.$it['order_number'])) . '"><div class="ticket-head"><span class="eyebrow">#' . h($it['order_number']) . ' · ' . h($it['order_created']) . '</span><span class="pill ' . h($it['status']) . '">' . h(t($it['status'])) . '</span></div><button type="button" class="ticket-drag-handle" aria-label="' . h(t('move_ticket')) . '" title="' . h(t('move_ticket')) . '">⠿</button><h3>' . h($it['quantity']) . '× ' . h($it['category_icon']) . ' ' . h($it['product_name']) . '</h3><strong class="ticket-guest">' . h($it['guest_name']) . '</strong>';
    if($opts){echo '<p class="ticket-details">' . h(implode(' · ',$opts)) . '</p>';}
    echo '<div class="ticket-actions">';
    foreach(['pending'=>t('mark_pending'),'in_progress'=>t('mark_in_progress'),'done'=>t('mark_done')] as $status=>$label){if($it['status']===$status)continue;
        echo '<form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="item_id" value="' . h($it['id']) . '"><input type="hidden" name="status" value="' . h($status) . '"><button class="btn small ' . ($status==='done'?'green':($status==='in_progress'?'primary':'ghost')) . '" type="submit">' . h($label) . '</button></form>';
    }
    echo '<form method="post" onsubmit="return confirm(' . h(json_encode(t('confirm_delete_order'))) . ')"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="delete_order"><input type="hidden" name="order_id" value="' . h($it['order_number']) . '"><button class="btn red small" type="submit" title="' . h(t('delete')) . '">×</button></form></div></article>';
}

$route = (string)($_GET['r'] ?? 'home');
if (setup_required() && !in_array($route, ['setup'], true)) { redirect_to('setup'); }
try {
    match ($route) {
        'home' => page_home(),
        'setup' => page_setup(),
        'guest_status_api' => page_guest_status_api(),
        'guest_inventory_api' => page_guest_inventory_api(),
        'kitchen_api' => page_kitchen_api(),
        'kitchen_action_api' => page_kitchen_action_api(),
        'weather_search_api' => page_weather_search_api(),
        'admin_settings' => page_admin_settings(),
        'admin_ingredients' => page_admin_ingredients(),
        'admin_inventory' => page_admin_inventory(),
        'admin_guests' => page_admin_guests(),
        'admin_login' => page_admin_login(),
        'admin_logout' => page_admin_logout(),
        'admin' => page_admin(),
        'admin_event' => page_admin_event(),
        'admin_categories' => page_admin_categories(),
        'admin_products' => page_admin_products(),
        'admin_lang' => page_admin_lang(),
        'admin_kitchen' => page_admin_kitchen(),
        'event' => page_event(),
        default => page_home(),
    };
} catch (Throwable $e) {
    http_response_code(500);
    render_header('Error');
    echo '<div class="section"><div class="notice bad"><strong>Error:</strong> ' . h($e->getMessage()) . '</div></div>';
    render_footer();
}
