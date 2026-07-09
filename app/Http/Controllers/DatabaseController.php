<?php

namespace App\Http\Controllers;

use App\Models\ManagedDatabase;
use App\Services\ManagedDatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseController extends Controller
{
    private const RESERVED_DATABASES = ['mysql', 'sys', 'information_schema', 'performance_schema'];

    private const RESERVED_USERNAMES = ['root', 'mysql'];

    public function index(): Response
    {
        return Inertia::render('databases/Index', [
            'databases' => ManagedDatabase::query()->latest()->get(),
        ]);
    }

    public function store(Request $request, ManagedDatabaseManager $manager): RedirectResponse
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'unique:managed_databases,name',
                'regex:/^[a-z][a-z0-9_]{2,31}$/D', Rule::notIn(self::RESERVED_DATABASES),
            ],
            'username' => [
                'required', 'string', 'unique:managed_databases,username',
                'regex:/^[a-z][a-z0-9_]{2,31}$/D',
                Rule::notIn([...self::RESERVED_USERNAMES, (string) config('database.connections.forge_mysql.username')]),
            ],
        ]);

        $password = Str::password(24, symbols: false);

        try {
            $manager->create($validated['name'], $validated['username'], $password);
        } catch (\Throwable $exception) {
            // QueryException messages embed the full SQL (with the quoted
            // password) — report and flash only the driver-level cause.
            report($exception->getPrevious() ?? $exception);

            return back()->with('error', 'Database creation failed: '.($exception->getPrevious()?->getMessage() ?? 'see the panel log.'));
        }

        ManagedDatabase::create($validated);

        return back()
            ->with('success', 'Database created. Password (shown once):')
            ->with('password', $password);
    }

    public function destroy(ManagedDatabase $database, ManagedDatabaseManager $manager): RedirectResponse
    {
        try {
            $manager->drop($database->name, $database->username);
        } catch (\Throwable $exception) {
            report($exception->getPrevious() ?? $exception);

            return back()->with('error', 'Database drop failed: '.($exception->getPrevious()?->getMessage() ?? 'see the panel log.'));
        }

        $database->delete();

        return back()->with('success', 'Database and user dropped.');
    }
}
