<?php

namespace App\Http\Controllers;

use App\Trait\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class SystemLogController extends Controller
{
    use ApiResponseTrait;

    /**
     * Get the latest entries from laravel.log
     */
    public function index(Request $request)
    {
        $logPath = storage_path('logs/laravel.log');

        // If laravel.log doesn't exist or is older than production.log, try production.log
        $prodLog = storage_path('logs/production.log');
        if (File::exists($prodLog) && (! File::exists($logPath) || File::lastModified($prodLog) > File::lastModified($logPath))) {
            $logPath = $prodLog;
        }

        if (! File::exists($logPath)) {
            return $request->expectsJson()
                ? $this->error(__('Log file not found.'), 404)
                : response('Log file not found.', 404);
        }

        // Read the last N lines (default 500)
        $lines = (int) $request->get('lines', 500);
        $logContent = $this->readLastLines($logPath, $lines);

        if ($request->expectsJson()) {
            return $this->success([
                'file_path' => $logPath,
                'last_modified' => date('Y-m-d H:i:s', File::lastModified($logPath)),
                'content' => $logContent,
            ]);
        }

        // Return as plain text for browser viewing
        $output = 'LOG FILE: '.$logPath."\n";
        $output .= 'LAST MODIFIED: '.date('Y-m-d H:i:s', File::lastModified($logPath))."\n";
        $output .= 'PHP POST MAX SIZE: '.ini_get('post_max_size')."\n";
        $output .= 'PHP UPLOAD MAX FILESIZE: '.ini_get('upload_max_filesize')."\n";
        $output .= 'ACTION: [ CLICK HERE TO CLEAR LOGS -> '.url('/debug-logs/clear')." ]\n";
        $output .= "--------------------------------------------------------------------------------\n\n";
        $output .= implode('', $logContent);

        return response($output)->header('Content-Type', 'text/plain');
    }

    /**
     * Clear the log file.
     */
    public function clear(Request $request)
    {
        $logPath = storage_path('logs/laravel.log');

        if (File::exists($logPath)) {
            File::put($logPath, '');

            if ($request->expectsJson()) {
                return $this->success(null, __('Log file cleared successfully.'));
            }

            return redirect('/debug-logs')->with('success', 'Logs cleared.');
        }

        return $request->expectsJson()
            ? $this->error(__('Log file not found.'), 404)
            : response('Log file not found.', 404);
    }

    /**
     * Show the .env file content.
     */
    public function showEnv()
    {
        $envPath = base_path('.env');
        if (! File::exists($envPath)) {
            return response('No .env file found.', 404);
        }

        $content = File::get($envPath);

        $html = "
        <html>
        <head><title>.env Manager</title></head>
        <body style='font-family: sans-serif; padding: 20px; background: #f4f4f4;'>
            <div style='max-width: 800px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                <h2 style='color: #333;'>.env File Manager</h2>
                <p style='color: #d9534f; font-weight: bold;'>⚠️ WARNING: Delete these routes after use for security!</p>
                <form action='/env-manager/update' method='POST'>
                    <input type='hidden' name='_token' value='".csrf_token()."'>
                    <textarea name='content' style='width: 100%; height: 500px; font-family: monospace; padding: 15px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; line-height: 1.5;'>{$content}</textarea>
                    <br><br>
                    <button type='submit' style='padding: 12px 25px; background: #5cb85c; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; font-weight: bold;'>SAVE CHANGES</button>
                </form>
                <br>
                <hr>
                <a href='/debug-logs' style='color: #0275d8; text-decoration: none;'>⬅️ Back to Logs</a>
            </div>
        </body>
        </html>";

        return response($html);
    }

    /**
     * Update the .env file content.
     */
    public function updateEnv(Request $request)
    {
        $envPath = base_path('.env');
        $content = $request->input('content');

        if (empty($content)) {
            return response('Content cannot be empty.', 400);
        }

        File::put($envPath, $content);

        // Try to clear cache programmatically
        try {
            Artisan::call('config:clear');
            Artisan::call('cache:clear');
            Artisan::call('optimize:clear');
        } catch (\Exception $e) {
            // Ignore if fails
        }

        return response("
            <div style='font-family: sans-serif; padding: 40px; text-align: center;'>
                <h2 style='color: #5cb85c;'>✅ .env file updated successfully!</h2>
                <p>The changes have been saved to the server.</p>
                <br>
                <a href='/env-manager' style='padding: 10px 20px; background: #0275d8; color: white; text-decoration: none; border-radius: 4px;'>Back to Editor</a>
                <a href='/debug-logs' style='padding: 10px 20px; background: #666; color: white; text-decoration: none; border-radius: 4px; margin-left: 10px;'>Go to Logs</a>
            </div>
        ");
    }

    private function readLastLines($filename, $lines)
    {
        if (! is_file($filename)) {
            return '';
        }

        $handle = fopen($filename, 'r');
        if (! $handle) {
            return '';
        }

        $pos = -2;
        $text = [];

        while ($lines > 0) {
            $reachedBeginning = $this->seekToPreviousNewline($handle, $pos);

            if ($reachedBeginning) {
                rewind($handle);
            }

            $text[] = fgets($handle);

            if ($reachedBeginning) {
                break;
            }

            fseek($handle, $pos, SEEK_END);
            $lines--;
        }

        fclose($handle);

        return array_reverse($text);
    }

    private function seekToPreviousNewline($handle, int &$pos): bool
    {
        while (fgetc($handle) !== "\n") {
            if (fseek($handle, $pos, SEEK_END) === -1) {
                return true;
            }
            $pos--;
        }

        return false;
    }
}
