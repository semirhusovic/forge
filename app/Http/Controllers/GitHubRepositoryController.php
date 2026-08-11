<?php

namespace App\Http\Controllers;

use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubClientFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Feeds the repository and branch dropdowns on the New Site form. Both
 * endpoints answer JSON and are polled from the client, so failures are
 * returned as 422 with GitHub's message rather than thrown as 500s.
 */
class GitHubRepositoryController extends Controller
{
    private const MAX_RESULTS = 30;

    public function __construct(private GitHubClientFactory $clients) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasGitHubConnection(), 403, 'Connect a GitHub account first.');

        $query = Str::lower(trim((string) $request->query('q', '')));

        try {
            $repositories = $this->clients->for($request->user())->repositories();
        } catch (GitHubApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if ($query !== '') {
            $repositories = array_filter(
                $repositories,
                fn (array $repository): bool => str_contains(Str::lower($repository['full_name']), $query),
            );
        }

        return response()->json([
            'repositories' => array_values(array_slice($repositories, 0, self::MAX_RESULTS)),
        ]);
    }

    public function branches(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasGitHubConnection(), 403, 'Connect a GitHub account first.');

        $validated = $request->validate([
            'repository' => ['required', 'string', 'regex:/^(?!.*\.\.)[\w.-]+\/[\w.-]+$/D'],
        ]);

        $client = $this->clients->for($request->user());

        try {
            return response()->json([
                'branches' => $client->branches($validated['repository']),
                'default_branch' => $client->defaultBranch($validated['repository']),
            ]);
        } catch (GitHubApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
