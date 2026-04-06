# PoolCheck Pro 💧

Zwembad onderhoud systeem — Villa Park Fontein.

## Bestanden (dit zijn alle bestanden in de repo)

| Bestand | Functie |
|---------|---------|
| `index.html` | App voor monteur/klant |
| `admin.html` | Beheerderspaneel (versie-indicator ingebouwd) |
| `history.html` | Klantgeschiedenis |
| `api.php` | Backend — **alle config staat hier** |
| `deploy.php` | Auto-deploy: SQL migraties + opschonen + versie opslaan |

## Configuratie — alles in api.php

```php
define('DB_PASS',        '...');   // Database wachtwoord
define('MAIL_PASS',      '...');   // E-mail wachtwoord
define('ADMIN_PASS',     '...');   // Admin panel wachtwoord
define('CLAUDE_API_KEY', '...');   // Claude Vision API key
define('APP_VERSION',    '4.6');   // Versienummer — ook in deploy.php aanpassen!
```

## Auto-deploy instellen (eenmalig)

### 1. Webhook uit Plesk kopiëren
Plesk → Git → `pool.git` → Settings → kopieer de **Webhook URL**

### 2. Webhook in GitHub instellen
github.com/mitchvg/pool → Settings → Webhooks → Add webhook  
Payload URL: Plesk webhook URL | Content type: `application/json`

### 3. Deployment action in Plesk
Edit → Enable additional deployment actions:
```
php /var/www/vhosts/pool.villaparkfontein.com/httpdocs/deploy.php
```

## Dagelijks gebruik

1. Vertel Claude wat je wil wijzigen
2. Claude zegt welk bestand gewijzigd is
3. Upload dat bestand naar github.com/mitchvg/pool
4. Plesk deployt direct — je ziet ✓ Versie X.X in het admin panel

## Versie bijhouden

Bij elke update pas je **twee** regels aan (beide dezelfde waarde):
- `api.php` → `define('APP_VERSION', '4.7');`
- `deploy.php` → `const APP_VERSION = '4.7';`

Het admin panel toont dan of bestanden en database op dezelfde versie zitten.

## URLs

| URL | Functie |
|-----|---------|
| `pool.villaparkfontein.com/?m=TOKEN` | Monteur/klant app |
| `pool.villaparkfontein.com/admin.html` | Beheerderspaneel |
| `pool.villaparkfontein.com/history.html?h=TOKEN` | Klantgeschiedenis |
