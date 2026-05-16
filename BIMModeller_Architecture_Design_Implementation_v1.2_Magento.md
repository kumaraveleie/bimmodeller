# BIMModeller Revit Plugin — Architecture, Design & Implementation

**Version:** v1.2 (Magento + zero-cost staging — supersedes v1.1)
**Status:** Authoritative reference for AI-assisted implementation
**Audience:** Claude Code (implementer), Codex (reviewer), Antigravity (tester), human engineers
**Scope:** v1.2 of the client agreement — separate-window UI, OAuth via bimmodeller.com, subscription gating
**Source:** Distillation of the Client Handover v1.2 and Team Execution Plan v1.2
**Platform note:** bimmodeller.com is built on **Magento 2 Open Source** (assumed 2.4.x). The bimm-connect server-side component is implemented as a **Magento 2 module**, not a WordPress plugin. The Revit plugin, REST API contract, OAuth flow, and admin dashboard are unaffected by this platform choice.

---

## How to use this document

This is a **machine-readable specification**. Every component has a defined contract; every contract has acceptance criteria. AI agents should treat the contracts in Parts II and III as authoritative. When implementation requires a decision not covered here, agents must (1) pick the simplest option consistent with the documented architecture, (2) note the choice in code comments, and (3) flag it in the PR description for human review.

**Roles assumed in this document:**

- **Implementer** (Claude Code / human) — writes code conforming to this spec.
- **Reviewer** (Codex) — checks PRs against this spec; flags deviations.
- **Tester** (Antigravity) — writes tests against acceptance criteria; runs CI matrix.

---

# Part I — Architecture

## 1. System overview

Three independently deployed components communicate over HTTPS:

```
┌─────────────────────────────┐         ┌──────────────────────────────┐
│  Revit Plugin               │         │  bimm-connect                │
│  (C# / WPF / Revit API)     │◀──────▶│  (PHP / WordPress plugin)    │
│  Installed per user         │  HTTPS  │  Installed on bimmodeller.com│
│  Windows + Revit 2024-2027  │  REST   │  Hosts REST API + OAuth      │
└─────────────────────────────┘         └────────────┬─────────────────┘
                                                     │
                                                     │ Reads/writes
                                                     ▼
                                        ┌──────────────────────────────┐
                                        │  WordPress DB + CDN          │
                                        │  - Products (WP CPT)         │
                                        │  - Categories (WP taxonomy)  │
                                        │  - Users (WP users)          │
                                        │  - Subscriptions (usermeta)  │
                                        │  - Events (custom table)     │
                                        │  - .rfa files (CDN bucket)   │
                                        └────────────┬─────────────────┘
                                                     ▲
                                                     │ Reads
                                                     │
                                        ┌────────────┴─────────────────┐
                                        │  Admin Dashboard             │
                                        │  (React 18 / TypeScript)     │
                                        │  Hosted at                   │
                                        │  bimmodeller.com/admin/...   │
                                        └──────────────────────────────┘
```

**Data ownership:** All persistent data lives on bimmodeller.com. The plugin holds only an encrypted token (DPAPI) and an in-memory cache.

**Authentication:** OAuth 2.0 Authorization Code with PKCE. Identity provider is bimmodeller.com's Magento 2 customer account store. We layer our PKCE-enforcing OAuth endpoints on top of Magento's native customer authentication.

**Subscription enforcement:** Server-side, on the `/family/{id}/download` endpoint. Plugin renders the result; never enforces.

## 2. The three repositories

| Repo | Tech | Owner after delivery | Distribution |
|---|---|---|---|
| `bimmodeller-revit-plugin` | C# .NET multi-target (net48, net8.0-windows, net10.0-windows) | BIMModeller (private repo) | Signed MSI, distributed from bimmodeller.com/revit-plugin |
| `bimm-connect` | PHP 8+ Magento 2 module | BIMModeller (private repo) | Installed via Composer + `bin/magento module:enable BIMM_Connect` |
| `bimm-admin-dashboard` | React 18 + TypeScript + Vite | BIMModeller (private repo) | Built and served via a Magento admin page (custom layout XML); admin URL `/admin/bimm/insights` |

Each repo has independent CI but shares this document as the contract spec.

## 3. Technology stack

### 3.1 Revit plugin

| Layer | Choice | Reason |
|---|---|---|
| Language | C# 10+ | Standard for Revit API |
| Frameworks | net48 (Revit 2024), net8.0-windows (Revit 2025-2026), net10.0-windows (Revit 2027) | Multi-target csproj covers all Revit versions |
| UI | WPF (Window, not DockablePane) | Per v1.2 client requirement |
| MVVM | `CommunityToolkit.Mvvm` | Reduces boilerplate; modern source generators |
| DI | `Microsoft.Extensions.DependencyInjection` | Simple, well-documented |
| HTTP client | `HttpClient` with Polly retry policy | Built-in; Polly handles retries |
| JSON | `System.Text.Json` | Built-in; fastest |
| OAuth client | Custom — Authorization Code + PKCE | No external dep; ~300 lines |
| Token storage | Windows DPAPI (`ProtectedData`) | Per-user, no extra dep |
| Logging | Serilog (file sink, %LOCALAPPDATA%\BIMModeller\logs) | Structured logs |
| Installer | WiX Toolset v4 | Industry standard for MSI |
| Auto-update | Squirrel.Windows | Battle-tested; signed delta updates |
| 3D viewer | NOT USED (deferred from v1) | — |

### 3.2 Server-side connector (bimm-connect — Magento 2 module)

| Layer | Choice | Reason |
|---|---|---|
| Language | PHP 8.1+ | Required by Magento 2.4.x |
| Platform | Magento 2.4 Open Source | Confirmed by client |
| Composer | Yes (Magento ecosystem standard) | Module distribution + autoloading |
| Module name | `BIMM_Connect` | Magento naming convention `<Vendor>_<Module>` |
| Namespace | `BIMM\Connect\*` | PSR-4 autoload from `app/code/BIMM/Connect/` |
| REST API | Magento WebAPI (`etc/webapi.xml` → `Magento\Framework\Webapi`) under route `/rest/V1/bimm/*` | Native Magento integration |
| OAuth server | Magento 2 native OAuth 2.0 (with custom client registration). We add PKCE wrapper for the plugin client. | Saves ~1 week vs custom OAuth |
| Data model | Native `catalog_product` for products, native categories for taxonomy, **customer groups** for subscription tiers, custom DB table for events | Magento idiomatic |
| Customer accounts | Native `customer_entity` (no replacement needed) | Existing infrastructure |
| Subscription tier storage | Magento **customer groups** (Lite / Essential / Pro) — manageable from admin UI | First-class Magento feature |
| Quota tracking | Custom customer attribute `bimm_quota_used_this_month` | Editable via admin and API |
| Token signing | Magento's built-in integration token + custom JWT for plugin-issued access tokens | Reuses Magento's crypto primitives |
| Tests | PHPUnit (Magento Test Framework) + Integration Tests | Standard Magento test setup |
| Static analysis | PHPStan level 5 (Magento marketplace requirement) + Magento ECS | Catches type errors; required for marketplace |

### 3.3 Admin dashboard (bimm-admin-dashboard)

| Layer | Choice | Reason |
|---|---|---|
| Framework | React 18 | Mature, well-known |
| Language | TypeScript 5+ | Type safety for charts and data |
| Bundler | Vite | Fast HMR for development |
| State | Zustand | Lightweight, no Redux overhead |
| Data fetching | TanStack Query (React Query) | Caches API responses; handles loading/error states |
| UI primitives | Tailwind CSS + Radix UI | Themeable; accessible |
| Charts | Chart.js (via react-chartjs-2) | Standard, performant |
| Auth | Shared WP session cookie + nonce | No separate auth flow; admin already signed in to WP |
| Routing | React Router v6 | Standard |
| Build output | Static bundle served by WP page template | Single file deploy |

## 4. Data model

### 4.1 Magento entities

| Entity | Magento type | Key fields |
|---|---|---|
| **Product** | Native `catalog_product` (existing Magento entity) | name (default attribute), description, custom attributes: `bimm_manufacturer`, `bimm_sku`, `bimm_dimensions`, `bimm_compliance_tags`, `bimm_rfa_url`, `bimm_thumbnail_url`, `bimm_file_size_bytes`. Created during module install via UpgradeData script |
| **Category** | Native `catalog_category` (existing Magento taxonomy) | Add custom attribute `bimm_icon_emoji` and `bimm_sort_order` |
| **Customer** | Native `customer_entity` (existing) | + custom attributes: `bimm_quota_used_this_month`, `bimm_quota_reset_at` |
| **Subscription tier** | Magento **customer group** (existing entity) | One group per tier: Lite, Essential, Pro. Group has linked config via custom DB table `bimm_subscription_tier_config` storing `monthly_load_limit` and `upgrade_url` |
| **Event** | Custom table `bimm_event` (declared in `etc/db_schema.xml`) | event_id, customer_id (nullable), install_id, event_type, payload (json), created_at |
| **OAuth client** | Native Magento `oauth_consumer` (extended) | Plus custom table `bimm_oauth_pkce` for PKCE challenge storage |
| **OAuth token (plugin-issued)** | Custom table `bimm_oauth_token` | token_hash, customer_id, expires_at, refresh_token_hash, scope, created_at, revoked_at |
| **OAuth code** | Magento cache (TTL 10 min) — Redis if configured, else file cache | code → {client_id, customer_id, code_challenge, redirect_uri, expires_at} |

### 4.2 Sequence: family load

```
Plugin                      bimm-connect              CDN
  │ GET /products/123 (Bearer token)
  │ ─────────────────────────────▶
  │                                │ check token; check user tier
  │                                │ return product detail
  │ ◀─────────────────────────────
  │
  │ User clicks "Load"
  │
  │ GET /family/123/download (Bearer token)
  │ ─────────────────────────────▶
  │                                │ check token
  │                                │ check user.quota_used < tier.monthly_limit
  │                                │ if ok: return 200 + signed CDN URL
  │                                │ if over: return 402 + upgrade message
  │                                │ if ok: increment user.quota_used
  │                                │ insert into wp_bimm_events
  │ ◀─────────────────────────────
  │
  │ GET <signed_url>
  │ ───────────────────────────────────────────▶
  │                                              │ stream .rfa
  │ ◀─────────────────────────────────────────── (.rfa bytes)
  │
  │ [Revit API] LoadFamily(rfa_bytes)
  │ [Revit API] Place at cursor
  │ [Revit API] Open 3D + Plan views, zoom to instance
```

