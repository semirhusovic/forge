# Forge Deploy Panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Forge-like panel running on the Ubuntu server it manages: create sites with Apache vhosts, deploy Laravel apps from GitHub (deploy key + webhook), issue Let's Encrypt SSL, edit `.env`/deploy scripts, manage MySQL databases, queue workers, and scheduler crons.

**Architecture:** All shell work goes through one `ShellRunner` service (with a `FORGE_FAKE_SHELL` mode for Windows dev). Long-running operations (install, deploy, SSL) are queued jobs that append output to DB columns; the Vue UI polls with Inertia `usePoll`. Privileged ops use a sudoers whitelist; managed MySQL uses a separate privileged DB connection.

**Tech Stack:** Laravel 13, Vue 3 + Inertia v3, Fortify, Wayfinder (`@/routes/...` imports), Tailwind 4, SQLite panel DB, database queue.

**Spec:** `docs/superpowers/specs/2026-07-08-forge-deploy-panel-design.md`

**Testing policy (per user):** No automated tests. Each task verifies via `php artisan migrate`, `php artisan route:list`, `vendor/bin/pint --dirty --format agent`, and (final task) `npm run build` + a fake-shell click-through. For local click-through set in `.env`: `FORGE_FAKE_SHELL=true` and `QUEUE_CONNECTION=sync`.

**Conventions:**
- New backend dirs: `app/Enums`, `app/Services`, `app/Jobs` (standard Laravel, created by artisan where possible).
- Use `php artisan make:... --no-interaction`.
- Wayfinder: import named routes from `@/routes/<name>`; call `.url()` when a string URL is needed for `useForm`.
- Commit after every task.

---

### Task 1: Config, enums, MySQL admin connection

**Files:**
- Create: `config/forge.php`
- Create: `app/Enums/SiteStatus.php`
- Create: `app/Enums/DeploymentStatus.php`
- Modify: `config/database.php` (add `forge_mysql` connection)
- Modify: `.env.example` and `.env` (forge vars)

- [ ] **Step 1: Create `config/forge.php`**

```php
<?php

return [
    /*
     * When true, ShellRunner logs commands instead of executing them and
     * managed-MySQL statements are skipped. For local development.
     */
    'fake_shell' => env('FORGE_FAKE_SHELL', false),

    'sites_path' => env('FORGE_SITES_PATH', '/home/forge'),

    'ssh_path' => env('FORGE_SSH_PATH', '/home/forge/.ssh'),

    'php_binary' => env('FORGE_PHP_BINARY', '/usr/bin/php'),

    'certbot_email' => env('FORGE_CERTBOT_EMAIL'),
];
```

- [ ] **Step 2: Create enums**

Run: `php artisan make:enum Enums/SiteStatus --string --no-interaction` and `php artisan make:enum Enums/DeploymentStatus --string --no-interaction`, then fill:

`app/Enums/SiteStatus.php`:
```php
<?php

namespace App\Enums;

enum SiteStatus: string
{
    case Pending = 'pending';
    case KeyGenerated = 'key_generated';
    case Installing = 'installing';
    case Installed = 'installed';
    case Failed = 'failed';
}
```

`app/Enums/DeploymentStatus.php`:
```php
<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
}
```

- [ ] **Step 3: Add `forge_mysql` connection to `config/database.php`**

Inside the `'connections'` array, after the existing `mysql` entry:

```php
'forge_mysql' => [
    'driver' => 'mysql',
    'host' => env('FORGE_MYSQL_HOST', '127.0.0.1'),
    'port' => env('FORGE_MYSQL_PORT', '3306'),
    'database' => '',
    'username' => env('FORGE_MYSQL_USERNAME', 'forge_admin'),
    'password' => env('FORGE_MYSQL_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
],
```

- [ ] **Step 4: Add env vars**

Append to `.env.example`:
```
FORGE_FAKE_SHELL=false
FORGE_SITES_PATH=/home/forge
FORGE_SSH_PATH=/home/forge/.ssh
FORGE_PHP_BINARY=/usr/bin/php
FORGE_CERTBOT_EMAIL=
FORGE_MYSQL_HOST=127.0.0.1
FORGE_MYSQL_USERNAME=forge_admin
FORGE_MYSQL_PASSWORD=
```

Append to `.env` the same block but with `FORGE_FAKE_SHELL=true`, and set `QUEUE_CONNECTION=sync` (local dev).

- [ ] **Step 5: Verify + commit**

Run: `php artisan config:show forge` → shows the forge config.
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add -A && git commit -m "feat: forge config, status enums, managed-mysql connection"
```

---

### Task 2: Migrations and models

**Files:**
- Create: migrations for `sites`, `deployments`, `managed_databases`, `workers`
- Create: `app/Models/Site.php`, `app/Models/Deployment.php`, `app/Models/ManagedDatabase.php`, `app/Models/Worker.php`

- [ ] **Step 1: Create migrations**

Run:
```
php artisan make:migration create_sites_table --no-interaction
php artisan make:migration create_deployments_table --no-interaction
php artisan make:migration create_managed_databases_table --no-interaction
php artisan make:migration create_workers_table --no-interaction
```

`create_sites_table` `up()`:
```php
Schema::create('sites', function (Blueprint $table) {
    $table->id();
    $table->string('domain')->unique();
    $table->string('repository');
    $table->string('branch')->default('main');
    $table->string('root_path');
    $table->string('web_root_suffix')->default('/public');
    $table->string('status')->default('pending');
    $table->text('deploy_script');
    $table->boolean('auto_deploy')->default(true);
    $table->string('webhook_token', 64)->unique();
    $table->text('deploy_key_public')->nullable();
    $table->boolean('ssl_enabled')->default(false);
    $table->timestamp('ssl_expires_at')->nullable();
    $table->boolean('has_scheduler')->default(false);
    $table->text('provision_log')->nullable();
    $table->timestamps();
});
```

`create_deployments_table` `up()`:
```php
Schema::create('deployments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->cascadeOnDelete();
    $table->string('status')->default('pending');
    $table->string('trigger')->default('manual');
    $table->string('commit_hash')->nullable();
    $table->string('commit_message')->nullable();
    $table->longText('output')->nullable();
    $table->timestamp('started_at')->nullable();
    $table->timestamp('finished_at')->nullable();
    $table->timestamps();
});
```

`create_managed_databases_table` `up()`:
```php
Schema::create('managed_databases', function (Blueprint $table) {
    $table->id();
    $table->string('name')->unique();
    $table->string('username');
    $table->timestamps();
});
```

`create_workers_table` `up()`:
```php
Schema::create('workers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->cascadeOnDelete();
    $table->string('command')->default('queue:work --tries=3');
    $table->string('status')->default('running');
    $table->timestamps();
});
```

- [ ] **Step 2: Create models**

Run `php artisan make:model Site --no-interaction` (and Deployment, ManagedDatabase, Worker; no factories — no tests for now).

`app/Models/Site.php`:
```php
<?php

namespace App\Models;

