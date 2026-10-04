<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>N8N Central - Debug Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Fira+Code&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --bg: #0f172a;
            --card-bg: #1e293b;
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --success: #22c55e;
            --error: #ef4444;
            --warning: #f59e0b;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 2rem;
            line-height: 1.5;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            border-bottom: 1px solid #334155;
            padding-bottom: 1rem;
        }

        h1 {
            font-size: 1.875rem;
            font-weight: 700;
            margin: 0;
            background: linear-gradient(to right, #818cf8, #c084fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 1.25rem;
            border-radius: 0.75rem;
            border: 1px solid #334155;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: var(--primary);
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .action-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            border-radius: 0.75rem;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.2s;
            text-decoration: none;
            color: white;
            gap: 0.5rem;
        }

        .btn-migrate { background-color: var(--primary); }
        .btn-migrate:hover { background-color: var(--primary-hover); box-shadow: 0 0 15px rgba(99, 102, 241, 0.4); }

        .btn-seed { background-color: var(--warning); }
        .btn-seed:hover { opacity: 0.9; box-shadow: 0 0 15px rgba(245, 158, 11, 0.4); }

        .btn-clear { background-color: #334155; }
        .btn-clear:hover { background-color: #475569; }

        .logs-section {
            background: #000;
            border-radius: 0.75rem;
            border: 1px solid #334155;
            padding: 1rem;
            overflow: hidden;
        }

        .logs-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding: 0 0.5rem;
        }

        .logs-content {
            font-family: 'Fira Code', monospace;
            font-size: 0.8125rem;
            white-space: pre-wrap;
            height: 500px;
            overflow-y: auto;
            color: #d1d5db;
            scrollbar-width: thin;
            scrollbar-color: #334155 transparent;
        }

        .alert {
            padding: 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .alert-success { background: rgba(34, 197, 94, 0.2); color: #4ade80; border: 1px solid #22c55e; }
        .alert-error { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid #ef4444; }

        .tag {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            background: #334155;
            color: var(--text-muted);
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                <h1>Debug Dashboard</h1>
                <p style="color: var(--text-muted); margin-top: 0.25rem;">N8N Central System Administration</p>
            </div>
            <a href="/" style="color: var(--primary); text-decoration: none; font-weight: 600;">← Back to Home</a>
        </header>

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if(session('error'))
            <div class="alert alert-error">{{ session('error') }}</div>
        @endif

{{--        <div class="stats-grid">--}}
{{--            <div class="stat-card">--}}
{{--                <div class="stat-label">Arabic Translations</div>--}}
{{--                <div class="stat-value">{{ $ar_count }} <span style="font-size: 0.875rem; font-weight: 400; color: var(--text-muted);">keys</span></div>--}}
{{--            </div>--}}
{{--            <div class="stat-card">--}}
{{--                <div class="stat-label">Log Last Modified</div>--}}
{{--                <div class="stat-value" style="font-size: 1rem;">{{ $last_modified }}</div>--}}
{{--            </div>--}}
{{--            <div class="stat-card">--}}
{{--                <div class="stat-label">Environment</div>--}}
{{--                <div class="stat-value"><span class="tag" style="background: var(--primary); color: white; padding: 0.5rem 1rem;">{{ config('app.env') }}</span></div>--}}
{{--            </div>--}}
{{--        </div>--}}

        <div class="actions-grid">
            <form action="{{ route('debug.migrate') }}" method="POST">
                @csrf
                <button type="submit" class="action-btn btn-migrate" onclick="return confirm('Are you sure you want to run migrations?')">
                    ⚡ Run Migrations
                </button>
            </form>
            <form action="{{ route('debug.seed') }}" method="POST">
                @csrf
                <button type="submit" class="action-btn btn-seed" onclick="return confirm('Are you sure you want to run seeders?')">
                    🌱 Run Seeders
                </button>
            </form>
            <form action="{{ route('debug.clear-logs') }}" method="POST">
                @csrf
                <button type="submit" class="action-btn btn-clear">
                    🧹 Clear Logs
                </button>
            </form>
            <a href="/env-manager" class="action-btn" style="background-color: #0d9488; font-weight: 700;">
                🚀 RUN .env MANAGER
            </a>
            <a href="/translations/scan" class="action-btn" style="background-color: #f59e0b; color: #000; font-weight: 700;">
                📡 START FULL SCAN
            </a>
            <a href="/translations/missing-keys" class="action-btn" style="background-color: #6366f1;">
                🔍 View Missing Keys
            </a>
            <form action="/translations/add-missing" method="POST">
                @csrf
                <button type="submit" class="action-btn" style="background-color: #8b5cf6; width: 100%;">
                    🔧 Fix & Add Missing Keys
                </button>
            </form>
        </div>

        <div class="logs-section">
            <div class="logs-header">
                <span style="font-weight: 600; color: var(--text-muted);">Full Laravel Log Viewer</span>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <button onclick="copyLogs()" id="copyBtn" class="tag" style="cursor: pointer; border: 1px solid #475569; background: #1e293b; color: #f8fafc; transition: all 0.2s;">
                        📋 Copy to Clipboard
                    </button>
                    <span class="tag">laravel.log</span>
                </div>
            </div>
            <div class="logs-content" id="logContent">
@if($logs)
{{ $logs }}
@else
No logs found or file is empty.
@endif
            </div>
        </div>
    </div>

    <script>
        // Scroll to bottom of logs on load
        window.onload = function() {
            var logContent = document.getElementById('logContent');
            logContent.scrollTop = logContent.scrollHeight;
        };

        function copyLogs() {
            const logContent = document.getElementById('logContent').innerText;
            const btn = document.getElementById('copyBtn');

            navigator.clipboard.writeText(logContent).then(() => {
                const originalText = btn.innerHTML;
                btn.innerHTML = '✅ Copied!';
                btn.style.borderColor = 'var(--success)';
                btn.style.color = '#4ade80';

                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.style.borderColor = '#475569';
                    btn.style.color = '#f8fafc';
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy: ', err);
                alert('Failed to copy logs.');
            });
        }
    </script>
</body>
</html>