## 5. Authentication architecture

### 5.1 OAuth flow chosen

**OAuth 2.0 Authorization Code with PKCE** (RFC 7636). Selected because:

- Standard for native desktop apps with browser-based sign-in
- No client secret needed (PKCE replaces it; safe for distributable installers)
- Industry-supported; familiar to security reviewers
- Single round-trip after authorization


### 5.1.1 Registration flow for new users

The plugin's Sign-In screen has two paths:

- **"Sign in with BIMModeller"** (gold button) — existing user path; opens `/oauth/authorize` directly.
- **"Create an account"** (link below the sign-in button) — new user path; opens `https://bimmodeller.com/register?return_to=plugin` plus the same OAuth parameters (`client_id`, `state`, `code_challenge`, `redirect_uri`, `scope`).

The registration sequence:

1. User clicks "Create an account" in the plugin.
2. Plugin opens system browser to `bimmodeller.com/register` carrying both `return_to=plugin` and the OAuth params.
3. bimmodeller.com renders its existing WordPress registration page (name, email, password, plus a tier-choice radio set defaulting to Lite-free).
4. User submits the form. bimm-connect:
   - Creates the WordPress user (existing WP behavior).
   - Sets `bimm_subscription_tier` usermeta from the tier choice.
   - Sends the verification email (existing WP behavior).
   - Auto-signs the user in (`wp_set_auth_cookie`).
   - Detects `return_to=plugin` and the OAuth params, and **internally redirects to `/wp-json/bimm/v1/oauth/authorize`** with those params.
5. `/oauth/authorize` sees the now-signed-in user, shows the "Authorize BIMModeller for Revit" consent screen (or skips it if pre-authorized in this session).
6. Standard OAuth callback fires; plugin receives the code, exchanges for tokens, lands on Discover view with a "Welcome, {name}" toast.

**Why this is clean:**

- Same OAuth endpoint, same redirect URI, same token issuance. Registration is just an additional pre-step on bimmodeller.com.
- Plugin code doesn't know about registration internals — it just opens a different starting URL.
- WordPress already handles user creation, password validation, email verification — we reuse all of it.
- bimm-connect adds one small hook: detect `return_to=plugin` + OAuth params after registration and forward to the authorize endpoint.

**bimm-connect implementation (PHP, `src/OAuth/RegistrationBridge.php`):**

```php
namespace BIMM\Connect\OAuth;

class RegistrationBridge {
    public function register(): void {
        add_action('user_register', [$this, 'onUserRegister'], 10, 1);
    }

    public function onUserRegister(int $user_id): void {
        if (($_GET['return_to'] ?? null) !== 'plugin') return;

        // Set default tier from form selection (validated against tier whitelist)
        $tier = in_array($_POST['bimm_tier'] ?? 'lite', ['lite','essential','pro'])
                ? $_POST['bimm_tier'] : 'lite';
        update_user_meta($user_id, 'bimm_subscription_tier', $tier);
        update_user_meta($user_id, 'bimm_quota_used_this_month', 0);

        // Auto-sign-in this WP session
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, false);

        // Forward into OAuth authorize with original params
        $oauth_params = array_intersect_key($_GET, array_flip([
            'response_type','client_id','redirect_uri','scope','state',
            'code_challenge','code_challenge_method',
        ]));
        wp_redirect(rest_url('bimm/v1/oauth/authorize') . '?' . http_build_query($oauth_params));
        exit;
    }
}
```

**Plugin-side: building the register URL (`BIMModeller.Core.Auth.OAuthClient`):**

```csharp
public string BuildRegisterUrl()
{
    var verifier  = PkceHelper.NewVerifier();
    var challenge = PkceHelper.Challenge(verifier);
    _pendingState = Guid.NewGuid().ToString("N");
    _pendingVerifier = verifier;
    var qs = new Dictionary<string,string>{
        ["return_to"]              = "plugin",
        ["response_type"]          = "code",
        ["client_id"]              = _settings.OAuthClientId,
        ["redirect_uri"]           = _settings.RedirectUri,
        ["scope"]                  = "catalog download",
        ["state"]                  = _pendingState,
        ["code_challenge"]         = challenge,
        ["code_challenge_method"]  = "S256",
    };
    return $"{_settings.ApiBase}/register?" + ToQueryString(qs);
}
```

The plugin shows two buttons; both prepare PKCE state, both spawn the localhost listener, both wait for the same callback. The user just doesn't see which URL was opened.

**Acceptance criteria for registration flow:**

- New user clicks "Create an account" → browser opens to bimmodeller.com/register
- After submitting the form, the browser ends up at the plugin's localhost callback (same as the sign-in path)
- Plugin receives a valid token
- A new WP user exists with the chosen `bimm_subscription_tier`
- Email verification reminder is sent to the user's inbox
- Telemetry event `signup_completed` is emitted

### 5.2 Sequence

```
Plugin                Browser              bimm-connect (bimmodeller.com)
  │
  │ Generate code_verifier (random 43-128 chars)
  │ code_challenge = SHA256(code_verifier), base64url-encoded
  │
  │ Start localhost listener on port 47620-47629 (find free port)
  │
  │ ProcessStartInfo "https://bimmodeller.com/wp-json/bimm/v1/oauth/authorize"
  │                "?response_type=code"
  │                "&client_id=bimm-revit-plugin"
  │                "&redirect_uri=http://localhost:47620/callback"
  │                "&scope=catalog+download"
  │                "&code_challenge={cc}"
  │                "&code_challenge_method=S256"
  │                "&state={anti-csrf}"
  │ ────────────▶
  │                  │
  │                  │ User signs in to bimmodeller.com (WP login)
  │                  │ User clicks "Authorize"
  │                  │
  │                  │ ─────────────────▶ POST /authorize (form)
  │                  │                              │
  │                  │                              │ Validate; mint code; store transient
  │                  │ ◀─── 302 to redirect_uri ────
  │                  │      ?code=xyz&state=...
  │
  │ ◀── localhost HTTP receives GET /callback?code=xyz&state=...
  │
  │ POST /oauth/token (form)
  │   grant_type=authorization_code
  │   code=xyz
  │   redirect_uri=http://localhost:47620/callback
  │   client_id=bimm-revit-plugin
  │   code_verifier={original_verifier}
  │ ──────────────────────────────────────────────────▶
  │                                                       │ Validate code+verifier
  │                                                       │ Issue access_token (1h)
  │                                                       │ Issue refresh_token (30d)
  │ ◀────────────────────────── 200 { access_token, refresh_token, expires_in, scope } ──
  │
  │ Store both tokens encrypted via DPAPI
  │ Close localhost listener
  │ Show signed-in UI
```

### 5.3 Token storage

- File: `%LOCALAPPDATA%\BIMModeller\auth.bin`
- Content: `ProtectedData.Protect(JSON({access_token, refresh_token, expires_at, user_email}), null, DataProtectionScope.CurrentUser)`
- Only the current Windows user can decrypt — by design
- On Sign Out: delete the file + call `/oauth/revoke`

### 5.4 Refresh

When an API call returns 401:

1. If `expires_at` shows token is expired, immediately try refresh
2. POST `/oauth/refresh` with `refresh_token`
3. On 200: save new tokens, retry original request
4. On error: clear tokens, prompt sign-in

### 5.5 Subscription enforcement (server-side authoritative)

Tier rules stored per **customer group** in the custom table `bimm_subscription_tier_config`:

```json
[
  { "id": "lite",      "name": "Lite",      "monthly_load_limit": 10,   "upgrade_url": "https://bimmodeller.com/pricing#essential" },
  { "id": "essential", "name": "Essential", "monthly_load_limit": 100,  "upgrade_url": "https://bimmodeller.com/pricing#pro" },
  { "id": "pro",       "name": "Pro",       "monthly_load_limit": null, "upgrade_url": null }
]
```

`null` monthly_load_limit = unlimited.

The `/family/{id}/download` handler (in `Controller/Webapi/Download.php`):

1. Validates bearer token → customer_id
2. Looks up `customer.group_id` → tier config row in `bimm_subscription_tier_config`
3. If `tier.monthly_load_limit !== null` AND `customer.bimm_quota_used_this_month >= tier.monthly_load_limit`:
   → returns `402 Payment Required` with body `{ "error": "quota_exceeded", "upgrade_url": tier.upgrade_url, "current_tier": tier.code }`
4. Else: increments `bimm_quota_used_this_month`, mints signed CDN URL, returns `200 { url, expires_at }`

Quota resets monthly via Magento cron job (`crontab.xml` schedule `0 0 1 * *`) that resets all customers' `bimm_quota_used_this_month` attribute to 0 on the first day of the month.

## 6. Deployment architecture

```
                          ┌─────────────────────────────────────────┐
                          │  bimmodeller.com (existing WP host)     │
                          │  - WordPress + bimm-connect             │
                          │  - Admin dashboard (built into WP page) │
                          │  - Subscription tier admin UI           │
                          └─────────────────────────────────────────┘
                                          ▲
                                          │ HTTPS
                                          │
                  ┌────────────────────────┼────────────────────────┐
                  │                                                 │
       ┌──────────┴──────────┐                       ┌──────────────┴──────────┐
       │  Architect machine  │                       │  Architect machine      │
       │  Windows 10/11      │                       │  Windows 10/11          │
       │  Revit 2027         │                       │  Revit 2024             │
       │  BIMModeller Plugin │                       │  BIMModeller Plugin     │
       └─────────────────────┘                       └─────────────────────────┘
```

**Installer distribution:**

- Signed MSI hosted at `https://bimmodeller.com/revit-plugin/BIMModeller.Setup.{version}.msi`
- Version manifest at `https://bimmodeller.com/wp-json/bimm/v1/version-manifest` returns `{ latest: "1.0.3", url: "...", changelog: "...", min_supported: "1.0.0" }`
- Squirrel.Windows polls the manifest on Revit startup; downloads delta + prompts user to restart Revit

**Logging:**

- Plugin → file (`%LOCALAPPDATA%\BIMModeller\logs\plugin-{date}.log`)
- bimm-connect → WP debug log (`wp-content/debug.log` when WP_DEBUG enabled, else syslog)
- Errors with PII stripping; never log tokens