use App\Enums\SiteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $fillable = [
        'domain', 'repository', 'branch', 'root_path', 'web_root_suffix',
        'status', 'deploy_script', 'auto_deploy', 'webhook_token',
        'deploy_key_public', 'ssl_enabled', 'ssl_expires_at',
        'has_scheduler', 'provision_log',
    ];

    protected function casts(): array
    {
        return [
            'status' => SiteStatus::class,
            'auto_deploy' => 'boolean',
            'ssl_enabled' => 'boolean',
            'has_scheduler' => 'boolean',
            'ssl_expires_at' => 'datetime',
        ];
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class)->latest();
    }

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }

    /** SSH host alias so each site uses its own deploy key. */
    public function gitHostAlias(): string
    {
        return 'github.com-site-'.$this->id;
    }

    /** Repository URL rewritten to use the per-site SSH host alias. */
    public function cloneUrl(): string
    {
        return preg_replace('/github\.com/', $this->gitHostAlias(), $this->repository, 1);
    }

    public function webhookUrl(): string
    {
        return route('webhook.deploy', ['site' => $this->id, 'token' => $this->webhook_token]);
    }

    public function webRoot(): string
    {
        return $this->root_path.$this->web_root_suffix;
    }

    public function appendProvisionLog(string $chunk): void
    {
        $this->update(['provision_log' => ($this->provision_log ?? '').$chunk]);
    }

    public static function defaultDeployScript(string $rootPath, string $branch): string
    {
        return <<<BASH
        cd {$rootPath}
        git pull origin {$branch}
        composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
        php artisan migrate --force
        php artisan optimize
        BASH;
    }
}
```

`app/Models/Deployment.php`:
```php
<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    protected $fillable = [
        'site_id', 'status', 'trigger', 'commit_hash', 'commit_message',
        'output', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function appendOutput(string $chunk): void
    {
        $this->update(['output' => ($this->output ?? '').$chunk]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [DeploymentStatus::Pending, DeploymentStatus::Running], true);
    }
}
```

`app/Models/ManagedDatabase.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedDatabase extends Model
{
    protected $fillable = ['name', 'username'];
}
```

`app/Models/Worker.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Worker extends Model
{
    protected $fillable = ['site_id', 'command', 'status'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function unitName(): string
    {
        return "forge-worker-{$this->id}.service";
    }
}
```

- [ ] **Step 3: Verify + commit**

Run: `php artisan migrate --no-interaction` → all four migrations run.
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add -A && git commit -m "feat: sites, deployments, managed databases, workers schema and models"
```

---

### Task 3: ShellRunner service

**Files:**
- Create: `app/Services/ShellRunner.php`
- Create: `app/Services/ShellResult.php`

- [ ] **Step 1: Create `app/Services/ShellResult.php`**

```php
<?php

namespace App\Services;

class ShellResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
    ) {
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
```

- [ ] **Step 2: Create `app/Services/ShellRunner.php`**

All command execution goes through here. Fake mode logs commands, emits a fake output line, and reports success — so the whole UI is clickable on Windows.

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class ShellRunner
{
    public function isFake(): bool
    {
        return (bool) config('forge.fake_shell');
    }

    /**
     * Run a shell command, streaming combined stdout/stderr to $onOutput.
     */
    public function run(string $command, ?string $cwd = null, int $timeout = 1800, ?callable $onOutput = null): ShellResult
    {
        if ($this->isFake()) {
            $line = '[fake-shell] $ '.$command."\n";
            Log::info($line, ['cwd' => $cwd]);
            if ($onOutput !== null) {
                $onOutput($line);
            }

            return new ShellResult(0, $line);
        }

        $output = '';

        $pending = Process::timeout($timeout);

        if ($cwd !== null) {
            $pending = $pending->path($cwd);
        }

        $result = $pending->run($command, function (string $type, string $buffer) use (&$output, $onOutput): void {
            $output .= $buffer;
            if ($onOutput !== null) {
                $onOutput($buffer);
            }
        });

        return new ShellResult($result->exitCode() ?? 1, $output);
    }

    /**
     * @throws RuntimeException when the command exits non-zero
     */
    public function runOrFail(string $command, ?string $cwd = null, int $timeout = 1800, ?callable $onOutput = null): ShellResult
    {
        $result = $this->run($command, $cwd, $timeout, $onOutput);

        if (! $result->successful()) {
            throw new RuntimeException("Command failed ({$result->exitCode}): {$command}\n{$result->output}");
        }

        return $result;
    }

    /**
     * Write contents to a root-owned path: write a temp file as the app user,
     * then sudo cp it into place (cp targets are whitelisted in sudoers).
     */
    public function writeAsRoot(string $contents, string $destination): void
    {
        if ($this->isFake()) {
            Log::info("[fake-shell] write {$destination}", ['contents' => $contents]);

            return;
        }

        $temp = tempnam(sys_get_temp_dir(), 'forge-');
        File::put($temp, $contents);

        try {
            $this->runOrFail(sprintf('sudo cp %s %s', escapeshellarg($temp), escapeshellarg($destination)));
            $this->runOrFail(sprintf('sudo chmod 644 %s', escapeshellarg($destination)));
        } finally {
            File::delete($temp);
        }
    }
}
```

- [ ] **Step 3: Verify + commit**

Run: `php artisan tinker --execute 'var_dump(app(\App\Services\ShellRunner::class)->run("echo hi")->successful());'` → `bool(true)` (fake mode logs it).
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add -A && git commit -m "feat: ShellRunner service with fake mode and privileged file writes"
```

---

### Task 4: Site creation — deploy key action, controller, routes, Index page, nav

**Files:**
- Create: `app/Actions/GenerateSiteDeployKey.php`
- Create: `app/Http/Controllers/SiteController.php`
- Create: `app/Http/Requests/StoreSiteRequest.php`
- Create: `resources/js/pages/sites/Index.vue`
- Modify: `routes/web.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` (share flash)
- Modify: `resources/js/components/AppSidebar.vue` (nav items)

- [ ] **Step 1: Create `app/Actions/GenerateSiteDeployKey.php`**

Run `php artisan make:class Actions/GenerateSiteDeployKey --no-interaction`, then:

```php
<?php

namespace App\Actions;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\ShellRunner;
use Illuminate\Support\Facades\File;

class GenerateSiteDeployKey
{
    public function __construct(private ShellRunner $shell)
    {
    }

    public function handle(Site $site): void
    {
        if ($this->shell->isFake()) {
            $site->update([
                'deploy_key_public' => "ssh-ed25519 AAAA-FAKE-KEY forge-site-{$site->id}",
                'status' => SiteStatus::KeyGenerated,
            ]);

            return;
        }

        $sshPath = config('forge.ssh_path');
        $keyFile = "{$sshPath}/site-{$site->id}";

        $this->shell->runOrFail(sprintf(
            'ssh-keygen -t ed25519 -N "" -C %s -f %s',
            escapeshellarg("forge-site-{$site->id}"),
            escapeshellarg($keyFile),
        ));

        File::append("{$sshPath}/config", implode("\n", [
            '',
            "Host {$site->gitHostAlias()}",
            '    HostName github.com',
            "    IdentityFile {$keyFile}",
            '    IdentitiesOnly yes',
            '',
        ]));

        $site->update([
            'deploy_key_public' => trim(File::get("{$keyFile}.pub")),
            'status' => SiteStatus::KeyGenerated,
        ]);
    }
}
```

- [ ] **Step 2: Create `app/Http/Requests/StoreSiteRequest.php`**

Run `php artisan make:request StoreSiteRequest --no-interaction`, then:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:100', 'unique:sites,domain', 'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.[a-z0-9-]{1,63})+$/'],
            'repository' => ['required', 'string', 'max:255', 'regex:/^git@github\.com:[\w.-]+\/[\w.-]+\.git$/'],
            'branch' => ['required', 'string', 'max:100', 'regex:/^[\w\/.-]+$/'],
        ];
    }
}
```

- [ ] **Step 3: Create `app/Http/Controllers/SiteController.php`**

Run `php artisan make:controller SiteController --no-interaction`, then:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\GenerateSiteDeployKey;
use App\Http\Requests\StoreSiteRequest;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('sites/Index', [
            'sites' => Site::query()->latest()->get(['id', 'domain', 'repository', 'branch', 'status', 'ssl_enabled']),
        ]);
    }

    public function store(StoreSiteRequest $request, GenerateSiteDeployKey $generateKey): RedirectResponse
    {
        $rootPath = rtrim(config('forge.sites_path'), '/').'/'.$request->validated('domain');

        $site = Site::create([
            ...$request->validated(),
            'root_path' => $rootPath,
            'webhook_token' => Str::random(48),
            'deploy_script' => Site::defaultDeployScript($rootPath, $request->validated('branch')),
        ]);

        $generateKey->handle($site);

        return to_route('sites.show', $site)->with('success', 'Site created. Add the deploy key and webhook to GitHub, then install the repository.');
    }

    public function show(Site $site): Response
    {
        return Inertia::render('sites/Show', [
            'site' => [
                ...$site->only([
                    'id', 'domain', 'repository', 'branch', 'root_path', 'status',
                    'deploy_script', 'auto_deploy', 'deploy_key_public',
                    'ssl_enabled', 'ssl_expires_at', 'has_scheduler', 'provision_log',
                ]),
                'webhook_url' => $site->webhookUrl(),
            ],
            'deployments' => $site->deployments()->limit(10)->get([
                'id', 'site_id', 'status', 'trigger', 'commit_hash', 'commit_message', 'created_at', 'finished_at',
            ]),
            'workers' => $site->workers()->get(['id', 'command', 'status']),
            'envContent' => Inertia::optional(fn () => app(\App\Services\EnvFileManager::class)->read($site)),
        ]);
    }

    public function destroy(Site $site): RedirectResponse
    {
        // Full server-side teardown (vhost, workers, cron) is wired in the final task.
        $site->delete();

        return to_route('sites.index')->with('success', 'Site deleted.');
    }
}
```

Note: `EnvFileManager` is created in Task 10; until then keep the `envContent` line commented out with `// 'envContent' => ...` and uncomment it in Task 10.

- [ ] **Step 4: Add routes to `routes/web.php`**

Replace the auth group with:

```php
use App\Http\Controllers\SiteController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::resource('sites', SiteController::class)->only(['index', 'store', 'show', 'destroy']);
});
```

- [ ] **Step 5: Share flash messages in `app/Http/Middleware/HandleInertiaRequests.php`**

Add to the array returned by `share()`:

```php
'flash' => [
    'success' => fn () => $request->session()->get('success'),
    'error' => fn () => $request->session()->get('error'),
    'password' => fn () => $request->session()->get('password'),
],
```

- [ ] **Step 6: Create `resources/js/pages/sites/Index.vue`**

