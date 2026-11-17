# SocialPHP Lisans Sunucusu

Bu klasör `kerem.site/api/lisans` altına olduğu gibi yüklenebilir. PHP 8.1+ ve SQLite yeterlidir; Composer gerekmez.

## Kurulum

1. Klasörü sunucuya `api/lisans` olarak kopyalayın.
2. `config.example.php` dosyasını `config.php` olarak kopyalayın.
3. Terminalde:

```bash
cd /path/to/api/lisans
php bin/license init
```

Bu komut RSA anahtar çiftini, SQLite veritabanını ve yönetici token'ını üretir. Çıktıdaki **public key** değerini kurulu SocialPHP tarafında `LICENSE_SERVER_PUBLIC_KEY` olarak kullanın.

4. Apache kullanıyorsanız `.htaccess` dosyaları hazırdır. Nginx kullanıyorsanız:

```nginx
location /api/lisans {
    try_files $uri $uri/ /api/lisans/index.php?$query_string;
}

location ~ ^/api/lisans/(config\.php|data/|bin/) {
    deny all;
}
```

5. HTTPS zorunludur. `config.php` içindeki `admin_token` değerini kimseyle paylaşmayın.

## Lisans üretme

Aylık ticari lisans 100 TL'dir ve 30 gün geçerlidir:

```bash
php bin/license issue --email=musteri@example.com --domain=ornek.com --plan=monthly
```

Çıktıdaki `SOC-XXXX-XXXX-XXXX-XXXX` anahtarını müşteriye verin. Domain zorunludur; lisans yalnızca o domainde çalışır.

Diğer komutlar:

```bash
php bin/license list
php bin/license revoke --id=<license_id>
php bin/license stats
php bin/license keys
```

## Uçlar

| Method | Yol | Açıklama |
| --- | --- | --- |
| GET | `/health` | Servis durumu |
| GET | `/plans` | Plan listesi |
| POST | `/activate` | Lisansı domain + kuruluma bağla |
| POST | `/verify` | Lisansı doğrula |
| POST | `/deactivate` | Aktivasyonu kaldır |
| POST | `/admin/issue` | Lisans üret (`X-Admin-Token`) |
| GET | `/admin/licenses` | Lisansları listele (`X-Admin-Token`) |
| POST | `/admin/revoke` | Lisansı iptal et (`X-Admin-Token`) |
| GET | `/admin/stats` | İstatistikler (`X-Admin-Token`) |

`activate` ve `verify` gövdesi:

```json
{
  "key": "SOC-XXXX-XXXX-XXXX-XXXX",
  "domain": "ornek.com",
  "installation_id": "inst_abc123",
  "nonce": "her istekte yeni 16-128 karakter"
}
```

Yanıtlar RSA-SHA256 ile imzalanır. İstemci `sig` alanını doğrulamadan yanıtı kabul etmez. Nonce tekrar kullanılamaz, saat farkı 5 dakikadan fazla olamaz.

## Güvenlik notları

- `config.php`, `data/` ve `bin/` web'den erişilemez olmalıdır.
- Veritabanını ve `data/private.key` dosyasını düzenli yedekleyin.
- Lisans iptali bir sonraki doğrulamada (varsayılan 1 saat) etkili olur.
- Aynı lisansın farklı domainlerde çalıştırılması domain bağlama ile engellenir.
