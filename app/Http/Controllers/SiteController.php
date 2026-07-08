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
            // 'envContent' => Inertia::optional(fn () => app(\App\Services\EnvFileManager::class)->read($site)), // uncommented in Task 10
        ]);
    }

    public function destroy(Site $site): RedirectResponse
    {
        // Full server-side teardown (vhost, workers, cron) is wired in the final task.
        $site->delete();

        return to_route('sites.index')->with('success', 'Site deleted.');
    }
}