```vue
<script setup lang="ts">
import { Head, useForm, Link, usePage } from '@inertiajs/vue3';
import { index as sitesIndex, store as sitesStore, show as siteShow } from '@/routes/sites';

interface SiteListItem {
    id: number;
    domain: string;
    repository: string;
    branch: string;
    status: string;
    ssl_enabled: boolean;
}

defineProps<{ sites: SiteListItem[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Sites', href: sitesIndex() }] },
});

const page = usePage();

const form = useForm({
    domain: '',
    repository: '',
    branch: 'main',
});

function submit() {
    form.post(sitesStore.url());
}
</script>

<template>
    <Head title="Sites" />

    <div class="flex flex-col gap-6 p-4">
        <div v-if="(page.props as any).flash?.success" class="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-800">
            {{ (page.props as any).flash.success }}
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-3 rounded-xl border p-4 md:max-w-xl">
            <h2 class="font-semibold">New site</h2>
            <label class="text-sm">
                Domain
                <input v-model="form.domain" placeholder="app.example.com" class="mt-1 w-full rounded border px-2 py-1.5" />
                <span v-if="form.errors.domain" class="text-sm text-red-600">{{ form.errors.domain }}</span>
            </label>
            <label class="text-sm">
                Repository (SSH)
                <input v-model="form.repository" placeholder="git@github.com:user/repo.git" class="mt-1 w-full rounded border px-2 py-1.5" />
                <span v-if="form.errors.repository" class="text-sm text-red-600">{{ form.errors.repository }}</span>
            </label>
            <label class="text-sm">
                Branch
                <input v-model="form.branch" class="mt-1 w-full rounded border px-2 py-1.5" />
                <span v-if="form.errors.branch" class="text-sm text-red-600">{{ form.errors.branch }}</span>
            </label>
            <button type="submit" :disabled="form.processing" class="self-start rounded bg-black px-4 py-2 text-sm text-white disabled:opacity-50 dark:bg-white dark:text-black">
                Create site
            </button>
        </form>

        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b">
                    <th class="py-2">Domain</th>
                    <th>Repository</th>
                    <th>Branch</th>
                    <th>Status</th>
                    <th>SSL</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="site in sites" :key="site.id" class="border-b">
                    <td class="py-2">
                        <Link :href="siteShow(site.id)" class="font-medium underline">{{ site.domain }}</Link>
                    </td>
                    <td>{{ site.repository }}</td>
                    <td>{{ site.branch }}</td>
                    <td>{{ site.status }}</td>
                    <td>{{ site.ssl_enabled ? 'yes' : 'no' }}</td>
                </tr>
                <tr v-if="!sites.length">
                    <td colspan="5" class="py-4 text-muted-foreground">No sites yet.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
```

- [ ] **Step 7: Add nav items in `resources/js/components/AppSidebar.vue`**

Read the file first; it has a `mainNavItems` (or similar) array with a Dashboard entry using a lucide icon. Add two entries following the exact existing shape, e.g.:

```ts
import { Globe, Database } from 'lucide-vue-next';
import { index as sitesIndex } from '@/routes/sites';
// databases route exists after Task 14; add the Databases nav item then if the import fails.

{ title: 'Sites', href: sitesIndex(), icon: Globe },
```

- [ ] **Step 8: Verify + commit**

Run: `php artisan route:list --path=sites` → index/store/show/destroy listed.
Run: `php artisan wayfinder:generate --no-interaction` → regenerates `@/routes`.
Run: `npm run build` → compiles without TS errors.
Run: `vendor/bin/pint --dirty --format agent`
Manual: log in, open `/sites`, create a site → redirected to `/sites/{id}` (page exists after Task 7; a temporary 500/missing-page error on the redirect is acceptable right now — verify the DB row exists via `php artisan tinker --execute 'App\Models\Site::first()->toArray();'`).
```bash
git add -A && git commit -m "feat: site creation with per-site deploy keys, sites index page"
```

---

### Task 5: Apache vhost template + ApacheManager

**Files:**
- Create: `resources/views/server/vhost.blade.php`
- Create: `app/Services/ApacheManager.php`

- [ ] **Step 1: Create `resources/views/server/vhost.blade.php`**

Blade only processes `{{ }}`; Apache's `${APACHE_LOG_DIR}` passes through untouched.

```blade
<VirtualHost *:80>
    ServerName {{ $site->domain }}
    DocumentRoot {{ $site->webRoot() }}

    <Directory {{ $site->webRoot() }}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/{{ $site->domain }}-error.log
    CustomLog ${APACHE_LOG_DIR}/{{ $site->domain }}-access.log combined
</VirtualHost>
```

- [ ] **Step 2: Create `app/Services/ApacheManager.php`**

```php
<?php

namespace App\Services;

use App\Models\Site;
use RuntimeException;

class ApacheManager
{
    public function __construct(private ShellRunner $shell)
    {
    }

    /**
     * Write and enable the HTTP vhost. configtest gates the reload; on
     * failure the site is disabled again so Apache keeps serving.
     *
     * @throws RuntimeException
     */
    public function installVhost(Site $site, ?callable $onOutput = null): void
    {
        $conf = view('server.vhost', ['site' => $site])->render();

        $this->shell->writeAsRoot($conf, "/etc/apache2/sites-available/{$site->domain}.conf");
        $this->shell->runOrFail(sprintf('sudo a2ensite %s', escapeshellarg("{$site->domain}.conf")), onOutput: $onOutput);

        $test = $this->shell->run('sudo apache2ctl configtest', onOutput: $onOutput);

        if (! $test->successful()) {
            $this->shell->run(sprintf('sudo a2dissite %s', escapeshellarg("{$site->domain}.conf")));

            throw new RuntimeException("Apache configtest failed, site disabled again:\n{$test->output}");
        }

        $this->shell->runOrFail('sudo systemctl reload apache2', onOutput: $onOutput);
    }

    public function removeVhost(Site $site): void
    {
        $this->shell->run(sprintf('sudo a2dissite %s', escapeshellarg("{$site->domain}.conf")));
        $this->shell->run(sprintf('sudo rm %s', escapeshellarg("/etc/apache2/sites-available/{$site->domain}.conf")));
        $this->shell->run('sudo systemctl reload apache2');
    }
}
```

- [ ] **Step 3: Verify + commit**

Run: `php artisan tinker --execute '$s = App\Models\Site::first(); echo view("server.vhost", ["site" => $s])->render();'` → rendered vhost with the site's domain and `/public` docroot.
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add -A && git commit -m "feat: apache vhost template and manager with configtest rollback"
```

---

### Task 6: InstallRepository job + install endpoint

**Files:**
- Create: `app/Jobs/InstallRepository.php`
- Create: `app/Http/Controllers/SiteInstallController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create the job**

Run `php artisan make:job InstallRepository --no-interaction`, then:

```php
<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\ApacheManager;
use App\Services\ShellRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class InstallRepository implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public Site $site)
    {
    }

    public function handle(ShellRunner $shell, ApacheManager $apache): void
    {
        $site = $this->site;
        $site->update(['status' => SiteStatus::Installing, 'provision_log' => '']);

        $log = fn (string $chunk) => $site->appendProvisionLog($chunk);
        $php = config('forge.php_binary');

        try {
            $shell->runOrFail(sprintf(
                'git clone --branch %s %s %s',
                escapeshellarg($site->branch),
                escapeshellarg($site->cloneUrl()),
                escapeshellarg($site->root_path),
            ), onOutput: $log);

            $shell->run('cp .env.example .env', cwd: $site->root_path, onOutput: $log);
            $shell->runOrFail('composer install --no-dev --no-interaction --prefer-dist', cwd: $site->root_path, timeout: 1800, onOutput: $log);
            $shell->run("{$php} artisan key:generate --force", cwd: $site->root_path, onOutput: $log);

            $apache->installVhost($site, $log);

            $site->update(['status' => SiteStatus::Installed]);
            $site->appendProvisionLog("\nInstall complete.\n");
        } catch (Throwable $e) {
            $site->appendProvisionLog("\nINSTALL FAILED: {$e->getMessage()}\n");
            $site->update(['status' => SiteStatus::Failed]);
        }
    }
}
```

- [ ] **Step 2: Create `app/Http/Controllers/SiteInstallController.php`**

Run `php artisan make:controller SiteInstallController --invokable --no-interaction`, then:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\SiteStatus;
use App\Jobs\InstallRepository;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;

class SiteInstallController extends Controller
{
    public function __invoke(Site $site): RedirectResponse
    {
        abort_unless(in_array($site->status, [SiteStatus::KeyGenerated, SiteStatus::Failed], true), 422, 'Site is not ready to install.');

        InstallRepository::dispatch($site);

        return back()->with('success', 'Installation started.');
    }
}
```

(Allowing `Failed` lets the user retry after fixing e.g. a missing deploy key. The job re-clones; on retry after a partial clone the `git clone` will fail with "directory not empty" — visible in the log; v1 leaves cleanup to the user.)

- [ ] **Step 3: Add route inside the auth group in `routes/web.php`**

```php
Route::post('sites/{site}/install', SiteInstallController::class)->name('sites.install');
```

- [ ] **Step 4: Verify + commit**

Run: `php artisan route:list --path=sites` → install route present.
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add -A && git commit -m "feat: repository install job (clone, composer, vhost)"
```

---

### Task 7: Site Show page with App tab

**Files:**
- Create: `resources/js/pages/sites/Show.vue`
- Create: `resources/js/pages/sites/tabs/AppTab.vue`

- [ ] **Step 1: Create `resources/js/pages/sites/Show.vue`**

Tabs are plain buttons + component switch; `usePoll` refreshes `site` + `deployments` so install/deploy progress appears live.

