`backend/public/index.php` dosyasında önce **queue** ve **JF-105** keyword’leriyle arama yaptım. Burada `/api/queue/enqueue` ve `/api/queue/process` endpoint’lerini buldum. Ardından process bloğu içinde “en yeni işi işleme” davranışını aşağıdaki kodda doğruladım:

```php
// Intentionally broken: process only the newest job, leave older jobs forever.
$lastIndex = count($jobs) - 1;
$jobs[$lastIndex]['status'] = 'processed';
$processed = [$jobs[$lastIndex]];
unset($jobs[$lastIndex]);
```

Daha sonra codebase üzerinde `test_jf_105` araması yaptım ve beklenen davranışın FIFO/adil tüketim olduğunu gördüm:

```bash
# JF-105: queue processing should be FIFO and not starve old jobs.
# Beklenti: a,b,c enqueue sonrası bir process çağrısından sonra queue'da b,c kalmalı.
```

Bu methoddan anladığım kadarıyla queue worker, kuyruktaki sıradaki işi alıp işlemesi gerekirken en son eklenen işi alıyor. Bu da eski işlerin kuyrukta birikmesine ve yük altında starve olmasına yol açıyor.

Ardından `/api/queue/enqueue` ve `/api/queue/process` implementasyonuna gittim. Burada ilk olarak enqueue tarafına retry/observability alanları ekledim:
- `attempts`,
- `max_attempts`,
- `next_retry_at`,
- `last_error`,
- ve `force_fail`.

`max_attempts` için alt/üst sınır da ekledim (1-10).

Bu noktadan sonra process akışını FIFO fairness ile güncelledim: kuyrukta `queued` veya `retry` durumunda olup `next_retry_at <= now` olan **ilk** iş seçiliyor.

Sonrasında;
- iş başarılıysa kuyruktan siliniyor,
- iş başarısızsa attempt artırılıyor,
- limit aşılmadıysa `retry` durumuna alınıp backoff ile tekrar planlanıyor,
- limit aşıldıysa `failed` durumuna alınıp kuyrukta gözlemlenebilir bırakılıyor.

Bu sayede silent drop engellendi ve failed job’lar görünür hale geldi.

Kritik değişiklik bloğu:

```php
foreach ($jobs as $idx => $job) {
    $status = (string)($job['status'] ?? 'queued');
    $nextRetryAt = (int)($job['next_retry_at'] ?? 0);

    if (($status === 'queued' || $status === 'retry') && $nextRetryAt <= $now) {
        $jobIndex = $idx;
        break;
    }
}

// Success: kuyruktan çıkar
unset($jobs[$jobIndex]);
$jobs = array_values($jobs);

// Failure: retry/failed durumuna taşı
$job['attempts'] = (int)($job['attempts'] ?? 0) + 1;
if ($job['attempts'] >= $maxAttempts) {
    $job['status'] = 'failed';
} else {
    $job['status'] = 'retry';
    $job['next_retry_at'] = $now + min(30, 2 ** $job['attempts']);
}
```

Ekrana bakma adımı için URL açmayı da denedim:
- Açılan URL: `http://localhost:8081/api/queue/process`
- Playwright evaluate çağrısı yaptım; ortamda sayfa içerik erişimi kısıtlı olduğundan oturum `about:blank` döndü. Bu yüzden endpoint çıktısını terminal/curl ile doğruladım.

Doğrulama için çalıştırdığım komutlar:

```bash
# Keyword aramaları
rg -n "JF-105|queue|enqueue|process only the newest|remaining" backend/public/index.php
rg -n "JF-105|queue fairness|test_jf_105" scripts/run_ticket_tests.sh

# Endpoint davranışı
curl -s -X POST 'http://localhost:8081/api/queue/enqueue' -H 'Content-Type: application/json' -d '{"type":"a"}'
curl -s -X POST 'http://localhost:8081/api/queue/enqueue' -H 'Content-Type: application/json' -d '{"type":"b"}'
curl -s -X POST 'http://localhost:8081/api/queue/enqueue' -H 'Content-Type: application/json' -d '{"type":"c"}'
curl -s -X POST 'http://localhost:8081/api/queue/process'

# Test runbook
bash scripts/run_ticket_tests.sh | sed -n '1,16p'
```

Doğrulama sonuçları:
1) `JF-105 queue fairness` testi **PASS** oldu.
2) Worker artık en yeni işi değil, uygun en eski işi işlediği için starvation davranışı düzeldi.
3) Retry/failed durumları response ve kuyruk state üzerinden izlenebilir hale geldi.

Son olarak; JF-105 için root-cause (en yeni işi işleme) giderildi. Queue tüketimi adil hale getirildi, retry/failed akışı eklendi ve silent drop riski azaltıldı.
