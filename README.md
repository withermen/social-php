# SocialPHP

#Geçici bilgielndirme:
-Lisans satışı şuanda durdurulmuştur, önümüzdeki günlerde geri açılacaktır.

Kısa ve uzun video sosyal medya ürünleri için sıfırdan yazılmış, kendi sunucunuzda çalışan PHP backend API'si. Hazır bir UI içermez; siz sadece arayüzü yazarsınız, hesaplar, yüklemeler, akış, yorumlar, bildirimler, mesajlar ve moderasyon bu kütüphane tarafından yönetilir.

- Framework bağımlılığı yok. PHP 8.1+ ve PDO yeterli.
- SQLite ile sıfır kurulum, MySQL ile production kullanımı.
- JWT erişim tokenı + dönen refresh token.
- Parçalı ve devam ettirilebilir video yükleme.
- Byte-range video streaming.
- Takip, keşfet, trend, hashtag ve kişiselleştirilmiş akış.
- Yorum, yanıt, beğeni, kaydetme, paylaşma, görüntüleme.
- Bildirimler, direkt mesajlar, arama, şikayet ve admin moderasyonu.
- FFmpeg ve GD ile gerçek video içerik analizi: sahne kesmeleri, ses, hareket, renk paleti, algısal hash ve konu çıkarımı.
- İlgi alanı vektörü, izleme geçmişi ve negatif geri bildirimle kişiselleştirilmiş öneriler.
- Short-only, long-only ve hybrid platform modları; akışlarda format filtresi.
- Ticari kullanım lisansları: sipariş, imzalı anahtar, aktivasyon limiti, iptal ve webhook.
- Reklam kampanyaları: hedefleme, frekans sınırı, CPM/CPC harcama ve bütçe tamamlama.
- Premium abonelikler: planlar, yetkiler, reklamsız deneyim, yükleme limitleri ve webhook.
- Test edilmiş 100+ endpoint.

## Gereksinimler

- PHP 8.1 veya üzeri
- `pdo`, `json`, `fileinfo`, `mbstring`
- `pdo_sqlite` veya `pdo_mysql`
- Önerilir: `gd`, `ffmpeg`, `ffprobe`

## Hızlı Başlangıç

```bash
cp .env.example .env
php bin/social migrate
php bin/social seed
php -S 127.0.0.1:8000 server.php
```

`.env` içinde mutlaka `APP_KEY` değerini uzun ve rastgele bir değerle değiştirin. `APP_URL` değeri istemcilerin gördüğü API adresi olmalıdır.

Seed sonrası demo hesaplar:

```text
alice@example.com   password123
bob@example.com     password123
charlie@example.com password123
```

Admin oluşturmak için:

```bash
php bin/social create-admin admin admin@example.com guclu-bir-sifre "Site Admin"
```

Composer kullanmak isterseniz:

```bash
composer dump-autoload
```

Composer kullanmadan da `autoload.php` doğrudan çalışır.

## Temel Kavramlar

### Yanıt formatı

Başarılı yanıtlar:

```json
{
  "data": {},
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 42,
      "total_pages": 3
    }
  }
}
```

