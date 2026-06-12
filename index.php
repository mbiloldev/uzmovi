<?php
// ============================================================
//  UZMOVI.TV KLON — Asosiy Kirish Nuqtasi (index.php)
//  Backend: PHP | Database: PostgreSQL | UI: HTML/CSS/JS
// ============================================================


// --- 2. MA'LUMOTLAR BAZASIGA ULANISH ---
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'DB ulanishda xato: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// --- 3. YORDAMCHI FUNKSIYALAR ---
function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id'       => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? 'Foydalanuvchi',
        'email'    => $_SESSION['email'] ?? '',
        'avatar'   => $_SESSION['avatar'] ?? null,
    ];
}

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

// --- 4. ROUTER ---
$request  = $_SERVER['REQUEST_URI'] ?? '/';
$method   = $_SERVER['REQUEST_METHOD'];

// URL'dan query string'ni olib tashlash
$path = strtok($request, '?');
$path = rtrim($path, '/') ?: '/';

// AJAX API so'rovlari
if (str_starts_with($path, '/api/')) {
    header('Content-Type: application/json');
    handleApiRequest($path, $method);
    exit;
}

// ============================================================
//  5. SAHIFALAR ROUTE'LARI
// ============================================================
$page = 'home'; // default

$routes = [
    '/'           => 'home',
    '/movies'     => 'movies',
    '/serials'    => 'serials',
    '/cartoons'   => 'cartoons',
    '/movie'      => 'movie_detail',
    '/watch'      => 'watch',
    '/search'     => 'search',
    '/login'      => 'login',
    '/register'   => 'register',
    '/logout'     => 'logout',
    '/profile'    => 'profile',
    '/favorites'  => 'favorites',
];

foreach ($routes as $route => $routePage) {
    if ($path === $route || str_starts_with($path, $route . '/')) {
        $page = $routePage;
        break;
    }
}

// URL parametrlarini olish
$urlParts = explode('/', trim($path, '/'));
$itemId   = isset($urlParts[1]) && is_numeric($urlParts[1]) ? (int)$urlParts[1] : null;

// Maxsus hollar
if ($page === 'logout') {
    session_destroy();
    redirect('/');
}

if (($page === 'profile' || $page === 'favorites') && !isLoggedIn()) {
    redirect('/login');
}

// ============================================================
//  6. MA'LUMOTLARNI OLISH FUNKSIYALARI
// ============================================================

function getMovies(string $type = 'movie', int $limit = 20, int $offset = 0, array $filters = []): array {
    try {
        $db     = getDB();
        $where  = ["m.type = :type"];
        $params = [':type' => $type];

        if (!empty($filters['genre_id'])) {
            $where[]                = "mg.genre_id = :genre_id";
            $params[':genre_id']    = (int)$filters['genre_id'];
        }
        if (!empty($filters['year'])) {
            $where[]           = "m.year = :year";
            $params[':year']   = (int)$filters['year'];
        }
        if (!empty($filters['country'])) {
            $where[]              = "m.country ILIKE :country";
            $params[':country']   = '%' . $filters['country'] . '%';
        }
        if (!empty($filters['search'])) {
            $where[]             = "(m.title ILIKE :search OR m.title_ru ILIKE :search)";
            $params[':search']   = '%' . $filters['search'] . '%';
        }

        $whereStr = implode(' AND ', $where);
        $joinGenre = !empty($filters['genre_id']) ? "LEFT JOIN movie_genres mg ON m.id = mg.movie_id" : "";

        $sql = "
            SELECT DISTINCT
                m.id, m.title, m.title_ru, m.year, m.country,
                m.rating, m.poster_url, m.duration, m.type,
                m.is_new, m.views_count, m.description,
                COALESCE(
                    STRING_AGG(g.name, ', ') FILTER (WHERE g.name IS NOT NULL),
                    ''
                ) AS genres
            FROM movies m
            $joinGenre
            LEFT JOIN movie_genres mg2 ON m.id = mg2.movie_id
            LEFT JOIN genres g ON mg2.genre_id = g.id
            WHERE $whereStr
            GROUP BY m.id, m.title, m.title_ru, m.year, m.country,
                     m.rating, m.poster_url, m.duration, m.type,
                     m.is_new, m.views_count, m.description
            ORDER BY m.views_count DESC, m.id DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return getDemoMovies($type, $limit);
    }
}

function getMovieById(int $id): ?array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT m.*,
                STRING_AGG(DISTINCT g.name, ', ') AS genres,
                STRING_AGG(DISTINCT a.name, ', ') AS actors
            FROM movies m
            LEFT JOIN movie_genres mg ON m.id = mg.movie_id
            LEFT JOIN genres g ON mg.genre_id = g.id
            LEFT JOIN movie_actors ma ON m.id = ma.movie_id
            LEFT JOIN actors a ON ma.actor_id = a.id
            WHERE m.id = :id
            GROUP BY m.id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return getDemoMovies('movie', 1)[0] ?? null;
    }
}

function getGenres(): array {
    try {
        $db   = getDB();
        $stmt = $db->query("SELECT id, name FROM genres ORDER BY name");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [
            ['id'=>1,'name'=>'Komediya'],['id'=>2,'name'=>'Drama'],
            ['id'=>3,'name'=>'Triller'],['id'=>4,'name'=>'Fantastika'],
            ['id'=>5,'name'=>'Jangovar'],['id'=>6,'name'=>'Romantik'],
            ['id'=>7,'name'=>'Qo\'rqinchli'],['id'=>8,'name'=>'Animatsiya'],
        ];
    }
}

