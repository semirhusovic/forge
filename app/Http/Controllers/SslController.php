<?php

namespace App\Http\Controllers;

use App\Enums\SiteStatus;
use App\Jobs\IssueCertificate;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;

class SslController extends Controller
{
    public function __invoke(Site $site): RedirectResponse
    {
        abort_unless($site->status === SiteStatus::Installed, 422, 'Install the site first.');

        if (blank(config('forge.certbot_email'))) {
            return back()->with('error', 'Set FORGE_CERTBOT_EMAIL in the panel .env first.');
        }

        IssueCertificate::dispatch($site);

        return back()->with('success', 'Certificate issuance started — watch the log below.');
    }
}
