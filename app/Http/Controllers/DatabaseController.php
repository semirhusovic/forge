<?php

namespace App\Http\Controllers;

use App\Models\ManagedDatabase;
use App\Services\ManagedDatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('databases/Index', [
            'databases' => ManagedDatabase::query()->latest()->get(),
        ]);
    }

    public function store(Request $request, ManagedDatabaseManager $manager): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'unique:managed_databases,name', 'regex:/^[a-z][a-z0-9_]{2,31}$/'],
            'username' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,31}$/'],
        ]);

        $password = Str::password(24, symbols: false);

        try {
            $manager->create($validated['name'], $validated['username'], $password);
        } catch (\Throwable $exception) {
            return back()->with('error', 'Database creation failed: '.$exception->getMessage());
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
            return back()->with('error', 'Database drop failed: '.$exception->getMessage());
        }

        $database->delete();

        return back()->with('success', 'Database and user dropped.');
    }
}
