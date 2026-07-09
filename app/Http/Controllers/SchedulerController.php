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