Hatalar:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "details": {
      "fields": {
        "email": "Please enter a valid email address."
      }
    }
  }
}
```

### Kimlik doğrulama

`register` veya `login` yanıtındaki `access_token` her istekte şu başlıkla gönderilir:

```http
Authorization: Bearer ACCESS_TOKEN
```

`refresh_token` yalnızca token yenilemek ve çıkış yapmak için kullanılır. Refresh token her kullanımda döner; eski token geçersiz olur.

```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"identifier":"alice@example.com","password":"password123"}'
```

### Sayfalama

Liste endpoint'leri `page` ve `per_page` kabul eder. `per_page` en fazla 100'dür.

```http
GET /api/v1/feed/foryou?page=2&per_page=15
```

Akış sayfaları arasında tekrarı önlemek için istemci aynı oturum kimliğini gönderir:

```http
GET /api/v1/feed/foryou?page=2&per_page=15&session_id=session-abc
```

`session_id` verilmezse sunucu üretir ve `meta.session_id` alanında döner. Aynı oturumda daha önce sunulan videolar sonraki sayfalarda tekrar gösterilmez.

## Endpoint Listesi

Tüm endpoint'ler `/api/v1` altındadır. `Auth` kolonu `Bearer` token gerektirir, `Optional` token varsa kişiselleştirir.

### Sistem

| Method | Endpoint | Auth | Açıklama |
| --- | --- | --- | --- |
| GET | `/` | - | API bilgisi |
| GET | `/health` | - | Veritabanı ve storage sağlığı |
| GET | `/platform` | - | Platform modu, format kuralları ve aktif özellikler |
| GET | `/media/{path}` | - | Avatar ve kapak görselleri |

### Hesap

| Method | Endpoint | Auth | Açıklama |
| --- | --- | --- | --- |
| POST | `/auth/register` | - | Kayıt |
| POST | `/auth/login` | - | Giriş |
| POST | `/auth/refresh` | - | Token yenileme |
| POST | `/auth/logout` | Optional | Refresh token iptali |
| POST | `/auth/verify-email` | - | E-posta doğrulama |
| POST | `/auth/resend-verification` | - | Doğrulama tekrar gönder |
| POST | `/auth/forgot-password` | - | Şifre sıfırlama isteği |
| POST | `/auth/reset-password` | - | Şifre sıfırlama |
| GET | `/me` | Auth | Kendi profilin |
| PATCH | `/me` | Auth | Profil güncelle |
| DELETE | `/me` | Auth | Hesabı kapat |
| POST | `/me/avatar` | Auth | Avatar yükle |
| DELETE | `/me/avatar` | Auth | Avatarı kaldır |
| POST | `/me/password` | Auth | Şifre değiştir |
| GET | `/me/saved-videos` | Auth | Kaydedilen videolar |

`register` alanları: `username`, `email`, `password`, `display_name`.
`login` alanları: `identifier` (e-posta veya kullanıcı adı), `password`.

### Kullanıcılar

| Method | Endpoint | Auth | Açıklama |
| --- | --- | --- | --- |
| GET | `/users/{username}` | Optional | Profil |
| GET | `/users/{username}/videos` | Optional | Kullanıcının videoları |
| GET | `/users/{username}/followers` | Optional | Takipçiler |
| GET | `/users/{username}/following` | Optional | Takip edilenler |
| POST | `/users/{username}/follow` | Auth | Takip et |
| DELETE | `/users/{username}/follow` | Auth | Takipten çık |
| POST | `/users/{username}/block` | Auth | Engelle |
| DELETE | `/users/{username}/block` | Auth | Engeli kaldır |
| POST | `/users/{username}/mute` | Auth | Sessize al |
| DELETE | `/users/{username}/mute` | Auth | Sessizi kaldır |

### Videolar

| Method | Endpoint | Auth | Açıklama |
| --- | --- | --- | --- |
| POST | `/videos` | Auth | Multipart video yükle |
| GET | `/videos/{id}` | Optional | Video detayı |
| PATCH | `/videos/{id}` | Auth | Başlık, açıklama, görünürlük |
| DELETE | `/videos/{id}` | Auth | Videoyu kaldır |
| GET | `/videos/{id}/stream` | Optional | Range destekli stream |
| POST | `/videos/{id}/view` | Optional | Görüntüleme kaydı |
| POST | `/videos/{id}/like` | Auth | Beğen |
| DELETE | `/videos/{id}/like` | Auth | Beğeniyi kaldır |
| POST | `/videos/{id}/save` | Auth | Kaydet |
| DELETE | `/videos/{id}/save` | Auth | Kaydı kaldır |
| POST | `/videos/{id}/share` | Optional | Paylaşım sayacı |
| GET | `/videos/{id}/likes` | Optional | Beğenenler |
| GET | `/videos/{id}/analysis` | Optional | İçerik analizi sonucu |
| POST | `/videos/{id}/analyze` | Auth | Analizi zorla yeniden çalıştır |
| POST | `/videos/{id}/watch` | Optional | İzleme sinyali (start, progress, complete, skip, replay) |
| POST | `/videos/{id}/not-interested` | Auth | Negatif geri bildirim |
| DELETE | `/videos/{id}/not-interested` | Auth | Geri bildirimi kaldır |

Görünürlük değerleri: `public`, `unlisted`, `private`. Yükleme yanıtı `format` (`short` veya `long`) ve analizden çıkan `topics` alanlarını içerir.

### Yorumlar

| Method | Endpoint | Auth | Açıklama |
| --- | --- | --- | --- |
| GET | `/videos/{id}/comments` | Optional | Yorumlar ve ilk yanıtlar |
| POST | `/videos/{id}/comments` | Auth | Yorum veya yanıt |
| PATCH | `/comments/{id}` | Auth | Yorum düzenle |
| DELETE | `/comments/{id}` | Auth | Yorum sil |
| POST | `/comments/{id}/like` | Auth | Yorum beğen |
| DELETE | `/comments/{id}/like` | Auth | Beğeniyi kaldır |
| GET | `/comments/{id}/replies` | Optional | Yanıtlar |

Yanıt için `parent_id` gönderilir. Yanıt derinliği tek seviyedir; bir yanıta yanıt verildiğinde üst yorum altında gösterilir.

### Akış ve Keşfet

| Method | Endpoint | Auth | Açıklama |
| --- | --- | --- | --- |
| GET | `/feed/following` | Auth | Takip edilenlerin akışı |
| GET | `/feed/foryou` | Optional | Kişiselleştirilmiş akış |
| GET | `/feed/shorts` | Optional | Yalnızca kısa videolar |
| GET | `/feed/long` | Optional | Yalnızca uzun videolar |
| GET | `/explore` | Optional | Trend videolar |
| GET | `/trends?days=7&limit=20` | Optional | Trend hashtag'ler |
| GET | `/hashtags/{tag}` | Optional | Hashtag akışı |
| GET | `/recommendations/interests` | Auth | İlgi alanı profili |
| PUT | `/recommendations/interests` | Auth | Açık ilgi alanlarını ayarla |
| DELETE | `/recommendations/interests` | Auth | Açık ilgi alanlarını temizle |

Tüm akış endpoint'leri `format=short|long|all` query parametresini kabul eder. Hybrid modda varsayılan `all`, short-only veya long-only modda ilgili format zorunludur.

### Arama

| Method | Endpoint | Auth | Açıklama |
| --- | --- | --- | --- |
| GET | `/search?q=php&type=all` | Optional | Kullanıcı, video, hashtag |

`type`: `all`, `users`, `videos`, `hashtags`.

### Bildirimler

| Method | Endpoint | Auth | Açıklama |
| --- | --- | --- | --- |
| GET | `/notifications` | Auth | Bildirimler |
| GET | `/notifications/unread-count` | Auth | Okunmamış sayısı |
| POST | `/notifications/{id}/read` | Auth | Okundu işaretle |
| POST | `/notifications/read-all` | Auth | Tümünü okundu işaretle |

### Mesajlar

| Method | Endpoint | Auth | Açıklama |
| --- | --- | --- | --- |
| GET | `/conversations` | Auth | Sohbet listesi |
| POST | `/conversations` | Auth | Sohbet başlat |
| GET | `/conversations/{id}` | Auth | Sohbet ve son mesajlar |
| GET | `/conversations/{id}/messages` | Auth | Mesajlar |
| POST | `/conversations/{id}/messages` | Auth | Mesaj gönder |
| POST | `/conversations/{id}/read` | Auth | Okundu işaretle |

Sohbet başlatmak için `username` veya `user_id` gönderilir.

### Şikayet ve Moderasyon

| Method | Endpoint | Auth | Rol | Açıklama |
| --- | --- | --- | --- | --- |
| POST | `/reports` | Auth | - | İçerik şikayeti |
| GET | `/admin/stats` | Auth | admin, moderator | Sistem istatistikleri |
| GET | `/admin/users` | Auth | admin | Kullanıcı arama |
| POST | `/admin/users/{id}/ban` | Auth | admin | Banla |
| POST | `/admin/users/{id}/unban` | Auth | admin | Banı kaldır |
| POST | `/admin/users/{id}/verify` | Auth | admin | Doğrulanmış rozeti |
| POST | `/admin/users/{id}/role` | Auth | admin | Rol değiştir |
| GET | `/admin/reports` | Auth | admin, moderator | Şikayetler |
| POST | `/admin/reports/{id}/resolve` | Auth | admin, moderator | Şikayeti kapat |
| POST | `/admin/videos/{id}/takedown` | Auth | admin, moderator | Videoyu kaldır |
| POST | `/admin/videos/{id}/restore` | Auth | admin, moderator | Videoyu geri al |

Şikayet nedenleri: `spam`, `harassment`, `hate`, `nudity`, `violence`, `self_harm`, `misinformation`, `copyright`, `impersonation`, `other`.

### Reklamlar

| Method | Endpoint | Auth | Açıklama |
| --- | --- | --- | --- |
| GET | `/ads/serve?placement=feed` | Optional | Hedeflemeye uygun reklam döner |
| POST | `/ads/{id}/click` | Optional | Tıklama kaydı ve hedef URL |
| GET | `/ads/campaigns` | Auth | Kampanyalar |
| POST | `/ads/campaigns` | Auth | Kampanya oluştur |
| GET | `/ads/campaigns/{id}` | Auth | Kampanya ve kreatifleri |
| PATCH | `/ads/campaigns/{id}` | Auth | Kampanya güncelle |
| POST | `/ads/campaigns/{id}/creatives` | Auth | Kreatif ekle |
| GET | `/ads/campaigns/{id}/stats` | Auth | Gösterim, tıklama, CTR ve harcama |
| POST | `/admin/ads/{id}/status` | Auth | Admin kampanya durumu |

Kampanya alanları: `name`, `placement` (`feed`, `explore`, `all`), `objective` (`impressions`, `clicks`), `budget_cents`, `bid_cents`, `targeting`, `starts_at`, `ends_at`. `targeting` içinde `topics` ve `formats` bulunur. Kreatif alanları: `title`, `body`, `cta_label`, `click_url`, `image_url`, `video_id`, `weight`; ayrıca multipart `image` gönderilebilir.

Premium kullanıcılar reklam görmez. Saatlik gösterim sınırı `ADS_FREQUENCY_PER_HOUR` ile belirlenir. Bütçesi tükenen kampanya otomatik olarak `completed` olur.

### Premium Abonelik

| Method | Endpoint | Auth | Açıklama |
| --- | --- | --- | --- |
| GET | `/premium/plans` | - | Aktif planlar |
| GET | `/premium/status` | Auth | Kullanıcının premium durumu |
| GET | `/me/subscription` | Auth | Abonelik detayı |
| POST | `/premium/subscribe` | Auth | Plan seç |
| POST | `/premium/cancel` | Auth | Dönem sonunda iptal |
| POST | `/premium/webhook` | - | İmzalı ödeme webhook'u |
| POST | `/admin/subscriptions` | Auth | Admin hediye abonelik |

Planlar `config/social.php` içindeki `premium.plans` bölümünden tanımlanır ve veritabanına otomatik eklenir. Yetkiler: `ad_free`, `premium_badge`, `larger_uploads`, `longer_videos`, `creator_analytics`, `priority_delivery`, `max_upload_size`, `max_duration`. Premium yükleme limitleri `VideoService` tarafından zorunlu tutulur.

Webhook `x-payment-signature` başlığında `sha256=<hmac>` taşır. Desteklenen olaylar: `subscription.activated`, `invoice.paid`, `subscription.canceled`.

### Ticari Lisans

| Method | Endpoint | Auth | Açıklama |
| --- | --- | --- | --- |
| GET | `/license/plans` | - | Lisans planları |
| GET | `/license/status` | Auth | Lisans durumu (admin) |
| POST | `/license/activate` | - | Kurulum aktivasyonu |
| POST | `/license/checkout` | - | Lisans siparişi |
| POST | `/license/webhook` | - | İmzalı ödeme webhook'u |
| GET | `/admin/licenses` | Auth | Lisans listesi |
| POST | `/admin/licenses` | Auth | Manuel lisans üret |
| POST | `/admin/licenses/{id}/revoke` | Auth | Lisansı iptal et |

Lisans anahtarları veritabanında hash'lenir, yalnızca üretim anında bir kez açık gösterilir. `LICENSE_PRIVATE_KEY` tanımlıysa `soc1.<payload>.<signature>` biçiminde imzalı anahtar üretilir ve `LICENSE_PUBLIC_KEY` ile doğrulanır. Aktivasyonlar `installation_id` bazında sayılır ve `max_activations` sınırı uygulanır.

Zorlama modları: `off` (kapalı), `warn` (yanıt verir, `X-License-Status: invalid` başlığı ekler), `enforce` (lisans yoksa 402 `license_required`). `/api/v1/license*`, `/api/v1/health`, `/api/v1` ve `/` her zaman muaftır.

## İçerik Analizi

Yükleme sonrası video FFmpeg/FFprobe ve GD ile analiz edilir. Sonuç `video_analysis` ve `video_topics` tablolarına yazılır.

- Sahne kesmeleri: `scene` filtresi ve `showinfo` ile kesme zamanları, kesme oranı.
- Ses: `volumedetect` ile ortalama/tepe ses, `silencedetect` ile sessizlik oranı.
- Görsel: örneklenen karelerden parlaklık, doygunluk, kontrast, renklilik, hareket ve baskın renk paleti.
- Algısal hash: ilk kareden 64 bit dHash, benzer içerik tespiti için.
- Konular: hashtag'ler, başlık/açıklama metni, süre formatı, en-boy oranı, ışık, renk, hareket, kesme hızı ve ses özelliklerinden ağırlıklı konu vektörü.

Analiz `ANALYSIS_ENABLED=false` ile kapatılabilir. `ANALYSIS_FRAME_SAMPLES` örnek kare sayısını, `ANALYSIS_FRAME_WIDTH` kare genişliğini, `ANALYSIS_SCENE_THRESHOLD` sahne eşiğini belirler. FFmpeg yoksa analiz yükleme akışını bozmaz; metin ve format tabanlı konular yine üretilir.

## Platform Modları

`PLATFORM_MODE` üç değer alır:

- `short`: yalnızca `PLATFORM_SHORT_MAX_DURATION` saniyeye kadar videolar kabul edilir.
- `long`: yalnızca `PLATFORM_LONG_MIN_DURATION` saniye ve üzeri videolar kabul edilir.
- `hybrid`: her iki format da kabul edilir; akışlar format filtresiyle ayrılır.

`PLATFORM_ENFORCE_UPLOAD_MODE=false` yükleme kontrolünü kapatır, akış filtreleri çalışmaya devam eder.

## Öneri Motoru

`/feed/foryou`, `/feed/shorts`, `/feed/long` ve `/explore` akışları izleyiciyi ekranda tutmayı hedefleyen çok amaçlı bir sıralama kullanır. Her video için aşağıdaki sinyaller hesaplanır:

- Tahmini tamamlanma: video, konu ve yazar bazında yumuşatılmış tamamlanma oranlarının ağırlıklı birleşimi.
- Beklenen izleme süresi: tahmini izleme oranı ile video süresinin çarpımı; uzun videoların toplam izlenme süresi avantajı korunur.
- Tahmini atlama: video, konu, yazar ve mevcut oturumdaki atlama oranından türetilen atlama olasılığı; yüksek atlama olasılığı güçlü ceza alır.
- Tekrar izleme: replay sinyalleri ve video/konu bazında yeniden izleme olasılığı.
- Kanca kalitesi: içerik analizinden gelen hızlı kesme, yüksek hareket, dikey format ve renk sinyalleri.
- Etkileşim olasılığı: beğeni, yorum, paylaşma, kaydetme ve takip sinyallerinin gösterime göre normalize edilmiş hali.
- İlgi profili: izleme, tamamlama, beğeni, kaydetme, paylaşma, yorum, takip ve açık ilgi alanlarından oluşan konu vektörüyle kosinüs benzerliği.
- Yazar yakınlığı: takip edilen üreticilere ek puan.
- Oturum bağlamı: aynı `session_id` içinde gösterilen konular, yazarlar, tekrarlar ve izleme davranışı.
- Yorgunluk ve tekrar: aynı konu veya yazardan art arda gelen videolara ve son günlerde tekrar gösterilen videolara ceza.
- Keşif: az gösterim almış videolar ve yeni üreticiler için UCB benzeri keşif bonusu.

Sıralama skoru bu sinyallerin ağırlıklı toplamıdır. Ardından slate oluşturucu sıralı listeyi gezer; yazar ve konu çeşitliliğini korur, oturum içi tekrarları engeller ve `feed.exploration_rate` aralığında keşif slotları yerleştirir.

Her akış öğesi `recommendation` alanında şu açıklamayı taşır: `score`, `predicted_completion`, `predicted_skip`, `predicted_engagement`, `expected_watch_seconds`, `interest_match`, `matched_topics`, `exploration`, `fatigue`, `reasons` ve `slate_position`. Akış meta'sında `algorithm.session` oturum istatistiklerini, `algorithm.weights` aktif ağırlıkları ve `algorithm.signals` kullanılan sinyalleri gösterir.

İzleme sinyalleri `/videos/{id}/watch` endpoint'ine gönderilir:

```bash
curl -X POST http://localhost:8000/api/v1/videos/$VIDEO_ID/watch \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"position":18,"duration":20,"event":"complete","session_id":"session-abc"}'
```

`event` değerleri `start`, `progress`, `complete`, `skip`, `replay` olur. `replayed=true` tekrar izlemeyi, `event=skip` atlamayı işaretler. Misafir izleyicilerin izleme sinyalleri kullanıcı profiline yazılmaz; oturum gösterimleri yine kaydedilir.

## Parçalı Video Yükleme

Büyük dosyalar için upload session oluşturun. Her parça sırayla gönderilir. Sunucu offset'i doğrular.

```bash
curl -X POST http://localhost:8000/api/v1/uploads \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"filename":"klip.mp4","size":10485760,"mime":"video/mp4","title":"İlk klip","description":"#demo","visibility":"public"}'
```

Yanıttaki `id` ile parçaları gönderin:

```bash
curl -X PUT "http://localhost:8000/api/v1/uploads/$UPLOAD_ID/chunk?offset=0" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/octet-stream" \
  --data-binary @parca-1.bin