function getComments(int $movieId): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT c.*, u.username, u.avatar_url
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.movie_id = :movie_id
            ORDER BY c.created_at DESC
            LIMIT 20
        ");
        $stmt->execute([':movie_id' => $movieId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function getFavorites(int $userId): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT m.* FROM movies m
            JOIN favorites f ON m.id = f.movie_id
            WHERE f.user_id = :user_id
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

// --- DEMO MA'LUMOTLAR (DB bo'lmagan holatda) ---
function getDemoMovies(string $type = 'movie', int $limit = 20): array {
    $movies = [
        ['id'=>1,'title'=>'Bahodir','title_ru'=>'Богатырь','year'=>2022,'country'=>'O\'zbekiston','rating'=>8.2,'poster_url'=>'https://placehold.co/300x450/1a1a2e/e94560?text=🎬','duration'=>115,'type'=>'movie','is_new'=>true,'views_count'=>124500,'genres'=>'Drama, Jangovar','description'=>'O\'zbek milliy qahramonligi haqidagi epik drama.'],
        ['id'=>2,'title'=>'Sehrli Dunyo','title_ru'=>'Волшебный мир','year'=>2023,'country'=>'AQSh','rating'=>7.8,'poster_url'=>'https://placehold.co/300x450/16213e/0f3460?text=🌍','duration'=>132,'type'=>'movie','is_new'=>true,'views_count'=>98200,'genres'=>'Fantastika, Sarguzasht','description'=>'Parallel olamlar va sirli kuchlar haqida.'],
        ['id'=>3,'title'=>'Ko\'ngilsiz Tun','title_ru'=>'Тёмная ночь','year'=>2021,'country'=>'Rossiya','rating'=>7.5,'poster_url'=>'https://placehold.co/300x450/0f3460/e94560?text=🌙','duration'=>98,'type'=>'movie','is_new'=>false,'views_count'=>76300,'genres'=>'Triller, Qo\'rqinchli','description'=>'Sirli qotillik va detektiv izlanishlar.'],
        ['id'=>4,'title'=>'Muhabbat Qo\'shig\'i','title_ru'=>'Песня любви','year'=>2023,'country'=>'O\'zbekiston','rating'=>8.5,'poster_url'=>'https://placehold.co/300x450/1a1a2e/ffd700?text=💛','duration'=>105,'type'=>'movie','is_new'=>true,'views_count'=>215000,'genres'=>'Romantik, Drama','description'=>'Ikki qalbning bir-biriga topilishi haqida.'],
        ['id'=>5,'title'=>'Avlodlar Urushi','title_ru'=>'Война поколений','year'=>2022,'country'=>'AQSh','rating'=>8.9,'poster_url'=>'https://placehold.co/300x450/0d0d0d/ff6b6b?text=⚔️','duration'=>148,'type'=>'movie','is_new'=>false,'views_count'=>342000,'genres'=>'Jangovar, Sarguzasht','description'=>'Kelajak uchun kurash.'],
        ['id'=>6,'title'=>'Kulgi Fabrikasi','title_ru'=>'Фабрика смеха','year'=>2023,'country'=>'O\'zbekiston','rating'=>7.2,'poster_url'=>'https://placehold.co/300x450/1a1a2e/00d2ff?text=😂','duration'=>92,'type'=>'movie','is_new'=>true,'views_count'=>88700,'genres'=>'Komediya','description'=>'Kulmay iloji yo\'q komediya.'],
        ['id'=>7,'title'=>'Yulduz Sayohati','title_ru'=>'Звёздное путешествие','year'=>2020,'country'=>'AQSh','rating'=>8.1,'poster_url'=>'https://placehold.co/300x450/050505/a855f7?text=🚀','duration'=>160,'type'=>'movie','is_new'=>false,'views_count'=>178000,'genres'=>'Fantastika, Sarguzasht','description'=>'Galaktikalar orasidagi epik sayohat.'],
        ['id'=>8,'title'=>'Toshkent Tuni','title_ru'=>'Ночь Ташкента','year'=>2023,'country'=>'O\'zbekiston','rating'=>7.9,'poster_url'=>'https://placehold.co/300x450/1a0033/ff6b35?text=🌃','duration'=>110,'type'=>'movie','is_new'=>true,'views_count'=>95400,'genres'=>'Triller, Drama','description'=>'Zamonaviy Toshkentda sirli voqealar.'],
    ];
    return array_slice(array_filter($movies, fn($m) => $m['type'] === $type), 0, $limit) ?: array_slice($movies, 0, $limit);
}

// ============================================================
//  7. API HANDLER
// ============================================================
function handleApiRequest(string $path, string $method): void {
    $db = null;

    // --- Izoh qo'shish ---
    if ($path === '/api/comments' && $method === 'POST') {
        if (!isLoggedIn()) { echo json_encode(['success'=>false,'error'=>'Tizimga kirish kerak']); return; }
        $data    = json_decode(file_get_contents('php://input'), true);
        $movieId = (int)($data['movie_id'] ?? 0);
        $text    = trim($data['text'] ?? '');
        if (!$movieId || strlen($text) < 2) { echo json_encode(['success'=>false,'error'=>'Noto\'g\'ri ma\'lumot']); return; }
        try {
            $db   = getDB();
            $stmt = $db->prepare("INSERT INTO comments (user_id, movie_id, text, created_at) VALUES (:uid, :mid, :txt, NOW()) RETURNING id");
            $stmt->execute([':uid'=>$_SESSION['user_id'],':mid'=>$movieId,':txt'=>$text]);
            $id = $stmt->fetchColumn();
            echo json_encode(['success'=>true,'id'=>$id,'username'=>$_SESSION['username'],'text'=>sanitize($text)]);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>'Saqlashda xato']); }
        return;
    }

    // --- Sevimlilarga qo'shish/olib tashlash ---
    if ($path === '/api/favorites' && $method === 'POST') {
        if (!isLoggedIn()) { echo json_encode(['success'=>false,'error'=>'Tizimga kirish kerak']); return; }
        $data    = json_decode(file_get_contents('php://input'), true);
        $movieId = (int)($data['movie_id'] ?? 0);
        try {
            $db     = getDB();
            $check  = $db->prepare("SELECT id FROM favorites WHERE user_id=:uid AND movie_id=:mid");
            $check->execute([':uid'=>$_SESSION['user_id'],':mid'=>$movieId]);
            if ($check->fetch()) {
                $db->prepare("DELETE FROM favorites WHERE user_id=:uid AND movie_id=:mid")->execute([':uid'=>$_SESSION['user_id'],':mid'=>$movieId]);
                echo json_encode(['success'=>true,'action'=>'removed']);
            } else {
                $db->prepare("INSERT INTO favorites (user_id, movie_id, created_at) VALUES (:uid,:mid,NOW())")->execute([':uid'=>$_SESSION['user_id'],':mid'=>$movieId]);
                echo json_encode(['success'=>true,'action'=>'added']);
            }
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>'Xato']); }
        return;
    }

    // --- Reyting berish ---
    if ($path === '/api/rate' && $method === 'POST') {
        if (!isLoggedIn()) { echo json_encode(['success'=>false,'error'=>'Tizimga kirish kerak']); return; }
        $data    = json_decode(file_get_contents('php://input'), true);
        $movieId = (int)($data['movie_id'] ?? 0);
        $rating  = min(10, max(1, (float)($data['rating'] ?? 0)));
        try {
            $db = getDB();
            $db->prepare("INSERT INTO ratings (user_id, movie_id, rating, created_at) VALUES (:uid,:mid,:r,NOW())
                          ON CONFLICT (user_id, movie_id) DO UPDATE SET rating=:r")->execute([':uid'=>$_SESSION['user_id'],':mid'=>$movieId,':r'=>$rating]);
            $avg = $db->prepare("SELECT ROUND(AVG(rating),1) FROM ratings WHERE movie_id=:mid");
            $avg->execute([':mid'=>$movieId]);
            echo json_encode(['success'=>true,'new_rating'=>$avg->fetchColumn()]);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>'Xato']); }
        return;
    }

    // --- Ko'rishlar sonini oshirish ---
    if ($path === '/api/view' && $method === 'POST') {
        $data    = json_decode(file_get_contents('php://input'), true);
        $movieId = (int)($data['movie_id'] ?? 0);
        try {
            $db = getDB();
            $db->prepare("UPDATE movies SET views_count = views_count + 1 WHERE id=:id")->execute([':id'=>$movieId]);
            echo json_encode(['success'=>true]);
        } catch (PDOException $e) { echo json_encode(['success'=>true]); }
        return;
    }

    // --- LOGIN ---
    if ($path === '/api/login' && $method === 'POST') {
        $data  = json_decode(file_get_contents('php://input'), true);
        $email = trim($data['email'] ?? '');
        $pass  = $data['password'] ?? '';
        try {
            $db   = getDB();
            $stmt = $db->prepare("SELECT id, username, email, password_hash, avatar_url FROM users WHERE email = :email");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();
            if ($user && password_verify($pass, $user['password_hash'])) {
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email']    = $user['email'];
                $_SESSION['avatar']   = $user['avatar_url'];
                echo json_encode(['success'=>true,'username'=>$user['username']]);
            } else {
                echo json_encode(['success'=>false,'error'=>'Email yoki parol noto\'g\'ri']);
            }
        } catch (PDOException $e) {
            // Demo login
            if ($email === 'demo@uzmovi.uz' && $pass === 'demo123') {
                $_SESSION['user_id']  = 1;
                $_SESSION['username'] = 'Demo Foydalanuvchi';
                $_SESSION['email']    = $email;
                echo json_encode(['success'=>true,'username'=>'Demo Foydalanuvchi']);
            } else {
                echo json_encode(['success'=>false,'error'=>'Tizimga ulanib bo\'lmadi']);
            }
        }
        return;
    }

    // --- REGISTER ---
    if ($path === '/api/register' && $method === 'POST') {
        $data     = json_decode(file_get_contents('php://input'), true);
        $username = sanitize($data['username'] ?? '');
        $email    = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $pass     = $data['password'] ?? '';
        if (!$username || !$email || strlen($pass) < 6) {
            echo json_encode(['success'=>false,'error'=>'Ma\'lumotlar noto\'g\'ri']); return;
        }
        try {
            $db   = getDB();
            $check = $db->prepare("SELECT id FROM users WHERE email=:email");
            $check->execute([':email'=>$email]);
            if ($check->fetch()) { echo json_encode(['success'=>false,'error'=>'Bu email allaqachon ro\'yxatdan o\'tgan']); return; }
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, created_at) VALUES (:u,:e,:h,NOW()) RETURNING id");
            $stmt->execute([':u'=>$username,':e'=>$email,':h'=>$hash]);
            $id = $stmt->fetchColumn();
            $_SESSION['user_id']  = $id;
            $_SESSION['username'] = $username;
            $_SESSION['email']    = $email;
            echo json_encode(['success'=>true,'username'=>$username]);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>'Ro\'yxatdan o\'tishda xato']); }
        return;
    }

    echo json_encode(['error' => 'Noma\'lum endpoint']);
}

// ============================================================
//  8. SAHIFA RENDER QO'YISH
// ============================================================

// Ma'lumotlarni sahifaga qarab yuklash
$pageData = [];