```vue
<script setup lang="ts">
import { Head, usePage, usePoll } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { index as sitesIndex, show as siteShow } from '@/routes/sites';
import AppTab from './tabs/AppTab.vue';

export interface SiteProps {
    id: number;
    domain: string;
    repository: string;
    branch: string;
    root_path: string;
    status: string;
    deploy_script: string;
    auto_deploy: boolean;
    deploy_key_public: string | null;
    ssl_enabled: boolean;
    ssl_expires_at: string | null;
    has_scheduler: boolean;
    provision_log: string | null;
    webhook_url: string;
}

export interface DeploymentItem {
    id: number;
    site_id: number;
    status: string;
    trigger: string;
    commit_hash: string | null;
    commit_message: string | null;
    created_at: string;
    finished_at: string | null;
}

export interface WorkerItem {
    id: number;
    command: string;
    status: string;
}

const props = defineProps<{
    site: SiteProps;
    deployments: DeploymentItem[];
    workers: WorkerItem[];
    envContent?: string;
}>();

defineOptions({ layout: {} });

const page = usePage();
const currentTab = ref<'app' | 'env' | 'ssl' | 'workers' | 'scheduler'>('app');

const tabs = computed(() => ['app', 'env', 'ssl', 'workers', 'scheduler'] as const);

usePoll(3000, { only: ['site', 'deployments'] });
</script>

<template>
    <Head :title="site.domain" />

    <div class="flex flex-col gap-4 p-4">
        <div v-if="(page.props as any).flash?.success" class="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-800">
            {{ (page.props as any).flash.success }}
        </div>
        <div v-if="(page.props as any).flash?.error" class="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">
            {{ (page.props as any).flash.error }}
        </div>

        <div class="flex items-center gap-3">
            <h1 class="text-xl font-semibold">{{ site.domain }}</h1>
            <span class="rounded bg-muted px-2 py-0.5 text-xs">{{ site.status }}</span>
        </div>

        <nav class="flex gap-1 border-b">
            <button
                v-for="tab in tabs"
                :key="tab"
                @click="currentTab = tab"
                class="rounded-t px-3 py-1.5 text-sm capitalize"
                :class="currentTab === tab ? 'border border-b-0 font-medium' : 'text-muted-foreground'"
            >
                {{ tab }}
            </button>
        </nav>

        <AppTab v-if="currentTab === 'app'" :site="site" :deployments="deployments" />
        <div v-else class="text-sm text-muted-foreground">Coming in a later task: {{ currentTab }}</div>
    </div>
</template>
```

Note: the `v-else` placeholder is replaced tab-by-tab in Tasks 10–13. Also set breadcrumbs following the `defineOptions({ layout: { breadcrumbs: [...] } })` pattern used in `Dashboard.vue`, with entries for Sites (`sitesIndex()`) and the domain (`siteShow(site.id)` — note `defineOptions` cannot access props; use a static title like 'Site' if needed).

- [ ] **Step 2: Create `resources/js/pages/sites/tabs/AppTab.vue`**

```vue
<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
import { install as siteInstall } from '@/routes/sites';
import { update as deployScriptUpdate } from '@/routes/sites/deployScript';
import { store as deploymentsStore } from '@/routes/sites/deployments';
import DeploymentRow from '@/pages/sites/DeploymentRow.vue';
import type { SiteProps, DeploymentItem } from '../Show.vue';

const props = defineProps<{ site: SiteProps; deployments: DeploymentItem[] }>();

const scriptForm = useForm({ deploy_script: props.site.deploy_script });

function saveScript() {
    scriptForm.put(deployScriptUpdate.url(props.site.id));
}

function installRepo() {
    router.post(siteInstall.url(props.site.id));
}

function deployNow() {
    router.post(deploymentsStore.url(props.site.id));
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <section v-if="site.status === 'pending' || site.status === 'key_generated' || site.status === 'failed'" class="flex flex-col gap-3 rounded-xl border p-4">
            <h2 class="font-semibold">Install repository</h2>
            <p class="text-sm text-muted-foreground">
                Add this deploy key to the GitHub repo (Settings → Deploy keys), and the webhook URL
                (Settings → Webhooks, content type JSON) for push-to-deploy. Then install.
            </p>
            <div>
                <div class="text-sm font-medium">Deploy key</div>
                <pre class="mt-1 overflow-x-auto rounded bg-muted p-2 text-xs">{{ site.deploy_key_public ?? 'generating…' }}</pre>
            </div>
            <div>
                <div class="text-sm font-medium">Webhook URL</div>
                <pre class="mt-1 overflow-x-auto rounded bg-muted p-2 text-xs">{{ site.webhook_url }}</pre>
            </div>
            <button @click="installRepo" class="self-start rounded bg-black px-4 py-2 text-sm text-white dark:bg-white dark:text-black">
                Install repository
            </button>
        </section>

        <section v-if="site.status === 'installing' || (site.status === 'failed' && site.provision_log)" class="rounded-xl border p-4">
            <h2 class="font-semibold">Install log</h2>
            <pre class="mt-2 max-h-96 overflow-auto rounded bg-black p-3 text-xs text-green-400">{{ site.provision_log }}</pre>
        </section>

        <template v-if="site.status === 'installed'">
            <section class="flex items-center gap-4 rounded-xl border p-4">
                <button @click="deployNow" class="rounded bg-black px-4 py-2 text-sm text-white dark:bg-white dark:text-black">
                    Deploy now
                </button>
                <div class="text-sm text-muted-foreground">
                    Push to <code>{{ site.branch }}</code> also deploys via webhook.
                </div>
            </section>

            <section class="flex flex-col gap-2 rounded-xl border p-4">
                <h2 class="font-semibold">Deploy script</h2>
                <textarea v-model="scriptForm.deploy_script" rows="8" class="w-full rounded border p-2 font-mono text-xs"></textarea>
                <span v-if="scriptForm.errors.deploy_script" class="text-sm text-red-600">{{ scriptForm.errors.deploy_script }}</span>
                <button @click="saveScript" :disabled="scriptForm.processing" class="self-start rounded border px-4 py-2 text-sm">
                    Save script
                </button>
            </section>

            <section class="flex flex-col gap-2 rounded-xl border p-4">
                <h2 class="font-semibold">Deployments</h2>
                <div v-if="!deployments.length" class="text-sm text-muted-foreground">No deployments yet.</div>
                <DeploymentRow v-for="deployment in deployments" :key="deployment.id" :deployment="deployment" />
            </section>

            <section class="rounded-xl border p-4 text-sm">
                <div class="font-medium">Webhook URL</div>
                <pre class="mt-1 overflow-x-auto rounded bg-muted p-2 text-xs">{{ site.webhook_url }}</pre>
                <div class="mt-3 font-medium">Deploy key</div>
                <pre class="mt-1 overflow-x-auto rounded bg-muted p-2 text-xs">{{ site.deploy_key_public }}</pre>
            </section>
        </template>
    </div>
</template>
```

Note: `DeploymentRow.vue` and the `deployScript`/`deployments` routes are created in Task 8. To keep this task compiling: create a stub `resources/js/pages/sites/DeploymentRow.vue` now (replaced in Task 8):

```vue
<script setup lang="ts">
import type { DeploymentItem } from './Show.vue';
defineProps<{ deployment: DeploymentItem }>();
</script>

<template>
    <div class="rounded border p-2 text-sm">{{ deployment.status }} — {{ deployment.commit_message ?? 'pending' }}</div>
</template>
```

and comment out the two not-yet-existing route imports + their usages (`saveScript`, `deployNow` bodies → `console.log`) — uncomment in Task 8.

- [ ] **Step 3: Verify + commit**

Run: `php artisan wayfinder:generate --no-interaction`, then `npm run build` → no TS errors.
Manual: open the site created in Task 4 → App tab shows deploy key + webhook URL; click "Install repository" (fake shell + sync queue) → status flips to `installed`, install log shows `[fake-shell]` lines.
Run: `vendor/bin/pint --dirty --format agent` (if any PHP touched)
```bash
git add -A && git commit -m "feat: site detail page with install flow and app tab"
```

---

### Task 8: Deployments — job, controller, live log UI

**Files:**
- Create: `app/Jobs/DeploySite.php`
- Create: `app/Http/Controllers/DeploymentController.php`
- Create: `app/Http/Controllers/DeployScriptController.php`
- Replace: `resources/js/pages/sites/DeploymentRow.vue`
- Modify: `routes/web.php`, `resources/js/pages/sites/tabs/AppTab.vue` (uncomment Task 7 stubs)

- [ ] **Step 1: Create `app/Jobs/DeploySite.php`**

Run `php artisan make:job DeploySite --no-interaction`, then:

```php
<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Services\ShellRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class DeploySite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public Deployment $deployment)
    {
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('deploy-site:'.$this->deployment->site_id))
                ->releaseAfter(30)
                ->expireAfter(1800),
        ];
    }

    public function handle(ShellRunner $shell): void
    {
        $deployment = $this->deployment;
        $site = $deployment->site;

        $deployment->update(['status' => DeploymentStatus::Running, 'started_at' => now()]);

        $script = "set -e\n".$site->deploy_script;

        $result = $shell->run(
            $script,
            cwd: $site->root_path,
            onOutput: fn (string $chunk) => $deployment->appendOutput($chunk),
        );

        $commit = $shell->run("git log -1 --pretty=format:'%H|%s'", cwd: $site->root_path);

        if ($commit->successful() && str_contains($commit->output, '|')) {
            [$hash, $message] = explode('|', trim($commit->output), 2);
            $deployment->fill(['commit_hash' => $hash, 'commit_message' => $message]);
        }

        $deployment->fill([
            'status' => $result->successful() ? DeploymentStatus::Success : DeploymentStatus::Failed,
            'finished_at' => now(),
        ])->save();
    }
}
```

