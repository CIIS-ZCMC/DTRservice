<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Device Log Alert - DTR Service</title>
    <script>
        (function() {
            const theme = localStorage.getItem('alertTheme');
            if (theme === 'dark') {
                // dark is default via :root, no class needed
            } else {
                document.documentElement.classList.add('light');
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
            --bg-input: #1e293b;
            --bg-hover: #1e293b;
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
            --modal-overlay: rgba(0, 0, 0, 0.7);
            --modal-bg: #0f172a;
            --modal-border: #334155;
        }

        html.light {
            --bg-body: #f1f5f9;
            --bg-header: #ffffff;
            --bg-panel: #ffffff;
            --bg-card: #f8fafc;
            --bg-input: #f1f5f9;
            --bg-hover: #f1f5f9;
            --border-color: #e2e8f0;
            --border-subtle: #f1f5f9;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            --scrollbar-track: #f1f5f9;
            --scrollbar-thumb: #cbd5e1;
            --scrollbar-hover: #94a3b8;
            --panel-header-from: #e0f2fe;
            --panel-header-to: #f8fafc;
            --modal-overlay: rgba(0, 0, 0, 0.4);
            --modal-bg: #ffffff;
            --modal-border: #e2e8f0;
        }

        body {
            background: var(--bg-body);
            color: var(--text-primary);
        }

        .scrollbar-thin::-webkit-scrollbar { width: 8px; height: 8px; }
        .scrollbar-thin::-webkit-scrollbar-track { background: var(--scrollbar-track); }
        .scrollbar-thin::-webkit-scrollbar-thumb { background: var(--scrollbar-thumb); border-radius: 4px; }
        .scrollbar-thin::-webkit-scrollbar-thumb:hover { background: var(--scrollbar-hover); }

        .panel-header {
            background: linear-gradient(135deg, var(--panel-header-from) 0%, var(--panel-header-to) 100%);
        }

        .glow-orange {
            box-shadow: 0 0 20px rgba(249, 115, 22, 0.2);
        }

        .cal-day {
            transition: all 0.15s ease;
        }
        .cal-day:hover {
            background: rgba(59, 130, 246, 0.15);
        }
        .cal-day.selected {
            background: rgba(59, 130, 246, 0.3);
            border-color: #3b82f6;
        }
        .cal-day.has-late {
            border-color: rgba(249, 115, 22, 0.5);
        }

        .late-badge {
            background: linear-gradient(135deg, #f97316, #ea580c);
            box-shadow: 0 1px 4px rgba(249, 115, 22, 0.4);
        }

        .file-row-late {
            border-left: 3px solid #f97316;
            background: rgba(249, 115, 22, 0.05);
        }
        .file-row-late:hover {
            background: rgba(249, 115, 22, 0.12);
        }
        .file-row-normal {
            border-left: 3px solid #22c55e;
        }
        .file-row-normal:hover {
            background: rgba(34, 197, 94, 0.08);
        }
        .file-row-clickable {
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .modal-overlay {
            background: var(--modal-overlay);
            backdrop-filter: blur(4px);
        }

        .themed-bg { background: var(--bg-panel); }
        .themed-card { background: var(--bg-card); }
        .themed-input { background: var(--bg-input); border-color: var(--border-color); color: var(--text-primary); }
        .themed-border { border-color: var(--border-color); }
        .themed-border-subtle { border-color: var(--border-subtle); }
        .themed-text-primary { color: var(--text-primary); }
        .themed-text-secondary { color: var(--text-secondary); }
        .themed-text-muted { color: var(--text-muted); }
        .themed-hover:hover { background: var(--bg-hover); }

        .themed-input::placeholder { color: var(--text-muted); }
        .themed-input option { background: var(--bg-panel); color: var(--text-primary); }
    </style>
</head>
<body class="min-h-screen" style="background: var(--bg-body); color: var(--text-primary);">
    <!-- Header -->
    <header class="themed-bg themed-border border-b px-6 py-4">
        <div class="max-w-full mx-auto">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-600 rounded-lg flex items-center justify-center">
                            <i class="fas fa-bell text-white text-lg"></i>
                        </div>
                        <div>
                            <h1 class="text-xl font-bold themed-text-primary">Device Log Alert</h1>
                            
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                  
                    <div class="flex items-center gap-2 themed-card themed-border rounded-lg px-3 py-2">
                        <label class="text-xs themed-text-secondary font-medium">Source:</label>
                        <select id="dataSource" class="themed-input rounded px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="db" selected>Device Logs</option>
                            <option value="file">File Record</option>
                        </select>
                    </div>
                    <button id="themeToggle" class="themed-input rounded-lg p-2 text-sm transition-colors" title="Toggle theme">
                        <i id="themeIcon" class="fas fa-sun text-yellow-400"></i>
                    </button>
                    <button id="scanBtn" class="bg-gradient-to-r from-orange-600 to-red-600 hover:from-orange-700 hover:to-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2 transition-all shadow-lg hover:shadow-orange-500/25">
                        <i class="fas fa-radar"></i> Scan
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="p-6">
        <!-- Loading overlay -->
        <div id="loadingOverlay" class="hidden fixed inset-0 z-50 flex items-center justify-center" style="background: var(--modal-overlay);">
            <div class="text-center">
                <i class="fas fa-spinner fa-spin text-4xl text-orange-500 mb-3"></i>
                <p class="themed-text-secondary">Scanning...</p>
            </div>
        </div>

        <div class="grid grid-cols-12 gap-6">
            <!-- Calendar -->
            <div class="col-span-5">
                <div class="themed-bg rounded-xl border themed-border overflow-hidden glow-orange">
                    <div class="panel-header px-4 py-3 border-b themed-border">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 bg-orange-600 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-calendar-alt text-white"></i>
                                </div>
                                <h2 class="text-sm font-semibold themed-text-primary">Calendar</h2>
                            </div>
                            <div class="flex items-center gap-2">
                                <button id="prevMonth" class="themed-input hover:opacity-80 px-3 py-1 rounded text-sm transition-colors">
                                    <i class="fas fa-chevron-left themed-text-primary"></i>
                                </button>
                                <span id="monthLabel" class="themed-text-primary font-medium text-sm min-w-[140px] text-center"></span>
                                <button id="nextMonth" class="themed-input hover:opacity-80 px-3 py-1 rounded text-sm transition-colors">
                                    <i class="fas fa-chevron-right themed-text-primary"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="p-4">
                        <!-- Day headers -->
                        <div class="grid grid-cols-7 gap-1 mb-2">
                            <div class="text-center text-xs themed-text-muted font-semibold py-1">Sun</div>
                            <div class="text-center text-xs themed-text-muted font-semibold py-1">Mon</div>
                            <div class="text-center text-xs themed-text-muted font-semibold py-1">Tue</div>
                            <div class="text-center text-xs themed-text-muted font-semibold py-1">Wed</div>
                            <div class="text-center text-xs themed-text-muted font-semibold py-1">Thu</div>
                            <div class="text-center text-xs themed-text-muted font-semibold py-1">Fri</div>
                            <div class="text-center text-xs themed-text-muted font-semibold py-1">Sat</div>
                        </div>
                        <!-- Calendar grid -->
                        <div id="calendarGrid" class="grid grid-cols-7 gap-1"></div>
                    </div>
                    <!-- Legend -->
                    <div class="px-4 py-3 border-t themed-border-subtle flex items-center gap-4 text-xs themed-text-secondary">
                        <span class="flex items-center gap-2">
                            <span class="late-badge text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">2</span>
                            Late pull count
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="w-3 h-3 border border-orange-500/50 rounded"></span>
                            Has late data
                        </span>
                    </div>
                </div>
            </div>

            <!-- Detail Panel -->
            <div class="col-span-7">
                <div class="themed-bg rounded-xl border themed-border overflow-hidden">
                    <div class="panel-header px-4 py-3 border-b themed-border">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-file-alt text-white"></i>
                                </div>
                                <div>
                                    <h2 class="text-sm font-semibold themed-text-primary">Date Details</h2>
                                    <span id="detailStatus" class="text-xs themed-text-secondary">Select a date from the calendar</span>
                                </div>
                            </div>
                            <button id="printBtn" onclick="openPrintModal()" class="hidden bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium px-3 py-1.5 rounded-lg flex items-center gap-2 transition-colors">
                                <i class="fas fa-print"></i> Print Device Logs
                            </button>
                        </div>
                    </div>
                    <div id="detailContent" class="p-4 min-h-[400px] max-h-[calc(100vh-220px)] overflow-y-auto scrollbar-thin">
                        <div class="themed-text-muted text-center py-16">
                            <i class="fas fa-hand-pointer text-3xl mb-3 themed-text-muted"></i>
                            <p>Click a date on the calendar to see which files contain logs for that date</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- File List -->
        <div id="fileListSection" class="mt-6">
            <div class="themed-bg rounded-xl border themed-border overflow-hidden">
                <div class="panel-header px-4 py-3 border-b themed-border">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-purple-600 rounded-lg flex items-center justify-center">
                            <i class="fas fa-database text-white"></i>
                        </div>
                        <div>
                            <h2 class="text-sm font-semibold themed-text-primary">Scanned Files</h2>
                            <span id="fileCount" class="text-xs themed-text-secondary">No files scanned yet</span>
                        </div>
                    </div>
                </div>
                <div id="fileList" class="p-4">
                    <div class="themed-text-muted text-center py-8">
                        <p>Click "Scan" to load data</p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        let scanData = null;
        let currentMonth = new Date();
        let selectedDate = null;
        let dataSource = 'db';
        let dbDetailEntries = [];

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDate(dateStr) {
            const d = new Date(dateStr + 'T00:00:00');
            return d.toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
        }

        function renderCalendar() {
            const year = currentMonth.getFullYear();
            const month = currentMonth.getMonth();
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];

            document.getElementById('monthLabel').textContent = `${monthNames[month]} ${year}`;

            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();

            const grid = document.getElementById('calendarGrid');
            grid.innerHTML = '';

            // Empty cells before first day
            for (let i = 0; i < firstDay; i++) {
                const empty = document.createElement('div');
                empty.className = 'h-16 rounded-lg themed-card opacity-30';
                grid.appendChild(empty);
            }

            // Day cells
            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const cell = document.createElement('div');
                cell.className = 'cal-day h-16 rounded-lg border themed-border themed-card cursor-pointer relative flex flex-col items-center justify-center';

                if (selectedDate === dateStr) {
                    cell.classList.add('selected');
                }

                const latePulls = scanData?.late_pulls || {};
                const dateEntries = scanData?.dates?.[dateStr] || [];

                // Day number
                const dayNum = document.createElement('span');
                dayNum.className = 'text-sm font-medium themed-text-secondary';
                dayNum.textContent = day;
                cell.appendChild(dayNum);

                // Late pull badge
                if (latePulls[dateStr]) {
                    cell.classList.add('has-late');
                    const badge = document.createElement('span');
                    badge.className = 'late-badge text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full mt-1';
                    badge.textContent = latePulls[dateStr];
                    cell.appendChild(badge);
                } else if (dateEntries.length > 0 || scanData?.dates?.hasOwnProperty(dateStr)) {
                    // Has data but no late pulls — show green dot or count badge
                    if (dataSource === 'db' && scanData?.dates?.[dateStr]?.count) {
                        const cnt = scanData.dates[dateStr].count;
                        const badge = document.createElement('span');
                        badge.className = 'bg-green-600/80 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full mt-1';
                        badge.textContent = cnt;
                        cell.appendChild(badge);
                    } else {
                        const dot = document.createElement('span');
                        dot.className = 'w-1.5 h-1.5 bg-green-500 rounded-full mt-1';
                        cell.appendChild(dot);
                    }
                }

                cell.addEventListener('click', () => selectDate(dateStr));
                grid.appendChild(cell);
            }
        }

        function selectDate(dateStr) {
            selectedDate = dateStr;
            renderCalendar();
            renderDetails(dateStr);
        }

        function updatePrintBtnVisibility() {
            const printBtn = document.getElementById('printBtn');
            if (dataSource === 'db') {
                printBtn.classList.remove('hidden');
            } else {
                printBtn.classList.add('hidden');
            }
        }

        function renderDetails(dateStr) {
            const detailContent = document.getElementById('detailContent');
            const detailStatus = document.getElementById('detailStatus');

            updatePrintBtnVisibility();

            if (!scanData) {
                detailContent.innerHTML = '<div class="themed-text-muted text-center py-8"><p>Scan first</p></div>';
                return;
            }

            const hasDate = scanData.dates.hasOwnProperty(dateStr);

            if (!hasDate) {
                detailStatus.textContent = `No data for ${dateStr}`;
                detailContent.innerHTML = `
                    <div class="themed-text-muted text-center py-16">
                        <i class="fas fa-calendar-times text-3xl mb-3"></i>
                        <p>No log entries found for <span class="text-orange-400 font-medium">${formatDate(dateStr)}</span></p>
                    </div>`;
                return;
            }

            if (dataSource === 'db') {
                detailStatus.textContent = `${formatDate(dateStr)} — Loading...`;
                detailContent.innerHTML = `<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-2"></i><p class="themed-text-secondary text-sm">Loading entries...</p></div>`;
                fetchDBEntries(dateStr);
            } else {
                const entries = scanData.dates[dateStr] || [];
                renderDetailsFile(dateStr, entries);
            }
        }

        async function fetchDBEntries(dateStr) {
            try {
                const response = await fetch('/logs/alert/date/' + encodeURIComponent(dateStr));
                const data = await response.json();
                const entries = data.entries || [];
                renderDetailsDB(dateStr, entries);
            } catch (error) {
                document.getElementById('detailStatus').textContent = `Error loading ${dateStr}`;
                document.getElementById('detailContent').innerHTML = `<div class="text-red-400 text-center py-8"><i class="fas fa-exclamation-triangle text-2xl mb-2"></i><p>Failed to load: ${escapeHtml(error.message)}</p></div>`;
            }
        }

        function renderDetailsFile(dateStr, entries) {
            const detailContent = document.getElementById('detailContent');
            const detailStatus = document.getElementById('detailStatus');

            const totalEntries = entries.reduce((sum, e) => sum + e.count, 0);
            const lateEntries = entries.filter(e => e.is_late);
            detailStatus.textContent = `${formatDate(dateStr)} — ${entries.length} file(s), ${totalEntries} entries, ${lateEntries.length} late pull(s)`;

            let html = `
                <div class="mb-4 p-3 themed-card rounded-lg border themed-border">
                    <div class="flex items-center gap-2 mb-1">
                        <i class="fas fa-info-circle text-blue-400"></i>
                        <span class="text-sm font-medium themed-text-primary">DTR Date: ${dateStr}</span>
                    </div>
                    <p class="text-xs themed-text-secondary">Found in ${entries.length} file(s) with ${totalEntries} total log entries</p>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs themed-text-muted border-b themed-border">
                            <th class="text-left py-2 px-3 font-semibold">File Name</th>
                            <th class="text-left py-2 px-3 font-semibold">File Date</th>
                            <th class="text-center py-2 px-3 font-semibold">Entries</th>
                            <th class="text-center py-2 px-3 font-semibold">Late By</th>
                            <th class="text-center py-2 px-3 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody>`;

            entries.forEach(entry => {
                const rowClass = entry.is_late ? 'file-row-late' : 'file-row-normal';
                const statusBadge = entry.is_late
                    ? `<span class="bg-orange-600/20 text-orange-400 text-xs px-2 py-1 rounded-full font-medium">LATE PULL</span>`
                    : `<span class="bg-green-600/20 text-green-400 text-xs px-2 py-1 rounded-full font-medium">ON TIME</span>`;
                const lateByText = entry.is_late ? `${entry.late_days} day${entry.late_days !== 1 ? 's' : ''}` : '—';

                html += `
                    <tr class="${rowClass} file-row-clickable border-b themed-border-subtle" onclick="openFileModal('${escapeHtml(entry.filename)}', '${dateStr}', ${entry.is_late}, ${entry.late_days})">
                        <td class="py-3 px-3 font-mono text-xs themed-text-secondary">${escapeHtml(entry.filename)}</td>
                        <td class="py-3 px-3 themed-text-secondary">${entry.file_date}</td>
                        <td class="py-3 px-3 text-center themed-text-primary font-medium">${entry.count}</td>
                        <td class="py-3 px-3 text-center ${entry.is_late ? 'text-orange-400 font-medium' : 'themed-text-muted'}">${lateByText}</td>
                        <td class="py-3 px-3 text-center">
                            <span class="flex items-center justify-center gap-2">
                                ${statusBadge}
                                <i class="fas fa-eye themed-text-muted hover:text-blue-400 text-xs"></i>
                            </span>
                        </td>
                    </tr>`;
            });

            html += '</tbody></table>';
            detailContent.innerHTML = html;
        }

        function renderDetailsDB(dateStr, entries) {
            const detailContent = document.getElementById('detailContent');
            const detailStatus = document.getElementById('detailStatus');

            dbDetailEntries = entries;
            detailStatus.textContent = `${formatDate(dateStr)} — ${entries.length} entries`;

            let html = `
                <div class="mb-4 p-3 themed-card rounded-lg border themed-border">
                    <div class="flex items-center gap-2 mb-1">
                        <i class="fas fa-info-circle text-blue-400"></i>
                        <span class="text-sm font-medium themed-text-primary">DTR Date: ${dateStr}</span>
                    </div>
                    <p class="text-xs themed-text-secondary">${entries.length} log entries from database</p>
                </div>
                <div class="mb-4 grid grid-cols-4 gap-3">
                    <input id="detailFilterBio" type="text" placeholder="Biometric ID" oninput="renderDBTable()"
                        class="themed-input text-xs rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <input id="detailFilterName" type="text" placeholder="Name" oninput="renderDBTable()"
                        class="themed-input text-xs rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <input id="detailFilterTime" type="text" placeholder="Time" oninput="renderDBTable()"
                        class="themed-input text-xs rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <input id="detailFilterDevice" type="text" placeholder="Device" oninput="renderDBTable()"
                        class="themed-input text-xs rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div id="dbTableResults"></div>`;

            detailContent.innerHTML = html;
            renderDBTable();
        }

        function renderDBTable() {
            const resultsDiv = document.getElementById('dbTableResults');
            if (!resultsDiv) return;

            const fBio = (document.getElementById('detailFilterBio')?.value || '').toLowerCase().trim();
            const fName = (document.getElementById('detailFilterName')?.value || '').toLowerCase().trim();
            const fTime = (document.getElementById('detailFilterTime')?.value || '').toLowerCase().trim();
            const fDevice = (document.getElementById('detailFilterDevice')?.value || '').toLowerCase().trim();

            const filtered = dbDetailEntries.filter(e => {
                if (fBio && !e.biometric_id.toLowerCase().includes(fBio)) return false;
                if (fName && !e.name.toLowerCase().includes(fName)) return false;
                if (fTime && !e.dtr_time.toLowerCase().includes(fTime)) return false;
                if (fDevice && !e.device_name.toLowerCase().includes(fDevice)) return false;
                return true;
            });

            let html = `<div class="mb-3 text-xs themed-text-secondary">Showing ${filtered.length} of ${dbDetailEntries.length} entries</div>`;

            if (filtered.length === 0) {
                html += `<div class="themed-text-muted text-center py-8"><i class="fas fa-search text-2xl mb-2"></i><p>No matching entries</p></div>`;
            } else {
                html += `
                    <div class="overflow-x-auto scrollbar-thin">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="themed-text-muted border-b themed-border">
                                    <th class="text-left py-2 px-2 font-semibold">Biometric ID</th>
                                    <th class="text-left py-2 px-2 font-semibold">Name</th>
                                    <th class="text-left py-2 px-2 font-semibold">Time</th>
                                    <th class="text-left py-2 px-2 font-semibold">Type</th>
                                    <th class="text-left py-2 px-2 font-semibold">Device</th>
                                    <th class="text-left py-2 px-2 font-semibold">Synced Time</th>
                                </tr>
                            </thead>
                            <tbody>`;

                filtered.forEach(e => {
                    html += `
                        <tr class="border-b themed-border-subtle themed-hover">
                            <td class="py-2 px-2 font-mono themed-text-secondary">${escapeHtml(e.biometric_id)}</td>
                            <td class="py-2 px-2 themed-text-primary">${escapeHtml(e.name)}</td>
                            <td class="py-2 px-2 font-mono text-blue-500">${escapeHtml(e.dtr_time)}</td>
                            <td class="py-2 px-2 themed-text-muted">${escapeHtml(e.dtr_type)}</td>
                            <td class="py-2 px-2 themed-text-muted">${escapeHtml(e.device_name)}</td>
                            <td class="py-2 px-2 font-mono themed-text-muted">${escapeHtml(e.created_at)}</td>
                        </tr>`;
                });

                html += '</tbody></table></div>';
            }

            resultsDiv.innerHTML = html;
        }

        function renderFileList() {
            const fileList = document.getElementById('fileList');
            const fileCount = document.getElementById('fileCount');

            if (!scanData || scanData.files.length === 0) {
                fileList.innerHTML = '<div class="themed-text-muted text-center py-8"><p>No files found</p></div>';
                fileCount.textContent = 'No files found';
                return;
            }

            fileCount.textContent = `${scanData.files.length} file(s) found`;

            let html = `
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs themed-text-muted border-b themed-border">
                            <th class="text-left py-2 px-3 font-semibold">File Name</th>
                            <th class="text-left py-2 px-3 font-semibold">File Date</th>
                            <th class="text-center py-2 px-3 font-semibold">Entries</th>
                            <th class="text-right py-2 px-3 font-semibold">Size</th>
                        </tr>
                    </thead>
                    <tbody>`;

            scanData.files.forEach(file => {
                html += `
                    <tr class="border-b themed-border-subtle themed-hover transition-colors">
                        <td class="py-3 px-3 font-mono text-xs themed-text-secondary">${escapeHtml(file.filename)}</td>
                        <td class="py-3 px-3 themed-text-secondary">${file.file_date}</td>
                        <td class="py-3 px-3 text-center themed-text-primary font-medium">${file.entries}</td>
                        <td class="py-3 px-3 text-right themed-text-muted text-xs">${file.size}</td>
                    </tr>`;
            });

            html += '</tbody></table>';
            fileList.innerHTML = html;
        }

        async function scanFiles() {
            dataSource = document.getElementById('dataSource').value;
            document.getElementById('loadingOverlay').classList.remove('hidden');
            document.getElementById('scanBtn').disabled = true;

            const url = dataSource === 'db' ? '/logs/alert/scan-db' : '/logs/alert/scan';
            const fileListSection = document.getElementById('fileListSection');

            try {
                const response = await fetch(url);
                scanData = await response.json();

                renderCalendar();
                updatePrintBtnVisibility();

                if (dataSource === 'db') {
                    fileListSection.style.display = 'none';
                } else {
                    fileListSection.style.display = 'block';
                    renderFileList();
                }

                if (selectedDate) {
                    renderDetails(selectedDate);
                }
            } catch (error) {
                alert('Failed to scan: ' + error.message);
            } finally {
                document.getElementById('loadingOverlay').classList.add('hidden');
                document.getElementById('scanBtn').disabled = false;
            }
        }

        // Event listeners
        document.getElementById('scanBtn').addEventListener('click', scanFiles);
        document.getElementById('dataSource').addEventListener('change', scanFiles);
        document.getElementById('prevMonth').addEventListener('click', () => {
            currentMonth.setMonth(currentMonth.getMonth() - 1);
            renderCalendar();
        });
        document.getElementById('nextMonth').addEventListener('click', () => {
            currentMonth.setMonth(currentMonth.getMonth() + 1);
            renderCalendar();
        });

        // --- File Modal ---
        let modalEntries = [];
        let selectedModalEntries = new Map();
        let isFromFileRecord = false;

        function getEntryKey(e) {
            return `${e.biometric_id}|${e.dtr_date}|${e.dtr_time}|${e.dtr_type}`;
        }

        function toggleEntrySelection(key) {
            const entry = modalEntries.find(e => getEntryKey(e) === key);
            if (!entry) return;

            if (selectedModalEntries.has(key)) {
                selectedModalEntries.delete(key);
            } else {
                selectedModalEntries.set(key, entry);
            }
            updateSelectedCount();
            renderModalEntries();
        }

        function toggleSelectAllModalEntries(headerCb) {
            const isChecked = headerCb.checked;
            const filtered = getFilteredModalEntries();

            filtered.forEach(e => {
                const key = getEntryKey(e);
                if (isChecked) {
                    selectedModalEntries.set(key, e);
                } else {
                    selectedModalEntries.delete(key);
                }
            });

            renderModalEntries();
        }

        function updateSelectedCount() {
            const btn = document.getElementById('btnGenerateDeviceLogs');
            const countSpan = document.getElementById('selectedCount');
            const count = selectedModalEntries.size;

            if (countSpan) countSpan.textContent = count;
            if (btn) btn.disabled = (count === 0);

            const selectAllCb = document.getElementById('selectAllModalEntries');
            if (selectAllCb) {
                const filtered = getFilteredModalEntries();
                if (filtered.length > 0) {
                    selectAllCb.checked = filtered.every(e => selectedModalEntries.has(getEntryKey(e)));
                } else {
                    selectAllCb.checked = false;
                }
            }
        }

        function getFilteredModalEntries() {
            const fBio = (document.getElementById('filterBio')?.value || '').toLowerCase().trim();
            const fName = (document.getElementById('filterName')?.value || '').toLowerCase().trim();
            const fTime = (document.getElementById('filterTime')?.value || '').toLowerCase().trim();
            const fDevice = (document.getElementById('filterDevice')?.value || '').toLowerCase().trim();

            return modalEntries.filter(e => {
                if (fBio && !e.biometric_id.toLowerCase().includes(fBio)) return false;
                if (fName && !e.name.toLowerCase().includes(fName)) return false;
                if (fTime && !e.dtr_time.toLowerCase().includes(fTime)) return false;
                if (fDevice && !e.device_name.toLowerCase().includes(fDevice)) return false;
                return true;
            });
        }

        async function openDBModal(dtrDate) {
            isFromFileRecord = false;
            selectedModalEntries.clear();
            document.getElementById('btnGenerateDeviceLogs')?.classList.add('hidden');

            const modal = document.getElementById('fileModal');
            const modalTitle = document.getElementById('fileModalTitle');
            const modalMeta = document.getElementById('fileModalMeta');

            modalTitle.innerHTML = `<i class="fas fa-database text-blue-400 mr-2"></i>Device Logs — ${dtrDate}`;
            modalMeta.innerHTML = `<span class="themed-text-secondary">Source: <span class="themed-text-primary font-medium">Database</span></span>`;
            document.getElementById('modalResults').innerHTML = `<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-2"></i><p class="themed-text-secondary text-sm">Loading entries...</p></div>`;
            document.getElementById('modalFilters').style.display = 'none';

            modal.classList.remove('hidden');
            modal.classList.add('flex');

            try {
                const response = await fetch('/logs/alert/date/' + encodeURIComponent(dtrDate));
                const data = await response.json();

                if (data.error) {
                    document.getElementById('modalFilters').style.display = 'none';
                    document.getElementById('modalResults').innerHTML = `<div class="text-red-400 text-center py-8"><i class="fas fa-exclamation-triangle text-2xl mb-2"></i><p>${escapeHtml(data.error)}</p></div>`;
                    return;
                }

                modalEntries = data.entries || [];

                if (modalEntries.length === 0) {
                    document.getElementById('modalFilters').style.display = 'none';
                    document.getElementById('modalResults').innerHTML = `<div class="text-slate-500 text-center py-8"><i class="fas fa-inbox text-2xl mb-2"></i><p>No entries found for ${dtrDate}</p></div>`;
                    return;
                }

                // Clear filters and show them
                document.getElementById('filterBio').value = '';
                document.getElementById('filterName').value = '';
                document.getElementById('filterTime').value = '';
                document.getElementById('filterDevice').value = '';
                document.getElementById('modalFilters').style.display = 'grid';

                renderModalEntries();
            } catch (error) {
                document.getElementById('modalFilters').style.display = 'none';
                document.getElementById('modalResults').innerHTML = `<div class="text-red-400 text-center py-8"><i class="fas fa-exclamation-triangle text-2xl mb-2"></i><p>Failed to load: ${escapeHtml(error.message)}</p></div>`;
            }
        }

        async function openFileModal(filename, dtrDate, isLate, lateDays) {
            isFromFileRecord = true;
            selectedModalEntries.clear();
            document.getElementById('btnGenerateDeviceLogs')?.classList.remove('hidden');

            const modal = document.getElementById('fileModal');
            const modalTitle = document.getElementById('fileModalTitle');
            const modalMeta = document.getElementById('fileModalMeta');
            const modalBody = document.getElementById('fileModalBody');

            const statusText = isLate
                ? `<span class="bg-orange-600/20 text-orange-400 text-xs px-2 py-1 rounded-full font-medium">LATE PULL</span> <span class="text-orange-400 text-xs ml-1">${lateDays} day${lateDays !== 1 ? 's' : ''} late</span>`
                : `<span class="bg-green-600/20 text-green-400 text-xs px-2 py-1 rounded-full font-medium">ON TIME</span>`;

            modalTitle.innerHTML = `<i class="fas fa-file-alt text-blue-400 mr-2"></i>${escapeHtml(filename)}`;
            modalMeta.innerHTML = `<span class="themed-text-secondary">DTR Date: <span class="themed-text-primary font-medium">${dtrDate}</span></span> <span class="mx-2 themed-text-muted">|</span> ${statusText}`;
            document.getElementById('modalResults').innerHTML = `<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-2"></i><p class="themed-text-secondary text-sm">Loading file contents...</p></div>`;
            document.getElementById('modalFilters').style.display = 'none';

            modal.classList.remove('hidden');
            modal.classList.add('flex');

            try {
                const response = await fetch('/logs/alert/file/' + encodeURIComponent(filename));
                const data = await response.json();

                if (data.error) {
                    document.getElementById('modalFilters').style.display = 'none';
                    document.getElementById('modalResults').innerHTML = `<div class="text-red-400 text-center py-8"><i class="fas fa-exclamation-triangle text-2xl mb-2"></i><p>${escapeHtml(data.error)}</p></div>`;
                    return;
                }

                modalEntries = data.entries.filter(e => e.dtr_date === dtrDate);

                if (modalEntries.length === 0) {
                    document.getElementById('modalFilters').style.display = 'none';
                    document.getElementById('modalResults').innerHTML = `<div class="text-slate-500 text-center py-8"><i class="fas fa-inbox text-2xl mb-2"></i><p>No entries found for ${dtrDate}</p></div>`;
                    return;
                }

                // Clear filters and show them
                document.getElementById('filterBio').value = '';
                document.getElementById('filterName').value = '';
                document.getElementById('filterTime').value = '';
                document.getElementById('filterDevice').value = '';
                document.getElementById('modalFilters').style.display = 'grid';

                renderModalEntries();
            } catch (error) {
                document.getElementById('modalFilters').style.display = 'none';
                document.getElementById('modalResults').innerHTML = `<div class="text-red-400 text-center py-8"><i class="fas fa-exclamation-triangle text-2xl mb-2"></i><p>Failed to load: ${escapeHtml(error.message)}</p></div>`;
            }
        }

        function renderModalEntries() {
            const resultsDiv = document.getElementById('modalResults');
            const filtered = getFilteredModalEntries();

            updateSelectedCount();

            let html = `<div class="mb-3 flex items-center justify-between text-xs themed-text-secondary">
                <span>Showing ${filtered.length} of ${modalEntries.length} entries</span>
                ${isFromFileRecord && selectedModalEntries.size > 0 ? `<span class="text-green-500 font-medium">${selectedModalEntries.size} record(s) selected</span>` : ''}
            </div>`;

            if (filtered.length === 0) {
                html += `<div class="themed-text-muted text-center py-8"><i class="fas fa-search text-2xl mb-2"></i><p>No matching entries</p></div>`;
            } else {
                const allFilteredChecked = isFromFileRecord && filtered.length > 0 && filtered.every(e => selectedModalEntries.has(getEntryKey(e)));

                html += `
                    <div class="overflow-x-auto scrollbar-thin">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="themed-text-muted border-b themed-border">
                                    ${isFromFileRecord ? `
                                    <th class="w-10 text-center py-2 px-2">
                                        <input type="checkbox" id="selectAllModalEntries" onchange="toggleSelectAllModalEntries(this)"
                                            ${allFilteredChecked ? 'checked' : ''} class="rounded border-slate-600 text-green-600 focus:ring-green-500 cursor-pointer">
                                    </th>` : ''}
                                    <th class="text-left py-2 px-2 font-semibold">Biometric ID</th>
                                    <th class="text-left py-2 px-2 font-semibold">Name</th>
                                    <th class="text-left py-2 px-2 font-semibold">DTR Date</th>
                                    <th class="text-left py-2 px-2 font-semibold">Time</th>
                                    <th class="text-left py-2 px-2 font-semibold">Type</th>
                                    <th class="text-left py-2 px-2 font-semibold">Device</th>
                                </tr>
                            </thead>
                            <tbody>`;

                filtered.forEach(e => {
                    const key = getEntryKey(e);
                    const isChecked = selectedModalEntries.has(key);
                    html += `
                        <tr class="border-b themed-border-subtle themed-hover ${isChecked ? 'bg-green-950/20' : ''}">
                            ${isFromFileRecord ? `
                            <td class="text-center py-2 px-2">
                                <input type="checkbox" class="entry-checkbox rounded border-slate-600 text-green-600 focus:ring-green-500 cursor-pointer"
                                    onchange="toggleEntrySelection('${escapeHtml(key)}')" ${isChecked ? 'checked' : ''}>
                            </td>` : ''}
                            <td class="py-2 px-2 font-mono themed-text-secondary">${escapeHtml(e.biometric_id)}</td>
                            <td class="py-2 px-2 themed-text-primary">${escapeHtml(e.name)}</td>
                            <td class="py-2 px-2 themed-text-secondary">${escapeHtml(e.dtr_date)}</td>
                            <td class="py-2 px-2 font-mono text-blue-500">${escapeHtml(e.dtr_time)}</td>
                            <td class="py-2 px-2 themed-text-muted">${escapeHtml(e.dtr_type)}</td>
                            <td class="py-2 px-2 themed-text-muted">${escapeHtml(e.device_name)}</td>
                        </tr>`;
                });

                html += '</tbody></table></div>';
            }

            resultsDiv.innerHTML = html;
        }

        async function generateDeviceLogs() {
            if (selectedModalEntries.size === 0) {
                alert('Please select at least one record.');
                return;
            }

            const btn = document.getElementById('btnGenerateDeviceLogs');
            btn.disabled = true;
            btn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Generating...`;

            const entries = Array.from(selectedModalEntries.values());

            try {
                const response = await fetch('/logs/alert/generate-device-logs', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                    },
                    body: JSON.stringify({ entries })
                });

                const data = await response.json();

                if (data.success) {
                    showNotification(data.message, 'success');
                    selectedModalEntries.clear();
                    renderModalEntries();
                    if (dataSource === 'db') {
                        scanFiles();
                    }
                } else {
                    showNotification(data.error || 'Failed to generate device logs', 'error');
                }
            } catch (error) {
                showNotification('Error generating device logs: ' + error.message, 'error');
            } finally {
                updateSelectedCount();
                btn.innerHTML = `<i class="fas fa-database"></i> Generate Device Logs (<span id="selectedCount">${selectedModalEntries.size}</span>)`;
            }
        }

        function showNotification(message, type = 'success') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `fixed bottom-5 right-5 z-50 px-4 py-3 rounded-lg shadow-2xl text-xs font-medium text-white flex items-center gap-3 border transition-all duration-300 transform translate-y-0 opacity-100 ${
                type === 'success' ? 'bg-emerald-600 border-emerald-500' : 'bg-red-600 border-red-500'
            }`;
            alertDiv.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'} text-base"></i>
                <span>${escapeHtml(message)}</span>
            `;
            document.body.appendChild(alertDiv);
            setTimeout(() => {
                alertDiv.classList.add('opacity-0', 'translate-y-2');
                setTimeout(() => alertDiv.remove(), 300);
            }, 4500);
        }

        function closeFileModal() {
            const modal = document.getElementById('fileModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Initial render
        renderCalendar();
        scanFiles();
    </script>

    <!-- File Modal -->
    <div id="fileModal" class="hidden fixed inset-0 z-50 modal-overlay items-center justify-center p-4">
        <div class="themed-bg rounded-xl border themed-border shadow-2xl w-full max-w-4xl max-h-[85vh] flex flex-col">
            <div class="panel-header px-5 py-4 border-b themed-border flex items-center justify-between">
                <div>
                    <h3 id="fileModalTitle" class="text-sm font-semibold themed-text-primary"></h3>
                    <div id="fileModalMeta" class="mt-1 text-xs"></div>
                </div>
                <div class="flex items-center gap-3">
                    <button id="btnGenerateDeviceLogs" onclick="generateDeviceLogs()" disabled class="hidden bg-gradient-to-r from-emerald-600 to-green-600 hover:from-emerald-700 hover:to-green-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-xs font-medium px-3.5 py-2 rounded-lg flex items-center gap-2 transition-all shadow-md">
                        <i class="fas fa-database"></i> Generate Device Logs (<span id="selectedCount">0</span>)
                    </button>
                    <button id="fileModalClose" class="themed-text-secondary hover:themed-text-primary themed-hover p-2 rounded-lg transition-colors">
                        <i class="fas fa-times text-lg"></i>
                    </button>
                </div>
            </div>
            <div id="fileModalBody" class="flex-1 overflow-y-auto scrollbar-thin p-5">
                <div id="modalFilters" class="mb-4 grid grid-cols-4 gap-3" style="display:none">
                    <input id="filterBio" type="text" placeholder="Biometric ID" oninput="renderModalEntries()"
                        class="themed-input text-xs rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <input id="filterName" type="text" placeholder="Name" oninput="renderModalEntries()"
                        class="themed-input text-xs rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <input id="filterTime" type="text" placeholder="Time" oninput="renderModalEntries()"
                        class="themed-input text-xs rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <input id="filterDevice" type="text" placeholder="Device" oninput="renderModalEntries()"
                        class="themed-input text-xs rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div id="modalResults"></div>
            </div>
        </div>
    </div>

    <!-- Print Modal -->
    <div id="printModal" class="hidden fixed inset-0 z-50 modal-overlay items-center justify-center p-4">
        <div class="themed-bg rounded-xl border themed-border shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col">
            <div class="panel-header px-5 py-4 border-b themed-border flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold themed-text-primary"><i class="fas fa-print text-blue-400 mr-2"></i>Print Device Logs</h3>
                    <div id="printModalMeta" class="mt-1 text-xs themed-text-secondary">Select employee and multiple dates to print device logs</div>
                </div>
                <button id="printModalClose" class="themed-text-secondary hover:themed-text-primary themed-hover p-2 rounded-lg transition-colors">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto scrollbar-thin p-5 space-y-4">
                <!-- Employee Selection -->
                <div>
                    <label class="text-xs themed-text-secondary font-medium block mb-1">Select Employee (required)</label>
                    <div class="relative">
                        <input id="printEmpSearch" type="text" placeholder="Search by name or biometric ID..."
                            class="themed-input text-xs rounded-lg px-3 py-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            onfocus="searchEmployees()" oninput="searchEmployees()" autocomplete="off">
                        <div id="printEmpDropdown" class="hidden absolute left-0 right-0 mt-1 themed-bg themed-border border rounded-lg shadow-lg max-h-60 overflow-y-auto z-20"></div>
                    </div>
                    <div id="printEmpSelected" class="hidden mt-2 p-2.5 themed-card themed-border border rounded-lg text-xs space-y-2">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="w-6 h-6 rounded-full bg-blue-600/20 text-blue-400 flex items-center justify-center font-bold text-[10px]"><i class="fas fa-user"></i></span>
                                <span id="printEmpLabel" class="themed-text-primary font-medium"></span>
                            </div>
                            <button onclick="clearSelectedEmployee()" class="themed-text-muted hover:text-red-400 text-xs px-1.5 py-0.5 rounded transition-colors"><i class="fas fa-times"></i> Clear</button>
                        </div>
                        <div class="pt-2 border-t themed-border-subtle flex items-center justify-between gap-2">
                            <span class="text-[11px] themed-text-secondary">Fetch attendance events (e.g. Flag Ceremony) for employee</span>
                            <button type="button" id="btnFetchAttendanceLogs" onclick="openAttendanceLogsModal()" class="bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-medium px-2.5 py-1.5 rounded-lg flex items-center gap-1.5 transition-colors shadow-sm shrink-0">
                                <i class="fas fa-clipboard-list text-xs"></i> fetch from Attendance Logs
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Date Selection Section (Multiple Dates) -->
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-xs themed-text-secondary font-medium block">Select Dates (multiple dates)</label>
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="useCalendarDate()" class="text-[11px] text-blue-400 hover:underline"><i class="fas fa-calendar-day mr-1"></i>Use Calendar Date</button>
                            <span class="themed-text-muted text-[11px]">•</span>
                            <button type="button" onclick="clearAllPrintDates()" class="text-[11px] text-red-400 hover:underline">Clear Dates</button>
                        </div>
                    </div>

                    <!-- Range & Custom Date Add -->
                    <div class="grid grid-cols-12 gap-3 mb-2">
                        <div class="col-span-5">
                            <label class="text-[10px] themed-text-muted block mb-1">From Date</label>
                            <input id="printStartDate" type="date" class="themed-input text-xs rounded-lg px-2.5 py-1.5 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="col-span-5">
                            <label class="text-[10px] themed-text-muted block mb-1">To Date</label>
                            <input id="printEndDate" type="date" class="themed-input text-xs rounded-lg px-2.5 py-1.5 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="col-span-2 flex items-end">
                            <button type="button" onclick="addPrintDates()" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-xs py-1.5 rounded-lg font-medium transition-colors flex items-center justify-center gap-1" title="Add Selected Date(s)">
                                <i class="fas fa-plus"></i> Add Date
                            </button>
                        </div>
                    </div>

                    <!-- Selected Date Chips -->
                    <div id="printDateChips" class="flex flex-wrap gap-1.5 min-h-[32px] p-2 themed-card themed-border border rounded-lg text-xs">
                        <span class="themed-text-muted text-xs italic">No dates selected</span>
                    </div>
                </div>

                <!-- Preview Area -->
                <div>
                    <label class="text-xs themed-text-secondary font-medium block mb-1">Log Preview</label>
                    <div id="printPreview" class="themed-card themed-border border rounded-lg p-4 themed-text-muted text-center">
                        <p>Select an employee and date(s) to preview device logs</p>
                    </div>
                </div>
            </div>
            <div class="px-5 py-4 border-t themed-border flex justify-between items-center">
                <span id="printSummaryText" class="text-xs themed-text-secondary"></span>
                <div class="flex gap-3">
                    <button onclick="closePrintModal()" class="themed-input text-xs font-medium px-4 py-2 rounded-lg transition-colors">Cancel</button>
                    <button id="printSubmitBtn" onclick="doPrint()" disabled class="bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-xs font-medium px-4 py-2 rounded-lg flex items-center gap-2 transition-colors">
                        <i class="fas fa-print"></i> Print Device Logs
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance Logs Modal -->
    <div id="attendanceLogsModal" class="hidden fixed inset-0 z-50 modal-overlay items-center justify-center p-4">
        <div class="themed-bg rounded-xl border themed-border shadow-2xl w-full max-w-3xl max-h-[85vh] flex flex-col">
            <div class="panel-header px-5 py-4 border-b themed-border flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold themed-text-primary flex items-center gap-2">
                        <i class="fas fa-clipboard-check text-emerald-500"></i>
                        Fetch Attendance Logs
                        <span class="bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-[10px] px-2 py-0.5 rounded-full font-mono">Via Attendance LOGs</span>
                    </h3>
                    <div id="attModalMeta" class="mt-1 text-xs themed-text-secondary">Select specific attendance logs to preview and print using device logs printer</div>
                </div>
                <button onclick="closeAttendanceLogsModal()" class="themed-text-secondary hover:themed-text-primary themed-hover p-2 rounded-lg transition-colors">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto scrollbar-thin p-5 space-y-3">
                <div id="attModalEmployeeInfo" class="p-3 themed-card themed-border border rounded-lg text-xs flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-user-check text-emerald-400 text-sm"></i>
                        <span id="attModalEmpName" class="font-semibold themed-text-primary"></span>
                    </div>
                    <span id="attModalEmpBio" class="font-mono text-blue-400 font-medium"></span>
                </div>

                <!-- Filter Controls -->
                <div class="grid grid-cols-12 gap-2.5 p-3 themed-card themed-border border rounded-lg">
                    <div class="col-span-5">
                        <label class="text-[10px] themed-text-muted block mb-1">Search Event / Title / Area</label>
                        <div class="relative">
                            <input id="attFilterSearch" type="text" placeholder="Search Flag Ceremony, area..."
                                oninput="filterAttendanceLogs()"
                                class="themed-input text-xs rounded-lg px-2.5 py-1.5 w-full focus:outline-none focus:ring-2 focus:ring-emerald-500 pl-7">
                            <i class="fas fa-search absolute left-2.5 top-2 text-xs themed-text-muted"></i>
                        </div>
                    </div>
                    <div class="col-span-3">
                        <label class="text-[10px] themed-text-muted block mb-1">From Date</label>
                        <input id="attFilterStartDate" type="date" onchange="filterAttendanceLogs()"
                            class="themed-input text-xs rounded-lg px-2.5 py-1.5 w-full focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </div>
                    <div class="col-span-3">
                        <label class="text-[10px] themed-text-muted block mb-1">To Date</label>
                        <input id="attFilterEndDate" type="date" onchange="filterAttendanceLogs()"
                            class="themed-input text-xs rounded-lg px-2.5 py-1.5 w-full focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </div>
                    <div class="col-span-1 flex items-end">
                        <button type="button" onclick="resetAttendanceFilters()" title="Reset Filters"
                            class="w-full themed-input hover:text-red-400 text-xs py-1.5 rounded-lg font-medium transition-colors flex items-center justify-center">
                            <i class="fas fa-undo"></i>
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-1">
                    <span class="text-xs font-medium themed-text-secondary">Specific Attendance Logs (Flag Ceremony / Events)</span>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="selectAllAttendanceLogs(true)" class="text-[11px] text-blue-400 hover:underline">Select All</button>
                        <span class="themed-text-muted text-[11px]">•</span>
                        <button type="button" onclick="selectAllAttendanceLogs(false)" class="text-[11px] text-red-400 hover:underline">Deselect All</button>
                    </div>
                </div>

                <div id="attModalResults" class="min-h-[160px]">
                    <!-- Table rendered dynamically -->
                </div>
            </div>
            <div class="px-5 py-4 border-t themed-border flex justify-between items-center">
                <span id="attSummaryText" class="text-xs themed-text-secondary"></span>
                <div class="flex gap-2">
                    <button onclick="closeAttendanceLogsModal()" class="themed-input text-xs font-medium px-3.5 py-2 rounded-lg transition-colors">Cancel</button>
                    <button id="btnApplyAttLogs" onclick="applyAttendanceLogsToPreview()" disabled class="bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-xs font-medium px-4 py-2 rounded-lg flex items-center gap-1.5 transition-colors shadow-sm">
                        <i class="fas fa-eye"></i> Apply to Print Preview
                    </button>
                    <button id="btnPrintAttLogsDirect" onclick="printAttendanceLogsDirect()" disabled class="bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-xs font-medium px-4 py-2 rounded-lg flex items-center gap-1.5 transition-colors shadow-sm">
                        <i class="fas fa-print"></i> Print (Via Attendance LOGs)
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Theme toggle
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');

        function applyTheme(isLight) {
            document.documentElement.classList.toggle('light', isLight);
            themeIcon.className = isLight ? 'fas fa-moon text-slate-600' : 'fas fa-sun text-yellow-400';
        }

        const savedTheme = localStorage.getItem('alertTheme');
        applyTheme(savedTheme !== 'dark');

        themeToggle.addEventListener('click', () => {
            const isLight = !document.documentElement.classList.contains('light');
            applyTheme(isLight);
            localStorage.setItem('alertTheme', isLight ? 'light' : 'dark');
        });

        document.getElementById('fileModalClose').addEventListener('click', closeFileModal);
        document.getElementById('fileModal').addEventListener('click', (e) => {
            if (e.target.id === 'fileModal') closeFileModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { closeFileModal(); closePrintModal(); }
        });

        // --- Print Modal Logic ---
        let selectedEmployee = null;
        let selectedPrintDates = new Set(); // Stores YYYY-MM-DD strings
        let isViaAttendanceMode = false;
        let selectedAttendanceInfoIds = new Set();
        let cachedAttendanceLogs = [];

        function openPrintModal() {
            const modal = document.getElementById('printModal');
            document.getElementById('printEmpSearch').value = '';
            document.getElementById('printEmpDropdown').classList.add('hidden');
            document.getElementById('printEmpSelected').classList.add('hidden');
            selectedEmployee = null;
            isViaAttendanceMode = false;
            selectedAttendanceInfoIds.clear();

            selectedPrintDates.clear();

            if (selectedDate) {
                document.getElementById('printStartDate').value = selectedDate;
                document.getElementById('printEndDate').value = selectedDate;
            } else {
                document.getElementById('printStartDate').value = '';
                document.getElementById('printEndDate').value = '';
            }

            renderDateChips();
            updatePrintPreview();
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            searchEmployees();
        }

        function closePrintModal() {
            const modal = document.getElementById('printModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        async function searchEmployees() {
            const q = document.getElementById('printEmpSearch').value.trim();
            const dropdown = document.getElementById('printEmpDropdown');
            dropdown.innerHTML = '<div class="px-3 py-2 text-xs themed-text-muted flex items-center gap-2"><i class="fas fa-spinner fa-spin text-blue-500"></i> Searching employees...</div>';
            dropdown.classList.remove('hidden');

            try {
                const response = await fetch('/logs/alert/employees?q=' + encodeURIComponent(q));
                const employees = await response.json();

                if (!employees || employees.length === 0) {
                    dropdown.innerHTML = '<div class="px-3 py-2 text-xs themed-text-muted">No employees found</div>';
                } else {
                    dropdown.innerHTML = employees.map(emp => `
                        <div onclick="selectEmployee('${escapeHtml(emp.biometric_id)}', '${escapeHtml(emp.name)}', '${escapeHtml(emp.designation || '')}')"
                            class="px-3 py-2 text-xs themed-text-primary themed-hover cursor-pointer border-b themed-border-subtle flex items-center justify-between">
                            <div>
                                <span class="font-mono text-blue-400 font-semibold mr-2">${escapeHtml(emp.biometric_id)}</span>
                                <span class="font-medium">${escapeHtml(emp.name)}</span>
                            </div>
                            ${emp.designation ? `<span class="text-[10px] themed-text-muted">${escapeHtml(emp.designation)}</span>` : ''}
                        </div>`).join('');
                }
            } catch (error) {
                dropdown.innerHTML = `<div class="px-3 py-2 text-xs text-red-400">Error loading employees: ${escapeHtml(error.message)}</div>`;
            }
        }

        function selectEmployee(bioId, name, designation) {
            selectedEmployee = { bioId, name, designation };
            isViaAttendanceMode = false;
            selectedAttendanceInfoIds.clear();
            document.getElementById('printEmpSearch').value = '';
            document.getElementById('printEmpDropdown').classList.add('hidden');
            document.getElementById('printEmpLabel').textContent = `${bioId} — ${name}${designation ? ` (${designation})` : ''}`;
            document.getElementById('printEmpSelected').classList.remove('hidden');
            updatePrintPreview();
        }

        function clearSelectedEmployee() {
            selectedEmployee = null;
            isViaAttendanceMode = false;
            selectedAttendanceInfoIds.clear();
            document.getElementById('printEmpSelected').classList.add('hidden');
            updatePrintPreview();
        }

        function addPrintDates() {
            const start = document.getElementById('printStartDate').value;
            const end = document.getElementById('printEndDate').value;

            if (start && end) {
                let current = new Date(start);
                const last = new Date(end);

                if (current > last) {
                    const temp = current;
                    current = last;
                    last = temp;
                }

                while (current <= last) {
                    const dateStr = current.toISOString().split('T')[0];
                    selectedPrintDates.add(dateStr);
                    current.setDate(current.getDate() + 1);
                }
            } else if (start) {
                selectedPrintDates.add(start);
            } else if (end) {
                selectedPrintDates.add(end);
            }

            renderDateChips();
            updatePrintPreview();
        }

        function useCalendarDate() {
            if (selectedDate) {
                selectedPrintDates.add(selectedDate);
                document.getElementById('printStartDate').value = selectedDate;
                document.getElementById('printEndDate').value = selectedDate;
                renderDateChips();
                updatePrintPreview();
            }
        }

        function removePrintDate(dateStr) {
            selectedPrintDates.delete(dateStr);
            renderDateChips();
            updatePrintPreview();
        }

        function clearAllPrintDates() {
            selectedPrintDates.clear();
            document.getElementById('printStartDate').value = '';
            document.getElementById('printEndDate').value = '';
            renderDateChips();
            updatePrintPreview();
        }

        function renderDateChips() {
            const chipsDiv = document.getElementById('printDateChips');
            const datesArr = Array.from(selectedPrintDates).sort();

            if (datesArr.length === 0) {
                chipsDiv.innerHTML = '<span class="themed-text-muted text-xs italic">No dates selected</span>';
                return;
            }

            chipsDiv.innerHTML = datesArr.map(d => `
                <span class="inline-flex items-center gap-1 bg-blue-600/20 border border-blue-500/30 text-blue-400 text-xs px-2 py-0.5 rounded-full font-mono">
                    ${d}
                    <button type="button" onclick="removePrintDate('${d}')" class="hover:text-red-400 ml-0.5"><i class="fas fa-times text-[10px]"></i></button>
                </span>`).join('');
        }

        async function updatePrintPreview() {
            const previewDiv = document.getElementById('printPreview');
            const printBtn = document.getElementById('printSubmitBtn');
            const summaryText = document.getElementById('printSummaryText');

            if (!selectedEmployee) {
                previewDiv.innerHTML = '<p class="themed-text-muted">Select an employee to preview logs</p>';
                printBtn.disabled = true;
                summaryText.textContent = '';
                return;
            }

            const datesArr = Array.from(selectedPrintDates).sort();
            if (datesArr.length === 0 && !isViaAttendanceMode) {
                previewDiv.innerHTML = '<p class="themed-text-muted">Select one or more dates or fetch from Attendance Logs to preview</p>';
                printBtn.disabled = true;
                summaryText.textContent = '';
                return;
            }

            previewDiv.innerHTML = '<div class="text-center py-6"><i class="fas fa-spinner fa-spin text-xl text-blue-500 mb-2"></i><p class="themed-text-secondary text-xs">Fetching preview...</p></div>';

            try {
                const response = await fetch('/logs/alert/preview-print', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                    },
                    body: JSON.stringify({
                        biometric_id: selectedEmployee.bioId,
                        name: selectedEmployee.name,
                        dates: datesArr,
                        is_via_attendance_logs: isViaAttendanceMode ? 1 : 0,
                        attendance_info_ids: isViaAttendanceMode ? Array.from(selectedAttendanceInfoIds) : []
                    })
                });

                if (!response.ok) {
                    throw new Error(`Server status ${response.status} (${response.statusText || 'Error'})`);
                }

                const data = await response.json();
                const entries = data.entries || [];

                printBtn.disabled = entries.length === 0;

                if (entries.length === 0) {
                    previewDiv.innerHTML = `<p class="themed-text-muted">No log entries found for ${escapeHtml(selectedEmployee.name)}</p>`;
                    summaryText.textContent = `0 log entries found`;
                    return;
                }

                summaryText.innerHTML = `Found ${entries.length} log entry(ies)` + (isViaAttendanceMode ? ` <span class="bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-[10px] px-1.5 py-0.5 rounded font-mono ml-1">Via Attendance LOGs</span>` : '');

                let html = `<div class="overflow-x-auto scrollbar-thin max-h-56">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 themed-bg border-b themed-border">
                            <tr class="themed-text-muted">
                                <th class="text-left py-2 px-2 font-semibold">Date</th>
                                <th class="text-left py-2 px-2 font-semibold">Biometric ID</th>
                                <th class="text-left py-2 px-2 font-semibold">Name</th>
                                <th class="text-left py-2 px-2 font-semibold">Time</th>
                                <th class="text-left py-2 px-2 font-semibold">Source / Device</th>
                            </tr>
                        </thead>
                        <tbody>`;

                entries.forEach(e => {
                    html += `<tr class="border-b themed-border-subtle hover:bg-blue-500/5">
                        <td class="py-1.5 px-2 font-mono text-blue-400 font-medium">${escapeHtml(e.dtr_date)}</td>
                        <td class="py-1.5 px-2 font-mono themed-text-secondary">${escapeHtml(e.biometric_id)}</td>
                        <td class="py-1.5 px-2 themed-text-primary">${escapeHtml(e.name)}</td>
                        <td class="py-1.5 px-2 font-mono text-emerald-500 font-semibold">${escapeHtml(e.dtr_time)}</td>
                        <td class="py-1.5 px-2 themed-text-muted font-medium">
                            ${isViaAttendanceMode ? `<span class="text-emerald-400 font-semibold"><i class="fas fa-clipboard-check mr-1"></i>${escapeHtml(e.device_name)}</span>` : escapeHtml(e.device_name)}
                        </td>
                    </tr>`;
                });

                html += '</tbody></table></div>';
                previewDiv.innerHTML = html;
            } catch (error) {
                previewDiv.innerHTML = `<p class="text-red-400 text-xs">Failed to fetch preview: ${escapeHtml(error.message)}</p>`;
                printBtn.disabled = true;
                summaryText.textContent = '';
            }
        }

        function doPrint() {
            if (!selectedEmployee) return;
            const datesArr = Array.from(selectedPrintDates).sort();
            if (datesArr.length === 0 && !isViaAttendanceMode) return;

            const tabName = 'print_tab_' + Date.now();
            const printTab = window.open('about:blank', tabName);

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/logs/alert/print';
            form.target = tabName;

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            if (csrfToken) {
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_token';
                csrfInput.value = csrfToken;
                form.appendChild(csrfInput);
            }

            const bioInput = document.createElement('input');
            bioInput.type = 'hidden';
            bioInput.name = 'biometric_id';
            bioInput.value = selectedEmployee.bioId;
            form.appendChild(bioInput);

            const nameInput = document.createElement('input');
            nameInput.type = 'hidden';
            nameInput.name = 'name';
            nameInput.value = selectedEmployee.name;
            form.appendChild(nameInput);

            if (isViaAttendanceMode) {
                const viaInput = document.createElement('input');
                viaInput.type = 'hidden';
                viaInput.name = 'is_via_attendance_logs';
                viaInput.value = '1';
                form.appendChild(viaInput);

                selectedAttendanceInfoIds.forEach(id => {
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'attendance_info_ids[]';
                    idInput.value = id;
                    form.appendChild(idInput);
                });
            }

            datesArr.forEach(d => {
                const dateInput = document.createElement('input');
                dateInput.type = 'hidden';
                dateInput.name = 'dates[]';
                dateInput.value = d;
                form.appendChild(dateInput);
            });

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        // --- Attendance Logs Modal & Mode Logic ---
        async function openAttendanceLogsModal() {
            if (!selectedEmployee) {
                alert('Please select an employee first.');
                return;
            }

            document.getElementById('attModalEmpName').textContent = selectedEmployee.name + (selectedEmployee.designation ? ` (${selectedEmployee.designation})` : '');
            document.getElementById('attModalEmpBio').textContent = `Bio ID: ${selectedEmployee.bioId}`;

            document.getElementById('attFilterSearch').value = '';
            document.getElementById('attFilterStartDate').value = '';
            document.getElementById('attFilterEndDate').value = '';

            const resultsDiv = document.getElementById('attModalResults');
            resultsDiv.innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-xl text-emerald-500 mb-2"></i><p class="themed-text-secondary text-xs">Loading attendance logs...</p></div>';

            const modal = document.getElementById('attendanceLogsModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            selectedAttendanceInfoIds.clear();
            updateAttendanceModalButtons();

            try {
                const response = await fetch('/logs/alert/attendance-logs?biometric_id=' + encodeURIComponent(selectedEmployee.bioId));
                const data = await response.json();
                cachedAttendanceLogs = data.entries || [];

                renderAttendanceLogsTable(cachedAttendanceLogs);
            } catch (error) {
                resultsDiv.innerHTML = `<div class="p-4 text-center text-red-400 text-xs">Failed to load attendance logs: ${escapeHtml(error.message)}</div>`;
                document.getElementById('attSummaryText').textContent = 'Error loading logs';
            }
        }

        function closeAttendanceLogsModal() {
            const modal = document.getElementById('attendanceLogsModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function filterAttendanceLogs() {
            const search = (document.getElementById('attFilterSearch').value || '').toLowerCase().trim();
            const startDate = document.getElementById('attFilterStartDate').value;
            const endDate = document.getElementById('attFilterEndDate').value;

            const filtered = cachedAttendanceLogs.filter(item => {
                const title = (item.event_title || '').toLowerCase();
                const area = (item.area || item.areacode || '').toLowerCase();
                const date = item.first_entry_date || (item.first_entry ? item.first_entry.split(' ')[0] : (item.dtr_date || ''));

                const matchesSearch = !search || title.includes(search) || area.includes(search);

                let matchesDate = true;
                if (startDate && endDate) {
                    matchesDate = date >= startDate && date <= endDate;
                } else if (startDate) {
                    matchesDate = date >= startDate;
                } else if (endDate) {
                    matchesDate = date <= endDate;
                }

                return matchesSearch && matchesDate;
            });

            renderAttendanceLogsTable(filtered);
        }

        function resetAttendanceFilters() {
            document.getElementById('attFilterSearch').value = '';
            document.getElementById('attFilterStartDate').value = '';
            document.getElementById('attFilterEndDate').value = '';
            renderAttendanceLogsTable(cachedAttendanceLogs);
        }

        function renderAttendanceLogsTable(entries) {
            const resultsDiv = document.getElementById('attModalResults');
            const summaryText = document.getElementById('attSummaryText');

            if (!entries || entries.length === 0) {
                resultsDiv.innerHTML = `<div class="p-6 text-center themed-card themed-border border rounded-lg themed-text-muted text-xs">No attendance logs found for ${escapeHtml(selectedEmployee.name)}</div>`;
                summaryText.textContent = '0 attendance logs found';
                updateAttendanceModalButtons();
                return;
            }

            summaryText.textContent = `Found ${entries.length} attendance log entry(ies)`;

            let html = `<div class="overflow-x-auto scrollbar-thin max-h-60 border themed-border rounded-lg">
                <table class="w-full text-xs">
                    <thead class="sticky top-0 themed-bg border-b themed-border">
                        <tr class="themed-text-muted">
                            <th class="py-2 px-2 text-center w-8">
                                <input type="checkbox" onchange="selectAllAttendanceLogs(this.checked)" class="rounded border-slate-600 text-emerald-600 focus:ring-emerald-500">
                            </th>
                            <th class="text-left py-2 px-2 font-semibold">Event / Title</th>
                            <th class="text-left py-2 px-2 font-semibold">Tapped Entry Time (First Entry)</th>
                            <th class="text-left py-2 px-2 font-semibold">Area</th>
                        </tr>
                    </thead>
                    <tbody>`;

            entries.forEach(item => {
                const checked = selectedAttendanceInfoIds.has(item.id) ? 'checked' : '';
                html += `<tr class="border-b themed-border-subtle hover:bg-emerald-500/5 transition-colors cursor-pointer" onclick="toggleAttendanceRowClick(event, ${item.id})">
                    <td class="py-2 px-2 text-center" onclick="event.stopPropagation()">
                        <input type="checkbox" value="${item.id}" ${checked} onchange="toggleAttendanceLogSelection(${item.id})" class="rounded border-slate-600 text-emerald-600 focus:ring-emerald-500">
                    </td>
                    <td class="py-2 px-2 font-medium themed-text-primary">
                        <span class="text-emerald-500 font-semibold mr-1.5"><i class="fas fa-calendar-check"></i></span>
                        ${escapeHtml(item.event_title)}
                    </td>
                    <td class="py-2 px-2 font-mono text-emerald-500 font-semibold">${escapeHtml(item.first_entry || (item.dtr_date + ' ' + item.dtr_time))}</td>
                    <td class="py-2 px-2 themed-text-muted">${escapeHtml(item.area || item.areacode || 'N/A')}</td>
                </tr>`;
            });

            html += '</tbody></table></div>';
            resultsDiv.innerHTML = html;
            updateAttendanceModalButtons();
        }

        function toggleAttendanceRowClick(e, id) {
            if (e.target.tagName === 'INPUT') return;
            toggleAttendanceLogSelection(id);
            renderAttendanceLogsTable(cachedAttendanceLogs);
        }

        function toggleAttendanceLogSelection(id) {
            if (selectedAttendanceInfoIds.has(id)) {
                selectedAttendanceInfoIds.delete(id);
            } else {
                selectedAttendanceInfoIds.add(id);
            }
            updateAttendanceModalButtons();
        }

        function selectAllAttendanceLogs(checked) {
            if (checked) {
                cachedAttendanceLogs.forEach(i => selectedAttendanceInfoIds.add(i.id));
            } else {
                selectedAttendanceInfoIds.clear();
            }
            renderAttendanceLogsTable(cachedAttendanceLogs);
        }

        function updateAttendanceModalButtons() {
            const hasSelected = selectedAttendanceInfoIds.size > 0;
            document.getElementById('btnApplyAttLogs').disabled = !hasSelected;
            document.getElementById('btnPrintAttLogsDirect').disabled = !hasSelected;
        }

        function applyAttendanceLogsToPreview() {
            if (selectedAttendanceInfoIds.size === 0) return;

            isViaAttendanceMode = true;

            cachedAttendanceLogs.forEach(item => {
                if (selectedAttendanceInfoIds.has(item.id)) {
                    const date = item.first_entry_date || (item.first_entry ? item.first_entry.split(' ')[0] : (item.open_date || item.dtr_date));
                    if (date) selectedPrintDates.add(date);
                }
            });

            renderDateChips();
            closeAttendanceLogsModal();
            updatePrintPreview();
        }

        function printAttendanceLogsDirect() {
            if (selectedAttendanceInfoIds.size === 0 || !selectedEmployee) return;

            const datesSet = new Set();
            cachedAttendanceLogs.forEach(item => {
                if (selectedAttendanceInfoIds.has(item.id)) {
                    const date = item.first_entry_date || (item.first_entry ? item.first_entry.split(' ')[0] : (item.open_date || item.dtr_date));
                    if (date) datesSet.add(date);
                }
            });
            const datesArr = Array.from(datesSet).sort();

            const tabName = 'print_att_tab_' + Date.now();
            const printTab = window.open('about:blank', tabName);

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/logs/alert/print';
            form.target = tabName;

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            if (csrfToken) {
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_token';
                csrfInput.value = csrfToken;
                form.appendChild(csrfInput);
            }

            const bioInput = document.createElement('input');
            bioInput.type = 'hidden';
            bioInput.name = 'biometric_id';
            bioInput.value = selectedEmployee.bioId;
            form.appendChild(bioInput);

            const nameInput = document.createElement('input');
            nameInput.type = 'hidden';
            nameInput.name = 'name';
            nameInput.value = selectedEmployee.name;
            form.appendChild(nameInput);

            const viaInput = document.createElement('input');
            viaInput.type = 'hidden';
            viaInput.name = 'is_via_attendance_logs';
            viaInput.value = '1';
            form.appendChild(viaInput);

            selectedAttendanceInfoIds.forEach(id => {
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'attendance_info_ids[]';
                idInput.value = id;
                form.appendChild(idInput);
            });

            datesArr.forEach(d => {
                const dateInput = document.createElement('input');
                dateInput.type = 'hidden';
                dateInput.name = 'dates[]';
                dateInput.value = d;
                form.appendChild(dateInput);
            });

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        document.getElementById('printModalClose').addEventListener('click', closePrintModal);
        document.getElementById('printModal').addEventListener('click', (e) => {
            if (e.target.id === 'printModal') closePrintModal();
        });
        document.getElementById('attendanceLogsModal').addEventListener('click', (e) => {
            if (e.target.id === 'attendanceLogsModal') closeAttendanceLogsModal();
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('#printEmpSearch') && !e.target.closest('#printEmpDropdown')) {
                document.getElementById('printEmpDropdown')?.classList.add('hidden');
            }
        });
    </script>
</body>
</html>
