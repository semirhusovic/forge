<?php

namespace App\Http\Controllers;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\LogFileManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class LogFileController extends Controller
{
    public function destroy(Request $request, Site $site, LogFileManager $logs): RedirectResponse
    {
        abort_unless($site->status === SiteStatus::Installed, 422, 'Install the site first.');

        $validated = $request->validate([
            'log' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $name = $logs->clear($site, $validated['log'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Cleared {$name}.");
    }
}
