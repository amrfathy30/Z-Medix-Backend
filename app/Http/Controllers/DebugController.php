<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class DebugController extends Controller
{
    /**
     * Display the debug dashboard.
     */
    public function index()
    {
        $logPath = storage_path('logs/laravel.log');
        $logs = '';
        if (File::exists($logPath)) {
            $logs = File::get($logPath);
        }

        // $arTranslationsCount = count(json_decode(File::get(base_path('lang/ar.json')), true) ?? []);

        return view('debug.dashboard', [
            'logs' => $logs,
            //            'ar_count' => $arTranslationsCount,
            'last_modified' => File::exists($logPath) ? date('Y-m-d H:i:s', File::lastModified($logPath)) : 'N/A',
        ]);
    }

    /**
     * Run migrations.
     */
    public function migrate()
    {
        try {
            Artisan::call('migrate', ['--force' => true]);

            return back()->with('success', 'Migrations executed successfully: '.Artisan::output());
        } catch (\Exception $e) {
            return back()->with('error', 'Migration failed: '.$e->getMessage());
        }
    }

    /**
     * Run seeders.
     */
    public function seed()
    {
        try {
            Artisan::call('db:seed', ['--force' => true]);

            return back()->with('success', 'Seeding executed successfully: '.Artisan::output());
        } catch (\Exception $e) {
            return back()->with('error', 'Seeding failed: '.$e->getMessage());
        }
    }

    /**
     * Clear the log file.
     */
    public function clearLogs()
    {
        $logPath = storage_path('logs/laravel.log');
        if (File::exists($logPath)) {
            File::put($logPath, '');

            return back()->with('success', 'Logs cleared successfully.');
        }

        return back()->with('error', 'Log file not found.');
    }
}
