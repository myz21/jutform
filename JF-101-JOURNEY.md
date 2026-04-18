Sorular aşağıdadır ama öncelikle mevcut adımların kod yapısını inceledim ve yorumum aşağıdadır.

---

`backend/public/index.php` dosyasını okuduktan sonra burada `/api/forms/submit` için bir **POST** isteği olduğunu fark ettim. Ardından aynı blok içinde `INSERT INTO preflight_check` ifadesinden yola çıkarak **execute** keyword’ünü arattım ve aşağıdaki problemi buldum:

```php
// Intentionally broken: duplicate write on every submit.
$insert->execute(['name' => $name]);
$insert->execute(['name' => $name]);
```

Daha sonra codebase üzerinde `respond_json(..., true)` araması yaptım ve aynı akışın hata durumunda da yanlış semantik döndüğünü gördüm:

```php
// Intentionally broken: returns 200 even on error.
respond_json(500, ['ok' => false, 'error' => $e->getMessage()], true);
```

Bu methoddan anladığım kadarıyla `submit` endpoint’i `form_id` ve `email` bilgisiyle kayıt açıyor. Ancak mevcut implementasyonda aynı request için iki kez insert yapıldığı için aynı kullanıcı eylemi birden fazla kalıcı kayıt üretiyor.

Ardından `/api/forms/submit` altındaki implementasyona gidip akışı düzelttim. Burada ilk olarak `form_id` ve `email` zorunlu olacak şekilde 422 validasyonu ekledim. Sonrasında aynı form+email kombinasyonu için `GET_LOCK` ile kısa süreli bir işlem kilidi koydum.

Bu doğrulama/kilit adımından sonra, son **10 saniye** içinde aynı `check_name` ile kayıt olup olmadığını kontrol ettim. Eğer varsa duplicate kabul edilip yeni write yapılmadan kontrollü response dönülüyor.

Sonrasında;
- gerçek duplicate durumunda `deduplicated: true` ile 200,
- yeni submit durumunda tek insert ve 201,
- lock alınamazsa 409,
- exception’da gerçek 500

dönecek şekilde akışı güncelledim.

Eklediğim düzeltilmiş akışın kritik kısmı:

```php
$windowSeconds = 10;
$lockName = 'submit_lock:' . sha1($name);
$lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 2) AS lock_ok');
$lockStmt->execute(['lock_name' => $lockName]);

$check = $pdo->prepare('SELECT id FROM preflight_check WHERE check_name = :name AND checked_at >= (NOW() - INTERVAL :window SECOND) LIMIT 1');
$check->bindValue(':name', $name, PDO::PARAM_STR);
$check->bindValue(':window', $windowSeconds, PDO::PARAM_INT);
$check->execute();

if ($recentDuplicate) {
	respond_json(200, [
		'ok' => true,
		'message' => 'Duplicate submit ignored',
		'deduplicated' => true,
	]);
}

$insert = $pdo->prepare('INSERT INTO preflight_check (check_name, checked_at) VALUES (:name, NOW())');
$insert->execute(['name' => $name]);
```

Doğrulama için hem otomatik test hem manuel test koştum.

1) `bash scripts/run_ticket_tests.sh` çıktısında `JF-101 duplicate submit protection` **PASS**.
2) Aynı payload iki kez hızlı gönderildiğinde ilk cevap `Submission accepted`, ikinci cevap `Duplicate submit ignored` döndü.
3) MySQL’de ilgili `check_name` için satır sayısı `1` kaldı.

Son olarak; JF-101 için root-cause (double execute + hatada force 200) giderildi, hızlı tekrar isteklerinde tek kalıcı kayıt garantilendi ve endpoint HTTP davranışı netleştirildi.