## 7. Subscription enforcement model

| Decision | Where it lives | Why |
|---|---|---|
| Tier rules (names, limits, prices) | WP option (bimm-connect admin UI) | Editable by BIMModeller staff without code change |
| Per-user tier assignment | WP usermeta `bimm_subscription_tier` | Tied to existing WP user; sync from external billing (e.g., Stripe webhooks) if used |
| Enforcement decision | bimm-connect `/family/{id}/download` handler | Server is authoritative |
| Quota state | WP usermeta `bimm_quota_used_this_month` | Resets monthly via cron |
| Plugin UI for "over quota" | Plugin: 402 response handler renders upgrade modal | Plugin never enforces, only displays |
| Upgrade flow | Browser deep link to `bimmodeller.com/pricing#{tier}` | Out of plugin scope; client owns billing |

**Critical invariant: the plugin must NEVER make a download decision based on local state.** All decisions are made by the server response. The plugin is a thin UI for whatever the server returns.


---

# Part II — Design

## 8. Revit plugin (C#) detailed design

### 8.1 Solution structure

```
bimmodeller-revit-plugin/
├── BIMModeller.sln
├── src/
│   ├── BIMModeller.Core/           # Pure C#, no Revit refs, multi-target
│   │   ├── Api/
│   │   │   ├── BimmApiClient.cs          # HTTP client with auth
│   │   │   ├── IBimmApiClient.cs
│   │   │   └── Models/                   # DTOs matching REST contract
│   │   ├── Auth/
│   │   │   ├── OAuthClient.cs            # Authz Code + PKCE
│   │   │   ├── ITokenStore.cs
│   │   │   ├── DpapiTokenStore.cs        # Windows-only impl
│   │   │   └── PkceHelper.cs
│   │   ├── Services/
│   │   │   ├── CatalogService.cs         # search, browse, detail
│   │   │   ├── DownloadService.cs        # download .rfa with 402 handling
│   │   │   └── TelemetryService.cs
│   │   ├── Settings/
│   │   │   └── AppSettings.cs            # endpoints, client_id, ports
│   │   └── BIMModeller.Core.csproj       # netstandard2.0
│   │
│   ├── BIMModeller.UI/             # WPF only, references Core
│   │   ├── Views/
│   │   │   ├── MainWindow.xaml           # The separate window
│   │   │   ├── SidebarView.xaml          # Projects/Discover/Libraries tabs
│   │   │   ├── DiscoverView.xaml         # Categories grid + filters
│   │   │   ├── ProductDetailView.xaml
│   │   │   ├── SignInView.xaml
│   │   │   └── UpgradePromptView.xaml
│   │   ├── ViewModels/
│   │   │   ├── MainViewModel.cs
│   │   │   ├── DiscoverViewModel.cs
│   │   │   ├── ProductDetailViewModel.cs
│   │   │   └── ...
│   │   ├── Controls/                     # Reusable WPF controls
│   │   ├── Resources/                    # Brand colors, styles
│   │   └── BIMModeller.UI.csproj         # net48 + net8.0-windows + net10.0-windows
│   │
│   └── BIMModeller.App/            # Revit-API-dependent entry point
│       ├── BIMModellerApp.cs             # IExternalApplication
│       ├── Commands/
│       │   ├── OpenBrowserCommand.cs     # ribbon: opens MainWindow
│       │   ├── ReplaceFamilyCommand.cs
│       │   └── SignOutCommand.cs
│       ├── RevitServices/
│       │   ├── FamilyLoader.cs           # wraps Document.LoadFamily
│       │   ├── ViewOrchestrator.cs       # multi-view zoom-to-fit
│       │   └── ExternalEventHandler.cs   # marshalling to Revit's UI thread
│       ├── Resources/
│       │   ├── BIMModeller.addin         # Revit manifest
│       │   └── icons/                    # Ribbon icons
│       └── BIMModeller.App.csproj        # multi-target, references Revit assemblies
│
├── tests/
│   ├── BIMModeller.Core.Tests/           # xUnit; mocks for HttpClient
│   ├── BIMModeller.UI.Tests/             # MVVM viewmodel tests
│   └── BIMModeller.App.IntegrationTests/ # Headless Revit (where possible)
│
├── installer/
│   ├── BIMModeller.Setup.wxs             # WiX MSI definition
│   └── build-installer.ps1
│
├── .github/workflows/
│   ├── ci.yml                            # build all targets, run tests
│   └── release.yml                       # signs MSI, uploads to bimmodeller.com
│
└── BIMModeller.sln
```

### 8.2 Dependency injection composition

`BIMModellerApp.OnStartup` builds the service provider:

```csharp
var services = new ServiceCollection();
services.AddSingleton<AppSettings>(_ => AppSettings.LoadFromDefaults());
services.AddSingleton<ITokenStore, DpapiTokenStore>();
services.AddSingleton<OAuthClient>();
services.AddSingleton<IBimmApiClient, BimmApiClient>();
services.AddTransient<CatalogService>();
services.AddTransient<DownloadService>();
services.AddTransient<TelemetryService>();
services.AddTransient<MainViewModel>();
services.AddTransient<DiscoverViewModel>();
// ... view models registered transient
ServiceProvider = services.BuildServiceProvider();
```

UI resolves view models via `App.ServiceProvider.GetRequiredService<MainViewModel>()`.

### 8.3 Threading model

| Operation | Thread |
|---|---|
| HTTP calls | Background (async/await) |
| OAuth localhost listener | Background; receives callback on threadpool |
| Token persistence (DPAPI) | Background, but cheap |
| WPF UI updates | UI thread (use `Dispatcher.InvokeAsync`) |
| Revit API calls (LoadFamily, etc.) | Revit's UI thread — must use `ExternalEvent`/`IExternalEventHandler` pattern |

**Critical:** never call Revit API from a non-Revit thread. The `FamilyLoader` service queues work into an `ExternalEventHandler` that Revit invokes on its own thread.

### 8.4 MainWindow lifecycle

- Created by `OpenBrowserCommand` when the user clicks the ribbon button
- Singleton per Revit session (re-shown if already open)
- Non-modal (`Show()`, not `ShowDialog()`)
- Window remembers size/position via `BIMModeller.UI` user-settings file
- Closing the window does NOT sign out

### 8.5 Sign-in / sign-out UX

| State | UI shown |
|---|---|
| App start, no token in DPAPI | `SignInView` with "Sign in with BIMModeller" button |
| Sign in flow in progress | "Waiting for browser…" with Cancel button |
| Signed in, normal | `SidebarView` + `DiscoverView` |
| 402 on download | `UpgradePromptView` modal over `ProductDetailView` |
| 401 + refresh fails | `SignInView` again |
| Sign out clicked | Revoke token; clear DPAPI; back to `SignInView` |

## 9. Magento module (PHP) detailed design

### 9.1 Module structure

```
bimm-connect/                                # Repo root, installed at app/code/BIMM/Connect/
├── composer.json
├── registration.php                         # Magento module registration
├── etc/
│   ├── module.xml                          # Declares BIMM_Connect, version, dependencies
│   ├── di.xml                              # Dependency injection bindings
│   ├── acl.xml                             # Admin permissions
│   ├── webapi.xml                          # REST routes under /rest/V1/bimm
│   ├── events.xml                          # Event observers (including RegistrationBridge)
│   ├── crontab.xml                         # Cron jobs (monthly quota reset)
│   ├── db_schema.xml                       # Custom tables (bimm_event, bimm_oauth_token, etc.)
│   ├── db_schema_whitelist.json            # Required for Magento DB schema validation
│   └── adminhtml/
│       ├── routes.xml
│       ├── menu.xml                        # Admin menu entry: "BIMModeller → Plugin Insights"
│       └── system.xml                      # Tier config settings (Stores → Configuration)
├── Setup/
│   ├── Patch/Data/
│   │   ├── CreateBimmTiersGroups.php       # Creates Lite/Essential/Pro customer groups
│   │   ├── AddBimmProductAttributes.php    # Adds bimm_manufacturer, etc. to catalog_product
│   │   └── AddBimmCustomerAttributes.php   # Adds bimm_quota_used_this_month to customers
│   └── Patch/Schema/
│       └── (none — db_schema.xml is declarative)
├── Api/
│   ├── ProductsRepositoryInterface.php
│   ├── CategoriesRepositoryInterface.php
│   ├── DownloadServiceInterface.php
│   ├── EventsServiceInterface.php
│   ├── MeServiceInterface.php
│   └── OAuth/
│       ├── AuthorizationServiceInterface.php
│       └── TokenServiceInterface.php
├── Model/
│   ├── Repository/
│   │   ├── ProductsRepository.php
│   │   ├── CategoriesRepository.php
│   │   └── EventsRepository.php
│   ├── OAuth/
│   │   ├── AuthorizationService.php       # PKCE + Magento OAuth wrapper
│   │   ├── TokenService.php
│   │   ├── PkceVerifier.php
│   │   └── ResourceModel/OAuthToken.php
│   ├── Subscription/
│   │   ├── TierService.php
│   │   ├── QuotaEnforcer.php
│   │   └── MonthlyResetJob.php             # Cron callable
│   └── Event/
│       └── EventIngestor.php
├── Controller/
│   └── Adminhtml/
│       └── Insights/
│           └── Index.php                   # Serves admin dashboard React bundle
├── Observer/
│   └── RegistrationBridge.php              # customer_register_success event observer
├── Block/
│   └── Adminhtml/
│       └── Insights/
│           └── Dashboard.php               # Magento block that loads the React bundle
├── view/
│   └── adminhtml/
│       ├── layout/
│       │   └── bimm_insights_index.xml
│       ├── templates/
│       │   └── dashboard.phtml             # Mounts <div id="bimm-admin-dashboard"></div>
│       └── web/
│           └── js/
│               └── bimm-dashboard.bundle.js  # Output from bimm-admin-dashboard build
├── Test/
│   ├── Unit/                                # PHPUnit tests
│   └── Integration/                         # Magento integration tests
├── phpstan.neon
└── README.md
```

### 9.2 Bootstrap

Magento auto-discovers the module via `registration.php`:

```php
<?php
\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'BIMM_Connect',
    __DIR__
);
```