```

Offset query yerine `Content-Range: bytes 0-5242879/10485760` başlığı da kullanılabilir. Son parça sonrası:

```bash
curl -X POST "http://localhost:8000/api/v1/uploads/$UPLOAD_ID/complete" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"tags":["php","video"]}'
```

Yükleme geçmişi ve kalan byte sayısı `GET /uploads/{id}` ile alınır. Vazgeçmek için `DELETE /uploads/{id}`.

### Tarayıcıdan yükleme örneği

```javascript
const form = new FormData();
form.append("video", file);
form.append("title", "Kısa video");
form.append("description", "#demo");
form.append("visibility", "public");

const response = await fetch(`${API}/videos`, {
  method: "POST",
  headers: { Authorization: `Bearer ${accessToken}` },
  body: form,
});
```

### Parçalı yükleme örneği

```javascript
const init = await fetch(`${API}/uploads`, {
  method: "POST",
  headers: {
    Authorization: `Bearer ${accessToken}`,
    "Content-Type": "application/json",
  },
  body: JSON.stringify({
    filename: file.name,
    size: file.size,
    mime: file.type,
    title: "Kısa video",
  }),
}).then((res) => res.json());

const uploadId = init.data.id;
const chunkSize = init.data.chunk_size;
let offset = 0;

