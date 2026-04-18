Arattım; ardından mevcut adımların kod yapısını inceledim ve yorumumu aşağıya yazdım.

---

`backend/public/index.php` dosyasında önce **JF-109**, **admin/action** ve **query** keyword’lerini arattım. Burada `/api/admin/action` için bir **POST** isteği olduğunu fark ettim. Ardından aynı blokta request parametrelerinin hiç doğrulanmadığını aşağıdaki kodla netleştirdim:

```php
$body = read_json_body();
respond_json(200, [
    'performed' => true,
    'received' => $body,
    'query' => $_GET,
]);
```

Daha sonra codebase üzerinde `test_jf_109` araması yaptım ve şu beklentiye ulaştım:

```bash
code=$(safe_http_code "$BASE_URL/api/admin/action?unsafe=1&drop=true" ...)
[[ "$code" =~ ^4 ]]
```

Bu methoddan anladığım kadarıyla endpoint; opsiyonel olarak bazı body alanlarını alabilir ama beklenmeyen query/body parametrelerini kabul etmemeli. Asıl iş mantığı allowlist’e göre doğrulama yapıp sadece güvenli aksiyonları çalıştırmak olmalı.

Ardından `/api/admin/action` implementasyonuna gittim. Burada ilk olarak query parametreleri için strict kontrol ekledim: tanımsız query key varsa 422 dönüyor.

Bu doğrulamadan sonra body için allowlist kontrolü ekledim. Beklenmeyen body alanları varsa yine 422 dönülüyor.

Sonrasında;
- `action` zorunluluğu,
- `action` allowlist kontrolü,
- beklenmeyen query/body reddi,
- ve yalnızca izinli alanları response’a yazma

tek akışta uygulandı.

Kritik değişiklik bloğu:

```php
$unknownQueryKeys = array_values(array_diff(array_keys($_GET), []));
if ($unknownQueryKeys) {
    respond_json(422, [...]);
}

$allowedBodyKeys = ['action', 'target_id', 'reason'];
$unknownBodyKeys = array_values(array_diff(array_keys($body), $allowedBodyKeys));
if ($unknownBodyKeys) {
    respond_json(422, [...]);
}

$allowedActions = ['reindex_logs', 'replay_email', 'archive_submission'];
if (!in_array($action, $allowedActions, true)) {
    respond_json(422, [...]);
}
```

Ekrana bakma adımı için URL açmayı da denedim:
- Açılan URL: `http://localhost:8081/api/admin/action?unsafe=1&drop=true`
- Playwright evaluate çağrısı yaptım; ortamda tarayıcı içerik erişimi kısıtlı olduğu için `about:blank` döndü.
- Bu yüzden endpoint doğrulamasını terminal/curl ile yaptım.

Doğrulama için çalıştırdığım komutlar:

```bash
# Keyword aramaları
rg -n "JF-109|admin/action|unexpected|allowlist|query" backend/public/index.php
rg -n "JF-109|strict validation|test_jf_109" scripts/run_ticket_tests.sh

# Endpoint kontrolü
curl -s -i -X POST 'http://localhost:8081/api/admin/action?unsafe=1&drop=true' -H 'Content-Type: application/json' -d '{"action":"delete_all","unexpected":"yes"}' | sed -n '1,18p'

# Test runbook
bash scripts/run_ticket_tests.sh | sed -n '1,24p'
```

Doğrulama sonuçları:
1) Endpoint beklenmeyen query parametrelerini 422 ile reddetti.
2) `bash scripts/run_ticket_tests.sh` içinde `JF-109 strict validation` **PASS** oldu.

Son olarak; JF-109 için root-cause (parametre doğrulaması olmaması) giderildi. Endpoint artık allowlist dışı query/body alanlarını reddediyor ve güvenli aksiyon seti dışında işlem yapmıyor.