switch ($page) {
    case 'home':
        $pageData['trending']  = getMovies('movie',   8, 0);
        $pageData['new']       = getMovies('movie',   8, 0, ['is_new' => true]);
        $pageData['serials']   = getMovies('serial',  6, 0);
        $pageData['cartoons']  = getMovies('cartoon', 6, 0);
        $pageData['genres']    = getGenres();
        break;
    case 'movies':
        $filters = [
            'genre_id' => $_GET['genre'] ?? null,
            'year'     => $_GET['year']  ?? null,
            'country'  => $_GET['country'] ?? null,
            'search'   => $_GET['q'] ?? null,
        ];
        $pageData['movies']  = getMovies('movie', 24, (int)(($_GET['page']??1)-1)*24, $filters);
        $pageData['genres']  = getGenres();
        $pageData['filters'] = $filters;
        break;
    case 'serials':
        $pageData['movies'] = getMovies('serial', 24);
        $pageData['genres'] = getGenres();
        break;
    case 'cartoons':
        $pageData['movies'] = getMovies('cartoon', 24);
        $pageData['genres'] = getGenres();
        break;
    case 'movie_detail':
        $pageData['movie']    = $itemId ? getMovieById($itemId) : getDemoMovies('movie',1)[0];
        $pageData['comments'] = $itemId ? getComments($itemId) : [];
        $pageData['related']  = getMovies($pageData['movie']['type'] ?? 'movie', 6);
        break;
    case 'search':
        $q = sanitize($_GET['q'] ?? '');
        $pageData['query']  = $q;
        $pageData['movies'] = $q ? getMovies('movie', 20, 0, ['search' => $q]) : [];
        break;
    case 'favorites':
        $user = getCurrentUser();
        $pageData['movies'] = $user ? getFavorites($user['id']) : [];
        break;
}

$currentUser = getCurrentUser();

// ============================================================
//  9. HTML CHIQARISH
// ============================================================
?>
<!DOCTYPE html>
<html lang="uz">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= SITE_NAME ?> – O'zbek Kinoteatri</title>
<meta name="description" content="UzMovi – O'zbekistonning eng yaxshi onlayn kinoteatri. Filmlar, seriallar va multfilmlarni HD sifatda bepul tomosha qiling.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Noto+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ===================== GLOBAL RESET ===================== */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg:          #0a0a0f;
  --bg2:         #111118;
  --bg3:         #1a1a24;
  --border:      #2a2a38;
  --accent:      #e94560;
  --accent2:     #ff6b35;
  --gold:        #fbbf24;
  --text:        #e8e8f0;
  --text2:       #9090a8;
  --text3:       #5a5a70;
  --card-radius: 10px;
  --font-head:   'Oswald', sans-serif;
  --font-body:   'Noto Sans', sans-serif;
}
html { scroll-behavior: smooth; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--font-body);
  font-size: 15px;
  line-height: 1.6;
  min-height: 100vh;
}
a { color: inherit; text-decoration: none; }
img { max-width: 100%; display: block; }
button { cursor: pointer; border: none; background: none; font: inherit; }

/* ===================== SCROLLBAR ===================== */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

/* ===================== NAVBAR ===================== */
.navbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
  background: linear-gradient(180deg, rgba(10,10,15,0.98) 0%, rgba(10,10,15,0.85) 100%);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid var(--border);
  padding: 0 24px;
  height: 64px;
  display: flex; align-items: center; justify-content: space-between;
  gap: 20px;
}
.nav-logo {
  font-family: var(--font-head);
  font-size: 26px;
  font-weight: 700;
  letter-spacing: 1px;
  color: var(--accent);
  text-shadow: 0 0 20px rgba(233,69,96,0.5);
  flex-shrink: 0;
}
.nav-logo span { color: var(--text); }
.nav-links {
  display: flex; align-items: center; gap: 4px;
  list-style: none;
}
.nav-links a {
  padding: 6px 14px;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 500;
  color: var(--text2);
  transition: all 0.2s;
}
.nav-links a:hover, .nav-links a.active {
  color: var(--text);
  background: var(--bg3);
}
.nav-search {
  display: flex; align-items: center;
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 0 14px;
  gap: 8px;
  flex: 1; max-width: 320px;
  transition: border-color 0.2s;
}
.nav-search:focus-within { border-color: var(--accent); }
.nav-search input {
  background: none; border: none; outline: none;
  color: var(--text); font: inherit; font-size: 14px;
  width: 100%; padding: 9px 0;
}
.nav-search input::placeholder { color: var(--text3); }
.nav-search svg { color: var(--text3); flex-shrink: 0; }
.nav-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 18px; border-radius: 8px;
  font-size: 14px; font-weight: 500;
  transition: all 0.2s; cursor: pointer;
}
.btn-primary {
  background: var(--accent); color: #fff;
  border: 1px solid transparent;
}
.btn-primary:hover { background: #c73652; transform: translateY(-1px); }
.btn-outline {
  background: transparent; color: var(--text2);
  border: 1px solid var(--border);
}
.btn-outline:hover { border-color: var(--accent); color: var(--accent); }
.user-avatar {
  width: 36px; height: 36px; border-radius: 50%;
  background: var(--accent); display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 14px; cursor: pointer;
  position: relative;
}

/* ===================== MAIN CONTENT ===================== */
.main { padding-top: 64px; }

/* ===================== HERO BANNER ===================== */
.hero {
  position: relative;
  height: 580px;
  overflow: hidden;
  display: flex; align-items: flex-end;
}
.hero-bg {
  position: absolute; inset: 0;
  background: linear-gradient(135deg, #0a0a0f 0%, #1a0a1a 50%, #0a0a1a 100%);
}
.hero-bg-img {
  position: absolute; inset: 0;
  background-size: cover; background-position: center;
  opacity: 0.3;
  filter: blur(2px);
}
.hero-gradient {
  position: absolute; inset: 0;
  background: linear-gradient(to top, var(--bg) 0%, transparent 60%),
              linear-gradient(to right, var(--bg) 0%, transparent 50%);
}
.hero-content {
  position: relative; z-index: 2;
  padding: 40px 48px;
  max-width: 700px;
}
.hero-badge {
  display: inline-flex; align-items: center; gap: 6px;
  background: var(--accent); color: #fff;
  padding: 4px 12px; border-radius: 20px;
  font-size: 12px; font-weight: 700; letter-spacing: 0.5px;
  text-transform: uppercase; margin-bottom: 16px;
}
.hero-title {
  font-family: var(--font-head);
  font-size: clamp(32px, 5vw, 56px);
  font-weight: 700;
  line-height: 1.1;
  margin-bottom: 12px;
  text-shadow: 0 2px 20px rgba(0,0,0,0.5);
}
.hero-meta {
  display: flex; align-items: center; gap: 16px;
  margin-bottom: 16px; flex-wrap: wrap;
}
.hero-meta span {
  font-size: 13px; color: var(--text2);
  display: flex; align-items: center; gap: 4px;
}
.hero-rating { color: var(--gold) !important; font-weight: 700; }
.hero-desc {
  color: var(--text2); font-size: 15px;
  margin-bottom: 24px;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.hero-actions { display: flex; gap: 12px; flex-wrap: wrap; }
.btn-hero {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 13px 28px; border-radius: 8px;
  font-size: 15px; font-weight: 700;
  letter-spacing: 0.3px; transition: all 0.25s;
}
.btn-hero-play {
  background: var(--accent); color: #fff;
  box-shadow: 0 4px 24px rgba(233,69,96,0.4);
}
.btn-hero-play:hover { transform: translateY(-2px); box-shadow: 0 8px 32px rgba(233,69,96,0.5); }
.btn-hero-info {
  background: rgba(255,255,255,0.1); color: #fff;
  border: 1px solid rgba(255,255,255,0.2);
  backdrop-filter: blur(8px);
}
.btn-hero-info:hover { background: rgba(255,255,255,0.2); }

/* ===================== SECTIONS ===================== */
.section { padding: 40px 48px; }
.section-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 24px;
}
.section-title {
  font-family: var(--font-head);
  font-size: 22px; font-weight: 600;
  letter-spacing: 0.5px;
  display: flex; align-items: center; gap: 10px;
}
.section-title::before {
  content: '';
  display: block; width: 4px; height: 22px;
  background: var(--accent); border-radius: 2px;
}
.see-all {
  font-size: 13px; color: var(--accent);
  display: flex; align-items: center; gap: 4px;
  transition: gap 0.2s;
}
.see-all:hover { gap: 8px; }

/* ===================== MOVIE GRID ===================== */
.movie-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 16px;
}
.movie-card {
  position: relative; border-radius: var(--card-radius);
  overflow: hidden; background: var(--bg2);
  transition: transform 0.25s, box-shadow 0.25s;
  cursor: pointer; group: true;
}
.movie-card:hover { transform: translateY(-6px) scale(1.02); box-shadow: 0 16px 40px rgba(0,0,0,0.6); }
.movie-poster {
  width: 100%; aspect-ratio: 2/3;
  object-fit: cover; display: block;
  background: var(--bg3);
  transition: filter 0.3s;
}
.movie-card:hover .movie-poster { filter: brightness(0.7); }
.movie-overlay {
  position: absolute; inset: 0;
  background: linear-gradient(to top, rgba(0,0,0,0.9) 0%, transparent 60%);
  display: flex; flex-direction: column; justify-content: flex-end;
  padding: 14px;
}
.movie-play-btn {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%,-50%) scale(0);
  width: 48px; height: 48px; border-radius: 50%;
  background: rgba(233,69,96,0.9);
  display: flex; align-items: center; justify-content: center;
  transition: transform 0.25s;
}
.movie-card:hover .movie-play-btn { transform: translate(-50%,-50%) scale(1); }
.movie-badge {
  position: absolute; top: 10px; left: 10px;
  background: var(--accent); color: #fff;
  padding: 2px 8px; border-radius: 4px;
  font-size: 10px; font-weight: 700; text-transform: uppercase;
}
.movie-rating {
  position: absolute; top: 10px; right: 10px;
  background: rgba(0,0,0,0.7); color: var(--gold);
  padding: 3px 8px; border-radius: 4px;
  font-size: 12px; font-weight: 700;
  display: flex; align-items: center; gap: 3px;
  backdrop-filter: blur(4px);
}
.movie-info { position: relative; }
.movie-title {
  font-family: var(--font-head);
  font-size: 14px; font-weight: 600;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  margin-bottom: 4px;
}
.movie-meta2 {
  font-size: 11px; color: var(--text3);
  display: flex; gap: 8px;
}

