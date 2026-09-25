<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>HRBLIZ Biometrics ID Management & Verification - DTR Service</title>
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
    <!-- SheetJS for local Excel/CSV parsing -->
    <script src="https://cdn.sheetjs.com/xlsx-0.20.2/package/dist/xlsx.full.min.js"></script>
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
            --table-head-bg: #0b1120;
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
            --border-subtle: #f1f5f9;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            --table-head-bg: #f8fafc;
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
                    <div class="w-9 h-9 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-bold shadow-md shadow-indigo-600/30">
                        <i class="fa-solid fa-fingerprint text-lg"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-base tracking-tight text-white dark:text-white">DTR<span class="text-indigo-400">service</span></span>
                            <span class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                Temporary Tool
                            </span>
                        </div>
                        <p class="text-xs text-slate-400 leading-none">Biometrics HRBLIZ ID Management & Verification</p>
                    </div>
                </a>
            </div>

            <div class="flex items-center gap-2 sm:gap-3">
                <a href="/devices" class="text-xs font-semibold px-3 py-1.5 rounded-lg border transition-colors flex items-center gap-1.5 hover:bg-slate-800 text-slate-300 border-slate-700">
                    <i class="fa-solid fa-server text-indigo-400"></i>
                    <span class="hidden sm:inline">Devices</span>
                </a>
                <a href="/logs/alert" class="text-xs font-semibold px-3 py-1.5 rounded-lg border transition-colors flex items-center gap-1.5 hover:bg-slate-800 text-slate-300 border-slate-700">
                    <i class="fa-solid fa-triangle-exclamation text-amber-400"></i>
                    <span class="hidden sm:inline">Log Alert</span>
                </a>
                <a href="/logs" class="text-xs font-semibold px-3 py-1.5 rounded-lg border transition-colors flex items-center gap-1.5 hover:bg-slate-800 text-slate-300 border-slate-700">
                    <i class="fa-solid fa-clock-rotate-left text-blue-400"></i>
                    <span class="hidden sm:inline">Logs</span>
                </a>

                <button id="themeToggle" class="w-9 h-9 rounded-lg border border-slate-700 flex items-center justify-center text-slate-300 hover:text-white hover:bg-slate-800 transition-colors" title="Toggle Dark/Light Mode">
                    <i class="fa-solid fa-sun text-sm dark:hidden"></i>
                    <i class="fa-solid fa-moon text-sm hidden dark:inline"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

        <!-- Stat Cards Row -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="p-4 rounded-xl border transition-all" style="background-color: var(--bg-card); border-color: var(--border-color);">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-400">Total Biometrics</span>
                    <i class="fa-solid fa-users text-slate-400"></i>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span id="statTotal" class="text-2xl font-bold tracking-tight text-white">{{ number_format($totalCount) }}</span>
                    <span class="text-xs text-slate-400">enrolled PINs</span>
                </div>
            </div>

            <div class="p-4 rounded-xl border transition-all" style="background-color: var(--bg-card); border-color: var(--border-color);">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-emerald-400">Mapped to HRBLIZ</span>
                    <i class="fa-solid fa-circle-check text-emerald-400"></i>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span id="statMapped" class="text-2xl font-bold tracking-tight text-emerald-400">{{ number_format($mappedCount) }}</span>
                    <span id="statMappedPct" class="text-xs text-slate-400">
                        {{ $totalCount > 0 ? round(($mappedCount / $totalCount) * 100, 1) : 0 }}%
                    </span>
                </div>
            </div>

            <div class="p-4 rounded-xl border transition-all" style="background-color: var(--bg-card); border-color: var(--border-color);">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-amber-400">Unmapped (Pending)</span>
                    <i class="fa-solid fa-clock text-amber-400"></i>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span id="statUnmapped" class="text-2xl font-bold tracking-tight text-amber-400">{{ number_format($unmappedCount) }}</span>
                    <span class="text-xs text-slate-400">need HRBLIZ ID</span>
                </div>
            </div>

            <div class="p-4 rounded-xl border transition-all" style="background-color: var(--bg-card); border-color: var(--border-color);">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-indigo-400">Excel / Upload Matched</span>
                    <i class="fa-solid fa-file-excel text-indigo-400"></i>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span id="statAnalyzed" class="text-2xl font-bold tracking-tight text-indigo-400">0</span>
                    <span id="statAnalyzedLabel" class="text-xs text-slate-400">pending upload</span>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="flex items-center justify-between border-b pb-2" style="border-color: var(--border-color);">
            <div class="flex items-center gap-2">
                <button id="tabBtnVerification" class="tab-btn active px-4 py-2 text-sm font-semibold rounded-lg flex items-center gap-2 transition-all bg-indigo-600 text-white shadow-sm shadow-indigo-600/30">
                    <i class="fa-solid fa-table-columns"></i>
                    <span>Side-by-Side Merge & Verification</span>
                    <span id="badgeVerifiedCount" class="hidden text-xs bg-indigo-800 text-indigo-200 px-2 py-0.5 rounded-full font-bold">0</span>
                </button>

                <button id="tabBtnManager" class="tab-btn px-4 py-2 text-sm font-medium rounded-lg flex items-center gap-2 transition-all text-slate-400 hover:text-white hover:bg-slate-800">
                    <i class="fa-solid fa-list-check"></i>
                    <span>Manual Biometrics Manager</span>
                </button>
            </div>

            <div class="flex items-center gap-2">
                @if($hasSample)
                <button id="btnLoadPreloaded" class="text-xs font-semibold px-3 py-1.5 rounded-lg border border-indigo-500/40 text-indigo-300 bg-indigo-500/10 hover:bg-indigo-500/20 transition-all flex items-center gap-1.5" title="Analyze the 3,069 HRBLIZ rows provided in the system">
                    <i class="fa-solid fa-bolt text-amber-400"></i>
                    <span>Load Provided HRBLIZ Data</span>
                </button>
                @endif
            </div>
        </div>

        <!-- TAB 1: SIDE-BY-SIDE VERIFICATION & MERGE -->
        <div id="tabContentVerification" class="space-y-6">

            <!-- Upload & Input Card -->
            <div class="rounded-xl border p-5 transition-all" style="background-color: var(--bg-card); border-color: var(--border-color);">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-700/60">
                    <div>
                        <h2 class="text-base font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-file-import text-indigo-400"></i>
                            1. Upload or Paste HRBLIZ Excel / CSV
                        </h2>
                        <p class="text-xs text-slate-400 mt-0.5">
                            Upload your biometric export file or paste rows. The system will analyze names and match with current biometrics.
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <button id="btnTogglePaste" class="text-xs font-semibold px-3 py-1.5 rounded-lg border border-slate-700 text-slate-300 hover:bg-slate-800 transition-colors flex items-center gap-1.5">
                            <i class="fa-solid fa-paste text-slate-400"></i>
                            <span id="txtTogglePaste">Paste CSV Text</span>
                        </button>
                    </div>
                </div>

                <!-- Upload Drag & Drop Area -->
                <div id="uploadContainer" class="mt-4">
                    <div id="dropZone" class="border-2 border-dashed border-slate-700 hover:border-indigo-500 rounded-xl p-8 text-center cursor-pointer transition-all bg-slate-900/40 hover:bg-indigo-950/20 group">
                        <input type="file" id="fileInput" class="hidden" accept=".xlsx,.xls,.csv,.txt">
                        <div class="flex flex-col items-center justify-center space-y-3">
                            <div class="w-14 h-14 rounded-full bg-indigo-600/10 border border-indigo-500/30 flex items-center justify-center text-indigo-400 group-hover:scale-110 transition-transform">
                                <i class="fa-solid fa-cloud-arrow-up text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-white">Click to upload or drag & drop</p>
                                <p class="text-xs text-slate-400 mt-1">Excel (.xlsx, .xls) or CSV (.csv, .txt) with <code class="text-indigo-300 bg-indigo-950/60 px-1 py-0.5 rounded">AC No.</code> and <code class="text-indigo-300 bg-indigo-950/60 px-1 py-0.5 rounded">Name</code></p>
                            </div>
                            <span id="selectedFileInfo" class="hidden text-xs font-medium px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                <i class="fa-solid fa-file-check mr-1"></i> <span id="selectedFileName">filename.xlsx</span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Paste Text Area (Hidden by default) -->
                <div id="pasteContainer" class="mt-4 hidden space-y-3">
                    <textarea id="rawCsvText" rows="6" placeholder="AC No.,No.,Name&#10;472,,&quot;Carreon, Manuel&quot;&#10;1001,,&quot;ABDULLA, ADAMIER P.&quot;&#10;1003,,&quot;ABUTAZIL, MOHAMMAD&quot;" class="w-full text-xs font-mono rounded-lg p-3 border border-slate-700 bg-slate-900/80 text-slate-200 placeholder-slate-600 focus:outline-none focus:border-indigo-500 custom-scrollbar"></textarea>
                    <div class="flex items-center justify-between text-xs text-slate-400">
                        <span>Format: Comma, tab, or semicolon separated with ID and Name columns.</span>
                        <button id="btnClearPaste" class="text-rose-400 hover:text-rose-300 text-xs">Clear text</button>
                    </div>
                </div>

                <!-- Action Button for Analysis -->
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 pt-3 border-t border-slate-800">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3 text-xs text-slate-400">
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-shield-halved text-emerald-400"></i>
                            <span>Zero data is committed until you review and click <strong>Merge</strong>.</span>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer text-slate-300 hover:text-white select-none bg-slate-900/60 px-3 py-1.5 rounded-lg border border-slate-700/80">
                            <input type="checkbox" id="chkExcludeAssigned" checked class="rounded border-slate-700 bg-slate-900 text-indigo-600 focus:ring-0 cursor-pointer">
                            <span>Don't include records with HRBLIZ already assigned</span>
                        </label>
                    </div>

                    <div class="flex items-center gap-3">
                        <button id="btnRunAnalysis" class="px-5 py-2 rounded-lg text-sm font-semibold bg-indigo-600 hover:bg-indigo-500 text-white shadow-md shadow-indigo-600/30 transition-all flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                            <i class="fa-solid fa-magnifying-glass-chart"></i>
                            <span>Analyze & Generate Verification List</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Analysis Loading Indicator -->
            <div id="analysisLoading" class="hidden rounded-xl border border-indigo-500/30 bg-indigo-950/20 p-8 text-center space-y-3">
                <i class="fa-solid fa-circle-notch fa-spin text-3xl text-indigo-400"></i>
                <h3 class="text-base font-bold text-white">Analyzing & Fuzzy-Matching Biometrics...</h3>
                <p class="text-xs text-slate-400 max-w-md mx-auto">Evaluating normalized names, token overlaps, and PIN structures against {{ number_format($totalCount) }} database records.</p>
            </div>

            <!-- Analysis Results & Verification Section -->
            <div id="verificationSection" class="hidden space-y-4">

                <!-- Summary Breakdown Banner -->
                <div class="rounded-xl border p-4 transition-all" style="background-color: var(--bg-card); border-color: var(--border-color);">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <div>
                            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                                <i class="fa-solid fa-circle-check text-emerald-400"></i>
                                2. Verification List & Match Analysis
                            </h3>
                            <p class="text-xs text-slate-400 mt-0.5">
                                Review paired records below. One side shows current biometrics in database, the other shows the Excel record.
                            </p>
                        </div>

                        <!-- Match Type Filter Buttons -->
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button class="filter-pill active text-xs font-semibold px-2.5 py-1.5 rounded-lg border transition-all bg-indigo-600 text-white border-indigo-500" data-filter="all">
                                All (<span id="countPillAll">0</span>)
                            </button>
                            <button class="filter-pill text-xs font-semibold px-2.5 py-1.5 rounded-lg border transition-all text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/10" data-filter="exact">
                                <i class="fa-solid fa-check-double mr-1"></i> Exact 100% (<span id="countPillExact">0</span>)
                            </button>
                            <button class="filter-pill text-xs font-semibold px-2.5 py-1.5 rounded-lg border transition-all text-blue-400 border-blue-500/30 hover:bg-blue-500/10" data-filter="high">
                                <i class="fa-solid fa-check mr-1"></i> High Overlap (<span id="countPillHigh">0</span>)
                            </button>
                            <button class="filter-pill text-xs font-semibold px-2.5 py-1.5 rounded-lg border transition-all text-amber-400 border-amber-500/30 hover:bg-amber-500/10" data-filter="possible">
                                <i class="fa-solid fa-triangle-exclamation mr-1"></i> Needs Review (<span id="countPillPossible">0</span>)
                            </button>
                            <button class="filter-pill text-xs font-semibold px-2.5 py-1.5 rounded-lg border transition-all text-slate-400 border-slate-700 hover:bg-slate-800" data-filter="unmatched">
                                <i class="fa-solid fa-question mr-1"></i> Unmatched (<span id="countPillUnmatched">0</span>)
                            </button>

                            <div id="badgeAlreadyAssignedWrapper" class="hidden items-center gap-1 px-2.5 py-1.5 rounded-lg bg-emerald-950/40 border border-emerald-500/30 text-emerald-400 text-xs font-medium" title="Records that already have an HRBLIZ ID in biometrics table were excluded from the queue">
                                <i class="fa-solid fa-shield-check"></i>
                                <span>Excluded (Already Assigned): <strong id="countAlreadyAssigned">0</strong></span>
                            </div>
                        </div>
                    </div>

                    <!-- Search and Selection Controls -->
                    <div class="mt-4 pt-3 border-t border-slate-800 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="relative w-64 sm:w-80">
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-xs text-slate-500"></i>
                                <input type="text" id="verificationSearch" placeholder="Filter by Name, PIN, or AC No..." class="w-full pl-9 pr-3 py-1.5 text-xs rounded-lg border border-slate-700 bg-slate-900/60 text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">
                            </div>

                            <div class="flex items-center gap-2 text-xs">
                                <button id="btnSelectAllFiltered" class="text-indigo-400 hover:text-indigo-300 font-semibold">Select All</button>
                                <span class="text-slate-600">|</span>
                                <button id="btnSelectHighOnly" class="text-emerald-400 hover:text-emerald-300 font-semibold">Select Confirmed (Exact + High)</button>
                                <span class="text-slate-600">|</span>
                                <button id="btnDeselectAll" class="text-slate-400 hover:text-slate-300">Deselect</button>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="text-xs text-slate-400">
                                Selected: <strong id="lblSelectedCount" class="text-white">0</strong>
                            </span>
                            <button id="btnBatchMerge" class="px-4 py-2 rounded-lg text-xs font-bold bg-emerald-600 hover:bg-emerald-500 text-white shadow-md shadow-emerald-600/30 transition-all flex items-center gap-1.5 disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                                <i class="fa-solid fa-code-merge"></i>
                                <span>Merge Selected into Database</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Side-by-Side Verification Table -->
                <div class="rounded-xl border overflow-hidden transition-all" style="background-color: var(--bg-card); border-color: var(--border-color);">
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-left text-xs">
                            <thead class="text-slate-400 border-b uppercase font-semibold text-[11px]" style="background-color: var(--table-head-bg); border-color: var(--border-color);">
                                <tr>
                                    <th class="py-3 px-3 w-10 text-center">
                                        <input type="checkbox" id="chkSelectAllTable" class="rounded border-slate-700 bg-slate-900 text-indigo-600 focus:ring-0">
                                    </th>
                                    <th class="py-3 px-4 w-5/12">
                                        <div class="flex items-center gap-1 text-slate-300">
                                            <i class="fa-solid fa-database text-indigo-400"></i>
                                            <span>Current Biometrics (System Database)</span>
                                        </div>
                                    </th>
                                    <th class="py-3 px-3 w-2/12 text-center">
                                        <span>Match Confidence</span>
                                    </th>
                                    <th class="py-3 px-4 w-4/12">
                                        <div class="flex items-center gap-1 text-slate-300">
                                            <i class="fa-solid fa-file-excel text-emerald-400"></i>
                                            <span>Excel / HRBLIZ Candidate</span>
                                        </div>
                                    </th>
                                    <th class="py-3 px-3 w-1/12 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody id="verificationTableBody" class="divide-y divide-slate-800">
                                <!-- Populated dynamically by JavaScript -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Empty state -->
                    <div id="verificationEmpty" class="hidden p-12 text-center space-y-2">
                        <i class="fa-solid fa-filter text-2xl text-slate-600"></i>
                        <p class="text-sm font-semibold text-slate-300">No records match the current filter</p>
                        <p class="text-xs text-slate-500">Try adjusting your search query or switching filter pills above.</p>
                    </div>

                    <!-- Pagination for verification list -->
                    <div class="p-3 border-t border-slate-800 flex items-center justify-between text-xs text-slate-400">
                        <span id="verificationPaginationInfo">Showing 0 of 0 records</span>
                        <div class="flex items-center gap-1" id="verificationPaginationButtons">
                            <!-- Page buttons -->
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: MANUAL BIOMETRICS MANAGER -->
        <div id="tabContentManager" class="hidden space-y-4">
            <div class="rounded-xl border p-4 transition-all" style="background-color: var(--bg-card); border-color: var(--border-color);">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-list-check text-indigo-400"></i>
                            Manual HRBLIZ Biometric ID Manager
                        </h2>
                        <p class="text-xs text-slate-400 mt-0.5">
                            Directly manage the <code class="text-indigo-300 bg-indigo-950/60 px-1 py-0.5 rounded">hrbliz_biometric_id</code> column on <code class="text-indigo-300 bg-indigo-950/60 px-1 py-0.5 rounded">biometrics</code> table.
                        </p>
                    </div>

                    <!-- Filters and Search -->
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative w-64">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-xs text-slate-500"></i>
                            <input type="text" id="managerSearch" placeholder="Search PIN, Name, or HRBLIZ ID..." class="w-full pl-9 pr-3 py-1.5 text-xs rounded-lg border border-slate-700 bg-slate-900/60 text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">
                        </div>

                        <div class="flex items-center rounded-lg border border-slate-700 p-0.5 bg-slate-900/60 text-xs">
                            <button class="manager-filter-btn active px-2.5 py-1 rounded font-medium bg-indigo-600 text-white" data-filter="all">All</button>
                            <button class="manager-filter-btn px-2.5 py-1 rounded font-medium text-slate-400 hover:text-white" data-filter="mapped">Mapped</button>
                            <button class="manager-filter-btn px-2.5 py-1 rounded font-medium text-slate-400 hover:text-white" data-filter="unmapped">Unmapped</button>
                        </div>

                        <select id="managerPerPage" class="text-xs rounded-lg border border-slate-700 bg-slate-900/60 text-slate-300 py-1.5 px-2.5 focus:outline-none focus:border-indigo-500">
                            <option value="50">50 / page</option>
                            <option value="100">100 / page</option>
                            <option value="250">250 / page</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Table of Biometrics Records -->
            <div class="rounded-xl border overflow-hidden transition-all" style="background-color: var(--bg-card); border-color: var(--border-color);">
                <div class="overflow-x-auto custom-scrollbar">
                    <table class="w-full text-left text-xs">
                        <thead class="text-slate-400 border-b uppercase font-semibold text-[11px]" style="background-color: var(--table-head-bg); border-color: var(--border-color);">
                            <tr>
                                <th class="py-3 px-4 w-28">Biometric PIN</th>
                                <th class="py-3 px-4">Employee Name</th>
                                <th class="py-3 px-4 w-72">HRBLIZ Biometric ID</th>
                                <th class="py-3 px-4 w-36 text-center">Status</th>
                                <th class="py-3 px-4 w-28 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody id="managerTableBody" class="divide-y divide-slate-800">
                            <tr>
                                <td colspan="5" class="py-8 text-center text-slate-400">
                                    <i class="fa-solid fa-circle-notch fa-spin mr-2"></i> Loading biometrics...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Manager Pagination Bar -->
                <div class="p-3 border-t border-slate-800 flex items-center justify-between text-xs text-slate-400">
                    <span id="managerPaginationInfo">Loading...</span>
                    <div class="flex items-center gap-1" id="managerPaginationButtons">
                        <!-- Populated by JS -->
                    </div>
                </div>
            </div>
        </div>

    </main>

    <!-- Modal: Candidate Picker for Manual Match -->
    <div id="candidateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-xs hidden">
        <div class="w-full max-w-xl rounded-xl border border-slate-700 bg-slate-900 shadow-2xl p-5 space-y-4 max-h-[85vh] flex flex-col">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h3 class="text-sm font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-link text-indigo-400"></i>
                        <span>Select Biometric Record for Link</span>
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Linking Excel: <span id="modalExcelName" class="text-emerald-400 font-semibold">NAME</span> (AC No: <span id="modalExcelAc" class="text-white font-mono">0000</span>)
                    </p>
                </div>
                <button id="modalCloseBtn" class="w-8 h-8 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 flex items-center justify-center">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Search inside modal -->
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-xs text-slate-500"></i>
                <input type="text" id="modalSearchInput" placeholder="Search by Biometric PIN or Name..." class="w-full pl-9 pr-3 py-2 text-xs rounded-lg border border-slate-700 bg-slate-950 text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">
            </div>

            <!-- Candidate list inside modal -->
            <div id="modalCandidateList" class="flex-1 overflow-y-auto space-y-2 pr-1 custom-scrollbar min-h-[220px]">
                <p class="text-xs text-slate-500 text-center py-6">Type to search biometrics...</p>
            </div>

            <div class="border-t border-slate-800 pt-3 flex items-center justify-between text-xs text-slate-400">
                <button id="btnUnlinkCurrent" class="text-rose-400 hover:text-rose-300 font-semibold">
                    <i class="fa-solid fa-unlink mr-1"></i> Clear / Unlink this row
                </button>
                <button id="modalCancelBtn" class="px-3 py-1.5 rounded-lg border border-slate-700 text-slate-300 hover:bg-slate-800">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toastNotification" class="fixed bottom-5 right-5 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-2xl border text-xs font-semibold transition-all duration-300 transform translate-y-12 opacity-0 pointer-events-none">
        <i id="toastIcon" class="fa-solid fa-circle-check text-base"></i>
        <span id="toastMessage">Action completed successfully.</span>
    </div>

    <!-- JavaScript Logic -->
    <script>
        const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        // App state
        let currentTab = 'verification';
        let verificationResults = [];
        let filteredVerification = [];
        let verificationFilter = 'all';
        let verificationSearchQuery = '';
        let verificationPage = 1;
        const VERIFICATION_PAGE_SIZE = 50;

        let managerPage = 1;
        let managerFilter = 'all';
        let managerSearchQuery = '';
        let managerPerPage = 50;

        let activeModalRowIndex = null;

        // Theme toggle
        const themeToggle = document.getElementById('themeToggle');
        themeToggle.addEventListener('click', () => {
            const isLight = document.documentElement.classList.toggle('light');
            localStorage.setItem('deviceTheme', isLight ? 'light' : 'dark');
            localStorage.setItem('alertTheme', isLight ? 'light' : 'dark');
        });

        // Tabs
        const tabBtnVerification = document.getElementById('tabBtnVerification');
        const tabBtnManager = document.getElementById('tabBtnManager');
        const tabContentVerification = document.getElementById('tabContentVerification');
        const tabContentManager = document.getElementById('tabContentManager');

        tabBtnVerification.addEventListener('click', () => switchTab('verification'));
        tabBtnManager.addEventListener('click', () => switchTab('manager'));

        function switchTab(tab) {
            currentTab = tab;
            if (tab === 'verification') {
                tabBtnVerification.classList.add('bg-indigo-600', 'text-white', 'shadow-sm', 'shadow-indigo-600/30');
                tabBtnVerification.classList.remove('text-slate-400');
                tabBtnManager.classList.remove('bg-indigo-600', 'text-white', 'shadow-sm', 'shadow-indigo-600/30');
                tabBtnManager.classList.add('text-slate-400');

                tabContentVerification.classList.remove('hidden');
                tabContentManager.classList.add('hidden');
            } else {
                tabBtnManager.classList.add('bg-indigo-600', 'text-white', 'shadow-sm', 'shadow-indigo-600/30');
                tabBtnManager.classList.remove('text-slate-400');
                tabBtnVerification.classList.remove('bg-indigo-600', 'text-white', 'shadow-sm', 'shadow-indigo-600/30');
                tabBtnVerification.classList.add('text-slate-400');

                tabContentManager.classList.remove('hidden');
                tabContentVerification.classList.add('hidden');
                loadManagerData();
            }
        }

        // Toggle Paste text container
        const btnTogglePaste = document.getElementById('btnTogglePaste');
        const pasteContainer = document.getElementById('pasteContainer');
        const txtTogglePaste = document.getElementById('txtTogglePaste');
        const uploadContainer = document.getElementById('uploadContainer');
        let pasteMode = false;

        btnTogglePaste.addEventListener('click', () => {
            pasteMode = !pasteMode;
            if (pasteMode) {
                pasteContainer.classList.remove('hidden');
                txtTogglePaste.textContent = 'Upload File Instead';
            } else {
                pasteContainer.classList.add('hidden');
                txtTogglePaste.textContent = 'Paste CSV Text';
            }
        });

        document.getElementById('btnClearPaste')?.addEventListener('click', () => {
            document.getElementById('rawCsvText').value = '';
        });

        // Dropzone & File Input
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const selectedFileInfo = document.getElementById('selectedFileInfo');
        const selectedFileName = document.getElementById('selectedFileName');
        let selectedFile = null;

        dropZone.addEventListener('click', () => fileInput.click());

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                handleFileSelect(e.target.files[0]);
            }
        });

        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('border-indigo-400', 'bg-indigo-950/30');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('border-indigo-400', 'bg-indigo-950/30');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('border-indigo-400', 'bg-indigo-950/30');
            if (e.dataTransfer.files.length > 0) {
                handleFileSelect(e.dataTransfer.files[0]);
            }
        });

        function handleFileSelect(file) {
            selectedFile = file;
            selectedFileName.textContent = `${file.name} (${(file.size / 1024).toFixed(1)} KB)`;
            selectedFileInfo.classList.remove('hidden');
        }

        // Run Analysis Button
        const btnRunAnalysis = document.getElementById('btnRunAnalysis');
        const btnLoadPreloaded = document.getElementById('btnLoadPreloaded');
        const analysisLoading = document.getElementById('analysisLoading');
        const verificationSection = document.getElementById('verificationSection');

        btnRunAnalysis.addEventListener('click', () => runAnalysis(false));

        if (btnLoadPreloaded) {
            btnLoadPreloaded.addEventListener('click', () => runAnalysis(true));
        }

        async function runAnalysis(useSample = false) {
            const formData = new FormData();

            if (useSample) {
                formData.append('use_sample', '1');
            } else if (pasteMode && document.getElementById('rawCsvText').value.trim()) {
                formData.append('raw_csv', document.getElementById('rawCsvText').value.trim());
            } else if (selectedFile) {
                formData.append('file', selectedFile);
            } else {
                showToast('Please select a file or paste CSV text first.', 'warning');
                return;
            }

            const chkExclude = document.getElementById('chkExcludeAssigned');
            const excludeAssigned = chkExclude ? chkExclude.checked : true;
            formData.append('exclude_assigned', excludeAssigned ? '1' : '0');

            analysisLoading.classList.remove('hidden');
            verificationSection.classList.add('hidden');
            btnRunAnalysis.disabled = true;

            try {
                const res = await fetch('/biometrics/hrbliz/analyze', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': CSRF_TOKEN,
                        'Accept': 'application/json',
                    },
                    body: formData,
                });

                const data = await res.json();
                analysisLoading.classList.add('hidden');
                btnRunAnalysis.disabled = false;

                if (!res.ok || !data.success) {
                    showToast(data.message || 'Failed to analyze records.', 'error');
                    return;
                }

                verificationResults = data.results || [];
                // Initialize checked status: automatically check exact and high confidence matches
                verificationResults.forEach(r => {
                    r.checked = (r.status === 'exact' || r.status === 'high');
                });

                // Update summary badges
                updateVerificationSummaryStats(data.summary);

                verificationPage = 1;
                filterAndRenderVerification();
                verificationSection.classList.remove('hidden');
                showToast(data.message, 'success');

                // Smooth scroll to results
                verificationSection.scrollIntoView({ behavior: 'smooth' });
            } catch (err) {
                analysisLoading.classList.add('hidden');
                btnRunAnalysis.disabled = false;
                showToast('Network error while analyzing file: ' + err.message, 'error');
            }
        }

        // Exclude already assigned checkbox toggle listener
        document.getElementById('chkExcludeAssigned')?.addEventListener('change', () => {
            verificationPage = 1;
            updateVerificationSummaryStats();
            filterAndRenderVerification();
        });

        // Verification Filters
        document.querySelectorAll('.filter-pill').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.filter-pill').forEach(b => {
                    b.classList.remove('bg-indigo-600', 'text-white', 'border-indigo-500');
                    b.classList.add('border-slate-700', 'text-slate-400');
                });
                btn.classList.add('bg-indigo-600', 'text-white', 'border-indigo-500');
                btn.classList.remove('border-slate-700', 'text-slate-400');

                verificationFilter = btn.dataset.filter;
                verificationPage = 1;
                filterAndRenderVerification();
            });
        });

        // Verification Search
        const verificationSearch = document.getElementById('verificationSearch');
        verificationSearch.addEventListener('input', (e) => {
            verificationSearchQuery = e.target.value.toLowerCase().trim();
            verificationPage = 1;
            filterAndRenderVerification();
        });

        function updateVerificationSummaryStats(initialSummary = null) {
            const excludeAssigned = document.getElementById('chkExcludeAssigned')?.checked ?? true;
            const activeResults = verificationResults.filter(r => {
                if (excludeAssigned && (r.already_assigned || (r.matched_biometric && r.matched_biometric.hrbliz_biometric_id !== null))) {
                    return false;
                }
                return true;
            });

            if (initialSummary) {
                document.getElementById('statAnalyzed').textContent = initialSummary.matched_count;
                document.getElementById('statAnalyzedLabel').textContent = `${initialSummary.total_rows} total rows (${Math.round((initialSummary.matched_count / initialSummary.total_rows) * 100)}% match)`;

                const alreadyAssigned = initialSummary.already_assigned_count || 0;
                const badgeAssigned = document.getElementById('badgeAlreadyAssignedWrapper');
                const countAssigned = document.getElementById('countAlreadyAssigned');
                if (badgeAssigned && countAssigned) {
                    if (alreadyAssigned > 0) {
                        countAssigned.textContent = alreadyAssigned;
                        badgeAssigned.classList.remove('hidden');
                        badgeAssigned.classList.add('inline-flex');
                    } else {
                        badgeAssigned.classList.add('hidden');
                        badgeAssigned.classList.remove('inline-flex');
                    }
                }
            } else {
                const total = activeResults.length;
                const matched = activeResults.filter(r => r.status !== 'unmatched').length;
                document.getElementById('statAnalyzed').textContent = matched;
                document.getElementById('statAnalyzedLabel').textContent = `${total} remaining in queue`;
            }

            const total = activeResults.length;
            let exact = 0;
            let high = 0;
            let possible = 0;
            let unmatched = 0;

            activeResults.forEach(r => {
                if (r.status === 'exact') exact++;
                else if (r.status === 'high') high++;
                else if (r.status === 'possible') possible++;
                else if (r.status === 'unmatched') unmatched++;
            });

            document.getElementById('countPillAll').textContent = total;
            document.getElementById('countPillExact').textContent = exact;
            document.getElementById('countPillHigh').textContent = high;
            document.getElementById('countPillPossible').textContent = possible;
            document.getElementById('countPillUnmatched').textContent = unmatched;

            const matchedRemaining = exact + high + possible;
            document.getElementById('badgeVerifiedCount').textContent = matchedRemaining;
            if (matchedRemaining === 0) {
                document.getElementById('badgeVerifiedCount').classList.add('hidden');
            } else {
                document.getElementById('badgeVerifiedCount').classList.remove('hidden');
            }
        }

        function filterAndRenderVerification() {
            const excludeAssigned = document.getElementById('chkExcludeAssigned')?.checked ?? true;

            filteredVerification = verificationResults.filter(row => {
                // If excludeAssigned is active, filter out any record where the matched biometric already has hrbliz_biometric_id
                if (excludeAssigned && (row.already_assigned || (row.matched_biometric && row.matched_biometric.hrbliz_biometric_id !== null))) {
                    return false;
                }

                // Filter by match type
                if (verificationFilter !== 'all' && row.status !== verificationFilter) {
                    return false;
                }

                // Filter by search text
                if (verificationSearchQuery) {
                    const matchExcelName = row.excel_name.toLowerCase().includes(verificationSearchQuery);
                    const matchExcelAc = String(row.excel_ac_no).includes(verificationSearchQuery);
                    const matchBioName = row.matched_biometric?.name?.toLowerCase().includes(verificationSearchQuery) || false;
                    const matchBioPin = String(row.matched_biometric?.biometric_id || '').includes(verificationSearchQuery);
                    if (!matchExcelName && !matchExcelAc && !matchBioName && !matchBioPin) {
                        return false;
                    }
                }

                return true;
            });

            renderVerificationTable();
            updateSelectionCounts();
        }

        function renderVerificationTable() {
            const tbody = document.getElementById('verificationTableBody');
            const emptyEl = document.getElementById('verificationEmpty');

            if (filteredVerification.length === 0) {
                tbody.innerHTML = '';
                emptyEl.classList.remove('hidden');
                if (verificationResults.length === 0) {
                    emptyEl.innerHTML = `
                        <div class="text-center py-10 space-y-2">
                            <div class="w-14 h-14 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center mx-auto text-2xl mb-3">
                                <i class="fa-solid fa-circle-check"></i>
                            </div>
                            <h4 class="text-base font-bold text-white">All Records Successfully Merged!</h4>
                            <p class="text-xs text-slate-400 max-w-sm mx-auto">There are no remaining records in this verification list. All matched biometric IDs have been saved.</p>
                        </div>
                    `;
                } else {
                    emptyEl.innerHTML = `
                        <div class="text-center py-8 space-y-2">
                            <i class="fa-solid fa-filter text-2xl text-slate-600 mb-2"></i>
                            <p class="text-sm font-semibold text-slate-300">No records match the current filter</p>
                            <p class="text-xs text-slate-500">Try adjusting your search query or switching filter pills above.</p>
                        </div>
                    `;
                }
                document.getElementById('verificationPaginationInfo').textContent = 'Showing 0 records';
                document.getElementById('verificationPaginationButtons').innerHTML = '';
                return;
            }

            emptyEl.classList.add('hidden');

            const total = filteredVerification.length;
            const startIdx = (verificationPage - 1) * VERIFICATION_PAGE_SIZE;
            const pageItems = filteredVerification.slice(startIdx, startIdx + VERIFICATION_PAGE_SIZE);

            let html = '';
            pageItems.forEach((row, pageOffset) => {
                const globalIdx = verificationResults.indexOf(row);
                const hasBio = !!row.matched_biometric;
                const bioPin = hasBio ? row.matched_biometric.biometric_id : null;
                const bioName = hasBio ? row.matched_biometric.name : 'No Match Found';
                const currentHrbliz = hasBio && row.matched_biometric.hrbliz_biometric_id ? row.matched_biometric.hrbliz_biometric_id : null;

                // Match confidence pill
                let badgeClass = 'bg-slate-800 text-slate-400 border-slate-700';
                let badgeIcon = 'fa-question';
                let badgeText = 'Unmatched';

                if (row.status === 'exact') {
                    badgeClass = 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30';
                    badgeIcon = 'fa-check-double';
                    badgeText = '100% Exact';
                } else if (row.status === 'high') {
                    badgeClass = 'bg-blue-500/15 text-blue-400 border-blue-500/30';
                    badgeIcon = 'fa-check';
                    badgeText = `${row.confidence}% Match`;
                } else if (row.status === 'possible') {
                    badgeClass = 'bg-amber-500/15 text-amber-400 border-amber-500/30';
                    badgeIcon = 'fa-triangle-exclamation';
                    badgeText = `${row.confidence}% Review`;
                }

                const isChecked = row.checked ? 'checked' : '';
                const disabledCheck = !hasBio ? 'disabled' : '';

                html += `
                    <tr class="hover:bg-slate-800/40 transition-colors ${row.checked ? 'bg-indigo-950/15' : ''}">
                        <!-- Selection Checkbox -->
                        <td class="py-3 px-3 text-center">
                            <input type="checkbox" class="row-chk rounded border-slate-700 bg-slate-900 text-indigo-600 focus:ring-0 cursor-pointer" data-idx="${globalIdx}" ${isChecked} ${disabledCheck}>
                        </td>

                        <!-- Left Side: Database Biometric -->
                        <td class="py-3 px-4">
                            ${hasBio ? `
                                <div class="flex items-center gap-2.5">
                                    <span class="font-mono text-xs font-bold px-2 py-0.5 rounded bg-slate-800 text-indigo-300 border border-slate-700">
                                        PIN #${bioPin}
                                    </span>
                                    <div>
                                        <div class="font-semibold text-white">${escapeHtml(bioName)}</div>
                                        <div class="text-[11px] text-slate-400 flex items-center gap-2">
                                            <span>Current HRBLIZ:</span>
                                            ${currentHrbliz 
                                                ? `<span class="font-mono text-emerald-400 font-medium">#${currentHrbliz}</span>`
                                                : `<span class="text-slate-500 italic">None</span>`
                                            }
                                        </div>
                                    </div>
                                </div>
                            ` : `
                                <div class="flex items-center gap-2 text-slate-500 italic">
                                    <i class="fa-solid fa-circle-exclamation text-amber-500/60"></i>
                                    <span>No corresponding employee in biometrics database</span>
                                </div>
                            `}
                        </td>

                        <!-- Center: Confidence & Method -->
                        <td class="py-3 px-3 text-center">
                            <div class="inline-flex flex-col items-center">
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border ${badgeClass} inline-flex items-center gap-1">
                                    <i class="fa-solid ${badgeIcon}"></i>
                                    <span>${badgeText}</span>
                                </span>
                                <span class="text-[10px] text-slate-500 mt-0.5">${escapeHtml(row.method || '')}</span>
                            </div>
                        </td>

                        <!-- Right Side: Excel / HRBLIZ Record -->
                        <td class="py-3 px-4">
                            <div class="flex items-center gap-2.5">
                                <div class="flex items-center gap-1">
                                    <span class="text-[11px] text-slate-400">ID:</span>
                                    <input type="number" class="hrbliz-id-input font-mono text-xs font-bold w-20 px-2 py-0.5 rounded border border-emerald-500/40 bg-emerald-950/20 text-emerald-300 focus:outline-none focus:border-emerald-400" value="${row.proposed_hrbliz_id}" data-idx="${globalIdx}">
                                </div>
                                <div class="font-semibold text-white">
                                    ${escapeHtml(row.excel_name)}
                                </div>
                            </div>
                        </td>

                        <!-- Actions -->
                        <td class="py-3 px-3 text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                <button class="btn-change-match p-1.5 rounded text-slate-400 hover:text-white hover:bg-slate-800 transition-colors" data-idx="${globalIdx}" title="Change matched employee">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                ${hasBio ? `
                                    <button class="btn-quick-merge px-2 py-1 rounded text-[11px] font-bold bg-emerald-600/20 hover:bg-emerald-600 text-emerald-300 hover:text-white border border-emerald-500/30 transition-all" data-idx="${globalIdx}" title="Save this single merge to DB">
                                        Merge
                                    </button>
                                ` : ''}
                            </div>
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;

            // Render pagination controls
            renderVerificationPagination(total);

            // Bind checkbox listeners
            tbody.querySelectorAll('.row-chk').forEach(chk => {
                chk.addEventListener('change', (e) => {
                    const idx = parseInt(e.target.dataset.idx, 10);
                    verificationResults[idx].checked = e.target.checked;
                    updateSelectionCounts();
                });
            });

            // Bind inline HRBLIZ ID input listeners
            tbody.querySelectorAll('.hrbliz-id-input').forEach(input => {
                input.addEventListener('change', (e) => {
                    const idx = parseInt(e.target.dataset.idx, 10);
                    verificationResults[idx].proposed_hrbliz_id = parseInt(e.target.value, 10) || 0;
                });
            });

            // Bind Change Match modal buttons
            tbody.querySelectorAll('.btn-change-match').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const idx = parseInt(btn.dataset.idx, 10);
                    openCandidateModal(idx);
                });
            });

            // Bind Quick Merge buttons
            tbody.querySelectorAll('.btn-quick-merge').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const idx = parseInt(btn.dataset.idx, 10);
                    executeSingleMerge(idx);
                });
            });
        }

        function renderVerificationPagination(total) {
            const startIdx = (verificationPage - 1) * VERIFICATION_PAGE_SIZE + 1;
            const endIdx = Math.min(verificationPage * VERIFICATION_PAGE_SIZE, total);
            const totalPages = Math.ceil(total / VERIFICATION_PAGE_SIZE);

            document.getElementById('verificationPaginationInfo').textContent = `Showing ${startIdx}-${endIdx} of ${total} records`;

            const btnContainer = document.getElementById('verificationPaginationButtons');
            if (totalPages <= 1) {
                btnContainer.innerHTML = '';
                return;
            }

            let buttonsHtml = '';
            buttonsHtml += `<button class="ver-page-btn px-2 py-1 rounded border border-slate-700 bg-slate-900 text-slate-300 hover:bg-slate-800 disabled:opacity-30" data-page="${verificationPage - 1}" ${verificationPage <= 1 ? 'disabled' : ''}>Prev</button>`;

            // Show page numbers
            const maxPages = 5;
            let startPage = Math.max(1, verificationPage - 2);
            let endPage = Math.min(totalPages, startPage + maxPages - 1);
            if (endPage - startPage < maxPages - 1) {
                startPage = Math.max(1, endPage - maxPages + 1);
            }

            for (let p = startPage; p <= endPage; p++) {
                buttonsHtml += `<button class="ver-page-btn px-2.5 py-1 rounded border ${p === verificationPage ? 'bg-indigo-600 text-white border-indigo-500 font-bold' : 'border-slate-700 bg-slate-900 text-slate-300 hover:bg-slate-800'}" data-page="${p}">${p}</button>`;
            }

            buttonsHtml += `<button class="ver-page-btn px-2 py-1 rounded border border-slate-700 bg-slate-900 text-slate-300 hover:bg-slate-800 disabled:opacity-30" data-page="${verificationPage + 1}" ${verificationPage >= totalPages ? 'disabled' : ''}>Next</button>`;

            btnContainer.innerHTML = buttonsHtml;

            btnContainer.querySelectorAll('.ver-page-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const p = parseInt(btn.dataset.page, 10);
                    if (p >= 1 && p <= totalPages) {
                        verificationPage = p;
                        renderVerificationTable();
                    }
                });
            });
        }

        // Selection actions
        function updateSelectionCounts() {
            const count = verificationResults.filter(r => r.checked && r.matched_biometric).length;
            document.getElementById('lblSelectedCount').textContent = count;
            const btnBatch = document.getElementById('btnBatchMerge');
            btnBatch.disabled = count === 0;

            const allFilteredChecked = filteredVerification.length > 0 && filteredVerification.every(r => !r.matched_biometric || r.checked);
            const chkAll = document.getElementById('chkSelectAllTable');
            if (chkAll) chkAll.checked = allFilteredChecked;
        }

        document.getElementById('chkSelectAllTable')?.addEventListener('change', (e) => {
            filteredVerification.forEach(r => {
                if (r.matched_biometric) r.checked = e.target.checked;
            });
            renderVerificationTable();
            updateSelectionCounts();
        });

        document.getElementById('btnSelectAllFiltered')?.addEventListener('click', () => {
            filteredVerification.forEach(r => {
                if (r.matched_biometric) r.checked = true;
            });
            renderVerificationTable();
            updateSelectionCounts();
        });

        document.getElementById('btnSelectHighOnly')?.addEventListener('click', () => {
            verificationResults.forEach(r => {
                r.checked = (r.status === 'exact' || r.status === 'high') && !!r.matched_biometric;
            });
            renderVerificationTable();
            updateSelectionCounts();
        });

        document.getElementById('btnDeselectAll')?.addEventListener('click', () => {
            verificationResults.forEach(r => r.checked = false);
            renderVerificationTable();
            updateSelectionCounts();
        });

        // Batch Merge Action
        document.getElementById('btnBatchMerge').addEventListener('click', async () => {
            const toMerge = verificationResults
                .filter(r => r.checked && r.matched_biometric)
                .map(r => ({
                    biometric_id: r.matched_biometric.biometric_id,
                    hrbliz_biometric_id: r.proposed_hrbliz_id || r.excel_ac_no,
                }));

            if (toMerge.length === 0) {
                showToast('No checked records to merge.', 'warning');
                return;
            }

            if (!confirm(`Are you sure you want to merge ${toMerge.length} HRBLIZ Biometric IDs into the database?`)) {
                return;
            }

            const btn = document.getElementById('btnBatchMerge');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Merging ${toMerge.length}...`;

            try {
                const res = await fetch('/biometrics/hrbliz/batch-merge', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF_TOKEN,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ mappings: toMerge }),
                });

                const data = await res.json();
                btn.disabled = false;
                btn.innerHTML = originalText;

                if (!res.ok || !data.success) {
                    showToast(data.message || 'Batch merge failed.', 'error');
                    return;
                }

                // Remove successfully merged rows from verificationResults
                const mergedPinSet = new Set(toMerge.map(m => m.biometric_id));
                verificationResults = verificationResults.filter(r => {
                    if (!r.matched_biometric) return true;
                    return !mergedPinSet.has(r.matched_biometric.biometric_id);
                });

                // Adjust page if current page exceeds new total pages
                const maxPage = Math.max(1, Math.ceil(verificationResults.length / VERIFICATION_PAGE_SIZE));
                if (verificationPage > maxPage) {
                    verificationPage = maxPage;
                }

                updateVerificationSummaryStats();
                filterAndRenderVerification();
                updateSelectionCounts();
                refreshHeaderStats();
                showToast(`Successfully merged ${data.updated_count} records and removed from list.`, 'success');
            } catch (err) {
                btn.disabled = false;
                btn.innerHTML = originalText;
                showToast('Network error during merge: ' + err.message, 'error');
            }
        });

        // Single Quick Merge
        async function executeSingleMerge(idx) {
            const row = verificationResults[idx];
            if (!row || !row.matched_biometric) return;

            const pin = row.matched_biometric.biometric_id;
            const hrblizId = row.proposed_hrbliz_id || row.excel_ac_no;

            try {
                const res = await fetch('/biometrics/hrbliz/update', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF_TOKEN,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        biometric_id: pin,
                        hrbliz_biometric_id: hrblizId,
                    }),
                });

                const data = await res.json();
                if (!res.ok || !data.success) {
                    showToast(data.message || 'Failed to update record.', 'error');
                    return;
                }

                // Remove successfully merged single row from verificationResults
                const removeIdx = verificationResults.indexOf(row);
                if (removeIdx !== -1) {
                    verificationResults.splice(removeIdx, 1);
                }

                const maxPage = Math.max(1, Math.ceil(verificationResults.length / VERIFICATION_PAGE_SIZE));
                if (verificationPage > maxPage) {
                    verificationPage = maxPage;
                }

                updateVerificationSummaryStats();
                filterAndRenderVerification();
                updateSelectionCounts();
                refreshHeaderStats();
                showToast(`Merged PIN #${pin} with HRBLIZ #${hrblizId} and removed from queue.`, 'success');
            } catch (err) {
                showToast('Network error: ' + err.message, 'error');
            }
        }

        // Candidate Selection Modal ("Change Match")
        const candidateModal = document.getElementById('candidateModal');
        const modalCloseBtn = document.getElementById('modalCloseBtn');
        const modalCancelBtn = document.getElementById('modalCancelBtn');
        const modalSearchInput = document.getElementById('modalSearchInput');
        const modalCandidateList = document.getElementById('modalCandidateList');
        const btnUnlinkCurrent = document.getElementById('btnUnlinkCurrent');

        modalCloseBtn.addEventListener('click', closeCandidateModal);
        modalCancelBtn.addEventListener('click', closeCandidateModal);

        function openCandidateModal(idx) {
            activeModalRowIndex = idx;
            const row = verificationResults[idx];
            if (!row) return;

            document.getElementById('modalExcelName').textContent = row.excel_name;
            document.getElementById('modalExcelAc').textContent = `#${row.excel_ac_no}`;
            modalSearchInput.value = '';

            // Show top alternatives or search
            let initialHtml = '';
            if (row.candidate_alternatives && row.candidate_alternatives.length > 0) {
                initialHtml += `<p class="text-[11px] font-semibold text-slate-400 mb-2">Suggested Candidates:</p>`;
                row.candidate_alternatives.forEach(cand => {
                    initialHtml += renderCandidateItem(cand.biometric_id, cand.name, null, `${cand.score}% Overlap`);
                });
            }

            modalCandidateList.innerHTML = initialHtml || '<p class="text-xs text-slate-500 text-center py-6">Type above to search database...</p>';
            candidateModal.classList.remove('hidden');
            modalSearchInput.focus();
        }

        function closeCandidateModal() {
            candidateModal.classList.add('hidden');
            activeModalRowIndex = null;
        }

        let modalSearchTimeout = null;
        modalSearchInput.addEventListener('input', (e) => {
            clearTimeout(modalSearchTimeout);
            modalSearchTimeout = setTimeout(() => {
                searchModalCandidates(e.target.value.trim());
            }, 250);
        });

        async function searchModalCandidates(query) {
            if (!query) {
                modalCandidateList.innerHTML = '<p class="text-xs text-slate-500 text-center py-6">Type to search database...</p>';
                return;
            }

            modalCandidateList.innerHTML = '<p class="text-xs text-slate-400 text-center py-6"><i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Searching...</p>';

            try {
                const res = await fetch(`/biometrics/hrbliz/candidates?q=${encodeURIComponent(query)}&limit=15`, {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();
                const candidates = data.candidates || [];

                if (candidates.length === 0) {
                    modalCandidateList.innerHTML = '<p class="text-xs text-slate-500 text-center py-6">No matching biometrics found.</p>';
                    return;
                }

                let html = '';
                candidates.forEach(c => {
                    html += renderCandidateItem(c.biometric_id, c.name, c.hrbliz_biometric_id);
                });
                modalCandidateList.innerHTML = html;

                modalCandidateList.querySelectorAll('.candidate-select-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const pin = parseInt(btn.dataset.pin, 10);
                        const name = btn.dataset.name;
                        const hrbliz = btn.dataset.hrbliz ? parseInt(btn.dataset.hrbliz, 10) : null;
                        assignBiometricToActiveRow(pin, name, hrbliz);
                    });
                });
            } catch (err) {
                modalCandidateList.innerHTML = `<p class="text-xs text-rose-400 text-center py-6">Error: ${err.message}</p>`;
            }
        }

        function renderCandidateItem(pin, name, currentHrbliz = null, scoreBadge = null) {
            return `
                <div class="flex items-center justify-between p-2.5 rounded-lg border border-slate-800 bg-slate-950/60 hover:bg-slate-800/60 transition-colors">
                    <div class="flex items-center gap-2.5">
                        <span class="font-mono text-xs font-bold px-2 py-0.5 rounded bg-slate-800 text-indigo-300 border border-slate-700">
                            PIN #${pin}
                        </span>
                        <div>
                            <div class="font-semibold text-white text-xs">${escapeHtml(name)}</div>
                            <div class="text-[10px] text-slate-400">
                                Current HRBLIZ: ${currentHrbliz ? `#${currentHrbliz}` : 'None'}
                                ${scoreBadge ? `<span class="ml-2 px-1.5 py-0.2 rounded bg-indigo-500/20 text-indigo-300">${scoreBadge}</span>` : ''}
                            </div>
                        </div>
                    </div>
                    <button class="candidate-select-btn px-2.5 py-1 text-xs font-semibold rounded bg-indigo-600 hover:bg-indigo-500 text-white" data-pin="${pin}" data-name="${escapeHtml(name)}" data-hrbliz="${currentHrbliz || ''}">
                        Select
                    </button>
                </div>
            `;
        }

        function assignBiometricToActiveRow(pin, name, currentHrbliz) {
            if (activeModalRowIndex === null) return;
            const row = verificationResults[activeModalRowIndex];
            if (!row) return;

            row.matched_biometric = {
                biometric_id: pin,
                name: name,
                hrbliz_biometric_id: currentHrbliz,
            };
            row.status = 'exact'; // manual override
            row.confidence = 100;
            row.method = 'Manual User Selection';
            row.checked = true;

            renderVerificationTable();
            updateSelectionCounts();
            closeCandidateModal();
            showToast(`Linked PIN #${pin} (${name}) to Excel record.`, 'success');
        }

        btnUnlinkCurrent.addEventListener('click', () => {
            if (activeModalRowIndex === null) return;
            const row = verificationResults[activeModalRowIndex];
            if (!row) return;

            row.matched_biometric = null;
            row.status = 'unmatched';
            row.confidence = 0;
            row.method = 'Unlinked by user';
            row.checked = false;

            renderVerificationTable();
            updateSelectionCounts();
            closeCandidateModal();
            showToast('Unlinked row.', 'info');
        });

        // TAB 2: MANUAL BIOMETRICS MANAGER LOGIC
        const managerSearch = document.getElementById('managerSearch');
        const managerPerPageSelect = document.getElementById('managerPerPage');
        let managerSearchTimeout = null;

        managerSearch.addEventListener('input', (e) => {
            clearTimeout(managerSearchTimeout);
            managerSearchTimeout = setTimeout(() => {
                managerSearchQuery = e.target.value.trim();
                managerPage = 1;
                loadManagerData();
            }, 300);
        });

        document.querySelectorAll('.manager-filter-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.manager-filter-btn').forEach(b => {
                    b.classList.remove('bg-indigo-600', 'text-white');
                    b.classList.add('text-slate-400');
                });
                btn.classList.add('bg-indigo-600', 'text-white');
                btn.classList.remove('text-slate-400');

                managerFilter = btn.dataset.filter;
                managerPage = 1;
                loadManagerData();
            });
        });

        managerPerPageSelect.addEventListener('change', (e) => {
            managerPerPage = parseInt(e.target.value, 10);
            managerPage = 1;
            loadManagerData();
        });

        async function loadManagerData() {
            const tbody = document.getElementById('managerTableBody');
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="py-8 text-center text-slate-400">
                        <i class="fa-solid fa-circle-notch fa-spin mr-2"></i> Loading biometrics data...
                    </td>
                </tr>
            `;

            const url = `/biometrics/hrbliz/list?page=${managerPage}&per_page=${managerPerPage}&filter=${managerFilter}&search=${encodeURIComponent(managerSearchQuery)}`;

            try {
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();

                if (!res.ok || !data.success) {
                    tbody.innerHTML = `<tr><td colspan="5" class="py-6 text-center text-rose-400">Failed to load data.</td></tr>`;
                    return;
                }

                const items = data.data || [];
                const pag = data.pagination;

                if (items.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="5" class="py-10 text-center text-slate-500">
                                <i class="fa-solid fa-users-slash text-2xl mb-2"></i>
                                <p>No biometrics records found matching your filters.</p>
                            </td>
                        </tr>
                    `;
                    document.getElementById('managerPaginationInfo').textContent = 'Showing 0 records';
                    document.getElementById('managerPaginationButtons').innerHTML = '';
                    return;
                }

                let html = '';
                items.forEach(item => {
                    const isMapped = item.hrbliz_biometric_id !== null;
                    html += `
                        <tr class="hover:bg-slate-800/40 transition-colors">
                            <!-- PIN -->
                            <td class="py-3 px-4 font-mono font-bold text-indigo-300">
                                #${item.biometric_id}
                            </td>

                            <!-- Employee Name -->
                            <td class="py-3 px-4 font-semibold text-white">
                                ${escapeHtml(item.name || 'Unknown')}
                            </td>

                            <!-- HRBLIZ ID Input -->
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-2">
                                    <input type="number" class="manager-hrbliz-input font-mono text-xs w-28 px-2.5 py-1 rounded border border-slate-700 bg-slate-900 text-white focus:outline-none focus:border-indigo-500" value="${item.hrbliz_biometric_id ?? ''}" placeholder="None" data-pin="${item.biometric_id}">
                                    <button class="btn-manager-save px-2.5 py-1 text-xs font-semibold rounded bg-indigo-600 hover:bg-indigo-500 text-white transition-colors" data-pin="${item.biometric_id}">
                                        Save
                                    </button>
                                    ${isMapped ? `
                                        <button class="btn-manager-clear text-slate-500 hover:text-rose-400 p-1 rounded" data-pin="${item.biometric_id}" title="Clear HRBLIZ ID">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    ` : ''}
                                </div>
                            </td>

                            <!-- Status -->
                            <td class="py-3 px-4 text-center">
                                ${isMapped ? `
                                    <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                                        <i class="fa-solid fa-circle-check"></i>
                                        <span>Mapped (#${item.hrbliz_biometric_id})</span>
                                    </span>
                                ` : `
                                    <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-800 text-slate-400 border border-slate-700">
                                        <i class="fa-solid fa-clock"></i>
                                        <span>Pending</span>
                                    </span>
                                `}
                            </td>

                            <!-- Action -->
                            <td class="py-3 px-4 text-right">
                                <span class="text-xs text-slate-500 font-mono">ID: ${item.id}</span>
                            </td>
                        </tr>
                    `;
                });

                tbody.innerHTML = html;

                // Render manager pagination
                renderManagerPagination(pag);

                // Bind save buttons in manager table
                tbody.querySelectorAll('.btn-manager-save').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        const pin = parseInt(btn.dataset.pin, 10);
                        const row = btn.closest('tr');
                        const input = row.querySelector('.manager-hrbliz-input');
                        const val = input.value.trim();
                        saveManagerHrblizId(pin, val === '' ? null : parseInt(val, 10));
                    });
                });

                // Bind clear buttons in manager table
                tbody.querySelectorAll('.btn-manager-clear').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const pin = parseInt(btn.dataset.pin, 10);
                        saveManagerHrblizId(pin, null);
                    });
                });

                // Enter key in input
                tbody.querySelectorAll('.manager-hrbliz-input').forEach(input => {
                    input.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            const pin = parseInt(input.dataset.pin, 10);
                            const val = input.value.trim();
                            saveManagerHrblizId(pin, val === '' ? null : parseInt(val, 10));
                        }
                    });
                });

            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="5" class="py-6 text-center text-rose-400">Error: ${err.message}</td></tr>`;
            }
        }

        async function saveManagerHrblizId(pin, hrblizId) {
            try {
                const res = await fetch('/biometrics/hrbliz/update', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF_TOKEN,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        biometric_id: pin,
                        hrbliz_biometric_id: hrblizId,
                    }),
                });

                const data = await res.json();
                if (!res.ok || !data.success) {
                    showToast(data.message || 'Failed to update record.', 'error');
                    return;
                }

                showToast(data.message, 'success');
                loadManagerData();
                refreshHeaderStats();
            } catch (err) {
                showToast('Network error: ' + err.message, 'error');
            }
        }

        function renderManagerPagination(pag) {
            const startIdx = (pag.current_page - 1) * pag.per_page + 1;
            const endIdx = Math.min(pag.current_page * pag.per_page, pag.total);
            document.getElementById('managerPaginationInfo').textContent = `Showing ${startIdx}-${endIdx} of ${pag.total} biometrics`;

            const btnContainer = document.getElementById('managerPaginationButtons');
            if (pag.last_page <= 1) {
                btnContainer.innerHTML = '';
                return;
            }

            let html = '';
            html += `<button class="mgr-page-btn px-2 py-1 rounded border border-slate-700 bg-slate-900 text-slate-300 hover:bg-slate-800 disabled:opacity-30" data-page="${pag.current_page - 1}" ${pag.current_page <= 1 ? 'disabled' : ''}>Prev</button>`;

            const maxPages = 5;
            let start = Math.max(1, pag.current_page - 2);
            let end = Math.min(pag.last_page, start + maxPages - 1);
            if (end - start < maxPages - 1) {
                start = Math.max(1, end - maxPages + 1);
            }

            for (let p = start; p <= end; p++) {
                html += `<button class="mgr-page-btn px-2.5 py-1 rounded border ${p === pag.current_page ? 'bg-indigo-600 text-white border-indigo-500 font-bold' : 'border-slate-700 bg-slate-900 text-slate-300 hover:bg-slate-800'}" data-page="${p}">${p}</button>`;
            }

            html += `<button class="mgr-page-btn px-2 py-1 rounded border border-slate-700 bg-slate-900 text-slate-300 hover:bg-slate-800 disabled:opacity-30" data-page="${pag.current_page + 1}" ${pag.current_page >= pag.last_page ? 'disabled' : ''}>Next</button>`;

            btnContainer.innerHTML = html;

            btnContainer.querySelectorAll('.mgr-page-btn').forEach(b => {
                b.addEventListener('click', () => {
                    const p = parseInt(b.dataset.page, 10);
                    if (p >= 1 && p <= pag.last_page) {
                        managerPage = p;
                        loadManagerData();
                    }
                });
            });
        }

        // Refresh stats across top header
        async function refreshHeaderStats() {
            try {
                const res = await fetch('/biometrics/hrbliz/list?per_page=1', { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (data.stats) {
                    const total = data.stats.total_system;
                    const mapped = data.stats.total_mapped;
                    const unmapped = data.stats.total_unmapped;

                    document.getElementById('statTotal').textContent = total.toLocaleString();
                    document.getElementById('statMapped').textContent = mapped.toLocaleString();
                    document.getElementById('statUnmapped').textContent = unmapped.toLocaleString();
                    document.getElementById('statMappedPct').textContent = total > 0 ? `${Math.round((mapped / total) * 100)}%` : '0%';
                }
            } catch (e) {
                // Non-critical
            }
        }

        // Toast feedback
        let toastTimeout = null;
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toastNotification');
            const icon = document.getElementById('toastIcon');
            const msg = document.getElementById('toastMessage');

            msg.textContent = message;

            toast.classList.remove('bg-emerald-950', 'text-emerald-200', 'border-emerald-600',
                                   'bg-rose-950', 'text-rose-200', 'border-rose-600',
                                   'bg-amber-950', 'text-amber-200', 'border-amber-600',
                                   'bg-blue-950', 'text-blue-200', 'border-blue-600');

            if (type === 'success') {
                toast.classList.add('bg-emerald-950', 'text-emerald-200', 'border-emerald-600');
                icon.className = 'fa-solid fa-circle-check text-emerald-400 text-base';
            } else if (type === 'error') {
                toast.classList.add('bg-rose-950', 'text-rose-200', 'border-rose-600');
                icon.className = 'fa-solid fa-circle-exclamation text-rose-400 text-base';
            } else if (type === 'warning') {
                toast.classList.add('bg-amber-950', 'text-amber-200', 'border-amber-600');
                icon.className = 'fa-solid fa-triangle-exclamation text-amber-400 text-base';
            } else {
                toast.classList.add('bg-blue-950', 'text-blue-200', 'border-blue-600');
                icon.className = 'fa-solid fa-circle-info text-blue-400 text-base';
            }

            toast.classList.remove('translate-y-12', 'opacity-0', 'pointer-events-none');

            clearTimeout(toastTimeout);
            toastTimeout = setTimeout(() => {
                toast.classList.add('translate-y-12', 'opacity-0', 'pointer-events-none');
            }, 4500);
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>
