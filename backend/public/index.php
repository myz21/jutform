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

        // JF-111: invalid request must not return HTTP 200.
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

// JF-103: Locale-unsafe search and sort for Turkish names.
if ($path === '/api/submissions/search' && $method === 'GET') {
    $q = $_GET['q'] ?? '';
    $rows = [
        ['id' => 1, 'name' => 'Ipek'],
        ['id' => 2, 'name' => 'ipek'],
        ['id' => 3, 'name' => 'İpek'],
        ['id' => 4, 'name' => 'Ismail'],
        ['id' => 5, 'name' => 'İsmail'],
    ];

    $normalizedQuery = normalize_search_text((string)$q);

    $result = array_values(array_filter($rows, function ($row) use ($normalizedQuery) {
        $normalizedName = normalize_search_text((string)$row['name']);
        return strpos($normalizedName, $normalizedQuery) !== false;
    }));

    if (class_exists('Collator')) {
        $collator = new Collator('tr_TR');
        $collator->setStrength(Collator::PRIMARY);
        usort($result, function ($a, $b) use ($collator) {
            return $collator->compare((string)$a['name'], (string)$b['name']);
        });
    } else {
        usort($result, function ($a, $b) {
            return strcmp(
                normalize_search_text((string)$a['name']),
                normalize_search_text((string)$b['name'])
            );
        });
    }

    respond_json(200, ['items' => $result]);
}

// JF-104: Log filters ignored, no total metadata.
if ($path === '/api/admin/logs' && $method === 'GET') {
    $logs = [
        ['id' => 1, 'event' => 'login', 'created_at' => '2026-04-01 10:00:00'],
        ['id' => 2, 'event' => 'submit', 'created_at' => '2026-04-02 11:00:00'],
        ['id' => 3, 'event' => 'email_sent', 'created_at' => '2026-04-03 12:00:00'],
        ['id' => 4, 'event' => 'submit', 'created_at' => '2026-04-15 09:30:00'],
        ['id' => 5, 'event' => 'submit', 'created_at' => '2026-04-28 17:10:00'],
    ];

    $event = trim((string)($_GET['event'] ?? ''));
    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    $dateTo = trim((string)($_GET['date_to'] ?? ''));
    $page = (int)($_GET['page'] ?? 1);
    $perPage = (int)($_GET['per_page'] ?? 20);

    if ($page < 1) {
        $page = 1;
    }
    if ($perPage < 1) {
        $perPage = 1;
    }
    if ($perPage > 100) {
        $perPage = 100;
    }

    $fromTs = $dateFrom !== '' ? strtotime($dateFrom . ' 00:00:00') : null;
    $toTs = $dateTo !== '' ? strtotime($dateTo . ' 23:59:59') : null;

    $filtered = array_values(array_filter($logs, function ($row) use ($event, $fromTs, $toTs) {
        if ($event !== '' && (string)$row['event'] !== $event) {
            return false;
        }

        $rowTs = strtotime((string)$row['created_at']);
        if ($fromTs !== null && $rowTs < $fromTs) {
            return false;
        }
        if ($toTs !== null && $rowTs > $toTs) {
            return false;
        }

        return true;
    }));

    usort($filtered, function ($a, $b) {
        return strcmp((string)$b['created_at'], (string)$a['created_at']);
    });

    $total = count($filtered);
    $offset = ($page - 1) * $perPage;
    $items = array_slice($filtered, $offset, $perPage);

    respond_json(200, [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
    ]);
}

// JF-105: Queue worker that leaves old jobs stuck.
if ($path === '/api/queue/enqueue' && $method === 'POST') {
    $body = read_json_body();
    $jobs = load_queue();
    $maxAttempts = (int)($body['max_attempts'] ?? 3);
    if ($maxAttempts < 1) {
        $maxAttempts = 1;
    }
    if ($maxAttempts > 10) {
        $maxAttempts = 10;
    }

    $jobs[] = [
        'id' => uniqid('job_', true),
        'type' => $body['type'] ?? 'email',
        'created_at' => time(),
        'status' => 'queued',
        'attempts' => 0,
        'max_attempts' => $maxAttempts,
        'next_retry_at' => 0,
        'last_error' => null,
        'force_fail' => (bool)($body['force_fail'] ?? false),
    ];
    save_queue($jobs);
    respond_json(202, ['queued' => count($jobs)]);
}

