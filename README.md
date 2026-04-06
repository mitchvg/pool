# PoolCheck Pro 💧

Zwembad onderhoud systeem — Villa Park Fontein.

## Bestanden in deze repo

| Bestand | Functie |
|---------|---------|
| `index.html` | App voor monteur/klant |
| `admin.html` | Beheerderspaneel |
| `history.html` | Klantgeschiedenis |
| `api.php` | Backend + **alle configuratie staat hier** |
| `schema.sql` | Database schema (veilig om opnieuw te draaien) |
| `deploy.php` | Automatische SQL-migratie na elke git pull |

---

## Configuratie — alles in api.php

```php
define('DB_PASS',        '...');   // Database wachtwoord
define('MAIL_PASS',      '...');   // E-mail wachtwoord  
define('ADMIN_PASS',     '...');   // Admin panel wachtwoord
define('CLAUDE_API_KEY', '...');   // Claude Vision API key
```

---

## Auto-deploy instellen (eenmalig, 10 minuten)

### 1. Webhook URL uit Plesk kopiëren
Plesk → **Git** → klik op `pool.git` → **Settings** → onderaan staat een **Webhook URL** — kopieer die.

### 2. Webhook toevoegen in GitHub
**github.com/mitchvg/pool → Settings → Webhooks → Add webhook**
- Payload URL: de Plesk webhook URL
- Content type: `application/json`
- Klik **Add webhook**

→ Plesk deployt nu direct bij elke push naar GitHub.

### 3. SQL automatisch laten draaien
Plesk → Git → je repo → **Edit** → vink aan **"Enable additional deployment actions"** → vul in:

```
php /var/www/vhosts/pool.villaparkfontein.com/httpdocs/deploy.php
```

*(controleer het pad in Plesk File Manager als bovenstaande niet klopt)*

---

## Dagelijks gebruik

**Stap 1:** Vertel Claude wat je wil wijzigen  
**Stap 2:** Claude zegt welk bestand gewijzigd is (bijv. alleen `api.php`)  
**Stap 3:** Jij uploadt dat bestand naar github.com/mitchvg/pool  
**Stap 4:** Plesk deployt automatisch ✓

**GitHub upload op mobiel/desktop:**
- Ga naar github.com/mitchvg/pool → klik het bestand → ✏️ bewerken
- Of: **Add file → Upload files** → meerdere losse bestanden tegelijk slepen
- **Geen ZIP** — upload altijd losse bestanden, GitHub pakt ZIPs niet uit

---

## URLs

| URL | Functie |
|-----|---------|
| `pool.villaparkfontein.com/?m=TOKEN` | App voor monteur (persoonlijke link) |
| `pool.villaparkfontein.com/?k=TOKEN` | Woning QR code |
| `pool.villaparkfontein.com/admin.html` | Beheerderspaneel |
| `pool.villaparkfontein.com/history.html?h=TOKEN` | Klantgeschiedenis |
