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
