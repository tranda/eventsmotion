<?php

namespace App\Http\Controllers\API;

use Illuminate\Support\Facades\Artisan;

/**
 * Administration → Database Updates (admins only, see routes/api.php).
 * Lists migrations that are in the code but not yet run on this server and
 * runs them like `php artisan migrate --force` from the hosting panel.
 *
 * GET  database-updates      {pending: [...], ran_count, error}
 * POST database-updates/run  {success, message, output, pending, ran_count, error}
 */
class DatabaseUpdatesController extends BaseController
{
    public function index()
    {
        return response()->json($this->migrationStatus());
    }

    public function run()
    {
        @set_time_limit(300);

        try {
            $code = Artisan::call('migrate', ['--force' => true]);
            $output = trim(Artisan::output());
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Database update failed: ' . $e->getMessage(),
                'output' => trim(Artisan::output()) ?: null,
            ] + $this->migrationStatus(), 500);
        }

        return response()->json([
            'success' => $code === 0,
            'message' => $code === 0 ? 'Database updated.' : 'Database update finished with errors.',
            'output' => $output ?: null,
        ] + $this->migrationStatus(), $code === 0 ? 200 : 500);
    }

    private function migrationStatus(): array
    {
        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles([database_path('migrations')]);
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
            $pending = array_values(array_diff(array_keys($files), $ran));
            sort($pending);

            return ['pending' => $pending, 'ran_count' => count($ran), 'error' => null];
        } catch (\Throwable $e) {
            return ['pending' => [], 'ran_count' => 0, 'error' => $e->getMessage()];
        }
    }
}
