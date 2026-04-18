`scripts/run_ticket_tests.sh` dosyasını okuduktan sonra burada JF-111 için `/api/forms/submit` endpoint’ine invalid body (`{}`) gönderildiğini fark ettim. Ardından test içinde “invalid request should not return 200” ifadesinden yola çıkarak **test_jf_111** keyword’ünü arattım ve aşağıdaki kontrolü buldum:

```bash
code=$(safe_http_code "$BASE_URL/api/forms/submit" -X POST -H 'Content-Type: application/json' -d '{}')
[[ "$code" =~ ^4|^5 ]]
```

Daha sonra codebase üzerinde `/api/forms/submit` araması yaptım ve şu koda ulaştım:

```php
if (empty($body['form_id']) || empty($body['email'])) {
    respond_json(422, [
        'ok' => false,
        'error' => 'form_id and email are required',
    ]);
}
```

Bu methoddan anladığım kadarıyla JF-111’in beklediği HTTP semantiği, invalid request’te 200 dönmemek. `/api/forms/submit` akışındaki validasyon bloğu bunu zaten sağlıyor. Ayrıca exception branch’inde de response artık 500 olarak dönüyor.

Önemli not: Bu ticket için ayrıca özel bir implementasyon yapmadık. JF-111’i tek başına hedefleyip yeni kod yazmadan önceki ticket fixlerinin etkisini kontrol ettik.

Ardından önceki değişiklik geçmişini (chatte yapılan adımları) incelediğimde şunu gördüm: JF-101 fixi sırasında `form_id/email` zorunlu validasyonu eklendi ve hata durumunda 200’e zorlama davranışı kaldırıldı. Bu nedenle JF-111 için ayrı bir kod değişikliği yapmaya gerek kalmadan test otomatik olarak geçer hale geldi.

Bu bağlantı şu anlama geliyor:
- JF-111’in kök ihtiyacı: yanlış requestte non-2xx dönmek,
- JF-101’de yapılan düzeltme: invalid body için 422 + exception’da 500,
- sonuç: JF-111 doğal olarak PASS.

Kritik ilişki bloğu:

```php
if (empty($body['form_id']) || empty($body['email'])) {
    respond_json(422, [...]);
}
...
catch (Exception $e) {
    respond_json(500, [...]);
}
```

Doğrulama için çalıştırdığım komutlar:

```bash
# JF-111 test tanımını kontrol
rg -n "JF-111|test_jf_111|invalid request" scripts/run_ticket_tests.sh

# Submit endpoint validasyonunu kontrol
rg -n "/api/forms/submit|form_id and email are required|respond_json\(500" backend/public/index.php

# Test sonucu doğrulama
bash scripts/run_ticket_tests.sh | grep 'JF-111\|Summary'
```

Doğrulama sonuçları:
1) `JF-111 HTTP status semantics` **PASS**.
2) Tüm testler geçti: `PASS=12 FAIL=0`.

Son olarak; JF-111’deki sorun “ayrı bir fix” ile değil, JF-101’de yapılan HTTP durum kodu/validasyon düzeltmesi sayesinde otomatik çözüldü. Demek ki JF-111 doğrudan `/api/forms/submit` hata semantiğine bağlıydı.

Özet cümle: JF-111’i özellikle çözmeye çalışmadan, JF-101’de yapılan doğru status-code ve validasyon düzeltmeleri nedeniyle ticket kendi kendine PASS durumuna geçti.
