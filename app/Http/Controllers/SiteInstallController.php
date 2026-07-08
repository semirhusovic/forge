<?php

namespace App\Http\Controllers;

use App\Enums\SiteStatus;
use App\Jobs\InstallRepository;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;

class SiteInstallController extends Controller
{
    public function __invoke(Site $site): RedirectResponse
    {
        abort_unless(in_array($site->status, [SiteStatus::KeyGenerated, SiteStatus::Failed], true), 422, 'Site is not ready to install.');

        InstallRepository::dispatch($site);

        return back()->with('success', 'Installation started.');
    }
}
