# BIMM_Connect — Magento 2 Module

REST API backend for the BIMModeller Revit plugin. Built as a Magento 2 module (`BIMM_Connect`).

---

## Prerequisites

Before you begin, install the following on your machine:

| Tool | Notes |
|---|---|
| Windows 11 | Required for WSL2 |
| WSL2 + Ubuntu | `wsl --install` in PowerShell as Administrator, then restart |
| Docker Desktop | Enable "Use WSL 2 based engine" during install |
| Magento Marketplace account | Free — get credentials at [commercemarketplace.adobe.com](https://commercemarketplace.adobe.com) |

### Docker Desktop WSL2 setup
After installing Docker Desktop:
1. Open Docker Desktop → Settings → Resources → WSL Integration
2. Enable integration for Ubuntu
3. Settings → Resources → Advanced → set Memory to at least **6144 MB (6 GB)**
4. Click **Apply & Restart**

### Magento Marketplace credentials
1. Sign in at [commercemarketplace.adobe.com](https://commercemarketplace.adobe.com)
2. Profile → **Access Keys** → **Create A New Access Key**
3. Save your **Public Key** (username) and **Private Key** (password)

---

## Common port conflicts

If you have MySQL or Redis installed natively on Windows, they will conflict with Docker. Disable them before starting:

Open **PowerShell as Administrator**:
```powershell
Get-Service | Where-Object { $_.Name -like "*redis*" -or $_.Name -like "*mysql*" } | ForEach-Object {
    Stop-Service $_.Name -Force
    Set-Service $_.Name -StartupType Disabled
    Write-Host "Disabled: $($_.Name)"
}
```

---

## Setup (first time only)

### Step 1 — Clone the repo

Open **Ubuntu (WSL2)**:
```bash
git clone https://github.com/kumaraveleie/bimmodeller.git
cd bimmodeller
```

### Step 2 — Start the Docker stack

```bash
cd bimm-connect/setup
chmod +x docker-setup.sh
./docker-setup.sh
```

This creates `~/bimm-magento-dev/` with the full Magento Docker stack (nginx, phpfpm, MariaDB 10.6, OpenSearch, Redis, RabbitMQ).

### Step 3 — Download Magento

```bash
cd ~/bimm-magento-dev
mkdir src

bin/composer create-project \
  --repository=https://repo.magento.com/ \
  magento/project-community-edition=2.4.7-p3 src
```

When prompted:
- **Username** → your Magento Marketplace **Public Key**
- **Password** → your Magento Marketplace **Private Key**
- Enter `Y` to store credentials

Takes 5–10 minutes.

### Step 4 — Install Magento

```bash
cd ~/bimm-magento-dev
bin/setup-install
```

Takes 5–10 minutes.

### Step 5 — Mount and enable BIMM_Connect module

```bash
mkdir -p ~/bimm-magento-dev/src/app/code/BIMM
ln -sfn /path/to/bimmodeller/bimm-connect ~/bimm-magento-dev/src/app/code/BIMM/Connect

bin/magento module:enable BIMM_Connect
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Replace `/path/to/bimmodeller` with the actual path where you cloned the repo.

### Step 6 — Add hosts entry

**PowerShell as Administrator:**
```powershell
Add-Content -Path "C:\Windows\System32\drivers\etc\hosts" -Value "127.0.0.1 magento.test"
```

**Ubuntu terminal:**
```bash
echo "127.0.0.1 magento.test" | sudo tee -a /etc/hosts
```

### Step 7 — Verify

```bash
curl -sk https://magento.test/rest/V1/bimm/health
```

Expected response:
```json
{"status":"ok","version":"1.0.0","magento_version":"2.4.7-p3"}
```

---

## Daily workflow

Open **Ubuntu (WSL2)**:

```bash
cd ~/bimm-magento-dev

# Start containers (if stopped)
docker compose up -d

# After editing PHP files
bin/magento cache:flush

# Stop at end of day
docker compose down
```

---

## Access the app

| URL | What |
|---|---|
| `https://magento.test` | Storefront |
| `https://magento.test/admin` | Admin panel (admin / admin1234) |
| `https://magento.test/rest/V1/bimm/health` | API health check |

> Accept the SSL warning in browser ("Advanced → Proceed") — it's a self-signed local certificate.

---

## API endpoints

| Route | Method | Auth | Description |
|---|---|---|---|
| `/rest/V1/bimm/health` | GET | Public | Liveness probe |
| `/rest/V1/bimm/version-manifest` | GET | Public | Plugin auto-update info |
| `/rest/V1/bimm/me` | GET | Bearer | Current user + subscription |
| `/rest/V1/bimm/products` | GET | Bearer | Product listing |
| `/rest/V1/bimm/products/:id` | GET | Bearer | Product detail |
| `/rest/V1/bimm/categories` | GET | Bearer | Category tree |
| `/rest/V1/bimm/search?q=` | GET | Bearer | Full-text search |
| `/rest/V1/bimm/family/:id/download` | GET | Bearer | Download RFA file (quota-gated) |
| `/rest/V1/bimm/events` | POST | Bearer | Ingest analytics events |
| `/rest/V1/bimm/oauth/token` | POST | Public | Exchange auth code for token |
| `/rest/V1/bimm/oauth/refresh` | POST | Public | Refresh access token |
| `/rest/V1/bimm/oauth/revoke` | POST | Bearer | Revoke token |

Full API spec in `BIMModeller_Track_A_Backend_Staging_v1.0.md`.

---

## Troubleshooting

### Port already in use (3306 or 6379)
```powershell
# Find the PID using the port
netstat -ano | findstr :3306

# Kill it
taskkill /PID <pid> /F
```

### MariaDB version error during setup:install
Edit `~/bimm-magento-dev/docker-compose.yml` and change the `db` image to `mariadb:10.6`, then:
```bash
docker compose down -v
docker compose up -d
bin/setup-install
```

### PHP version too new
Edit `~/bimm-magento-dev/compose.versions.yaml` and change PHP image from `8.4` to `8.3`, then:
```bash
docker compose down
docker compose up -d
```

### Credentials not prompted by composer
Set them manually:
```bash
docker exec -it bimm-magento-dev-phpfpm-1 composer config \
  --global http-basic.repo.magento.com YOUR_PUBLIC_KEY YOUR_PRIVATE_KEY
```

### curl: Could not resolve host: magento.test
The hosts entry is missing. Re-run Step 6 above.

---

## Optional — Public URL via Cloudflare Tunnel

Exposes your local instance as a public HTTPS URL (needed for Track B Revit plugin integration tests).

Requires a Cloudflare account with a domain you own.

```bash
# Edit the domain in the script first
nano bimm-connect/setup/cloudflare-tunnel.sh
# Replace "bimm-dev.yourdomain.com" with your actual domain

chmod +x bimm-connect/setup/cloudflare-tunnel.sh
./bimm-connect/setup/cloudflare-tunnel.sh
```

Keep the tunnel terminal open while developing. Update Magento base URL:
```bash
cd ~/bimm-magento-dev
bin/magento config:set web/secure/base_url https://bimm-dev.yourdomain.com/
bin/magento config:set web/unsecure/base_url https://bimm-dev.yourdomain.com/
bin/magento cache:flush
```