- [ ] **Step 2: Create `app/Http/Controllers/DeploymentController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\SiteStatus;
use App\Jobs\DeploySite;
use App\Models\Deployment;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class DeploymentController extends Controller
{
    public function store(Site $site): RedirectResponse
    {
        abort_unless($site->status === SiteStatus::Installed, 422, 'Site is not installed.');

        $deployment = $site->deployments()->create(['trigger' => 'manual']);

        DeploySite::dispatch($deployment);

        return back()->with('success', 'Deployment queued.');
    }

    public function show(Site $site, Deployment $deployment): JsonResponse
    {
        return response()->json($deployment->only(['id', 'status', 'output', 'commit_hash', 'commit_message', 'finished_at']));
    }
}
```

- [ ] **Step 3: Create `app/Http/Controllers/DeployScriptController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeployScriptController extends Controller
{
    public function __invoke(Request $request, Site $site): RedirectResponse
    {
        $validated = $request->validate([
            'deploy_script' => ['required', 'string', 'max:10000'],
        ]);

        $site->update(['deploy_script' => $validated['deploy_script']]);

        return back()->with('success', 'Deploy script saved.');
    }
}
```

- [ ] **Step 4: Add routes inside the auth group**

```php
Route::post('sites/{site}/deployments', [DeploymentController::class, 'store'])->name('sites.deployments.store');
Route::get('sites/{site}/deployments/{deployment}', [DeploymentController::class, 'show'])->name('sites.deployments.show')->scopeBindings();
Route::put('sites/{site}/deploy-script', DeployScriptController::class)->name('sites.deploy-script.update');
```

Check the generated Wayfinder import paths after `php artisan wayfinder:generate` (`resources/js/routes/sites/deployments/index.ts` and `resources/js/routes/sites/deployScript/index.ts` or similar — adjust the imports in AppTab/DeploymentRow to the actual generated paths).

- [ ] **Step 5: Replace `resources/js/pages/sites/DeploymentRow.vue`**

Expandable row; while the deployment is active it fetches the JSON log endpoint every 2s.

```vue
<script setup lang="ts">
import { ref, onUnmounted } from 'vue';
import { show as deploymentShow } from '@/routes/sites/deployments';
import type { DeploymentItem } from './Show.vue';

const props = defineProps<{ deployment: DeploymentItem }>();

const expanded = ref(false);
const log = ref<string>('');
const liveStatus = ref(props.deployment.status);
let timer: ReturnType<typeof setInterval> | null = null;

async function fetchLog() {
    const response = await fetch(deploymentShow.url([props.deployment.site_id, props.deployment.id]), {
        headers: { Accept: 'application/json' },
    });
    const data = await response.json();
    log.value = data.output ?? '';
    liveStatus.value = data.status;

    if (data.status !== 'pending' && data.status !== 'running') {
        stopPolling();
    }
}

function stopPolling() {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
}

function toggle() {
    expanded.value = !expanded.value;

    if (expanded.value) {
        fetchLog();
        timer = setInterval(fetchLog, 2000);
    } else {
        stopPolling();
    }
}

onUnmounted(stopPolling);
</script>

<template>
    <div class="rounded border">
        <button @click="toggle" class="flex w-full items-center gap-3 p-2 text-left text-sm">
            <span
                class="rounded px-2 py-0.5 text-xs"
                :class="{
                    'bg-green-100 text-green-800': liveStatus === 'success',
                    'bg-red-100 text-red-800': liveStatus === 'failed',
                    'bg-yellow-100 text-yellow-800': liveStatus === 'running' || liveStatus === 'pending',
                }"
            >
                {{ liveStatus }}
            </span>
            <span class="font-mono text-xs">{{ deployment.commit_hash?.slice(0, 7) ?? '—' }}</span>
            <span class="flex-1 truncate">{{ deployment.commit_message ?? '' }}</span>
            <span class="text-xs text-muted-foreground">{{ deployment.trigger }} · {{ new Date(deployment.created_at).toLocaleString() }}</span>
        </button>
        <pre v-if="expanded" class="max-h-96 overflow-auto border-t bg-black p-3 text-xs text-green-400">{{ log || 'no output yet…' }}</pre>
    </div>
</template>
```

- [ ] **Step 6: Un-stub AppTab**

Uncomment the `deployScript`/`deployments` route imports and restore the real `saveScript`/`deployNow` bodies from Task 7's listing.

- [ ] **Step 7: Verify + commit**

Run: `php artisan wayfinder:generate --no-interaction`, `npm run build`.
Manual: on the installed fake site click "Deploy now" → deployment appears, expand it → `[fake-shell]` log lines, status `success`. Edit deploy script → save → flash appears.
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add -A && git commit -m "feat: deployments with live log polling and deploy script editor"
```

---

### Task 9: GitHub webhook endpoint

**Files:**
- Create: `app/Http/Controllers/WebhookDeployController.php`
- Modify: `routes/web.php`, `bootstrap/app.php` (CSRF exemption)

- [ ] **Step 1: Create `app/Http/Controllers/WebhookDeployController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\SiteStatus;
use App\Jobs\DeploySite;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookDeployController extends Controller
{
    public function __invoke(Request $request, Site $site, string $token): JsonResponse
    {
        abort_unless(hash_equals($site->webhook_token, $token), 404);

        if ($site->status !== SiteStatus::Installed) {
            return response()->json(['status' => 'ignored', 'reason' => 'site not installed'], 422);
        }

        if (! $site->auto_deploy) {
            return response()->json(['status' => 'ignored', 'reason' => 'auto-deploy disabled']);
        }

        $ref = $request->input('ref');

        if (is_string($ref) && $ref !== 'refs/heads/'.$site->branch) {
            return response()->json(['status' => 'ignored', 'reason' => 'branch mismatch']);
        }

        $deployment = $site->deployments()->create(['trigger' => 'webhook']);

        DeploySite::dispatch($deployment);

        return response()->json(['status' => 'queued', 'deployment_id' => $deployment->id]);
    }
}
```

- [ ] **Step 2: Add the route (outside the auth group) in `routes/web.php`**

```php
Route::post('webhook/deploy/{site}/{token}', WebhookDeployController::class)->name('webhook.deploy');
```

- [ ] **Step 3: Exempt the webhook from CSRF in `bootstrap/app.php`**

Inside the existing `->withMiddleware(function (Middleware $middleware) { ... })` callback add:

```php
$middleware->validateCsrfTokens(except: [
    'webhook/deploy/*',
]);
```

- [ ] **Step 4: Verify + commit**

Run (PowerShell, replace token from `php artisan tinker --execute 'echo App\Models\Site::first()->webhook_token;'`):
```
Invoke-RestMethod -Method Post -Uri "http://localhost:8000/webhook/deploy/1/<token>" -ContentType 'application/json' -Body '{"ref":"refs/heads/main"}'
```
Expected: `{"status":"queued",...}` and a new webhook-triggered deployment on the site page. A wrong token returns 404.
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add -A && git commit -m "feat: github push webhook triggers deployments"
```

---

### Task 10: .env editor

**Files:**
- Create: `app/Services/EnvFileManager.php`
- Create: `app/Http/Controllers/EnvFileController.php`
- Create: `resources/js/pages/sites/tabs/EnvTab.vue`
- Modify: `routes/web.php`, `app/Http/Controllers/SiteController.php` (uncomment `envContent`), `resources/js/pages/sites/Show.vue`

- [ ] **Step 1: Create `app/Services/EnvFileManager.php`**

In fake mode the "site .env" lives under `storage/app/fake-sites/` so the editor works on Windows.

```php
<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\File;

class EnvFileManager
{
    public function __construct(private ShellRunner $shell)
    {
    }

    public function read(Site $site): string
    {
        $path = $this->path($site);

        return File::exists($path) ? File::get($path) : '';
    }

    public function write(Site $site, string $content): void
    {
        $path = $this->path($site);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);

        $this->shell->run(config('forge.php_binary').' artisan optimize:clear', cwd: $site->root_path);
    }

    private function path(Site $site): string
    {
        return $this->shell->isFake()
            ? storage_path("app/fake-sites/{$site->domain}.env")
            : $site->root_path.'/.env';
    }
}
```

- [ ] **Step 2: Create `app/Http/Controllers/EnvFileController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\EnvFileManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EnvFileController extends Controller
{
    public function __invoke(Request $request, Site $site, EnvFileManager $envFiles): RedirectResponse
    {
        $validated = $request->validate([
            'content' => ['present', 'string', 'max:20000'],
        ]);

        $envFiles->write($site, $validated['content']);

        return back()->with('success', '.env saved and caches cleared.');
    }
}
```

- [ ] **Step 3: Route + controller prop**

Route inside the auth group:
```php
Route::put('sites/{site}/env', EnvFileController::class)->name('sites.env.update');
```

In `SiteController::show`, uncomment/add:
```php
'envContent' => Inertia::optional(fn () => app(\App\Services\EnvFileManager::class)->read($site)),
```

- [ ] **Step 4: Create `resources/js/pages/sites/tabs/EnvTab.vue`**

The env content is an optional prop — request it on tab mount via partial reload.

