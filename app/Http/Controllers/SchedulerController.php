<?php

namespace App\Http\Controllers;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\SchedulerManager;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class SchedulerController extends Controller
{
    public function __invoke(Site $site, SchedulerManager $scheduler): RedirectResponse
    {
        abort_unless($site->status === SiteStatus::Installed, 422, 'Install the site first.');

        try {
            if ($site->has_scheduler) {
                $scheduler->disable($site);
            } else {
                $scheduler->enable($site);
            }
        } catch (RuntimeException $exception) {
            return back()->with('error', 'Scheduler update failed: '.$exception->getMessage());
        }

        return back()->with('success', $site->has_scheduler ? 'Scheduler enabled.' : 'Scheduler disabled.');
    }
}
