<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Manual Attendance Punch Ingestion - DTR Service</title>
    <script>
        (function() {
            const theme = localStorage.getItem('dtrTheme') || localStorage.getItem('alertTheme') || localStorage.getItem('deviceTheme');
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
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
        }

        html.light {
            --bg-body: #f8fafc;
            --bg-header: #ffffff;
            --bg-panel: #ffffff;
            --bg-card: #ffffff;
            --bg-subcard: #f1f5f9;
            --bg-input: #f8fafc;
            --bg-hover: #f1f5f9;
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-primary);
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.1);
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #475569;
            border-radius: 4px;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col font-sans transition-colors duration-150">

    <!-- Top Navigation Header -->
    <header class="border-b sticky top-0 z-40 transition-colors" style="background-color: var(--bg-header); border-color: var(--border-color);">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="/" class="flex items-center gap-2 group">
                    <div class="w-9 h-9 rounded-lg bg-teal-600 flex items-center justify-center text-white font-bold shadow-md shadow-teal-600/30">
                        <i class="fa-solid fa-fingerprint text-lg"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-base tracking-tight text-white dark:text-white">DTR<span class="text-teal-400">service</span></span>
                            <span class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded bg-teal-500/20 text-teal-300 border border-teal-500/30">
                                Manual Ingestion
                            </span>
                        </div>
                        <p class="text-xs text-slate-400 leading-none">Direct Recording & Synchronous Push to Port 8080</p>
                    </div>
                </a>
            </div>

            <div class="flex items-center gap-2 sm:gap-3">
                <!-- Target Server Health Pill -->
                <div id="zkHealthBadge" class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-lg border text-xs font-semibold bg-slate-800/80 border-slate-700 text-slate-300">
                    <span id="zkHealthDot" class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span id="zkHealthText">Checking :8080...</span>
                    <button id="btnRefreshHealth" class="text-slate-400 hover:text-white ml-1 transition-colors" title="Recheck Server Connection">
                        <i class="fa-solid fa-arrows-rotate text-xs"></i>
                    </button>
                </div>

                <a href="/devices" class="text-xs font-semibold px-3 py-1.5 rounded-lg border transition-colors flex items-center gap-1.5 hover:bg-slate-800 text-slate-300 border-slate-700">
                    <i class="fa-solid fa-server text-indigo-400"></i>
                    <span class="hidden md:inline">Devices</span>
                </a>
                <a href="/logs/alert" class="text-xs font-semibold px-3 py-1.5 rounded-lg border transition-colors flex items-center gap-1.5 hover:bg-slate-800 text-slate-300 border-slate-700">
                    <i class="fa-solid fa-triangle-exclamation text-amber-400"></i>
                    <span class="hidden md:inline">Alerts</span>
                </a>
                <a href="/logs" class="text-xs font-semibold px-3 py-1.5 rounded-lg border transition-colors flex items-center gap-1.5 hover:bg-slate-800 text-slate-300 border-slate-700">
                    <i class="fa-solid fa-clock-rotate-left text-blue-400"></i>
                    <span class="hidden md:inline">Logs</span>
                </a>
                <a href="/biometrics/hrbliz" class="text-xs font-semibold px-3 py-1.5 rounded-lg border transition-colors flex items-center gap-1.5 hover:bg-slate-800 text-slate-300 border-slate-700">
                    <i class="fa-solid fa-users-viewfinder text-emerald-400"></i>
                    <span class="hidden md:inline">HRBLIZ</span>
                </a>

                <button id="themeToggle" class="w-9 h-9 rounded-lg border border-slate-700 flex items-center justify-center text-slate-300 hover:text-white hover:bg-slate-800 transition-colors" title="Toggle Dark/Light Mode">
                    <i class="fa-solid fa-sun text-sm dark:hidden"></i>
                    <i class="fa-solid fa-moon text-sm hidden dark:inline"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- Main Content Container -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

        <!-- Top Banner / Explainer -->
        <div class="rounded-xl border p-4 sm:p-5 flex flex-col md:flex-row md:items-center justify-between gap-4" style="background-color: var(--bg-panel); border-color: var(--border-color);">
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <h1 class="text-lg sm:text-xl font-bold tracking-tight text-white flex items-center gap-2">
                        <i class="fa-solid fa-keyboard text-teal-400"></i>
                        Manual Punch Entry
                    </h1>
                    <span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-teal-500/10 text-teal-300 border border-teal-500/20">
                        Synchronous Recording
                    </span>
                </div>
                <p class="text-xs sm:text-sm text-slate-400">
                    Directly records punches into DTRService MySQL database and immediately mirrors them over HTTP to the ZKTeco integration server at <code class="px-1.5 py-0.5 rounded bg-slate-800 text-teal-300 font-mono text-xs">{{ $zkHost }}</code> for legacy payroll.
                </p>
            </div>

            <!-- Mode Switch Tabs -->
            <div class="flex items-center gap-1 p-1 rounded-lg bg-slate-900/60 border border-slate-800 self-start md:self-auto">
                <button id="tabSingleBtn" class="px-3.5 py-1.5 rounded-md text-xs font-semibold transition-all bg-teal-600 text-white shadow-sm flex items-center gap-1.5">
                    <i class="fa-solid fa-user"></i>
                    Single Punch
                </button>
                <button id="tabBatchBtn" class="px-3.5 py-1.5 rounded-md text-xs font-semibold transition-all text-slate-400 hover:text-white flex items-center gap-1.5">
                    <i class="fa-solid fa-users"></i>
                    Batch Mode
                </button>
            </div>
        </div>

        <!-- Global Alert Notifications -->
        <div id="alertContainer" class="hidden rounded-xl border p-4 transition-all"></div>

        <!-- Grid Layout: Form Column + Status/Session History Column -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- Left Column: Input Form (7 cols) -->
            <div class="lg:col-span-7 space-y-6">

                <!-- TAB 1: Single Punch Form -->
                <div id="singlePunchSection" class="rounded-xl border p-5 sm:p-6 space-y-5" style="background-color: var(--bg-panel); border-color: var(--border-color);">
                    <div class="border-b pb-3" style="border-color: var(--border-color);">
                        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-400 flex items-center gap-2">
                            <i class="fa-solid fa-circle-dot text-teal-400 text-xs"></i>
                            Attendance Record Details
                        </h2>
                    </div>

                    <!-- Employee Search & Autocomplete -->
                    <div class="space-y-1.5 relative">
                        <label class="block text-xs font-semibold text-slate-300 flex items-center justify-between">
                            <span>Search Employee (PIN or Name) <span class="text-rose-400">*</span></span>
                            <span id="selectedEmployeeBadge" class="hidden text-[11px] font-semibold text-emerald-400 flex items-center gap-1">
                                <i class="fa-solid fa-circle-check"></i> Matched
                            </span>
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                <i class="fa-solid fa-magnifying-glass text-xs"></i>
                            </span>
                            <input type="text" id="employeeSearchInput" autocomplete="off" placeholder="Type employee name or PIN (e.g. 2343, Abutazil, 493)..."
                                class="w-full pl-9 pr-24 py-2.5 rounded-lg border text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-teal-500 transition-colors"
                                style="background-color: var(--bg-input); border-color: var(--border-color);">
                            <button type="button" id="btnClearSearch" class="hidden absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-200">
                                <i class="fa-solid fa-xmark text-xs"></i>
                            </button>
                        </div>

                        <!-- Autocomplete Suggestions Dropdown -->
                        <div id="employeeDropdown" class="hidden absolute z-30 left-0 right-0 mt-1 rounded-lg border shadow-xl max-h-56 overflow-y-auto custom-scrollbar"
                            style="background-color: var(--bg-card); border-color: var(--border-color);">
                            <!-- Dynamic Autocomplete Results -->
                        </div>
                    </div>

                    <!-- Explicit ID Fields (Auto-filled on search, or editable directly) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="block text-xs font-semibold text-slate-300">
                                Canonical Biometric PIN <span class="text-rose-400">*</span>
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                    <i class="fa-solid fa-id-badge text-xs"></i>
                                </span>
                                <input type="text" id="biometricIdInput" placeholder="e.g. 2343 or 493"
                                    class="w-full pl-9 pr-3 py-2 rounded-lg border text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-teal-500 font-mono"
                                    style="background-color: var(--bg-input); border-color: var(--border-color);">
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-xs font-semibold text-slate-300 flex items-center justify-between">
                                <span>HRBLIZ Biometric PIN</span>
                                <span class="text-[10px] text-slate-400">(Optional / Mapped)</span>
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                    <i class="fa-solid fa-fingerprint text-xs"></i>
                                </span>
                                <input type="text" id="hrblizIdInput" placeholder="e.g. 1003 or 5180"
                                    class="w-full pl-9 pr-3 py-2 rounded-lg border text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-teal-500 font-mono"
                                    style="background-color: var(--bg-input); border-color: var(--border-color);">
                            </div>
                        </div>
                    </div>

                    <!-- Employee Name (Optional / Auto-resolved) -->
                    <div class="space-y-1.5">
                        <label class="block text-xs font-semibold text-slate-300 flex items-center justify-between">
                            <span>Employee Name</span>
                            <span class="text-[10px] text-slate-400">(Auto-resolved from database if empty)</span>
                        </label>
                        <input type="text" id="employeeNameInput" placeholder="Auto-populated or enter custom name..."
                            class="w-full px-3 py-2 rounded-lg border text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-teal-500"
                            style="background-color: var(--bg-input); border-color: var(--border-color);">
                    </div>

                    <!-- Date & Time Row -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between">
                                <label class="block text-xs font-semibold text-slate-300">Date <span class="text-rose-400">*</span></label>
                                <div class="flex items-center gap-1 text-[11px]">
                                    <button type="button" id="btnDateToday" class="text-teal-400 hover:text-teal-300 underline font-semibold">Today</button>
                                    <span class="text-slate-600">|</span>
                                    <button type="button" id="btnDateYesterday" class="text-slate-400 hover:text-slate-200">Yesterday</button>
                                </div>
                            </div>
                            <input type="date" id="punchDateInput"
                                class="w-full px-3 py-2 rounded-lg border text-sm text-white focus:outline-none focus:ring-2 focus:ring-teal-500"
                                style="background-color: var(--bg-input); border-color: var(--border-color);">
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between">
                                <label class="block text-xs font-semibold text-slate-300">Time <span class="text-rose-400">*</span></label>
                                <button type="button" id="btnTimeNow" class="text-teal-400 hover:text-teal-300 text-[11px] underline font-semibold flex items-center gap-1">
                                    <i class="fa-solid fa-clock text-[10px]"></i> Current Time
                                </button>
                            </div>
                            <input type="time" step="1" id="punchTimeInput"
                                class="w-full px-3 py-2 rounded-lg border text-sm text-white focus:outline-none focus:ring-2 focus:ring-teal-500 font-mono"
                                style="background-color: var(--bg-input); border-color: var(--border-color);">
                        </div>
                    </div>

                    <!-- Punch Status / Classification Cards -->
                    <div class="space-y-2">
                        <label class="block text-xs font-semibold text-slate-300 flex items-center justify-between">
                            <span>Punch Status / Type <span class="text-rose-400">*</span></span>
                            <span class="text-[11px] text-slate-400" id="statusDescriptionLabel">Selected: Check-In (AM In)</span>
                        </label>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2" id="statusCardsContainer">
                            <!-- Status 0: Check-In -->
                            <button type="button" data-status="0" class="status-card active p-2.5 rounded-lg border text-left transition-all flex flex-col justify-between bg-emerald-500/10 border-emerald-500 text-emerald-300 ring-2 ring-emerald-500/50">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold">0: Check-In</span>
                                    <i class="fa-solid fa-arrow-right-to-bracket text-emerald-400"></i>
                                </div>
                                <span class="text-[10px] text-slate-400 mt-1">Arrival / AM In</span>
                            </button>

                            <!-- Status 1: Check-Out -->
                            <button type="button" data-status="1" class="status-card p-2.5 rounded-lg border text-left transition-all flex flex-col justify-between border-slate-700 hover:border-slate-500 text-slate-300">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold">1: Check-Out</span>
                                    <i class="fa-solid fa-arrow-right-from-bracket text-rose-400"></i>
                                </div>
                                <span class="text-[10px] text-slate-400 mt-1">Departure / PM Out</span>
                            </button>

                            <!-- Status 2: Break-Out -->
                            <button type="button" data-status="2" class="status-card p-2.5 rounded-lg border text-left transition-all flex flex-col justify-between border-slate-700 hover:border-slate-500 text-slate-300">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold">2: Break-Out</span>
                                    <i class="fa-solid fa-utensils text-amber-400"></i>
                                </div>
                                <span class="text-[10px] text-slate-400 mt-1">Lunch / Mid-Out</span>
                            </button>

                            <!-- Status 3: Break-In -->
                            <button type="button" data-status="3" class="status-card p-2.5 rounded-lg border text-left transition-all flex flex-col justify-between border-slate-700 hover:border-slate-500 text-slate-300">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold">3: Break-In</span>
                                    <i class="fa-solid fa-mug-hot text-blue-400"></i>
                                </div>
                                <span class="text-[10px] text-slate-400 mt-1">Return / Mid-In</span>
                            </button>

                            <!-- Status 4: Overtime-In -->
                            <button type="button" data-status="4" class="status-card p-2.5 rounded-lg border text-left transition-all flex flex-col justify-between border-slate-700 hover:border-slate-500 text-slate-300">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold">4: OT-In</span>
                                    <i class="fa-solid fa-business-time text-purple-400"></i>
                                </div>
                                <span class="text-[10px] text-slate-400 mt-1">Overtime Start</span>
                            </button>

                            <!-- Status 5: Overtime-Out -->
                            <button type="button" data-status="5" class="status-card p-2.5 rounded-lg border text-left transition-all flex flex-col justify-between border-slate-700 hover:border-slate-500 text-slate-300">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold">5: OT-Out</span>
                                    <i class="fa-solid fa-clock-rotate-left text-fuchsia-400"></i>
                                </div>
                                <span class="text-[10px] text-slate-400 mt-1">Overtime End</span>
                            </button>

                            <!-- Status 255: Auto-Classify via Shift Windows -->
                            <button type="button" data-status="255" class="status-card p-2.5 rounded-lg border text-left transition-all flex flex-col justify-between border-slate-700 hover:border-slate-500 text-slate-300 sm:col-span-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold">255: Auto-Classify (UMIS)</span>
                                    <i class="fa-solid fa-wand-magic-sparkles text-amber-300"></i>
                                </div>
                                <span class="text-[10px] text-slate-400 mt-1">Auto-resolves In or Out via employee schedule</span>
                            </button>
                        </div>
                        <input type="hidden" id="selectedStatusInput" value="0">
                    </div>

                    <!-- Additional Options & Device Tag -->
                    <div class="pt-2 border-t space-y-3" style="border-color: var(--border-color);">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <label class="block text-xs font-semibold text-slate-300">Device Serial Number / Source</label>
                                <input type="text" id="deviceNameInput" value="RKQ4242300018"
                                    class="w-full px-3 py-2 rounded-lg border text-sm text-white focus:outline-none focus:ring-2 focus:ring-teal-500 font-mono text-xs"
                                    style="background-color: var(--bg-input); border-color: var(--border-color);">
                            </div>

                            <div class="space-y-2 pt-1 sm:pt-4">
                                <label class="flex items-center gap-2 cursor-pointer select-none">
                                    <input type="checkbox" id="syncZktecoCheckbox" checked class="w-4 h-4 rounded text-teal-600 bg-slate-800 border-slate-700 focus:ring-teal-500">
                                    <span class="text-xs text-slate-300 font-medium">Forward to Port 8080 Synchronously</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer select-none">
                                    <input type="checkbox" id="preventDuplicatesCheckbox" checked class="w-4 h-4 rounded text-teal-600 bg-slate-800 border-slate-700 focus:ring-teal-500">
                                    <span class="text-xs text-slate-300 font-medium">Prevent duplicate punch entries</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="pt-3">
                        <button type="button" id="btnSubmitPunch" class="w-full py-3 px-4 rounded-lg bg-teal-600 hover:bg-teal-500 active:scale-[0.99] text-white font-bold text-sm shadow-lg shadow-teal-600/30 transition-all flex items-center justify-center gap-2">
                            <i class="fa-solid fa-paper-plane"></i>
                            <span id="btnSubmitText">Record Punch & Synchronize (:8080)</span>
                            <span id="submitSpinner" class="hidden"><i class="fa-solid fa-circle-notch fa-spin"></i></span>
                        </button>
                    </div>
                </div>

                <!-- TAB 2: Batch Mode Form -->
                <div id="batchPunchSection" class="hidden rounded-xl border p-5 sm:p-6 space-y-5" style="background-color: var(--bg-panel); border-color: var(--border-color);">
                    <div class="border-b pb-3 flex items-center justify-between" style="border-color: var(--border-color);">
                        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-400 flex items-center gap-2">
                            <i class="fa-solid fa-layer-group text-teal-400 text-xs"></i>
                            Batch Attendance Ingestion
                        </h2>
                        <button type="button" id="btnAddBatchRow" class="text-xs font-semibold px-2.5 py-1 rounded bg-slate-800 text-teal-400 hover:bg-slate-700 transition-colors flex items-center gap-1">
                            <i class="fa-solid fa-plus text-[10px]"></i> Add Row
                        </button>
                    </div>

                    <p class="text-xs text-slate-400">
                        Add multiple punches to record them in batch and send in a single synchronous push to Port 8080.
                    </p>

                    <!-- Batch Rows Table -->
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-xs text-left" id="batchTable">
                            <thead>
                                <tr class="text-slate-400 border-b border-slate-700">
                                    <th class="py-2 px-2">PIN <span class="text-rose-400">*</span></th>
                                    <th class="py-2 px-2">Date (YYYY-MM-DD)</th>
                                    <th class="py-2 px-2">Time (HH:MM:SS)</th>
                                    <th class="py-2 px-2">Status</th>
                                    <th class="py-2 px-2 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody id="batchTableBody" class="divide-y divide-slate-800">
                                <!-- Dynamic rows inserted via JS -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Batch Submit -->
                    <div class="pt-2">
                        <button type="button" id="btnSubmitBatch" class="w-full py-3 px-4 rounded-lg bg-teal-600 hover:bg-teal-500 text-white font-bold text-sm shadow-lg shadow-teal-600/30 transition-all flex items-center justify-center gap-2">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <span id="btnSubmitBatchText">Batch Record & Synchronize All</span>
                            <span id="batchSubmitSpinner" class="hidden"><i class="fa-solid fa-circle-notch fa-spin"></i></span>
                        </button>
                    </div>
                </div>

            </div>

            <!-- Right Column: Live Synchronization Feedback & Session History (5 cols) -->
            <div class="lg:col-span-5 space-y-6">

                <!-- Latest Synchronization Card -->
                <div class="rounded-xl border p-5 space-y-4" style="background-color: var(--bg-panel); border-color: var(--border-color);">
                    <div class="flex items-center justify-between border-b pb-3" style="border-color: var(--border-color);">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center gap-2">
                            <i class="fa-solid fa-tower-broadcast text-teal-400"></i>
                            Latest Sync Status
                        </h3>
                        <span id="syncResultStatusPill" class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-800 text-slate-400 border border-slate-700">
                            Awaiting Input
                        </span>
                    </div>

                    <!-- Result Details Display -->
                    <div id="latestResultEmpty" class="py-8 text-center text-slate-500 space-y-2">
                        <i class="fa-solid fa-clock text-3xl opacity-40"></i>
                        <p class="text-xs">No manual punch submitted yet in this session.</p>
                        <p class="text-[11px] text-slate-600">Submit a punch on the left to see instant DTR and Port 8080 sync results.</p>
                    </div>

                    <div id="latestResultCard" class="hidden space-y-3">
                        <!-- Employee Banner -->
                        <div class="p-3 rounded-lg bg-slate-800/60 border border-slate-700/60 flex items-center justify-between">
                            <div>
                                <h4 id="resEmployeeName" class="font-bold text-sm text-white">Employee Name</h4>
                                <div class="text-xs text-slate-400 flex items-center gap-2 mt-0.5 font-mono">
                                    <span>PIN: <b id="resBioPin" class="text-teal-400">--</b></span>
                                    <span id="resHrbPinContainer" class="hidden text-slate-500">| HRB: <b id="resHrbPin" class="text-amber-400">--</b></span>
                                </div>
                            </div>
                            <span id="resStatusBadge" class="text-xs font-bold px-2.5 py-1 rounded border bg-emerald-500/20 text-emerald-300 border-emerald-500/30">
                                Check-In
                            </span>
                        </div>

                        <!-- Timestamp & DB Row -->
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div class="p-2.5 rounded bg-slate-900/40 border border-slate-800">
                                <span class="text-slate-500 block text-[10px] uppercase font-bold">Punch Timestamp</span>
                                <span id="resTimestamp" class="font-mono text-slate-200 font-semibold">--</span>
                            </div>
                            <div class="p-2.5 rounded bg-slate-900/40 border border-slate-800">
                                <span class="text-slate-500 block text-[10px] uppercase font-bold">DTR Database ID</span>
                                <span id="resDbId" class="font-mono text-slate-200 font-semibold">--</span>
                            </div>
                        </div>

                        <!-- ZKTeco Sync Result Details -->
                        <div id="zkSyncOutcomeBox" class="p-3 rounded-lg border text-xs space-y-1.5 transition-all bg-emerald-950/20 border-emerald-800/40 text-emerald-300">
                            <div class="flex items-center justify-between font-bold">
                                <span class="flex items-center gap-1.5">
                                    <i id="zkSyncOutcomeIcon" class="fa-solid fa-circle-check text-emerald-400"></i>
                                    <span id="zkSyncOutcomeTitle">ZKTeco Forward Successful</span>
                                </span>
                                <span id="zkSyncLatency" class="font-mono text-[11px] text-emerald-400">-- ms</span>
                            </div>
                            <div class="text-[11px] opacity-80" id="zkSyncOutcomeMsg">
                                Punch pushed to {{ $zkHost }} (downloaded = 0).
                            </div>
                            <div class="text-[10px] font-mono text-slate-400 pt-1 border-t border-emerald-800/30 flex items-center justify-between">
                                <span>PIN Sent: <b id="zkSyncPinSent" class="text-slate-300">--</b></span>
                                <span>Status Sent: <b id="zkSyncStatusSent" class="text-slate-300">--</b></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Session History Table -->
                <div class="rounded-xl border p-5 space-y-3" style="background-color: var(--bg-panel); border-color: var(--border-color);">
                    <div class="flex items-center justify-between border-b pb-3" style="border-color: var(--border-color);">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center gap-2">
                            <i class="fa-solid fa-clock-rotate-left text-teal-400"></i>
                            Punches Recorded This Session
                        </h3>
                        <span id="sessionPunchesCount" class="text-xs font-mono text-teal-400 font-bold">0 records</span>
                    </div>

                    <div class="overflow-x-auto max-h-72 overflow-y-auto custom-scrollbar">
                        <table class="w-full text-xs text-left" id="sessionHistoryTable">
                            <thead>
                                <tr class="text-slate-500 border-b border-slate-800 text-[10px] uppercase">
                                    <th class="py-1.5 px-2">Time</th>
                                    <th class="py-1.5 px-2">Employee</th>
                                    <th class="py-1.5 px-2">Type</th>
                                    <th class="py-1.5 px-2 text-right">ZKTeco Sync</th>
                                </tr>
                            </thead>
                            <tbody id="sessionHistoryBody" class="divide-y divide-slate-800/60">
                                <tr id="emptyHistoryRow">
                                    <td colspan="4" class="py-4 text-center text-slate-500 text-xs italic">
                                        No entries in session yet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

        </div>

    </main>

    <!-- Footer -->
    <footer class="border-t py-4 text-center text-xs text-slate-500 transition-colors" style="background-color: var(--bg-header); border-color: var(--border-color);">
        <p>ZCMC Automated DTRService &bull; ZKTeco Synchronous Ingestion Engine &bull; Port 8181 / 8080</p>
    </footer>

    <!-- JavaScript Application Logic -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const zkHost = "{{ $zkHost }}";

            // State
            let currentStatus = 0;
            let sessionPunches = [];
            let autocompleteTimeout = null;

            // DOM Elements
            const themeToggle = document.getElementById('themeToggle');
            const zkHealthBadge = document.getElementById('zkHealthBadge');
            const zkHealthDot = document.getElementById('zkHealthDot');
            const zkHealthText = document.getElementById('zkHealthText');
            const btnRefreshHealth = document.getElementById('btnRefreshHealth');

            const tabSingleBtn = document.getElementById('tabSingleBtn');
            const tabBatchBtn = document.getElementById('tabBatchBtn');
            const singlePunchSection = document.getElementById('singlePunchSection');
            const batchPunchSection = document.getElementById('batchPunchSection');

            const employeeSearchInput = document.getElementById('employeeSearchInput');
            const employeeDropdown = document.getElementById('employeeDropdown');
            const btnClearSearch = document.getElementById('btnClearSearch');
            const selectedEmployeeBadge = document.getElementById('selectedEmployeeBadge');

            const biometricIdInput = document.getElementById('biometricIdInput');
            const hrblizIdInput = document.getElementById('hrblizIdInput');
            const employeeNameInput = document.getElementById('employeeNameInput');
            const punchDateInput = document.getElementById('punchDateInput');
            const punchTimeInput = document.getElementById('punchTimeInput');
            const selectedStatusInput = document.getElementById('selectedStatusInput');
            const statusDescriptionLabel = document.getElementById('statusDescriptionLabel');
            const deviceNameInput = document.getElementById('deviceNameInput');
            const syncZktecoCheckbox = document.getElementById('syncZktecoCheckbox');
            const preventDuplicatesCheckbox = document.getElementById('preventDuplicatesCheckbox');

            const btnSubmitPunch = document.getElementById('btnSubmitPunch');
            const btnSubmitText = document.getElementById('btnSubmitText');
            const submitSpinner = document.getElementById('submitSpinner');
            const alertContainer = document.getElementById('alertContainer');

            const btnDateToday = document.getElementById('btnDateToday');
            const btnDateYesterday = document.getElementById('btnDateYesterday');
            const btnTimeNow = document.getElementById('btnTimeNow');

            // Result Elements
            const latestResultEmpty = document.getElementById('latestResultEmpty');
            const latestResultCard = document.getElementById('latestResultCard');
            const syncResultStatusPill = document.getElementById('syncResultStatusPill');
            const resEmployeeName = document.getElementById('resEmployeeName');
            const resBioPin = document.getElementById('resBioPin');
            const resHrbPinContainer = document.getElementById('resHrbPinContainer');
            const resHrbPin = document.getElementById('resHrbPin');
            const resStatusBadge = document.getElementById('resStatusBadge');
            const resTimestamp = document.getElementById('resTimestamp');
            const resDbId = document.getElementById('resDbId');
            const zkSyncOutcomeBox = document.getElementById('zkSyncOutcomeBox');
            const zkSyncOutcomeIcon = document.getElementById('zkSyncOutcomeIcon');
            const zkSyncOutcomeTitle = document.getElementById('zkSyncOutcomeTitle');
            const zkSyncOutcomeMsg = document.getElementById('zkSyncOutcomeMsg');
            const zkSyncLatency = document.getElementById('zkSyncLatency');
            const zkSyncPinSent = document.getElementById('zkSyncPinSent');
            const zkSyncStatusSent = document.getElementById('zkSyncStatusSent');
            const sessionHistoryBody = document.getElementById('sessionHistoryBody');
            const sessionPunchesCount = document.getElementById('sessionPunchesCount');

            // 1. Initialize Date & Time Defaults
            function initDateTime() {
                const now = new Date();
                const yyyy = now.getFullYear();
                const mm = String(now.getMonth() + 1).padStart(2, '0');
                const dd = String(now.getDate()).padStart(2, '0');
                punchDateInput.value = `${yyyy}-${mm}-${dd}`;

                const hh = String(now.getHours()).padStart(2, '0');
                const min = String(now.getMinutes()).padStart(2, '0');
                const ss = String(now.getSeconds()).padStart(2, '0');
                punchTimeInput.value = `${hh}:${min}:${ss}`;
            }
            initDateTime();

            // Date & Time Shortcut Buttons
            btnDateToday.addEventListener('click', () => {
                const now = new Date();
                punchDateInput.value = now.toISOString().split('T')[0];
            });

            btnDateYesterday.addEventListener('click', () => {
                const d = new Date();
                d.setDate(d.getDate() - 1);
                punchDateInput.value = d.toISOString().split('T')[0];
            });

            btnTimeNow.addEventListener('click', () => {
                const now = new Date();
                const hh = String(now.getHours()).padStart(2, '0');
                const min = String(now.getMinutes()).padStart(2, '0');
                const ss = String(now.getSeconds()).padStart(2, '0');
                punchTimeInput.value = `${hh}:${min}:${ss}`;
            });

            // 2. Theme Toggle
            themeToggle.addEventListener('click', () => {
                const isLight = document.documentElement.classList.toggle('light');
                localStorage.setItem('dtrTheme', isLight ? 'light' : 'dark');
            });

            // 3. Tab Switching
            tabSingleBtn.addEventListener('click', () => {
                tabSingleBtn.className = "px-3.5 py-1.5 rounded-md text-xs font-semibold transition-all bg-teal-600 text-white shadow-sm flex items-center gap-1.5";
                tabBatchBtn.className = "px-3.5 py-1.5 rounded-md text-xs font-semibold transition-all text-slate-400 hover:text-white flex items-center gap-1.5";
                singlePunchSection.classList.remove('hidden');
                batchPunchSection.classList.add('hidden');
            });

            tabBatchBtn.addEventListener('click', () => {
                tabBatchBtn.className = "px-3.5 py-1.5 rounded-md text-xs font-semibold transition-all bg-teal-600 text-white shadow-sm flex items-center gap-1.5";
                tabSingleBtn.className = "px-3.5 py-1.5 rounded-md text-xs font-semibold transition-all text-slate-400 hover:text-white flex items-center gap-1.5";
                batchPunchSection.classList.remove('hidden');
                singlePunchSection.classList.add('hidden');

                if (document.querySelectorAll('#batchTableBody tr').length === 0) {
                    addBatchRow();
                    addBatchRow();
                }
            });

            // 4. Punch Status Selection Cards
            const statusDescriptions = {
                0: 'Selected: Check-In (AM In / Shift Start)',
                1: 'Selected: Check-Out (PM Out / Shift End)',
                2: 'Selected: Break-Out (Lunch Out)',
                3: 'Selected: Break-In (Lunch In)',
                4: 'Selected: Overtime-In',
                5: 'Selected: Overtime-Out',
                255: 'Selected: Auto-Classify (UMIS Schedule Windows)',
            };

            document.querySelectorAll('.status-card').forEach(card => {
                card.addEventListener('click', () => {
                    document.querySelectorAll('.status-card').forEach(c => {
                        c.classList.remove('active', 'bg-emerald-500/10', 'border-emerald-500', 'text-emerald-300', 'ring-2', 'ring-emerald-500/50');
                        c.classList.add('border-slate-700', 'text-slate-300');
                    });

                    card.classList.add('active', 'bg-emerald-500/10', 'border-emerald-500', 'text-emerald-300', 'ring-2', 'ring-emerald-500/50');
                    card.classList.remove('border-slate-700', 'text-slate-300');

                    const statusVal = parseInt(card.getAttribute('data-status'), 10);
                    currentStatus = statusVal;
                    selectedStatusInput.value = statusVal;
                    statusDescriptionLabel.textContent = statusDescriptions[statusVal] || `Selected: Status ${statusVal}`;
                });
            });

            // 5. Employee Autocomplete Search
            employeeSearchInput.addEventListener('input', function() {
                const query = this.value.trim();
                btnClearSearch.classList.toggle('hidden', query === '');

                if (autocompleteTimeout) clearTimeout(autocompleteTimeout);

                if (query.length < 1) {
                    employeeDropdown.classList.add('hidden');
                    employeeDropdown.innerHTML = '';
                    return;
                }

                autocompleteTimeout = setTimeout(() => {
                    fetch(`/punches/employees?q=${encodeURIComponent(query)}`)
                        .then(r => r.json())
                        .then(res => {
                            const employees = res.data || [];
                            if (employees.length === 0) {
                                employeeDropdown.innerHTML = `
                                    <div class="p-3 text-xs text-slate-400 italic text-center">
                                        No employee matched "${query}". You can still type the PIN directly below.
                                    </div>
                                `;
                                employeeDropdown.classList.remove('hidden');
                                return;
                            }

                            employeeDropdown.innerHTML = employees.map(emp => `
                                <div class="employee-suggestion p-2.5 hover:bg-slate-700/60 cursor-pointer border-b border-slate-700/40 last:border-0 flex items-center justify-between"
                                     data-pin="${emp.biometric_id}"
                                     data-hrb="${emp.hrbliz_biometric_id || ''}"
                                     data-name="${emp.name}">
                                    <div>
                                        <div class="font-bold text-xs text-white">${emp.name}</div>
                                        <div class="text-[11px] text-slate-400 font-mono">
                                            Canonical PIN: <span class="text-teal-400">${emp.biometric_id}</span>
                                            ${emp.hrbliz_biometric_id ? ` &bull; HRBLIZ: <span class="text-amber-400">${emp.hrbliz_biometric_id}</span>` : ''}
                                        </div>
                                    </div>
                                    <i class="fa-solid fa-arrow-turn-down text-slate-500 text-xs"></i>
                                </div>
                            `).join('');

                            employeeDropdown.querySelectorAll('.employee-suggestion').forEach(item => {
                                item.addEventListener('click', function() {
                                    const pin = this.getAttribute('data-pin');
                                    const hrb = this.getAttribute('data-hrb');
                                    const name = this.getAttribute('data-name');

                                    biometricIdInput.value = pin;
                                    hrblizIdInput.value = hrb;
                                    employeeNameInput.value = name;
                                    employeeSearchInput.value = `${name} (${pin})`;
                                    selectedEmployeeBadge.classList.remove('hidden');
                                    employeeDropdown.classList.add('hidden');
                                });
                            });

                            employeeDropdown.classList.remove('hidden');
                        })
                        .catch(err => {
                            console.error('Search error:', err);
                        });
                }, 200);
            });

            btnClearSearch.addEventListener('click', () => {
                employeeSearchInput.value = '';
                biometricIdInput.value = '';
                hrblizIdInput.value = '';
                employeeNameInput.value = '';
                selectedEmployeeBadge.classList.add('hidden');
                btnClearSearch.classList.add('hidden');
                employeeDropdown.classList.add('hidden');
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!employeeSearchInput.contains(e.target) && !employeeDropdown.contains(e.target)) {
                    employeeDropdown.classList.add('hidden');
                }
            });

            // 6. Check ZKTeco Server Connection
            function checkServerHealth() {
                zkHealthDot.className = "w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse";
                zkHealthText.textContent = "Pinging :8080...";

                fetch('/api/manual-punch')
                    .then(r => r.json())
                    .then(data => {
                        const health = data.zkteco_server?.health;
                        if (health && health.success) {
                            zkHealthDot.className = "w-2.5 h-2.5 rounded-full bg-emerald-400";
                            zkHealthText.textContent = `ZKTeco Online (${Math.round(health.latency_ms)}ms)`;
                        } else {
                            zkHealthDot.className = "w-2.5 h-2.5 rounded-full bg-rose-500";
                            zkHealthText.textContent = "ZKTeco Offline";
                        }
                    })
                    .catch(() => {
                        zkHealthDot.className = "w-2.5 h-2.5 rounded-full bg-rose-500";
                        zkHealthText.textContent = "Connection Error";
                    });
            }
            btnRefreshHealth.addEventListener('click', checkServerHealth);
            checkServerHealth();

            // 7. Submit Single Punch Handler
            btnSubmitPunch.addEventListener('click', handleSingleSubmit);

            function handleSingleSubmit() {
                const bioPin = biometricIdInput.value.trim();
                const hrbPin = hrblizIdInput.value.trim();
                const empName = employeeNameInput.value.trim();
                const date = punchDateInput.value.trim();
                const time = punchTimeInput.value.trim();

                if (!bioPin && !hrbPin) {
                    showAlert('error', 'Please enter a Canonical Biometric PIN or HRBLIZ PIN.');
                    biometricIdInput.focus();
                    return;
                }

                if (!date || !time) {
                    showAlert('error', 'Please provide a valid date and time.');
                    return;
                }

                const dateTime = `${date} ${time}`;
                const payload = {
                    biometric_id: bioPin || undefined,
                    hrbliz_biometric_id: hrbPin || undefined,
                    name: empName || undefined,
                    date_time: dateTime,
                    status: parseInt(selectedStatusInput.value, 10),
                    device_name: deviceNameInput.value.trim() || 'RKQ4242300018',
                    sync_zkteco: syncZktecoCheckbox.checked,
                    prevent_duplicates: preventDuplicatesCheckbox.checked,
                };

                // UI Loading State
                btnSubmitPunch.disabled = true;
                btnSubmitText.textContent = "Recording & Forwarding...";
                submitSpinner.classList.remove('hidden');
                hideAlert();

                fetch('/api/manual-punch', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(payload),
                })
                .then(async response => {
                    const data = await response.json();
                    return { ok: response.ok, status: response.status, data };
                })
                .then(({ ok, status, data }) => {
                    btnSubmitPunch.disabled = false;
                    btnSubmitText.textContent = "Record Punch & Synchronize (:8080)";
                    submitSpinner.classList.add('hidden');

                    if (!ok) {
                        const errMsg = data.message || 'Failed to submit punch.';
                        showAlert('error', errMsg);
                        return;
                    }

                    if (data.is_duplicate) {
                        showAlert('warning', `⚠️ Duplicate skipped: Punch for PIN ${data.data.biometric_id} at ${data.data.date_time} is already recorded.`);
                        displayLatestResult(data, true);
                    } else {
                        const syncSuccess = data.zkteco_sync?.success;
                        const syncLatency = data.zkteco_sync?.latency_ms ? ` (${data.zkteco_sync.latency_ms}ms)` : '';
                        showAlert('success', `✓ Success: Punch recorded in DTRService (ID #${data.data.id}) and ${syncSuccess ? 'synchronized with Port 8080' + syncLatency : 'ZKTeco forward recorded'}.`);
                        displayLatestResult(data, false);
                        addSessionHistory(data);
                    }
                })
                .catch(err => {
                    btnSubmitPunch.disabled = false;
                    btnSubmitText.textContent = "Record Punch & Synchronize (:8080)";
                    submitSpinner.classList.add('hidden');
                    showAlert('error', `Network error: ${err.message}`);
                });
            }

            // Display Latest Result Card
            function displayLatestResult(res, isDuplicate) {
                latestResultEmpty.classList.add('hidden');
                latestResultCard.classList.remove('hidden');

                const d = res.data;
                const zk = res.zkteco_sync;

                resEmployeeName.textContent = d.name || 'Unknown Employee';
                resBioPin.textContent = d.biometric_id;

                if (d.hrbliz_biometric_id) {
                    resHrbPin.textContent = d.hrbliz_biometric_id;
                    resHrbPinContainer.classList.remove('hidden');
                } else {
                    resHrbPinContainer.classList.add('hidden');
                }

                resStatusBadge.textContent = d.status_label || `Status ${d.status}`;
                resTimestamp.textContent = d.date_time;
                resDbId.textContent = d.id ? `#${d.id}` : '(Existing)';

                if (isDuplicate) {
                    syncResultStatusPill.className = "text-[10px] font-bold px-2 py-0.5 rounded bg-amber-500/20 text-amber-300 border border-amber-500/30";
                    syncResultStatusPill.textContent = "Duplicate Skipped";

                    zkSyncOutcomeBox.className = "p-3 rounded-lg border text-xs space-y-1.5 bg-amber-950/20 border-amber-800/40 text-amber-300";
                    zkSyncOutcomeIcon.className = "fa-solid fa-triangle-exclamation text-amber-400";
                    zkSyncOutcomeTitle.textContent = "Already In Database";
                    zkSyncOutcomeMsg.textContent = "Punch was previously saved; duplicate skipped to prevent payroll errors.";
                    zkSyncLatency.textContent = "";
                    zkSyncPinSent.textContent = d.biometric_id;
                    zkSyncStatusSent.textContent = d.status;
                } else if (zk && zk.success) {
                    syncResultStatusPill.className = "text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30";
                    syncResultStatusPill.textContent = "Recorded & Synced";

                    zkSyncOutcomeBox.className = "p-3 rounded-lg border text-xs space-y-1.5 bg-emerald-950/20 border-emerald-800/40 text-emerald-300";
                    zkSyncOutcomeIcon.className = "fa-solid fa-circle-check text-emerald-400";
                    zkSyncOutcomeTitle.textContent = "Synchronized to Port 8080";
                    zkSyncOutcomeMsg.textContent = `Punch saved to SQLite and flagged downloaded = 0 for desktop payroll.`;
                    zkSyncLatency.textContent = zk.latency_ms ? `${zk.latency_ms} ms` : "";
                    zkSyncPinSent.textContent = zk.pin_sent || d.biometric_id;
                    zkSyncStatusSent.textContent = zk.status_sent !== undefined ? zk.status_sent : d.status;
                } else {
                    syncResultStatusPill.className = "text-[10px] font-bold px-2 py-0.5 rounded bg-rose-500/20 text-rose-300 border border-rose-500/30";
                    syncResultStatusPill.textContent = "Sync Skipped / Failed";

                    zkSyncOutcomeBox.className = "p-3 rounded-lg border text-xs space-y-1.5 bg-rose-950/20 border-rose-800/40 text-rose-300";
                    zkSyncOutcomeIcon.className = "fa-solid fa-circle-xmark text-rose-400";
                    zkSyncOutcomeTitle.textContent = "ZKTeco Sync Bypassed";
                    zkSyncOutcomeMsg.textContent = zk?.error || zk?.message || "Sync was skipped or host was unreachable.";
                    zkSyncLatency.textContent = "";
                    zkSyncPinSent.textContent = d.biometric_id;
                    zkSyncStatusSent.textContent = d.status;
                }
            }

            // Session History Tracker
            function addSessionHistory(res) {
                const emptyRow = document.getElementById('emptyHistoryRow');
                if (emptyRow) emptyRow.remove();

                const d = res.data;
                const zk = res.zkteco_sync;
                sessionPunches.push(d);
                sessionPunchesCount.textContent = `${sessionPunches.length} records`;

                const tr = document.createElement('tr');
                tr.className = "hover:bg-slate-800/40 transition-colors";

                const isSynced = zk && zk.success;
                const syncBadge = isSynced
                    ? `<span class="inline-flex items-center gap-1 text-[10px] text-emerald-400 font-semibold"><i class="fa-solid fa-check text-[9px]"></i> Port 8080</span>`
                    : `<span class="inline-flex items-center gap-1 text-[10px] text-slate-500"><i class="fa-solid fa-minus text-[9px]"></i> Local only</span>`;

                tr.innerHTML = `
                    <td class="py-2 px-2 font-mono text-slate-300">${d.date_time.split(' ')[1] || d.date_time}</td>
                    <td class="py-2 px-2 font-semibold text-white truncate max-w-[120px]" title="${d.name}">${d.name} (${d.biometric_id})</td>
                    <td class="py-2 px-2">
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold ${d.status === 0 ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'}">
                            ${d.status_label || d.status}
                        </span>
                    </td>
                    <td class="py-2 px-2 text-right">${syncBadge}</td>
                `;

                sessionHistoryBody.insertBefore(tr, sessionHistoryBody.firstChild);
            }

            // Helper: Alert Banners
            function showAlert(type, message) {
                alertContainer.classList.remove('hidden', 'bg-emerald-950/30', 'border-emerald-600', 'text-emerald-300', 'bg-rose-950/30', 'border-rose-600', 'text-rose-300', 'bg-amber-950/30', 'border-amber-600', 'text-amber-300');

                if (type === 'success') {
                    alertContainer.classList.add('bg-emerald-950/30', 'border-emerald-600', 'text-emerald-300');
                } else if (type === 'warning') {
                    alertContainer.classList.add('bg-amber-950/30', 'border-amber-600', 'text-amber-300');
                } else {
                    alertContainer.classList.add('bg-rose-950/30', 'border-rose-600', 'text-rose-300');
                }

                alertContainer.innerHTML = `<div class="text-xs sm:text-sm font-semibold">${message}</div>`;
                alertContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            function hideAlert() {
                alertContainer.classList.add('hidden');
                alertContainer.innerHTML = '';
            }

            // 8. Batch Mode Implementation
            const batchTableBody = document.getElementById('batchTableBody');
            const btnAddBatchRow = document.getElementById('btnAddBatchRow');
            const btnSubmitBatch = document.getElementById('btnSubmitBatch');
            const btnSubmitBatchText = document.getElementById('btnSubmitBatchText');
            const batchSubmitSpinner = document.getElementById('batchSubmitSpinner');

            function addBatchRow() {
                const tr = document.createElement('tr');
                tr.className = "border-b border-slate-800";
                const today = new Date().toISOString().split('T')[0];

                tr.innerHTML = `
                    <td class="py-1.5 px-2">
                        <input type="text" placeholder="PIN" class="batch-pin w-24 px-2 py-1 rounded border border-slate-700 bg-slate-800 text-white font-mono text-xs focus:ring-1 focus:ring-teal-500">
                    </td>
                    <td class="py-1.5 px-2">
                        <input type="date" value="${today}" class="batch-date w-32 px-2 py-1 rounded border border-slate-700 bg-slate-800 text-white text-xs focus:ring-1 focus:ring-teal-500">
                    </td>
                    <td class="py-1.5 px-2">
                        <input type="time" step="1" value="08:00:00" class="batch-time w-28 px-2 py-1 rounded border border-slate-700 bg-slate-800 text-white font-mono text-xs focus:ring-1 focus:ring-teal-500">
                    </td>
                    <td class="py-1.5 px-2">
                        <select class="batch-status px-2 py-1 rounded border border-slate-700 bg-slate-800 text-white text-xs focus:ring-1 focus:ring-teal-500">
                            <option value="0">0: In</option>
                            <option value="1">1: Out</option>
                            <option value="2">2: Break-Out</option>
                            <option value="3">3: Break-In</option>
                            <option value="255">255: Auto</option>
                        </select>
                    </td>
                    <td class="py-1.5 px-2 text-right">
                        <button type="button" class="btn-remove-row text-rose-400 hover:text-rose-300 p-1">
                            <i class="fa-solid fa-trash-can text-xs"></i>
                        </button>
                    </td>
                `;

                tr.querySelector('.btn-remove-row').addEventListener('click', () => tr.remove());
                batchTableBody.appendChild(tr);
            }
            btnAddBatchRow.addEventListener('click', addBatchRow);

            btnSubmitBatch.addEventListener('click', () => {
                const rows = document.querySelectorAll('#batchTableBody tr');
                const punches = [];

                rows.forEach(tr => {
                    const pin = tr.querySelector('.batch-pin')?.value.trim();
                    const date = tr.querySelector('.batch-date')?.value.trim();
                    const time = tr.querySelector('.batch-time')?.value.trim();
                    const status = tr.querySelector('.batch-status')?.value;

                    if (pin && date && time) {
                        punches.push({
                            biometric_id: pin,
                            date_time: `${date} ${time}`,
                            status: parseInt(status, 10),
                            device_name: 'RKQ4242300018',
                        });
                    }
                });

                if (punches.length === 0) {
                    showAlert('error', 'Please fill in at least one row with a valid PIN, Date, and Time.');
                    return;
                }

                btnSubmitBatch.disabled = true;
                btnSubmitBatchText.textContent = "Processing Batch...";
                batchSubmitSpinner.classList.remove('hidden');

                fetch('/api/manual-punch', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        punches: punches,
                        sync_zkteco: true,
                        prevent_duplicates: true,
                    }),
                })
                .then(r => r.json())
                .then(data => {
                    btnSubmitBatch.disabled = false;
                    btnSubmitBatchText.textContent = "Batch Record & Synchronize All";
                    batchSubmitSpinner.classList.add('hidden');

                    if (data.success) {
                        showAlert('success', `✓ Batch Complete: ${data.inserted_count} punch(es) inserted, ${data.skipped_count} duplicate(s) skipped.`);
                        batchTableBody.innerHTML = '';
                        addBatchRow();
                    } else {
                        showAlert('error', data.message || 'Batch submission failed.');
                    }
                })
                .catch(err => {
                    btnSubmitBatch.disabled = false;
                    btnSubmitBatchText.textContent = "Batch Record & Synchronize All";
                    batchSubmitSpinner.classList.add('hidden');
                    showAlert('error', `Batch error: ${err.message}`);
                });
            });

            // Keyboard shortcut (Ctrl + Enter submits form)
            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    if (!singlePunchSection.classList.contains('hidden')) {
                        handleSingleSubmit();
                    }
                }
            });
        });
    </script>
</body>
</html>
