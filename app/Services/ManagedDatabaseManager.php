<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Manages MySQL databases and users for deployed applications.
 *
 * Identifiers (database name, username) cannot use PDO parameter bindings — those
 * are for values only. The controller regex-validates all identifiers before they
 * reach this service, and the password is passed through PDO::quote() to safely
 * embed it in the IDENTIFIED BY clause.
 *
 * All methods no-op when `forge.fake_shell` is enabled (local / test mode).
 */
class ManagedDatabaseManager
{
    public function create(string $name, string $username, string $password): void
    {
        if (config('forge.fake_shell')) {
            return;
        }

        $connection = $this->connection();

        $connection->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $connection->statement("CREATE USER '{$username}'@'localhost' IDENTIFIED BY ".$connection->getPdo()->quote($password));
        $connection->statement("GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$username}'@'localhost'");
        $connection->statement('FLUSH PRIVILEGES');
    }

    public function drop(string $name, string $username): void
    {
        if (config('forge.fake_shell')) {
            return;
        }

        $connection = $this->connection();

        $connection->statement("DROP DATABASE IF EXISTS `{$name}`");
        $connection->statement("DROP USER IF EXISTS '{$username}'@'localhost'");
        $connection->statement('FLUSH PRIVILEGES');
    }

    private function connection(): Connection
    {
        return DB::connection('forge_mysql');
    }
}
