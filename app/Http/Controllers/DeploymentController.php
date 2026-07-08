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
