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
