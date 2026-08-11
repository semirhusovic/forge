<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubClientFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GitHubConnectionController extends Controller
{
    /** `repo` covers deploy keys and private clones, `admin:repo_hook` covers webhooks. */
    private const SCOPES = 'repo,admin:repo_hook';

    private const AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';

    private const TOKEN_URL = 'https://github.com/login/oauth/access_token';

    public function __construct(private GitHubClientFactory $clients) {}

    /**
     * Show the GitHub connection settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/GitHub', [
            'configured' => filled(config('services.github.client_id'))
                && filled(config('services.github.client_secret')),
        ]);
    }

    /**
     * Redirect the user to GitHub to authorize the requested scopes.
     */
    public function create(Request $request): RedirectResponse
    {
        if (blank(config('services.github.client_id')) || blank(config('services.github.client_secret'))) {
            return to_route('github.edit')->with('error', 'Set GITHUB_CLIENT_ID and GITHUB_CLIENT_SECRET in the panel .env first.');
        }

        $state = Str::random(40);
        $request->session()->put('github_oauth_state', $state);

        return redirect()->away(self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.github.client_id'),
            'redirect_uri' => route('github.callback'),
            'scope' => self::SCOPES,
            'state' => $state,
        ]));
    }

    /**
     * Exchange the OAuth code for a token and store the connected account.
     */
    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('github_oauth_state');
        $received = $request->query('state');

        abort_unless(
            is_string($expected) && is_string($received) && hash_equals($expected, $received),
            403,
            'Invalid OAuth state.',
        );

        $response = Http::asForm()->acceptJson()->post(self::TOKEN_URL, [
            'client_id' => config('services.github.client_id'),
            'client_secret' => config('services.github.client_secret'),
            'code' => $request->query('code'),
            'redirect_uri' => route('github.callback'),
        ]);

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            $reason = $response->json('error_description') ?? 'GitHub returned no access token.';

            return to_route('github.edit')->with('error', "GitHub authorization failed: {$reason}");
        }

        $user = $request->user();
        $user->github_token = $token;

        try {
            $viewer = $this->clients->for($user)->viewer();
        } catch (GitHubApiException $exception) {
            report($exception);

            return to_route('github.edit')->with('error', "GitHub authorization failed: {$exception->getMessage()}");
        }

        $user->github_login = $viewer['login'];
        $user->github_avatar_url = $viewer['avatar_url'];
        $user->github_connected_at = now();
        $user->save();

        return to_route('github.edit')->with('success', "Connected to GitHub as {$viewer['login']}.");
    }

    /**
     * Clear the stored GitHub connection.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->clearGitHubConnection();

        return to_route('github.edit')->with('success', 'GitHub disconnected. Revoke the grant at github.com/settings/applications to complete removal.');
    }
}