while (offset < file.size) {
  const chunk = file.slice(offset, offset + chunkSize);
  await fetch(`${API}/uploads/${uploadId}/chunk?offset=${offset}`, {
    method: "PUT",
    headers: {
      Authorization: `Bearer ${accessToken}`,
      "Content-Type": "application/octet-stream",
    },
    body: chunk,
  });
  offset += chunk.size;
}

const video = await fetch(`${API}/uploads/${uploadId}/complete`, {
  method: "POST",
  headers: {
    Authorization: `Bearer ${accessToken}`,
    "Content-Type": "application/json",
  },
  body: JSON.stringify({ tags: ["php"] }),
}).then((res) => res.json());
```

## Streaming

`stream_url` doğrudan `<video>` etiketine verilebilir. Sunucu `Range` başlığını destekler.

```html
<video controls src="http://localhost:8000/api/v1/videos/VIDEO_ID/stream"></video>
```

Videoyu ileri sarmak için tarayıcı otomatik olarak `Range: bytes=...` gönderir. Sunucu `206 Partial Content`, `Content-Range` ve `Accept-Ranges` döner.

Erişim kuralları:

- `private` video yalnızca sahibine ve yöneticilere görünür.
- `unlisted` video akışlarda görünmez, doğrudan bağlantıyla açılır.
- `removed` video herkese 404 döner.
- Engellenen kullanıcıların içerikleri görünmez.
- Beğeni, kaydetme ve görüntüleme aksiyonları erişim kontrolünden geçer.

## Akış Algoritması

`/feed/foryou` adayları dört havuzdan birleştirilir: son 14 günün yeni videoları, yüksek tamamlanma/izlenme süresine sahip videolar, ilgi profiliyle eşleşen konular ve az gösterim almış keşif videoları. `/explore` aynı motoru 7 günlük pencereyle çalıştırır.

Ağırlıklar `config/social.php` içindeki `feed` bölümünden veya `.env` değişkenlerinden ayarlanır:

```php
'feed' => [
    'candidate_limit' => 240,
    'window_days' => 14,
    'half_life_hours' => 72,
    'max_per_author' => 2,
    'max_author_total' => 4,
    'max_same_topic' => 2,
    'retention_weight' => 5.4,
    'expected_watch_weight' => 2.6,
    'rewatch_weight' => 1.8,
    'hook_weight' => 3.4,
    'engagement_weight' => 2.0,
    'skip_penalty' => 9.0,
    'session_skip_penalty' => 4.0,
    'repeat_penalty' => 10.0,
    'topic_fatigue_weight' => 1.6,
    'author_fatigue_weight' => 1.2,
    'exploration_weight' => 1.7,
    'exploration_rate' => 0.16,
    'exploration_min_impressions' => 8,
    'session_window_minutes' => 45,
    'skip_ratio' => 0.25,
    'completion_ratio' => 0.9,
],
```

`FEED_RETENTION_WEIGHT`, `FEED_EXPECTED_WATCH_WEIGHT`, `FEED_REWATCH_WEIGHT`, `FEED_HOOK_WEIGHT`, `FEED_ENGAGEMENT_WEIGHT`, `FEED_SKIP_PENALTY`, `FEED_EXPLORATION_WEIGHT`, `FEED_EXPLORATION_RATE`, `FEED_EXPLORATION_MIN_IMPRESSIONS`, `FEED_SESSION_WINDOW_MINUTES`, `FEED_COMPLETION_RATIO`, `FEED_SKIP_RATIO`, `FEED_MAX_PER_AUTHOR`, `FEED_MAX_AUTHOR_TOTAL` ve `FEED_MAX_SAME_TOPIC` ortam değişkenleri aynı değerleri ezer.

`completion_ratio` izleme oranının tamamlanma sayılma eşiğidir; `skip_ratio` bu eşiğin altında kalan ve ilk saniyelerde bırakılan izlemeleri atlama olarak işaretler. `session_window_minutes` oturum bağlamının geriye dönük penceresidir.

`following` akışı kronolojiktir ve format filtresi uygular. `trends` hashtag'lerin son 7 gündeki görüntüleme ve beğeni toplamına göre sıralanır.

## Veritabanı

Varsayılan olarak SQLite kullanılır:

```env
DB_DRIVER=sqlite
DB_DATABASE=
```

Boş bırakıldığında `database/social.sqlite` oluşturulur. MySQL için:

```env
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=social
DB_USER=root
DB_PASSWORD=gizli
```

Şema `bin/social migrate` ile oluşturulur. `auto_migrate` varsayılan olarak açıktır ve uygulama açılışında eksik tabloları oluşturur. Production'da migration'ı deploy adımında çalıştırıp `auto_migrate` değerini kapatabilirsiniz.

## Depolama

Varsayılan depolama `storage/app` altındadır. Bu dizin web sunucusunun document root'u dışında kalmalıdır. Görseller `/media/{path}` endpoint'i üzerinden servis edilir; videolar yetki kontrolünden geçen `/api/v1/videos/{id}/stream` endpoint'inden akar.

```env
STORAGE_ROOT=/var/www/social/storage/app
STORAGE_URL=/media
```

Dosya yolu `storage/app/videos/YYYY/MM/{id}.mp4`, kapak görseli `storage/app/thumbnails/{id}.jpg`, avatarlar `storage/app/avatars/{userId}-{timestamp}.jpg` olarak tutulur.

FFmpeg yoksa video yine yüklenir; süre ve çözünürlük bilinmiyorsa 0 kalır, kapak görseli yerine üretilen gradyan kapak kullanılır. FFmpeg varsa süre, boyut ve gerçek kapak görseli otomatik üretilir.

## E-posta

Varsayılan mail sürücüsü dosyaya yazan `log` sürücüsüdür. Doğrulama ve şifre sıfırlama bağlantıları `storage/logs/mail.log` dosyasına yazılır.

Geliştirme sırasında token'ları doğrudan API yanıtında görmek için:

```env
AUTH_EXPOSE_TOKENS=true
```

Production'da bu değer kesinlikle `false` olmalıdır. Kendi mail servisinizi bağlamak için `Social\Mail\MailerInterface` arayüzünü uygulayıp `App` içindeki mailer oluşturmayı değiştirebilirsiniz.

## Ayarlar

Öne çıkan `.env` değerleri:

```env
APP_URL=https://api.example.com
APP_KEY=uzun-rastgele-bir-anahtar
AUTH_ACCESS_TTL=3600
AUTH_REFRESH_TTL=2592000
UPLOAD_MAX_SIZE=536870912
UPLOAD_MAX_CHUNK=8388608
UPLOAD_MAX_DURATION=600
MEDIA_FFMPEG=ffmpeg
MEDIA_FFPROBE=ffprobe
PLATFORM_MODE=hybrid
PLATFORM_SHORT_MAX_DURATION=180
PLATFORM_LONG_MIN_DURATION=180
ANALYSIS_ENABLED=true
ADS_ENABLED=true
ADS_FREQUENCY_PER_HOUR=12
LICENSING_ENABLED=false
LICENSING_ENFORCEMENT=off
LICENSE_KEY=
CORS_ALLOWED_ORIGINS=https://app.example.com
TRUST_PROXY=false
```

PHP tarafında da yükleme limitlerini artırın:

```ini
upload_max_filesize = 512M
post_max_size = 512M
max_execution_time = 120
```

## Test

```bash
php tests/run.php
```

Test paketi geçici bir veritabanı ve depolama alanı oluşturur. Kayıt, giriş, token yenileme, profil, avatar, takip, video yükleme, kapak görseli, range stream, görüntüleme tekrarı, beğeni, kaydetme, yorum, yanıt, akış, keşfet, trend, arama, bildirim, mesaj, parçalı yükleme, moderasyon, ban, şifre sıfırlama, yetki sınırları, platform modları, içerik analizi, format filtreli öneriler, lisans yaşam döngüsü ve zorlaması, reklam hedefleme/frekans/bütçe ve premium abonelik akışları test edilir.

## Üretim Kontrol Listesi

- `APP_KEY` değiştirildi.
- `APP_DEBUG=false`.
- `AUTH_EXPOSE_TOKENS=false`.
- HTTPS kullanılıyor.
- `storage` document root dışında.
- CORS yalnızca gerçek frontend origin'lerine açık.
- `TRUST_PROXY` yalnızca güvenilen reverse proxy arkasında açık.
- PHP yükleme limitleri video boyutuna göre ayarlı.
- Yedekleme hem veritabanını hem `storage/app` dizinini kapsıyor.
- Şikayet ve moderasyon akışı operasyonel.
- Rate limit değerleri trafiğe göre ayarlandı.
- Reklam bütçeleri ve premium plan fiyatları gözden geçirildi.
- Ticari kullanım için `LICENSING_ENABLED=true` ve `LICENSING_ENFORCEMENT=enforce` yapılandırıldı.
- Ödeme ve lisans webhook sırları güçlü ve yalnızca sunucuda tanımlı.

## Planlar ve Lisans

Kurulum iki planla çalışır:

| Plan | Reklam | Gelişmiş algoritma | Kısa video | Uzak lisans |
| --- | --- | --- | --- | --- |
| `free` | Kapalı | Kapalı | Kapalı | Gerekmez |
| `commercial` | Açık | Açık | Açık | Zorunlu |

Plan seçimi:

```bash
php bin/social install --plan=free
php bin/social install --plan=commercial --domain=ornek.com \
  --server=https://kerem.site/api/lisans \
  --public-key=BASE64_PUBLIC_KEY