```vue
<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
import { onMounted, watch } from 'vue';
import { update as envUpdate } from '@/routes/sites/env';
import type { SiteProps } from '../Show.vue';

const props = defineProps<{ site: SiteProps; envContent?: string }>();

const form = useForm({ content: props.envContent ?? '' });

onMounted(() => {
    if (props.envContent === undefined) {
        router.reload({ only: ['envContent'] });
    }
});

watch(
    () => props.envContent,
    (value) => {
        if (value !== undefined && !form.isDirty) {
            form.defaults({ content: value }).reset();
        }
    },
);

function save() {
    form.put(envUpdate.url(props.site.id));
}
</script>

<template>
    <div class="flex flex-col gap-2 rounded-xl border p-4">
        <h2 class="font-semibold">.env</h2>
        <div v-if="envContent === undefined" class="h-64 animate-pulse rounded bg-muted"></div>
        <template v-else>
            <textarea v-model="form.content" rows="20" class="w-full rounded border p-2 font-mono text-xs"></textarea>
            <span v-if="form.errors.content" class="text-sm text-red-600">{{ form.errors.content }}</span>
            <button @click="save" :disabled="form.processing" class="self-start rounded bg-black px-4 py-2 text-sm text-white dark:bg-white dark:text-black">
                Save .env
            </button>
        </template>
    </div>
</template>
```

- [ ] **Step 5: Wire the tab in `Show.vue`**

```vue
import EnvTab from './tabs/EnvTab.vue';
// in template, replacing part of the v-else placeholder:
<EnvTab v-else-if="currentTab === 'env'" :site="site" :envContent="envContent" />
```

- [ ] **Step 6: Verify + commit**

Run: `php artisan wayfinder:generate --no-interaction`, `npm run build`.
Manual: Env tab → skeleton, then editor; type `FOO=bar`, save → success flash; reload page → content persisted (in `storage/app/fake-sites/`).
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add -A && git commit -m "feat: per-site .env editor"
```

---

### Task 11: SSL via certbot

**Files:**
- Create: `app/Jobs/IssueCertificate.php`
- Create: `app/Http/Controllers/SslController.php`
- Create: `resources/js/pages/sites/tabs/SslTab.vue`
- Modify: `routes/web.php`, `resources/js/pages/sites/Show.vue`

- [ ] **Step 1: Create `app/Jobs/IssueCertificate.php`**

Run `php artisan make:job IssueCertificate --no-interaction`, then:

```php
<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\ShellRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IssueCertificate implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public Site $site)
    {
    }

    public function handle(ShellRunner $shell): void
    {
        $site = $this->site;
        $site->appendProvisionLog("\n--- Issuing SSL certificate ---\n");

        $result = $shell->run(sprintf(
            'sudo certbot --apache --non-interactive --agree-tos --redirect -m %s -d %s',
            escapeshellarg((string) config('forge.certbot_email')),
            escapeshellarg($site->domain),
        ), onOutput: fn (string $chunk) => $site->appendProvisionLog($chunk));

        if ($result->successful()) {
            // Certbot's own systemd timer renews; 90 days is Let's Encrypt's validity.
            $site->update(['ssl_enabled' => true, 'ssl_expires_at' => now()->addDays(90)]);
            $site->appendProvisionLog("\nSSL enabled.\n");
        } else {
            $site->appendProvisionLog("\nSSL ISSUANCE FAILED.\n");
        }
    }
}
```

- [ ] **Step 2: Create `app/Http/Controllers/SslController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\SiteStatus;
use App\Jobs\IssueCertificate;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;

class SslController extends Controller
{
    public function __invoke(Site $site): RedirectResponse
    {
        abort_unless($site->status === SiteStatus::Installed, 422, 'Install the site first.');

        if (blank(config('forge.certbot_email'))) {
            return back()->with('error', 'Set FORGE_CERTBOT_EMAIL in the panel .env first.');
        }

        IssueCertificate::dispatch($site);

        return back()->with('success', 'Certificate issuance started — watch the log below.');
    }
}
```

- [ ] **Step 3: Route inside the auth group**

```php
Route::post('sites/{site}/ssl', SslController::class)->name('sites.ssl.store');
```

- [ ] **Step 4: Create `resources/js/pages/sites/tabs/SslTab.vue`**

```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { store as sslStore } from '@/routes/sites/ssl';
import type { SiteProps } from '../Show.vue';

const props = defineProps<{ site: SiteProps }>();

function issue() {
    router.post(sslStore.url(props.site.id));
}
</script>

<template>
    <div class="flex flex-col gap-4 rounded-xl border p-4">
        <h2 class="font-semibold">SSL</h2>

        <div v-if="site.ssl_enabled" class="text-sm">
            <span class="rounded bg-green-100 px-2 py-0.5 text-green-800">Active</span>
            <span class="ml-2 text-muted-foreground">
                Expires ~{{ site.ssl_expires_at ? new Date(site.ssl_expires_at).toLocaleDateString() : '—' }} (auto-renewed by certbot)
            </span>
        </div>
        <div v-else class="text-sm text-muted-foreground">
            No certificate. DNS for <code>{{ site.domain }}</code> must point at this server before issuing.
        </div>

        <button @click="issue" class="self-start rounded bg-black px-4 py-2 text-sm text-white dark:bg-white dark:text-black">
            {{ site.ssl_enabled ? 'Re-issue certificate' : 'Issue certificate (Let\'s Encrypt)' }}
        </button>

        <pre v-if="site.provision_log" class="max-h-96 overflow-auto rounded bg-black p-3 text-xs text-green-400">{{ site.provision_log }}</pre>
    </div>
</template>
```

- [ ] **Step 5: Wire the tab in `Show.vue`**

```vue
import SslTab from './tabs/SslTab.vue';
<SslTab v-else-if="currentTab === 'ssl'" :site="site" />
```

- [ ] **Step 6: Verify + commit**

Run: `php artisan wayfinder:generate --no-interaction`, `npm run build`.
Manual: set `FORGE_CERTBOT_EMAIL=test@example.com` in `.env`; SSL tab → Issue → fake log line appears, badge flips to Active.
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add -A && git commit -m "feat: lets encrypt ssl issuance via certbot"
```

---

### Task 12: Queue workers (systemd units)

**Files:**
- Create: `resources/views/server/worker-unit.blade.php`
- Create: `app/Services/WorkerManager.php`
- Create: `app/Http/Controllers/WorkerController.php`
- Create: `resources/js/pages/sites/tabs/WorkersTab.vue`
- Modify: `routes/web.php`, `resources/js/pages/sites/Show.vue`

- [ ] **Step 1: Create `resources/views/server/worker-unit.blade.php`**

```blade
[Unit]
Description=Forge worker {{ $worker->id }} for {{ $site->domain }}
After=network.target

[Service]
User=forge
Restart=always
RestartSec=3
WorkingDirectory={{ $site->root_path }}
ExecStart={{ config('forge.php_binary') }} artisan {{ $worker->command }}

[Install]
WantedBy=multi-user.target
```

- [ ] **Step 2: Create `app/Services/WorkerManager.php`**

```php
<?php

namespace App\Services;

use App\Models\Worker;

class WorkerManager
{
    public function __construct(private ShellRunner $shell)
    {
    }

    public function install(Worker $worker): void
    {
        $unit = view('server.worker-unit', ['worker' => $worker, 'site' => $worker->site])->render();

        $this->shell->writeAsRoot($unit, "/etc/systemd/system/{$worker->unitName()}");
        $this->shell->runOrFail('sudo systemctl daemon-reload');
        $this->shell->runOrFail("sudo systemctl enable --now {$worker->unitName()}");
    }

    public function restart(Worker $worker): void
    {
        $this->shell->runOrFail("sudo systemctl restart {$worker->unitName()}");
    }

    public function remove(Worker $worker): void
    {
        $this->shell->run("sudo systemctl disable --now {$worker->unitName()}");
        $this->shell->run("sudo rm /etc/systemd/system/{$worker->unitName()}");
        $this->shell->run('sudo systemctl daemon-reload');
    }
}
```

- [ ] **Step 3: Create `app/Http/Controllers/WorkerController.php`**

The command is validated to a safe artisan-argument charset because it lands in a systemd `ExecStart` line.

```php
<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\Worker;
use App\Services\WorkerManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkerController extends Controller
{
    public function store(Request $request, Site $site, WorkerManager $workers): RedirectResponse
    {
        $validated = $request->validate([
            'command' => ['required', 'string', 'max:200', 'regex:/^queue:work[a-zA-Z0-9:_=. \-]*$/'],
        ]);

        $worker = $site->workers()->create([
            'command' => $validated['command'],
            'status' => 'running',
        ]);

        $workers->install($worker);

        return back()->with('success', 'Worker created and started.');
    }

    public function restart(Site $site, Worker $worker, WorkerManager $workers): RedirectResponse
    {
        $workers->restart($worker);

        return back()->with('success', 'Worker restarted.');
    }

    public function destroy(Site $site, Worker $worker, WorkerManager $workers): RedirectResponse
    {
        $workers->remove($worker);
        $worker->delete();

        return back()->with('success', 'Worker removed.');
    }
}
```

- [ ] **Step 4: Routes inside the auth group**

```php
Route::post('sites/{site}/workers', [WorkerController::class, 'store'])->name('sites.workers.store');
Route::post('sites/{site}/workers/{worker}/restart', [WorkerController::class, 'restart'])->name('sites.workers.restart')->scopeBindings();
Route::delete('sites/{site}/workers/{worker}', [WorkerController::class, 'destroy'])->name('sites.workers.destroy')->scopeBindings();
```

- [ ] **Step 5: Create `resources/js/pages/sites/tabs/WorkersTab.vue`**

