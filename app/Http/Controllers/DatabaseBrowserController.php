<?php

namespace App\Http\Controllers;

use App\Models\ManagedDatabase;
use App\Services\DatabaseInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseBrowserController extends Controller
{
    public function show(Request $request, ManagedDatabase $database, DatabaseInspector $inspector): Response
    {
        $table = $request->query('table');

        return Inertia::render('databases/Show', [
            'database' => $database->only(['id', 'name', 'username']),
            'tables' => Inertia::optional(fn () => $inspector->tables($database->name)),
            'rows' => Inertia::optional(
                fn () => is_string($table) && $table !== ''
                    ? $inspector->rows(
                        $database->name,
                        $table,
                        (int) $request->query('page', '1'),
                    )
                    : null,
            ),
        ]);
    }

    /**
     * Execute console SQL and return the result as JSON for `useHttp`, so the
     * console does not push a page visit per query.
     *
     * A statement that fails comes back 200 with `error` populated rather than
     * as an exception — the driver's message is the point of a SQL console,
     * not a server error.
     */
    public function query(Request $request, ManagedDatabase $database, DatabaseInspector $inspector): JsonResponse
    {
        $validated = $request->validate([
            'sql' => ['required', 'string', 'max:20000'],
        ]);

        return response()->json(
            $inspector->query($database->name, $validated['sql']),
        );
    }
}