`etc/module.xml` declares dependencies (Magento_Customer, Magento_Catalog, Magento_Webapi, Magento_Integration). REST routes are declared in `etc/webapi.xml`; Magento's WebAPI framework auto-routes them. Admin menu in `etc/adminhtml/menu.xml`. Cron jobs in `etc/crontab.xml`. No hand-coded boot function — Magento's DI container does all the wiring.

To install:

```bash
composer require bimmodeller/bimm-connect
bin/magento module:enable BIMM_Connect
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean
```

### 9.3 OAuth endpoints

| Route | Method | Handler | Purpose |
|---|---|---|---|
| `/rest/V1/bimm/oauth/authorize` | GET | `AuthorizationService::authorize` | Shows authorize-this-app screen (uses Magento customer session); on POST issues code |
| `/rest/V1/bimm/oauth/token` | POST | `AuthorizationService::exchange` | Code → access_token + refresh_token (validated via PKCE) |
| `/rest/V1/bimm/oauth/refresh` | POST | `AuthorizationService::refresh` | refresh_token → new access_token |
| `/rest/V1/bimm/oauth/revoke` | POST | `AuthorizationService::revoke` | Mark token as revoked |

Authorization codes stored in Magento cache (file or Redis) with 10-minute TTL via `Magento\Framework\App\CacheInterface`. Access tokens stored as rows in `bimm_oauth_token` (hash only, never plaintext).

### 9.4 Catalog endpoints

| Route | Method | Auth | Returns |
|---|---|---|---|
| `/rest/V1/bimm/products` | GET | Bearer | Paginated list of products (drawn from `catalog_product`) |
| `/rest/V1/bimm/products/{id}` | GET | Bearer | Product detail |
| `/rest/V1/bimm/categories` | GET | Bearer | Category tree (drawn from `catalog_category`) with counts |
| `/rest/V1/bimm/search` | GET | Bearer | Search results (uses Magento search index) |
| `/rest/V1/bimm/family/{id}/download` | GET | Bearer | 200 + signed URL OR 402 |
| `/rest/V1/bimm/events` | POST | Bearer | Telemetry ingestion |
| `/rest/V1/bimm/me` | GET | Bearer | Current customer + group (subscription) |
| `/rest/V1/bimm/version-manifest` | GET | Public | Latest plugin version |

### 9.5 Subscription enforcement code path

`DownloadService::download($familyId)`:

1. Authenticate: Magento's WebAPI framework injects the customer via bearer token (Magento native).
2. Resolve `customer.group_id` → tier config row in `bimm_subscription_tier_config`.
3. `QuotaEnforcer::canLoad($customer, $tier)`:
   - If `tier.monthly_load_limit === null` → return `true` (unlimited)
   - If `customer.bimm_quota_used_this_month >= tier.monthly_load_limit` → return `false`
   - Else → `true`
4. If `false`: throw `LocalizedException` with 402 mapping in `webapi.xml`; body: `{"error": "quota_exceeded", "upgrade_url": ..., "current_tier": ...}`.
5. If `true`:
   - Increment customer attribute atomically via `CustomerRepositoryInterface::save` with version check
   - Generate signed CDN URL (15-min TTL) via configured CDN service
   - Insert row into `bimm_event` via `EventIngestor`
   - Return `{"url": ..., "expires_at": ...}` (Magento serializes to JSON)

## 10. Admin dashboard (React) detailed design

### 10.1 Page tree

```
/admin/plugin-insights/
  /                    Overview (KPIs + daily-loads chart)
  /users/              User list with tier filter
  /users/:id           User detail (their loads, events)
  /families/           Most-loaded families
  /gaps/               Zero-result searches
  /tiers/              Tier configuration (delegates to WP admin)
  /settings/           OAuth client config, retention policy
```

### 10.2 Routing

```tsx
<BrowserRouter basename="/admin/plugin-insights">
  <Routes>
    <Route path="/" element={<Overview />} />
    <Route path="/users" element={<UserList />} />
    <Route path="/users/:id" element={<UserDetail />} />
    <Route path="/families" element={<TopFamilies />} />
    <Route path="/gaps" element={<DemandGaps />} />
    <Route path="/tiers" element={<TierConfig />} />
    <Route path="/settings" element={<Settings />} />
  </Routes>
</BrowserRouter>
```

### 10.3 Auth

Admin already signed in via Magento admin login. Bundle is served only to Magento admin users with ACL resource `BIMM_Connect::insights` (declared in `etc/acl.xml`; enforced by `Controller/Adminhtml/Insights/Index.php::_isAllowed()`). API calls include Magento's admin form key for CSRF protection.

### 10.4 State and data fetching

- TanStack Query for all server reads (`useQuery(['products', filters], fetcher)`)
- Zustand for UI state (current filter, selected user)
- React Query handles cache, loading, error states

## 11. REST API contract

### 11.1 Common conventions

- Base URL: `https://bimmodeller.com/rest/V1/bimm`
- Auth: `Authorization: Bearer {access_token}` on every authenticated route
- Errors return JSON: `{ "code": "<machine-code>", "message": "Human readable", "data": {...} }`
- HTTP status codes used: 200, 201, 204, 400, 401, 402, 403, 404, 429, 500
- Rate limit: 60 req/min per token, 429 with `Retry-After` header
- Pagination: `?page=1&per_page=20`; response includes `X-Total` header

### 11.2 Endpoint specifications

**`GET /products`**

Query parameters: `category` (slug), `manufacturer`, `compliance` (tag), `page`, `per_page` (max 100), `sort` (name|popularity|recent)

Response 200:

```json
{
  "items": [
    {
      "id": 412,
      "name": "Manual Fire Pull Station MPS-100",
      "slug": "manual-fire-pull-station-mps-100",
      "manufacturer": "Honeywell",
      "category": "fire-alarm-devices",
      "thumbnail_url": "https://cdn.bimmodeller.com/thumbs/mps-100.jpg",
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

**`GET /products/{id}`**

Response 200: full product detail (everything above plus `description`, `specs` object, `downloads` array).

**`GET /categories`**

Response 200:

```json
{
  "categories": [
    {
      "slug": "fire-alarm-devices",
      "name": "Fire Alarm Devices",
      "icon": "🚨",
      "count": 412,
      "parent": null
    }
  ]
}
```

**`GET /search?q=fire+pull`**

Response 200: same shape as `/products`, with results scored by relevance.

**`GET /family/{id}/download`**

Response 200:

```json
{
  "url": "https://cdn.bimmodeller.com/rfa/mps-100.rfa?sig=...&exp=1715600000",
  "expires_at": "2026-05-13T15:30:00Z",
  "file_size_bytes": 1438209,
  "sha256": "abc123..."
}
```

Response 402:

```json
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

**`POST /events`**

Request body:

```json
{
  "events": [
    {
      "event_type": "family_loaded",
      "occurred_at": "2026-05-12T10:14:32Z",
      "install_id": "uuid-...",
      "payload": { "family_id": 412, "revit_version": "2027" }
    }
  ]
}
```

Response 204.

Allowed event_types: `search_performed`, `category_browsed`, `product_viewed`, `family_loaded`, `family_replaced`, `error_occurred`, `signin_completed`, `signout_completed`.

**`GET /me`**

Response 200:

```json
{
  "user_id": 91,
  "email": "ben@example.com",
  "name": "Ben O'Donnell",
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

**`GET /version-manifest`** (public, no auth):

```json
{
  "latest": "1.0.3",
  "url": "https://bimmodeller.com/revit-plugin/BIMModeller.Setup.1.0.3.msi",
  "sha256": "...",
  "released_at": "2026-05-09T12:00:00Z",
  "changelog": "Fixes Replace command on Revit 2025.",
  "min_supported": "1.0.0"
}
```

## 12. OAuth flow — exact steps

See sequence diagram in §5.2. The exact code paths:

| Step | Plugin code | bimm-connect code |
|---|---|---|
| Generate PKCE verifier | `PkceHelper.NewVerifier()` returns 64-char base64url | — |
| Compute challenge | `PkceHelper.Challenge(verifier)` = SHA256 + base64url | — |
| Find free port | `OAuthClient.FindFreePort()` tries 47620-47629 | — |
| Start listener | `HttpListener` on `http://localhost:{port}/callback` | — |
| Open browser | `Process.Start(authorize_url)` | — |
| Show authorize UI | — | `OAuthController::authorize` (must be WP-signed-in) |
| Validate code_challenge | — | Stored in transient with the code |
| Issue code | — | `TokenService::issueCode($user_id, $challenge, $redirect_uri)` |
| Redirect to localhost | — | `wp_redirect("$redirect_uri?code=$code&state=$state")` |
| Receive callback | `HttpListener.GetContext()` → parse `code`, `state` | — |
| Exchange for token | `OAuthClient.ExchangeCode(code, verifier)` POSTs to `/oauth/token` | `OAuthController::exchange` validates verifier matches challenge |
| Persist tokens | `DpapiTokenStore.Save(tokens)` | `TokenRepository::store($user_id, $hashed_token)` |
| Close listener | `HttpListener.Stop()` | — |
| Show signed-in UI | `MainViewModel.OnSignedIn()` | — |

## 13. Telemetry schema

Table `bimm_event` declared in `etc/db_schema.xml`:

```xml
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
```

Event payload schemas (JSON):

| event_type | payload |
|---|---|
| `search_performed` | `{ query, result_count, response_time_ms }` |
| `category_browsed` | `{ category_slug }` |
| `product_viewed` | `{ family_id, manufacturer }` |
| `family_loaded` | `{ family_id, manufacturer, time_to_load_ms }` |
| `family_replaced` | `{ from_family_id, to_family_id }` |
| `error_occurred` | `{ where, message, stack_redacted }` |
| `signin_completed` | `{ provider: "wordpress" }` |
| `signout_completed` | `{}` |
| `quota_exceeded_seen` | `{ current_tier, monthly_limit }` |

Retention: 13 months rolling. Magento cron (`crontab.xml` daily) deletes rows older than 400 days.

## 14. Error handling

### 14.1 Plugin error envelope (in-process)

All service methods return `Result<T>` where `T` is the success payload:

```csharp
public class Result<T>
{
    public bool IsSuccess { get; }
    public T? Value { get; }
    public BimmError? Error { get; }
    public static Result<T> Ok(T value);
    public static Result<T> Fail(BimmError error);
}

public record BimmError(string Code, string Message, int? HttpStatus, object? Data);
```