```vue
<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
import { store as workersStore, restart as workerRestart, destroy as workerDestroy } from '@/routes/sites/workers';
import type { SiteProps, WorkerItem } from '../Show.vue';

const props = defineProps<{ site: SiteProps; workers: WorkerItem[] }>();

const form = useForm({ command: 'queue:work --tries=3' });

function create() {
    form.post(workersStore.url(props.site.id), { onSuccess: () => form.reset() });
}

function restart(worker: WorkerItem) {
    router.post(workerRestart.url([props.site.id, worker.id]));
}

function remove(worker: WorkerItem) {
    if (confirm('Remove this worker?')) {
        router.delete(workerDestroy.url([props.site.id, worker.id]));
    }
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <form @submit.prevent="create" class="flex items-end gap-2 rounded-xl border p-4">
            <label class="flex-1 text-sm">
                Artisan command
                <input v-model="form.command" class="mt-1 w-full rounded border px-2 py-1.5 font-mono text-xs" />
                <span v-if="form.errors.command" class="text-sm text-red-600">{{ form.errors.command }}</span>
            </label>
            <button type="submit" :disabled="form.processing" class="rounded bg-black px-4 py-2 text-sm text-white dark:bg-white dark:text-black">
                Add worker
            </button>
        </form>

        <div class="flex flex-col gap-2">
            <div v-for="worker in workers" :key="worker.id" class="flex items-center gap-3 rounded border p-3 text-sm">
                <code class="flex-1">php artisan {{ worker.command }}</code>
                <span class="text-xs text-muted-foreground">{{ worker.status }}</span>
                <button @click="restart(worker)" class="rounded border px-3 py-1">Restart</button>
                <button @click="remove(worker)" class="rounded border border-red-300 px-3 py-1 text-red-700">Delete</button>
            </div>
            <div v-if="!workers.length" class="text-sm text-muted-foreground">No workers.</div>
        </div>
    </div>
</template>
```

- [ ] **Step 6: Wire the tab in `Show.vue`**

```vue
import WorkersTab from './tabs/WorkersTab.vue';
<WorkersTab v-else-if="currentTab === 'workers'" :site="site" :workers="workers" />
```

Also add `workers` to the `usePoll` `only` list: `usePoll(3000, { only: ['site', 'deployments', 'workers'] });`

- [ ] **Step 7: Verify + commit**

Run: `php artisan wayfinder:generate --no-interaction`, `npm run build`.
Manual: Workers tab → add worker → appears in list; restart and delete work (fake shell logs).
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add -A && git commit -m "feat: per-site queue workers as systemd units"
```

---

### Task 13: Scheduler toggle

**Files:**
- Create: `app/Services/SchedulerManager.php`
- Create: `app/Http/Controllers/SchedulerController.php`
- Create: `resources/js/pages/sites/tabs/SchedulerTab.vue`
- Modify: `routes/web.php`, `resources/js/pages/sites/Show.vue`

- [ ] **Step 1: Create `app/Services/SchedulerManager.php`**

```php
<?php

namespace App\Services;

use App\Models\Site;

class SchedulerManager
{
    public function __construct(private ShellRunner $shell)
    {
    }

    public function enable(Site $site): void
    {
        $php = config('forge.php_binary');
        $cron = "* * * * * forge {$php} {$site->root_path}/artisan schedule:run >> /dev/null 2>&1\n";

        $this->shell->writeAsRoot($cron, $this->cronPath($site));
        $site->update(['has_scheduler' => true]);
    }

    public function disable(Site $site): void
    {
        $this->shell->run('sudo rm '.escapeshellarg($this->cronPath($site)));
        $site->update(['has_scheduler' => false]);
    }

    private function cronPath(Site $site): string
    {
        return "/etc/cron.d/forge-site-{$site->id}";
    }
}
```

- [ ] **Step 2: Create `app/Http/Controllers/SchedulerController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\SchedulerManager;
use Illuminate\Http\RedirectResponse;

class SchedulerController extends Controller
{
    public function __invoke(Site $site, SchedulerManager $scheduler): RedirectResponse
    {
        abort_unless($site->status === SiteStatus::Installed, 422, 'Install the site first.');

        $site->has_scheduler ? $scheduler->disable($site) : $scheduler->enable($site);

        return back()->with('success', $site->refresh()->has_scheduler ? 'Scheduler enabled.' : 'Scheduler disabled.');
    }
}
```

- [ ] **Step 3: Route inside the auth group**

```php
Route::put('sites/{site}/scheduler', SchedulerController::class)->name('sites.scheduler.update');
```

- [ ] **Step 4: Create `resources/js/pages/sites/tabs/SchedulerTab.vue`**

```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { update as schedulerUpdate } from '@/routes/sites/scheduler';
import type { SiteProps } from '../Show.vue';

const props = defineProps<{ site: SiteProps }>();

function toggle() {
    router.put(schedulerUpdate.url(props.site.id));
}
</script>

<template>
    <div class="flex flex-col gap-3 rounded-xl border p-4">
        <h2 class="font-semibold">Scheduler</h2>
        <p class="text-sm text-muted-foreground">
            Runs <code>php artisan schedule:run</code> every minute via a cron entry in <code>/etc/cron.d</code>.
        </p>
        <div class="text-sm">
            Status:
            <span :class="site.has_scheduler ? 'text-green-700' : 'text-muted-foreground'">
                {{ site.has_scheduler ? 'enabled' : 'disabled' }}
            </span>
        </div>
        <button @click="toggle" class="self-start rounded bg-black px-4 py-2 text-sm text-white dark:bg-white dark:text-black">
            {{ site.has_scheduler ? 'Disable scheduler' : 'Enable scheduler' }}
        </button>
    </div>
</template>
```

- [ ] **Step 5: Wire the tab in `Show.vue`** (this removes the last `v-else` placeholder)

```vue
import SchedulerTab from './tabs/SchedulerTab.vue';
<SchedulerTab v-else-if="currentTab === 'scheduler'" :site="site" />
```

- [ ] **Step 6: Verify + commit**

Run: `php artisan wayfinder:generate --no-interaction`, `npm run build`.
Manual: toggle scheduler on/off → status flips, flashes shown.
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add -A && git commit -m "feat: per-site scheduler cron toggle"
```

---

### Task 14: Managed MySQL databases

**Files:**
- Create: `app/Services/ManagedDatabaseManager.php`
- Create: `app/Http/Controllers/DatabaseController.php`
- Create: `resources/js/pages/databases/Index.vue`
- Modify: `routes/web.php`, `resources/js/components/AppSidebar.vue` (Databases nav item)

- [ ] **Step 1: Create `app/Services/ManagedDatabaseManager.php`**

Identifiers can't use bindings; the controller regex-validates them, and the password goes through PDO quoting. Fake mode skips MySQL entirely.

```php
<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class ManagedDatabaseManager
{
    public function create(string $name, string $username, string $password): void
    {
        if (config('forge.fake_shell')) {
            return;
        }

        $connection = $this->connection();

        $connection->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $connection->statement("CREATE USER '{$username}'@'localhost' IDENTIFIED BY ".$connection->getPdo()->quote($password));
        $connection->statement("GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$username}'@'localhost'");
        $connection->statement('FLUSH PRIVILEGES');
    }

    public function drop(string $name, string $username): void
    {
        if (config('forge.fake_shell')) {
            return;
        }

        $connection = $this->connection();

        $connection->statement("DROP DATABASE IF EXISTS `{$name}`");
        $connection->statement("DROP USER IF EXISTS '{$username}'@'localhost'");
        $connection->statement('FLUSH PRIVILEGES');
    }

    private function connection(): Connection
    {
        return DB::connection('forge_mysql');
    }
}
```

- [ ] **Step 2: Create `app/Http/Controllers/DatabaseController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\ManagedDatabase;
use App\Services\ManagedDatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('databases/Index', [
            'databases' => ManagedDatabase::query()->latest()->get(),
        ]);
    }

    public function store(Request $request, ManagedDatabaseManager $manager): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'unique:managed_databases,name', 'regex:/^[a-z][a-z0-9_]{2,31}$/'],
            'username' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,31}$/'],
        ]);

        $password = Str::password(24, symbols: false);

        $manager->create($validated['name'], $validated['username'], $password);

        ManagedDatabase::create($validated);

        return back()
            ->with('success', "Database created. Password (shown once): ")
            ->with('password', $password);
    }

    public function destroy(ManagedDatabase $database, ManagedDatabaseManager $manager): RedirectResponse
    {
        $manager->drop($database->name, $database->username);
        $database->delete();

        return back()->with('success', 'Database and user dropped.');
    }
}
```

- [ ] **Step 3: Route inside the auth group**

```php
Route::resource('databases', DatabaseController::class)->only(['index', 'store', 'destroy']);
```

- [ ] **Step 4: Create `resources/js/pages/databases/Index.vue`**

