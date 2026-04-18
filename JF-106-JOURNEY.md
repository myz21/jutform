Arattım; ardından mevcut adımların kod yapısını inceledim ve yorumumu aşağıya yazdım.

---

`backend/public/index.php` dosyasında önce **csv**, **export** ve **JF-106** keyword’leriyle arama yaptım. Burada `/api/export/csv` endpoint’ine ait GET isteğini buldum. Ardından aynı blok içinde “no escaping/quoting” yorumundan yola çıkarak kırık satırı aşağıdaki şekilde tespit ettim:

```php
// Intentionally broken: no escaping/quoting.
echo $row['id'] . ',' . $row['name'] . ',' . $row['note'] . "\n";
```

Daha sonra codebase üzerinde `test_jf_106` araması yaptım ve beklenen davranışın CSV parse edildiğinde her satırın sabit kolon sayısına sahip olması olduğunu gördüm:

```python
rows = list(csv.reader(io.StringIO(text)))
ok = len(rows) >= 2 and all(len(r) == 3 for r in rows)
```

Bu methoddan anladığım kadarıyla endpoint; CSV olarak `id,name,note` kolonlarını döndürüyor. Ancak manuel string birleştirme kullanıldığı için virgül, çift tırnak ve satır sonu içeren alanlarda kolon hizası bozuluyor.

Ardından `/api/export/csv` implementasyonuna gittim. Burada ilk olarak content-type header’ını `text/csv; charset=UTF-8` olacak şekilde netleştirdim. Sonrasında `php://output` stream’i açıp header ve satırlar için `fputcsv` kullanacak şekilde akışı güncelledim.

Bu güncelleme ile CSV escaping/quoting RFC uyumlu biçimde otomatik hale geldi.

Sonrasında;
- header satırı `fputcsv` ile yazılıyor,
- her satır aynı helper ile serialize ediliyor,
- ve multiline/virgül/tırnak içeren alanlar güvenli şekilde export ediliyor.

Kritik değişiklik bloğu:

```php
header('Content-Type: text/csv; charset=UTF-8');
$out = fopen('php://output', 'w');

fputcsv($out, ['id', 'name', 'note']);
foreach ($rows as $row) {
    fputcsv($out, [$row['id'], $row['name'], $row['note']]);
}
fclose($out);
```

Ekrana bakma adımı için URL açmayı da denedim:
- Açılan URL: `http://localhost:8081/api/export/csv`
- Playwright evaluate çağrısı yapıldı; ortamda tarayıcı içerik erişimi kısıtlı olduğundan sonuç `about:blank` geldi.
- Bu nedenle endpoint çıktısını terminal/curl üzerinden doğruladım.

Doğrulama için çalıştırdığım komutlar:

```bash
# Keyword aramaları
rg -n "JF-106|csv|export|fputcsv|escaping|/api/export/csv" backend/public/index.php
rg -n "JF-106|CSV|test_jf_106" scripts/run_ticket_tests.sh

# Endpoint çıktısı
curl -s 'http://localhost:8081/api/export/csv' | sed -n '1,12p'

# Test runbook
bash scripts/run_ticket_tests.sh | sed -n '1,18p'
```

Doğrulama sonuçları:
1) CSV çıktısında `value,with,commas`, `Carol "The Great"` ve multiline note alanları doğru quote/escape ile dönüyor.
2) `bash scripts/run_ticket_tests.sh` içinde `JF-106 CSV escaping` **PASS** oldu.

Son olarak; JF-106 için root-cause (manual CSV string birleştirme) giderildi. CSV export artık RFC-uyumlu escaping/quoting ile güvenli şekilde üretiliyor ve kolon hizası korunuyor.