ViewModels handle errors by switching on `Error.Code`:

| Code | UI response |
|---|---|
| `network_unavailable` | Toast: "Check your internet connection" |
| `unauthorized` | Trigger silent token refresh, then retry once |
| `quota_exceeded` | Show `UpgradePromptView` modal |
| `rate_limited` | Toast: "Too many requests; retrying in {retry_after}s" |
| `server_error` | Toast: "Something went wrong; we've logged it" |
| any other | Toast with the message |

### 14.2 Retry policy

```csharp
HttpPolicy.WaitAndRetryAsync(
    retryCount: 3,
    sleepDuration: attempt => TimeSpan.FromSeconds(Math.Pow(2, attempt)),
    onlyOnTransientFailures: true)
```

Only retry on 502/503/504 and timeouts. Never retry 4xx (those are deliberate server decisions).

### 14.3 Server error envelope

All bimm-connect responses use:

```json
{ "code": "string", "message": "string", "data": {} }
```

PHP exception → 500 with `{ code: "server_error", message: "Generic server error" }`. Detailed exception goes to WP debug log.


---

# Part III — Implementation

## 15. Repository setup checklist

### 15.1 bimmodeller-revit-plugin

```bash
# Day 1 setup
mkdir bimmodeller-revit-plugin && cd $_
git init
dotnet new sln -n BIMModeller

dotnet new classlib -n BIMModeller.Core   -o src/BIMModeller.Core -f netstandard2.0
dotnet new wpflib   -n BIMModeller.UI     -o src/BIMModeller.UI
dotnet new classlib -n BIMModeller.App    -o src/BIMModeller.App

dotnet sln add src/BIMModeller.Core/BIMModeller.Core.csproj
dotnet sln add src/BIMModeller.UI/BIMModeller.UI.csproj
dotnet sln add src/BIMModeller.App/BIMModeller.App.csproj

dotnet new xunit -n BIMModeller.Core.Tests -o tests/BIMModeller.Core.Tests
dotnet new xunit -n BIMModeller.UI.Tests   -o tests/BIMModeller.UI.Tests
dotnet sln add tests/BIMModeller.Core.Tests/BIMModeller.Core.Tests.csproj
dotnet sln add tests/BIMModeller.UI.Tests/BIMModeller.UI.Tests.csproj

# Add packages
dotnet add src/BIMModeller.Core package System.Text.Json
dotnet add src/BIMModeller.Core package Polly
dotnet add src/BIMModeller.Core package System.Security.Cryptography.ProtectedData --version 8.0.0
dotnet add src/BIMModeller.UI   package CommunityToolkit.Mvvm
dotnet add src/BIMModeller.UI   package Microsoft.Extensions.DependencyInjection
dotnet add src/BIMModeller.App  package Squirrel.Windows
```

Multi-target the UI and App csproj files manually:

```xml
<TargetFrameworks>net48;net8.0-windows;net10.0-windows</TargetFrameworks>
<UseWPF>true</UseWPF>
```

Revit API references: add `<Reference>` to `RevitAPI.dll` and `RevitAPIUI.dll` using `<HintPath>` conditional on target framework (different Revit version per TFM).

### 15.2 bimm-connect (Magento 2 module)

```bash
# Create the repo
mkdir bimm-connect && cd $_
git init

# Bootstrap composer
composer init --name=bimmodeller/bimm-connect --type=magento2-module --no-interaction
composer require magento/framework:"^103.0" magento/module-customer:"*" magento/module-catalog:"*"
composer require --dev phpunit/phpunit phpstan/phpstan magento/magento-coding-standard

# Module skeleton
mkdir -p etc Setup/Patch/Data Api Model/{Repository,OAuth,Subscription,Event} \
         Controller/Adminhtml/Insights Observer Block/Adminhtml/Insights \
         view/adminhtml/{layout,templates,web/js} Test/{Unit,Integration}

# registration.php
cat > registration.php <<'PHP'
<?php
\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'BIMM_Connect',
    __DIR__
);
PHP

# etc/module.xml
cat > etc/module.xml <<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Module/etc/module.xsd">
    <module name="BIMM_Connect" setup_version="1.0.0">
        <sequence>
            <module name="Magento_Customer"/>
            <module name="Magento_Catalog"/>
            <module name="Magento_Webapi"/>
            <module name="Magento_Integration"/>
        </sequence>
    </module>
</config>
XML
```

To install on the dev Magento instance:

```bash
# From bimmodeller.com Magento root:
composer require bimmodeller/bimm-connect
bin/magento module:enable BIMM_Connect
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### 15.3 bimm-admin-dashboard

```bash
npm create vite@latest bimm-admin-dashboard -- --template react-ts
cd bimm-admin-dashboard
npm install
npm install @tanstack/react-query zustand react-router-dom
npm install chart.js react-chartjs-2
npm install -D tailwindcss postcss autoprefixer
npx tailwindcss init -p
```

Build target: bundle output goes into `bimm-connect/assets/admin-dashboard.bundle.js` (configure via Vite's `build.outDir`).

## 16. Implementation order (critical path)

Build in this order. Each step has acceptance criteria; do not move on until the previous step's tests pass.

| Order | Module | Why this position | Acceptance |
|---|---|---|---|
| 1 | bimm-connect skeleton with `/health` endpoint | Unblocks everything else | `curl /wp-json/bimm/v1/health` returns 200 on staging |
| 2 | bimm-connect: products, categories, search (PUBLIC, no auth yet) | Plugin can be built against real data | `curl /wp-json/bimm/v1/products` returns 20 items |
| 3 | Plugin: BIMModeller.Core API client + Plugin loads from REST | Validates HTTP layer end-to-end | Plugin shows categories in WPF window |
| 4 | Plugin: BIMModeller.App ribbon button + MainWindow | First visible deliverable | Click ribbon → window opens |
| 5 | Plugin: Discover view with categories grid | Marketplace UI matches mockup | Categories render, clickable |
| 6 | Plugin: Search + Product Detail | UI completeness | End-to-end browse to product page |
| 7 | bimm-connect: /family/{id}/download (no quota yet — return signed URL) | Unblocks Load command | curl returns signed URL |
| 8 | Plugin: Load + Place + multi-view orchestration | Core workflow | Family loads in Revit, both views zoom |
| 9 | Plugin: Replace command | Required by client | Right-click instance → swap works |
| 10 | bimm-connect: telemetry endpoint + event ingestion | Need data flowing | Events table has rows |
| 11 | Admin dashboard skeleton (Overview KPIs) | First admin deliverable | KPIs render against live data |
| 12 | bimm-connect: OAuth (authorize, token, refresh, revoke) | Unblocks plugin auth | Manual curl flow completes |
| 13 | Plugin: SignInView + OAuth client + token store | Plugin can sign in | End-to-end browser flow works |
| 14 | bimm-connect: /me endpoint + subscription tier in WP admin | Tier model wired | curl /me shows tier |
| 15 | bimm-connect: quota enforcement on /download | Subscription works | Hit limit → 402 |
| 16 | Plugin: 402 handler + UpgradePromptView | UX for quota | Modal renders correctly |
| 17 | Plugin: error states, loading states, retry policy | Polish | Network drop recovers gracefully |
| 18 | Admin dashboard: per-user, top families, demand gaps | Final dashboard | Pages render |
| 19 | Installer: WiX MSI + signing + auto-update channel | Delivery pipeline | Signed MSI installs on clean Windows |
| 20 | Documentation: user guide, admin guide, FAQ, 2 videos | GA requirement | All four artefacts published |

## 17. Build pipeline (GitHub Actions)

### 17.1 bimmodeller-revit-plugin/.github/workflows/ci.yml

```yaml
name: CI
on: [push, pull_request]
jobs:
  build:
    runs-on: windows-latest
    strategy:
      matrix:
        tfm: [net48, net8.0-windows, net10.0-windows]
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-dotnet@v4
        with: { dotnet-version: '10.0.x' }
      - run: dotnet restore
      - run: dotnet build --framework ${{ matrix.tfm }} --no-restore -c Release
      - run: dotnet test  --framework ${{ matrix.tfm }} --no-build -c Release --filter "Category!=RevitIntegration"
```

### 17.2 release.yml (on tag push)

```yaml
name: Release
on:
  push: { tags: ['v*.*.*'] }
jobs:
  release:
    runs-on: windows-latest
    steps:
      - uses: actions/checkout@v4
      - run: dotnet publish src/BIMModeller.App -c Release -f net10.0-windows -o publish/
      - run: ./installer/build-installer.ps1 -Version ${{ github.ref_name }}
      - name: Sign MSI
        env:
          CERT_BASE64: ${{ secrets.CODE_SIGNING_CERT }}
          CERT_PASSWORD: ${{ secrets.CODE_SIGNING_PASSWORD }}
        run: |
          $cert = [Convert]::FromBase64String($env:CERT_BASE64)
          [IO.File]::WriteAllBytes("cert.pfx", $cert)
          & signtool sign /f cert.pfx /p $env:CERT_PASSWORD /tr http://timestamp.digicert.com /td sha256 /fd sha256 installer/BIMModeller.Setup.${{ github.ref_name }}.msi
      - name: Upload to bimmodeller.com
        run: |
          # SFTP or S3 upload to bimmodeller.com/revit-plugin/
          # Implementation depends on hosting choice
```

### 17.3 bimm-connect/.github/workflows/ci.yml

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
    steps:
      - uses: actions/checkout@v4
        with: { path: bimm-connect }
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.2', extensions: bcmath,intl,gd,xsl,soap }
      - name: Install Magento with module
        run: |
          composer create-project --repository=https://repo.magento.com magento/project-community-edition magento "2.4.7"
          mv bimm-connect magento/app/code/BIMM/Connect
          cd magento && bin/magento setup:install --base-url=http://localhost \
            --db-host=127.0.0.1 --db-name=magento_test --db-user=root --db-password=magento \
            --admin-firstname=admin --admin-lastname=admin --admin-email=admin@test.local \
            --admin-user=admin --admin-password=admin1234 --use-rewrites=1
          bin/magento module:enable BIMM_Connect
          bin/magento setup:upgrade
      - run: cd magento && ./vendor/bin/phpstan analyse app/code/BIMM/Connect --level=5
      - run: cd magento && ./vendor/bin/phpunit -c app/code/BIMM/Connect/Test/phpunit.xml
```

