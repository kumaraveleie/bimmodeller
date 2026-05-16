# Track A — Backend & Staging (Magento Module)

**Version:** v1.0
**Audience:** Track A engineer (Backend / Magento 2 + staging owner) — humans and AI tools alike (Claude Code / Codex / Antigravity)
**Companion doc:** Track B (Revit Plugin) — separate spec for the plugin developer
**Master reference:** `BIMModeller_Architecture_Design_Implementation_v1.2_Magento.md` (system-wide architecture)

---

## How to use this document

This is the **self-contained spec for Track A**. Track A is the engineer who owns:

1. The local Docker Magento 2 staging environment
2. The Cloudflare Tunnel that exposes it as a public URL
3. The `BIMM_Connect` Magento 2 module (PHP)
4. The admin dashboard backend (Magento side; React frontend is shared)
5. Seed-data import from the BIMModeller catalog snapshot
6. Cutover to the real bimmodeller.com when client grants access

**Track A does NOT touch:**
- The Revit plugin (C# / WPF) — that's Track B's job
- Anything in the `bimmodeller-revit-plugin` repo

**The contract between Track A and Track B is §3 (REST API).** Once both tracks sign off on the contract at end of Week 2, Track A delivers against the spec independently. Any later change to §3 needs a PR with sign-off from both tracks.

---

## 1. Goal and success criteria

### 1.1 Primary goal

Stand up a working Magento 2.4 staging instance (free, Cloudflare-tunnelled) and build the `BIMM_Connect` Magento 2 module that exposes the REST API in §3. By Week 7 the module supports unauthenticated read endpoints. By Week 9 it supports OAuth + subscription gating end-to-end. By Week 11 the same module is deployable to bimmodeller.com production.

### 1.2 Concrete success criteria (testable)

- `curl https://bimm-dev.{your-domain}.com/rest/V1/bimm/health` returns 200 by end of Week 1
- `curl /rest/V1/bimm/products` returns 3,874 products by end of Week 5
- `/rest/V1/bimm/search?q=fire+pull` returns ≤9 results in <500 ms by end of Week 6
- OAuth Authorization Code + PKCE round-trip working end-to-end by end of Week 8
- A Lite customer at quota limit gets 402 with upgrade URL by end of Week 9
- Admin dashboard renders KPIs from real events by end of Week 10
- Magento module deployable to production bimmodeller.com via `composer require + bin/magento module:enable` by end of Week 11

### 1.3 What success looks like to Track B

Track B's plugin makes HTTPS calls to your staging URL. Every call honours the contract in §3. Track B's CI uses your staging as the integration-test backend. If Track B's tests start failing, it's because you broke the contract — not their fault.

---

## 2. Tech stack

| Layer | Choice | Version |
|---|---|---|
| Platform | Magento 2 Open Source | 2.4.7-p3 (latest patch) |
| Language | PHP | 8.1+ (Magento 2.4 requirement) |
| Web server | Nginx (in Docker) | 1.24 |
| Database | MySQL | 8.0 |
| Search | Elasticsearch | 7.17 |
| Cache | Redis | 7 |
| Composer | for Magento + our module | latest |
| Module name | `BIMM_Connect` | namespace `BIMM\Connect\*` |
| Static analysis | PHPStan (level 5) + Magento Coding Standard | |
| Tests | PHPUnit + Magento Integration Tests | |
| Tunnel | Cloudflare Tunnel (`cloudflared`) | free tier |
| Local Docker | `markoshust/docker-magento` | 2.4.7-p3 |

**Do not introduce new dependencies without flagging in PR.** Stack is fixed.

---

## 3. REST API contract — the authoritative spec

**Base URL (dev):** `https://bimm-dev.{your-domain}.com/rest/V1/bimm`
**Base URL (production):** `https://bimmodeller.com/rest/V1/bimm`

### 3.1 Common conventions

- All authenticated routes require `Authorization: Bearer {access_token}`
- Errors return JSON: `{"code": "<machine-code>", "message": "Human readable", "data": {...}}`
- HTTP status codes used: 200, 201, 204, 400, 401, 402, 403, 404, 429, 500
- Rate limit: 60 requests/minute per token (return 429 with `Retry-After` header)
- Pagination: `?page=1&per_page=20` (max 100); response includes `X-Total` header
- Latency target: 200 ms median, 500 ms p99 for read endpoints

### 3.2 Endpoint inventory

| Route | Method | Auth | Status |
|---|---|---|---|
| `/rest/V1/bimm/health` | GET | Public | 200 OK |
| `/rest/V1/bimm/version-manifest` | GET | Public | 200 |
| `/rest/V1/bimm/oauth/authorize` | GET | Magento session | 302 |
| `/rest/V1/bimm/oauth/token` | POST | None (PKCE) | 200 |
| `/rest/V1/bimm/oauth/refresh` | POST | refresh_token | 200 |
| `/rest/V1/bimm/oauth/revoke` | POST | Bearer | 204 |
| `/rest/V1/bimm/me` | GET | Bearer | 200 |
| `/rest/V1/bimm/products` | GET | Bearer | 200 |
| `/rest/V1/bimm/products/{id}` | GET | Bearer | 200 |
| `/rest/V1/bimm/categories` | GET | Bearer | 200 |
| `/rest/V1/bimm/search?q={query}` | GET | Bearer | 200 |
| `/rest/V1/bimm/family/{id}/download` | GET | Bearer | 200 \| 402 |
| `/rest/V1/bimm/events` | POST | Bearer | 204 |

### 3.3 Endpoint specifications

#### `GET /health`

Public liveness probe. Returns:

```json
{"status": "ok", "version": "1.0.0", "magento_version": "2.4.7-p3"}
```

#### `GET /version-manifest` (public)

For the Revit plugin's auto-updater.

```json
{
  "latest": "1.0.3",
  "url": "https://bimmodeller.com/revit-plugin/BIMModeller.Setup.1.0.3.msi",
  "sha256": "abc123...",
  "released_at": "2026-05-09T12:00:00Z",
  "changelog": "Fixes Replace command on Revit 2025.",
  "min_supported": "1.0.0"
}
```

Source: WP option or env variable; admin can update.

#### `GET /oauth/authorize`

Magento native customer session must be active. Query params: `response_type=code`, `client_id`, `redirect_uri`, `scope`, `state`, `code_challenge`, `code_challenge_method=S256`.

If customer is signed in, render an "Authorize BIMModeller for Revit" screen with Allow / Deny buttons. On Allow:

1. Generate a 30-char random `code`
2. Store in cache: key `bimm_oauth_code:{code}`, TTL 10 min, value:
   ```json
   {"customer_id": 91, "client_id": "bimm-revit-plugin", "redirect_uri": "...", "code_challenge": "...", "scope": "catalog download"}
   ```
3. Redirect to `redirect_uri?code={code}&state={state}`

If customer is not signed in, redirect to Magento login, with returnto pointing back to `/oauth/authorize`.

If query has `return_to=plugin` flag, treat as a registration-then-authorize flow (see §4.5).

#### `POST /oauth/token`

Body: `grant_type=authorization_code`, `code`, `redirect_uri`, `client_id`, `code_verifier`.

Validation:
1. Look up cache `bimm_oauth_code:{code}` → if missing, return `400 invalid_grant`
2. Delete the cache entry (single-use)
3. Compute `expected_challenge = base64url(sha256(code_verifier))` → must equal stored `code_challenge`, else 400
4. Validate `redirect_uri` matches stored one
5. Issue access_token (1 h) and refresh_token (30 d)
6. Store hashed tokens in `bimm_oauth_token` table

Response:
```json
{"access_token": "...", "refresh_token": "...", "token_type": "Bearer", "expires_in": 3600, "scope": "catalog download"}
```

#### `POST /oauth/refresh`

Body: `grant_type=refresh_token`, `refresh_token`, `client_id`. Old refresh_token is revoked (rotation); new pair issued.

#### `POST /oauth/revoke`

Body: `token` (access or refresh). Mark `revoked_at` in `bimm_oauth_token`. Returns 204.

#### `GET /me`

Returns:
```json
{
  "user_id": 91,
  "email": "sarah@example.com",
  "name": "Sarah Chen",
  "subscription": {
    "tier_id": "lite",
    "tier_name": "Lite",
    "monthly_load_limit": 10,
    "quota_used": 7,
    "resets_at": "2026-06-01T00:00:00Z",
    "upgrade_url": "https://bimmodeller.com/pricing#essential"
  }
}
```

`tier_id` comes from `customer.group_code`; `monthly_load_limit`, `upgrade_url` from `bimm_subscription_tier_config` table.

#### `GET /products`

Query: `category` (slug), `manufacturer`, `compliance` (tag), `page`, `per_page` (max 100), `sort` (name|popularity|recent).

Response:
```json
{
  "items": [
    {
      "id": 412,
      "name": "Manual Fire Pull Station MPS-100",
      "slug": "manual-fire-pull-station-mps-100",
      "manufacturer": "Honeywell",
      "category": "fire-alarm-devices",
      "thumbnail_url": "https://...",
      "compliance_tags": ["ADA", "NFPA 72"],
      "file_size_bytes": 1438209,
      "updated_at": "2026-03-12T10:14:00Z"
    }
  ],
  "total": 412,
  "page": 1,
  "per_page": 20
}
```

Source: Native `catalog_product` rows with custom attributes (`bimm_manufacturer`, `bimm_compliance_tags`, `bimm_rfa_url`, etc.). Use Magento's `ProductRepositoryInterface` + custom collection filters.

#### `GET /products/{id}`

Full product detail. Same shape as items above plus `description`, `specs` object, `downloads` array.

```json
{
  "id": 412,
  "name": "...",
  "manufacturer": "...",
  "description": "...",
  "specs": {
    "Mounting": "Wall, 4-inch box",
    "Height AFF": "48 inches (ADA)",
    "Compliance": "NFPA 72, ADA, UL 38"
  },
  "downloads": [
    {"type": "rfa", "url_path": "/family/412/download", "size_bytes": 1438209},
    {"type": "pdf", "name": "Datasheet", "url": "https://cdn.../datasheet.pdf", "size_bytes": 312000}
  ],
  "thumbnail_url": "...",
  "compliance_tags": ["ADA", "NFPA 72"]
}
```

#### `GET /categories`

```json
{
  "categories": [
    {"slug": "fire-alarm-devices", "name": "Fire Alarm Devices", "icon": "🚨", "count": 412, "parent": null}
  ]
}
```

Source: Native `catalog_category` with custom attribute `bimm_icon_emoji`.

#### `GET /search?q=fire+pull`

Same shape as `/products`, scored by relevance. Use Magento's Elasticsearch query API.

#### `GET /family/{id}/download` — the critical subscription-gated endpoint

Validation flow:
1. Authenticate bearer token → customer_id
2. Resolve customer.group_id → tier from `bimm_subscription_tier_config`
3. If `tier.monthly_load_limit !== null` AND `customer.bimm_quota_used_this_month >= tier.monthly_load_limit`:
   ```json
   HTTP 402
   {
     "code": "quota_exceeded",
     "message": "You have used 10 of 10 monthly loads on the Lite plan.",
     "data": {
       "current_tier": "lite",
       "quota_used": 10,
       "monthly_limit": 10,
       "upgrade_url": "https://bimmodeller.com/pricing#essential",
       "resets_at": "2026-06-01T00:00:00Z"
     }
   }
   ```
4. Else: increment `bimm_quota_used_this_month` atomically, mint signed CDN URL (15-min TTL), log event, return:
   ```json
   HTTP 200
   {"url": "https://cdn.bimmodeller.com/rfa/mps-100.rfa?sig=...&exp=1715600000",
    "expires_at": "2026-05-13T15:30:00Z",
    "file_size_bytes": 1438209,
    "sha256": "abc123..."}
   ```

**Critical invariant: the server is authoritative. The plugin must NEVER decide a quota check.**

#### `POST /events`

Body:
```json
{
  "events": [
    {"event_type": "family_loaded", "occurred_at": "...", "install_id": "uuid-...", "payload": {"family_id": 412}}
  ]
}
```

Allowed `event_type`: `search_performed`, `category_browsed`, `product_viewed`, `family_loaded`, `family_replaced`, `error_occurred`, `signin_completed`, `signout_completed`, `quota_exceeded_seen`.

Response 204. Insert into `bimm_event` table.

---

## 4. Implementation details

### 4.1 Module structure

```
bimm-connect/                        # Repo root, installed at app/code/BIMM/Connect/
├── composer.json                    # type: magento2-module
├── registration.php
├── etc/
│   ├── module.xml                   # depends on Magento_Customer, Magento_Catalog, Magento_Webapi, Magento_Integration
│   ├── di.xml
│   ├── acl.xml                      # admin permissions
│   ├── webapi.xml                   # REST routes
│   ├── events.xml                   # observers (RegistrationBridge)
│   ├── crontab.xml                  # monthly quota reset
│   ├── db_schema.xml
│   └── adminhtml/{routes.xml,menu.xml,system.xml}
├── Setup/Patch/Data/
│   ├── CreateBimmTiersGroups.php    # creates customer groups Lite/Essential/Pro
│   ├── AddBimmProductAttributes.php # bimm_manufacturer, bimm_compliance_tags, etc.
│   └── AddBimmCustomerAttributes.php # bimm_quota_used_this_month, bimm_quota_reset_at
├── Api/                             # service interfaces
├── Model/
│   ├── Repository/                  # ProductsRepository, CategoriesRepository, EventsRepository
│   ├── OAuth/                       # AuthorizationService, TokenService, PkceVerifier
│   ├── Subscription/                # TierService, QuotaEnforcer, MonthlyResetJob
│   └── Event/EventIngestor.php
├── Controller/Adminhtml/Insights/   # serves React admin dashboard bundle
├── Observer/RegistrationBridge.php  # customer_register_success bridge for OAuth flow
├── Block/Adminhtml/Insights/Dashboard.php
├── view/adminhtml/{layout,templates,web/js}
└── Test/{Unit,Integration}
```

### 4.2 Database schema

`etc/db_schema.xml`:

```xml
<?xml version="1.0"?>
<schema xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Setup/Declaration/Schema/etc/schema.xsd">

  <table name="bimm_event" resource="default" engine="innodb">
    <column xsi:type="bigint" name="event_id" unsigned="true" nullable="false" identity="true"/>
    <column xsi:type="int" name="customer_id" unsigned="true" nullable="true"/>
    <column xsi:type="varchar" name="install_id" length="36" nullable="false"/>
    <column xsi:type="varchar" name="event_type" length="64" nullable="false"/>
    <column xsi:type="text" name="payload" nullable="false"/>
    <column xsi:type="varchar" name="revit_version" length="16" nullable="true"/>
    <column xsi:type="varchar" name="plugin_version" length="16" nullable="true"/>
    <column xsi:type="timestamp" name="created_at" default="CURRENT_TIMESTAMP"/>
    <constraint xsi:type="primary" referenceId="PRIMARY"><column name="event_id"/></constraint>
    <index referenceId="IDX_CUSTOMER_CREATED"><column name="customer_id"/><column name="created_at"/></index>
    <index referenceId="IDX_TYPE_CREATED"><column name="event_type"/><column name="created_at"/></index>
    <index referenceId="IDX_INSTALL"><column name="install_id"/></index>
  </table>

  <table name="bimm_oauth_token" resource="default" engine="innodb">
    <column xsi:type="bigint" name="token_id" unsigned="true" nullable="false" identity="true"/>
    <column xsi:type="char" name="token_hash" length="64" nullable="false"/>
    <column xsi:type="char" name="refresh_token_hash" length="64" nullable="false"/>
    <column xsi:type="int" name="customer_id" unsigned="true" nullable="false"/>
    <column xsi:type="varchar" name="scope" length="128" nullable="false"/>
    <column xsi:type="timestamp" name="expires_at" nullable="false"/>
    <column xsi:type="timestamp" name="created_at" default="CURRENT_TIMESTAMP"/>
    <column xsi:type="timestamp" name="revoked_at" nullable="true"/>
    <constraint xsi:type="primary" referenceId="PRIMARY"><column name="token_id"/></constraint>
    <constraint xsi:type="unique" referenceId="UNQ_TOKEN_HASH"><column name="token_hash"/></constraint>
    <index referenceId="IDX_CUSTOMER"><column name="customer_id"/></index>
  </table>

  <table name="bimm_subscription_tier_config" resource="default" engine="innodb">
    <column xsi:type="int" name="tier_id" unsigned="true" nullable="false" identity="true"/>
    <column xsi:type="int" name="customer_group_id" unsigned="true" nullable="false"/>
    <column xsi:type="varchar" name="code" length="32" nullable="false"/>
    <column xsi:type="varchar" name="name" length="64" nullable="false"/>
    <column xsi:type="int" name="monthly_load_limit" nullable="true"/>
    <column xsi:type="varchar" name="upgrade_url" length="512" nullable="true"/>
    <constraint xsi:type="primary" referenceId="PRIMARY"><column name="tier_id"/></constraint>
    <constraint xsi:type="unique" referenceId="UNQ_CODE"><column name="code"/></constraint>
    <constraint xsi:type="unique" referenceId="UNQ_GROUP"><column name="customer_group_id"/></constraint>
  </table>
</schema>
```

### 4.3 OAuth implementation (PKCE wrapper on Magento native OAuth)

We don't reimplement OAuth from scratch. We use Magento 2's built-in OAuth (which has tokens, integration scoping, etc.) and add a thin PKCE wrapper for the desktop-app flow.

Key class: `BIMM\Connect\Model\OAuth\AuthorizationService`. Skeleton:

```php
namespace BIMM\Connect\Model\OAuth;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Math\Random;
use Magento\Integration\Model\IntegrationTokenServiceInterface;

class AuthorizationService {
    public function __construct(
        private CacheInterface $cache,
        private Random $random,
        private TokenService $tokenService,
        private PkceVerifier $pkceVerifier,
        private \Magento\Customer\Model\Session $customerSession
    ) {}

    public function issueCode(array $params): string {
        // Called from /oauth/authorize when customer hits Allow
        $code = $this->random->getRandomString(40);
        $this->cache->save(
            json_encode([
                'customer_id'    => $this->customerSession->getCustomerId(),
                'client_id'      => $params['client_id'],
                'redirect_uri'   => $params['redirect_uri'],
                'code_challenge' => $params['code_challenge'],
                'scope'          => $params['scope'] ?? 'catalog download',
            ]),
            "bimm_oauth_code:$code",
            ['bimm_oauth'],
            600  // 10 minutes
        );
        return $code;
    }

    public function exchangeCodeForToken(string $code, string $verifier, string $redirectUri): array {
        $cached = $this->cache->load("bimm_oauth_code:$code");
        if (!$cached) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Code expired or invalid'));
        }
        $this->cache->remove("bimm_oauth_code:$code"); // single-use
        $data = json_decode($cached, true);

        if (!$this->pkceVerifier->verify($verifier, $data['code_challenge'])) {
            throw new \Magento\Framework\Exception\LocalizedException(__('PKCE verification failed'));
        }
        if (!hash_equals($data['redirect_uri'], $redirectUri)) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Redirect URI mismatch'));
        }

        return $this->tokenService->issuePair($data['customer_id'], $data['scope']);
    }
}
```

`PkceVerifier::verify($verifier, $challenge)` computes `base64url(sha256($verifier))` and compares to `$challenge` with `hash_equals`.

### 4.4 Subscription enforcement

`Model/Subscription/QuotaEnforcer.php`:

```php
namespace BIMM\Connect\Model\Subscription;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;

class QuotaEnforcer {
    public function __construct(
        private TierService $tierService,
        private CustomerRepositoryInterface $customerRepo
    ) {}

    public function canLoad(CustomerInterface $customer): bool {
        $tier = $this->tierService->forCustomerGroup($customer->getGroupId());
        if ($tier['monthly_load_limit'] === null) return true;
        $used = (int)$customer->getCustomAttribute('bimm_quota_used_this_month')?->getValue() ?: 0;
        return $used < $tier['monthly_load_limit'];
    }

    public function recordLoad(CustomerInterface $customer): void {
        $used = (int)$customer->getCustomAttribute('bimm_quota_used_this_month')?->getValue() ?: 0;
        $customer->setCustomAttribute('bimm_quota_used_this_month', $used + 1);
        $this->customerRepo->save($customer);
    }
}
```

### 4.5 Registration flow bridge

When a user clicks "Create an account" in the Revit plugin, the browser opens `bimmodeller.com/customer/account/create?return_to=plugin&...oauth_params`. After Magento creates the account, observer hooks into `customer_register_success` and forwards to `/rest/V1/bimm/oauth/authorize` with the original OAuth params. See `Observer/RegistrationBridge.php`.

### 4.6 Monthly quota reset cron

`etc/crontab.xml`:

```xml
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Cron:etc/crontab.xsd">
  <group id="default">
    <job name="bimm_monthly_quota_reset"
         instance="BIMM\Connect\Model\Subscription\MonthlyResetJob"
         method="execute">
      <schedule>0 0 1 * *</schedule>  <!-- first day of every month, 00:00 -->
    </job>
  </group>
</config>
```

`MonthlyResetJob::execute()` runs `UPDATE customer_entity_int SET value = 0 WHERE attribute_id = (id of bimm_quota_used_this_month)`.

### 4.7 Telemetry ingestion

`Model/Event/EventIngestor::insertBatch(array $events, ?int $customerId)`. Validates `event_type` against allowed list (§3.3 `/events`). Persists into `bimm_event`. No async queue in v1 — Magento can handle 60 inserts/min easily.

### 4.8 Admin dashboard wiring

`Controller/Adminhtml/Insights/Index.php` renders a Magento page that mounts the React bundle from `view/adminhtml/web/js/bimm-dashboard.bundle.js` (built from `bimm-admin-dashboard` repo). The dashboard makes authenticated API calls back to `/rest/V1/bimm/admin/*` endpoints (admin-only variants — separate ACL `BIMM_Connect::insights`).

### 4.9 Seed data import

We have a snapshot from the existing BIMModeller crawler: `catalog-snapshot.json` (3,874 products). Track A imports them on first run.

Use Magento's import API. Sketch in `Setup/Scripts/import-products.php`:

```php
foreach ($snapshot['products'] as $p) {
    $product = $this->productFactory->create();
    $product->setSku($p['sku']);
    $product->setName($p['name']);
    $product->setTypeId('simple');
    $product->setAttributeSetId(4);
    $product->setStatus(1);
    $product->setVisibility(4);
    $product->setData('bimm_manufacturer', $p['manufacturer']);
    $product->setData('bimm_compliance_tags', implode(',', $p['compliance_tags']));
    $product->setData('bimm_rfa_url', $p['rfa_url']);
    $product->setData('bimm_thumbnail_url', $p['thumbnail_url']);
    $product->setData('bimm_file_size_bytes', $p['file_size_bytes']);
    $this->productRepo->save($product);
}
```

Run as a CLI command: `bin/magento bimm:seed:products /path/to/catalog-snapshot.json`.

---

## 5. Local dev environment & Cloudflare Tunnel

### 5.1 Bring up Magento Docker stack

```bash
mkdir bimm-magento-dev && cd bimm-magento-dev
curl -s https://raw.githubusercontent.com/markshust/docker-magento/main/lib/onelinesetup | bash -s -- magento.test community 2.4.7-p3
# ~15 minutes; outputs Magento at https://magento.test (local-only TLS)
```

### 5.2 Mount BIMM_Connect module

```bash
cd src/app/code
mkdir -p BIMM
git clone git@github.com:your-org/bimm-connect.git BIMM/Connect
cd ../..
bin/magento module:enable BIMM_Connect
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### 5.3 Cloudflare Tunnel (named, with your own domain)

```bash
brew install cloudflared
cloudflared tunnel login          # opens browser
cloudflared tunnel create bimm-dev
cloudflared tunnel route dns bimm-dev bimm-dev.yourdomain.com
cat > ~/.cloudflared/config.yml <<EOF
tunnel: <id>
credentials-file: /Users/you/.cloudflared/<id>.json
ingress:
  - hostname: bimm-dev.yourdomain.com
    service: https://magento.test
    originRequest:
      noTLSVerify: true
  - service: http_status:404
EOF
cloudflared tunnel run bimm-dev
```

### 5.4 Magento base URL

```bash
docker compose exec phpfpm bash
bin/magento config:set web/secure/base_url https://bimm-dev.yourdomain.com/
bin/magento config:set web/unsecure/base_url https://bimm-dev.yourdomain.com/
bin/magento config:set web/secure/use_in_frontend 1
bin/magento config:set web/secure/use_in_adminhtml 1
bin/magento cache:flush
```

### 5.5 Verify

```bash
curl https://bimm-dev.yourdomain.com/rest/V1/bimm/health
# {"status":"ok", "version":"1.0.0", "magento_version":"2.4.7-p3"}
```

### 5.6 Daily workflow

Two terminals — Docker stack + tunnel. Stop with `Ctrl+C` and `docker compose down` at end of day. See Architecture §22.12.6 for tradeoffs.

---

## 6. Implementation order (week by week, Track A only)

| Week | Focus | Concrete deliverable |
|---|---|---|
| 1 | Bootstrap | Docker Magento up; Cloudflare Tunnel live with public URL; `bimm-connect` repo created; `registration.php`, `etc/module.xml`, `etc/db_schema.xml` skeleton; `/rest/V1/bimm/health` returns 200 |
| 2 | API contract sign-off | All endpoints listed in `etc/webapi.xml`; `/products`, `/categories` return empty arrays; product custom attributes patched via UpgradeData script. PHASE 1 GATE (joint with Track B). |
| 3 | Catalog | `Setup/Patch/Data/CreateBimmTiersGroups.php` (Lite/Essential/Pro customer groups); `bin/magento bimm:seed:products` imports 500 priority families from snapshot JSON; `/products` returns real data |
| 4 | Search + categories | Magento Elasticsearch query layer hooked up; `/categories` returns category tree with counts; `/search?q=` returns scored results; pagination working |
| 5 | Download (no quota yet) | `/family/{id}/download` returns 200 + signed CDN URL (mint via configured CDN service; for dev use local file paths or MinIO); telemetry POST endpoint accepts events; admin dashboard skeleton at `/admin/bimm/insights` |
| 6 | Polish + scale | All 3,874 families imported; performance budget hit (<500ms p99 on /search); error envelope consistent across all endpoints; rate limiting middleware in place |
| 7 | PHASE 2 GATE | All read endpoints fully working through Cloudflare Tunnel; Track B's plugin can hit them and render real data; admin dashboard skeleton showing live events |
| 8 | OAuth implementation | `AuthorizationService`, `TokenService`, `PkceVerifier` complete; `/oauth/authorize`, `/token`, `/refresh`, `/revoke` working; `RegistrationBridge` observer hooked into `customer_register_success`; `/me` returns user + tier |
| 9 | Subscription enforcement | `QuotaEnforcer` complete; `/family/{id}/download` returns 402 with upgrade payload when over quota; monthly cron job tested; admin dashboard shows per-user data. PHASE 3 GATE (joint). |
| 10 | GA prep | All cron jobs scheduled; rate limit tuned; error envelope verified; CI matrix green; admin dashboard final UX |
| 11 | Cutover | Module installable on bimmodeller.com production via `composer require + bin/magento module:enable + setup:upgrade`; data parity check passes; smoke tests green. PHASE 4 GATE. |

---

## 7. CI / GitHub Actions

`.github/workflows/ci.yml`:

```yaml
name: CI
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env: { MYSQL_ROOT_PASSWORD: magento, MYSQL_DATABASE: magento_test }
        ports: [3306:3306]
        options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s
      elasticsearch:
        image: elasticsearch:7.17.10
        env: { discovery.type: single-node, ES_JAVA_OPTS: "-Xms512m -Xmx512m" }
        ports: [9200:9200]
    steps:
      - uses: actions/checkout@v4
        with: { path: bimm-connect }
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.2', extensions: bcmath,intl,gd,xsl,soap }
      - name: Install Magento with module
        run: |
          composer create-project --repository=https://repo.magento.com magento/project-community-edition magento "2.4.7-p3"
          mkdir -p magento/app/code/BIMM
          mv bimm-connect magento/app/code/BIMM/Connect
          cd magento && bin/magento setup:install --base-url=http://localhost \
            --db-host=127.0.0.1 --db-name=magento_test --db-user=root --db-password=magento \
            --elasticsearch-host=localhost --elasticsearch-port=9200 \
            --admin-firstname=admin --admin-lastname=admin --admin-email=admin@test.local \
            --admin-user=admin --admin-password=admin1234 --use-rewrites=1 --search-engine=elasticsearch7
          bin/magento module:enable BIMM_Connect
          bin/magento setup:upgrade
          bin/magento setup:di:compile
      - run: cd magento && ./vendor/bin/phpstan analyse app/code/BIMM/Connect --level=5
      - run: cd magento && ./vendor/bin/phpunit -c app/code/BIMM/Connect/Test/phpunit.xml
```

---

## 8. Testing strategy

### 8.1 Unit tests (`Test/Unit/`)

| Class | Coverage target |
|---|---|
| `PkceVerifier` | 100% — covers valid verifier, wrong verifier, malformed challenge |
| `TierService` | 80% — covers all three tier lookups + missing-tier |
| `QuotaEnforcer` | 80% — under, at, and over quota; unlimited tier |
| `MonthlyResetJob` | 80% — resets counts; logs |
| `EventIngestor` | 70% — valid event, unknown event_type rejected, batch insertion |
| Controllers | 60% — happy path + 4xx errors |

### 8.2 Integration tests (`Test/Integration/`)

| Scenario | Steps |
|---|---|
| Full OAuth round-trip | Create customer; simulate `/authorize`; exchange code for token; access `/me` with token; revoke; subsequent calls return 401 |
| Quota enforcement | Customer in Lite group at 9 loads; load #10 succeeds; load #11 returns 402 with correct payload |
| Quota monthly reset | Run cron; verify all customers' quota_used reset to 0 |
| Telemetry batch ingestion | POST 5 events; query `bimm_event` table; row count matches |
| Registration bridge | Hit `customer/account/create?return_to=plugin&...`; verify redirect to `/oauth/authorize` with same params |

### 8.3 Performance tests

| Endpoint | Budget |
|---|---|
| `/products` (page 1, 20 items) | < 200 ms |
| `/products/{id}` | < 100 ms |
| `/categories` | < 100 ms (cached) |
| `/search?q=` (3,874 products, no exotic query) | < 500 ms p99 |
| `/family/{id}/download` | < 200 ms (signed URL generation only; no actual file transfer) |

Load test in Week 7 simulating 1,000 events/minute through `/events` — must not drop events or 5xx.

---

## 9. AI tooling guidance (Track A specific)

### 9.1 For Claude Code (implementer)

When asking Claude Code to implement a module, the prompt should:

1. Reference this document section by number
2. Specify the file(s) in scope
3. Reference §3 for any endpoint contract
4. List acceptance criteria

Example prompt:

> "Implement `BIMM\Connect\Model\OAuth\AuthorizationService` per Track A doc §4.3. Files: `app/code/BIMM/Connect/Model/OAuth/AuthorizationService.php` and `PkceVerifier.php`. Implement `issueCode()` and `exchangeCodeForToken()`. Write PHPUnit tests covering: valid round-trip, wrong verifier (must throw), expired code (must throw), redirect URI mismatch (must throw). Acceptance criteria: see §1.2 OAuth round-trip."

### 9.2 For Codex (reviewer)

Review checklist per Track A PR:

- [ ] Code conforms to Magento Coding Standard (`./vendor/bin/phpcs`)
- [ ] No direct SQL — use Magento ORM (`ResourceModel`, `ProductRepositoryInterface`, etc.)
- [ ] Tokens never logged
- [ ] Quota decisions only on server (no client-trusting code)
- [ ] DI compile passes (`bin/magento setup:di:compile`)
- [ ] New endpoint matches §3 spec exactly (URL, request body, response shape, status codes)
- [ ] If schema changes, `etc/db_schema.xml` AND `etc/db_schema_whitelist.json` both updated
- [ ] PHPStan level 5 passes
- [ ] New code has at least one unit test
- [ ] If touching the API contract (§3), there's a parallel PR with Track B sign-off

### 9.3 For Antigravity (tester)

Track A-specific test scenarios:

| Scenario | Pass criteria |
|---|---|
| OAuth happy path | curl `/oauth/authorize` → simulate consent → exchange code for token → call `/me` with token (200) → revoke token → call `/me` again (401) |
| OAuth wrong PKCE | Same as above but use wrong `code_verifier` in `/token` exchange → expect 400 |
| Subscription gating | Seed customer in Lite group at quota_used=9; call `/family/412/download` (200); call again (200, quota_used=10); call again (402 with correct upgrade_url and resets_at) |
| Telemetry ingestion | POST 100 events in one batch → query `bimm_event` → row count = 100 |
| Cron quota reset | Manually advance time to first of month; run cron; query all customers; all `bimm_quota_used_this_month` = 0 |
| Rate limit | Issue 61 requests in <60s from one bearer token → expect 429 on the 61st with `Retry-After` header |
| Registration bridge | curl `/customer/account/create?return_to=plugin&client_id=x&...` and complete form → expect 302 to `/oauth/authorize` with same params |

---

## 10. Acceptance criteria (Track A "done" definition)

By end of Week 11, Track A is complete when:

- [ ] All endpoints in §3 return responses matching the spec, validated by Antigravity scenarios in §9.3
- [ ] CI matrix green: PHPStan level 5 + PHPUnit + Magento integration tests
- [ ] Module installable on a fresh Magento 2.4.7 instance via `composer require + bin/magento module:enable + bin/magento setup:upgrade`
- [ ] Seed data import script works on a fresh Magento install
- [ ] Cloudflare Tunnel daily-driver workflow documented in `bimm-connect/README.md`
- [ ] Production cutover dry-run successful on a clean Magento staging
- [ ] Admin dashboard accessible at `/admin/bimm/insights` showing real telemetry data
- [ ] Zero P0 bugs open
- [ ] Track B has signed off on the contract — their plugin works end-to-end against Track A's staging

---

## 11. Cutover to bimmodeller.com production

When BIMModeller grants production Magento access (expected Week 10-11):

```bash
# On the client's production Magento root, as their dev:
composer require bimmodeller/bimm-connect
bin/magento module:enable BIMM_Connect
bin/magento setup:upgrade        # creates tables, customer attributes, customer groups
bin/magento setup:di:compile
bin/magento cache:flush

# Configure tier rules via admin UI (Stores → Configuration → BIMModeller → Tiers)
# OR via CLI:
bin/magento bimm:tiers:configure --tier=lite --limit=10 --upgrade-url=https://bimmodeller.com/pricing#essential

# Register the production OAuth client:
bin/magento bimm:oauth:register-client \
  --client-id=bimm-revit-plugin \
  --redirect-uri=http://localhost:47620/callback

# Run the seed-data import IF their existing product catalog needs `bimm_*` attributes backfilled:
bin/magento bimm:seed:backfill   # custom CLI that adds bimm_manufacturer etc. to existing products

# Smoke test:
curl https://bimmodeller.com/rest/V1/bimm/health
# Expected: {"status":"ok"}
```

Data parity check (`bin/magento bimm:parity-check --target https://bimmodeller.com`) confirms category structure, product counts, and customer group IDs match the dev environment.

After cutover, Track B updates plugin `appsettings.json` to point at `bimmodeller.com` — that's the only change on the plugin side.

---

## 12. Useful references

- [Magento 2 DevDocs](https://devdocs.magento.com/) — official documentation
- [Mark Shust's docker-magento](https://github.com/markshust/docker-magento) — our local Docker setup
- [Magento Coding Standard](https://github.com/magento/magento-coding-standard) — required for marketplace
- [OAuth 2.0 PKCE RFC 7636](https://datatracker.ietf.org/doc/html/rfc7636) — the auth flow we implement
- [Cloudflare Tunnel docs](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/) — for the public staging URL
- Master architecture doc: `BIMModeller_Architecture_Design_Implementation_v1.2_Magento.md`

---

End of Track A spec. Track B has its own doc; the two meet only at §3 (the REST API contract).
