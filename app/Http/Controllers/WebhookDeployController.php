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

        if (! is_string($ref) || $ref !== 'refs/heads/'.$site->branch) {
            return response()->json(['status' => 'ignored', 'reason' => 'branch mismatch']);
        }

        $deployment = $site->deployments()->create(['trigger' => 'webhook']);

        DeploySite::dispatch($deployment);

        return response()->json(['status' => 'queued', 'deployment_id' => $deployment->id]);
    }
}