## 18. Testing strategy

### 18.1 Unit tests

| Component | Library | Coverage target |
|---|---|---|
| BIMModeller.Core | xUnit + Moq | 80% of services and models |
| BIMModeller.UI viewmodels | xUnit + WPF-free mocks | 70% |
| bimm-connect classes | PHPUnit | 80% of services and controllers |

Examples of test categories:

- **OAuth happy path** — PKCE verifier matches challenge, token issued
- **OAuth failure cases** — wrong verifier, expired code, revoked token, missing state
- **Subscription enforcement** — tier=lite at 9/10 loads → 200; at 10/10 → 402; tier=pro → always 200
- **Quota reset cron** — month rollover resets all users' quota_used to 0
- **402 handler** — plugin renders UpgradePromptView with the upgrade_url from response

### 18.2 Integration tests

| Scenario | How |
|---|---|
| Full OAuth round-trip | Run bimm-connect locally; plugin OAuth flow; assert token persisted |
| Family load end-to-end | Run bimm-connect locally; download fixture .rfa; verify file integrity |
| 402 quota path | Seed user at quota limit; attempt download; assert 402 + correct payload |
| Telemetry pipeline | Plugin emits events; query /events admin endpoint; verify row count |

### 18.3 End-to-end (manual + Antigravity scripted)

Antigravity test scenarios (each scenario = one Antigravity script):

| Scenario | Steps | Pass criteria |
|---|---|---|
| Cold install + first load | Install MSI → start Revit 2027 → click ribbon → sign in → browse → load family → see family in 3D + plan | All 6 steps complete in < 120s |
| Sign out + sign in again | Sign out → close Revit → reopen → click ribbon → sign in screen shown → sign in → previous session restored | No errors; user state correct |
| Quota exceeded | Seed test user as Lite/9-of-10; load a family (succeeds, 10/10); load another family (402, upgrade modal shown) | Modal renders with correct tier and upgrade URL |
| Token refresh | Wait for access token to expire; perform action; observe silent refresh; action succeeds | One 401 silently retried; success on retry |
| Network loss during Load | Disconnect Wi-Fi mid-download; verify error toast; reconnect; retry succeeds | No corrupted state |
| Multi-version compat | Repeat all of the above on Revit 2024, 2025, 2026 if Multi-Version QA add-on sold | All scenarios pass on each version |

### 18.4 Performance budgets (must be enforced in CI for Plugin)

| Metric | Budget |
|---|---|
| Plugin cold start (open window first time) | < 800 ms |
| Plugin warm start (re-open window) | < 200 ms |
| Search response | < 500 ms p95 |
| Load family end-to-end | < 3 s on broadband |
| Memory: plugin idle | < 80 MB |
| Memory: plugin with 1000 product cards loaded | < 200 MB |

## 19. AI agent guidance

### 19.1 For the implementer (Claude Code)

**Module prompts.** When asking Claude Code to implement a module, the prompt should reference this document and specify:

1. Section number(s) that contain the contract
2. Acceptance criteria (from §20)
3. Files in scope (under which directory)
4. Tests it must produce

Example prompt:

> Implement `BIMModeller.Core.Auth.OAuthClient` per §5 (OAuth flow), §8.5 (Sign-in UX), and §12 (exact steps). Files: `src/BIMModeller.Core/Auth/`. Write xUnit tests covering happy path, expired code, mismatched verifier, missing state. Acceptance: see §20.3.

**Always prefer the simpler implementation.** This document specifies behavior, not style. When choosing between two architecturally-equivalent approaches, pick the one with fewer abstractions.

**Don't introduce new third-party dependencies** without flagging them in a PR comment. Stack is fixed in §3.

**Threading rule** (§8.3): never call Revit API from a non-Revit thread. Use `ExternalEvent` pattern.

**Token rule** (§5.3): never log tokens; never write them to disk except via `DpapiTokenStore`.

**Server-side rule** (§5.5): plugin never enforces subscription rules. If you find yourself writing `if (loadCount > limit)` in C#, you are wrong.

### 19.2 For the reviewer (Codex)

**Review checklist** (run on every PR):

- [ ] Does the change conform to the architecture in Part I? Flag any architectural drift.
- [ ] Does any new dependency appear in csproj/composer.json/package.json that isn't in §3? Flag.
- [ ] Does any code path enforce business rules client-side (violating §7)? Flag.
- [ ] Are tokens, refresh tokens, or PKCE verifiers logged? Flag.
- [ ] Are Revit API calls made on non-Revit threads? Flag.
- [ ] Do new endpoints follow the response envelope (§14.3)? Flag if not.
- [ ] Does new code have at least one unit test? Flag if no.
- [ ] Are performance budgets (§18.4) at risk? Flag.

**For each flagged issue, point to the section of this document that defines the rule.**

### 19.3 For the tester (Antigravity)

**Test scenario authoring.** Each scenario in §18.3 maps to one Antigravity scenario. The scenario script should:

1. Set up fixtures (seed user in bimm-connect; install plugin from latest MSI)
2. Drive the UI (click, type, wait)
3. Assert observable outcomes (screenshot diff, log content, DB state)
4. Tear down (revoke tokens, delete test user)

**Test data:** use seeded test users matching tier patterns in §5.5. Each Antigravity run starts with a clean WP staging snapshot.

**Failure reporting:** when a scenario fails, attach (a) plugin log file, (b) network HAR if available, (c) screenshot, (d) bimm-connect WP debug log entries within the time window.

## 20. Acceptance criteria

### 20.1 Plugin opens

- Plugin installs from MSI with no admin elevation prompt
- BIMModeller tab appears in Revit ribbon after restart
- Clicking ribbon opens a WPF window (~1900×1000) over Revit
- Window remembers its size/position across sessions

### 20.2 Sign-in

- First open shows SignInView, not Discover
- "Sign in with BIMModeller" opens default browser to bimmodeller.com
- After signing in on bimmodeller.com, the browser closes (or shows a confirmation) and plugin shows Discover
- Token persists across Revit restarts
- Sign out clears the token and shows SignInView again

### 20.3 OAuth correctness

- PKCE code_verifier is 43-128 base64url chars
- code_challenge is SHA256(verifier) base64url
- state is anti-CSRF random; verified on callback
- Wrong verifier → token endpoint returns 400
- Expired code (>10 min) → token endpoint returns 400
- Revoked token → API returns 401
- Refresh token can be used once; second use returns 400

### 20.4 Catalog and search

- Discover shows ≥ 20 categories
- Each category shows a count and an icon
- Clicking a category drills into a filtered product list
- Search returns results in < 500 ms p95 on 3,874-product catalog
- Filter chips toggle and update results
- Product detail page renders all spec fields and downloadable file links

### 20.5 Load

- Click Load → spinner → family imported into Revit
- Family is placed at the cursor (or user picks placement)
- After placement, both 3D view and plan view are open
- Both views zoom to the new instance
- Event `family_loaded` is logged in telemetry

### 20.6 Replace

- Right-click a family instance in Revit → Replace with…
- Plugin shows the Discover view filtered to compatible families
- Selecting a replacement preserves location and rotation
- Telemetry event `family_replaced` is logged

### 20.7 Subscription gating

- /me returns user's tier and quota usage
- Plugin shows current tier and remaining quota in sidebar footer
- Load when over quota → 402 → UpgradePromptView modal
- Modal shows correct tier name, limit, and upgrade URL
- "Upgrade" button opens the upgrade URL in default browser
- "Cancel" closes the modal; window returns to product detail

### 20.8 Telemetry

- Events emitted on: search, browse, view, load, replace, error, sign-in, sign-out, quota-exceeded
- Each event arrives at /events within 30 seconds
- Admin dashboard's Overview KPIs update within 30 seconds of event ingestion

### 20.9 Installer + auto-update

- Signed MSI installs in < 60 seconds on clean Windows 11
- Auto-update channel returns 200 with current version
- A newer version triggers Squirrel update prompt
- Update + restart preserves user's sign-in state (token stays in DPAPI)

## 21. Security checklist

| Item | Where enforced |
|---|---|
| HTTPS only — reject http:// API endpoints | Plugin `BimmApiClient` constructor |
| Token storage encrypted with DPAPI per-user | `DpapiTokenStore` |
| Tokens never written to logs | `Serilog` filter that redacts header values |
| PKCE required on /oauth/token | `OAuthController::exchange` rejects requests without code_verifier |
| Authorization codes single-use (deleted after exchange) | `TokenService::exchangeCode` |
| Refresh tokens rotated on use (old one revoked) | `OAuthController::refresh` |
| Bearer tokens stored as hash, not plaintext | `TokenRepository::store` |
| Rate limiting on /oauth/token (5 req/min per IP) | `OAuthController` middleware |
| CSRF protection on admin dashboard | `X-WP-Nonce` on all admin API calls |
| WP admin pages require `manage_options` capability | `DashboardPage::register` |
| .rfa download URLs signed and time-limited (15 min) | `DownloadController::handle` |
| No SQL injection via WP REST API params | All queries use `$wpdb->prepare` |
| No XSS in admin dashboard | React auto-escapes; never use `dangerouslySetInnerHTML` |
| Dependencies scanned (Dependabot) | GitHub config in all 3 repos |
| Code-signing certificate stored as GH Actions secret | Repo secret `CODE_SIGNING_CERT` |
| Telemetry events PII-stripped | Plugin removes email/name from `error_occurred` payloads |

---

## 22. Dummy / dev environment and the swap to production

### 22.1 Why this exists

Engineering must not block on BIMModeller granting staging access. We build a self-contained dev environment that mirrors what bimmodeller.com will host (a **Magento 2** instance running the `BIMM_Connect` module) and develop the plugin entirely against it. When real access arrives, the swap is a configuration change — no plugin code changes.

This is also a best practice independent of access timing: every developer has the full stack locally, CI runs end-to-end tests inside Docker, and onboarding a new engineer takes about 10 minutes.

### 22.2 What the dev environment contains

