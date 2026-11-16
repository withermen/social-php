# SocialPHP

Kısa ve uzun video sosyal medya ürünleri için sıfırdan yazılmış, kendi sunucunuzda çalışan PHP backend API'si. Hazır bir UI içermez; siz sadece arayüzü yazarsınız, hesaplar, yüklemeler, akış, yorumlar, bildirimler, mesajlar ve moderasyon bu kütüphane tarafından yönetilir.

- Framework bağımlılığı yok. PHP 8.1+ ve PDO yeterli.
- SQLite ile sıfır kurulum, MySQL ile production kullanımı.
- JWT erişim tokenı + dönen refresh token.
- Parçalı ve devam ettirilebilir video yükleme.
- Byte-range video streaming.
- Takip, keşfet, trend, hashtag ve kişiselleştirilmiş akış.
- Yorum, yanıt, beğeni, kaydetme, paylaşma, görüntüleme.
- Bildirimler, direkt mesajlar, arama, şikayet ve admin moderasyonu.
- FFmpeg varsa otomatik süre çözünürlük ve kapak görseli; yoksa güvenli fallback.
- Test edilmiş 60+ endpoint.

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

## Endpoint Listesi

Tüm endpoint'ler `/api/v1` altındadır. `Auth` kolonu `Bearer` token gerektirir, `Optional` token varsa kişiselleştirir.

### Sistem

| Method | Endpoint | Auth | Açıklama |
| --- | --- | --- | --- |
| GET | `/` | - | API bilgisi |
| GET | `/health` | - | Veritabanı ve storage sağlığı |
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

Görünürlük değerleri: `public`, `unlisted`, `private`.

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
| GET | `/explore` | Optional | Trend videolar |
| GET | `/trends?days=7&limit=20` | Optional | Trend hashtag'ler |
| GET | `/hashtags/{tag}` | Optional | Hashtag akışı |

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

`/feed/foryou` son 14 günün adaylarını çeker ve şu sinyalleri puanlar:

- Tazelik: 72 saatlik yarı ömürle üstel azalma.
- Etkileşim: beğeni, yorum, paylaşım, kaydetme ve görüntüleme sayıları.
- Etkileşim oranı: görüntülemeye göre beğeni ve yorum yoğunluğu.
- Yazar yakınlığı: takip edilen yazara ek puan.
- Çeşitlilik: aynı yazardan üst üste en fazla 2 video.

Parametreler `config/social.php` içindeki `feed` bölümünden değiştirilir:

```php
'feed' => [
    'candidate_limit' => 240,
    'window_days' => 14,
    'half_life_hours' => 72,
    'max_per_author' => 2,
],
```

`following` akışı kronolojiktir. `explore` son 7 günün etkileşim ağırlıklı sıralamasıdır. `trends` hashtag'lerin son 7 gündeki görüntüleme ve beğeni toplamına göre sıralanır.

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

Test paketi geçici bir veritabanı ve depolama alanı oluşturur. Kayıt, giriş, token yenileme, profil, avatar, takip, video yükleme, kapak görseli, range stream, görüntüleme tekrarı, beğeni, kaydetme, yorum, yanıt, akış, keşfet, trend, arama, bildirim, mesaj, parçalı yükleme, moderasyon, ban, şifre sıfırlama ve yetki sınırları test edilir.

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

## Lisans

MIT. Ayrıntılar için `LICENSE` dosyasına bakın.
