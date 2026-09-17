<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Biometric Device Management - DTR Service</title>
    <script>
        (function() {
            const theme = localStorage.getItem('alertTheme') || localStorage.getItem('deviceTheme');
            if (theme === 'light') {
                document.documentElement.classList.add('light');
            } else {
                document.documentElement.classList.remove('light');
            }
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: 'class',
        };
    </script>
    <style>
        :root {
            --bg-body: #020617;
            --bg-header: #0f172a;
            --bg-panel: #0f172a;
            --bg-card: #1e293b;
            --bg-subcard: rgba(15, 23, 42, 0.6);
            --bg-input: #1e293b;
            --bg-hover: rgba(51, 65, 85, 0.4);
            --border-color: #334155;
            --border-subtle: #1e293b;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --scrollbar-track: #1f2937;
            --scrollbar-thumb: #4b5563;
            --scrollbar-hover: #6b7280;
            --panel-header-from: #1e3a5f;
            --panel-header-to: #0f172a;
            --modal-overlay: rgba(0, 0, 0, 0.75);
            --modal-bg: #0f172a;
            --modal-border: #334155;

            /* Headers & Table in Dark Mode */
            --table-head-bg: #0b1120;
            --table-head-text: #94a3b8;
            --table-head-hover-text: #ffffff;
            --track-bg: rgba(51, 65, 85, 0.4);

            /* Secondary Buttons */
            --btn-sec-bg: #1e293b;
            --btn-sec-border: #334155;
            --btn-sec-text: #e2e8f0;
            --btn-sec-hover-bg: #334155;
            --btn-sec-hover-text: #ffffff;
            --btn-sec-hover-border: #475569;

            /* Action Buttons (Table) in Dark Mode */
            --btn-test-bg: rgba(16, 185, 129, 0.12);
            --btn-test-border: rgba(16, 185, 129, 0.3);
            --btn-test-text: #34d399;
            --btn-test-hover-bg: #059669;
            --btn-test-hover-text: #ffffff;

            --btn-sync-bg: rgba(99, 102, 241, 0.12);
            --btn-sync-border: rgba(99, 102, 241, 0.3);
            --btn-sync-text: #818cf8;
            --btn-sync-hover-bg: #4f46e5;
            --btn-sync-hover-text: #ffffff;

            --btn-restart-bg: rgba(244, 63, 94, 0.12);
            --btn-restart-border: rgba(244, 63, 94, 0.3);
            --btn-restart-text: #fb7185;
            --btn-restart-hover-bg: #e11d48;
            --btn-restart-hover-text: #ffffff;

            --btn-edit-bg: rgba(59, 130, 246, 0.12);
            --btn-edit-border: rgba(59, 130, 246, 0.3);
            --btn-edit-text: #60a5fa;
            --btn-edit-hover-bg: #2563eb;
            --btn-edit-hover-text: #ffffff;

            /* Badges & Role Pills in Dark Mode */
            --pill-blue-bg: rgba(59, 130, 246, 0.12);
            --pill-blue-border: rgba(59, 130, 246, 0.3);
            --pill-blue-text: #60a5fa;

            --pill-amber-bg: rgba(245, 158, 11, 0.12);
            --pill-amber-border: rgba(245, 158, 11, 0.3);
            --pill-amber-text: #fbbf24;

            --pill-emerald-bg: rgba(16, 185, 129, 0.12);
            --pill-emerald-border: rgba(16, 185, 129, 0.3);
            --pill-emerald-text: #34d399;

            --pill-purple-bg: rgba(168, 85, 247, 0.12);
            --pill-purple-border: rgba(168, 85, 247, 0.3);
            --pill-purple-text: #c084fc;

            --pill-rose-bg: rgba(244, 63, 94, 0.12);
            --pill-rose-border: rgba(244, 63, 94, 0.3);
            --pill-rose-text: #f87171;

            /* Alert Boxes */
            --alert-warn-bg: rgba(245, 158, 11, 0.1);
            --alert-warn-border: rgba(245, 158, 11, 0.25);
            --alert-warn-text: #fbbf24;

            --alert-danger-bg: rgba(244, 63, 94, 0.1);
            --alert-danger-border: rgba(244, 63, 94, 0.25);
            --alert-danger-text: #fca5a5;

            /* Batch Bar */
            --batch-bar-bg: rgba(37, 99, 235, 0.12);
            --batch-bar-border: rgba(37, 99, 235, 0.3);
            --batch-bar-text: #93c5fd;
        }

        html.light {
            --bg-body: #f1f5f9;
            --bg-header: #ffffff;
            --bg-panel: #ffffff;
            --bg-card: #ffffff;
            --bg-subcard: #f8fafc;
            --bg-input: #ffffff;
            --bg-hover: #f1f5f9;
            --border-color: #cbd5e1;
            --border-subtle: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-muted: #64748b;
            --scrollbar-track: #f1f5f9;
            --scrollbar-thumb: #cbd5e1;
            --scrollbar-hover: #94a3b8;
            --panel-header-from: #e0f2fe;
            --panel-header-to: #ffffff;
            --modal-overlay: rgba(15, 23, 42, 0.55);
            --modal-bg: #ffffff;
            --modal-border: #cbd5e1;

            /* Headers & Table in Light Mode (Crisp contrast) */
            --table-head-bg: #f8fafc;
            --table-head-text: #334155;
            --table-head-hover-text: #1d4ed8;
            --track-bg: #e2e8f0;

            /* Secondary Buttons in Light Mode */
            --btn-sec-bg: #ffffff;
            --btn-sec-border: #cbd5e1;
            --btn-sec-text: #334155;
            --btn-sec-hover-bg: #f1f5f9;
            --btn-sec-hover-text: #0f172a;
            --btn-sec-hover-border: #94a3b8;

            /* Action Buttons (Table) in Light Mode */
            --btn-test-bg: #ecfdf5;
            --btn-test-border: #a7f3d0;
            --btn-test-text: #047857;
            --btn-test-hover-bg: #059669;
            --btn-test-hover-text: #ffffff;

            --btn-sync-bg: #eef2ff;
            --btn-sync-border: #c7d2fe;
            --btn-sync-text: #4338ca;
            --btn-sync-hover-bg: #4f46e5;
            --btn-sync-hover-text: #ffffff;

            --btn-restart-bg: #fff1f2;
            --btn-restart-border: #fecdd3;
            --btn-restart-text: #be123c;
            --btn-restart-hover-bg: #e11d48;
            --btn-restart-hover-text: #ffffff;

            --btn-edit-bg: #eff6ff;
            --btn-edit-border: #bfdbfe;
            --btn-edit-text: #1d4ed8;
            --btn-edit-hover-bg: #2563eb;
            --btn-edit-hover-text: #ffffff;

            /* Badges & Role Pills in Light Mode (High Legibility) */
            --pill-blue-bg: #eff6ff;
            --pill-blue-border: #bfdbfe;
            --pill-blue-text: #1d4ed8;

            --pill-amber-bg: #fffbeb;
            --pill-amber-border: #fde68a;
            --pill-amber-text: #b45309;

            --pill-emerald-bg: #ecfdf5;
            --pill-emerald-border: #a7f3d0;
            --pill-emerald-text: #047857;

            --pill-purple-bg: #f5f3ff;
            --pill-purple-border: #ddd6fe;
            --pill-purple-text: #6d28d9;

            --pill-rose-bg: #fff1f2;
            --pill-rose-border: #fecdd3;
            --pill-rose-text: #be123c;

            /* Alert Boxes in Light Mode */
            --alert-warn-bg: #fffbeb;
            --alert-warn-border: #fde68a;
            --alert-warn-text: #92400e;

            --alert-danger-bg: #fff1f2;
            --alert-danger-border: #fecdd3;
            --alert-danger-text: #9f1239;

            /* Batch Bar in Light Mode */
            --batch-bar-bg: #eff6ff;
            --batch-bar-border: #bfdbfe;
            --batch-bar-text: #1e40af;
        }

        body {
            background: var(--bg-body);
            color: var(--text-primary);
            font-family: ui-sans-serif, system-ui, sans-serif;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .scrollbar-thin::-webkit-scrollbar { width: 6px; height: 6px; }
        .scrollbar-thin::-webkit-scrollbar-track { background: var(--scrollbar-track); }
        .scrollbar-thin::-webkit-scrollbar-thumb { background: var(--scrollbar-thumb); border-radius: 4px; }
        .scrollbar-thin::-webkit-scrollbar-thumb:hover { background: var(--scrollbar-hover); }

        /* Themed Structural Classes */
        .themed-header {
            background: var(--bg-header);
            border-color: var(--border-color);
        }
        .themed-bg { background: var(--bg-panel); }
        .themed-card {
            background: var(--bg-card);
            border-color: var(--border-color);
        }
        .themed-subcard {
            background: var(--bg-subcard);
            border: 1px solid var(--border-color);
        }
        .themed-input {
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        .themed-input:focus {
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25);
        }
        .themed-input option {
            background: var(--bg-card);
            color: var(--text-primary);
        }
        .themed-border { border-color: var(--border-color); }
        .themed-text-primary { color: var(--text-primary); }
        .themed-text-secondary { color: var(--text-secondary); }
        .themed-text-muted { color: var(--text-muted); }
        .themed-hover:hover { background: var(--bg-hover); }

        /* Secondary Standard Button */
        .btn-secondary {
            background: var(--btn-sec-bg);
            border: 1px solid var(--btn-sec-border);
            color: var(--btn-sec-text);
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            transition: all 0.15s ease-in-out;
        }
        .btn-secondary:hover:not([disabled]) {
            background: var(--btn-sec-hover-bg);
            color: var(--btn-sec-hover-text);
            border-color: var(--btn-sec-hover-border);
        }
        .btn-secondary:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        /* Table Action Buttons */
        .btn-action-test {
            background: var(--btn-test-bg);
            border: 1px solid var(--btn-test-border);
            color: var(--btn-test-text);
            transition: all 0.15s ease-in-out;
        }
        .btn-action-test:hover:not([disabled]) {
            background: var(--btn-test-hover-bg);
            color: var(--btn-test-hover-text);
            border-color: var(--btn-test-hover-bg);
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(5, 150, 105, 0.25);
        }

        .btn-action-sync {
            background: var(--btn-sync-bg);
            border: 1px solid var(--btn-sync-border);
            color: var(--btn-sync-text);
            transition: all 0.15s ease-in-out;
        }
        .btn-action-sync:hover:not([disabled]) {
            background: var(--btn-sync-hover-bg);
            color: var(--btn-sync-hover-text);
            border-color: var(--btn-sync-hover-bg);
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(79, 70, 229, 0.25);
        }

        .btn-action-restart {
            background: var(--btn-restart-bg);
            border: 1px solid var(--btn-restart-border);
            color: var(--btn-restart-text);
            transition: all 0.15s ease-in-out;
        }
        .btn-action-restart:hover:not([disabled]) {
            background: var(--btn-restart-hover-bg);
            color: var(--btn-restart-hover-text);
            border-color: var(--btn-restart-hover-bg);
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(225, 29, 72, 0.25);
        }

        .btn-action-edit {
            background: var(--btn-edit-bg);
            border: 1px solid var(--btn-edit-border);
            color: var(--btn-edit-text);
            transition: all 0.15s ease-in-out;
        }
        .btn-action-edit:hover:not([disabled]) {
            background: var(--btn-edit-hover-bg);
            color: var(--btn-edit-hover-text);
            border-color: var(--btn-edit-hover-bg);
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.25);
        }

        /* Role & Status Pills */
        .pill-blue {
            background: var(--pill-blue-bg);
            border: 1px solid var(--pill-blue-border);
            color: var(--pill-blue-text);
        }
        .pill-amber {
            background: var(--pill-amber-bg);
            border: 1px solid var(--pill-amber-border);
            color: var(--pill-amber-text);
        }
        .pill-emerald {
            background: var(--pill-emerald-bg);
            border: 1px solid var(--pill-emerald-border);
            color: var(--pill-emerald-text);
        }
        .pill-purple {
            background: var(--pill-purple-bg);
            border: 1px solid var(--pill-purple-border);
            color: var(--pill-purple-text);
        }
        .pill-rose {
            background: var(--pill-rose-bg);
            border: 1px solid var(--pill-rose-border);
            color: var(--pill-rose-text);
        }

        /* Table Thead */
        .themed-thead {
            background: var(--table-head-bg);
            color: var(--table-head-text);
            border-bottom: 1px solid var(--border-color);
        }
        .th-sortable {
            transition: color 0.15s ease;
        }
        .th-sortable:hover {
            color: var(--table-head-hover-text);
        }

        /* Alert Boxes */
        .alert-box-warning {
            background: var(--alert-warn-bg);
            border: 1px solid var(--alert-warn-border);
            color: var(--alert-warn-text);
        }
        .alert-box-danger {
            background: var(--alert-danger-bg);
            border: 1px solid var(--alert-danger-border);
            color: var(--alert-danger-text);
        }

        /* Batch Action Bar */
        .batch-bar {
            background: var(--batch-bar-bg);
            border: 1px solid var(--batch-bar-border);
            color: var(--batch-bar-text);
        }

        .pulse-online {
            box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
            animation: pulse-green 2s infinite;
        }
        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
        }

        .pulse-offline {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <!-- Header -->
    <header class="themed-header border-b px-6 py-4 sticky top-0 z-30 shadow-xs backdrop-blur-md">
        <div class="max-w-full mx-auto flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-xl flex items-center justify-center shadow-md shadow-blue-500/20 text-white">
                    <i class="fas fa-fingerprint text-xl"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-bold tracking-tight themed-text-primary">Biometric Device Management</h1>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold pill-blue">
                            ZK / ADMS Native
                        </span>
                    </div>
                    <p class="text-xs themed-text-muted">Real-time status, time synchronization, terminal restart & auto-discovery controls</p>
                </div>
            </div>

            <!-- Global Actions -->
            <div class="flex items-center gap-3">
                <!-- Theme Toggle -->
                <button id="themeToggle" class="btn-secondary rounded-lg p-2 text-sm" title="Toggle Light/Dark Theme">
                    <i id="themeIcon" class="fas fa-sun text-yellow-400"></i>
                </button>

                <!-- Fullscreen Maximize Toggle -->
                <button id="fullscreenBtn" class="btn-secondary rounded-lg p-2 text-sm" title="Toggle Fullscreen View">
                    <i id="fullscreenIcon" class="fas fa-expand"></i>
                </button>

                <!-- Command Runner Console Button -->
                <button id="openCommandRunnerBtn" class="bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 active:from-blue-800 active:to-indigo-800 text-white px-3.5 py-2 rounded-lg text-xs font-semibold flex items-center gap-2 shadow-xs transition-all cursor-pointer" title="Run Biometric & Device CLI Commands via GUI">
                    <i class="fas fa-terminal text-blue-200"></i> Run Commands
                </button>

                <!-- Bulk Test Button -->
                <button id="testAllBtn" class="bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white px-3.5 py-2 rounded-lg text-xs font-semibold flex items-center gap-2 shadow-xs transition-all">
                    <i class="fas fa-bolt"></i> Test All Connections
                </button>

                <!-- Sync All Clocks Button -->
                <button id="syncAllBtn" class="bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white px-3.5 py-2 rounded-lg text-xs font-semibold flex items-center gap-2 shadow-xs transition-all">
                    <i class="fas fa-clock"></i> Sync All Clocks
                </button>

                <!-- Refresh Button -->
                <button id="refreshBtn" class="btn-secondary rounded-lg px-3.5 py-2 text-xs font-semibold flex items-center gap-1.5 shadow-2xs" title="Reload Device Table">
                    <i class="fas fa-sync-alt" id="refreshIcon"></i> Refresh
                </button>
            </div>
        </div>
    </header>

    <!-- Main Container (Full Width Maximized) -->
    <main class="max-w-full mx-auto w-full px-6 py-6 flex-1 space-y-6">

        <!-- KPI Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Total Devices -->
            <div class="themed-card rounded-xl p-5 border shadow-xs flex items-center justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wider font-semibold themed-text-muted">Total Devices</p>
                    <h3 id="statTotal" class="text-3xl font-black mt-1.5 themed-text-primary">{{ $totalDevices ?? 0 }}</h3>
                    <p class="text-xs themed-text-muted mt-1">Configured terminals</p>
                </div>
                <div class="w-12 h-12 rounded-xl pill-blue flex items-center justify-center text-xl">
                    <i class="fas fa-network-wired"></i>
                </div>
            </div>

            <!-- Online Terminals -->
            <div class="themed-card rounded-xl p-5 border shadow-xs flex items-center justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <p class="text-xs uppercase tracking-wider font-semibold themed-text-muted">Online</p>
                        <span id="statOnlineRateBadge" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold pill-emerald">
                            {{ $availabilityRate ?? 0 }}%
                        </span>
                    </div>
                    <h3 id="statOnline" class="text-3xl font-black mt-1.5 text-emerald-600 dark:text-emerald-400">{{ $onlineDevices ?? 0 }}</h3>
                    <p class="text-xs themed-text-muted mt-1">Active within 2 mins</p>
                </div>
                <div class="w-12 h-12 rounded-xl pill-emerald flex items-center justify-center text-xl">
                    <i class="fas fa-wifi"></i>
                </div>
            </div>

            <!-- Offline Terminals -->
            <div class="themed-card rounded-xl p-5 border shadow-xs flex items-center justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wider font-semibold themed-text-muted">Offline / Unreachable</p>
                    <h3 id="statOffline" class="text-3xl font-black mt-1.5 text-rose-600 dark:text-rose-400">{{ $offlineDevices ?? 0 }}</h3>
                    <p class="text-xs themed-text-muted mt-1">Requiring verification</p>
                </div>
                <div class="w-12 h-12 rounded-xl pill-rose flex items-center justify-center text-xl">
                    <i class="fas fa-plug-circle-xmark"></i>
                </div>
            </div>

            <!-- Terminal Roles -->
            <div class="themed-card rounded-xl p-5 border shadow-xs flex items-center justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wider font-semibold themed-text-muted">Terminal Roles</p>
                    <div class="flex items-center gap-1.5 mt-2 flex-wrap">
                        <button type="button" id="kpiBtnOperating" class="text-xs px-2 py-1 rounded pill-blue font-semibold cursor-pointer hover:opacity-85 transition-all active:scale-95" title="Click to filter Operating devices">
                            <i class="fas fa-id-card mr-1"></i> <span id="statOperating">{{ $operatingDevices ?? 0 }}</span> Operating
                        </button>
                        <button type="button" id="kpiBtnRegistering" class="text-xs px-2 py-1 rounded pill-amber font-semibold cursor-pointer hover:opacity-85 transition-all active:scale-95" title="Click to filter Registering devices">
                            <i class="fas fa-user-plus mr-1"></i> <span id="statRegistering">{{ $registeringDevices ?? 0 }}</span> Registering
                        </button>
                        <button type="button" id="kpiBtnAttendance" class="text-xs px-2 py-1 rounded pill-purple font-semibold cursor-pointer hover:opacity-85 transition-all active:scale-95" title="Click to filter Attendance devices">
                            <i class="fas fa-calendar-check mr-1"></i> <span id="statAttendance">{{ $attendanceDevices ?? 0 }}</span> Attendance
                        </button>
                    </div>
                    <p class="text-xs themed-text-muted mt-1.5">Click any role to filter inventory</p>
                </div>
                <div class="w-12 h-12 rounded-xl pill-amber flex items-center justify-center text-xl">
                    <i class="fas fa-layer-group"></i>
                </div>
            </div>
        </div>

        <!-- Network Health & Live Status -->
        <div class="themed-card rounded-xl p-4 border shadow-xs flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="w-full md:w-2/3">
                <div class="flex items-center justify-between text-xs font-semibold mb-1.5">
                    <span class="themed-text-secondary"><i class="fas fa-heartbeat text-emerald-600 dark:text-emerald-400 mr-1.5"></i>Network Availability Ratio</span>
                    <span id="healthPercentText" class="text-emerald-600 dark:text-emerald-400 font-bold">{{ $availabilityRate ?? 0 }}%</span>
                </div>
                <div class="w-full h-2.5 rounded-full overflow-hidden" style="background: var(--track-bg);">
                    <div id="healthBar" class="h-full bg-gradient-to-r from-emerald-500 to-teal-400 rounded-full transition-all duration-500" style="width: {{ $availabilityRate ?? 0 }}%;"></div>
                </div>
            </div>
            <div class="flex items-center gap-3 text-xs themed-text-muted self-end md:self-center">
                <span class="inline-flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400 font-medium">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span> Live Poll Ready
                </span>
                <span>•</span>
                <span>Last updated: <strong id="lastUpdatedTime" class="themed-text-primary">Just now</strong></span>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="themed-card rounded-xl p-4 border shadow-xs space-y-3">
            <div class="flex flex-col md:flex-row items-center justify-between gap-3">
                <!-- Search Box -->
                <div class="relative w-full md:w-96">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 themed-text-muted text-xs"></i>
                    <input type="text" id="searchInput" placeholder="Search name, IP, serial, MAC..." 
                        class="w-full pl-9 pr-8 py-2 text-xs rounded-lg themed-input transition-all">
                    <button id="clearSearchBtn" class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                        <i class="fas fa-times-circle text-xs"></i>
                    </button>
                </div>

                <!-- Filters -->
                <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
                    <!-- Status Filter -->
                    <div class="flex items-center gap-1.5 text-xs">
                        <span class="themed-text-muted font-medium">Status:</span>
                        <select id="filterStatus" class="themed-input rounded-lg px-2.5 py-1.5 text-xs font-medium cursor-pointer">
                            <option value="all" selected>All</option>
                            <option value="online">Online</option>
                            <option value="offline">Offline</option>
                        </select>
                    </div>

                    <!-- Role Filter -->
                    <div class="flex items-center gap-1.5 text-xs">
                        <span class="themed-text-muted font-medium">Role:</span>
                        <select id="filterType" class="themed-input rounded-lg px-2.5 py-1.5 text-xs font-medium cursor-pointer">
                            <option value="all" selected>All Roles</option>
                            <option value="operating">Operating Only</option>
                            <option value="registering">Registering Only</option>
                            <option value="attendance">Attendance</option>
                        </select>
                    </div>

                    <!-- Active Filter -->
                    <div class="flex items-center gap-1.5 text-xs">
                        <span class="themed-text-muted font-medium">Active:</span>
                        <select id="filterActive" class="themed-input rounded-lg px-2.5 py-1.5 text-xs font-medium cursor-pointer">
                            <option value="all" selected>All</option>
                            <option value="1">Enabled</option>
                            <option value="0">Disabled</option>
                        </select>
                    </div>

                    <!-- Per Page -->
                    <div class="flex items-center gap-1.5 text-xs">
                        <span class="themed-text-muted font-medium">Show:</span>
                        <select id="filterPerPage" class="themed-input rounded-lg px-2.5 py-1.5 text-xs font-medium cursor-pointer">
                            <option value="10" selected>10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="all">All</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Floating Batch Actions Bar (hidden by default) -->
            <div id="batchActionBar" class="hidden batch-bar rounded-lg p-2.5 flex items-center justify-between text-xs transition-all">
                <div class="flex items-center gap-2">
                    <span class="w-5 h-5 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-[10px]" id="selectedCountBadge">0</span>
                    <span class="font-semibold"><span id="selectedCountText">0</span> device(s) selected</span>
                </div>
                <div class="flex items-center gap-2">
                    <button id="batchTestBtn" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-md font-semibold flex items-center gap-1.5 transition-colors shadow-xs">
                        <i class="fas fa-bolt text-xs"></i> Test Connection
                    </button>
                    <button id="batchSyncBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-md font-semibold flex items-center gap-1.5 transition-colors shadow-xs">
                        <i class="fas fa-clock text-xs"></i> Sync Time
                    </button>
                    <button id="batchRestartBtn" class="bg-rose-600 hover:bg-rose-700 text-white px-3 py-1.5 rounded-md font-semibold flex items-center gap-1.5 transition-colors shadow-xs">
                        <i class="fas fa-redo-alt text-xs"></i> Restart
                    </button>
                    <button id="batchClearSelectionBtn" class="btn-secondary px-2.5 py-1.5 rounded-md" title="Clear selection">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Devices Data Table -->
        <div class="themed-card rounded-xl border shadow-xs overflow-hidden">
            <div class="overflow-x-auto scrollbar-thin">
                <table class="w-full text-left text-xs">
                    <thead class="themed-thead uppercase text-[10px] font-bold tracking-wider select-none">
                        <tr>
                            <th class="py-3.5 px-4 w-10 text-center">
                                <input type="checkbox" id="selectAllCheckbox" class="rounded border-slate-400 dark:border-slate-600 text-blue-600 focus:ring-0 cursor-pointer">
                            </th>
                            <th class="py-3.5 px-4">Status</th>
                            <th class="th-sortable py-3.5 px-4 cursor-pointer" id="sortByName">
                                Device Name <i class="fas fa-sort text-[10px] ml-1 opacity-60"></i>
                            </th>
                            <th class="th-sortable py-3.5 px-4 cursor-pointer" id="sortByIp">
                                IP & Ports <i class="fas fa-sort text-[10px] ml-1 opacity-60"></i>
                            </th>
                            <th class="py-3.5 px-4">Hardware Identifiers</th>
                            <th class="py-3.5 px-4">Roles & Classification</th>
                            <th class="th-sortable py-3.5 px-4 cursor-pointer" id="sortByLastSeen">
                                Last Seen <i class="fas fa-sort text-[10px] ml-1 opacity-60"></i>
                            </th>
                            <th class="py-3.5 px-4 text-right pr-6">Quick Actions</th>
                        </tr>
                    </thead>
                    <tbody id="deviceTableBody" class="divide-y themed-border">
                        <!-- Loaded via JavaScript -->
                        <tr>
                            <td colspan="8" class="py-12 text-center themed-text-muted">
                                <i class="fas fa-circle-notch fa-spin text-2xl text-blue-500 mb-2"></i>
                                <p>Loading biometric terminals...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <div class="px-5 py-3.5 border-t themed-border flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
                <div class="themed-text-muted">
                    Showing <span id="pagFrom" class="font-bold themed-text-primary">0</span> to 
                    <span id="pagTo" class="font-bold themed-text-primary">0</span> of 
                    <span id="pagTotal" class="font-bold themed-text-primary">0</span> devices
                </div>
                <div id="paginationControls" class="flex items-center gap-1.5">
                    <!-- Page buttons rendered dynamically -->
                </div>
            </div>
        </div>

    </main>

    <!-- Footer -->
    <footer class="themed-bg themed-border border-t py-4 px-6 text-center text-xs themed-text-muted">
        <p>&copy; {{ date('Y') }} DTRservice Platform • ZCMC Health Information Management Biometrics Infrastructure</p>
    </footer>

    <!-- ==================== MODALS ==================== -->

    <!-- 1. EDIT DEVICE & ROLES MODAL -->
    <div id="editNameModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: var(--modal-overlay); backdrop-filter: blur(4px);">
        <div class="themed-card rounded-2xl border max-w-md w-full p-6 shadow-2xl space-y-5">
            <div class="flex items-center justify-between pb-3 border-b themed-border">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl pill-blue flex items-center justify-center text-lg">
                        <i class="fas fa-sliders-h"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold themed-text-primary">Edit Device & Operational Roles</h3>
                        <p class="text-xs themed-text-muted">Customize friendly label and terminal modes</p>
                    </div>
                </div>
                <button type="button" class="closeModalBtn text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 text-sm p-1">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Notice: Hardware is Fixed -->
            <div class="alert-box-warning rounded-lg p-3 text-xs flex items-start gap-2.5">
                <i class="fas fa-shield-alt text-sm mt-0.5 shrink-0"></i>
                <p>Hardware identifiers (IP, Serial Number, Ports) are managed via network discovery. You can configure the friendly name, registration mode, and attendance status below.</p>
            </div>

            <form id="editNameForm" class="space-y-4">
                <input type="hidden" id="editDeviceId" value="">

                <!-- Read-Only Hardware Tags -->
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div class="themed-subcard p-2.5 rounded-lg">
                        <div class="text-[10px] uppercase font-semibold themed-text-muted flex items-center gap-1">
                            <i class="fas fa-lock text-[9px]"></i> IP Address
                        </div>
                        <div id="editDeviceIp" class="font-mono font-semibold themed-text-primary mt-1">-</div>
                    </div>
                    <div class="themed-subcard p-2.5 rounded-lg">
                        <div class="text-[10px] uppercase font-semibold themed-text-muted flex items-center gap-1">
                            <i class="fas fa-lock text-[9px]"></i> Serial Number
                        </div>
                        <div id="editDeviceSn" class="font-mono font-semibold themed-text-primary mt-1 truncate" title="">-</div>
                    </div>
                </div>

                <!-- Editable Device Name Field -->
                <div>
                    <label for="editDeviceNameInput" class="block text-xs font-semibold themed-text-secondary mb-1.5">
                        Device Friendly Name <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" id="editDeviceNameInput" required maxlength="100"
                        class="w-full px-3.5 py-2.5 text-sm rounded-lg themed-input font-medium"
                        placeholder="e.g. Live - Admin-Lobby">
                    <p id="editNameError" class="hidden text-xs text-rose-500 mt-1 font-medium"></p>
                </div>

                <!-- Terminal Classification Role (Operating vs Registering) -->
                <div class="space-y-2 pt-1">
                    <label class="block text-xs font-semibold themed-text-secondary">
                        Terminal Role / Classification
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex items-center gap-2.5 p-2.5 rounded-lg themed-subcard cursor-pointer border hover:border-blue-500/50 transition-colors">
                            <input type="radio" name="editDeviceRole" value="operating" id="editRoleOperatingRadio" class="text-blue-600 focus:ring-0">
                            <div>
                                <div class="text-xs font-bold themed-text-primary flex items-center gap-1">
                                    <i class="fas fa-id-card text-blue-500 text-[10px]"></i> Operating
                                </div>
                                <div class="text-[10px] themed-text-muted leading-tight">Daily staff clocking</div>
                            </div>
                        </label>
                        <label class="flex items-center gap-2.5 p-2.5 rounded-lg themed-subcard cursor-pointer border hover:border-amber-500/50 transition-colors">
                            <input type="radio" name="editDeviceRole" value="registering" id="editRoleRegisteringRadio" class="text-amber-500 focus:ring-0">
                            <div>
                                <div class="text-xs font-bold themed-text-primary flex items-center gap-1">
                                    <i class="fas fa-user-plus text-amber-500 text-[10px]"></i> Registering
                                </div>
                                <div class="text-[10px] themed-text-muted leading-tight">Enroll fingerprints</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Capabilities & Operational Flags -->
                <div class="space-y-2 pt-1">
                    <label class="block text-xs font-semibold themed-text-secondary">
                        Operational Flags
                    </label>

                    <!-- Attendance Capture Flag -->
                    <label class="flex items-center justify-between p-2.5 rounded-lg themed-subcard cursor-pointer border hover:border-purple-500/50 transition-colors">
                        <div class="flex items-center gap-2.5">
                            <span class="w-7 h-7 rounded-lg pill-purple flex items-center justify-center text-xs">
                                <i class="fas fa-clipboard-check"></i>
                            </span>
                            <div>
                                <div class="text-xs font-bold themed-text-primary">Attendance Capture</div>
                                <div class="text-[10px] themed-text-muted leading-tight">Designate for official event & flag ceremony attendance</div>
                            </div>
                        </div>
                        <input type="checkbox" id="editForAttendanceCheckbox" class="rounded text-purple-600 focus:ring-0 w-4 h-4 cursor-pointer">
                    </label>

                    <!-- Active Inventory Status Flag -->
                    <label class="flex items-center justify-between p-2.5 rounded-lg themed-subcard cursor-pointer border hover:border-emerald-500/50 transition-colors">
                        <div class="flex items-center gap-2.5">
                            <span class="w-7 h-7 rounded-lg pill-emerald flex items-center justify-center text-xs">
                                <i class="fas fa-power-off"></i>
                            </span>
                            <div>
                                <div class="text-xs font-bold themed-text-primary">Active Terminal</div>
                                <div class="text-[10px] themed-text-muted leading-tight">Enable terminal polling and template synchronization</div>
                            </div>
                        </div>
                        <input type="checkbox" id="editIsActiveCheckbox" class="rounded text-emerald-600 focus:ring-0 w-4 h-4 cursor-pointer">
                    </label>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2 border-t themed-border">
                    <button type="button" class="closeModalBtn btn-secondary px-4 py-2 rounded-lg text-xs font-semibold">
                        Cancel
                    </button>
                    <button type="submit" id="saveNameBtn" class="px-4 py-2 rounded-lg text-xs font-semibold bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white flex items-center gap-2 shadow-xs transition-colors">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. RESTART CONFIRMATION MODAL -->
    <div id="restartModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: var(--modal-overlay); backdrop-filter: blur(4px);">
        <div class="themed-card rounded-2xl border border-rose-500/30 max-w-md w-full p-6 shadow-2xl space-y-5">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl pill-rose flex items-center justify-center text-xl">
                    <i class="fas fa-triangle-exclamation"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold themed-text-primary">Restart Biometric Terminal?</h3>
                    <p class="text-xs themed-text-muted">Temporary disconnect warning</p>
                </div>
            </div>

            <div class="alert-box-danger rounded-lg p-3.5 text-xs space-y-1.5 leading-relaxed">
                <p class="font-bold">Caution: Terminal will reboot immediately.</p>
                <p class="text-[11px] opacity-95">
                    Restarting this hardware device will temporarily sever its network connection for approximately 45 to 60 seconds. Employees scanning attendance punches during reboot will experience a brief interruption.
                </p>
            </div>

            <!-- Target Device Details -->
            <div class="themed-subcard p-3 rounded-lg space-y-1.5 text-xs">
                <div class="flex justify-between">
                    <span class="themed-text-muted font-medium">Terminal:</span>
                    <strong id="restartDeviceName" class="themed-text-primary">-</strong>
                </div>
                <div class="flex justify-between">
                    <span class="themed-text-muted font-medium">IP Address:</span>
                    <strong id="restartDeviceIp" class="font-mono themed-text-primary">-</strong>
                </div>
                <div class="flex justify-between">
                    <span class="themed-text-muted font-medium">Serial Number:</span>
                    <strong id="restartDeviceSn" class="font-mono themed-text-primary">-</strong>
                </div>
            </div>

            <input type="hidden" id="restartDeviceId" value="">

            <div class="flex items-center justify-end gap-2.5 pt-2 border-t themed-border">
                <button type="button" class="closeModalBtn btn-secondary px-4 py-2 rounded-lg text-xs font-semibold">
                    Cancel
                </button>
                <button type="button" id="confirmRestartBtn" class="px-4 py-2 rounded-lg text-xs font-semibold bg-rose-600 hover:bg-rose-700 active:bg-rose-800 text-white flex items-center gap-2 shadow-xs transition-colors">
                    <i class="fas fa-redo-alt"></i> Confirm Restart
                </button>
            </div>
        </div>
    </div>

    <!-- 3. BATCH RESTART CONFIRMATION MODAL -->
    <div id="batchRestartModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: var(--modal-overlay); backdrop-filter: blur(4px);">
        <div class="themed-card rounded-2xl border border-rose-500/30 max-w-md w-full p-6 shadow-2xl space-y-5">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl pill-rose flex items-center justify-center text-xl">
                    <i class="fas fa-radiation"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold themed-text-primary">Restart Multiple Terminals?</h3>
                    <p class="text-xs themed-text-muted">Batch restart confirmation</p>
                </div>
            </div>

            <p class="text-xs themed-text-secondary leading-relaxed">
                You are about to restart <strong id="batchRestartCount" class="text-rose-600 dark:text-rose-400 font-bold">0</strong> biometric devices at once.
                Please ensure this does not conflict with peak hospital shift transitions.
            </p>

            <div id="batchRestartList" class="themed-subcard max-h-36 overflow-y-auto scrollbar-thin p-2 rounded-lg text-xs space-y-1">
                <!-- Injected via JS -->
            </div>

            <div class="flex items-center justify-end gap-2.5 pt-2 border-t themed-border">
                <button type="button" class="closeModalBtn btn-secondary px-4 py-2 rounded-lg text-xs font-semibold">
                    Cancel
                </button>
                <button type="button" id="confirmBatchRestartBtn" class="px-4 py-2 rounded-lg text-xs font-semibold bg-rose-600 hover:bg-rose-700 active:bg-rose-800 text-white flex items-center gap-2 shadow-xs transition-colors">
                    <i class="fas fa-redo-alt"></i> Restart All Selected
                </button>
            </div>
        </div>
    </div>

    <!-- 4. DEVICE DETAILS & HARDWARE SPECS MODAL -->
    <div id="detailsModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: var(--modal-overlay); backdrop-filter: blur(4px);">
        <div class="themed-card rounded-2xl border max-w-lg w-full p-6 shadow-2xl space-y-5">
            <div class="flex items-center justify-between pb-3 border-b themed-border">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl pill-blue flex items-center justify-center text-lg">
                        <i class="fas fa-microchip"></i>
                    </div>
                    <div>
                        <h3 id="detailsDeviceName" class="text-base font-bold themed-text-primary">-</h3>
                        <p class="text-xs themed-text-muted">Hardware specifications & diagnostics</p>
                    </div>
                </div>
                <button type="button" class="closeModalBtn text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 text-sm p-1">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Details Content Grid -->
            <div class="grid grid-cols-2 gap-3 text-xs" id="detailsGrid">
                <!-- Populated via JS -->
            </div>

            <!-- Diagnostics Action Toolbar -->
            <div class="pt-3 border-t themed-border flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" id="detailsTestBtn" class="btn-action-test px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition-all">
                        <i class="fas fa-bolt"></i> Test Connection
                    </button>
                    <button type="button" id="detailsSyncBtn" class="btn-action-sync px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition-all">
                        <i class="fas fa-clock"></i> Sync Time
                    </button>
                    <button type="button" id="detailsConfigureBtn" class="btn-action-edit px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition-all">
                        <i class="fas fa-sliders-h"></i> Configure Roles
                    </button>
                </div>
                <button type="button" class="closeModalBtn btn-secondary px-4 py-1.5 rounded-lg text-xs font-semibold">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- ==================== COMMAND RUNNER MODAL ==================== -->
    <div id="commandRunnerModal" class="hidden fixed inset-0 z-50 flex flex-col w-full h-full p-0" style="background: var(--modal-overlay); backdrop-filter: blur(6px);">
        <div id="commandRunnerCard" class="themed-card w-full h-full flex flex-col shadow-2xl overflow-hidden rounded-none border-0">
            <!-- Modal Header -->
            <div class="flex items-center justify-between px-6 py-3.5 border-b themed-border shrink-0" style="background: var(--bg-subcard);">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-600 flex items-center justify-center text-white shadow-md">
                        <i class="fas fa-terminal text-lg"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-bold themed-text-primary">Biometric & Device Command Runner</h3>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold pill-blue uppercase tracking-wider">
                                CLI GUI Console
                            </span>
                        </div>
                        <p class="text-xs themed-text-muted">Execute administrative, sync, diagnostics & recovery commands directly without terminal prompt</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" id="cmdModalExpandBtn" class="themed-text-muted hover:themed-text-primary text-sm p-2 rounded-lg themed-hover transition-all cursor-pointer" title="Toggle Windowed / Full Screen">
                        <i class="fas fa-compress" id="cmdModalExpandIcon"></i>
                    </button>
                    <button type="button" class="closeModalBtn themed-text-muted hover:themed-text-primary text-sm p-2 rounded-lg themed-hover transition-all cursor-pointer" title="Close (Esc)">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <!-- Modal Content (2 Columns on Desktop) -->
            <div class="flex-1 flex flex-col lg:flex-row min-h-0 overflow-hidden">
                <!-- Left: Form & Command Configuration -->
                <div class="w-full lg:w-5/12 xl:w-4/12 p-5 space-y-4 overflow-y-auto border-b lg:border-b-0 lg:border-r themed-border">
                    <!-- 1. Select Command -->
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold uppercase tracking-wider themed-text-secondary">
                            <i class="fas fa-list-check text-blue-500 mr-1"></i> Select Command
                        </label>
                        <select id="cmdSelect" class="w-full themed-input rounded-xl px-3 py-2 text-xs font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                            <optgroup label="Biometrics & Fingerprint Sync">
                                <option value="biometrics:sync-device">biometrics:sync-device - Sync DB Masterlist & Templates to Device(s)</option>
                                <option value="biometrics:import-from-logs">biometrics:import-from-logs - Recover Dropped Templates from Logs</option>
                            </optgroup>
                            <optgroup label="Diagnostics & Live Template Verification">
                                <option value="biometrics:check-device">biometrics:check-device - Check Live Enrolled Fingers & Auto-Fix</option>
                                <option value="biometrics:check-device-match">biometrics:check-device-match - Detect Duplicate/Conflicting Finger Slots</option>
                                <option value="biometrics:find-duplicates">biometrics:find-duplicates - Search DB for Duplicate Fingerprints</option>
                            </optgroup>
                            <optgroup label="Log Pulling & Missed Log Recovery">
                                <option value="devices:pull-logs">devices:pull-logs - Pull or Resend Attendance Logs (SOAP / ADMS)</option>
                            </optgroup>
                            <optgroup label="Log Clearing & Storage Maintenance">
                                <option value="devices:clear-logs">devices:clear-logs - Clear Terminal Attendance Memory (with Pre-Sync)</option>
                                <option value="device-logs:prune">device-logs:prune - Prune Historical Database Records</option>
                            </optgroup>
                            <optgroup label="User & Finger Slot Deletion">
                                <option value="biometrics:delete-user">biometrics:delete-user - Purge User Profile from Device(s) & DB</option>
                                <option value="biometrics:delete-finger">biometrics:delete-finger - Delete Specific Enrolled Finger Slot (0-9)</option>
                            </optgroup>
                            <optgroup label="Queue & Command Status">
                                <option value="biometrics:command-status">biometrics:command-status - View Real-Time Command Queue Status</option>
                                <option value="biometrics:clear-queue">biometrics:clear-queue - Emergency Stop & Clear Queue Files</option>
                            </optgroup>
                        </select>
                        <div id="cmdDescriptionBox" class="p-2.5 rounded-lg text-xs themed-subcard flex items-start gap-2">
                            <i class="fas fa-info-circle text-blue-500 dark:text-blue-400 mt-0.5 shrink-0"></i>
                            <div class="flex-1 text-xs themed-text-muted leading-relaxed" id="cmdDescriptionText">
                                Loading command description...
                            </div>
                            <span id="cmdGuideBadge" class="text-[10px] px-2 py-0.5 rounded font-mono font-bold pill-blue shrink-0">NEW_DEVICE_GUIDE.md</span>
                        </div>
                    </div>

                    <!-- 2. Target Device -->
                    <div id="cmdDeviceGroup" class="space-y-1.5">
                        <label class="block text-xs font-bold uppercase tracking-wider themed-text-secondary">
                            <i class="fas fa-network-wired text-indigo-500 mr-1"></i> Target Device
                        </label>
                        <select id="cmdDeviceTarget" class="w-full themed-input rounded-xl px-3 py-2 text-xs font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                            <option value="all">⚡ All Active Registered Devices</option>
                        </select>
                    </div>

                    <!-- 3. Target User / PIN Selector (Interactive Autocomplete + Manual PIN) -->
                    <div id="cmdPinGroup" class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-bold uppercase tracking-wider themed-text-secondary">
                                <i class="fas fa-user text-emerald-500 mr-1"></i> Target User / Biometric PIN
                            </label>
                            <span id="cmdPinBadge" class="text-[10px] font-semibold px-2 py-0.5 rounded pill-amber">
                                Optional
                            </span>
                        </div>

                        <!-- Selected User Tag / Chip (when chosen) -->
                        <div id="cmdSelectedUserChip" class="hidden p-2.5 rounded-xl pill-blue flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded-lg bg-blue-500/20 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold text-xs">
                                    <i class="fas fa-id-badge"></i>
                                </div>
                                <div>
                                    <div class="text-xs font-bold themed-text-primary" id="cmdSelectedUserName">Employee Name</div>
                                    <div class="text-[10px] text-blue-600 dark:text-blue-400 font-mono">Biometric PIN: <span id="cmdSelectedUserPin" class="font-bold">0</span></div>
                                </div>
                            </div>
                            <button type="button" id="cmdClearUserBtn" class="themed-text-muted hover:text-rose-500 p-1 text-xs cursor-pointer" title="Clear selected employee">
                                <i class="fas fa-times-circle"></i>
                            </button>
                        </div>

                        <!-- Autocomplete Search Input -->
                        <div class="relative" id="cmdUserSearchWrapper">
                            <div class="relative">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 themed-text-muted text-xs"></i>
                                <input type="text" id="cmdUserSearchInput" placeholder="Search employee name or enter numeric PIN..." 
                                    class="w-full themed-input rounded-xl pl-8 pr-8 py-2 text-xs font-semibold placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                    autocomplete="off">
                                <span id="cmdUserSearchSpinner" class="hidden absolute right-3 top-1/2 -translate-y-1/2 text-blue-500 dark:text-blue-400 text-xs">
                                    <i class="fas fa-circle-notch fa-spin"></i>
                                </span>
                            </div>

                            <!-- Autocomplete Dropdown Menu -->
                            <div id="cmdUserDropdown" class="hidden absolute left-0 right-0 top-full mt-1 max-h-48 overflow-y-auto themed-card border themed-border rounded-xl shadow-2xl z-30 divide-y themed-border">
                                <!-- Suggestions injected via JS -->
                            </div>
                        </div>
                    </div>

                    <!-- 4. Dynamic Options Container -->
                    <div id="cmdDynamicOptions" class="space-y-3 p-3.5 rounded-xl themed-subcard">
                        <!-- Injected dynamically based on selected command -->
                    </div>

                    <!-- 5. Destructive Command Warning / Confirmation (Conditional) -->
                    <div id="cmdDangerBox" class="hidden p-3.5 rounded-xl alert-box-danger space-y-2">
                        <div class="flex items-start gap-2 text-xs">
                            <i class="fas fa-exclamation-triangle text-rose-500 text-sm mt-0.5 shrink-0"></i>
                            <div>
                                <strong class="font-bold text-rose-600 dark:text-rose-400">Caution: High-Impact / Destructive Operation</strong>
                                <p class="text-[11px] opacity-90 mt-0.5" id="cmdDangerText">This action modifies or purges device/database data.</p>
                            </div>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer pt-1 border-t border-rose-500/25 text-xs font-semibold text-rose-600 dark:text-rose-400">
                            <input type="checkbox" id="cmdConfirmDangerCheckbox" class="rounded border-rose-500 text-rose-600 focus:ring-0">
                            <span>I understand the impact and confirm execution</span>
                        </label>
                    </div>

                    <!-- 6. CLI Command Preview Box -->
                    <div class="space-y-1">
                        <div class="flex items-center justify-between text-[11px] uppercase tracking-wider font-bold themed-text-secondary">
                            <span><i class="fas fa-terminal text-blue-500 dark:text-blue-400 mr-1"></i> Command Preview</span>
                            <button type="button" id="cmdCopyCliBtn" class="themed-text-muted hover:themed-text-primary transition-colors text-[10px] font-mono flex items-center gap-1 cursor-pointer">
                                <i class="fas fa-copy"></i> Copy CLI
                            </button>
                        </div>
                        <div class="p-2.5 rounded-xl bg-slate-900 border border-slate-700/60 dark:bg-slate-950 dark:border-slate-800 font-mono text-xs text-blue-400 flex items-center justify-between overflow-x-auto shadow-inner">
                            <code id="cmdCliPreview" class="whitespace-nowrap select-all">$ php artisan biometrics:sync-device --all-devices</code>
                        </div>
                    </div>

                    <!-- 7. Action Button -->
                    <div class="pt-1">
                        <button type="button" id="cmdExecuteBtn" class="w-full bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 active:from-blue-800 active:to-indigo-800 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold py-2.5 px-4 rounded-xl text-xs flex items-center justify-center gap-2 transition-all shadow-md cursor-pointer">
                            <i class="fas fa-play" id="cmdExecuteIcon"></i>
                            <span id="cmdExecuteText">Run Command</span>
                        </button>
                    </div>
                </div>

                <!-- Right: Live Terminal Console Output -->
                <div class="w-full lg:w-7/12 xl:w-8/12 p-5 flex flex-col bg-slate-950 text-slate-100 min-h-[340px] lg:min-h-0 border-t lg:border-t-0 lg:border-l themed-border">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse" id="consolePulse"></span>
                            <span class="font-mono text-xs font-bold text-slate-300">Terminal Output</span>
                            <span id="consoleStatusBadge" class="text-[10px] font-mono px-2 py-0.5 rounded bg-slate-800 text-slate-400 font-semibold">
                                Idle
                            </span>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" id="cmdCopyOutputBtn" class="text-slate-400 hover:text-slate-200 p-1.5 text-xs rounded hover:bg-slate-800 transition-colors cursor-pointer" title="Copy Output">
                                <i class="fas fa-copy"></i>
                            </button>
                            <button type="button" id="cmdClearOutputBtn" class="text-slate-400 hover:text-slate-200 p-1.5 text-xs rounded hover:bg-slate-800 transition-colors cursor-pointer" title="Clear Console">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Monospace Output Box -->
                    <div class="flex-1 min-h-[240px] lg:min-h-0 overflow-y-auto py-3 font-mono text-[11px] leading-relaxed select-text space-y-2 text-slate-300" id="consoleOutputScreen">
                        <div class="text-slate-500 italic">
                            Terminal console initialized.<br>
                            Select a command on the left, configure options, and click "Run Command".<br>
                            Output will appear here in real time.
                        </div>
                    </div>

                    <!-- Console Footer Meta -->
                    <div class="pt-2 border-t border-slate-800/80 flex items-center justify-between text-[10px] font-mono text-slate-500">
                        <span id="consoleDuration">Duration: -</span>
                        <span id="consoleTimestamp">Ready</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Notification Container -->
    <div id="toastContainer" class="fixed bottom-6 right-6 z-50 flex flex-col gap-2 max-w-md w-full pointer-events-none">
        <!-- Injected via JS -->
    </div>

    <!-- ==================== SCRIPTS ==================== -->
    <script>
        // State Management
        const state = {
            devices: [],
            selectedIds: new Set(),
            meta: { current_page: 1, per_page: 10, total: 0, last_page: 1, from: 0, to: 0 },
            stats: { total: {{ $totalDevices ?? 0 }}, online: {{ $onlineDevices ?? 0 }}, offline: {{ $offlineDevices ?? 0 }}, registering: {{ $registeringDevices ?? 0 }}, operating: {{ $operatingDevices ?? 0 }}, attendance: {{ $attendanceDevices ?? 0 }}, availability_rate: {{ $availabilityRate ?? 0 }} },
            search: '',
            status: 'all',
            type: 'all',
            active: 'all',
            perPage: 10,
            sortBy: 'id',
            sortDir: 'asc',
            isTestingAll: false,
            activeProbes: new Set(),
        };

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        // DOM Elements
        const deviceTableBody = document.getElementById('deviceTableBody');
        const searchInput = document.getElementById('searchInput');
        const clearSearchBtn = document.getElementById('clearSearchBtn');
        const filterStatus = document.getElementById('filterStatus');
        const filterType = document.getElementById('filterType');
        const filterActive = document.getElementById('filterActive');
        const filterPerPage = document.getElementById('filterPerPage');
        const selectAllCheckbox = document.getElementById('selectAllCheckbox');
        const batchActionBar = document.getElementById('batchActionBar');
        const selectedCountText = document.getElementById('selectedCountText');
        const selectedCountBadge = document.getElementById('selectedCountBadge');
        const refreshBtn = document.getElementById('refreshBtn');
        const refreshIcon = document.getElementById('refreshIcon');
        const testAllBtn = document.getElementById('testAllBtn');
        const syncAllBtn = document.getElementById('syncAllBtn');

        // Modals
        const editNameModal = document.getElementById('editNameModal');
        const editNameForm = document.getElementById('editNameForm');
        const editDeviceId = document.getElementById('editDeviceId');
        const editDeviceNameInput = document.getElementById('editDeviceNameInput');
        const editDeviceIp = document.getElementById('editDeviceIp');
        const editDeviceSn = document.getElementById('editDeviceSn');
        const editNameError = document.getElementById('editNameError');

        const restartModal = document.getElementById('restartModal');
        const restartDeviceId = document.getElementById('restartDeviceId');
        const restartDeviceName = document.getElementById('restartDeviceName');
        const restartDeviceIp = document.getElementById('restartDeviceIp');
        const restartDeviceSn = document.getElementById('restartDeviceSn');
        const confirmRestartBtn = document.getElementById('confirmRestartBtn');

        const batchRestartModal = document.getElementById('batchRestartModal');
        const batchRestartCount = document.getElementById('batchRestartCount');
        const batchRestartList = document.getElementById('batchRestartList');
        const confirmBatchRestartBtn = document.getElementById('confirmBatchRestartBtn');

        const detailsModal = document.getElementById('detailsModal');
        const detailsDeviceName = document.getElementById('detailsDeviceName');
        const detailsGrid = document.getElementById('detailsGrid');

        // Theme Toggle Handler
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');
        function updateThemeIcon() {
            const isLight = document.documentElement.classList.contains('light');
            themeIcon.className = isLight ? 'fas fa-moon text-indigo-600 text-sm' : 'fas fa-sun text-yellow-400 text-sm';
        }
        updateThemeIcon();
        themeToggle.addEventListener('click', () => {
            const isLight = document.documentElement.classList.toggle('light');
            localStorage.setItem('alertTheme', isLight ? 'light' : 'dark');
            localStorage.setItem('deviceTheme', isLight ? 'light' : 'dark');
            updateThemeIcon();
        });

        // Fullscreen Toggle Handler
        const fullscreenBtn = document.getElementById('fullscreenBtn');
        const fullscreenIcon = document.getElementById('fullscreenIcon');
        if (fullscreenBtn) {
            fullscreenBtn.addEventListener('click', () => {
                if (!document.fullscreenElement) {
                    document.documentElement.requestFullscreen().catch(err => {
                        console.warn('Fullscreen mode error:', err);
                    });
                } else {
                    if (document.exitFullscreen) {
                        document.exitFullscreen();
                    }
                }
            });
            document.addEventListener('fullscreenchange', () => {
                if (document.fullscreenElement) {
                    fullscreenIcon.className = 'fas fa-compress text-blue-500';
                    fullscreenBtn.title = 'Exit Fullscreen View';
                } else {
                    fullscreenIcon.className = 'fas fa-expand';
                    fullscreenBtn.title = 'Toggle Fullscreen View';
                }
            });
        }

        // Toast Notification Function
        function showToast(message, type = 'info', duration = 4000) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'pointer-events-auto p-3.5 rounded-xl border shadow-xl flex items-start gap-3 transform transition-all duration-200 translate-y-2 opacity-0 text-xs font-medium';

            const isLight = document.documentElement.classList.contains('light');
            let bgClass = isLight ? 'bg-white border-slate-300 text-slate-800 shadow-lg' : 'bg-slate-900 border-slate-700 text-slate-100';
            let iconClass = isLight ? 'fa-info-circle text-blue-600' : 'fa-info-circle text-blue-400';

            if (type === 'success') {
                bgClass = isLight ? 'bg-emerald-50 border-emerald-300 text-emerald-900 shadow-lg' : 'bg-emerald-950 border-emerald-800 text-emerald-100';
                iconClass = isLight ? 'fa-check-circle text-emerald-600' : 'fa-check-circle text-emerald-400';
            } else if (type === 'error') {
                bgClass = isLight ? 'bg-rose-50 border-rose-300 text-rose-900 shadow-lg' : 'bg-rose-950 border-rose-800 text-rose-100';
                iconClass = isLight ? 'fa-exclamation-circle text-rose-600' : 'fa-exclamation-circle text-rose-400';
            } else if (type === 'warning') {
                bgClass = isLight ? 'bg-amber-50 border-amber-300 text-amber-900 shadow-lg' : 'bg-amber-950 border-amber-800 text-amber-100';
                iconClass = isLight ? 'fa-triangle-exclamation text-amber-600' : 'fa-triangle-exclamation text-amber-400';
            }

            toast.classList.add(...bgClass.split(' '));
            toast.innerHTML = `
                <i class="fas ${iconClass} text-sm mt-0.5 shrink-0"></i>
                <div class="flex-1 leading-snug">${escapeHtml(message)}</div>
                <button class="shrink-0 opacity-60 hover:opacity-100 transition-opacity ml-1">
                    <i class="fas fa-times"></i>
                </button>
            `;

            container.appendChild(toast);
            requestAnimationFrame(() => {
                toast.classList.remove('translate-y-2', 'opacity-0');
            });

            const closeBtn = toast.querySelector('button');
            const dismiss = () => {
                toast.classList.add('opacity-0', 'translate-y-2');
                setTimeout(() => toast.remove(), 200);
            };
            closeBtn.addEventListener('click', dismiss);
            if (duration > 0) setTimeout(dismiss, duration);
        }

        // Helper: Escape HTML
        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Fetch Devices from Server
        async function fetchDevices() {
            refreshIcon.classList.add('fa-spin');
            try {
                const params = new URLSearchParams({
                    page: state.meta.current_page,
                    per_page: state.perPage,
                    search: state.search,
                    status: state.status,
                    type: state.type,
                    active: state.active,
                    sort_by: state.sortBy,
                    sort_dir: state.sortDir,
                });

                const res = await fetch(`/api/devices/paginated?${params.toString()}`);
                const result = await res.json();

                if (result.success) {
                    state.devices = result.data || [];
                    state.meta = result.meta || state.meta;
                    state.stats = result.stats || state.stats;

                    renderStats();
                    renderTable();
                    renderPagination();
                    updateLastUpdated();
                    if (typeof updateCmdRunnerDevices === 'function') updateCmdRunnerDevices(state.devices);
                } else {
                    showToast(result.message || 'Failed to fetch device data', 'error');
                }
            } catch (err) {
                showToast('Network error while loading devices: ' + err.message, 'error');
            } finally {
                refreshIcon.classList.remove('fa-spin');
            }
        }

        // Render Summary Stats Cards
        function renderStats() {
            document.getElementById('statTotal').textContent = state.stats.total || 0;
            document.getElementById('statOnline').textContent = state.stats.online || 0;
            document.getElementById('statOffline').textContent = state.stats.offline || 0;
            document.getElementById('statOperating').textContent = state.stats.operating || 0;
            document.getElementById('statRegistering').textContent = state.stats.registering || 0;
            const statAttendance = document.getElementById('statAttendance');
            if (statAttendance) {
                statAttendance.textContent = state.stats.attendance || 0;
            }

            const rate = state.stats.availability_rate || 0;
            document.getElementById('statOnlineRateBadge').textContent = `${rate}%`;
            document.getElementById('healthPercentText').textContent = `${rate}%`;
            document.getElementById('healthBar').style.width = `${rate}%`;
        }

        // Render Table Body
        function renderTable() {
            if (!state.devices || state.devices.length === 0) {
                deviceTableBody.innerHTML = `
                    <tr>
                        <td colspan="8" class="py-12 text-center themed-text-muted">
                            <div class="w-12 h-12 rounded-full themed-subcard mx-auto flex items-center justify-center text-slate-400 mb-3 text-lg">
                                <i class="fas fa-inbox"></i>
                            </div>
                            <p class="font-semibold text-sm">No biometric devices match the current filter criteria</p>
                            <p class="text-xs mt-1">Try adjusting your search terms or filter selections</p>
                        </td>
                    </tr>
                `;
                updateSelectAllState();
                return;
            }

            let html = '';
            state.devices.forEach(d => {
                const isSelected = state.selectedIds.has(d.id);
                const isChecking = state.activeProbes.has(d.id);
                const isOnline = d.is_online;

                // Status Badge
                let statusBadge = '';
                if (isChecking) {
                    statusBadge = `
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold pill-blue">
                            <i class="fas fa-circle-notch fa-spin"></i> Probing...
                        </span>
                    `;
                } else if (isOnline) {
                    const latencyTag = d.latency_ms ? `<span class="ml-1 text-[10px] opacity-75 font-mono">(${d.latency_ms}ms)</span>` : '';
                    statusBadge = `
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold pill-emerald">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 pulse-online"></span> Online ${latencyTag}
                        </span>
                    `;
                } else {
                    statusBadge = `
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold pill-rose">
                            <span class="w-2 h-2 rounded-full bg-rose-500 pulse-offline"></span> Offline
                        </span>
                    `;
                }

                // Type & Role Badges (Click-to-Toggle)
                const roleBadge = d.is_registration 
                    ? `<button class="btnToggleRole px-2 py-0.5 rounded text-[10px] font-semibold pill-amber hover:opacity-80 active:scale-95 transition-all flex items-center gap-1.5 shadow-2xs cursor-pointer" 
                        data-id="${d.id}" data-field="is_registration" data-val="0" title="Designated as Registering Terminal. Click to switch to Operating Terminal">
                        <i class="fas fa-user-plus text-[9px]"></i> <span>Registering</span> <i class="fas fa-exchange-alt text-[8px] opacity-60"></i>
                       </button>`
                    : `<button class="btnToggleRole px-2 py-0.5 rounded text-[10px] font-semibold pill-blue hover:opacity-80 active:scale-95 transition-all flex items-center gap-1.5 shadow-2xs cursor-pointer" 
                        data-id="${d.id}" data-field="is_registration" data-val="1" title="Designated as Operating Terminal. Click to switch to Registering Terminal">
                        <i class="fas fa-id-card text-[9px]"></i> <span>Operating</span> <i class="fas fa-exchange-alt text-[8px] opacity-60"></i>
                       </button>`;

                const attBadge = d.for_attendance
                    ? `<button class="btnToggleRole px-1.5 py-0.5 rounded text-[9px] font-semibold pill-purple hover:opacity-80 active:scale-95 transition-all flex items-center gap-1 shadow-2xs cursor-pointer" 
                        data-id="${d.id}" data-field="for_attendance" data-val="0" title="Designated for official attendance capture. Click to unset.">
                        <i class="fas fa-clipboard-check text-[9px]"></i> Attendance
                       </button>`
                    : `<button class="btnToggleRole px-1.5 py-0.5 rounded text-[9px] font-medium border border-dashed border-slate-300 dark:border-slate-700 text-slate-400 hover:text-purple-600 hover:border-purple-400 dark:hover:text-purple-400 active:scale-95 transition-all flex items-center gap-1 cursor-pointer" 
                        data-id="${d.id}" data-field="for_attendance" data-val="1" title="Click to designate for Attendance Capture">
                        <i class="fas fa-plus text-[8px]"></i> Attendance
                       </button>`;

                html += `
                    <tr class="themed-hover transition-colors ${isSelected ? 'bg-blue-500/5' : ''}" data-device-id="${d.id}">
                        <!-- Checkbox -->
                        <td class="py-3 px-4 text-center">
                            <input type="checkbox" class="rowCheckbox rounded border-slate-400 dark:border-slate-600 text-blue-600 focus:ring-0 cursor-pointer" 
                                data-id="${d.id}" ${isSelected ? 'checked' : ''}>
                        </td>

                        <!-- Status -->
                        <td class="py-3 px-4" id="statusCol_${d.id}">
                            ${statusBadge}
                        </td>

                        <!-- Device Name with Quick Rename Icon -->
                        <td class="py-3 px-4">
                            <div class="flex items-center gap-2 group">
                                <span class="font-bold themed-text-primary text-xs" id="nameText_${d.id}">${escapeHtml(d.device_name)}</span>
                                <button class="btnEditName opacity-0 group-hover:opacity-100 text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 transition-opacity p-1 text-[11px]" 
                                    data-id="${d.id}" data-name="${escapeHtml(d.device_name)}" data-ip="${escapeHtml(d.ip_address)}" data-sn="${escapeHtml(d.serial_number || '')}"
                                    data-is-reg="${d.is_registration ? 1 : 0}" data-for-att="${d.for_attendance ? 1 : 0}" data-is-active="${d.is_active ? 1 : 0}"
                                    title="Edit Device Settings & Roles">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                            </div>
                            <div class="text-[10px] themed-text-muted mt-0.5">ID: #${d.id} • Terminal #${d.device_id || d.id}</div>
                        </td>

                        <!-- IP & Networking Ports -->
                        <td class="py-3 px-4 font-mono text-xs">
                            <div class="flex items-center gap-1.5 font-bold themed-text-primary">
                                <i class="fas fa-network-wired text-[10px] themed-text-muted"></i>
                                <span>${escapeHtml(d.ip_address || 'Unassigned')}</span>
                            </div>
                            <div class="text-[10px] themed-text-muted mt-0.5">
                                SOAP: <span class="themed-text-secondary">${d.soap_port || 80}</span> • 
                                UDP: <span class="themed-text-secondary">${d.udp_port || 4370}</span> • 
                                Key: <span class="themed-text-secondary">${d.com_key || 0}</span>
                            </div>
                        </td>

                        <!-- Hardware Identifiers -->
                        <td class="py-3 px-4 text-xs font-mono">
                            <div class="flex items-center gap-1.5" title="Device Serial Number">
                                <span class="font-semibold themed-text-secondary">${escapeHtml(d.serial_number || 'N/A')}</span>
                                ${d.serial_number ? `
                                    <button class="btnCopy text-[10px] text-slate-400 hover:text-blue-600 dark:hover:text-blue-400" data-clipboard="${escapeHtml(d.serial_number)}" title="Copy Serial Number">
                                        <i class="far fa-copy"></i>
                                    </button>
                                ` : ''}
                            </div>
                            <div class="text-[10px] themed-text-muted mt-0.5 truncate max-w-[140px]" title="${escapeHtml(d.mac_address || 'MAC Not Bound')}">
                                MAC: ${escapeHtml(d.mac_address || 'Auto-bound')}
                            </div>
                        </td>

                        <!-- Roles & Classification -->
                        <td class="py-3 px-4">
                            <div class="flex flex-col gap-1">
                                <div>${roleBadge}</div>
                                <div>${attBadge}</div>
                            </div>
                        </td>

                        <!-- Last Seen -->
                        <td class="py-3 px-4 text-xs">
                            <div class="font-medium themed-text-secondary" title="${escapeHtml(d.last_seen_at || 'Never connected')}">
                                ${escapeHtml(d.last_seen_human || 'Never')}
                            </div>
                            <div class="text-[10px] themed-text-muted mt-0.5 font-mono">
                                ${d.last_seen_at ? escapeHtml(d.last_seen_at) : 'No recorded ping'}
                            </div>
                        </td>

                        <!-- Actions Column -->
                        <td class="py-3 px-4 text-right pr-6">
                            <div class="inline-flex items-center gap-1.5">
                                <!-- Test Connection -->
                                <button class="btnTest btn-action-test p-2 rounded-lg text-xs font-semibold" 
                                    data-id="${d.id}" title="Test Connection & Latency">
                                    <i class="fas fa-bolt"></i>
                                </button>

                                <!-- Sync Time -->
                                <button class="btnSyncTime btn-action-sync p-2 rounded-lg text-xs font-semibold" 
                                    data-id="${d.id}" data-name="${escapeHtml(d.device_name)}" title="Synchronize Server Clock">
                                    <i class="fas fa-clock"></i>
                                </button>

                                <!-- Restart -->
                                <button class="btnRestart btn-action-restart p-2 rounded-lg text-xs font-semibold" 
                                    data-id="${d.id}" data-name="${escapeHtml(d.device_name)}" data-ip="${escapeHtml(d.ip_address)}" data-sn="${escapeHtml(d.serial_number || '')}" title="Restart Terminal">
                                    <i class="fas fa-redo-alt"></i>
                                </button>

                                <!-- Edit Name & Roles -->
                                <button class="btnEditName btn-action-edit p-2 rounded-lg text-xs font-semibold" 
                                    data-id="${d.id}" data-name="${escapeHtml(d.device_name)}" data-ip="${escapeHtml(d.ip_address)}" data-sn="${escapeHtml(d.serial_number || '')}"
                                    data-is-reg="${d.is_registration ? 1 : 0}" data-for-att="${d.for_attendance ? 1 : 0}" data-is-active="${d.is_active ? 1 : 0}"
                                    title="Edit Device Name & Roles">
                                    <i class="fas fa-sliders-h"></i>
                                </button>

                                <!-- Hardware Details -->
                                <button class="btnDetails btn-secondary p-2 rounded-lg text-xs" 
                                    data-id="${d.id}" title="Technical Specifications">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>

                                <!-- Run Command for this Device -->
                                <button class="btnRowRunCommand btn-secondary p-2 rounded-lg text-xs hover:text-blue-500 hover:border-blue-400" 
                                    data-id="${d.id}" data-name="${escapeHtml(d.device_name)}" data-sn="${escapeHtml(d.serial_number || '')}" data-ip="${escapeHtml(d.ip_address)}" title="Run Command for this Device">
                                    <i class="fas fa-terminal text-blue-600 dark:text-blue-400"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });

            deviceTableBody.innerHTML = html;
            attachRowEventListeners();
            updateSelectAllState();
        }

        // Attach dynamic row event listeners
        function attachRowEventListeners() {
            // Checkboxes
            document.querySelectorAll('.rowCheckbox').forEach(cb => {
                cb.addEventListener('change', (e) => {
                    const id = parseInt(e.target.dataset.id, 10);
                    if (e.target.checked) {
                        state.selectedIds.add(id);
                    } else {
                        state.selectedIds.delete(id);
                    }
                    updateSelectionUI();
                });
            });

            // Copy to clipboard buttons
            document.querySelectorAll('.btnCopy').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const text = btn.dataset.clipboard;
                    if (text) {
                        navigator.clipboard.writeText(text);
                        showToast(`Copied "${text}" to clipboard`, 'info', 2000);
                    }
                });
            });

            // Test Connection button
            document.querySelectorAll('.btnTest').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    const id = parseInt(btn.dataset.id, 10);
                    await testSingleDevice(id);
                });
            });

            // Sync Time button
            document.querySelectorAll('.btnSyncTime').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    const id = parseInt(btn.dataset.id, 10);
                    const name = btn.dataset.name;
                    await syncDeviceClock(id, name);
                });
            });

            // Restart button (opens modal)
            document.querySelectorAll('.btnRestart').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    restartDeviceId.value = btn.dataset.id;
                    restartDeviceName.textContent = btn.dataset.name;
                    restartDeviceIp.textContent = btn.dataset.ip;
                    restartDeviceSn.textContent = btn.dataset.sn || 'None';
                    openModal(restartModal);
                });
            });

            // Toggle Role / Attendance quick buttons
            document.querySelectorAll('.btnToggleRole').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    const id = parseInt(btn.dataset.id, 10);
                    const field = btn.dataset.field;
                    const val = parseInt(btn.dataset.val, 10);
                    await toggleDeviceRole(id, field, val);
                });
            });

            // Edit Name & Roles button (opens modal)
            document.querySelectorAll('.btnEditName').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    editDeviceId.value = btn.dataset.id;
                    editDeviceNameInput.value = btn.dataset.name;
                    editDeviceIp.textContent = btn.dataset.ip;
                    editDeviceSn.textContent = btn.dataset.sn || 'None';
                    editDeviceSn.title = btn.dataset.sn || 'None';

                    const isReg = btn.dataset.isReg === '1';
                    document.getElementById('editRoleRegisteringRadio').checked = isReg;
                    document.getElementById('editRoleOperatingRadio').checked = !isReg;
                    document.getElementById('editForAttendanceCheckbox').checked = (btn.dataset.forAtt === '1');
                    document.getElementById('editIsActiveCheckbox').checked = (btn.dataset.isActive === '1');

                    editNameError.classList.add('hidden');
                    openModal(editNameModal);
                    setTimeout(() => editDeviceNameInput.focus(), 50);
                });
            });

            // Details button
            document.querySelectorAll('.btnDetails').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = parseInt(btn.dataset.id, 10);
                    const dev = state.devices.find(x => x.id === id);
                    if (dev) showDeviceDetails(dev);
                });
            });

            // Run Command for specific row button
            document.querySelectorAll('.btnRowRunCommand').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = parseInt(btn.dataset.id, 10);
                    openCommandRunnerForDevice(id);
                });
            });
        }

        // Test Single Device Connection
        async function testSingleDevice(id) {
            state.activeProbes.add(id);
            renderTable();

            try {
                const res = await fetch(`/api/devices/${id}/test-connection`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
                });
                const result = await res.json();

                if (result.success && result.data) {
                    const d = result.data;
                    // Update state device
                    const match = state.devices.find(x => x.id === id);
                    if (match) {
                        match.is_online = d.is_online;
                        match.connection_status = d.status;
                        match.latency_ms = d.latency_ms;
                        match.last_seen_at = d.last_seen_at;
                        match.last_seen_human = d.last_seen_human;
                    }

                    if (d.is_online) {
                        showToast(d.message || `Terminal is online (${d.latency_ms}ms)`, 'success');
                    } else {
                        showToast(d.message || 'Terminal connection timed out', 'warning');
                    }
                } else {
                    showToast(result.message || 'Failed to test device connection', 'error');
                }
            } catch (err) {
                showToast('Test connection probe error: ' + err.message, 'error');
            } finally {
                state.activeProbes.delete(id);
                renderTable();
            }
        }

        // Sync Clock on Device
        async function syncDeviceClock(id, name) {
            showToast(`Synchronizing server clock with ${name}...`, 'info', 2000);
            try {
                const res = await fetch(`/api/devices/${id}/sync-time`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
                });
                const result = await res.json();
                if (result.success) {
                    showToast(result.message || `Clock synced on ${name}`, 'success');
                } else {
                    showToast(result.message || `Failed to sync clock on ${name}`, 'error');
                }
            } catch (err) {
                showToast('Sync time error: ' + err.message, 'error');
            }
        }

        // Confirm Single Device Restart
        confirmRestartBtn.addEventListener('click', async () => {
            const id = parseInt(restartDeviceId.value, 10);
            const name = restartDeviceName.textContent;
            closeModal(restartModal);

            showToast(`Sending reboot command to ${name}...`, 'warning', 3000);
            try {
                const res = await fetch(`/api/devices/${id}/restart`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
                });
                const result = await res.json();
                if (result.success) {
                    showToast(result.message || `Restart signal dispatched to ${name}`, 'success');
                } else {
                    showToast(result.message || `Failed to restart ${name}`, 'error');
                }
            } catch (err) {
                showToast('Restart error: ' + err.message, 'error');
            }
        });

        // Edit Device Form Submission
        editNameForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = parseInt(editDeviceId.value, 10);
            const newName = editDeviceNameInput.value.trim();
            const isReg = document.getElementById('editRoleRegisteringRadio').checked ? 1 : 0;
            const forAtt = document.getElementById('editForAttendanceCheckbox').checked ? 1 : 0;
            const isActive = document.getElementById('editIsActiveCheckbox').checked ? 1 : 0;

            if (!newName) {
                editNameError.textContent = 'Device name cannot be blank.';
                editNameError.classList.remove('hidden');
                return;
            }

            const saveBtn = document.getElementById('saveNameBtn');
            const originalText = saveBtn.innerHTML;
            saveBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Saving...';
            saveBtn.disabled = true;

            try {
                const res = await fetch(`/api/devices/${id}/name`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ 
                        device_name: newName,
                        is_registration: isReg,
                        for_attendance: forAtt,
                        is_active: isActive
                    })
                });
                const result = await res.json();

                if (result.success && result.data) {
                    const match = state.devices.find(x => x.id === id);
                    if (match) {
                        match.device_name = result.data.device_name;
                        match.is_registration = result.data.is_registration;
                        match.for_attendance = result.data.for_attendance;
                        match.is_active = result.data.is_active;
                    }

                    recalculateStats();
                    renderStats();
                    showToast(result.message || 'Device configuration updated successfully', 'success');
                    closeModal(editNameModal);
                    renderTable();
                } else {
                    editNameError.textContent = result.message || 'Failed to update device.';
                    editNameError.classList.remove('hidden');
                }
            } catch (err) {
                editNameError.textContent = err.message || 'Network error.';
                editNameError.classList.remove('hidden');
            } finally {
                saveBtn.innerHTML = originalText;
                saveBtn.disabled = false;
            }
        });

        // Quick toggle single role or attendance flag
        async function toggleDeviceRole(id, field, value) {
            const dev = state.devices.find(x => x.id === id);
            const devName = dev ? dev.device_name : `Device #${id}`;

            try {
                const res = await fetch(`/api/devices/${id}/roles`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ [field]: value })
                });
                const result = await res.json();

                if (result.success && result.data) {
                    if (dev) {
                        dev.is_registration = result.data.is_registration;
                        dev.for_attendance = result.data.for_attendance;
                        dev.is_active = result.data.is_active;
                    }

                    recalculateStats();
                    renderStats();
                    renderTable();
                    showToast(result.message || `Updated configuration for ${devName}`, 'success', 3000);
                } else {
                    showToast(result.message || 'Failed to update role', 'error');
                }
            } catch (err) {
                showToast('Role update probe error: ' + err.message, 'error');
            }
        }

        // Recalculate operational role stats locally
        function recalculateStats() {
            let reg = 0;
            let op = 0;
            state.devices.forEach(d => {
                if (d.is_registration) reg++;
                else op++;
            });
            state.stats.registering = reg;
            state.stats.operating = op;
        }

        // Test All Connections
        testAllBtn.addEventListener('click', async () => {
            if (state.isTestingAll) return;
            state.isTestingAll = true;
            testAllBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Probing All...';
            testAllBtn.disabled = true;

            showToast('Probing network connectivity across all registered devices...', 'info', 4000);

            try {
                const res = await fetch('/api/devices/test-all', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
                });
                const result = await res.json();

                if (result.success && result.data) {
                    showToast(`Test Complete: ${result.online} online, ${result.offline} offline`, 'success', 5000);
                    // Refresh data
                    await fetchDevices();
                } else {
                    showToast(result.message || 'Batch test failed', 'error');
                }
            } catch (err) {
                showToast('Batch test probe error: ' + err.message, 'error');
            } finally {
                state.isTestingAll = false;
                testAllBtn.innerHTML = '<i class="fas fa-bolt"></i> Test All Connections';
                testAllBtn.disabled = false;
            }
        });

        // Sync All Clocks
        syncAllBtn.addEventListener('click', async () => {
            if (!confirm('Synchronize server date & time across all active terminals?')) return;

            syncAllBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Syncing...';
            syncAllBtn.disabled = true;

            try {
                const res = await fetch('/api/devices/sync-time-all', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
                });
                const result = await res.json();

                if (result.success) {
                    showToast(result.message || `Time sync command dispatched to active devices`, 'success');
                } else {
                    showToast(result.message || 'Failed to sync all devices', 'error');
                }
            } catch (err) {
                showToast('Batch sync error: ' + err.message, 'error');
            } finally {
                syncAllBtn.innerHTML = '<i class="fas fa-clock"></i> Sync All Clocks';
                syncAllBtn.disabled = false;
            }
        });

        // Batch Action Handlers
        document.getElementById('batchTestBtn').addEventListener('click', async () => {
            const ids = Array.from(state.selectedIds);
            showToast(`Testing ${ids.length} selected devices...`, 'info', 3000);
            for (const id of ids) {
                await testSingleDevice(id);
            }
            showToast(`Batch test completed for ${ids.length} devices`, 'success');
        });

        document.getElementById('batchSyncBtn').addEventListener('click', async () => {
            const ids = Array.from(state.selectedIds);
            showToast(`Syncing time on ${ids.length} selected devices...`, 'info', 3000);
            let ok = 0;
            for (const id of ids) {
                try {
                    await fetch(`/api/devices/${id}/sync-time`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
                    });
                    ok++;
                } catch (e) {}
            }
            showToast(`Synchronized time on ${ok} of ${ids.length} devices`, 'success');
        });

        document.getElementById('batchRestartBtn').addEventListener('click', () => {
            const ids = Array.from(state.selectedIds);
            batchRestartCount.textContent = ids.length;

            let listHtml = '';
            ids.forEach(id => {
                const dev = state.devices.find(x => x.id === id);
                if (dev) {
                    listHtml += `
                        <div class="flex items-center justify-between py-1 border-b themed-border last:border-0">
                            <span class="font-bold themed-text-primary">${escapeHtml(dev.device_name)}</span>
                            <span class="font-mono themed-text-muted">${escapeHtml(dev.ip_address)}</span>
                        </div>
                    `;
                }
            });
            batchRestartList.innerHTML = listHtml;
            openModal(batchRestartModal);
        });

        confirmBatchRestartBtn.addEventListener('click', async () => {
            const ids = Array.from(state.selectedIds);
            closeModal(batchRestartModal);

            try {
                const res = await fetch('/api/devices/restart-batch', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ device_ids: ids })
                });
                const result = await res.json();
                if (result.success) {
                    showToast(result.message || `Batch restart dispatched to ${ids.length} devices`, 'success');
                    state.selectedIds.clear();
                    updateSelectionUI();
                } else {
                    showToast(result.message || 'Batch restart failed', 'error');
                }
            } catch (err) {
                showToast('Batch restart error: ' + err.message, 'error');
            }
        });

        document.getElementById('batchClearSelectionBtn').addEventListener('click', () => {
            state.selectedIds.clear();
            updateSelectionUI();
            renderTable();
        });

        // Select All Checkbox
        selectAllCheckbox.addEventListener('change', (e) => {
            if (e.target.checked) {
                state.devices.forEach(d => state.selectedIds.add(d.id));
            } else {
                state.devices.forEach(d => state.selectedIds.delete(d.id));
            }
            updateSelectionUI();
            renderTable();
        });

        function updateSelectAllState() {
            if (state.devices.length === 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
                return;
            }
            const allSelected = state.devices.every(d => state.selectedIds.has(d.id));
            const someSelected = state.devices.some(d => state.selectedIds.has(d.id));
            selectAllCheckbox.checked = allSelected;
            selectAllCheckbox.indeterminate = someSelected && !allSelected;
        }

        function updateSelectionUI() {
            const count = state.selectedIds.size;
            selectedCountText.textContent = count;
            selectedCountBadge.textContent = count;
            if (count > 0) {
                batchActionBar.classList.remove('hidden');
            } else {
                batchActionBar.classList.add('hidden');
            }
            updateSelectAllState();
        }

        // Show Hardware Specs in Details Modal
        function showDeviceDetails(d) {
            detailsDeviceName.textContent = d.device_name;
            detailsGrid.innerHTML = `
                <div class="themed-subcard p-2.5 rounded-lg">
                    <span class="text-[10px] uppercase font-semibold themed-text-muted">Primary IP Address</span>
                    <div class="font-mono font-bold themed-text-primary mt-0.5">${escapeHtml(d.ip_address || 'Unassigned')}</div>
                </div>
                <div class="themed-subcard p-2.5 rounded-lg">
                    <span class="text-[10px] uppercase font-semibold themed-text-muted">Hardware Serial Number</span>
                    <div class="font-mono font-bold themed-text-primary mt-0.5 truncate" title="${escapeHtml(d.serial_number || 'N/A')}">${escapeHtml(d.serial_number || 'N/A')}</div>
                </div>
                <div class="themed-subcard p-2.5 rounded-lg">
                    <span class="text-[10px] uppercase font-semibold themed-text-muted">MAC Physical Address</span>
                    <div class="font-mono font-bold themed-text-primary mt-0.5">${escapeHtml(d.mac_address || 'Not Available')}</div>
                </div>
                <div class="themed-subcard p-2.5 rounded-lg">
                    <span class="text-[10px] uppercase font-semibold themed-text-muted">Connection Ports & Key</span>
                    <div class="font-mono font-bold themed-text-primary mt-0.5">SOAP: ${d.soap_port} • UDP: ${d.udp_port} • Key: ${d.com_key}</div>
                </div>
                <div class="themed-subcard p-2.5 rounded-lg">
                    <span class="text-[10px] uppercase font-semibold themed-text-muted">Classification Role</span>
                    <div class="font-bold themed-text-primary mt-0.5">${d.is_registration ? 'Registering Device' : 'Operating Terminal'}</div>
                </div>
                <div class="themed-subcard p-2.5 rounded-lg">
                    <span class="text-[10px] uppercase font-semibold themed-text-muted">Terminal Mode</span>
                    <div class="font-bold themed-text-primary mt-0.5">${d.for_attendance ? 'Attendance Capture' : 'General Terminal'}</div>
                </div>
                <div class="themed-subcard p-2.5 rounded-lg">
                    <span class="text-[10px] uppercase font-semibold themed-text-muted">Last Successful Ping</span>
                    <div class="font-bold themed-text-primary mt-0.5">${escapeHtml(d.last_seen_at || 'Never')}</div>
                </div>
                <div class="themed-subcard p-2.5 rounded-lg">
                    <span class="text-[10px] uppercase font-semibold themed-text-muted">Last Cleared Timestamp</span>
                    <div class="font-bold themed-text-primary mt-0.5">${escapeHtml(d.last_cleared_at || 'Never cleared')}</div>
                </div>
            `;

            // Wire action buttons in modal
            const testBtn = document.getElementById('detailsTestBtn');
            testBtn.onclick = async () => {
                closeModal(detailsModal);
                await testSingleDevice(d.id);
            };

            const syncBtn = document.getElementById('detailsSyncBtn');
            syncBtn.onclick = async () => {
                closeModal(detailsModal);
                await syncDeviceClock(d.id, d.device_name);
            };

            const configureBtn = document.getElementById('detailsConfigureBtn');
            if (configureBtn) {
                configureBtn.onclick = () => {
                    closeModal(detailsModal);
                    editDeviceId.value = d.id;
                    editDeviceNameInput.value = d.device_name;
                    editDeviceIp.textContent = d.ip_address;
                    editDeviceSn.textContent = d.serial_number || 'None';
                    editDeviceSn.title = d.serial_number || 'None';

                    document.getElementById('editRoleRegisteringRadio').checked = (d.is_registration == 1);
                    document.getElementById('editRoleOperatingRadio').checked = (d.is_registration != 1);
                    document.getElementById('editForAttendanceCheckbox').checked = (d.for_attendance == 1);
                    document.getElementById('editIsActiveCheckbox').checked = (d.is_active == 1);

                    editNameError.classList.add('hidden');
                    openModal(editNameModal);
                    setTimeout(() => editDeviceNameInput.focus(), 50);
                };
            }

            openModal(detailsModal);
        }

        // Render Pagination Controls
        function renderPagination() {
            document.getElementById('pagFrom').textContent = state.meta.from || 0;
            document.getElementById('pagTo').textContent = state.meta.to || 0;
            document.getElementById('pagTotal').textContent = state.meta.total || 0;

            const container = document.getElementById('paginationControls');
            const { current_page, last_page } = state.meta;

            if (last_page <= 1) {
                container.innerHTML = '';
                return;
            }

            let html = '';
            // Prev button
            html += `
                <button class="pagBtn btn-secondary px-2.5 py-1 rounded-md text-xs font-semibold ${current_page <= 1 ? 'opacity-40 cursor-not-allowed' : ''}" 
                    data-page="${current_page - 1}" ${current_page <= 1 ? 'disabled' : ''}>
                    <i class="fas fa-chevron-left text-[10px]"></i>
                </button>
            `;

            // Numbered buttons
            for (let p = 1; p <= last_page; p++) {
                if (p === 1 || p === last_page || (p >= current_page - 1 && p <= current_page + 1)) {
                    const isActive = p === current_page;
                    html += `
                        <button class="pagBtn px-3 py-1 rounded-md font-semibold text-xs transition-colors ${isActive ? 'bg-blue-600 text-white shadow-xs font-bold' : 'btn-secondary'}" 
                            data-page="${p}">
                            ${p}
                        </button>
                    `;
                } else if (p === current_page - 2 || p === current_page + 2) {
                    html += `<span class="px-1 themed-text-muted">...</span>`;
                }
            }

            // Next button
            html += `
                <button class="pagBtn btn-secondary px-2.5 py-1 rounded-md text-xs font-semibold ${current_page >= last_page ? 'opacity-40 cursor-not-allowed' : ''}" 
                    data-page="${current_page + 1}" ${current_page >= last_page ? 'disabled' : ''}>
                    <i class="fas fa-chevron-right text-[10px]"></i>
                </button>
            `;

            container.innerHTML = html;

            container.querySelectorAll('.pagBtn:not([disabled])').forEach(btn => {
                btn.addEventListener('click', () => {
                    state.meta.current_page = parseInt(btn.dataset.page, 10);
                    fetchDevices();
                });
            });
        }

        // Modal Helpers
        function openModal(modal) {
            modal.classList.remove('hidden');
        }
        function closeModal(modal) {
            modal.classList.add('hidden');
        }
        document.querySelectorAll('.closeModalBtn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const modal = e.target.closest('[id$="Modal"]');
                if (modal) closeModal(modal);
            });
        });
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                [editNameModal, restartModal, batchRestartModal, detailsModal, commandRunnerModal].forEach(m => {
                    if (m) closeModal(m);
                });
            }
        });

        // Search Input (Debounced)
        let debounceTimer = null;
        searchInput.addEventListener('input', (e) => {
            state.search = e.target.value;
            clearSearchBtn.classList.toggle('hidden', !state.search);
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                state.meta.current_page = 1;
                fetchDevices();
            }, 300);
        });

        clearSearchBtn.addEventListener('click', () => {
            searchInput.value = '';
            state.search = '';
            clearSearchBtn.classList.add('hidden');
            state.meta.current_page = 1;
            fetchDevices();
        });

        // Filters Change
        filterStatus.addEventListener('change', (e) => {
            state.status = e.target.value;
            state.meta.current_page = 1;
            fetchDevices();
        });

        filterType.addEventListener('change', (e) => {
            state.type = e.target.value;
            state.meta.current_page = 1;
            fetchDevices();
        });

        // Terminal Roles KPI Pill Quick Filter Handlers
        const kpiBtnOperating = document.getElementById('kpiBtnOperating');
        const kpiBtnRegistering = document.getElementById('kpiBtnRegistering');
        const kpiBtnAttendance = document.getElementById('kpiBtnAttendance');

        if (kpiBtnOperating) {
            kpiBtnOperating.addEventListener('click', () => {
                const nextVal = filterType.value === 'operating' ? 'all' : 'operating';
                filterType.value = nextVal;
                state.type = nextVal;
                state.meta.current_page = 1;
                fetchDevices();
            });
        }
        if (kpiBtnRegistering) {
            kpiBtnRegistering.addEventListener('click', () => {
                const nextVal = filterType.value === 'registering' ? 'all' : 'registering';
                filterType.value = nextVal;
                state.type = nextVal;
                state.meta.current_page = 1;
                fetchDevices();
            });
        }
        if (kpiBtnAttendance) {
            kpiBtnAttendance.addEventListener('click', () => {
                const nextVal = filterType.value === 'attendance' ? 'all' : 'attendance';
                filterType.value = nextVal;
                state.type = nextVal;
                state.meta.current_page = 1;
                fetchDevices();
            });
        }

        filterActive.addEventListener('change', (e) => {
            state.active = e.target.value;
            state.meta.current_page = 1;
            fetchDevices();
        });

        filterPerPage.addEventListener('change', (e) => {
            state.perPage = e.target.value;
            state.meta.current_page = 1;
            fetchDevices();
        });

        // Sorting Handlers
        document.getElementById('sortByName').addEventListener('click', () => toggleSort('device_name'));
        document.getElementById('sortByIp').addEventListener('click', () => toggleSort('ip_address'));
        document.getElementById('sortByLastSeen').addEventListener('click', () => toggleSort('last_seen_at'));

        function toggleSort(field) {
            if (state.sortBy === field) {
                state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                state.sortBy = field;
                state.sortDir = 'asc';
            }
            fetchDevices();
        }

        // Refresh Button
        refreshBtn.addEventListener('click', () => fetchDevices());

        function updateLastUpdated() {
            const d = new Date();
            document.getElementById('lastUpdatedTime').textContent = d.toLocaleTimeString();
        }

        // ==================== COMMAND RUNNER LOGIC ====================
        const cmdRunner = {
            manifest: null,
            devices: [],
            fingerNames: {},
            selectedPin: null,
            selectedEmployeeName: null,
            isExecuting: false,
            debounceTimer: null,
            todayDate: new Date().toISOString().split('T')[0],
        };

        // Command Runner DOM Elements
        const commandRunnerModal = document.getElementById('commandRunnerModal');
        const openCommandRunnerBtn = document.getElementById('openCommandRunnerBtn');
        const cmdSelect = document.getElementById('cmdSelect');
        const cmdDescriptionText = document.getElementById('cmdDescriptionText');
        const cmdGuideBadge = document.getElementById('cmdGuideBadge');
        const cmdDeviceGroup = document.getElementById('cmdDeviceGroup');
        const cmdDeviceTarget = document.getElementById('cmdDeviceTarget');
        const cmdPinGroup = document.getElementById('cmdPinGroup');
        const cmdPinBadge = document.getElementById('cmdPinBadge');
        const cmdSelectedUserChip = document.getElementById('cmdSelectedUserChip');
        const cmdSelectedUserName = document.getElementById('cmdSelectedUserName');
        const cmdSelectedUserPin = document.getElementById('cmdSelectedUserPin');
        const cmdClearUserBtn = document.getElementById('cmdClearUserBtn');
        const cmdUserSearchWrapper = document.getElementById('cmdUserSearchWrapper');
        const cmdUserSearchInput = document.getElementById('cmdUserSearchInput');
        const cmdUserSearchSpinner = document.getElementById('cmdUserSearchSpinner');
        const cmdUserDropdown = document.getElementById('cmdUserDropdown');
        const cmdDynamicOptions = document.getElementById('cmdDynamicOptions');
        const cmdDangerBox = document.getElementById('cmdDangerBox');
        const cmdDangerText = document.getElementById('cmdDangerText');
        const cmdConfirmDangerCheckbox = document.getElementById('cmdConfirmDangerCheckbox');
        const cmdCliPreview = document.getElementById('cmdCliPreview');
        const cmdCopyCliBtn = document.getElementById('cmdCopyCliBtn');
        const cmdExecuteBtn = document.getElementById('cmdExecuteBtn');
        const cmdExecuteIcon = document.getElementById('cmdExecuteIcon');
        const cmdExecuteText = document.getElementById('cmdExecuteText');
        const consoleStatusBadge = document.getElementById('consoleStatusBadge');
        const consolePulse = document.getElementById('consolePulse');
        const consoleOutputScreen = document.getElementById('consoleOutputScreen');
        const consoleDuration = document.getElementById('consoleDuration');
        const consoleTimestamp = document.getElementById('consoleTimestamp');
        const cmdCopyOutputBtn = document.getElementById('cmdCopyOutputBtn');
        const cmdClearOutputBtn = document.getElementById('cmdClearOutputBtn');
        const cmdModalExpandBtn = document.getElementById('cmdModalExpandBtn');
        const cmdModalExpandIcon = document.getElementById('cmdModalExpandIcon');
        const commandRunnerCard = document.getElementById('commandRunnerCard');
        let isCmdModalFullscreen = true;

        // Fetch manifest and populate devices
        async function initCommandRunner() {
            if (cmdRunner.manifest) return;
            try {
                const res = await fetch('/api/command-runner/manifest');
                const data = await res.json();
                if (data.success) {
                    cmdRunner.manifest = data.commands || {};
                    cmdRunner.devices = data.devices || [];
                    cmdRunner.fingerNames = data.finger_names || {};
                    populateCmdDeviceDropdown(cmdRunner.devices);
                    onCommandSelectionChange();
                }
            } catch (err) {
                console.error('Failed to initialize Command Runner manifest:', err);
            }
        }

        // Populate device dropdown
        function populateCmdDeviceDropdown(devices) {
            if (!cmdDeviceTarget) return;
            const currentVal = cmdDeviceTarget.value || 'all';
            let html = '<option value="all">⚡ All Active Registered Devices</option>';
            if (Array.isArray(devices) && devices.length > 0) {
                devices.forEach(d => {
                    const sn = d.serial_number ? `(${d.serial_number})` : '(No SN)';
                    const ip = d.ip_address || 'No IP';
                    const activeTag = d.is_active ? '' : ' [Inactive]';
                    html += `<option value="${d.id}">${escapeHtml(d.device_name)} - ${escapeHtml(ip)} ${escapeHtml(sn)}${activeTag}</option>`;
                });
            }
            cmdDeviceTarget.innerHTML = html;
            if (currentVal && cmdDeviceTarget.querySelector(`option[value="${currentVal}"]`)) {
                cmdDeviceTarget.value = currentVal;
            }
        }

        function updateCmdRunnerDevices(devices) {
            if (devices && devices.length > 0) {
                cmdRunner.devices = devices;
                populateCmdDeviceDropdown(devices);
            }
        }

        // Open modal for a specific device from row action
        function openCommandRunnerForDevice(deviceId) {
            initCommandRunner().then(() => {
                if (cmdDeviceTarget) {
                    cmdDeviceTarget.value = String(deviceId);
                }
                openModal(commandRunnerModal);
                updateCliPreview();
            });
        }

        if (openCommandRunnerBtn) {
            openCommandRunnerBtn.addEventListener('click', () => {
                initCommandRunner().then(() => {
                    openModal(commandRunnerModal);
                    updateCliPreview();
                });
            });
        }

        // Fullscreen Toggle for Command Runner Modal
        if (cmdModalExpandBtn && commandRunnerCard) {
            cmdModalExpandBtn.addEventListener('click', () => {
                isCmdModalFullscreen = !isCmdModalFullscreen;
                if (isCmdModalFullscreen) {
                    commandRunnerModal.classList.remove('items-center', 'justify-center', 'p-3', 'sm:p-4');
                    commandRunnerModal.classList.add('flex-col', 'w-full', 'h-full', 'p-0');
                    commandRunnerCard.classList.remove('rounded-2xl', 'border', 'max-w-6xl', 'max-h-[94vh]');
                    commandRunnerCard.classList.add('rounded-none', 'border-0', 'w-full', 'h-full');
                    cmdModalExpandIcon.className = 'fas fa-compress';
                    cmdModalExpandBtn.title = 'Switch to Windowed Modal';
                } else {
                    commandRunnerModal.classList.remove('flex-col', 'p-0');
                    commandRunnerModal.classList.add('items-center', 'justify-center', 'p-3', 'sm:p-4');
                    commandRunnerCard.classList.remove('rounded-none', 'border-0');
                    commandRunnerCard.classList.add('rounded-2xl', 'border', 'max-w-6xl', 'max-h-[94vh]');
                    cmdModalExpandIcon.className = 'fas fa-expand';
                    cmdModalExpandBtn.title = 'Switch to Full Screen';
                }
            });
        }

        // Handle Command selection change
        function onCommandSelectionChange() {
            const cmd = cmdSelect.value;
            const meta = (cmdRunner.manifest && cmdRunner.manifest[cmd]) ? cmdRunner.manifest[cmd] : null;

            if (meta) {
                cmdDescriptionText.textContent = meta.description || '';
                cmdGuideBadge.textContent = meta.guide || 'GUIDE';

                // Guide Badge Styling
                if (meta.guide === 'MANUAL_DEVICE_LOG_PULLING_GUIDE.md') {
                    cmdGuideBadge.className = 'text-[10px] px-2 py-0.5 rounded font-mono font-bold pill-purple shrink-0';
                } else {
                    cmdGuideBadge.className = 'text-[10px] px-2 py-0.5 rounded font-mono font-bold pill-blue shrink-0';
                }

                // PIN Requirement UI
                if (meta.pin_requirement === 'required') {
                    cmdPinGroup.classList.remove('hidden');
                    cmdPinBadge.textContent = 'Required';
                    cmdPinBadge.className = 'text-[10px] font-semibold px-2 py-0.5 rounded pill-rose';
                } else if (meta.pin_requirement === 'optional') {
                    cmdPinGroup.classList.remove('hidden');
                    cmdPinBadge.textContent = 'Optional';
                    cmdPinBadge.className = 'text-[10px] font-semibold px-2 py-0.5 rounded pill-blue';
                } else {
                    // none
                    cmdPinGroup.classList.add('hidden');
                    cmdPinBadge.textContent = 'Not Applicable';
                    cmdPinBadge.className = 'text-[10px] font-semibold px-2 py-0.5 rounded pill-amber';
                }

                // Device Requirement UI
                if (meta.device_requirement === 'none') {
                    cmdDeviceGroup.classList.add('hidden');
                } else {
                    cmdDeviceGroup.classList.remove('hidden');
                }

                // Danger Warning Box
                if (meta.danger_level === 'danger' || meta.danger_level === 'warning') {
                    cmdDangerBox.classList.remove('hidden');
                    cmdConfirmDangerCheckbox.checked = false;
                    if (cmd === 'devices:clear-logs') {
                        cmdDangerText.textContent = 'Clearing terminal logs permanently purges the local hardware attendance buffer (pre-synced to DB first).';
                    } else if (cmd === 'biometrics:clear-queue') {
                        cmdDangerText.textContent = 'Clearing the queue immediately deletes all pending command files. Devices stop receiving sync tasks.';
                    } else if (cmd === 'biometrics:delete-user') {
                        cmdDangerText.textContent = 'Deleting an enrolled user purges biometric templates from terminal(s) and optionally database.';
                    } else if (cmd === 'device-logs:prune') {
                        cmdDangerText.textContent = 'Pruning deletes historical attendance logs from MySQL database. Run Dry Run first to verify count.';
                    } else {
                        cmdDangerText.textContent = 'This administrative operation modifies or purges system data.';
                    }
                } else {
                    cmdDangerBox.classList.add('hidden');
                    cmdConfirmDangerCheckbox.checked = false;
                }
            }

            renderDynamicOptions(cmd);
            updateCliPreview();
        }

        cmdSelect.addEventListener('change', onCommandSelectionChange);
        cmdDeviceTarget.addEventListener('change', updateCliPreview);

        // Render Dynamic Options per Command
        function renderDynamicOptions(cmd) {
            let html = '';
            const today = cmdRunner.todayDate;

            switch (cmd) {
                case 'biometrics:sync-device':
                    html = `
                        <div class="space-y-2">
                            <div class="text-xs font-bold themed-text-primary">Sync & Provisioning Options</div>
                            <label class="flex items-center gap-2 text-xs themed-text-secondary cursor-pointer">
                                <input type="checkbox" id="opt_clean_unused" class="rounded border-slate-400 dark:border-slate-600 text-blue-600 focus:ring-0" checked>
                                <span>Deep clean unused finger slots (default; uncheck for fast sync: <code>--no-clean</code>)</span>
                            </label>
                            <label class="flex items-center gap-2 text-xs themed-text-secondary cursor-pointer">
                                <input type="checkbox" id="opt_table" class="rounded border-slate-400 dark:border-slate-600 text-blue-600 focus:ring-0" checked>
                                <span>Display full summary table in console (<code>--table</code>)</span>
                            </label>
                        </div>
                    `;
                    break;

                case 'biometrics:check-device':
                    html = `
                        <div class="space-y-2">
                            <div class="text-xs font-bold themed-text-primary">Diagnostic & Fix Action</div>
                            <div class="space-y-1.5 text-xs themed-text-secondary">
                                <label class="flex items-center gap-2 cursor-pointer p-2 rounded-lg themed-subcard themed-hover transition-colors">
                                    <input type="radio" name="opt_check_mode" value="inspect" checked class="text-blue-600 focus:ring-0">
                                    <div>
                                        <strong class="text-blue-600 dark:text-blue-400">Inspect Live Fingers Only</strong>
                                        <p class="text-[11px] themed-text-muted">Direct hardware SOAP query of slots 0-9 vs central database</p>
                                    </div>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer p-2 rounded-lg themed-subcard themed-hover transition-colors">
                                    <input type="radio" name="opt_check_mode" value="fix" class="text-emerald-600 focus:ring-0">
                                    <div>
                                        <strong class="text-emerald-600 dark:text-emerald-400">Auto-Fix Discrepancies (<code>--fix</code>)</strong>
                                        <p class="text-[11px] themed-text-muted">Push missing DB templates & clean ghost slots to achieve 100% sync</p>
                                    </div>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer p-2 rounded-lg themed-subcard themed-hover transition-colors">
                                    <input type="radio" name="opt_check_mode" value="clean" class="text-amber-600 focus:ring-0">
                                    <div>
                                        <strong class="text-amber-600 dark:text-amber-400">Purge Ghost Slots Only (<code>--clean</code>)</strong>
                                        <p class="text-[11px] themed-text-muted">Purge unassigned finger slots from device without pushing templates</p>
                                    </div>
                                </label>
                            </div>
                        </div>
                    `;
                    break;

                case 'biometrics:check-device-match':
                    html = `
                        <div class="space-y-2">
                            <div class="text-xs font-bold themed-text-primary">Template Match & Conflict Options</div>
                            <div class="space-y-1">
                                <label class="text-[11px] themed-text-muted">Compare against another specific PIN (optional):</label>
                                <input type="number" id="opt_compare_pin" placeholder="e.g. 8084 (detect if templates match this employee)" class="w-full themed-input rounded-lg px-3 py-1.5 text-xs font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                            </div>
                            <label class="flex items-center gap-2 text-xs themed-text-secondary cursor-pointer">
                                <input type="checkbox" id="opt_clean_match" class="rounded border-slate-400 dark:border-slate-600 text-blue-600 focus:ring-0">
                                <span>Automatically purge detected conflicting slots from terminal (<code>--clean</code>)</span>
                            </label>
                            <label class="flex items-center gap-2 text-xs themed-text-secondary cursor-pointer">
                                <input type="checkbox" id="opt_db_only" class="rounded border-slate-400 dark:border-slate-600 text-blue-600 focus:ring-0">
                                <span>Audit database templates only (skip physical terminal query: <code>--db-only</code>)</span>
                            </label>
                        </div>
                    `;
                    break;

                case 'biometrics:find-duplicates':
                    html = `
                        <div class="space-y-2 text-xs themed-text-secondary">
                            <div class="text-xs font-bold themed-text-primary">Database Duplicate Template Audit</div>
                            <p class="text-[11px] themed-text-muted">
                                Searches the central biometrics table for identical fingerprint templates.
                            </p>
                            <div class="p-2.5 rounded-lg pill-blue text-[11px]">
                                <i class="fas fa-info-circle mr-1"></i> If an Employee PIN is selected above, audits against that PIN. If no PIN is selected, audits all enrolled employees across the hospital (<code>--all</code>).
                            </div>
                        </div>
                    `;
                    break;

                case 'biometrics:command-status':
                    html = `
                        <div class="space-y-2">
                            <div class="text-xs font-bold themed-text-primary">Queue Filters</div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[11px] themed-text-muted">Filter Status:</label>
                                    <select id="opt_status_filter" class="w-full themed-input rounded-lg px-2.5 py-1.5 text-xs font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                        <option value="">All Statuses</option>
                                        <option value="PENDING">PENDING</option>
                                        <option value="SENT">SENT</option>
                                        <option value="SUCCESS">SUCCESS</option>
                                        <option value="FAILED">FAILED</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[11px] themed-text-muted">Record Limit:</label>
                                    <input type="number" id="opt_limit" value="50" min="5" max="250" class="w-full themed-input rounded-lg px-2.5 py-1.5 text-xs font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                </div>
                            </div>
                        </div>
                    `;
                    break;

                case 'biometrics:clear-queue':
                    html = `
                        <div class="p-3 rounded-lg alert-box-danger space-y-1 text-xs">
                            <div class="text-xs font-bold text-rose-600 dark:text-rose-400">Emergency Queue Reset</div>
                            <p class="text-[11px] opacity-90 leading-relaxed">
                                Instantly resets <code>device_commands.json</code> and deletes all rotated queue files.
                                Connected terminals will stop processing pending synchronization commands on their next poll.
                            </p>
                        </div>
                    `;
                    break;

                case 'biometrics:delete-user':
                    html = `
                        <div class="space-y-2">
                            <div class="text-xs font-bold themed-text-primary">User Deletion Options</div>
                            <p class="text-[11px] themed-text-muted">
                                Dispatches <code>DATA DELETE USER PIN={pin}</code> command to target terminal(s).
                            </p>
                            <label class="flex items-center gap-2 text-xs text-rose-600 dark:text-rose-400 font-semibold cursor-pointer">
                                <input type="checkbox" id="opt_with_db" class="rounded border-rose-500 text-rose-600 focus:ring-0">
                                <span>Also delete user record from database biometrics table (<code>--with-db</code>)</span>
                            </label>
                        </div>
                    `;
                    break;

                case 'biometrics:delete-finger':
                    html = `
                        <div class="space-y-2">
                            <div class="text-xs font-bold themed-text-primary">Target Finger Slot</div>
                            <label class="text-[11px] themed-text-muted">Select Finger Slot (0-9):</label>
                            <select id="opt_fid" class="w-full themed-input rounded-lg px-3 py-1.5 text-xs font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                <option value="0">Slot 0 - Right Thumb</option>
                                <option value="1">Slot 1 - Right Index</option>
                                <option value="2">Slot 2 - Right Middle</option>
                                <option value="3">Slot 3 - Right Ring</option>
                                <option value="4">Slot 4 - Right Little</option>
                                <option value="5">Slot 5 - Left Thumb</option>
                                <option value="6">Slot 6 - Left Index</option>
                                <option value="7">Slot 7 - Left Middle</option>
                                <option value="8">Slot 8 - Left Ring</option>
                                <option value="9">Slot 9 - Left Little</option>
                            </select>
                            <p class="text-[10px] themed-text-muted">
                                Deletes finger slot from DB and broadcasts deletion commands to all active connected devices.
                            </p>
                        </div>
                    `;
                    break;

                case 'biometrics:import-from-logs':
                    html = `
                        <div class="space-y-2">
                            <div class="text-xs font-bold themed-text-primary">Log Recovery Options</div>
                            <p class="text-[11px] themed-text-muted">
                                Scans raw logs in <code>storage/app/private/</code> to recover dropped templates.
                            </p>
                            <label class="flex items-center gap-2 text-xs themed-text-secondary cursor-pointer">
                                <input type="checkbox" id="opt_sync_devices" class="rounded border-slate-400 dark:border-slate-600 text-blue-600 focus:ring-0" checked>
                                <span>Sync recovered templates to all active devices (<code>--sync-devices</code>)</span>
                            </label>
                        </div>
                    `;
                    break;

                case 'devices:pull-logs':
                    html = `
                        <div class="space-y-2.5">
                            <div class="text-xs font-bold themed-text-primary">Attendance Log Pull & Resend Mode</div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[11px] themed-text-muted">Pull Protocol:</label>
                                    <select id="opt_pull_mode" class="w-full themed-input rounded-lg px-2.5 py-1.5 text-xs font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                        <option value="soap">SOAP Pull (Instant Direct SOAP Port 80)</option>
                                        <option value="adms">ADMS Push Resend (--resend)</option>
                                    </select>
                                </div>
                                <div id="opt_adms_type_box" class="hidden">
                                    <label class="text-[11px] themed-text-muted">ADMS Command Type:</label>
                                    <select id="opt_adms_type" class="w-full themed-input rounded-lg px-2.5 py-1.5 text-xs font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                        <option value="DATA QUERY ATTLOG">DATA QUERY ATTLOG (Standard)</option>
                                        <option value="LOG">LOG (Legacy Firmware)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[11px] themed-text-muted">Date Filter:</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <span class="text-[10px] themed-text-muted">Start Date:</span>
                                        <input type="date" id="opt_pull_start_date" value="${today}" class="w-full themed-input rounded-lg px-2.5 py-1 text-xs font-mono font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                    </div>
                                    <div>
                                        <span class="text-[10px] themed-text-muted">End Date:</span>
                                        <input type="date" id="opt_pull_end_date" value="${today}" class="w-full themed-input rounded-lg px-2.5 py-1 text-xs font-mono font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    break;

                case 'devices:clear-logs':
                    html = `
                        <div class="space-y-2">
                            <div class="text-xs font-bold themed-text-primary">Attendance Clearance Options</div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[11px] themed-text-muted">Wipe Protocol:</label>
                                    <select id="opt_clear_method" class="w-full themed-input rounded-lg px-2.5 py-1.5 text-xs font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                        <option value="both">Both (SOAP instant + ADMS fallback)</option>
                                        <option value="soap">SOAP Port 80 Only</option>
                                        <option value="adms">ADMS HTTP Command Only</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[11px] themed-text-muted">Catch-Up Age (Days):</label>
                                    <input type="number" id="opt_older_than" value="7" min="1" max="90" class="w-full themed-input rounded-lg px-2.5 py-1.5 text-xs font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                </div>
                            </div>
                            <div class="space-y-1.5 pt-1">
                                <label class="flex items-center gap-2 text-xs themed-text-secondary cursor-pointer">
                                    <input type="checkbox" id="opt_dry_run" class="rounded border-slate-400 dark:border-slate-600 text-blue-600 focus:ring-0">
                                    <span>Dry Run (<code>--dry-run</code>) - Test pre-sync check without wiping</span>
                                </label>
                                <label class="flex items-center gap-2 text-xs themed-text-secondary cursor-pointer">
                                    <input type="checkbox" id="opt_catch_up" class="rounded border-slate-400 dark:border-slate-600 text-blue-600 focus:ring-0">
                                    <span>Catch-up mode (<code>--catch-up</code>) - Target devices needing clearance</span>
                                </label>
                                <label class="flex items-center gap-2 text-xs text-rose-600 dark:text-rose-400 cursor-pointer">
                                    <input type="checkbox" id="opt_skip_sync" class="rounded border-rose-500 text-rose-600 focus:ring-0">
                                    <span>Skip pre-sync verification (<code>--skip-sync</code> - DANGEROUS)</span>
                                </label>
                            </div>
                        </div>
                    `;
                    break;

                case 'device-logs:prune':
                    html = `
                        <div class="space-y-2">
                            <div class="text-xs font-bold themed-text-primary">Database Retention Pruning</div>
                            <div>
                                <label class="text-[11px] themed-text-muted">Delete records older than (days):</label>
                                <input type="number" id="opt_prune_days" value="365" min="30" max="3650" class="w-full themed-input rounded-lg px-2.5 py-1.5 text-xs font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none">
                            </div>
                            <label class="flex items-center gap-2 text-xs themed-text-secondary cursor-pointer">
                                <input type="checkbox" id="opt_prune_dry_run" class="rounded border-slate-400 dark:border-slate-600 text-blue-600 focus:ring-0" checked>
                                <span>Dry Run (<code>--dry-run</code>) - Calculate matching row count without deleting</span>
                            </label>
                        </div>
                    `;
                    break;
            }

            cmdDynamicOptions.innerHTML = html;

            // Attach change listeners to dynamic elements to update CLI preview
            cmdDynamicOptions.querySelectorAll('input, select').forEach(el => {
                el.addEventListener('input', updateCliPreview);
                el.addEventListener('change', updateCliPreview);
            });

            // Handle toggle of ADMS command type box for pull-logs
            const pullModeSelect = document.getElementById('opt_pull_mode');
            const admsTypeBox = document.getElementById('opt_adms_type_box');
            if (pullModeSelect && admsTypeBox) {
                pullModeSelect.addEventListener('change', () => {
                    admsTypeBox.classList.toggle('hidden', pullModeSelect.value !== 'adms');
                    updateCliPreview();
                });
            }
        }

        // Build Live Command Preview string
        function updateCliPreview() {
            const cmd = cmdSelect.value;
            const target = cmdDeviceTarget.value;
            const pin = cmdRunner.selectedPin;
            let parts = ['php artisan', cmd];

            // Resolve device target representation
            let targetDeviceObj = null;
            if (target !== 'all') {
                targetDeviceObj = (cmdRunner.devices || []).find(d => String(d.id) === String(target)) || (state.devices || []).find(d => String(d.id) === String(target));
            }

            switch (cmd) {
                case 'biometrics:sync-device':
                    if (target === 'all') {
                        parts.push('--all-devices');
                    } else if (targetDeviceObj) {
                        parts.push(targetDeviceObj.serial_number || `DEV_${targetDeviceObj.id}`);
                    }
                    if (pin) parts.push(`--pin=${pin}`);
                    const cleanUnused = document.getElementById('opt_clean_unused');
                    if (cleanUnused && !cleanUnused.checked) parts.push('--no-clean');
                    const showTable = document.getElementById('opt_table');
                    if (showTable && showTable.checked) parts.push('--table');
                    break;

                case 'biometrics:check-device':
                    parts.push(pin ? pin : '<PIN>');
                    if (target === 'all') {
                        parts.push('--all-devices');
                    } else if (targetDeviceObj) {
                        parts.push(targetDeviceObj.serial_number || `DEV_${targetDeviceObj.id}`);
                    }
                    const checkMode = document.querySelector('input[name="opt_check_mode"]:checked')?.value || 'inspect';
                    if (checkMode === 'fix') parts.push('--fix --force');
                    else if (checkMode === 'clean') parts.push('--clean --force');
                    break;

                case 'biometrics:check-device-match':
                    parts.push(pin ? pin : '<PIN>');
                    if (target === 'all') {
                        parts.push('--all-devices');
                    } else if (targetDeviceObj) {
                        parts.push(targetDeviceObj.serial_number || `DEV_${targetDeviceObj.id}`);
                    }
                    const comparePin = document.getElementById('opt_compare_pin')?.value;
                    if (comparePin) parts.push(`--compare-pin=${comparePin}`);
                    if (document.getElementById('opt_clean_match')?.checked) parts.push('--clean --force');
                    if (document.getElementById('opt_db_only')?.checked) parts.push('--db-only');
                    break;

                case 'biometrics:find-duplicates':
                    if (pin) parts.push(pin);
                    else parts.push('--all');
                    break;

                case 'biometrics:command-status':
                    if (target !== 'all' && targetDeviceObj) parts.push(`--device=${targetDeviceObj.serial_number}`);
                    if (pin) parts.push(`--pin=${pin}`);
                    const statusFilter = document.getElementById('opt_status_filter')?.value;
                    if (statusFilter) parts.push(`--status=${statusFilter}`);
                    const limitVal = document.getElementById('opt_limit')?.value;
                    if (limitVal) parts.push(`--limit=${limitVal}`);
                    break;

                case 'biometrics:clear-queue':
                    // No options
                    break;

                case 'biometrics:delete-user':
                    parts.push(pin ? pin : '<PIN>');
                    if (target === 'all') {
                        parts.push('--all-devices');
                    } else if (targetDeviceObj) {
                        parts.push(targetDeviceObj.serial_number || `DEV_${targetDeviceObj.id}`);
                    }
                    if (document.getElementById('opt_with_db')?.checked) parts.push('--with-db');
                    break;

                case 'biometrics:delete-finger':
                    parts.push(pin ? pin : '<PIN>');
                    const fidVal = document.getElementById('opt_fid')?.value || '0';
                    parts.push(fidVal);
                    break;

                case 'biometrics:import-from-logs':
                    if (pin) parts.push(`--pin=${pin}`);
                    if (document.getElementById('opt_sync_devices')?.checked) parts.push('--sync-devices');
                    break;

                case 'devices:pull-logs':
                    if (target === 'all') {
                        parts.push('--all');
                    } else if (targetDeviceObj) {
                        parts.push(String(targetDeviceObj.id));
                    }
                    const pullMode = document.getElementById('opt_pull_mode')?.value;
                    if (pullMode === 'adms') {
                        parts.push('--resend');
                        const admsType = document.getElementById('opt_adms_type')?.value;
                        if (admsType && admsType !== 'DATA QUERY ATTLOG') parts.push(`--type="${admsType}"`);
                    }
                    const startDate = document.getElementById('opt_pull_start_date')?.value;
                    const endDate = document.getElementById('opt_pull_end_date')?.value;
                    if (startDate && endDate && startDate === endDate) {
                        parts.push(`--date=${startDate}`);
                    } else {
                        if (startDate) parts.push(`--start-date=${startDate}`);
                        if (endDate) parts.push(`--end-date=${endDate}`);
                    }
                    if (pin) parts.push(`--pin=${pin}`);
                    break;

                case 'devices:clear-logs':
                    if (target === 'all') {
                        parts.push('--all');
                    } else if (targetDeviceObj) {
                        parts.push(String(targetDeviceObj.id));
                    }
                    const clearMethod = document.getElementById('opt_clear_method')?.value;
                    if (clearMethod && clearMethod !== 'both') parts.push(`--method=${clearMethod}`);
                    if (document.getElementById('opt_dry_run')?.checked) parts.push('--dry-run');
                    if (document.getElementById('opt_catch_up')?.checked) {
                        parts.push('--catch-up');
                        const olderThan = document.getElementById('opt_older_than')?.value;
                        if (olderThan) parts.push(`--older-than=${olderThan}`);
                    }
                    if (document.getElementById('opt_skip_sync')?.checked) parts.push('--skip-sync');
                    parts.push('--force');
                    break;

                case 'device-logs:prune':
                    const pruneDays = document.getElementById('opt_prune_days')?.value || '365';
                    parts.push(`--older-than=${pruneDays}`);
                    if (document.getElementById('opt_prune_dry_run')?.checked) parts.push('--dry-run');
                    parts.push('--force');
                    break;
            }

            cmdCliPreview.textContent = '$ ' + parts.join(' ');
        }

        // Copy CLI Command
        cmdCopyCliBtn.addEventListener('click', () => {
            const text = cmdCliPreview.textContent.replace(/^\$\s*/, '');
            navigator.clipboard.writeText(text);
            showToast('Copied CLI command to clipboard', 'info', 2000);
        });

        // Copy Terminal Output
        cmdCopyOutputBtn.addEventListener('click', () => {
            const text = consoleOutputScreen.innerText;
            navigator.clipboard.writeText(text);
            showToast('Copied console output to clipboard', 'info', 2000);
        });

        // Clear Terminal Output
        cmdClearOutputBtn.addEventListener('click', () => {
            consoleOutputScreen.innerHTML = '<div class="text-slate-500 italic">Terminal console cleared. Ready for next command.</div>';
            consoleStatusBadge.textContent = 'Idle';
            consoleStatusBadge.className = 'text-[10px] font-mono px-2 py-0.5 rounded bg-slate-800 text-slate-400 font-semibold';
            consoleDuration.textContent = 'Duration: -';
            consoleTimestamp.textContent = 'Ready';
        });

        // Employee Autocomplete Logic
        cmdUserSearchInput.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            clearTimeout(cmdRunner.debounceTimer);

            if (!query) {
                cmdUserDropdown.classList.add('hidden');
                cmdUserDropdown.innerHTML = '';
                return;
            }

            cmdUserSearchSpinner.classList.remove('hidden');

            cmdRunner.debounceTimer = setTimeout(async () => {
                try {
                    const res = await fetch(`/api/command-runner/employees?q=${encodeURIComponent(query)}`);
                    const data = await res.json();
                    renderEmployeeSuggestions(query, data.employees || []);
                } catch (err) {
                    console.error('Employee autocomplete error:', err);
                } finally {
                    cmdUserSearchSpinner.classList.add('hidden');
                }
            }, 250);
        });

        // Allow pressing Enter in search box to use as raw PIN
        cmdUserSearchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const val = cmdUserSearchInput.value.trim();
                if (val && /^\d+$/.test(val)) {
                    selectEmployeePin(val, `Manual Biometric PIN #${val}`);
                }
            }
        });

        function renderEmployeeSuggestions(query, employees) {
            let html = '';

            // Top item: manual PIN if numeric
            if (/^\d+$/.test(query)) {
                html += `
                    <div class="p-2.5 themed-hover cursor-pointer flex items-center justify-between text-blue-600 dark:text-blue-400 transition-colors" data-pin="${query}" data-name="Manual PIN #${query}">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-keyboard text-xs text-blue-500 dark:text-blue-400"></i>
                            <span class="text-xs font-semibold">Use custom entered PIN: <strong class="font-mono underline">${escapeHtml(query)}</strong></span>
                        </div>
                        <span class="pill-amber text-[10px] px-2 py-0.5 rounded font-mono font-bold">PIN: ${escapeHtml(query)}</span>
                    </div>
                `;
            }

            if (employees.length > 0) {
                employees.forEach(emp => {
                    html += `
                        <div class="p-2.5 themed-hover cursor-pointer flex items-center justify-between transition-colors themed-text-primary" data-pin="${emp.biometric_id}" data-name="${escapeHtml(emp.name)}">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <div class="w-6 h-6 rounded-full pill-blue flex items-center justify-center text-[10px] shrink-0 font-bold">
                                    <i class="fas fa-user text-[9px]"></i>
                                </div>
                                <div class="truncate text-xs font-semibold">${escapeHtml(emp.name)}</div>
                            </div>
                            <span class="pill-blue text-[10px] px-2 py-0.5 rounded font-mono font-bold shrink-0 ml-2">PIN: ${escapeHtml(emp.biometric_id)}</span>
                        </div>
                    `;
                });
            } else if (!/^\d+$/.test(query)) {
                html += `
                    <div class="p-3 text-center text-xs themed-text-muted italic">
                        No enrolled employee found matching "${escapeHtml(query)}". You can enter a numeric PIN directly.
                    </div>
                `;
            }

            cmdUserDropdown.innerHTML = html;
            cmdUserDropdown.classList.remove('hidden');

            cmdUserDropdown.querySelectorAll('[data-pin]').forEach(el => {
                el.addEventListener('click', () => {
                    selectEmployeePin(el.dataset.pin, el.dataset.name);
                });
            });
        }

        function selectEmployeePin(pin, name) {
            cmdRunner.selectedPin = pin;
            cmdRunner.selectedEmployeeName = name;
            cmdSelectedUserName.textContent = name;
            cmdSelectedUserPin.textContent = pin;
            cmdSelectedUserChip.classList.remove('hidden');
            cmdUserSearchInput.value = '';
            cmdUserDropdown.classList.add('hidden');
            updateCliPreview();
        }

        cmdClearUserBtn.addEventListener('click', () => {
            cmdRunner.selectedPin = null;
            cmdRunner.selectedEmployeeName = null;
            cmdSelectedUserChip.classList.add('hidden');
            cmdUserSearchInput.value = '';
            cmdUserDropdown.classList.add('hidden');
            updateCliPreview();
        });

        // Hide dropdown on click outside
        document.addEventListener('click', (e) => {
            if (!cmdUserSearchWrapper.contains(e.target)) {
                cmdUserDropdown.classList.add('hidden');
            }
        });

        // Execute Command Handler
        cmdExecuteBtn.addEventListener('click', async () => {
            const cmd = cmdSelect.value;
            const meta = cmdRunner.manifest ? cmdRunner.manifest[cmd] : null;

            // 1. PIN validation
            if (meta && meta.pin_requirement === 'required' && !cmdRunner.selectedPin) {
                showToast('Please select or enter an Employee Biometric PIN for this command.', 'warning');
                cmdUserSearchInput.focus();
                cmdUserSearchInput.classList.add('ring-2', 'ring-rose-500');
                setTimeout(() => cmdUserSearchInput.classList.remove('ring-2', 'ring-rose-500'), 2500);
                return;
            }

            // 2. Destructive command confirmation
            if ((meta && (meta.danger_level === 'danger' || meta.danger_level === 'warning')) && !cmdConfirmDangerCheckbox.checked) {
                showToast('Please check the confirmation box below to acknowledge this high-impact command.', 'warning');
                cmdDangerBox.scrollIntoView({ behavior: 'smooth' });
                cmdDangerBox.classList.add('animate-pulse');
                setTimeout(() => cmdDangerBox.classList.remove('animate-pulse'), 1500);
                return;
            }

            // Collect parameters
            const params = {};
            switch (cmd) {
                case 'biometrics:sync-device':
                    params.no_clean = !document.getElementById('opt_clean_unused')?.checked;
                    params.table = document.getElementById('opt_table')?.checked;
                    break;
                case 'biometrics:check-device':
                    params.mode = document.querySelector('input[name="opt_check_mode"]:checked')?.value || 'inspect';
                    break;
                case 'biometrics:check-device-match':
                    const comparePin = document.getElementById('opt_compare_pin')?.value;
                    if (comparePin) params.compare_pin = comparePin;
                    params.clean = document.getElementById('opt_clean_match')?.checked;
                    params.db_only = document.getElementById('opt_db_only')?.checked;
                    break;
                case 'biometrics:command-status':
                    const statusFilter = document.getElementById('opt_status_filter')?.value;
                    if (statusFilter) params.status = statusFilter;
                    const limitVal = document.getElementById('opt_limit')?.value;
                    if (limitVal) params.limit = limitVal;
                    break;
                case 'biometrics:delete-user':
                    params.with_db = document.getElementById('opt_with_db')?.checked;
                    break;
                case 'biometrics:delete-finger':
                    params.fid = document.getElementById('opt_fid')?.value || '0';
                    break;
                case 'biometrics:import-from-logs':
                    params.sync_devices = document.getElementById('opt_sync_devices')?.checked;
                    break;
                case 'devices:pull-logs':
                    const pullMode = document.getElementById('opt_pull_mode')?.value;
                    if (pullMode === 'adms') {
                        params.resend = true;
                        params.type = document.getElementById('opt_adms_type')?.value || 'DATA QUERY ATTLOG';
                    }
                    const startDate = document.getElementById('opt_pull_start_date')?.value;
                    const endDate = document.getElementById('opt_pull_end_date')?.value;
                    if (startDate && endDate && startDate === endDate) {
                        params.date = startDate;
                    } else {
                        if (startDate) params.start_date = startDate;
                        if (endDate) params.end_date = endDate;
                    }
                    break;
                case 'devices:clear-logs':
                    params.method = document.getElementById('opt_clear_method')?.value || 'both';
                    params.dry_run = document.getElementById('opt_dry_run')?.checked;
                    params.catch_up = document.getElementById('opt_catch_up')?.checked;
                    params.older_than = document.getElementById('opt_older_than')?.value || '7';
                    params.skip_sync = document.getElementById('opt_skip_sync')?.checked;
                    break;
                case 'device-logs:prune':
                    params.older_than = document.getElementById('opt_prune_days')?.value || '365';
                    params.dry_run = document.getElementById('opt_prune_dry_run')?.checked;
                    break;
            }

            // Set running state
            cmdRunner.isExecuting = true;
            cmdExecuteBtn.disabled = true;
            cmdExecuteIcon.className = 'fas fa-circle-notch fa-spin';
            cmdExecuteText.textContent = 'Executing command...';

            consoleStatusBadge.textContent = 'Running...';
            consoleStatusBadge.className = 'text-[10px] font-mono px-2 py-0.5 rounded bg-amber-500/20 text-amber-400 font-bold';
            consolePulse.className = 'w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse';

            const nowTime = new Date().toLocaleTimeString();
            consoleTimestamp.textContent = `Started at ${nowTime}`;

            const cliCommandText = cmdCliPreview.textContent.replace(/^\$\s*/, '');
            consoleOutputScreen.innerHTML = `
                <div class="text-blue-400 font-bold border-b border-slate-800 pb-1.5">
                    <span class="text-slate-500">[${nowTime}]</span> $ ${escapeHtml(cliCommandText)}
                </div>
                <div class="text-slate-400 italic py-1 animate-pulse">
                    Connecting to target terminal(s) & dispatching command...
                </div>
            `;

            try {
                const payload = {
                    command: cmd,
                    device_target: cmdDeviceTarget.value,
                    pin: cmdRunner.selectedPin,
                    params: params,
                };

                const res = await fetch('/api/command-runner/run', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(payload),
                });

                const result = await res.json();

                if (result.success) {
                    consoleStatusBadge.textContent = 'Exit 0 (Success)';
                    consoleStatusBadge.className = 'text-[10px] font-mono px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-400 font-bold';
                    consolePulse.className = 'w-2.5 h-2.5 rounded-full bg-emerald-500';
                    showToast(`Command [${cmd}] finished successfully (${result.duration || '0s'})`, 'success');
                } else {
                    consoleStatusBadge.textContent = `Exit ${result.exit_code ?? 1} (Failed)`;
                    consoleStatusBadge.className = 'text-[10px] font-mono px-2 py-0.5 rounded bg-rose-500/20 text-rose-400 font-bold';
                    consolePulse.className = 'w-2.5 h-2.5 rounded-full bg-rose-500';
                    showToast(result.message || 'Command execution finished with errors', 'error');
                }

                consoleDuration.textContent = `Duration: ${result.duration || '-'}`;
                consoleTimestamp.textContent = `Completed at ${new Date().toLocaleTimeString()}`;

                // Render terminal output text
                const formattedOutput = escapeHtml(result.output || '(No output returned)');
                consoleOutputScreen.innerHTML = `
                    <div class="text-blue-400 font-bold border-b border-slate-800 pb-1.5">
                        <span class="text-slate-500">[${nowTime}]</span> $ ${escapeHtml(result.command || cliCommandText)}
                    </div>
                    <pre class="font-mono text-xs whitespace-pre overflow-x-auto text-slate-200 mt-2">${formattedOutput}</pre>
                `;
                consoleOutputScreen.scrollTop = consoleOutputScreen.scrollHeight;

            } catch (err) {
                consoleStatusBadge.textContent = 'Network Error';
                consoleStatusBadge.className = 'text-[10px] font-mono px-2 py-0.5 rounded bg-rose-500/20 text-rose-400 font-bold';
                consolePulse.className = 'w-2.5 h-2.5 rounded-full bg-rose-500';
                showToast('Failed to contact Command Runner API: ' + err.message, 'error');

                consoleOutputScreen.innerHTML = `
                    <div class="text-rose-400 font-bold">
                        API REQUEST FAILED: ${escapeHtml(err.message)}
                    </div>
                `;
            } finally {
                cmdRunner.isExecuting = false;
                cmdExecuteBtn.disabled = false;
                cmdExecuteIcon.className = 'fas fa-play';
                cmdExecuteText.textContent = 'Run Command';
            }
        });

        // Auto Poll every 45 seconds to keep heartbeat live
        setInterval(() => {
            fetchDevices();
        }, 45000);

        // Initial Load
        fetchDevices();
        initCommandRunner();
    </script>

</body>
</html>