```
bimm-dev-environment/
├── docker-compose.yml       # WordPress + MySQL + (optional) MinIO for CDN
├── Dockerfile.wp            # WP image with bimm-connect pre-mounted
├── plugins/
│   └── bimm-connect -> ../../bimm-connect (symlink to the actual repo)
├── seed/
│   ├── products.json        # 3,874 products from our snapshot crawler
│   ├── categories.json
│   └── rfa-samples/         # placeholder .rfa files (zero-byte or small real)
├── scripts/
│   ├── seed-data.sh         # WP-CLI commands to insert products/categories
│   ├── seed-test-users.sh   # creates lite-user, essential-user, pro-user
│   ├── configure-oauth.sh   # registers OAuth client + redirect URIs
│   └── parity-check.sh      # diffs dev WP against real bimmodeller staging
├── .env.example             # localhost URLs, ports, demo credentials
└── README.md                # 10-minute onboarding
```

### 22.3 One-command startup

```bash
git clone bimm-dev-environment
cd bimm-dev-environment
cp .env.example .env
docker compose up -d
# ~30 sec for WP to initialise
docker compose exec wordpress ./scripts/seed-data.sh
docker compose exec wordpress ./scripts/seed-test-users.sh
docker compose exec wordpress ./scripts/configure-oauth.sh
```

After this:

- WordPress at `http://dev.bimmodeller.local` (with `127.0.0.1 dev.bimmodeller.local` added to `/etc/hosts`)
- REST API at `http://dev.bimmodeller.local/wp-json/bimm/v1`
- WP admin at `http://dev.bimmodeller.local/wp-admin` (login: `admin` / `admin`)
- Plugin's `appsettings.json` points to this dev URL during development

### 22.4 Seed data sources

| What we seed | Source | How |
|---|---|---|
| 3,874 products | Our existing snapshot crawler output (`catalog-snapshot.json`) | Convert each entry to a `wp_insert_post` call via WP-CLI |
| Category taxonomy | Snapshot crawler `categories.json` | `wp term create` per category |
| Test users | Hardcoded in script | `wp user create` × 3 (one per tier) |
| Subscription tiers | Hardcoded JSON | `wp option update bimm_subscription_tiers` |
| OAuth client | Hardcoded | `wp option update bimm_oauth_clients` |
| .rfa files | Zero-byte placeholders or small real samples | Mounted as a local Docker volume; in production CDN |

### 22.5 The configuration switch

When real access to bimmodeller.com staging arrives, the swap is three config lines. **No plugin code change.**

| File | Dev value | Production-staging value | Production-GA value |
|---|---|---|---|
| Plugin `appsettings.json` → `ApiBase` | `http://dev.bimmodeller.local/wp-json/bimm/v1` | `https://staging.bimmodeller.com/wp-json/bimm/v1` | `https://bimmodeller.com/wp-json/bimm/v1` |
| Plugin `appsettings.json` → `OAuthClientId` | `bimm-revit-plugin-dev` | `bimm-revit-plugin-staging` | `bimm-revit-plugin` |
| Plugin `appsettings.json` → `OAuthRedirectPort` | `47620` | `47620` | `47620` |

OAuth clients (with the redirect URIs) are pre-registered on each environment.

### 22.6 The cutover procedure

When BIMModeller grants staging access (expected end of Phase 2, before Phase 3 OAuth work):

| Step | Owner | Time |
|---|---|---|
| 1. BIMModeller installs `bimm-connect` plugin on staging.bimmodeller.com | BIMModeller IT + plugin lead | 30 min |
| 2. Run `parity-check.sh` against staging — compares dev WP data to staging WP data | Backend dev | 10 min |
| 3. Resolve any diffs (extra fields, missing categories) — usually one PR to bimm-connect | Backend dev | 1-3 hours |
| 4. Register OAuth client `bimm-revit-plugin-staging` in staging WP admin | Backend dev | 5 min |
| 5. Switch plugin `appsettings.json` to staging URL | Plugin dev | 1 min |
| 6. Run the 9 acceptance scenarios from §20 against staging | QA | 2 hours |
| 7. Fix any drift; merge | All | varies |
| 8. Repeat 4-7 for production at GA time | All | ~half day |

Total cutover effort: **~half a day** for staging, **~half a day** for GA. Compared to the 11-week project, this is in the noise — the dev environment removes essentially all the schedule risk associated with blocked staging access.

### 22.7 Gotchas

| Risk | Mitigation |
|---|---|
| Localhost is HTTP; production is HTTPS | Plugin's HTTP client accepts both schemes; OAuth redirect URIs registered for both `http://localhost:47620/callback` (dev) and the same on prod (the listener is always on localhost) |
| Real bimmodeller.com may have categories or product fields we haven't crawled | `parity-check.sh` surfaces diffs at cutover; usually 1-3 small `bimm-connect` patches resolve it |
| Real bimmodeller.com WP users may have WooCommerce subscriptions | Our dummy uses simple usermeta `bimm_subscription_tier`. In production, a WooCommerce hook populates the same usermeta from order data. Plugin contract is unchanged. |
| Real bimmodeller.com .rfa files may be much larger | Run the large-file Antigravity scenario (§18.3) against real staging once we have access |
| Test users in dev don't exist in production | Production tests use seeded prod-staging test accounts that BIMModeller creates as part of the kickoff items (§3.2) |
| OAuth client_id differs between environments | Each `appsettings.json` profile (dev / staging / prod) names its own client; CI builds an MSI per profile if needed, or the user toggles via an environment variable |

### 22.8 What this changes in the project schedule

The week-by-week schedule (§16, Implementation order) already assumes this approach. Specifically:

- Day 1 of Week 1: `bimm-dev-environment` repo created; Docker stack running
- Day 2 of Week 1: seed data loaded; first REST call from plugin to dummy succeeds
- Weeks 1-9: all engineering work happens against the dummy
- Week 10 morning: cutover to bimmodeller.com staging (½ day per §22.6)
- Week 10 afternoon onwards: GA-prep work against real staging
- Week 11 morning: cutover to bimmodeller.com production
- Week 11 onwards: GA

If BIMModeller delivers staging access in Week 1 anyway: we still use the Docker dev environment for daily work, and switch to real staging just for end-of-phase smoke tests. The Docker environment never becomes obsolete — it's the developer's local sandbox forever.

### 22.9 Sample `docker-compose.yml` (Magento 2)

