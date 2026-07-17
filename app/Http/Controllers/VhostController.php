<?php

namespace App\Http\Controllers;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\ApacheManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class VhostController extends Controller
{
    public function __invoke(Request $request, Site $site, ApacheManager $apache): RedirectResponse
    {
        abort_unless($site->status === SiteStatus::Installed, 422, 'Install the site first.');

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:20000'],
        ]);

        try {
            $apache->updateVhost($site, $validated['content']);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Apache config saved and reloaded.');
    }
}
