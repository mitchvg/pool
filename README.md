# PoolCheck Pro 💧

Zwembad onderhoud systeem voor Villa Park Fontein.

## Setup (eenmalig)

### 1. GitHub Secrets instellen

Ga naar github.com/mitchvg/pool → **Settings → Secrets and variables → Actions** → klik **New repository secret** voor elk van deze:

| Secret | Waarde |
|--------|--------|
| `SSH_HOST` | `pool.villaparkfontein.com` |
| `SSH_USER` | SSH gebruikersnaam van je VPS (bijv. `root` of Plesk gebruiker) |
| `SSH_PASS` | SSH wachtwoord |
| `SSH_PATH` | Pad naar de webroot, bijv. `/var/www/vhosts/pool.villaparkfontein.com/httpdocs` |

> **Tip: SSH_PATH vinden**
> Log in op je VPS via Plesk Terminal of SSH en typ: `ls /var/www/vhosts/` — je ziet dan de mappen. Het pad eindigt op `/httpdocs`.

### 2. Code voor het eerst uploaden

```bash
git clone https://github.com/mitchvg/pool.git
cd pool

# Kopieer alle v4 bestanden hierin
# (of download de ZIP van Claude en pak uit)

git add .
git commit -m "Initiële versie PoolCheck Pro v4"
git push origin main
```

GitHub Actions deployt nu automatisch naar je VPS.

### 3. Database instellen (eenmalig)

Ga naar Plesk → Databases → phpMyAdmin → SQL tab → plak inhoud van `schema.sql` → Uitvoeren.

---

## Dagelijks gebruik

### Code wijzigen via Claude.ai (mobiel of desktop)
1. Bespreek wijziging met Claude in dit Project
2. Claude past de bestanden aan
3. Download de ZIP van Claude
4. Upload naar github.com/mitchvg/pool (drag & drop)
5. GitHub Actions deployt automatisch ✓

### Handmatig updaten
Ga naar `pool.villaparkfontein.com/update.php` → wachtwoord `PoolAdmin2024!` → upload ZIP.

---

## URLs

| URL | Functie |
|-----|---------|
| `pool.villaparkfontein.com/` | App voor monteur |
| `pool.villaparkfontein.com/?m=TOKEN` | Monteur ingelogd |
| `pool.villaparkfontein.com/?k=TOKEN` | Woning QR code |
| `pool.villaparkfontein.com/admin.html` | Beheerderspaneel |
| `pool.villaparkfontein.com/history.html?h=TOKEN` | Klantgeschiedenis |
| `pool.villaparkfontein.com/update.php` | Updater |

---

## Wachtwoorden (wijzig na installatie!)

- Admin panel: `PoolAdmin2024!`
- Updater: `PoolAdmin2024!`
- DB user: `pool` — zie `api.php` voor wachtwoord

## API Key

Voeg je Anthropic API key toe in `api.php` regel:
```php
define('CLAUDE_API_KEY', 'sk-ant-...');
```

Haal een nieuwe op via [console.anthropic.com](https://console.anthropic.com) als de huidige verlopen/gestolen is.