```vue
<script setup lang="ts">
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { index as databasesIndex, store as databasesStore, destroy as databasesDestroy } from '@/routes/databases';

interface DatabaseItem {
    id: number;
    name: string;
    username: string;
    created_at: string;
}

defineProps<{ databases: DatabaseItem[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Databases', href: databasesIndex() }] },
});

const page = usePage();

const form = useForm({ name: '', username: '' });

function create() {
    form.post(databasesStore.url(), { onSuccess: () => form.reset() });
}

function remove(database: DatabaseItem) {
    if (confirm(`Drop database "${database.name}" and its user? This is permanent.`)) {
        router.delete(databasesDestroy.url(database.id));
    }
}
</script>

<template>
    <Head title="Databases" />

    <div class="flex flex-col gap-6 p-4">
        <div v-if="(page.props as any).flash?.password" class="rounded border border-yellow-300 bg-yellow-50 p-3 text-sm text-yellow-900">
            {{ (page.props as any).flash.success }}
            <code class="font-bold">{{ (page.props as any).flash.password }}</code>
            — copy it now, it is not stored.
        </div>
        <div v-else-if="(page.props as any).flash?.success" class="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-800">
            {{ (page.props as any).flash.success }}
        </div>

        <form @submit.prevent="create" class="flex items-end gap-2 rounded-xl border p-4 md:max-w-xl">
            <label class="flex-1 text-sm">
                Database name
                <input v-model="form.name" class="mt-1 w-full rounded border px-2 py-1.5" />
                <span v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</span>
            </label>
            <label class="flex-1 text-sm">
                Username
                <input v-model="form.username" class="mt-1 w-full rounded border px-2 py-1.5" />
                <span v-if="form.errors.username" class="text-sm text-red-600">{{ form.errors.username }}</span>
            </label>
            <button type="submit" :disabled="form.processing" class="rounded bg-black px-4 py-2 text-sm text-white dark:bg-white dark:text-black">
                Create
            </button>
        </form>

        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b">
                    <th class="py-2">Name</th>
                    <th>Username</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="database in databases" :key="database.id" class="border-b">
                    <td class="py-2 font-mono">{{ database.name }}</td>
                    <td class="font-mono">{{ database.username }}</td>
                    <td>{{ new Date(database.created_at).toLocaleDateString() }}</td>
                    <td class="text-right">
                        <button @click="remove(database)" class="rounded border border-red-300 px-3 py-1 text-red-700">Drop</button>
                    </td>
                </tr>
                <tr v-if="!databases.length">
                    <td colspan="4" class="py-4 text-muted-foreground">No managed databases.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
```

- [ ] **Step 5: Add the Databases nav item to `AppSidebar.vue`** (deferred from Task 4)

```ts
import { index as databasesIndex } from '@/routes/databases';
{ title: 'Databases', href: databasesIndex(), icon: Database },
```

- [ ] **Step 6: Verify + commit**

Run: `php artisan wayfinder:generate --no-interaction`, `npm run build`.
Manual: create a database (fake mode skips MySQL) → yellow one-time-password banner; drop it after confirm.
Run: `vendor/bin/pint --dirty --format agent`
```bash
git add -A && git commit -m "feat: managed mysql databases with one-time passwords"
```

---

### Task 15: Site teardown, registration lock, server setup script, final verification

**Files:**
- Modify: `app/Http/Controllers/SiteController.php` (full destroy)
- Modify: `app/Actions/Fortify/CreateNewUser.php` (single-admin lock)
- Create: `docs/server-setup.sh`

- [ ] **Step 1: Full site teardown in `SiteController::destroy`**

Replace the destroy method (and add the imports):

```php
public function destroy(
    Site $site,
    \App\Services\ApacheManager $apache,
    \App\Services\WorkerManager $workers,
    \App\Services\SchedulerManager $scheduler,
): RedirectResponse {
    foreach ($site->workers as $worker) {
        $workers->remove($worker);
    }

    if ($site->has_scheduler) {
        $scheduler->disable($site);
    }

    $apache->removeVhost($site);

    // Site files in root_path are intentionally left on disk (v1).
    $site->delete();

    return to_route('sites.index')->with('success', 'Site removed from the panel. Files were left on disk.');
}
```

Move the `\App\Services\...` FQCNs to proper `use` imports per project style.

- [ ] **Step 2: Lock registration to a single admin in `app/Actions/Fortify/CreateNewUser.php`**

At the top of the `create()` method body add:

```php
if (\App\Models\User::query()->exists()) {
    throw \Illuminate\Validation\ValidationException::withMessages([
        'email' => __('Registration is disabled — this panel already has an admin.'),
    ]);
}
```

(with proper `use` imports).

- [ ] **Step 3: Create `docs/server-setup.sh`**

One-time root provisioning script for the Ubuntu server:

```bash
#!/usr/bin/env bash
# One-time setup for the Forge panel host. Run as root on Ubuntu 22.04/24.04:
#   sudo bash server-setup.sh
set -euo pipefail

FORGE_USER=forge
FORGE_HOME=/home/forge

# --- system packages -------------------------------------------------------
apt-get update
apt-get install -y apache2 libapache2-mod-php php-cli php-mysql php-xml php-curl \
    php-mbstring php-zip php-sqlite3 composer git certbot python3-certbot-apache \
    mysql-server
a2enmod rewrite
systemctl reload apache2

# --- forge user ------------------------------------------------------------
if ! id "$FORGE_USER" &>/dev/null; then
    useradd --create-home --shell /bin/bash "$FORGE_USER"
fi
mkdir -p "$FORGE_HOME/.ssh"
touch "$FORGE_HOME/.ssh/config"
chown -R "$FORGE_USER:$FORGE_USER" "$FORGE_HOME/.ssh"
chmod 700 "$FORGE_HOME/.ssh"

# Pre-trust GitHub's host keys so git clone doesn't prompt.
sudo -u "$FORGE_USER" bash -c "ssh-keyscan github.com >> $FORGE_HOME/.ssh/known_hosts 2>/dev/null"

# --- sudoers whitelist -----------------------------------------------------
cat > /etc/sudoers.d/forge-panel <<'SUDOERS'
forge ALL=(root) NOPASSWD: /usr/sbin/a2ensite *
forge ALL=(root) NOPASSWD: /usr/sbin/a2dissite *
forge ALL=(root) NOPASSWD: /usr/sbin/apache2ctl configtest
forge ALL=(root) NOPASSWD: /usr/bin/systemctl reload apache2
forge ALL=(root) NOPASSWD: /usr/bin/systemctl daemon-reload
forge ALL=(root) NOPASSWD: /usr/bin/systemctl start forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/systemctl stop forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/systemctl restart forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/systemctl enable --now forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/systemctl disable --now forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/certbot *
forge ALL=(root) NOPASSWD: /usr/bin/cp * /etc/apache2/sites-available/*
forge ALL=(root) NOPASSWD: /usr/bin/cp * /etc/systemd/system/forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/cp * /etc/cron.d/forge-site-*
forge ALL=(root) NOPASSWD: /usr/bin/chmod 644 /etc/apache2/sites-available/*
forge ALL=(root) NOPASSWD: /usr/bin/chmod 644 /etc/systemd/system/forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/chmod 644 /etc/cron.d/forge-site-*
forge ALL=(root) NOPASSWD: /usr/bin/rm /etc/apache2/sites-available/*
forge ALL=(root) NOPASSWD: /usr/bin/rm /etc/systemd/system/forge-worker-*
forge ALL=(root) NOPASSWD: /usr/bin/rm -f /etc/cron.d/forge-site-*
SUDOERS
chmod 440 /etc/sudoers.d/forge-panel
visudo -cf /etc/sudoers.d/forge-panel

# --- privileged mysql user for managed databases ---------------------------
echo
echo "Create the panel's MySQL admin user (put the password in the panel's .env as FORGE_MYSQL_PASSWORD):"
echo "  mysql -e \"CREATE USER 'forge_admin'@'localhost' IDENTIFIED BY '<password>'; GRANT ALL PRIVILEGES ON *.* TO 'forge_admin'@'localhost' WITH GRANT OPTION; FLUSH PRIVILEGES;\""
echo
echo "Then deploy the panel itself into $FORGE_HOME/<panel-domain>, configure its Apache vhost,"
echo "run its queue worker (systemd unit) as the forge user, and set FORGE_FAKE_SHELL=false."
```

- [ ] **Step 4: Final verification**

Run: `php artisan migrate:fresh --no-interaction` → clean run.
Run: `php artisan route:list --except-vendor` → all routes from tasks 4–14 present.
Run: `vendor/bin/pint --format agent` → clean.
Run: `npm run build` → clean.
Manual click-through with `FORGE_FAKE_SHELL=true`, `QUEUE_CONNECTION=sync`: register first user → second registration blocked; create site → key + webhook shown → install → installed; deploy now → success log; edit deploy script; edit .env; issue SSL; add/restart/remove worker; toggle scheduler; create/drop database; delete site.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: site teardown, single-admin lock, server setup script"
```

---

## Self-review notes

- **Spec coverage:** system/privileges (T1, T3, T15), data model (T2), site lifecycle + deploy key + vhost + install (T4–T7), deployments + webhook (T8–T9), env editor (T10), SSL (T11), workers (T12), scheduler (T13), databases (T14), teardown + registration lock + provisioning script (T15). `processes` column for workers dropped (YAGNI — spec says v1 is 1 process).
- **Cross-task stubs:** Task 4 references `EnvFileManager` (commented until T10); Task 7 stubs `DeploymentRow` + two route imports (resolved in T8); Databases nav item deferred to T14. Each stub's resolution task is named at the stub site.
- **Wayfinder caveat:** generated import paths for nested route names (`sites.deployments.store` → `@/routes/sites/deployments`) should be confirmed against the generated files after `php artisan wayfinder:generate`; adjust imports if the generator uses a different casing/path.
