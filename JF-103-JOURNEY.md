Sorular aşağıdadır ama öncelikle mevcut adımların kod yapısını inceledim ve yorumum aşağıdadır.

---

`backend/public/index.php` dosyasını okuduktan sonra burada `/api/submissions/search` için bir **GET** isteği olduğunu fark ettim. Ardından aynı blok içinde `strtolower` ifadesinden yola çıkarak **locale-unsafe case conversion** problemini arattım ve aşağıdaki kırık davranışı buldum:

```php
$result = array_values(array_filter($rows, function ($row) use ($q) {
	return strpos(strtolower($row['name']), strtolower($q)) !== false;
}));

usort($result, function ($a, $b) {
	return strcmp($a['name'], $b['name']);
});
```

Daha sonra codebase üzerinde `q=ipek` ve `q=İpek` davranışını karşılaştırdım ve Türkçe büyük/küçük harf varyantlarında arama ve sıralamanın tutarsız olmasına neden olan iki noktayı netleştirdim:

```text
1) `strtolower` UTF-8/Türkçe harf varyantları için güvenilir normalize sağlamıyor.
2) `strcmp` locale-aware olmadığı için Türkçe sıralama beklentisini karşılamıyor.
```

Bu methoddan anladığım kadarıyla endpoint; opsiyonel olarak **q** parametresini alıyor. `array_filter` içinden çağrılan asıl iş mantığı isimlerin query ile eşleşmesi. Response olarak da `items` ile birlikte filtrelenmiş kayıtlar dönülüyor.

Ardından aynı endpoint implementasyonuna gidip çözümü uyguladım. Burada ilk olarak `normalize_search_text` adında bir helper ekledim. Bu helper:
- `mb_strtolower(..., 'UTF-8')` ile UTF-8 lowercase yapıyor,
- Turkish case dönüşümünden gelen combining dot (`U+0307`) karakterini temizliyor,
- `ı, ş, ğ, ü, ö, ç` harflerini karşılaştırma için normalize ediyor.

Ardından arama tarafını bu normalize değerler üzerinden case-insensitive çalışacak şekilde güncelledim.

Sonrasında;
- `Collator('tr_TR')` mevcutsa locale-safe sıralama,
- mevcut değilse normalize string ile deterministic fallback sıralama

şeklinde iki kademeli bir sıralama stratejisi uyguladım.

Kritik değişiklik bloğu:

```php
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
}
```

Doğrulama için endpoint ve test runbook üzerinden kontrol yaptım.

Çalıştırdığım komutlar:

```bash
curl -s 'http://localhost:8081/api/submissions/search?q=ipek'
curl -s 'http://localhost:8081/api/submissions/search?q=%C4%B0pek'
bash scripts/run_ticket_tests.sh | sed -n '1,10p'
```

1) `GET /api/submissions/search?q=ipek` ve `GET /api/submissions/search?q=İpek` aynı sonucu döndü.
2) `bash scripts/run_ticket_tests.sh` içinde `JF-103 Turkish search consistency` **PASS** oldu.

Son olarak; JF-103 için root-cause (locale-unsafe lowercase + strcmp sorting) giderildi. Türkçe karakterler için arama davranışı tutarlı hale getirildi ve sıralama locale-safe/fallback mantığı ile öngörülebilir hale getirildi.