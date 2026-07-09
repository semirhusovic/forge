<?php

namespace App\Http\Controllers;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\Worker;
use App\Services\WorkerManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class WorkerController extends Controller
{
    public function store(Request $request, Site $site, WorkerManager $workers): RedirectResponse
    {
        abort_unless($site->status === SiteStatus::Installed, 422, 'Install the site first.');

        $validated = $request->validate([
            'command' => ['required', 'string', 'max:200', 'regex:/^queue:work( [a-zA-Z0-9:_=. \-]*)?$/D'],
        ]);

        $worker = $site->workers()->create([
            'command' => $validated['command'],
            'status' => 'running',
        ]);

        try {
            $workers->install($worker);
        } catch (RuntimeException $exception) {
            $worker->delete();

            return back()->with('error', 'Worker install failed: '.$exception->getMessage());
        }

        return back()->with('success', 'Worker created and started.');
    }

    public function restart(Site $site, Worker $worker, WorkerManager $workers): RedirectResponse
    {
        try {
            $workers->restart($worker);
        } catch (RuntimeException $exception) {
            return back()->with('error', 'Worker restart failed: '.$exception->getMessage());
        }

        return back()->with('success', 'Worker restarted.');
    }

    public function destroy(Site $site, Worker $worker, WorkerManager $workers): RedirectResponse
    {
        $workers->remove($worker);
        $worker->delete();

        return back()->with('success', 'Worker removed.');
    }
}