Recommended starting point: clone the [Mark Shust Magento Docker](https://github.com/markshust/docker-magento) project, then mount our module. Simplified example:

```yaml
services:
  db:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: magento
      MYSQL_DATABASE: magento
    volumes: [db_data:/var/lib/mysql]
    healthcheck:
      test: ["CMD", "mysqladmin", "ping"]
      interval: 5s
      retries: 30

  elasticsearch:
    image: elasticsearch:7.17.10
    environment:
      - discovery.type=single-node
      - "ES_JAVA_OPTS=-Xms512m -Xmx512m"
    volumes: [es_data:/usr/share/elasticsearch/data]

  redis:
    image: redis:7-alpine

  app:
    image: markoshust/magento-php:8.2-fpm-2
    depends_on:
      db: { condition: service_healthy }
      elasticsearch: { condition: service_started }
    volumes:
      - app_data:/var/www/html
      - ../bimm-connect:/var/www/html/app/code/BIMM/Connect
      - ./seed/rfa-samples:/var/www/html/pub/media/bimm-rfa
      - ./scripts:/scripts

  web:
    image: markoshust/magento-nginx:1.24-1
    depends_on: [app]
    ports: ["80:8000"]
    volumes:
      - app_data:/var/www/html

  # Optional: stand in for the CDN (signed-URL test)
  minio:
    image: minio/minio
    command: server /data --console-address ":9001"
    ports: ["9000:9000", "9001:9001"]
    environment:
      MINIO_ROOT_USER: minioadmin
      MINIO_ROOT_PASSWORD: minioadmin
    volumes: [minio_data:/data]

volumes:
  db_data:
  app_data:
  es_data:
  minio_data:
```

First-time bootstrap inside the container:

```bash
docker compose exec app bash
composer create-project --repository=https://repo.magento.com magento/project-community-edition .
bin/magento setup:install --base-url=http://dev.bimmodeller.local \
  --db-host=db --db-name=magento --db-user=root --db-password=magento \
  --elasticsearch-host=elasticsearch --elasticsearch-port=9200 \
  --admin-firstname=Dev --admin-lastname=Admin --admin-email=admin@dev.local \
  --admin-user=admin --admin-password=admin12345 \
  --use-rewrites=1 --search-engine=elasticsearch7
bin/magento module:enable BIMM_Connect
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### 22.10 Sample `seed-data.sh` (Magento 2)

```bash
#!/usr/bin/env bash
set -e
cd /var/www/html

# Make sure module is enabled (idempotent)
bin/magento module:enable BIMM_Connect
bin/magento setup:upgrade

# Import categories using Magento's category creation API
php /scripts/import-categories.php

# Import 3,874 products using Magento's product import API (REST/V1/products)
# OR use bin/magento import:csv if we generate a Magento-compatible CSV
php /scripts/import-products.php

# Customer groups for tiers (idempotent — patches run only once)
bin/magento setup:db-data:upgrade  # runs CreateBimmTiersGroups patch

# Tier config — set per-group monthly load limits
php /scripts/configure-tiers.php

# Seed test customers — one per tier
php /scripts/seed-test-customers.sh

# Register the plugin's OAuth client
bin/magento bimm:oauth:register-client \
  --client-id=bimm-revit-plugin-dev \
  --redirect-uri=http://localhost:47620/callback

# Cache refresh
bin/magento cache:clean
bin/magento cache:flush

echo "Seed complete."
bin/magento bimm:status  # custom CLI: reports product count, customer count, tier config
```

### 22.11 Acceptance criteria for the dev environment

- `docker compose up -d` then seed scripts complete in < 5 minutes on a clean laptop
- `curl http://dev.bimmodeller.local/rest/V1/bimm/products` returns 3,800+ products
- `curl /rest/V1/bimm/health` returns 200
- Plugin (with dev `appsettings.json`) can sign in as `lite-user` and load 10 families before hitting 402
- `parity-check.sh --target https://staging.bimmodeller.com` produces a clean diff report

---



### 22.12 Free public access via Cloudflare Tunnel — the zero-cost staging URL

The local Docker Magento setup is reachable only on the developer's laptop. To give the rest of the team and (eventually) BIMModeller staging-ready access without paying for hosting, we expose it through a **Cloudflare Tunnel** — a free, secure reverse tunnel from a public Cloudflare URL to the local Docker stack.

**Zero monthly cost.** No droplet. No managed Magento. The price is one team member keeps Docker + Cloudflared running during work hours.

#### 22.12.1 Two flavors of tunnel

| Mode | URL | Setup | When to use |
|---|---|---|---|
| **TryCloudflare** (quick tunnel) | `https://random-thing.trycloudflare.com` — changes every restart | One command, no account | Solo dev / quick experiments |
| **Named Tunnel** | `https://bimm-dev.yourdomain.com` — stays the same forever | 10-minute one-time setup, free Cloudflare account + a domain | Team development (recommended) |

#### 22.12.2 One-time setup (named tunnel — recommended)

```bash
# 1. Install cloudflared (macOS / Windows / Linux all supported)
brew install cloudflared   # macOS
# or download from cloudflare.com

# 2. Authenticate (opens browser)
cloudflared tunnel login

# 3. Create the tunnel (one time only)
cloudflared tunnel create bimm-dev
# Outputs a tunnel ID and credentials JSON saved to ~/.cloudflared/

# 4. Route a subdomain to it (one time only)
cloudflared tunnel route dns bimm-dev bimm-dev.yourdomain.com

# 5. Configure ingress (~/.cloudflared/config.yml)
cat > ~/.cloudflared/config.yml <<EOF
tunnel: <tunnel-id-from-step-3>
credentials-file: /Users/you/.cloudflared/<tunnel-id>.json
ingress:
  - hostname: bimm-dev.yourdomain.com
    service: https://magento.test
    originRequest:
      noTLSVerify: true
  - service: http_status:404
EOF
```

#### 22.12.3 Daily operation

```bash
# Terminal 1 — start Docker Magento (markoshust/docker-magento)
cd ~/bimm-magento-dev && docker compose up -d

# Terminal 2 — start the tunnel (runs as long as this is open)
cloudflared tunnel run bimm-dev

# That's it — https://bimm-dev.yourdomain.com is now alive and serves Magento
```

#### 22.12.4 Tell Magento its public URL

```bash
docker compose exec phpfpm bash
bin/magento config:set web/secure/base_url https://bimm-dev.yourdomain.com/
bin/magento config:set web/unsecure/base_url https://bimm-dev.yourdomain.com/
bin/magento config:set web/secure/use_in_frontend 1
bin/magento config:set web/secure/use_in_adminhtml 1
bin/magento cache:flush
```

#### 22.12.5 Plugin configuration

`appsettings.json` on the Plugin side points to the tunnel URL:

```json
{
  "ApiBase": "https://bimm-dev.yourdomain.com/rest/V1/bimm",
  "OAuthClientId": "bimm-revit-plugin-dev",
  "OAuthRedirectPort": 47620
}
```

#### 22.12.6 Tradeoffs to be honest about

| Tradeoff | Mitigation |
|---|---|
| The host laptop must be on during work hours | Designate one team laptop as "the staging machine"; rotate weekly if needed |
| Unattended runs (overnight, weekend) — staging may be offline | Use local-only tests for those runs; OR temporarily spin up a $24 droplet during launch week only |
| TryCloudflare URLs change per restart | Use the named-tunnel approach (requires a domain you control) — URL stays stable |
| Bandwidth limited by the host laptop's internet | Cloudflare's free tier is generous; only an issue if dozens of architects download at once. Fine for dev / beta. |

#### 22.12.7 Cutover to bimmodeller.com

When BIMModeller grants real staging access on their Magento installation, the cutover is identical to §22.6 — one config change on the plugin side. Engineering doesn't pay anything ever in this model.

#### 22.12.8 Run cloudflared as a service (for reliability)

To survive a laptop reboot or terminal close:

```bash
sudo cloudflared service install
# Tunnel comes back automatically after Docker is back up
```

---

# Appendices

## A. Sample code snippets

### A.1 PKCE helper (Plugin)

```csharp
using System.Security.Cryptography;
using System.Text;

public static class PkceHelper
{
    public static string NewVerifier()
    {
        var bytes = new byte[64];
        RandomNumberGenerator.Fill(bytes);
        return Base64UrlEncode(bytes);
    }

    public static string Challenge(string verifier)
    {
        using var sha = SHA256.Create();
        var hash = sha.ComputeHash(Encoding.ASCII.GetBytes(verifier));
        return Base64UrlEncode(hash);
    }

    private static string Base64UrlEncode(byte[] bytes) =>
        Convert.ToBase64String(bytes).Replace('+', '-').Replace('/', '_').TrimEnd('=');
}
```

### A.2 BimmApiClient base (Plugin)

```csharp
public class BimmApiClient : IBimmApiClient
{
    private readonly HttpClient _http;
    private readonly ITokenStore _tokens;
    private readonly AppSettings _settings;
    private readonly ILogger<BimmApiClient> _logger;

    public async Task<Result<T>> GetAsync<T>(string path, CancellationToken ct = default)
    {
        var token = await _tokens.GetAsync(ct);
        if (token == null) return Result<T>.Fail(new BimmError("not_signed_in", "Sign in required", null, null));

        using var req = new HttpRequestMessage(HttpMethod.Get, _settings.ApiBase + path);
        req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token.AccessToken);

        var resp = await _http.SendAsync(req, ct);
        if (resp.StatusCode == HttpStatusCode.Unauthorized)
            return await RefreshAndRetry<T>(req, ct);
        if (resp.StatusCode == (HttpStatusCode)402)
            return Result<T>.Fail(await ParseBimmError(resp));
        if (!resp.IsSuccessStatusCode)
            return Result<T>.Fail(await ParseBimmError(resp));

        var body = await resp.Content.ReadAsStringAsync(ct);
        return Result<T>.Ok(JsonSerializer.Deserialize<T>(body, JsonOpts)!);
    }
}
```

### A.3 OAuth token endpoint (bimm-connect)

```php
namespace BIMM\Connect\Rest;

class OAuthController {
    public function exchange(\WP_REST_Request $req): \WP_REST_Response {
        $code = $req->get_param('code');
        $verifier = $req->get_param('code_verifier');
        $redirect = $req->get_param('redirect_uri');

        $transient = get_transient("bimm_oauth_code_$code");
        if (!$transient) return $this->error('invalid_grant', 'Code expired or invalid', 400);
        delete_transient("bimm_oauth_code_$code");

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!hash_equals($transient['code_challenge'], $challenge))
            return $this->error('invalid_grant', 'PKCE verification failed', 400);

        if (!hash_equals($transient['redirect_uri'], $redirect))
            return $this->error('invalid_grant', 'Redirect URI mismatch', 400);

        $tokens = $this->tokenService->issuePair($transient['user_id'], $transient['scope']);
        return new \WP_REST_Response([
            'access_token'  => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => 3600,
            'scope'         => $transient['scope'],
        ], 200);
    }
}
```

### A.4 Subscription enforcement (bimm-connect)

```php
namespace BIMM\Connect\Subscription;

class QuotaEnforcer {
    public function canLoad(User $user, Tier $tier): bool {
        if ($tier->monthlyLoadLimit === null) return true; // unlimited
        return $user->quotaUsedThisMonth < $tier->monthlyLoadLimit;
    }

    public function recordLoad(User $user): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->usermeta}
             SET meta_value = CAST(meta_value AS UNSIGNED) + 1
             WHERE user_id = %d AND meta_key = 'bimm_quota_used_this_month'",
            $user->id
        ));
    }
}
```

### A.5 Multi-view orchestration (Plugin, Revit API)

```csharp
public class ViewOrchestrator
{
    public void ZoomBothViewsToInstance(UIDocument uidoc, ElementId instanceId)
    {
        var doc = uidoc.Document;
        var view3d = new FilteredElementCollector(doc)
            .OfClass(typeof(View3D))
            .Cast<View3D>()
            .FirstOrDefault(v => !v.IsTemplate);
        var planView = doc.ActiveView; // assume current plan; refine if needed

        uidoc.RequestViewChange(view3d);
        var uiView3d = uidoc.GetOpenUIViews().First(v => v.ViewId == view3d.Id);
        var bbox = doc.GetElement(instanceId).get_BoundingBox(view3d);
        uiView3d.ZoomAndCenterRectangle(bbox.Min, bbox.Max);

        uidoc.RequestViewChange(planView);
        var uiViewPlan = uidoc.GetOpenUIViews().First(v => v.ViewId == planView.Id);
        var bboxPlan = doc.GetElement(instanceId).get_BoundingBox(planView);
        uiViewPlan.ZoomAndCenterRectangle(bboxPlan.Min, bboxPlan.Max);
    }
}
```

## B. Glossary

| Term | Meaning |
|---|---|
| PKCE | Proof Key for Code Exchange — OAuth extension for public clients |
| DPAPI | Windows Data Protection API; per-user encryption |
| TFM | Target Framework Moniker (e.g., net48, net8.0-windows) |
| CPT | Custom Post Type (WordPress) |
| WP REST API | WordPress's built-in REST API at `/wp-json/` |
| External Event | Revit pattern for marshalling work to Revit's UI thread |
| Family | A Revit object type (e.g., a door, a sink); stored as `.rfa` |
| Signed URL | Time-limited, signed URL to a CDN-hosted file |
| Bearer token | Access token sent in `Authorization: Bearer ...` header |
| Quota | Per-tier monthly load limit (e.g., Lite = 10 loads/month) |

## C. Change log

- **v1.0** (May 2026) — Initial issue (WordPress edition). Reflects Client Handover v1.2 / Team Execution Plan v1.2 scope: separate-window UI, OAuth via bimmodeller.com, subscription gating, Libraries tab as placeholder. Includes §22 — Dummy/dev environment so engineering is unblocked from Day 1.
- **v1.2** (May 2026) — Adds §22.12 — Free public access via Cloudflare Tunnel — zero-cost staging URL for team development.
- **v1.1** (May 2026) — **Magento edition.** Server-side rewrite from WordPress plugin to Magento 2 module. Plugin (C#), REST contract, OAuth flow, admin dashboard React app, project schedule are all UNAFFECTED. Affected sections: §1 (system diagram), §2 (repo description), §3.2 (server tech stack), §4.1 (entities), §5.1.1 (registration flow), §5.5 (subscription enforcement code path), §9 (renamed from "WordPress connector" to "Magento module"), §11.1 (base URL), §13 (event table), §15.2 (repo setup), §17.3 (CI), §22 (dev environment uses Magento Docker).

---

End of document. For any question not answered by the contracts above, the implementer should ask the human project lead before guessing.
