<?php
declare(strict_types=1);

session_start();

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

    $count = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($count === 0) {
        seed_data($pdo);
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
    $lang = $baseDe;
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
$langCode = $_SESSION['lang'] ?? 'de';
$LANG = load_lang($langCode);

function t(string $key, array $vars = []): string {
    global $LANG;
    $text = $LANG[$key] ?? $key;
    foreach ($vars as $k => $v) { $text = str_replace('{' . $k . '}', (string)$v, $text); }
    return $text;
}

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function is_admin(): bool { return !empty($_SESSION['admin']); }
function admin_password(): string { return (string)(getenv('GRILLER_ADMIN_PASSWORD') ?: 'change-me-now'); }
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
    $current = $_SESSION['lang'] ?? 'de';
    echo '<!doctype html><html lang="' . h($current) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title ? $title . ' · ' . t('app_name') : t('app_name')) . '</title>';
    echo '<link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css"></head><body>';
    echo '<header class="topbar"><div class="container topbar-inner"><a class="brand" href="' . h(route('home')) . '"><img src="assets/logo.svg" alt=""><span>' . h(t('app_name')) . ' <small class="muted">' . h(t('by_pengu')) . '</small></span></a><nav class="nav">';
    echo '<form class="lang-select" method="get"><input type="hidden" name="r" value="' . h($_GET['r'] ?? 'home') . '">';
    foreach ($_GET as $k => $v) { if (!in_array($k, ['r','lang'], true) && is_scalar($v)) { echo '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">'; } }
    echo '<label class="muted" for="lang">' . h(t('language')) . '</label><select id="lang" name="lang" onchange="this.form.submit()">';
    foreach ($langs as $code => $label) { echo '<option value="' . h($code) . '" ' . ($current === $code ? 'selected' : '') . '>' . h($label) . '</option>'; }
    echo '</select></form>';
    echo '<a class="btn ghost small" href="' . h(route('home')) . '">' . h(t('home')) . '</a>';
    if (is_admin()) { echo '<a class="btn dark small" href="' . h(route('admin')) . '">' . h(t('admin')) . '</a><a class="btn ghost small" href="' . h(route('admin_logout')) . '">' . h(t('logout')) . '</a>'; }
    else { echo '<a class="btn dark small" href="' . h(route('admin_login')) . '">' . h(t('admin')) . '</a>'; }
    echo '</nav></div></header><main class="container">';
    $f = flash(); if ($f) { echo '<div class="notice ' . h($f[1]) . '">' . h($f[0]) . '</div>'; }
}
function render_footer(): void { echo '</main><footer class="footer"><div class="container">' . h(t('footer')) . '</div></footer></body></html>'; }
function admin_tabs(): void {
    echo '<div class="admin-tabs">';
    echo '<a class="btn ghost small" href="' . h(route('admin')) . '">' . h(t('events')) . '</a>';
    echo '<a class="btn ghost small" href="' . h(route('admin_categories')) . '">' . h(t('categories')) . '</a>';
    echo '<a class="btn ghost small" href="' . h(route('admin_products')) . '">' . h(t('products')) . '</a>';
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

function page_home(): void {
    $pdo = db();
    $events = $pdo->query("SELECT * FROM events WHERE status = 'public' ORDER BY COALESCE(event_date,'9999-12-31'), created_at DESC")->fetchAll();
    render_header();
    echo '<section class="hero"><div><h1>' . h(t('hero_title')) . '</h1><p>' . h(t('hero_subtitle')) . '</p><div class="split-actions"><a class="btn primary" href="' . h(route('admin_login')) . '">' . h(t('admin')) . '</a><a class="btn ghost" href="#events">' . h(t('open_events')) . '</a></div></div><div class="hero-card"><img src="assets/hero-grill.svg" alt=""></div></section>';
    echo '<section id="events" class="section"><div class="section-title"><h2>' . h(t('open_events')) . '</h2></div>';
    if (!$events) { echo '<div class="empty">' . h(t('no_public_events')) . '</div>'; }
    else {
        echo '<div class="grid cols-3">';
        foreach ($events as $e) {
            echo '<article class="card solid"><span class="pill public">' . h(t('public')) . '</span><h3 style="margin-top:14px">' . h($e['title']) . '</h3>';
            if ($e['event_date']) { echo '<p class="muted">' . h(t('event_date')) . ': ' . h($e['event_date']) . '</p>'; }
            if ($e['description']) { echo '<p>' . nl2br(h($e['description'])) . '</p>'; }
            echo '<a class="btn primary" href="' . h(route('event', ['slug' => $e['slug']])) . '">' . h(t('join_event')) . '</a></article>';
        }
        echo '</div>';
    }
    echo '</section>';
    render_footer();
}

function page_admin_login(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $u = trim((string)($_POST['username'] ?? ''));
        $p = (string)($_POST['password'] ?? '');
        if ($u === ADMIN_USER && hash_equals(admin_password(), $p)) {
            $_SESSION['admin'] = true;
            redirect_to('admin');
        }
        flash(t('login_failed'), 'bad');
        redirect_to('admin_login');
    }
    render_header(t('login_title'));
    echo '<section class="section"><div class="grid cols-2"><div class="card"><h2>' . h(t('login_title')) . '</h2><p class="muted">' . h(t('admin_password_hint')) . '</p><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><div class="field"><label>' . h(t('username')) . '</label><input name="username" value="Griller" autocomplete="username"></div><div class="field"><label>' . h(t('password')) . '</label><input name="password" type="password" autocomplete="current-password"></div><input class="primary" type="submit" value="' . h(t('login')) . '"></form></div><div class="hero-card"><img src="assets/hero-grill.svg" alt=""></div></div></section>';
    render_footer();
}