if ($path === '/api/queue/process' && $method === 'POST') {
    $jobs = load_queue();
    if (!$jobs) {
        respond_json(200, ['processed' => 0, 'remaining' => 0, 'failed' => 0]);
    }

    $now = time();
    $jobIndex = null;

    // FIFO fairness: process the oldest eligible job first.
    foreach ($jobs as $idx => $job) {
        $status = (string)($job['status'] ?? 'queued');
        $nextRetryAt = (int)($job['next_retry_at'] ?? 0);

        if (($status === 'queued' || $status === 'retry') && $nextRetryAt <= $now) {
            $jobIndex = $idx;
            break;
        }
    }

    if ($jobIndex === null) {
        $failedCount = count(array_filter($jobs, function ($job) {
            return (string)($job['status'] ?? '') === 'failed';
        }));
        respond_json(200, [
            'processed' => 0,
            'remaining' => count($jobs),
            'failed' => $failedCount,
            'note' => 'no eligible job to process',
        ]);
    }

    $job = $jobs[$jobIndex];
    $job['status'] = 'processing';
    $job['started_at'] = $now;

    $type = (string)($job['type'] ?? 'email');
    $shouldFail = (bool)($job['force_fail'] ?? false) || strpos($type, 'fail') !== false;

    if ($shouldFail) {
        $job['attempts'] = (int)($job['attempts'] ?? 0) + 1;
        $job['last_error'] = 'Simulated worker failure';

        $maxAttempts = (int)($job['max_attempts'] ?? 3);
        if ($job['attempts'] >= $maxAttempts) {
            $job['status'] = 'failed';
            $job['failed_at'] = $now;
            $job['next_retry_at'] = 0;
        } else {
            $job['status'] = 'retry';
            $job['next_retry_at'] = $now + min(30, 2 ** $job['attempts']);
        }

        $jobs[$jobIndex] = $job;
        save_queue($jobs);

        $failedCount = count(array_filter($jobs, function ($queuedJob) {
            return (string)($queuedJob['status'] ?? '') === 'failed';
        }));

        respond_json(200, [
            'processed' => 0,
            'remaining' => count($jobs),
            'failed' => $failedCount,
            'retried' => $job['status'] === 'retry',
            'job_id' => $job['id'],
            'attempts' => $job['attempts'],
            'note' => 'worker completed with retry/failure',
        ]);
    }

    unset($jobs[$jobIndex]);
    $jobs = array_values($jobs);
    save_queue($jobs);

    $failedCount = count(array_filter($jobs, function ($queuedJob) {
        return (string)($queuedJob['status'] ?? '') === 'failed';
    }));

    respond_json(200, [
        'processed' => 1,
        'remaining' => count($jobs),
        'failed' => $failedCount,
        'job_id' => $job['id'],
        'note' => 'worker completed',
    ]);
}

// JF-106: Broken CSV export escaping.
if ($path === '/api/export/csv' && $method === 'GET') {
    header('Content-Type: text/csv; charset=UTF-8');
    $rows = [
        ['id' => 1, 'name' => 'Alice', 'note' => 'simple'],
        ['id' => 2, 'name' => 'Bob', 'note' => 'value,with,commas'],
        ['id' => 3, 'name' => 'Carol "The Great"', 'note' => "multi\nline"],
    ];

    $out = fopen('php://output', 'w');
    if ($out === false) {
        respond_json(500, ['ok' => false, 'error' => 'Failed to open output stream']);
    }

    fputcsv($out, ['id', 'name', 'note']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['id'], $row['name'], $row['note']]);
    }
    fclose($out);
    exit;
}

// JF-107: Missing zero-fill days in daily stats.
if ($path === '/api/stats/daily-submissions' && $method === 'GET') {
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    $knownCounts = [
        $today->sub(new DateInterval('P7D'))->format('Y-m-d') => 5,
        $today->sub(new DateInterval('P3D'))->format('Y-m-d') => 2,
        $today->sub(new DateInterval('P1D'))->format('Y-m-d') => 9,
    ];

    $days = [];
    for ($i = 29; $i >= 0; $i--) {
        $date = $today->sub(new DateInterval('P' . $i . 'D'))->format('Y-m-d');
        $days[] = [
            'date' => $date,
            'count' => $knownCounts[$date] ?? 0,
        ];
    }

    respond_json(200, [
        'days' => $days,
    ]);
}