/* ===================== GENRES STRIP ===================== */
.genres-strip {
  display: flex; gap: 10px; overflow-x: auto;
  padding: 0 48px 24px; scrollbar-width: none;
}
.genres-strip::-webkit-scrollbar { display: none; }
.genre-chip {
  flex-shrink: 0; padding: 8px 18px;
  border-radius: 20px; border: 1px solid var(--border);
  font-size: 13px; color: var(--text2);
  background: var(--bg2); cursor: pointer;
  transition: all 0.2s; white-space: nowrap;
}
.genre-chip:hover, .genre-chip.active {
  border-color: var(--accent); color: var(--accent);
  background: rgba(233,69,96,0.1);
}

/* ===================== FILTERS BAR ===================== */
.filters-bar {
  display: flex; gap: 12px; flex-wrap: wrap;
  padding: 24px 48px 0; align-items: center;
}
.filter-select {
  background: var(--bg2); border: 1px solid var(--border);
  color: var(--text); padding: 9px 14px;
  border-radius: 8px; font: inherit; font-size: 14px;
  cursor: pointer; transition: border-color 0.2s;
}
.filter-select:focus { outline: none; border-color: var(--accent); }

/* ===================== MOVIE DETAIL ===================== */
.detail-hero {
  position: relative; min-height: 500px;
  display: flex; align-items: flex-end;
}
.detail-bg {
  position: absolute; inset: 0;
  background: linear-gradient(to bottom, var(--bg) 0%, var(--bg) 100%);
}
.detail-bg-img {
  position: absolute; inset: 0;
  background-size: cover; background-position: center top;
  opacity: 0.15; filter: blur(8px);
}
.detail-gradient {
  position: absolute; inset: 0;
  background: linear-gradient(to top, var(--bg) 0%, transparent 70%);
}
.detail-content {
  position: relative; z-index: 2;
  display: flex; gap: 40px; align-items: flex-end;
  padding: 40px 48px;
}
.detail-poster {
  width: 220px; flex-shrink: 0; border-radius: 12px;
  overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.6);
}
.detail-poster img { width: 100%; height: auto; display: block; }
.detail-info { flex: 1; }
.detail-title {
  font-family: var(--font-head);
  font-size: clamp(28px, 4vw, 48px);
  font-weight: 700; margin-bottom: 8px;
}
.detail-title-ru { font-size: 18px; color: var(--text2); margin-bottom: 16px; }
.detail-meta {
  display: flex; flex-wrap: wrap; gap: 10px;
  margin-bottom: 20px;
}
.detail-tag {
  background: var(--bg3); border: 1px solid var(--border);
  padding: 4px 12px; border-radius: 6px;
  font-size: 13px; color: var(--text2);
}
.detail-rating-big {
  display: flex; align-items: center; gap: 8px;
  margin-bottom: 16px;
}
.rating-stars { display: flex; gap: 3px; }
.star { color: var(--border); font-size: 18px; cursor: pointer; transition: color 0.1s; }
.star.filled, .star:hover, .star:hover ~ .star { color: var(--gold); }
.detail-desc { color: var(--text2); line-height: 1.7; margin-bottom: 24px; max-width: 600px; }
.detail-actions { display: flex; gap: 12px; flex-wrap: wrap; }
.btn-watch {
  background: var(--accent); color: #fff;
  padding: 14px 32px; border-radius: 10px;
  font-size: 16px; font-weight: 700;
  display: flex; align-items: center; gap: 8px;
  transition: all 0.2s;
  box-shadow: 0 4px 20px rgba(233,69,96,0.35);
}
.btn-watch:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(233,69,96,0.45); }
.btn-fav {
  padding: 14px; border-radius: 10px;
  border: 1px solid var(--border);
  color: var(--text2); font-size: 20px;
  transition: all 0.2s;
}
.btn-fav:hover, .btn-fav.active { border-color: var(--gold); color: var(--gold); }

