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

            let bgClass = 'bg-slate-900 border-slate-700 text-slate-100';
            let iconClass = 'fa-info-circle text-blue-400';

            if (type === 'success') {
                bgClass = 'bg-emerald-950 border-emerald-800 text-emerald-100';
                iconClass = 'fa-check-circle text-emerald-400';
            } else if (type === 'error') {
                bgClass = 'bg-rose-950 border-rose-800 text-rose-100';
                iconClass = 'fa-exclamation-circle text-rose-400';
            } else if (type === 'warning') {
                bgClass = 'bg-amber-950 border-amber-800 text-amber-100';
                iconClass = 'fa-triangle-exclamation text-amber-400';
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
                [editNameModal, restartModal, batchRestartModal, detailsModal].forEach(m => closeModal(m));
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

        // Auto Poll every 45 seconds to keep heartbeat live
        setInterval(() => {
            fetchDevices();
        }, 45000);

        // Initial Load
        fetchDevices();
    </script>
</body>
</html>