function page_admin_logout(): void { unset($_SESSION['admin']); redirect_to('home'); }

function page_admin(): void {
    require_admin();
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_event') {
        verify_csrf();
        $title = trim((string)($_POST['title'] ?? '')) ?: 'Grillevent';
        $slug = unique_slug($pdo, $title);
        $stmt = $pdo->prepare('INSERT INTO events(title,slug,event_date,description,status) VALUES(?,?,?,?,?)');
        $stmt->execute([$title, $slug, $_POST['event_date'] ?: null, trim((string)($_POST['description'] ?? '')), 'draft']);
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
            $items = $pdo->prepare('SELECT * FROM event_products WHERE event_id = ?');
            $items->execute([$id]);
            foreach ($items->fetchAll() as $item) {
                $pdo->prepare('INSERT INTO event_products(event_id,product_id,sort_order,active) VALUES(?,?,?,?)')->execute([$newId, $item['product_id'], $item['sort_order'], $item['active']]);
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
    $events = $pdo->query('SELECT e.*, COUNT(DISTINCT g.id) AS guest_count, COUNT(oi.id) AS item_count FROM events e LEFT JOIN guests g ON g.event_id=e.id LEFT JOIN orders o ON o.guest_id=g.id LEFT JOIN order_items oi ON oi.order_id=o.id GROUP BY e.id ORDER BY e.created_at DESC')->fetchAll();
    render_header(t('admin_dashboard'));
    echo '<section class="section"><div class="section-title"><h2>' . h(t('admin_dashboard')) . '</h2></div>'; admin_tabs(); echo '<div class="grid cols-2"><div class="card"><h3>' . h(t('new_event')) . '</h3><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="create_event"><div class="field"><label>' . h(t('title')) . '</label><input name="title" required placeholder="Sommer BBQ"></div><div class="field"><label>' . h(t('event_date')) . '</label><input name="event_date" type="date"></div><div class="field"><label>' . h(t('description')) . '</label><textarea name="description" placeholder="Hinweise für Gäste"></textarea></div><input class="primary" type="submit" value="' . h(t('create')) . '"></form></div><div class="card"><h3>' . h(t('events')) . '</h3><p class="muted">' . h(t('public_hint')) . '</p><p class="muted">' . h(t('guests_hint')) . '</p></div></div>';
    echo '<div class="section"><div class="table-wrap"><table><thead><tr><th>' . h(t('title')) . '</th><th>' . h(t('event_date')) . '</th><th>' . h(t('status')) . '</th><th>' . h(t('guests')) . '</th><th>' . h(t('actions')) . '</th></tr></thead><tbody>';
    foreach ($events as $e) {
        echo '<tr><td><strong>' . h($e['title']) . '</strong><br><span class="muted">/' . h($e['slug']) . '</span></td><td>' . h($e['event_date'] ?: '-') . '</td><td><span class="pill ' . h($e['status']) . '">' . h(status_label($e['status'])) . '</span></td><td><strong>' . h((string)$e['guest_count']) . '</strong><br><span class="muted">' . h(t('orders')) . ': ' . h((string)$e['item_count']) . '</span></td><td class="nowrap"><div class="split-actions"><a class="btn small primary" href="' . h(route('admin_event', ['id' => $e['id']])) . '">' . h(t('edit')) . '</a><a class="btn small green" href="' . h(route('admin_kitchen', ['id' => $e['id']])) . '">' . h(t('kitchen')) . '</a><form class="inline-form" method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="clone_event"><input type="hidden" name="id" value="' . h($e['id']) . '"><button class="btn small ghost" type="submit">' . h(t('clone')) . '</button></form><form class="inline-form" method="post" onsubmit="return confirm(\'' . h(t('confirm_delete_event')) . '\')"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="delete_event"><input type="hidden" name="id" value="' . h($e['id']) . '"><button class="btn small red" type="submit">' . h(t('delete')) . '</button></form></div></td></tr>';
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
    $categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll();
    render_header(t('categories'));
    echo '<section class="section"><div class="section-title"><h2>' . h(t('categories')) . '</h2></div>'; admin_tabs();
    echo '<div class="card"><h3>' . h(t('add_category')) . '</h3><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="save_category"><div class="form-row"><div class="field"><label>' . h(t('category_name')) . '</label><input name="name" required></div><div class="field"><label>' . h(t('icon')) . '</label><input name="icon" value="🔥"></div></div><input class="primary" type="submit" value="' . h(t('add_category')) . '"></form></div>';
    echo '<div class="section"><div class="section-title compact"><div><h3>' . h(t('category_sorting')) . '</h3><p class="muted">' . h(t('drag_sort_hint')) . '</p></div><form method="post" onsubmit="document.getElementById(\'category-order\').value=sortableOrder(\'category-list\')"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="sort_categories"><input type="hidden" id="category-order" name="order"><button class="btn primary" type="submit">' . h(t('save_order')) . '</button></form></div><div class="table-wrap"><table><thead><tr><th></th><th>' . h(t('category')) . '</th><th>' . h(t('icon')) . '</th><th>' . h(t('actions')) . '</th></tr></thead><tbody id="category-list">';
    foreach ($categories as $c) {
        echo '<tr data-sort-id="' . h($c['id']) . '"><td class="drag-handle" title="' . h(t('drag_to_sort')) . '">☰</td><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="id" value="' . h($c['id']) . '"><td><input name="name" value="' . h($c['name']) . '"></td><td><input name="icon" value="' . h($c['icon']) . '"></td><td><div class="split-actions"><button class="btn small primary" name="action" value="save_category" type="submit">' . h(t('save')) . '</button><button class="btn small red" name="action" value="delete_category" type="submit" onclick="return confirm(\'' . h(t('confirm_delete_category')) . '\')">' . h(t('delete')) . '</button></div></td></form></tr>';
    }
    echo '</tbody></table></div></div></section>';
    render_sortable_script();
    echo '<script>initSortable("category-list","category-order");</script>';
    render_footer();
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
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM order_items oi JOIN event_products ep ON ep.id = oi.event_product_id WHERE ep.product_id = ?');
            $stmt->execute([$id]);
            $orderCount = (int)$stmt->fetchColumn();
            if ($orderCount > 0) {
                flash(t('cannot_delete_product_with_orders'), 'bad');
            } else {
                $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
                flash(t('product_deleted'));
            }
            redirect_to('admin_products');
        }

        $name = trim((string)($_POST['name'] ?? '')) ?: 'Produkt';
        $categoryId = (int)($_POST['category_id'] ?? 1);
        $description = trim((string)($_POST['description'] ?? ''));
        $options = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['options'] ?? '')))));
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
        flash(t('product_saved'));
        redirect_to('admin_products');
    }
    $categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll();
    $products = $pdo->query('SELECT p.*, c.name AS category_name, c.icon AS category_icon FROM products p JOIN categories c ON c.id=p.category_id ORDER BY c.sort_order, p.sort_order, p.name')->fetchAll();
    render_header(t('products'));
    echo '<section class="section"><div class="section-title"><h2>' . h(t('products')) . '</h2></div>'; admin_tabs();
    echo '<div class="card"><h3>' . h(t('add_product')) . '</h3><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="save_product"><div class="form-row"><div class="field"><label>' . h(t('product_name')) . '</label><input name="name" required></div><div class="field"><label>' . h(t('category')) . '</label><select name="category_id">';
    foreach ($categories as $c) { echo '<option value="' . h($c['id']) . '">' . h($c['icon'] . ' ' . $c['name']) . '</option>'; }
    echo '</select></div></div><div class="field"><label>' . h(t('product_description')) . '</label><textarea name="description"></textarea></div><div class="field"><label>' . h(t('comma_options')) . '</label><input name="options" placeholder="Käse, Zwiebeln, Tomate"></div><div class="form-row"><label class="check-chip"><input type="checkbox" name="allow_doneness"> ' . h(t('allow_doneness')) . '</label><label class="check-chip"><input type="checkbox" name="active" checked> ' . h(t('active')) . '</label></div><input class="primary" type="submit" value="' . h(t('add_product')) . '"></form></div>';
    echo '<div class="notice warn">' . h(t('delete_product_hint')) . '</div>';
    echo '<div class="section"><div class="section-title compact"><div><h3>' . h(t('product_sorting')) . '</h3><p class="muted">' . h(t('drag_sort_hint')) . '</p></div><form method="post" onsubmit="document.getElementById(\'product-order\').value=sortableOrder(\'product-list\')"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="sort_products"><input type="hidden" id="product-order" name="order"><button class="btn primary" type="submit">' . h(t('save_order')) . '</button></form></div><div class="table-wrap"><table><thead><tr><th></th><th>' . h(t('product_name')) . '</th><th>' . h(t('category')) . '</th><th>' . h(t('options')) . '</th><th>' . h(t('status')) . '</th><th>' . h(t('actions')) . '</th></tr></thead><tbody id="product-list">';
    foreach ($products as $p) {
        echo '<tr data-sort-id="' . h($p['id']) . '"><td class="drag-handle" title="' . h(t('drag_to_sort')) . '">☰</td><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="id" value="' . h($p['id']) . '"><td><input name="name" value="' . h($p['name']) . '"><textarea name="description">' . h($p['description']) . '</textarea></td><td><select name="category_id">';
        foreach ($categories as $c) { echo '<option value="' . h($c['id']) . '" ' . ((int)$c['id']===(int)$p['category_id']?'selected':'') . '>' . h($c['icon'] . ' ' . $c['name']) . '</option>'; }
        echo '</select></td><td><input name="options" value="' . h(implode(', ', decode_options($p['options_json']))) . '"><br><label class="check-chip"><input type="checkbox" name="allow_doneness" ' . ((int)$p['allow_doneness'] ? 'checked' : '') . '> ' . h(t('allow_doneness')) . '</label></td><td><label class="check-chip"><input type="checkbox" name="active" ' . ((int)$p['active'] ? 'checked' : '') . '> ' . h(t('active')) . '</label></td><td><div class="split-actions"><button class="btn small primary" name="action" value="save_product" type="submit">' . h(t('save')) . '</button><button class="btn small red" name="action" value="delete_product" type="submit" onclick="return confirm(\'' . h(t('confirm_delete_product')) . '\')">' . h(t('delete')) . '</button></div></td></form></tr>';
    }
    echo '</tbody></table></div></div></section>';
    render_sortable_script();
    echo '<script>initSortable("product-list","product-order");</script>';
    render_footer();
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

