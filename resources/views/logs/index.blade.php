<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Log Viewer - DTR Service</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .log-line {
            font-family: 'JetBrains Mono', 'Fira Code', 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.5;
        }
        .log-error { color: #f87171; background: rgba(239, 68, 68, 0.1); border-left: 3px solid #ef4444; }
        .log-warning { color: #fbbf24; background: rgba(251, 191, 36, 0.1); border-left: 3px solid #f59e0b; }
        .log-info { color: #60a5fa; background: rgba(59, 130, 246, 0.1); border-left: 3px solid #3b82f6; }
        .log-debug { color: #9ca3af; background: rgba(107, 114, 128, 0.05); border-left: 3px solid #6b7280; }
        .log-default { color: #e5e7eb; background: rgba(55, 65, 81, 0.3); border-left: 3px solid #4b5563; }
        
        .scrollbar-thin::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .scrollbar-thin::-webkit-scrollbar-track {
            background: #1f2937;
        }
        .scrollbar-thin::-webkit-scrollbar-thumb {
            background: #4b5563;
            border-radius: 4px;
        }
        .scrollbar-thin::-webkit-scrollbar-thumb:hover {
            background: #6b7280;
        }

        .panel-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #0f172a 100%);
        }

        .glow-blue {
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.3);
        }

        .glow-purple {
            box-shadow: 0 0 20px rgba(139, 92, 246, 0.3);
        }

        /* ========== SCRATCH CSS ========== */
        /* Add your custom styles here for quick testing */
        /* =========================================== */
    </style>
</head>
<body class="bg-slate-950 text-gray-100 min-h-screen">
    <!-- Header -->
    <header class="bg-slate-900 border-b border-slate-800 px-6 py-4">
        <div class="max-w-full mx-auto">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
                            <i class="fas fa-terminal text-white text-lg"></i>
                        </div>
                        <div>
                            <h1 class="text-xl font-bold text-white">Log Viewer</h1>
                            <p class="text-xs text-slate-400">DTR Service Monitoring</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2 bg-slate-800 rounded-lg px-3 py-2">
                        <label class="text-xs text-slate-400 font-medium">Lines:</label>
                        <select id="lineCount" class="bg-slate-700 text-white border border-slate-600 rounded px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="50">50</option>
                            <option value="100" selected>100</option>
                            <option value="200">200</option>
                            <option value="500">500</option>
                            <option value="1000">1000</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2 bg-slate-800 rounded-lg px-3 py-2">
                        <label class="text-xs text-slate-400 font-medium">Auto-refresh:</label>
                        <select id="refreshInterval" class="bg-slate-700 text-white border border-slate-600 rounded px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="0">Off</option>
                            <option value="2000">2s</option>
                            <option value="5000" selected>5s</option>
                            <option value="10000">10s</option>
                            <option value="30000">30s</option>
                        </select>
                    </div>
                    <button id="refreshBtn" class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2 transition-all shadow-lg hover:shadow-blue-500/25">
                        <i class="fas fa-sync-alt"></i>
                        Refresh
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="p-6">
        <div class="grid grid-cols-2 gap-6 h-[calc(100vh-140px)]">
            <!-- Laravel Logs Panel -->
            <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden flex flex-col glow-blue">
                <div class="panel-header px-4 py-3 border-b border-slate-700">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center">
                                <i class="fab fa-laravel text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-sm font-semibold text-white">Laravel Logs</h2>
                                <div class="flex items-center gap-2 text-xs text-slate-400">
                                    <span id="laravelStatus">Loading...</span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-slate-400">
                            <span id="laravelSize">--</span>
                            <span id="laravelModified">--</span>
                            <button onclick="clearLog('laravel.log')" class="text-red-400 hover:text-red-300 hover:bg-red-900/30 px-2 py-1 rounded transition-colors">
                                <i class="fas fa-trash-alt"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>
                <div id="laravelContent" class="flex-1 overflow-y-auto scrollbar-thin p-3">
                    <div class="text-slate-500 text-center py-8">
                        <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
                        <p>Loading logs...</p>
                    </div>
                </div>
            </div>

            <!-- Device Logs Panel -->
            <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden flex flex-col glow-purple">
                <div class="panel-header px-4 py-3 border-b border-slate-700">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 bg-purple-600 rounded-lg flex items-center justify-center">
                                <i class="fas fa-microchip text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-sm font-semibold text-white">Device Logs</h2>
                                <div class="flex items-center gap-2 text-xs text-slate-400">
                                    <span id="deviceStatus">Loading...</span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-slate-400">
                            <span id="deviceSize">--</span>
                            <span id="deviceModified">--</span>
                            <button onclick="clearLog('device_logs.log')" class="text-red-400 hover:text-red-300 hover:bg-red-900/30 px-2 py-1 rounded transition-colors">
                                <i class="fas fa-trash-alt"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>
                <div id="deviceContent" class="flex-1 overflow-y-auto scrollbar-thin p-3">
                    <div class="text-slate-500 text-center py-8">
                        <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
                        <p>Loading logs...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Status -->
        <div class="mt-4 flex items-center justify-between text-xs text-slate-500">
            <div class="flex items-center gap-4">
                <span class="flex items-center gap-2">
                    <span class="w-3 h-3 bg-red-500 rounded-full"></span> ERROR
                </span>
                <span class="flex items-center gap-2">
                    <span class="w-3 h-3 bg-yellow-500 rounded-full"></span> WARNING
                </span>
                <span class="flex items-center gap-2">
                    <span class="w-3 h-3 bg-blue-500 rounded-full"></span> INFO
                </span>
                <span class="flex items-center gap-2">
                    <span class="w-3 h-3 bg-gray-500 rounded-full"></span> DEBUG
                </span>
            </div>
            <div id="lastUpdated">Last updated: --</div>
        </div>
    </main>

    <script>
        let refreshTimer = null;

        function getLogColor(line) {
            if (line.includes('.ERROR')) return 'log-error';
            if (line.includes('.WARNING') || line.includes('.WARN')) return 'log-warning';
            if (line.includes('.INFO')) return 'log-info';
            if (line.includes('.DEBUG')) return 'log-debug';
            return 'log-default';
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function renderLogs(containerId, data) {
            const container = document.getElementById(containerId);
            
            if (!data.exists) {
                container.innerHTML = `<div class="text-slate-500 text-center py-8"><i class="fas fa-file-exclamation text-2xl mb-2"></i><p>File not found</p></div>`;
                return;
            }

            if (!data.lines || data.lines.length === 0) {
                container.innerHTML = `<div class="text-slate-500 text-center py-8"><i class="fas fa-file-alt text-2xl mb-2"></i><p>No log entries</p></div>`;
                return;
            }

            let html = '';
            data.lines.forEach(line => {
                if (!line.trim()) return;
                const colorClass = getLogColor(line);
                html += `<div class="log-line ${colorClass} px-3 py-1.5 mb-1 rounded whitespace-pre-wrap">${escapeHtml(line)}</div>`;
            });
            container.innerHTML = html;
        }

        async function fetchLogs() {
            const lines = document.getElementById('lineCount').value;
            
            try {
                const response = await fetch(`/logs/view?lines=${lines}`);
                const data = await response.json();
                
                // Render Laravel logs
                renderLogs('laravelContent', data.laravel);
                document.getElementById('laravelStatus').textContent = data.laravel.exists 
                    ? `${data.laravel.lines.length} / ${data.laravel.total_lines} lines`
                    : 'Not found';
                document.getElementById('laravelSize').textContent = data.laravel.exists ? data.laravel.size : '--';
                document.getElementById('laravelModified').textContent = data.laravel.exists ? data.laravel.modified : '--';
                
                // Render Device logs
                renderLogs('deviceContent', data.device);
                document.getElementById('deviceStatus').textContent = data.device.exists 
                    ? `${data.device.lines.length} / ${data.device.total_lines} lines`
                    : 'Not found';
                document.getElementById('deviceSize').textContent = data.device.exists ? data.device.size : '--';
                document.getElementById('deviceModified').textContent = data.device.exists ? data.device.modified : '--';
                
                document.getElementById('lastUpdated').textContent = `Last updated: ${new Date().toLocaleTimeString()}`;
            } catch (error) {
                document.getElementById('laravelContent').innerHTML = `<div class="text-red-400 text-center py-8"><i class="fas fa-exclamation-triangle text-2xl mb-2"></i><p>Failed to load: ${escapeHtml(error.message)}</p></div>`;
                document.getElementById('deviceContent').innerHTML = `<div class="text-red-400 text-center py-8"><i class="fas fa-exclamation-triangle text-2xl mb-2"></i><p>Failed to load: ${escapeHtml(error.message)}</p></div>`;
            }
        }

        function setAutoRefresh() {
            if (refreshTimer) {
                clearInterval(refreshTimer);
                refreshTimer = null;
            }
            
            const interval = parseInt(document.getElementById('refreshInterval').value);
            if (interval > 0) {
                refreshTimer = setInterval(fetchLogs, interval);
            }
        }

        async function clearLog(filename) {
            if (!confirm(`Are you sure you want to clear ${filename}? This action cannot be undone.`)) {
                return;
            }

            try {
                const response = await fetch('/logs/clear', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                    },
                    body: JSON.stringify({ file: filename })
                });

                const data = await response.json();

                if (data.success) {
                    // Refresh logs after clearing
                    fetchLogs();
                } else {
                    alert('Failed to clear log: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                alert('Failed to clear log: ' + error.message);
            }
        }

        // Event listeners
        document.getElementById('lineCount').addEventListener('change', fetchLogs);
        document.getElementById('refreshInterval').addEventListener('change', setAutoRefresh);
        document.getElementById('refreshBtn').addEventListener('click', fetchLogs);

        // Initial load
        fetchLogs();
        setAutoRefresh();
    </script>
</body>
</html>
