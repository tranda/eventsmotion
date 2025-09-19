<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestDatabaseCommand extends Command
{
    protected $signature = 'db:test';
    protected $description = 'Test database connection and show basic info';

    public function handle()
    {
        try {
            // Test basic connection
            $this->info('Testing database connection...');

            $connection = DB::connection();
            $pdo = $connection->getPdo();

            $this->info('✓ Database connection successful!');
            $this->info('Database Name: ' . $connection->getDatabaseName());

            // Test a simple query
            $result = DB::select('SELECT 1 as test');
            $this->info('✓ Simple query test successful!');

            // Check if users table exists
            $tables = DB::select("SHOW TABLES LIKE 'users'");
            if (count($tables) > 0) {
                $this->info('✓ Users table exists');

                // Count users
                $userCount = DB::table('users')->count();
                $this->info("Users count: {$userCount}");
            } else {
                $this->error('✗ Users table does not exist');
            }

            // Check password_resets table
            $passwordResetTables = DB::select("SHOW TABLES LIKE 'password_resets'");
            if (count($passwordResetTables) > 0) {
                $this->info('✓ Password_resets table exists');
            } else {
                $this->error('✗ Password_resets table does not exist');
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('✗ Database connection failed!');
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }
}