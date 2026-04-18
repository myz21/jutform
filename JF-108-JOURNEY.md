`backend/public/index.php` dosyasında önce **JF-108**, **request-reset** ve **expires_at** keyword’lerini arattım. Burada `/api/auth/request-reset` için bir **POST** isteği olduğunu fark ettim. Ardından aynı blokta expiry üretimi için kullanılan satırı inceleyip aşağıdaki kırık noktayı buldum:

```php
'expires_at' => date(DATE_ATOM, time() - 60),
```

Daha sonra codebase üzerinde `test_jf_108` araması yaptım ve şu test beklentisine ulaştım:

```python
exp_dt = datetime.fromisoformat(exp.replace("Z", "+00:00"))
now = datetime.now(timezone.utc)
print("OK" if exp_dt > now else "NO")
```

Bu methoddan anladığım kadarıyla endpoint; body içinden **email** parametresini alıyor. Asıl iş mantığı reset token üretimi ve token’ın kullanım süresini (`expires_at`) belirlemek. Response olarak da `email`, `token` ve `expires_at` dönülüyor.

Ardından `/api/auth/request-reset` implementasyonuna gittim. Burada ilk olarak `email` için zorunluluk kontrolü ekledim (boşsa 422). Daha sonra token süresi için geçmiş zaman yerine UTC tabanlı ileri tarih üretimi ekledim.

Bu doğrulamadan sonra `issued_at`, `expires_at` ve `ttl_seconds` alanlarıyla response’u daha izlenebilir hale getirdim.

Sonrasında;
- `issued_at` UTC now,
- `expires_at` now + 15 dakika,
- boş email için 422,
- ve token üretimi

tek akışta dönüyor.

Kritik değişiklik bloğu:

```php
$issuedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$expiresAt = $issuedAt->add(new DateInterval('PT15M'));

respond_json(200, [
    'email' => $email,
    'token' => bin2hex(random_bytes(8)),
    'issued_at' => $issuedAt->format(DATE_ATOM),
    'expires_at' => $expiresAt->format(DATE_ATOM),
    'ttl_seconds' => 900,
]);
```

Ekrana bakma adımı için URL açmayı da denedim:
- Açılan URL: `http://localhost:8081/api/auth/request-reset`
- Playwright evaluate çağrısı yaptım; ortamda içerik erişimi kısıtlı olduğundan `about:blank` döndü.
- Bu yüzden endpoint doğrulamasını terminal/curl üzerinden gerçekleştirdim.

Doğrulama için çalıştırdığım komutlar:

```bash
# Keyword aramaları
rg -n "JF-108|request-reset|expires_at|token" backend/public/index.php
rg -n "JF-108|reset token expiry|test_jf_108" scripts/run_ticket_tests.sh

# Endpoint kontrolü
curl -s -X POST 'http://localhost:8081/api/auth/request-reset' -H 'Content-Type: application/json' -d '{"email":"user@example.com"}'

# Test runbook
bash scripts/run_ticket_tests.sh | sed -n '1,22p'
```

Doğrulama sonuçları:
1) Endpoint artık `expires_at` alanını gelecekte dönüyor.
2) `bash scripts/run_ticket_tests.sh` içinde `JF-108 reset token expiry` **PASS** oldu.

Son olarak; JF-108 için root-cause (token expiry’nin geçmişte üretilmesi) giderildi. Reset token yaşam döngüsü ileri zaman penceresiyle tutarlı hale getirildi.
