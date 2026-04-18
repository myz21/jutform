<?php

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function respond_json(int $statusCode, array $payload, bool $force200 = false): void
{
    http_response_code($force200 ? 200 : $statusCode);
    echo json_encode($payload, JSON_PRETTY_PRINT) . "\n";
    exit;
}

function get_db(): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST'),
        getenv('DB_PORT'),
        getenv('DB_NAME')
    );

    return new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

function queue_file_path(): string
{
    return '/tmp/jutform-practice-queue.json';
}

function load_queue(): array
{
    $file = queue_file_path();
    if (!file_exists($file)) {
        return [];
    }

    $raw = file_get_contents($file);
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function save_queue(array $jobs): void
{
    file_put_contents(queue_file_path(), json_encode(array_values($jobs), JSON_PRETTY_PRINT));
}

function submission_trash_file_path(): string
{
    return '/tmp/jutform-submission-trash.json';
}

function load_submission_trash(): array
{
    $file = submission_trash_file_path();
    if (!file_exists($file)) {
        return [];
    }

    $raw = file_get_contents($file);
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function save_submission_trash(array $items): void
{
    file_put_contents(submission_trash_file_path(), json_encode($items, JSON_PRETTY_PRINT));
}

function normalize_search_text(string $value): string
{
    $lower = mb_strtolower($value, 'UTF-8');
    // Strip combining dot above produced by some Turkish case conversions.
    $lower = str_replace("\u{0307}", '', $lower);

    return strtr($lower, [
        'ı' => 'i',
        'ş' => 's',
        'ğ' => 'g',
        'ü' => 'u',
        'ö' => 'o',
        'ç' => 'c',
    ]);
}

// JF-101: Duplicate submit behavior
if ($path === '/api/forms/submit' && $method === 'POST') {
    $body = read_json_body();
    $formId = $body['form_id'] ?? 'unknown';
    $email = $body['email'] ?? 'unknown@example.com';
    $name = sprintf('submission:%s:%s', $formId, $email);

    try {
        $pdo = get_db();

        if (empty($body['form_id']) || empty($body['email'])) {
            respond_json(422, [
                'ok' => false,
                'error' => 'form_id and email are required',
            ]);
        }

        $windowSeconds = 10;
        $lockName = 'submit_lock:' . sha1($name);
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 2) AS lock_ok');
        $lockStmt->execute(['lock_name' => $lockName]);
        $lockOk = (int)$lockStmt->fetchColumn();

        if ($lockOk !== 1) {
            respond_json(409, [
                'ok' => false,
                'message' => 'Submission is already being processed',
            ]);
        }

        $check = $pdo->prepare('SELECT id FROM preflight_check WHERE check_name = :name AND checked_at >= (NOW() - INTERVAL :window SECOND) LIMIT 1');
        $check->bindValue(':name', $name, PDO::PARAM_STR);
        $check->bindValue(':window', $windowSeconds, PDO::PARAM_INT);
        $check->execute();
        $recentDuplicate = (bool)$check->fetch(PDO::FETCH_ASSOC);

        if ($recentDuplicate) {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lockName]);

            respond_json(200, [
                'ok' => true,
                'message' => 'Duplicate submit ignored',
                'deduplicated' => true,
            ]);
        }

        $insert = $pdo->prepare('INSERT INTO preflight_check (check_name, checked_at) VALUES (:name, NOW())');
        $insert->execute(['name' => $name]);

        $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);

        respond_json(201, [
            'ok' => true,
            'message' => 'Submission accepted',
            'deduplicated' => false,
            'writes' => 1,
        ]);
    } catch (Exception $e) {
        respond_json(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

// JF-102: Health endpoint should be stable and deterministic.
if ($path === '/api/health') {

    $results = [];
    $allPassed = true;

    // PHP version
    $results['php'] = [
        'status' => 'ok',
        'version' => PHP_VERSION,
    ];

    // Required extensions
    $requiredExtensions = ['pdo', 'pdo_mysql', 'redis', 'json', 'intl', 'zip', 'imagick'];
    $extensions = [];
    foreach ($requiredExtensions as $ext) {
        $loaded = extension_loaded($ext);
        $extensions[$ext] = $loaded ? 'ok' : 'missing';
        if (!$loaded) $allPassed = false;
    }
    $results['extensions'] = $extensions;

    // MySQL
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_NAME') . '_staging');
        $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        $row = $pdo->query('SELECT COUNT(*) as cnt FROM preflight_check')->fetch(PDO::FETCH_ASSOC);
        $results['mysql'] = [
            'status' => 'ok',
            'version' => $version,
            'test_query' => 'passed (read ' . $row['cnt'] . ' rows)',
        ];
    } catch (Exception $e) {
        $results['mysql'] = ['status' => 'error', 'message' => $e->getMessage()];
        $allPassed = false;
    }

    // Redis
    try {
        $redis = new Redis();
        $connected = $redis->connect(getenv('REDIS_HOST'), (int)getenv('REDIS_PORT'), 5);
        if (!$connected) throw new Exception('Connection failed');
        $redis->set('preflight_test', 'ok', 10);
        $val = $redis->get('preflight_test');
        $results['redis'] = [
            'status' => 'ok',
            'server_info' => $redis->info('server')['redis_version'] ?? 'unknown',
            'test_write_read' => $val === 'ok' ? 'passed' : 'failed',
        ];
        $redis->close();
    } catch (Exception $e) {
        $results['redis'] = ['status' => 'error', 'message' => $e->getMessage()];
        $allPassed = false;
    }

    // Mailpit
    try {
        $mailHost = getenv('MAIL_HOST');
        $mailPort = (int)getenv('MAIL_PORT');
        $sock = @fsockopen($mailHost, $mailPort, $errno, $errstr, 5);
        if ($sock) {
            fclose($sock);
            $results['mailpit'] = ['status' => 'ok', 'smtp_port' => $mailPort];
        } else {
            throw new Exception("Cannot connect to $mailHost:$mailPort - $errstr");
        }
    } catch (Exception $e) {
        $results['mailpit'] = ['status' => 'error', 'message' => $e->getMessage()];
        $allPassed = false;
    }

    $response = [
        'overall' => $allPassed ? 'ALL CHECKS PASSED' : 'SOME CHECKS FAILED',
        'checks' => $results,
    ];

    http_response_code(200);
    echo json_encode($response, JSON_PRETTY_PRINT) . "\n";
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not found. Try /api/health']) . "\n";
