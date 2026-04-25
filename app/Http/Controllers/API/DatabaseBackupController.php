<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;

class DatabaseBackupController extends BaseController
{
    /**
     * List available backups.
     */
    public function index()
    {
        try {
            $files = Storage::disk('local')->files('backups');
            $backups = [];

            foreach ($files as $file) {
                if (str_ends_with($file, '.sql')) {
                    $backups[] = [
                        'filename' => basename($file),
                        'size' => Storage::disk('local')->size($file),
                        'created_at' => date('Y-m-d H:i:s', Storage::disk('local')->lastModified($file)),
                    ];
                }
            }

            // Sort by newest first
            usort($backups, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

            return $this->sendResponse($backups, 'Backups retrieved successfully');
        } catch (\Exception $e) {
            return $this->sendError('Error listing backups', [$e->getMessage()], 500);
        }
    }

    /**
     * Create a new database backup using pure PHP.
     */
    public function store()
    {
        try {
            $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
            $path = storage_path('app/backups/' . $filename);

            // Ensure backups directory exists
            if (!is_dir(storage_path('app/backups'))) {
                mkdir(storage_path('app/backups'), 0755, true);
            }

            $sql = '';
            $database = config('database.connections.mysql.database');

            // Header
            $sql .= "-- Database Backup\n";
            $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
            $sql .= "-- Database: {$database}\n\n";
            $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

            // Get all tables
            $tables = DB::select('SHOW TABLES');
            $tableKey = 'Tables_in_' . $database;

            foreach ($tables as $table) {
                $tableName = $table->$tableKey;

                // Skip migrations table
                if ($tableName === 'migrations') continue;

                // Drop table statement
                $sql .= "DROP TABLE IF EXISTS `{$tableName}`;\n";

                // Create table statement
                $createTable = DB::select("SHOW CREATE TABLE `{$tableName}`");
                $sql .= $createTable[0]->{'Create Table'} . ";\n\n";

                // Insert data
                $rows = DB::table($tableName)->get();

                if ($rows->count() > 0) {
                    $columns = array_keys((array) $rows->first());
                    $columnList = implode('`, `', $columns);

                    foreach ($rows->chunk(100) as $chunk) {
                        $sql .= "INSERT INTO `{$tableName}` (`{$columnList}`) VALUES\n";

                        $values = [];
                        foreach ($chunk as $row) {
                            $rowValues = [];
                            foreach ((array) $row as $value) {
                                if (is_null($value)) {
                                    $rowValues[] = 'NULL';
                                } else {
                                    $rowValues[] = "'" . addslashes($value) . "'";
                                }
                            }
                            $values[] = '(' . implode(', ', $rowValues) . ')';
                        }

                        $sql .= implode(",\n", $values) . ";\n\n";
                    }
                }
            }

            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

            file_put_contents($path, $sql);
            $size = filesize($path);

            return $this->sendResponse([
                'filename' => $filename,
                'size' => $size,
                'created_at' => date('Y-m-d H:i:s'),
            ], 'Backup created successfully');

        } catch (\Exception $e) {
            return $this->sendError('Error creating backup', [$e->getMessage()], 500);
        }
    }

    /**
     * Restore database from a backup file using pure PHP.
     */
    public function restore(Request $request)
    {
        try {
            $request->validate([
                'filename' => 'required|string',
            ]);

            $filename = $request->filename;
            $path = storage_path('app/backups/' . $filename);

            if (!file_exists($path)) {
                return $this->sendError('Backup file not found', [], 404);
            }

            $sql = file_get_contents($path);

            DB::unprepared($sql);

            return $this->sendResponse(null, 'Database restored successfully from ' . $filename);

        } catch (\Exception $e) {
            return $this->sendError('Error restoring backup', [$e->getMessage()], 500);
        }
    }

    /**
     * Delete a backup file.
     */
    public function destroy(Request $request)
    {
        try {
            $request->validate([
                'filename' => 'required|string',
            ]);

            $filename = $request->filename;
            $filepath = 'backups/' . $filename;

            if (!Storage::disk('local')->exists($filepath)) {
                return $this->sendError('Backup file not found', [], 404);
            }

            Storage::disk('local')->delete($filepath);

            return $this->sendResponse(null, 'Backup deleted successfully');

        } catch (\Exception $e) {
            return $this->sendError('Error deleting backup', [$e->getMessage()], 500);
        }
    }

    /**
     * Download a backup file.
     */
    public function download(Request $request)
    {
        try {
            $filename = $request->query('filename');

            if (!$filename) {
                return $this->sendError('Filename is required', [], 422);
            }

            $path = storage_path('app/backups/' . $filename);

            if (!file_exists($path)) {
                return $this->sendError('Backup file not found', [], 404);
            }

            return response()->download($path, $filename);

        } catch (\Exception $e) {
            return $this->sendError('Error downloading backup', [$e->getMessage()], 500);
        }
    }
}