/* ===================== VIDEO PLAYER ===================== */
.video-section { padding: 0 48px 40px; }
.video-wrap {
  background: #000; border-radius: 12px; overflow: hidden;
  aspect-ratio: 16/9; position: relative;
  box-shadow: 0 20px 60px rgba(0,0,0,0.7);
}
.video-wrap video, .video-wrap iframe {
  width: 100%; height: 100%; display: block;
}
.video-placeholder {
  width: 100%; height: 100%;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  background: radial-gradient(ellipse at center, #1a1a2e 0%, #0a0a0f 100%);
  gap: 16px; color: var(--text2);
}
.video-placeholder .play-big {
  width: 80px; height: 80px; border-radius: 50%;
  background: var(--accent); display: flex; align-items: center; justify-content: center;
  cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;
  box-shadow: 0 0 40px rgba(233,69,96,0.4);
}
.video-placeholder .play-big:hover { transform: scale(1.1); box-shadow: 0 0 60px rgba(233,69,96,0.6); }

/* ===================== COMMENTS ===================== */
.comments-section { padding: 0 48px 40px; max-width: 800px; }
.comments-title {
  font-family: var(--font-head); font-size: 20px;
  margin-bottom: 20px; display: flex; align-items: center; gap: 8px;
}
.comment-form {
  display: flex; gap: 12px; margin-bottom: 28px;
}
.comment-input {
  flex: 1; background: var(--bg2); border: 1px solid var(--border);
  color: var(--text); padding: 12px 16px; border-radius: 10px;
  font: inherit; font-size: 14px; resize: none; height: 80px;
  transition: border-color 0.2s;
}
.comment-input:focus { outline: none; border-color: var(--accent); }
.comment-submit {
  background: var(--accent); color: #fff;
  padding: 0 20px; border-radius: 10px;
  font-weight: 700; font-size: 14px;
  transition: background 0.2s;
  align-self: flex-end; height: 44px;
}
.comment-submit:hover { background: #c73652; }
.comment-item {
  display: flex; gap: 14px; margin-bottom: 20px;
  padding-bottom: 20px; border-bottom: 1px solid var(--border);
}
.comment-avatar {
  width: 40px; height: 40px; border-radius: 50%;
  background: var(--bg3); flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; color: var(--accent);
}
.comment-user { font-weight: 600; font-size: 14px; margin-bottom: 4px; }
.comment-time { font-size: 12px; color: var(--text3); }
.comment-text { font-size: 14px; color: var(--text2); margin-top: 6px; }

/* ===================== MODAL ===================== */
.modal-overlay {
  position: fixed; inset: 0; z-index: 2000;
  background: rgba(0,0,0,0.85); backdrop-filter: blur(8px);
  display: flex; align-items: center; justify-content: center;
  opacity: 0; pointer-events: none; transition: opacity 0.3s;
}
.modal-overlay.open { opacity: 1; pointer-events: all; }
.modal {
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 16px; width: 100%; max-width: 420px;
  padding: 36px; position: relative;
  transform: scale(0.95); transition: transform 0.3s;
}
.modal-overlay.open .modal { transform: scale(1); }
.modal-close {
  position: absolute; top: 16px; right: 16px;
  width: 32px; height: 32px; border-radius: 50%;
  background: var(--bg3); color: var(--text2);
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; transition: all 0.2s;
}
.modal-close:hover { background: var(--accent); color: #fff; }
.modal-title {
  font-family: var(--font-head); font-size: 24px;
  margin-bottom: 24px;
}
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 13px; color: var(--text2); margin-bottom: 6px; }
.form-input {
  width: 100%; background: var(--bg3); border: 1px solid var(--border);
  color: var(--text); padding: 12px 16px; border-radius: 8px;
  font: inherit; font-size: 14px; transition: border-color 0.2s;
}
.form-input:focus { outline: none; border-color: var(--accent); }
.form-error { color: var(--accent); font-size: 13px; margin-top: 8px; min-height: 20px; }
.form-switch {
  text-align: center; margin-top: 16px;
  font-size: 13px; color: var(--text2);
}
.form-switch a { color: var(--accent); cursor: pointer; }

/* ===================== TOAST ===================== */
.toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 3000; display: flex; flex-direction: column; gap: 10px; }
.toast {
  background: var(--bg2); border: 1px solid var(--border);
  border-left: 4px solid var(--accent); color: var(--text);
  padding: 14px 20px; border-radius: 10px;
  font-size: 14px; min-width: 280px;
  box-shadow: 0 8px 30px rgba(0,0,0,0.4);
  animation: slideIn 0.3s ease;
}
.toast.success { border-left-color: #22c55e; }
.toast.error   { border-left-color: var(--accent); }
@keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
@keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }

/* ===================== FOOTER ===================== */
footer {
  background: var(--bg2); border-top: 1px solid var(--border);
  padding: 40px 48px 20px;
  margin-top: 40px;
}
.footer-grid {
  display: grid; grid-template-columns: 2fr 1fr 1fr 1fr;
  gap: 40px; margin-bottom: 32px;
}
.footer-logo { font-family: var(--font-head); font-size: 28px; color: var(--accent); margin-bottom: 12px; }
.footer-desc { font-size: 13px; color: var(--text3); line-height: 1.8; }
.footer-social { display: flex; gap: 10px; margin-top: 16px; }
.social-btn {
  width: 36px; height: 36px; border-radius: 8px;
  background: var(--bg3); border: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; transition: all 0.2s;
}
.social-btn:hover { border-color: var(--accent); background: rgba(233,69,96,0.1); }
.footer-heading { font-family: var(--font-head); font-size: 15px; margin-bottom: 14px; }
.footer-links { list-style: none; display: flex; flex-direction: column; gap: 8px; }
.footer-links a { font-size: 13px; color: var(--text3); transition: color 0.2s; }
.footer-links a:hover { color: var(--accent); }
.footer-bottom {
  border-top: 1px solid var(--border); padding-top: 20px;
  display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;
}
.footer-copy { font-size: 13px; color: var(--text3); }

/* ===================== SEARCH PAGE ===================== */
.search-header { padding: 40px 48px 20px; }
.search-title { font-family: var(--font-head); font-size: 28px; margin-bottom: 6px; }
.search-subtitle { color: var(--text2); font-size: 15px; }
.no-results {
  text-align: center; padding: 80px 48px;
  color: var(--text3);
}
.no-results svg { margin: 0 auto 16px; opacity: 0.3; }

/* ===================== LOADING ===================== */
.loading-overlay {
  position: fixed; inset: 0; z-index: 5000;
  background: var(--bg); display: flex;
  align-items: center; justify-content: center;
  transition: opacity 0.5s;
}
.loading-overlay.hide { opacity: 0; pointer-events: none; }
.loader {
  display: flex; flex-direction: column;
  align-items: center; gap: 16px;
}
.loader-logo { font-family: var(--font-head); font-size: 40px; color: var(--accent); }
.spinner {
  width: 40px; height: 40px; border-radius: 50%;
  border: 3px solid var(--border);
  border-top-color: var(--accent);
  animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ===================== RESPONSIVE ===================== */
@media (max-width: 900px) {
  .navbar { padding: 0 16px; }
  .nav-links { display: none; }
  .section { padding: 32px 16px; }
  .hero-content { padding: 32px 16px; }
  .filters-bar { padding: 16px 16px 0; }
  .genres-strip { padding: 0 16px 16px; }
  .detail-content { flex-direction: column; padding: 24px 16px; }
  .detail-poster { width: 160px; }
  .video-section { padding: 0 16px 32px; }
  .comments-section { padding: 0 16px 32px; }
  .footer-grid { grid-template-columns: 1fr 1fr; }
  footer { padding: 32px 16px 16px; }
  .movie-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
}
@media (max-width: 500px) {
  .hero { height: 440px; }
  .footer-grid { grid-template-columns: 1fr; }
  .nav-search { max-width: 180px; }
}
</style>
</head>
<body>

<!-- LOADING -->
<div class="loading-overlay" id="loading">
  <div class="loader">
    <div class="loader-logo">UzMovi</div>
    <div class="spinner"></div>
  </div>
</div>

<!-- NAVBAR -->
<nav class="navbar">
  <a href="/" class="nav-logo">Uz<span>Movi</span></a>
  <ul class="nav-links">
    <li><a href="/" class="<?= $page==='home'?'active':'' ?>">Bosh sahifa</a></li>
    <li><a href="/movies" class="<?= $page==='movies'?'active':'' ?>">Filmlar</a></li>
    <li><a href="/serials" class="<?= $page==='serials'?'active':'' ?>">Seriallar</a></li>
    <li><a href="/cartoons" class="<?= $page==='cartoons'?'active':'' ?>">Multfilmlar</a></li>
  </ul>
  <form class="nav-search" action="/search" method="get">
    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
    </svg>
    <input type="text" name="q" placeholder="Film qidirish..." value="<?= sanitize($_GET['q'] ?? '') ?>">
  </form>
  <div class="nav-actions">
    <?php if ($currentUser): ?>
      <a href="/favorites" class="btn btn-outline">❤️ Sevimlilar</a>
      <div class="user-avatar" title="<?= sanitize($currentUser['username']) ?>">
        <?= mb_strtoupper(mb_substr($currentUser['username'], 0, 1)) ?>
      </div>
      <a href="/logout" class="btn btn-outline">Chiqish</a>
    <?php else: ?>
      <button class="btn btn-outline" onclick="openModal('login')">Kirish</button>
      <button class="btn btn-primary" onclick="openModal('register')">Ro'yxatdan o'tish</button>
    <?php endif; ?>
  </div>
</nav>

<!-- MAIN CONTENT -->
<main class="main">
<?php

// ============================================================
//  SAHIFALAR
// ============================================================

// ─── HOME ───────────────────────────────────────────────────
if ($page === 'home'):
  $heroMovie = $pageData['trending'][0] ?? null;
?>

  <!-- HERO -->
  <?php if ($heroMovie): ?>
  <section class="hero">
    <div class="hero-bg">
      <?php if (!empty($heroMovie['poster_url'])): ?>
        <div class="hero-bg-img" style="background-image: url('<?= sanitize($heroMovie['poster_url']) ?>')"></div>
      <?php endif; ?>
    </div>
    <div class="hero-gradient"></div>
    <div class="hero-content">
      <div class="hero-badge">🔥 Trendda #1</div>
      <h1 class="hero-title"><?= sanitize($heroMovie['title']) ?></h1>
      <div class="hero-meta">
        <span class="hero-rating">⭐ <?= number_format((float)($heroMovie['rating'] ?? 0), 1) ?></span>
        <span>📅 <?= (int)($heroMovie['year'] ?? 0) ?></span>
        <span>🎬 <?= sanitize($heroMovie['genres'] ?? '') ?></span>
        <?php if (!empty($heroMovie['duration'])): ?>
          <span>⏱ <?= (int)$heroMovie['duration'] ?> daqiqa</span>
        <?php endif; ?>
      </div>
      <p class="hero-desc"><?= sanitize(substr($heroMovie['description'] ?? '', 0, 200)) ?>...</p>
      <div class="hero-actions">
        <a href="/watch/<?= (int)$heroMovie['id'] ?>" class="btn-hero btn-hero-play">
          ▶ Tomosha qilish
        </a>
        <a href="/movie/<?= (int)$heroMovie['id'] ?>" class="btn-hero btn-hero-info">
          ℹ Batafsil
        </a>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- JANRLAR -->
  <div class="genres-strip">
    <?php foreach ($pageData['genres'] as $genre): ?>
      <a href="/movies?genre=<?= (int)$genre['id'] ?>" class="genre-chip">
        <?= sanitize($genre['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- TRENDDA -->
  <section class="section">
    <div class="section-header">
      <h2 class="section-title">🔥 Trendda</h2>
      <a href="/movies" class="see-all">Barchasi →</a>
    </div>
    <div class="movie-grid">
      <?php foreach ($pageData['trending'] as $m): renderMovieCard($m); endforeach; ?>
    </div>
  </section>

  <!-- YANGI FILMLAR -->
  <section class="section">
    <div class="section-header">
      <h2 class="section-title">✨ Yangi filmlar</h2>
      <a href="/movies?new=1" class="see-all">Barchasi →</a>
    </div>
    <div class="movie-grid">
      <?php foreach ($pageData['new'] as $m): renderMovieCard($m); endforeach; ?>
    </div>
  </section>

  <!-- SERIALLAR -->
  <?php if (!empty($pageData['serials'])): ?>
  <section class="section">
    <div class="section-header">
      <h2 class="section-title">📺 Seriallar</h2>
      <a href="/serials" class="see-all">Barchasi →</a>
    </div>
    <div class="movie-grid">
      <?php foreach ($pageData['serials'] as $m): renderMovieCard($m); endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- MULTFILMLAR -->
  <?php if (!empty($pageData['cartoons'])): ?>
  <section class="section">
    <div class="section-header">
      <h2 class="section-title">🎨 Multfilmlar</h2>
      <a href="/cartoons" class="see-all">Barchasi →</a>
    </div>
    <div class="movie-grid">
      <?php foreach ($pageData['cartoons'] as $m): renderMovieCard($m); endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

<?php

// ─── MOVIES / SERIALS / CARTOONS LIST ───────────────────────
elseif (in_array($page, ['movies','serials','cartoons'])):
  $titles = ['movies'=>'Filmlar','serials'=>'Seriallar','cartoons'=>'Multfilmlar'];
?>
  <section class="section">
    <div class="section-header">
      <h2 class="section-title"><?= $titles[$page] ?></h2>
    </div>
    <!-- FILTERS -->
    <div class="filters-bar" style="padding:0 0 24px;">
      <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;">
        <select name="genre" class="filter-select" onchange="this.form.submit()">
          <option value="">Barcha janrlar</option>
          <?php foreach ($pageData['genres'] as $g): ?>
            <option value="<?= (int)$g['id'] ?>" <?= ($_GET['genre']??'')==$g['id']?'selected':'' ?>>
              <?= sanitize($g['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <select name="year" class="filter-select" onchange="this.form.submit()">
          <option value="">Barcha yillar</option>
          <?php for ($y = date('Y'); $y >= 2000; $y--): ?>
            <option value="<?= $y ?>" <?= ($_GET['year']??'')==$y?'selected':'' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
        <input type="text" name="q" class="filter-select" placeholder="Qidirish..."
               value="<?= sanitize($_GET['q'] ?? '') ?>" style="min-width:180px;">
        <button type="submit" class="btn btn-primary">Qidirish</button>
      </form>
    </div>
    <?php if (empty($pageData['movies'])): ?>
      <div class="no-results">
        <div style="font-size:64px;margin-bottom:16px;">🎬</div>
        <div style="font-size:18px;font-weight:600;margin-bottom:8px;">Natija topilmadi</div>
        <div>Boshqa so'z bilan qidirib ko'ring</div>
      </div>
    <?php else: ?>
      <div class="movie-grid">
        <?php foreach ($pageData['movies'] as $m): renderMovieCard($m); endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

<?php

// ─── MOVIE DETAIL ────────────────────────────────────────────
elseif ($page === 'movie_detail'):
  $movie = $pageData['movie'];
  if (!$movie): ?>
    <div class="no-results" style="padding:120px 48px;">
      <div style="font-size:64px;margin-bottom:16px;">😕</div>
      <h2>Film topilmadi</h2>
    </div>
  <?php else: ?>
  <div class="detail-hero">
    <div class="detail-bg">
      <?php if (!empty($movie['poster_url'])): ?>
        <div class="detail-bg-img" style="background-image:url('<?= sanitize($movie['poster_url']) ?>')"></div>
      <?php endif; ?>
    </div>
    <div class="detail-gradient"></div>
    <div class="detail-content">
      <div class="detail-poster">
        <img src="<?= sanitize($movie['poster_url'] ?? 'https://placehold.co/300x450/1a1a2e/e94560?text=🎬') ?>"
             alt="<?= sanitize($movie['title']) ?>">
      </div>
      <div class="detail-info">
        <h1 class="detail-title"><?= sanitize($movie['title']) ?></h1>
        <?php if (!empty($movie['title_ru'])): ?>
          <div class="detail-title-ru"><?= sanitize($movie['title_ru']) ?></div>
        <?php endif; ?>
        <div class="detail-meta">
          <?php if (!empty($movie['year'])): ?>
            <span class="detail-tag">📅 <?= (int)$movie['year'] ?></span>
          <?php endif; ?>
          <?php if (!empty($movie['country'])): ?>
            <span class="detail-tag">🌍 <?= sanitize($movie['country']) ?></span>
          <?php endif; ?>
          <?php if (!empty($movie['duration'])): ?>
            <span class="detail-tag">⏱ <?= (int)$movie['duration'] ?> daqiqa</span>
          <?php endif; ?>
          <?php if (!empty($movie['genres'])): ?>
            <span class="detail-tag">🎭 <?= sanitize($movie['genres']) ?></span>
          <?php endif; ?>
        </div>
        <div class="detail-rating-big">
          <span style="font-size:32px;font-weight:700;color:var(--gold);">
            ⭐ <?= number_format((float)($movie['rating'] ?? 0), 1) ?>
          </span>
          <span style="color:var(--text3);font-size:14px;">/10</span>
          <div class="rating-stars" id="ratingStars" data-movie="<?= (int)$movie['id'] ?>">
            <?php for ($i = 1; $i <= 10; $i++): ?>
              <span class="star <?= $i <= round($movie['rating'] ?? 0) ? 'filled' : '' ?>"
                    onclick="rateMovie(<?= (int)$movie['id'] ?>, <?= $i ?>)" data-val="<?= $i ?>">★</span>
            <?php endfor; ?>
          </div>
        </div>
        <?php if (!empty($movie['description'])): ?>
          <p class="detail-desc"><?= sanitize($movie['description']) ?></p>
        <?php endif; ?>
        <?php if (!empty($movie['actors'])): ?>
          <div style="font-size:13px;color:var(--text3);margin-bottom:20px;">
            <strong style="color:var(--text2);">Aktyorlar:</strong> <?= sanitize($movie['actors']) ?>
          </div>
        <?php endif; ?>
        <div class="detail-actions">
          <a href="/watch/<?= (int)$movie['id'] ?>" class="btn-watch">▶ Tomosha qilish</a>
          <button class="btn-fav" id="favBtn" onclick="toggleFavorite(<?= (int)$movie['id'] ?>)" title="Sevimlilarga qo'shish">♡</button>
        </div>
      </div>
    </div>
  </div>

  <!-- COMMENTS -->
  <div class="comments-section">
    <h3 class="comments-title">💬 Izohlar (<?= count($pageData['comments']) ?>)</h3>
    <?php if (isLoggedIn()): ?>
    <div class="comment-form">
      <textarea class="comment-input" id="commentText" placeholder="Fikringizni yozing..."></textarea>
      <button class="comment-submit" onclick="submitComment(<?= (int)$movie['id'] ?>)">Yuborish</button>
    </div>
    <?php else: ?>
      <div style="padding:16px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;margin-bottom:24px;font-size:14px;color:var(--text2);">
        Izoh qoldirish uchun <a href="#" onclick="openModal('login')" style="color:var(--accent);">tizimga kiring</a>
      </div>
    <?php endif; ?>
    <div id="commentsList">
      <?php foreach ($pageData['comments'] as $c): ?>
        <div class="comment-item">
          <div class="comment-avatar">
            <?= mb_strtoupper(mb_substr($c['username'] ?? 'U', 0, 1)) ?>
          </div>
          <div>
            <div class="comment-user"><?= sanitize($c['username'] ?? 'Anonim') ?>
              <span class="comment-time">· <?= date('d.m.Y', strtotime($c['created_at'])) ?></span>
            </div>
            <div class="comment-text"><?= sanitize($c['text']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($pageData['comments'])): ?>
        <div style="text-align:center;padding:40px;color:var(--text3);">Hali izoh yo'q. Birinchi bo'lib yozing!</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- RELATED MOVIES -->
  <?php if (!empty($pageData['related'])): ?>
  <section class="section">
    <div class="section-header">
      <h2 class="section-title">🎬 O'xshash filmlar</h2>
    </div>
    <div class="movie-grid">
      <?php foreach ($pageData['related'] as $m):
        if ($m['id'] !== $movie['id']): renderMovieCard($m); endif;
      endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

<?php endif;

// ─── WATCH ───────────────────────────────────────────────────
elseif ($page === 'watch'):
  $movie = $itemId ? getMovieById($itemId) : getDemoMovies('movie',1)[0];
?>
  <div style="padding:24px 48px 40px;">
    <?php if ($movie): ?>
    <h2 style="font-family:var(--font-head);font-size:22px;margin-bottom:16px;">
      ▶ <?= sanitize($movie['title']) ?>
    </h2>
    <?php endif; ?>
    <div class="video-wrap" style="max-width:1100px;border-radius:12px;">
      <?php if (!empty($movie['video_url'])): ?>
        <video controls autoplay>
          <source src="<?= sanitize($movie['video_url']) ?>" type="video/mp4">
        </video>
      <?php elseif (!empty($movie['embed_url'])): ?>
        <iframe src="<?= sanitize($movie['embed_url']) ?>" allowfullscreen frameborder="0" allow="autoplay"></iframe>
      <?php else: ?>
        <div class="video-placeholder">
          <div class="play-big" onclick="this.innerHTML='⏳ Yuklanmoqda...'">
            <svg width="28" height="28" fill="#fff" viewBox="0 0 24 24">
              <path d="M8 5v14l11-7z"/>
            </svg>
          </div>
          <div style="font-size:14px;">Video yuklanmoqda...</div>
          <div style="font-size:12px;color:var(--text3);"><?= $movie ? sanitize($movie['title']) : 'Film' ?></div>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php
  // Ko'rishlar sonini oshirish
  if ($itemId) {
    echo "<script>fetch('/api/view',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({movie_id:$itemId})});</script>";
  }

// ─── SEARCH ──────────────────────────────────────────────────
elseif ($page === 'search'):
?>
  <div class="search-header">
    <h1 class="search-title">
      <?php if ($pageData['query']): ?>
        "<?= sanitize($pageData['query']) ?>" bo'yicha natijalar
      <?php else: ?>
        Qidirish
      <?php endif; ?>
    </h1>
    <?php if ($pageData['movies']): ?>
      <div class="search-subtitle"><?= count($pageData['movies']) ?> ta natija topildi</div>
    <?php endif; ?>
  </div>
  <?php if (empty($pageData['movies'])): ?>
    <div class="no-results">
      <div style="font-size:64px;margin-bottom:16px;">🔍</div>
      <div style="font-size:18px;font-weight:600;margin-bottom:8px;">Natija topilmadi</div>
      <div>Boshqa so'z bilan qidirib ko'ring</div>
    </div>
  <?php else: ?>
    <section class="section" style="padding-top:0;">
      <div class="movie-grid">
        <?php foreach ($pageData['movies'] as $m): renderMovieCard($m); endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

<?php

// ─── LOGIN ───────────────────────────────────────────────────
elseif ($page === 'login'):
  echo "<script>window.addEventListener('load',()=>openModal('login'));</script>";

// ─── REGISTER ────────────────────────────────────────────────
elseif ($page === 'register'):
  echo "<script>window.addEventListener('load',()=>openModal('register'));</script>";

// ─── FAVORITES ───────────────────────────────────────────────
elseif ($page === 'favorites'):
?>
  <section class="section">
    <div class="section-header">
      <h2 class="section-title">❤️ Sevimli filmlarim</h2>
    </div>
    <?php if (empty($pageData['movies'])): ?>
      <div class="no-results">
        <div style="font-size:64px;margin-bottom:16px;">❤️</div>
        <div style="font-size:18px;font-weight:600;margin-bottom:8px;">Sevimlilarga film qo'shilmagan</div>
        <div><a href="/movies" style="color:var(--accent);">Film ko'rishni boshlang</a></div>
      </div>
    <?php else: ?>
      <div class="movie-grid">
        <?php foreach ($pageData['movies'] as $m): renderMovieCard($m); endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

</main>

<!-- AUTH MODALS -->
<div class="modal-overlay" id="authModal" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <button class="modal-close" onclick="closeModal()">×</button>

    <!-- LOGIN FORM -->
    <div id="loginForm">
      <h2 class="modal-title">Kirish</h2>
      <div class="form-group">
        <label>Email manzil</label>
        <input type="email" class="form-input" id="loginEmail" placeholder="email@misol.com">
      </div>
      <div class="form-group">
        <label>Parol</label>
        <input type="password" class="form-input" id="loginPass" placeholder="••••••••">
      </div>
      <div class="form-error" id="loginError"></div>
      <button class="btn btn-primary" style="width:100%;padding:13px;font-size:16px;border-radius:8px;margin-top:4px;" onclick="doLogin()">Kirish</button>
      <div class="form-switch">
        Hisob yo'qmi? <a onclick="switchModal('register')">Ro'yxatdan o'ting</a>
      </div>
      <div style="margin-top:12px;padding:12px;background:var(--bg3);border-radius:8px;font-size:12px;color:var(--text3);">
        <strong>Demo:</strong> demo@uzmovi.uz / demo123
      </div>
    </div>

    <!-- REGISTER FORM -->
    <div id="registerForm" style="display:none;">
      <h2 class="modal-title">Ro'yxatdan o'tish</h2>
      <div class="form-group">
        <label>Foydalanuvchi nomi</label>
        <input type="text" class="form-input" id="regUsername" placeholder="Ismingiz">
      </div>
      <div class="form-group">
        <label>Email manzil</label>
        <input type="email" class="form-input" id="regEmail" placeholder="email@misol.com">
      </div>
      <div class="form-group">
        <label>Parol (kamida 6 ta belgi)</label>
        <input type="password" class="form-input" id="regPass" placeholder="••••••••">
      </div>
      <div class="form-error" id="registerError"></div>
      <button class="btn btn-primary" style="width:100%;padding:13px;font-size:16px;border-radius:8px;margin-top:4px;" onclick="doRegister()">Ro'yxatdan o'tish</button>
      <div class="form-switch">
        Hisobingiz bormi? <a onclick="switchModal('login')">Kiring</a>
      </div>
    </div>
  </div>
</div>

<!-- TOAST CONTAINER -->
<div class="toast-container" id="toastContainer"></div>

<!-- FOOTER -->
<footer>
  <div class="footer-grid">
    <div>
      <div class="footer-logo">UzMovi</div>
      <p class="footer-desc">O'zbekistonning eng yaxshi onlayn kinoteatri. Filmlar, seriallar va multfilmlarni HD sifatda bepul tomosha qiling.</p>
      <div class="footer-social">
        <a href="https://t.me/IT_MENTOR_UZ" target="_blank" class="social-btn" title="Telegram">✈️</a>
        <a href="https://instagram.com/IT_MENTOR_UZ" target="_blank" class="social-btn" title="Instagram">📷</a>
        <a href="https://youtube.com/@IT_MENTOR_UZ" target="_blank" class="social-btn" title="YouTube">▶️</a>
      </div>
    </div>
    <div>
      <div class="footer-heading">Tezkor havolalar</div>
      <ul class="footer-links">
        <li><a href="/">Bosh sahifa</a></li>
        <li><a href="/movies">Filmlar</a></li>
        <li><a href="/serials">Seriallar</a></li>
        <li><a href="/cartoons">Multfilmlar</a></li>
      </ul>
    </div>
    <div>
      <div class="footer-heading">Janrlar</div>
      <ul class="footer-links">
        <?php foreach (array_slice($pageData['genres'] ?? getGenres(), 0, 6) as $g): ?>
          <li><a href="/movies?genre=<?= (int)$g['id'] ?>"><?= sanitize($g['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div>
      <div class="footer-heading">Loyiha</div>
      <ul class="footer-links">
        <li><a href="#">Biz haqimizda</a></li>
        <li><a href="#">Kontakt</a></li>
        <li><a href="#">Maxfiylik siyosati</a></li>
        <li><a href="https://t.me/IT_MENTOR_UZ" target="_blank">@IT_MENTOR_UZ</a></li>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="footer-copy">© <?= date('Y') ?> UzMovi. Barcha huquqlar himoyalangan.</div>
    <div style="font-size:13px;color:var(--text3);">
      PHP + PostgreSQL | <a href="https://t.me/IT_MENTOR_UZ" style="color:var(--accent);">@IT_MENTOR_UZ</a>
    </div>
  </div>
</footer>

<script>
// ============================================================
//  JAVASCRIPT
// ============================================================

// --- LOADING SCREEN ---
window.addEventListener('load', () => {
  setTimeout(() => {
    const l = document.getElementById('loading');
    if (l) { l.classList.add('hide'); setTimeout(() => l.remove(), 500); }
  }, 600);
});

// --- MODAL ---
function openModal(type = 'login') {
  document.getElementById('authModal').classList.add('open');
  switchModal(type);
}
function closeModal() {
  document.getElementById('authModal').classList.remove('open');
}
function switchModal(type) {
  document.getElementById('loginForm').style.display    = type === 'login' ? 'block' : 'none';
  document.getElementById('registerForm').style.display = type === 'register' ? 'block' : 'none';
  document.getElementById('loginError').textContent    = '';
  document.getElementById('registerError').textContent = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// --- LOGIN ---
async function doLogin() {
  const email = document.getElementById('loginEmail').value.trim();
  const pass  = document.getElementById('loginPass').value;
  const errEl = document.getElementById('loginError');
  if (!email || !pass) { errEl.textContent = 'Barcha maydonlarni to\'ldiring'; return; }
  try {
    const res  = await fetch('/api/login', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({email, password:pass}) });
    const data = await res.json();
    if (data.success) {
      showToast('Xush kelibsiz, ' + data.username + '!', 'success');
      closeModal();
      setTimeout(() => location.reload(), 800);
    } else {
      errEl.textContent = data.error || 'Xato yuz berdi';
    }
  } catch { errEl.textContent = 'Serverga ulanib bo\'lmadi'; }
}

// --- REGISTER ---
async function doRegister() {
  const username = document.getElementById('regUsername').value.trim();
  const email    = document.getElementById('regEmail').value.trim();
  const pass     = document.getElementById('regPass').value;
  const errEl    = document.getElementById('registerError');
  if (!username || !email || !pass) { errEl.textContent = 'Barcha maydonlarni to\'ldiring'; return; }
  if (pass.length < 6) { errEl.textContent = 'Parol kamida 6 ta belgi bo\'lishi kerak'; return; }
  try {
    const res  = await fetch('/api/register', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({username, email, password:pass}) });
    const data = await res.json();
    if (data.success) {
      showToast('Ro\'yxatdan o\'tdingiz!', 'success');
      closeModal();
      setTimeout(() => location.reload(), 800);
    } else {
      errEl.textContent = data.error || 'Xato yuz berdi';
    }
  } catch { errEl.textContent = 'Serverga ulanib bo\'lmadi'; }
}

// --- ENTER TUGMASI ---
document.addEventListener('keydown', e => {
  if (e.key === 'Enter') {
    if (document.getElementById('loginForm').style.display !== 'none') doLogin();
    else if (document.getElementById('registerForm').style.display !== 'none') doRegister();
  }
});

// --- SEVIMLILARGA QO'SHISH ---
async function toggleFavorite(movieId) {
  try {
    const res  = await fetch('/api/favorites', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({movie_id:movieId}) });
    const data = await res.json();
    if (!data.success && data.error === 'Tizimga kirish kerak') {
      openModal('login'); return;
    }
    const btn = document.getElementById('favBtn');
    if (data.action === 'added') {
      btn.textContent = '♥'; btn.classList.add('active');
      showToast('Sevimlilarga qo\'shildi ❤️', 'success');
    } else {
      btn.textContent = '♡'; btn.classList.remove('active');
      showToast('Sevimlilardan o\'chirildi', '');
    }
  } catch { showToast('Xato yuz berdi', 'error'); }
}

// --- REYTING ---
async function rateMovie(movieId, rating) {
  try {
    const res  = await fetch('/api/rate', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({movie_id:movieId, rating}) });
    const data = await res.json();
    if (!data.success && data.error?.includes('kirish')) { openModal('login'); return; }
    if (data.success) showToast('Reytingiz saqlandi: ' + rating + '/10 ⭐', 'success');
    // Yulduzlarni yangilash
    document.querySelectorAll('#ratingStars .star').forEach((s, i) => {
      s.classList.toggle('filled', i < rating);
    });
  } catch { showToast('Xato yuz berdi', 'error'); }
}

// --- IZOH YUBORISH ---
async function submitComment(movieId) {
  const text  = document.getElementById('commentText').value.trim();
  const errEl = null;
  if (!text) { showToast('Izoh matni kiritilmagan', 'error'); return; }
  try {
    const res  = await fetch('/api/comments', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({movie_id:movieId, text}) });
    const data = await res.json();
    if (data.success) {
      document.getElementById('commentText').value = '';
      // Yangi izohni ro'yxatga qo'shish
      const list = document.getElementById('commentsList');
      const div  = document.createElement('div');
      div.className = 'comment-item';
      div.innerHTML = `
        <div class="comment-avatar">${data.username.charAt(0).toUpperCase()}</div>
        <div>
          <div class="comment-user">${escapeHtml(data.username)} <span class="comment-time">· Hozir</span></div>
          <div class="comment-text">${escapeHtml(text)}</div>
        </div>
      `;
      list.insertBefore(div, list.firstChild);
      showToast('Izoh qo\'shildi!', 'success');
    } else {
      if (data.error?.includes('kirish')) openModal('login');
      else showToast(data.error || 'Xato', 'error');
    }
  } catch { showToast('Serverga ulanib bo\'lmadi', 'error'); }
}

// --- TOAST ---
function showToast(msg, type = '') {
  const c    = document.getElementById('toastContainer');
  const div  = document.createElement('div');
  div.className = 'toast ' + type;
  div.textContent = msg;
  c.appendChild(div);
  setTimeout(() => {
    div.style.animation = 'slideOut 0.3s ease forwards';
    setTimeout(() => div.remove(), 300);
  }, 3000);
}

function escapeHtml(str) {
  return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// --- NAVBAR SCROLL EFFECT ---
window.addEventListener('scroll', () => {
  const nav = document.querySelector('.navbar');
  nav.style.boxShadow = window.scrollY > 10 ? '0 4px 24px rgba(0,0,0,0.4)' : 'none';
});
</script>

</body>
</html>
<?php

// ============================================================
//  MOVIE CARD HELPER FUNCTION
//  (PHP funksiyasi - global, render qilishdan oldin kerak)
// ============================================================
function renderMovieCard(array $m): void {
    $title   = htmlspecialchars($m['title'] ?? '', ENT_QUOTES, 'UTF-8');
    $poster  = htmlspecialchars($m['poster_url'] ?? 'https://placehold.co/300x450/1a1a2e/e94560?text=🎬', ENT_QUOTES, 'UTF-8');
    $year    = (int)($m['year'] ?? 0);
    $rating  = number_format((float)($m['rating'] ?? 0), 1);
    $type    = $m['type'] ?? 'movie';
    $id      = (int)($m['id'] ?? 0);
    $isNew   = !empty($m['is_new']);
    $genres  = htmlspecialchars($m['genres'] ?? '', ENT_QUOTES, 'UTF-8');
    echo "
    <a href='/movie/$id' class='movie-card'>
      <img src='$poster' alt='$title' class='movie-poster' loading='lazy'>
      <div class='movie-overlay'>
        <div class='movie-play-btn'>
          <svg width='18' height='18' fill='#fff' viewBox='0 0 24 24'><path d='M8 5v14l11-7z'/></svg>
        </div>
        " . ($isNew ? "<div class='movie-badge'>Yangi</div>" : "") . "
        <div class='movie-rating'>⭐ $rating</div>
        <div class='movie-info'>
          <div class='movie-title'>$title</div>
          <div class='movie-meta2'>
            <span>$year</span>
            " . ($genres ? "<span>$genres</span>" : "") . "
          </div>
        </div>
      </div>
    </a>";
}
?>
