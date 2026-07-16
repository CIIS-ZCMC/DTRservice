<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Device Log Alert - DTR Service</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .scrollbar-thin::-webkit-scrollbar { width: 8px; height: 8px; }
        .scrollbar-thin::-webkit-scrollbar-track { background: #1f2937; }
        .scrollbar-thin::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 4px; }
        .scrollbar-thin::-webkit-scrollbar-thumb:hover { background: #6b7280; }

        .panel-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #0f172a 100%);
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
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
        }
    </style>
</head>
<body class="bg-slate-950 text-gray-100 min-h-screen">
    <!-- Header -->
    <header class="bg-slate-900 border-b border-slate-800 px-6 py-4">
        <div class="max-w-full mx-auto">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-600 rounded-lg flex items-center justify-center">
                            <i class="fas fa-bell text-white text-lg"></i>
                        </div>
                        <div>
                            <h1 class="text-xl font-bold text-white">Device Log Alert</h1>
                            <p class="text-xs text-slate-400">Late-pulled data scanner</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <a href="/logs" class="text-slate-400 hover:text-white text-sm flex items-center gap-2 transition-colors">
                        <i class="fas fa-arrow-left"></i> Back to Logs
                    </a>
                    <div class="flex items-center gap-2 bg-slate-800 rounded-lg px-3 py-2">
                        <label class="text-xs text-slate-400 font-medium">Source:</label>
                        <select id="dataSource" class="bg-slate-700 text-white border border-slate-600 rounded px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="file" selected>File Record</option>
                            <option value="db">Device Logs</option>
                        </select>
                    </div>
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
        <div id="loadingOverlay" class="hidden fixed inset-0 bg-slate-950/80 z-50 flex items-center justify-center">
            <div class="text-center">
                <i class="fas fa-spinner fa-spin text-4xl text-orange-500 mb-3"></i>
                <p class="text-slate-400">Scanning log files...</p>
            </div>
        </div>

        <div class="grid grid-cols-12 gap-6">
            <!-- Calendar -->
            <div class="col-span-5">
                <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden glow-orange">
                    <div class="panel-header px-4 py-3 border-b border-slate-700">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 bg-orange-600 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-calendar-alt text-white"></i>
                                </div>
                                <h2 class="text-sm font-semibold text-white">Calendar</h2>
                            </div>
                            <div class="flex items-center gap-2">
                                <button id="prevMonth" class="bg-slate-700 hover:bg-slate-600 text-white px-3 py-1 rounded text-sm transition-colors">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <span id="monthLabel" class="text-white font-medium text-sm min-w-[140px] text-center"></span>
                                <button id="nextMonth" class="bg-slate-700 hover:bg-slate-600 text-white px-3 py-1 rounded text-sm transition-colors">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="p-4">
                        <!-- Day headers -->
                        <div class="grid grid-cols-7 gap-1 mb-2">
                            <div class="text-center text-xs text-slate-500 font-semibold py-1">Sun</div>
                            <div class="text-center text-xs text-slate-500 font-semibold py-1">Mon</div>
                            <div class="text-center text-xs text-slate-500 font-semibold py-1">Tue</div>
                            <div class="text-center text-xs text-slate-500 font-semibold py-1">Wed</div>
                            <div class="text-center text-xs text-slate-500 font-semibold py-1">Thu</div>
                            <div class="text-center text-xs text-slate-500 font-semibold py-1">Fri</div>
                            <div class="text-center text-xs text-slate-500 font-semibold py-1">Sat</div>
                        </div>
                        <!-- Calendar grid -->
                        <div id="calendarGrid" class="grid grid-cols-7 gap-1"></div>
                    </div>
                    <!-- Legend -->
                    <div class="px-4 py-3 border-t border-slate-800 flex items-center gap-4 text-xs text-slate-400">
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
                <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
                    <div class="panel-header px-4 py-3 border-b border-slate-700">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center">
                                <i class="fas fa-file-alt text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-sm font-semibold text-white">Date Details</h2>
                                <span id="detailStatus" class="text-xs text-slate-400">Select a date from the calendar</span>
                            </div>
                        </div>
                    </div>
                    <div id="detailContent" class="p-4 min-h-[400px] max-h-[calc(100vh-220px)] overflow-y-auto scrollbar-thin">
                        <div class="text-slate-500 text-center py-16">
                            <i class="fas fa-hand-pointer text-3xl mb-3 text-slate-600"></i>
                            <p>Click a date on the calendar to see which files contain logs for that date</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- File List -->
        <div id="fileListSection" class="mt-6">
            <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
                <div class="panel-header px-4 py-3 border-b border-slate-700">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-purple-600 rounded-lg flex items-center justify-center">
                            <i class="fas fa-database text-white"></i>
                        </div>
                        <div>
                            <h2 class="text-sm font-semibold text-white">Scanned Files</h2>
                            <span id="fileCount" class="text-xs text-slate-400">No files scanned yet</span>
                        </div>
                    </div>
                </div>
                <div id="fileList" class="p-4">
                    <div class="text-slate-500 text-center py-8">
                        <p>Click "Scan Files" to load data</p>
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
                empty.className = 'h-16 rounded-lg bg-slate-800/30';
                grid.appendChild(empty);
            }

            // Day cells
            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const cell = document.createElement('div');
                cell.className = 'cal-day h-16 rounded-lg border border-slate-700 bg-slate-800/50 cursor-pointer relative flex flex-col items-center justify-center';

                if (selectedDate === dateStr) {
                    cell.classList.add('selected');
                }

                const latePulls = scanData?.late_pulls || {};
                const dateEntries = scanData?.dates?.[dateStr] || [];

                // Day number
                const dayNum = document.createElement('span');
                dayNum.className = 'text-sm font-medium text-slate-300';
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
                detailContent.innerHTML = '<div class="text-slate-500 text-center py-8"><p>Scan first</p></div>';
                return;
            }

            const hasDate = scanData.dates.hasOwnProperty(dateStr);

            if (!hasDate) {
                detailStatus.textContent = `No data for ${dateStr}`;
                detailContent.innerHTML = `
                    <div class="text-slate-500 text-center py-16">
                        <i class="fas fa-calendar-times text-3xl mb-3 text-slate-600"></i>
                        <p>No log entries found for <span class="text-orange-400 font-medium">${formatDate(dateStr)}</span></p>
                    </div>`;
                return;
            }

            if (dataSource === 'db') {
                detailStatus.textContent = `${formatDate(dateStr)} — Loading...`;
                detailContent.innerHTML = `<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-2"></i><p class="text-slate-400 text-sm">Loading entries...</p></div>`;
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
                <div class="mb-4 p-3 bg-slate-800/50 rounded-lg border border-slate-700">
                    <div class="flex items-center gap-2 mb-1">
                        <i class="fas fa-info-circle text-blue-400"></i>
                        <span class="text-sm font-medium text-white">DTR Date: ${dateStr}</span>
                    </div>
                    <p class="text-xs text-slate-400">Found in ${entries.length} file(s) with ${totalEntries} total log entries</p>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-500 border-b border-slate-700">
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
                    <tr class="${rowClass} file-row-clickable border-b border-slate-800/50" onclick="openFileModal('${escapeHtml(entry.filename)}', '${dateStr}', ${entry.is_late}, ${entry.late_days})">
                        <td class="py-3 px-3 font-mono text-xs text-slate-300">${escapeHtml(entry.filename)}</td>
                        <td class="py-3 px-3 text-slate-400">${entry.file_date}</td>
                        <td class="py-3 px-3 text-center text-white font-medium">${entry.count}</td>
                        <td class="py-3 px-3 text-center ${entry.is_late ? 'text-orange-400 font-medium' : 'text-slate-500'}">${lateByText}</td>
                        <td class="py-3 px-3 text-center">
                            <span class="flex items-center justify-center gap-2">
                                ${statusBadge}
                                <i class="fas fa-eye text-slate-500 hover:text-blue-400 text-xs"></i>
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

            detailStatus.textContent = `${formatDate(dateStr)} — ${entries.length} entries`;

            let html = `
                <div class="mb-4 p-3 bg-slate-800/50 rounded-lg border border-slate-700">
                    <div class="flex items-center gap-2 mb-1">
                        <i class="fas fa-info-circle text-blue-400"></i>
                        <span class="text-sm font-medium text-white">DTR Date: ${dateStr}</span>
                    </div>
                    <p class="text-xs text-slate-400">${entries.length} log entries from database</p>
                </div>
                <div class="flex justify-end mb-3">
                    <button onclick="openDBModal('${dateStr}')" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium px-3 py-1.5 rounded-lg flex items-center gap-2 transition-colors">
                        <i class="fas fa-eye"></i> View All with Filters
                    </button>
                </div>
                <div class="overflow-x-auto scrollbar-thin">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-slate-500 border-b border-slate-700">
                                <th class="text-left py-2 px-2 font-semibold">Biometric ID</th>
                                <th class="text-left py-2 px-2 font-semibold">Name</th>
                                <th class="text-left py-2 px-2 font-semibold">Time</th>
                                <th class="text-left py-2 px-2 font-semibold">Type</th>
                                <th class="text-left py-2 px-2 font-semibold">Device</th>
                            </tr>
                        </thead>
                        <tbody>`;

            entries.forEach(e => {
                html += `
                    <tr class="border-b border-slate-800/50 hover:bg-slate-800/30">
                        <td class="py-2 px-2 font-mono text-slate-300">${escapeHtml(e.biometric_id)}</td>
                        <td class="py-2 px-2 text-slate-200">${escapeHtml(e.name)}</td>
                        <td class="py-2 px-2 font-mono text-blue-300">${escapeHtml(e.dtr_time)}</td>
                        <td class="py-2 px-2 text-slate-500">${escapeHtml(e.dtr_type)}</td>
                        <td class="py-2 px-2 text-slate-500">${escapeHtml(e.device_name)}</td>
                    </tr>`;
            });

            html += '</tbody></table></div>';
            detailContent.innerHTML = html;
        }

        function renderFileList() {
            const fileList = document.getElementById('fileList');
            const fileCount = document.getElementById('fileCount');

            if (!scanData || scanData.files.length === 0) {
                fileList.innerHTML = '<div class="text-slate-500 text-center py-8"><p>No files found</p></div>';
                fileCount.textContent = 'No files found';
                return;
            }

            fileCount.textContent = `${scanData.files.length} file(s) found`;

            let html = `
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-500 border-b border-slate-700">
                            <th class="text-left py-2 px-3 font-semibold">File Name</th>
                            <th class="text-left py-2 px-3 font-semibold">File Date</th>
                            <th class="text-center py-2 px-3 font-semibold">Entries</th>
                            <th class="text-right py-2 px-3 font-semibold">Size</th>
                        </tr>
                    </thead>
                    <tbody>`;

            scanData.files.forEach(file => {
                html += `
                    <tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                        <td class="py-3 px-3 font-mono text-xs text-slate-300">${escapeHtml(file.filename)}</td>
                        <td class="py-3 px-3 text-slate-400">${file.file_date}</td>
                        <td class="py-3 px-3 text-center text-white font-medium">${file.entries}</td>
                        <td class="py-3 px-3 text-right text-slate-500 text-xs">${file.size}</td>
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
            modalMeta.innerHTML = `<span class="text-slate-400">Source: <span class="text-white font-medium">Database</span></span>`;
            document.getElementById('modalResults').innerHTML = `<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-2"></i><p class="text-slate-400 text-sm">Loading entries...</p></div>`;
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
            modalMeta.innerHTML = `<span class="text-slate-400">DTR Date: <span class="text-white font-medium">${dtrDate}</span></span> <span class="mx-2 text-slate-600">|</span> ${statusText}`;
            document.getElementById('modalResults').innerHTML = `<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-2"></i><p class="text-slate-400 text-sm">Loading file contents...</p></div>`;
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

            let html = `<div class="mb-3 text-xs text-slate-400">Showing ${filtered.length} of ${modalEntries.length} entries</div>`;

            if (filtered.length === 0) {
                html += `<div class="text-slate-500 text-center py-8"><i class="fas fa-search text-2xl mb-2 text-slate-600"></i><p>No matching entries</p></div>`;
            } else {
                html += `
                    <div class="overflow-x-auto scrollbar-thin">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-slate-500 border-b border-slate-700">
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
                        <tr class="border-b border-slate-800/50 hover:bg-slate-800/30">
                            <td class="py-2 px-2 font-mono text-slate-300">${escapeHtml(e.biometric_id)}</td>
                            <td class="py-2 px-2 text-slate-200">${escapeHtml(e.name)}</td>
                            <td class="py-2 px-2 text-slate-400">${escapeHtml(e.dtr_date)}</td>
                            <td class="py-2 px-2 font-mono text-blue-300">${escapeHtml(e.dtr_time)}</td>
                            <td class="py-2 px-2 text-slate-500">${escapeHtml(e.dtr_type)}</td>
                            <td class="py-2 px-2 text-slate-500">${escapeHtml(e.device_name)}</td>
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
        <div class="bg-slate-900 rounded-xl border border-slate-700 shadow-2xl w-full max-w-4xl max-h-[85vh] flex flex-col">
            <div class="panel-header px-5 py-4 border-b border-slate-700 flex items-center justify-between">
                <div>
                    <h3 id="fileModalTitle" class="text-sm font-semibold text-white"></h3>
                    <div id="fileModalMeta" class="mt-1 text-xs"></div>
                </div>
                <button id="fileModalClose" class="text-slate-400 hover:text-white hover:bg-slate-700 p-2 rounded-lg transition-colors">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div id="fileModalBody" class="flex-1 overflow-y-auto scrollbar-thin p-5">
                <div id="modalFilters" class="mb-4 grid grid-cols-4 gap-3" style="display:none">
                    <input id="filterBio" type="text" placeholder="Biometric ID" oninput="renderModalEntries()"
                        class="bg-slate-800 text-white text-xs border border-slate-600 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 placeholder-slate-500">
                    <input id="filterName" type="text" placeholder="Name" oninput="renderModalEntries()"
                        class="bg-slate-800 text-white text-xs border border-slate-600 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 placeholder-slate-500">
                    <input id="filterTime" type="text" placeholder="Time" oninput="renderModalEntries()"
                        class="bg-slate-800 text-white text-xs border border-slate-600 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 placeholder-slate-500">
                    <input id="filterDevice" type="text" placeholder="Device" oninput="renderModalEntries()"
                        class="bg-slate-800 text-white text-xs border border-slate-600 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 placeholder-slate-500">
                </div>
                <div id="modalResults"></div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('fileModalClose').addEventListener('click', closeFileModal);
        document.getElementById('fileModal').addEventListener('click', (e) => {
            if (e.target.id === 'fileModal') closeFileModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeFileModal();
        });
    </script>
</body>
</html>