// JF-108: Password reset token already expired.
if ($path === '/api/auth/request-reset' && $method === 'POST') {
    $body = read_json_body();
    $email = trim((string)($body['email'] ?? ''));
    if ($email === '') {
        respond_json(422, [
            'ok' => false,
            'error' => 'email is required',
        ]);
    }

    $issuedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expiresAt = $issuedAt->add(new DateInterval('PT15M'));

    respond_json(200, [
        'email' => $email,
        'token' => bin2hex(random_bytes(8)),
        'issued_at' => $issuedAt->format(DATE_ATOM),
        'expires_at' => $expiresAt->format(DATE_ATOM),
        'ttl_seconds' => 900,
    ]);
}

// JF-109: No validation for admin action parameters.
if ($path === '/api/admin/action' && $method === 'POST') {
    $body = read_json_body();

    $allowedQueryKeys = [];
    $unknownQueryKeys = array_values(array_diff(array_keys($_GET), $allowedQueryKeys));
    if ($unknownQueryKeys) {
        respond_json(422, [
            'ok' => false,
            'error' => 'Unexpected query parameters',
            'fields' => $unknownQueryKeys,
        ]);
    }

    $allowedBodyKeys = ['action', 'target_id', 'reason'];
    $unknownBodyKeys = array_values(array_diff(array_keys($body), $allowedBodyKeys));
    if ($unknownBodyKeys) {
        respond_json(422, [
            'ok' => false,
            'error' => 'Unexpected body parameters',
            'fields' => $unknownBodyKeys,
        ]);
    }

    $action = trim((string)($body['action'] ?? ''));
    if ($action === '') {
        respond_json(422, [
            'ok' => false,
            'error' => 'action is required',
        ]);
    }

    $allowedActions = ['reindex_logs', 'replay_email', 'archive_submission'];
    if (!in_array($action, $allowedActions, true)) {
        respond_json(422, [
            'ok' => false,
            'error' => 'Invalid action',
            'allowed_actions' => $allowedActions,
        ]);
    }

    respond_json(200, [
        'performed' => true,
        'action' => $action,
        'target_id' => $body['target_id'] ?? null,
        'reason' => $body['reason'] ?? null,
    ]);
}

// JF-110: Hard delete, no restore window.
if ($path === '/api/submissions/delete' && $method === 'POST') {
    $body = read_json_body();
    $id = $body['id'] ?? null;
    if ($id === null || $id === '') {
        respond_json(422, [
            'ok' => false,
            'error' => 'id is required',
        ]);
    }

    $trash = load_submission_trash();
    $now = time();
    $restoreUntil = $now + (30 * 24 * 60 * 60);
    $trash[(string)$id] = [
        'id' => $id,
        'deleted_at' => $now,
        'restore_until' => $restoreUntil,
        'status' => 'deleted',
    ];
    save_submission_trash($trash);

    respond_json(200, [
        'deleted' => true,
        'id' => $id,
        'mode' => 'soft-delete',
        'restore_until' => date(DATE_ATOM, $restoreUntil),
    ]);
}

if ($path === '/api/submissions/restore' && $method === 'POST') {
    $body = read_json_body();
    $id = $body['id'] ?? null;
    if ($id === null || $id === '') {
        respond_json(422, [
            'ok' => false,
            'error' => 'id is required',
        ]);
    }

    $trash = load_submission_trash();
    $key = (string)$id;
    if (!isset($trash[$key])) {
        respond_json(404, ['ok' => false, 'error' => 'Submission is not deleted']);
    }

    $entry = $trash[$key];
    if ((int)($entry['restore_until'] ?? 0) < time()) {
        unset($trash[$key]);
        save_submission_trash($trash);
        respond_json(410, ['ok' => false, 'error' => 'Restore window expired']);
    }

    unset($trash[$key]);
    save_submission_trash($trash);

    respond_json(200, [
        'restored' => true,
        'id' => $id,
        'mode' => 'restore',
    ]);
}

http_response_code(404);
echo json_encode(['error' => 'Not found. Try /api/health']) . "\n";
