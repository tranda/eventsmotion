<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ApiKey;
use App\Models\User;

class ApiKeySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Create a development API key for Google Sheets integration
        $adminUser = User::where('access_level', '>=', 3)->first();

        if ($adminUser) {
            $result = ApiKey::generate(
                'Google Sheets Integration - Development',
                ['races.bulk-update'], // Specific permission for race data import
                $adminUser->id,
                null // No expiration for development
            );

            $this->command->info('Development API Key created:');
            $this->command->line('Key: ' . $result['api_key']);
            $this->command->line('Name: ' . $result['model']->name);
            $this->command->line('Permissions: ' . implode(', ', $result['model']->permissions));
            $this->command->warn('IMPORTANT: Store this key securely - it will not be shown again!');
        } else {
            $this->command->error('No admin user found. Please create an admin user first.');
        }
    }
}