function page_event(): void {
    $pdo = db();
    $slug = (string)($_GET['slug'] ?? ''); $event = get_event_by_slug($slug);
    if (!$event || !in_array($event['status'], ['public','closed'], true)) { render_header(); echo '<div class="section"><div class="empty">' . h(t('no_public_events')) . '</div></div>'; render_footer(); return; }
    $tokenKey = 'guest_token_' . $event['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'join') {
        verify_csrf();
        $name = trim((string)($_POST['name'] ?? '')) ?: 'Gast';
        $pin = trim((string)($_POST['pin'] ?? ''));
        $profileId = null;
        if ($pin !== '') {
            if (!preg_match('/^\d{4}$/', $pin)) {
                flash(t('invalid_pin'), 'bad');
                redirect_to('event', ['slug' => $event['slug']]);
            }
            $profileId = find_or_create_guest_profile($pdo, $name, $pin);
            $stmt = $pdo->prepare('SELECT token FROM guests WHERE event_id = ? AND profile_id = ? ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$event['id'], $profileId]);
            $existingToken = $stmt->fetchColumn();
            if ($existingToken) {
                $_SESSION[$tokenKey] = (string)$existingToken;
                flash(t('guest_restored'));
                redirect_to('event', ['slug' => $event['slug']]);
            }
        }
        $token = bin2hex(random_bytes(20));
        $pdo->prepare('INSERT INTO guests(event_id,profile_id,name,token) VALUES(?,?,?,?)')->execute([$event['id'], $profileId, $name, $token]);
        $_SESSION[$tokenKey] = $token;
        redirect_to('event', ['slug' => $event['slug']]);
    }
    $guest = null;
    if (!empty($_SESSION[$tokenKey])) {
        $stmt = $pdo->prepare('SELECT * FROM guests WHERE token = ? AND event_id = ?'); $stmt->execute([$_SESSION[$tokenKey], $event['id']]); $guest = $stmt->fetch() ?: null;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'order' && $guest && $event['status'] === 'public') {
        verify_csrf();
        $items = $_POST['items'] ?? [];
        $chosen = [];
        foreach ($items as $eventProductId => $data) {
            $qty = max(0, min(20, (int)($data['quantity'] ?? 0)));
            if ($qty > 0) {
                $chosen[] = [(int)$eventProductId, $qty, (string)($data['doneness'] ?? ''), array_values(array_filter(array_map('strval', $data['options'] ?? []))), trim((string)($data['note'] ?? ''))];
            }
        }
        if (!$chosen) { flash(t('no_items_selected'), 'bad'); redirect_to('event', ['slug' => $event['slug']]); }
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO orders(event_id,guest_id,status) VALUES(?,?,?)')->execute([$event['id'], $guest['id'], 'pending']);
        $orderId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO order_items(order_id,event_product_id,quantity,doneness,options_json,note,status) VALUES(?,?,?,?,?,?,?)');
        foreach ($chosen as $ch) { $stmt->execute([$orderId, $ch[0], $ch[1], $ch[2], json_encode($ch[3], JSON_UNESCAPED_UNICODE), $ch[4], 'pending']); }
        $pdo->commit();
        flash(t('order_saved'));
        redirect_to('event', ['slug' => $event['slug']]);
    }
    render_header($event['title']);
    echo '<section class="section"><div class="section-title"><div><span class="pill ' . h($event['status']) . '">' . h(status_label($event['status'])) . '</span><h2>' . h($event['title']) . '</h2><p class="muted">' . h($event['event_date'] ?: '') . '</p></div></div>';
    if ($event['description']) { echo '<div class="notice">' . nl2br(h($event['description'])) . '</div>'; }
    if ($event['status'] === 'closed') { echo '<div class="notice warn">' . h(t('closed_hint')) . '</div>'; }
    if (!$guest) {
        echo '<div class="grid cols-2"><div class="card"><h3>' . h(t('join_event')) . '</h3><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="join"><div class="field"><label>' . h(t('guest_name')) . '</label><input name="name" required autofocus autocomplete="name"></div><div class="field"><label>' . h(t('guest_pin')) . '</label><input name="pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="off" placeholder="1234"><span class="muted">' . h(t('guest_pin_hint')) . '</span></div><input class="primary" type="submit" value="' . h(t('continue')) . '"></form></div><div class="hero-card"><img src="assets/hero-grill.svg" alt=""></div></div>';
    } else {
        echo '<div class="notice good">' . h(t('welcome_guest', ['name' => $guest['name']])) . '</div>';
        show_guest_status($event, $guest);
        show_guest_order_form($event, $guest);
    }
    echo '</section>';
    render_footer();
}

function event_menu_items(int $eventId): array {
    $stmt = db()->prepare('SELECT ep.id AS event_product_id, p.*, c.name AS category_name, c.icon AS category_icon FROM event_products ep JOIN products p ON p.id=ep.product_id JOIN categories c ON c.id=p.category_id WHERE ep.event_id=? AND ep.active=1 AND p.active=1 ORDER BY c.sort_order, ep.sort_order, p.sort_order, p.name');
    $stmt->execute([$eventId]); return $stmt->fetchAll();
}
function show_guest_order_form(array $event, array $guest): void {
    if ($event['status'] !== 'public') { return; }
    $items = event_menu_items((int)$event['id']);
    echo '<div class="card"><h3>' . h(t('menu')) . '</h3><form method="post" data-order-form="1"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="order"><div class="grid">';
    $lastCat = '';
    foreach ($items as $item) {
        if ($lastCat !== $item['category_name']) { $lastCat = $item['category_name']; echo '<h3 style="margin:18px 0 0">' . h($item['category_icon'] . ' ' . $item['category_name']) . '</h3>'; }
        $id = (int)$item['event_product_id'];
        echo '<div class="menu-item"><div><strong>' . h($item['name']) . '</strong>'; if ($item['description']) { echo '<p class="muted">' . h($item['description']) . '</p>'; }
        $opts = decode_options($item['options_json']);
        if ($opts) { echo '<div class="option-grid">'; foreach ($opts as $opt) { echo '<label class="check-chip"><input type="checkbox" name="items[' . $id . '][options][]" value="' . h($opt) . '"> ' . h($opt) . '</label>'; } echo '</div>'; }
        if ((int)$item['allow_doneness']) { echo '<div class="field" style="margin-top:12px"><label>' . h(t('doneness')) . '</label><select name="items[' . $id . '][doneness]"><option value="">-</option>'; foreach (doneness_options() as $k=>$v) { echo '<option value="' . h($k) . '">' . h($v) . '</option>'; } echo '</select></div>'; }
        echo '<div class="field" style="margin-top:12px"><label>' . h(t('note')) . '</label><input name="items[' . $id . '][note]" placeholder="z. B. ohne Zwiebeln"></div></div><div class="qty-area"><label class="muted">' . h(t('quantity')) . '</label><div class="qty-stepper"><button class="qty-btn" type="button" aria-label="Menge verringern" onclick="const i=this.parentNode.querySelector(\'input\');i.value=Math.max(Number(i.min||0),Number(i.value||0)-1)">−</button><input class="quantity qty-input" name="items[' . $id . '][quantity]" type="number" inputmode="numeric" min="0" max="20" value="0"><button class="qty-btn" type="button" aria-label="Menge erhöhen" onclick="const i=this.parentNode.querySelector(\'input\');i.value=Math.min(Number(i.max||20),Number(i.value||0)+1)">+</button></div></div></div>';
    }
    echo '</div><br><input class="primary" type="submit" value="' . h(t('send_order')) . '"></form></div>';
}
function show_guest_status(array $event, array $guest): void {
    $stmt = db()->prepare('SELECT oi.*, p.name AS product_name, c.icon AS category_icon FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN event_products ep ON ep.id=oi.event_product_id JOIN products p ON p.id=ep.product_id JOIN categories c ON c.id=p.category_id WHERE o.event_id=? AND o.guest_id=? ORDER BY oi.created_at DESC, oi.id DESC');
    $stmt->execute([$event['id'], $guest['id']]); $items = $stmt->fetchAll();
    echo '<div class="section"><div class="card"><h3>' . h(t('order_status')) . '</h3><p class="muted">' . h(t('ready_hint')) . '</p>';
    if (!$items) { echo '<div class="empty">' . h(t('no_items_selected')) . '</div>'; }
    else { echo '<div class="status-panel">'; foreach ($items as $it) { $opts = decode_options($it['options_json']); echo '<div class="status-item"><div><strong>' . h($it['quantity'] . '× ' . $it['category_icon'] . ' ' . $it['product_name']) . '</strong><br><span class="muted">' . h(implode(', ', $opts)) . ($it['doneness'] ? ' · ' . h(doneness_options()[$it['doneness']] ?? $it['doneness']) : '') . ($it['note'] ? ' · ' . h($it['note']) : '') . '</span></div><span class="pill ' . h($it['status']) . '">' . h(status_label($it['status'])) . '</span></div>'; } echo '</div>'; }
    echo '</div></div><script>
window.addEventListener("DOMContentLoaded", function () {
  const form = document.querySelector("form[data-order-form]");
  const hasOpenSelection = function () {
    if (!form) { return false; }
    return Array.from(form.elements).some(function (el) {
      if (!el.name || el.type === "hidden" || el.type === "submit" || el.tagName === "BUTTON") { return false; }
      if ((el.type === "checkbox" || el.type === "radio") && el.checked) { return true; }
      if (el.type === "number") { return Number(el.value || 0) > Number(el.min || 0); }
      if ((el.tagName === "SELECT" || el.tagName === "TEXTAREA" || el.tagName === "INPUT") && el.value && el.value.trim() !== "") { return true; }
      return false;
    });
  };
  function refreshWhenSafe() {
    if (hasOpenSelection()) {
      setTimeout(refreshWhenSafe, 15000);
      return;
    }
    location.reload();
  }
  setTimeout(refreshWhenSafe, 15000);
});
</script>';
}

function page_admin_kitchen(): void {
    require_admin();
    $pdo = db(); $id = (int)($_GET['id'] ?? 0); $event = get_event($id); if (!$event) { redirect_to('admin'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $itemId = (int)($_POST['item_id'] ?? 0); $status = (string)($_POST['status'] ?? 'pending');
        if (in_array($status, ['pending','in_progress','done'], true)) {
            $pdo->prepare('UPDATE order_items SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status, $itemId]);
            $orderId = (int)$pdo->query('SELECT order_id FROM order_items WHERE id=' . $itemId)->fetchColumn();
            if ($orderId) {
                $all = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id=?'); $all->execute([$orderId]);
                $done = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id=? AND status='done'"); $done->execute([$orderId]);
                $orderStatus = ((int)$all->fetchColumn() === (int)$done->fetchColumn()) ? 'done' : 'in_progress';
                $pdo->prepare('UPDATE orders SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$orderStatus, $orderId]);
            }
        }
        redirect_to('admin_kitchen', ['id' => $id]);
    }
    $stmt = $pdo->prepare('SELECT oi.*, g.name AS guest_name, p.name AS product_name, c.icon AS category_icon, o.created_at AS order_created FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN guests g ON g.id=o.guest_id JOIN event_products ep ON ep.id=oi.event_product_id JOIN products p ON p.id=ep.product_id JOIN categories c ON c.id=p.category_id WHERE o.event_id=? ORDER BY CASE oi.status WHEN "pending" THEN 1 WHEN "in_progress" THEN 2 ELSE 3 END, oi.created_at ASC');
    $stmt->execute([$id]); $items = $stmt->fetchAll();
    $lanes = ['pending'=>[], 'in_progress'=>[], 'done'=>[]]; foreach ($items as $it) { $lanes[$it['status']][] = $it; }
    render_header(t('kitchen') . ' · ' . $event['title']);
    echo '<section class="section"><div class="section-title"><div><h2>' . h(t('kitchen')) . ': ' . h($event['title']) . '</h2><p class="muted">' . h(t('public_link')) . ': ' . h(public_link($event)) . '</p></div><a class="btn ghost" href="' . h(route('admin_event', ['id' => $id])) . '">' . h(t('back')) . '</a></div>'; admin_tabs();
    if (!$items) { echo '<div class="empty">' . h(t('empty_kitchen')) . '</div>'; }
    echo '<div class="kitchen-board">';
    foreach (['pending','in_progress','done'] as $lane) {
        echo '<div class="lane"><h3><span class="pill ' . h($lane) . '">' . h(status_label($lane)) . '</span> ' . count($lanes[$lane]) . '</h3>';
        foreach ($lanes[$lane] as $it) { render_ticket($it); }
        echo '</div>';
    }
    echo '</div></section><script>setTimeout(()=>location.reload(),20000);</script>';
    render_footer();
}
function render_ticket(array $it): void {
    $opts = decode_options($it['options_json']);
    echo '<article class="ticket"><div class="ticket-head"><strong>' . h($it['quantity'] . '× ' . $it['category_icon'] . ' ' . $it['product_name']) . '</strong><span class="pill ' . h($it['status']) . '">' . h(status_label($it['status'])) . '</span></div>';
    echo '<div class="muted">' . h(t('guest')) . ': ' . h($it['guest_name']) . '</div>';
    $details = [];
    if ($opts) { $details[] = implode(', ', $opts); }
    if ($it['doneness']) { $details[] = doneness_options()[$it['doneness']] ?? $it['doneness']; }
    if ($it['note']) { $details[] = $it['note']; }
    if ($details) { echo '<p>' . h(implode(' · ', $details)) . '</p>'; }
    echo '<div class="ticket-actions">';
    foreach (['pending'=>t('mark_pending'), 'in_progress'=>t('mark_in_progress'), 'done'=>t('mark_done')] as $status => $label) {
        echo '<form method="post" class="inline-form"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="item_id" value="' . h($it['id']) . '"><input type="hidden" name="status" value="' . h($status) . '"><button class="btn small ' . ($status === 'done' ? 'green' : 'ghost') . '" type="submit">' . h($label) . '</button></form>';
    }
    echo '</div></article>';
}

$route = (string)($_GET['r'] ?? 'home');
try {
    match ($route) {
        'home' => page_home(),
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
