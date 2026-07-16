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
                            <option value="file" selected>File Record</option>
                            <option value="db">Device Logs</option>
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
                                <i class="fas fa-print"></i> Print
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
        let dataSource = 'file';
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

        function renderDetails(dateStr) {
            const detailContent = document.getElementById('detailContent');
            const detailStatus = document.getElementById('detailStatus');

            if (!scanData) {
                detailContent.innerHTML = '<div class="themed-text-muted text-center py-8"><p>Scan first</p></div>';
                document.getElementById('printBtn').classList.add('hidden');
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
                document.getElementById('printBtn').classList.add('hidden');
                return;
            }

            if (dataSource === 'db') {
                detailStatus.textContent = `${formatDate(dateStr)} — Loading...`;
                detailContent.innerHTML = `<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-2"></i><p class="themed-text-secondary text-sm">Loading entries...</p></div>`;
                document.getElementById('printBtn').classList.remove('hidden');
                fetchDBEntries(dateStr);
            } else {
                document.getElementById('printBtn').classList.add('hidden');
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

        async function openDBModal(dtrDate) {
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
            const filtersDiv = document.getElementById('modalFilters');
            const fBio = (document.getElementById('filterBio')?.value || '').toLowerCase().trim();
            const fName = (document.getElementById('filterName')?.value || '').toLowerCase().trim();
            const fTime = (document.getElementById('filterTime')?.value || '').toLowerCase().trim();
            const fDevice = (document.getElementById('filterDevice')?.value || '').toLowerCase().trim();

            const filtered = modalEntries.filter(e => {
                if (fBio && !e.biometric_id.toLowerCase().includes(fBio)) return false;
                if (fName && !e.name.toLowerCase().includes(fName)) return false;
                if (fTime && !e.dtr_time.toLowerCase().includes(fTime)) return false;
                if (fDevice && !e.device_name.toLowerCase().includes(fDevice)) return false;
                return true;
            });

            let html = `<div class="mb-3 text-xs themed-text-secondary">Showing ${filtered.length} of ${modalEntries.length} entries</div>`;

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
                                    <th class="text-left py-2 px-2 font-semibold">DTR Date</th>
                                    <th class="text-left py-2 px-2 font-semibold">Time</th>
                                    <th class="text-left py-2 px-2 font-semibold">Type</th>
                                    <th class="text-left py-2 px-2 font-semibold">Device</th>
                                </tr>
                            </thead>
                            <tbody>`;

                filtered.forEach(e => {
                    html += `
                        <tr class="border-b themed-border-subtle themed-hover">
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
                <button id="fileModalClose" class="themed-text-secondary hover:themed-text-primary themed-hover p-2 rounded-lg transition-colors">
                    <i class="fas fa-times text-lg"></i>
                </button>
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
        <div class="themed-bg rounded-xl border themed-border shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col">
            <div class="panel-header px-5 py-4 border-b themed-border flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold themed-text-primary"><i class="fas fa-print text-blue-400 mr-2"></i>Print Device Logs</h3>
                    <div id="printModalMeta" class="mt-1 text-xs themed-text-secondary"></div>
                </div>
                <button id="printModalClose" class="themed-text-secondary hover:themed-text-primary themed-hover p-2 rounded-lg transition-colors">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto scrollbar-thin p-5">
                <div class="mb-4">
                    <label class="text-xs themed-text-secondary font-medium block mb-1">Search Employee (required)</label>
                    <div class="relative">
                        <input id="printEmpSearch" type="text" placeholder="Type name or biometric ID..."
                            class="themed-input text-xs rounded-lg px-3 py-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            oninput="searchEmployees()" autocomplete="off">
                        <div id="printEmpDropdown" class="hidden absolute left-0 right-0 mt-1 themed-bg themed-border border rounded-lg shadow-lg max-h-60 overflow-y-auto z-10"></div>
                    </div>
                    <div id="printEmpSelected" class="hidden mt-2 p-2 themed-card themed-border border rounded-lg text-xs flex items-center justify-between">
                        <span id="printEmpLabel" class="themed-text-primary"></span>
                        <button onclick="clearSelectedEmployee()" class="themed-text-muted hover:text-red-400 text-xs"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div id="printPreview" class="themed-text-muted text-center py-8">
                    <p>Select an employee to preview logs</p>
                </div>
            </div>
            <div class="px-5 py-4 border-t themed-border flex justify-end gap-3">
                <button onclick="closePrintModal()" class="themed-input text-xs font-medium px-4 py-2 rounded-lg transition-colors">Cancel</button>
                <button id="printSubmitBtn" onclick="doPrint()" disabled class="bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-xs font-medium px-4 py-2 rounded-lg flex items-center gap-2 transition-colors">
                    <i class="fas fa-print"></i> Print
                </button>
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

        // --- Print Modal ---
        let selectedEmployee = null;

        function openPrintModal() {
            if (!selectedDate) return;
            const modal = document.getElementById('printModal');
            document.getElementById('printModalMeta').textContent = `Date: ${selectedDate} — ${dbDetailEntries.length} entries available`;
            document.getElementById('printEmpSearch').value = '';
            document.getElementById('printEmpDropdown').classList.add('hidden');
            document.getElementById('printEmpSelected').classList.add('hidden');
            selectedEmployee = null;
            updatePrintPreview();
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closePrintModal() {
            const modal = document.getElementById('printModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function searchEmployees() {
            const q = document.getElementById('printEmpSearch').value.trim().toLowerCase();
            const dropdown = document.getElementById('printEmpDropdown');

            if (q.length < 1) {
                dropdown.classList.add('hidden');
                return;
            }

            const seen = new Set();
            const unique = [];
            for (const e of dbDetailEntries) {
                const key = e.biometric_id;
                if (!seen.has(key)) {
                    seen.add(key);
                    unique.push({ biometric_id: e.biometric_id, name: e.name });
                }
            }

            const filtered = unique.filter(emp =>
                emp.biometric_id.toLowerCase().includes(q) ||
                emp.name.toLowerCase().includes(q)
            );

            if (filtered.length === 0) {
                dropdown.innerHTML = '<div class="px-3 py-2 text-xs themed-text-muted">No employees found</div>';
            } else {
                dropdown.innerHTML = filtered.map(emp => `
                    <div onclick="selectEmployee('${emp.biometric_id}', '${escapeHtml(emp.name)}')"
                        class="px-3 py-2 text-xs themed-text-primary themed-hover cursor-pointer border-b themed-border-subtle">
                        <span class="font-mono themed-text-secondary">${emp.biometric_id}</span>
                        &mdash; ${escapeHtml(emp.name)}
                    </div>`).join('');
            }
            dropdown.classList.remove('hidden');
        }

        function selectEmployee(bioId, name) {
            selectedEmployee = { bioId, name };
            document.getElementById('printEmpSearch').value = '';
            document.getElementById('printEmpDropdown').classList.add('hidden');
            document.getElementById('printEmpLabel').textContent = `${bioId} — ${name}`;
            document.getElementById('printEmpSelected').classList.remove('hidden');
            updatePrintPreview();
        }

        function clearSelectedEmployee() {
            selectedEmployee = null;
            document.getElementById('printEmpSelected').classList.add('hidden');
            updatePrintPreview();
        }

        document.addEventListener('click', (e) => {
            if (!e.target.closest('#printEmpSearch') && !e.target.closest('#printEmpDropdown')) {
                document.getElementById('printEmpDropdown')?.classList.add('hidden');
            }
        });

        function updatePrintPreview() {
            const previewDiv = document.getElementById('printPreview');
            const printBtn = document.getElementById('printSubmitBtn');

            if (!selectedEmployee) {
                previewDiv.innerHTML = '<p class="themed-text-muted">Select an employee to preview logs</p>';
                printBtn.disabled = true;
                return;
            }

            const bio = selectedEmployee.bioId.toLowerCase();
            const name = selectedEmployee.name.toLowerCase();
            const filtered = dbDetailEntries.filter(e =>
                e.biometric_id.toLowerCase().includes(bio) &&
                e.name.toLowerCase().includes(name)
            );

            printBtn.disabled = filtered.length === 0;

            if (filtered.length === 0) {
                previewDiv.innerHTML = '<p class="themed-text-muted">No matching log entries for this employee on this date</p>';
                return;
            }

            let html = `<div class="mb-3 text-xs themed-text-secondary">Showing ${filtered.length} of ${dbDetailEntries.length} entries</div>
                <div class="overflow-x-auto scrollbar-thin">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="themed-text-muted border-b themed-border">
                                <th class="text-left py-2 px-2 font-semibold">Biometric ID</th>
                                <th class="text-left py-2 px-2 font-semibold">Name</th>
                                <th class="text-left py-2 px-2 font-semibold">Time</th>
                                <th class="text-left py-2 px-2 font-semibold">Device</th>
                            </tr>
                        </thead>
                        <tbody>`;

            filtered.slice(0, 20).forEach(e => {
                html += `<tr class="border-b themed-border-subtle">
                    <td class="py-2 px-2 font-mono themed-text-secondary">${escapeHtml(e.biometric_id)}</td>
                    <td class="py-2 px-2 themed-text-primary">${escapeHtml(e.name)}</td>
                    <td class="py-2 px-2 font-mono text-blue-500">${escapeHtml(e.dtr_time)}</td>
                    <td class="py-2 px-2 themed-text-muted">${escapeHtml(e.device_name)}</td>
                </tr>`;
            });

            if (filtered.length > 20) {
                html += `<tr><td colspan="4" class="py-2 text-center themed-text-muted">... and ${filtered.length - 20} more entries</td></tr>`;
            }

            html += '</tbody></table></div>';
            previewDiv.innerHTML = html;
        }

        function doPrint() {
            if (!selectedEmployee) return;

            const params = new URLSearchParams();
            params.set('date', selectedDate);
            params.set('name', selectedEmployee.name);
            params.set('biometric_id', selectedEmployee.bioId);

            window.open('/logs/alert/print?' + params.toString(), '_blank');
        }

        document.getElementById('printModalClose').addEventListener('click', closePrintModal);
        document.getElementById('printModal').addEventListener('click', (e) => {
            if (e.target.id === 'printModal') closePrintModal();
        });
    </script>
</body>
</html>