```

Ticari lisans aylık 100 TL'dir ve 30 gün geçerlidir. Lisans sunucusu `license-server/` klasöründedir; `kerem.site/api/lisans` altına yüklenir. Ayrıntılar için `license-server/README.md` dosyasına bakın.

Ticari planda:

1. `php bin/social license-key SOC-XXXX-XXXX-XXXX-XXXX`
2. `php bin/social license-check`

Lisans; domain ve kurulum kimliğine bağlanır. Doğrulama yanıtı RSA-SHA256 ile imzalanır, nonce ve zaman damgası kontrol edilir. Varsayılan olarak 6 saatte bir, hata durumunda en fazla 5 denemede sistem kilitlenir. Kilitliyken yalnızca `/api/v1/license/*` uçları açıktır; geçerli anahtar girilince sistem tekrar açılır. Veri veya kod silinmez.

Free planda `/feed/shorts` 402 döner, kısa video yüklemesi reddedilir, reklam servis edilmez ve akışlar gelişmiş retention sıralaması yerine temel kronolojik sıralamayı kullanır.

İsteğe bağlı dosya bütünlüğü kontrolü:

```bash
php bin/social integrity
```

`LICENSE_INTEGRITY=true` yapıldığında `src/`, `config/` ve `bin/` dosyalarının özeti `storage/app/integrity.json` ile karşılaştırılır. Değişiklik varsa sistem kilitlenir. Kod güncellemelerinden sonra `php bin/social integrity` tekrar çalıştırılmalıdır.

> PHP kaynak kodu, çalışması için sunucuda çözülmek zorunda olduğundan tek başına "şifreleme" ile kalıcı olarak gizlenemez. En güçlü koruma; sunucu tarafı lisans doğrulaması, domain bağlama, imzalı yanıtlar ve dosya bütünlüğü kontrolüdür. Kaynak kodun gerçekten şifrelenmesi gerekiyorsa ionCube veya SourceGuardian gibi bir PHP encoder kullanılmalıdır.

Kod MIT lisanslıdır; ayrıntılar için `LICENSE` dosyasına bakın. Ticari kullanım için yukarıdaki `commercial` plan ve uzak lisans zorunludur.
