Arattım; ardından mevcut adımların kod yapısını inceledim ve yorumumu aşağıya yazdım.

---

`backend/public/index.php` dosyasında önce **JF-110**, **submissions/delete** ve **submissions/restore** keyword’lerini arattım. Burada delete/restore için iki ayrı endpoint olduğunu fark ettim. Ardından delete bloğunda aşağıdaki hard-delete davranışını buldum:

```php
respond_json(200, [
    'deleted' => true,
    'id' => $body['id'] ?? null,
    'mode' => 'hard-delete',
]);
```

Daha sonra restore bloğunu incelediğimde akışın doğrudan 404 döndüğünü gördüm:

```php
if ($path === '/api/submissions/restore' && $method === 'POST') {
    respond_json(404, ['error' => 'Not found']);
}
```

Bu methoddan anladığım kadarıyla JF-110 için beklenen davranış soft-delete sonrası restore edilebilir bir pencere olması. Ancak mevcut implementasyonda silme state’i tutulmadığı için restore mümkün değildi.

Ardından `test_jf_110` kodunu kontrol ettim ve beklentinin delete + restore çağrılarının her ikisinin de 200 dönmesi olduğunu doğruladım.

Sonrasında `delete` akışına stateful soft-delete mantığı ekledim:
- `id` zorunlu validasyonu,
- silinen kayıtların `/tmp/jutform-submission-trash.json` dosyasında tutulması,
- `restore_until` alanıyla 30 günlük restore penceresi,
- response’ta `mode: soft-delete`.

Bu implementasyon için yardımcı fonksiyonlar ekledim:
- `submission_trash_file_path`,
- `load_submission_trash`,
- `save_submission_trash`.

Restore tarafında;
- `id` validasyonu,
- trash içinde kayıt kontrolü,
- restore window kontrolü (süre geçmişse 410),
- başarılı restore’da kaydı trashten düşürme

akışını ekledim.

Kritik değişiklik bloğu:

```php
$trash[(string)$id] = [
    'id' => $id,
    'deleted_at' => $now,
    'restore_until' => $restoreUntil,
    'status' => 'deleted',
];
save_submission_trash($trash);

...

if (!isset($trash[$key])) {
    respond_json(404, ['ok' => false, 'error' => 'Submission is not deleted']);
}

if ((int)($entry['restore_until'] ?? 0) < time()) {
    respond_json(410, ['ok' => false, 'error' => 'Restore window expired']);
}

unset($trash[$key]);
save_submission_trash($trash);
respond_json(200, ['restored' => true, 'id' => $id, 'mode' => 'restore']);
```

Ekrana bakma adımı için URL açmayı da denedim:
- Açılan URL: `http://localhost:8081/api/submissions/restore`
- Playwright evaluate çağrısı yaptım; ortamda içerik erişimi kısıtlı olduğundan sonuç `about:blank` döndü.
- Bu yüzden endpoint davranışını terminal/curl ile doğruladım.

Doğrulama için çalıştırdığım komutlar:

```bash
# Keyword aramaları
rg -n "JF-110|submissions/delete|submissions/restore|hard-delete|restore" backend/public/index.php
rg -n "JF-110|soft delete|restore should succeed|test_jf_110" scripts/run_ticket_tests.sh

# Endpoint doğrulaması
curl -s -X POST 'http://localhost:8081/api/submissions/delete' -H 'Content-Type: application/json' -d '{"id":123}'
curl -s -X POST 'http://localhost:8081/api/submissions/restore' -H 'Content-Type: application/json' -d '{"id":123}'

# Test runbook
bash scripts/run_ticket_tests.sh | sed -n '1,26p'
```

Doğrulama sonuçları:
1) Delete endpoint artık `soft-delete` modunda çalışıyor ve restore window dönüyor.
2) Restore endpoint delete sonrası başarıyla 200 dönüyor.
3) `bash scripts/run_ticket_tests.sh` içinde `JF-110 soft delete + restore` **PASS** oldu.

Son olarak; JF-110 için root-cause (state tutulmayan hard-delete ve restore implementasyonunun olmaması) giderildi. Soft-delete + restore akışı 30 günlük pencere ile çalışır hale getirildi.
