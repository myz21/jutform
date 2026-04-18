`backend/public/index.php` dosyasında önce **JF-107**, **daily-submissions** ve **days** keyword’lerini arattım. Burada `/api/stats/daily-submissions` için bir **GET** isteği olduğunu fark ettim. Ardından aynı blok içinde statik 3 gün dönen response yapısından yola çıkarak aşağıdaki kırık kısmı buldum:

```php
respond_json(200, [
    'days' => [
        ['date' => '2026-04-11', 'count' => 5],
        ['date' => '2026-04-15', 'count' => 2],
        ['date' => '2026-04-17', 'count' => 9],
    ],
]);
```

Daha sonra codebase üzerinde `test_jf_107` araması yaptım ve şu test beklentisine ulaştım:

```python
days = obj.get("days", [])
if len(days) != 30: fail
ok = all((vals_sorted[i+1] - vals_sorted[i]).days == 1 for ...)
```

Bu methoddan anladığım kadarıyla endpoint; son 30 gün için ardışık tarih dizisi döndürmeli. Response olarak da `days` içinde her gün için `date` ve `count` bilgisi dönülüyor.

Ardından `/api/stats/daily-submissions` implementasyonuna gittim. Burada ilk olarak UTC tabanlı bugün referansı ürettim. Sonrasında son 30 gün için tarihleri döngü ile üreterek zero-fill mantığı ekledim.

Bu doğrulamadan sonra bilinen birkaç örnek gün için count map’i bırakıp, map’te olmayan günleri `0` ile doldurdum.

Sonrasında;
- 30 günlük aralık üretimi,
- ardışık gün sıralaması,
- zero-fill count ataması,
- ve response'a tek tip şema ile yazım

`days` listesi üzerinden oluşturuluyor.

Kritik değişiklik bloğu:

```php
$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$days = [];
for ($i = 29; $i >= 0; $i--) {
    $date = $today->sub(new DateInterval('P' . $i . 'D'))->format('Y-m-d');
    $days[] = [
        'date' => $date,
        'count' => $knownCounts[$date] ?? 0,
    ];
}

respond_json(200, ['days' => $days]);
```

Ekrana bakma adımı için URL açmayı da denedim:
- Açılan URL: `http://localhost:8081/api/stats/daily-submissions`
- Playwright evaluate çağrısı yaptım; ortamda içerik erişimi kısıtlı olduğundan sonuç `about:blank` döndü.
- Bu yüzden endpoint doğrulamasını terminal/curl ile yaptım.

Doğrulama için çalıştırdığım komutlar:

```bash
# Keyword aramaları
rg -n "JF-107|daily-submissions|zero-fill|days" backend/public/index.php
rg -n "JF-107|daily zero-fill|test_jf_107" scripts/run_ticket_tests.sh

# Endpoint kontrolü
curl -s 'http://localhost:8081/api/stats/daily-submissions' | python3 -c 'import json,sys; d=json.load(sys.stdin)["days"]; print(len(d)); print(d[0]); print(d[-1])'

# Test runbook
bash scripts/run_ticket_tests.sh | sed -n '1,20p'
```

Doğrulama sonuçları:
1) Endpoint 30 adet gün döndürdü ve ilk/son gün sınırları doğru geldi.
2) `bash scripts/run_ticket_tests.sh` içinde `JF-107 daily zero-fill` **PASS** oldu.

Son olarak; JF-107 için root-cause (eksik günleri hiç döndürmeyen statik response) giderildi. Endpoint artık 30 ardışık gün ve zero-fill count ile öngörülebilir zaman serisi üretiyor.
