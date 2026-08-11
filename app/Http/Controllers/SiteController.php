<?php

namespace App\Http\Controllers;

use App\Actions\GenerateSiteDeployKey;
use App\Actions\ProvisionGitHubRepository;
use App\Actions\TeardownGitHubRepository;
use App\Http\Requests\StoreSiteRequest;
use App\Jobs\InstallRepository;
use App\Models\Site;
use App\Services\ApacheManager;
use App\Services\EnvFileManager;
use App\Services\GitHub\GitHubApiException;
use App\Services\LogFileManager;
use App\Services\SchedulerManager;
use App\Services\WorkerManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('sites/Index', [
            'sites' => Site::query()->latest()->get(['id', 'domain', 'repository', 'branch', 'status', 'ssl_enabled', 'php_version']),
            'phpVersions' => config('forge.php_versions'),
            'defaultPhpVersion' => config('forge.default_php_version'),
            'githubConnected' => $request->user()->hasGitHubConnection(),
        ]);
    }

    public function store(
        StoreSiteRequest $request,
        GenerateSiteDeployKey $generateKey,
        ProvisionGitHubRepository $provision,
    ): RedirectResponse {
        $rootPath = rtrim(config('forge.sites_path'), '/').'/'.$request->validated('domain');

        $site = Site::create([
            ...$request->validated(),
            'root_path' => $rootPath,
            'webhook_token' => Str::random(48),
            'deploy_script' => Site::defaultDeployScript($rootPath, $request->validated('branch'), $request->validated('php_version')),
        ]);

        try {
            $generateKey->handle($site);
        } catch (\Throwable $exception) {
            report($exception);
            $site->delete();

            return back()->with('error', 'Deploy key generation failed: '.$exception->getMessage());
        }

        $user = $request->user();

        if (! $user->hasGitHubConnection()) {
            return to_route('sites.show', $site)
                ->with('success', 'Site created. Add the deploy key and webhook to GitHub, then install the repository.');
        }

        try {
            $provision->handle($site, $user);
        } catch (GitHubApiException $exception) {
            report($exception);

            return to_route('sites.show', $site)->with('error', $this->provisionFailureMessage($site, $exception));
        }

        InstallRepository::dispatch($site);

        return to_route('sites.show', $site)
            ->with('success', 'Site created — deploy key and webhook installed on GitHub. Installing now.');
    }

    public function show(Request $request, Site $site): Response
    {
        return Inertia::render('sites/Show', [
            'site' => [
                ...$site->only([
                    'id', 'domain', 'repository', 'branch', 'root_path', 'status',
                    'php_version', 'deploy_script', 'auto_deploy', 'deploy_key_public',
                    'ssl_enabled', 'ssl_expires_at', 'has_scheduler', 'provision_log', 'ssl_log',
                ]),
                'webhook_url' => $site->webhookUrl(),
            ],
            'deployments' => $site->deployments()->limit(10)->get([
                'id', 'site_id', 'status', 'trigger', 'commit_hash', 'commit_message', 'created_at', 'finished_at',
            ]),
            'workers' => $site->workers()->get(['id', 'command', 'status']),
            'envContent' => Inertia::optional(fn () => app(EnvFileManager::class)->read($site)),
            'vhostContent' => Inertia::optional(fn () => app(ApacheManager::class)->readVhost($site)),
            'logFiles' => Inertia::optional(fn () => app(LogFileManager::class)->files($site)),
            'logContent' => Inertia::optional(
                fn () => app(LogFileManager::class)->tail($site, $request->query('log')),
            ),
        ]);
    }

    public function destroy(
        Request $request,
        Site $site,
        ApacheManager $apache,
        WorkerManager $workers,
        SchedulerManager $scheduler,
        TeardownGitHubRepository $github,
    ): RedirectResponse {
        try {
            $github->handle($site, $request->user());
        } catch (\Throwable $e) {
            report($e);
        }

        foreach ($site->workers as $worker) {
            $workers->remove($worker);
        }

        if ($site->has_scheduler) {
            try {
                $scheduler->disable($site);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        try {
            $apache->removeVhost($site);
        } catch (\Throwable $e) {
            report($e);
        }

        // Site files in root_path are intentionally left on disk (v1).
        $site->delete();

        return to_route('sites.index')->with('success', 'Site removed from the panel. Files were left on disk.');
    }

    /**
     * Name the step that did not land, so the operator knows which of the two
     * panels on the site page they still need to copy across by hand.
     */
    private function provisionFailureMessage(Site $site, GitHubApiException $exception): string
    {
        if ($exception->status === ProvisionGitHubRepository::INVALID_REPOSITORY_STATUS) {
            return "Site created, but GitHub provisioning could not be started: {$exception->getMessage()} "
                .'Fix the repository below, then add the deploy key and webhook manually and click Install.';
        }

        $missing = $site->github_key_id === null ? 'deploy key' : 'webhook';

        return "Site created, but the {$missing} could not be added to GitHub: {$exception->getMessage()} "
            .'Add it manually below, then click Install.';
    }
}
