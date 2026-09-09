# 📥 Manual Biometric Log Pulling & Missed Log Recovery Guide

This guide details how to manually pull attendance logs or request attendance resends from physical ZKTeco biometric devices in the **ZCMC DTRService** system.

---

## 📋 Table of Contents
1. [Why Logs Can Be Missed (The "Marked OK" Issue)](#1-why-logs-can-be-missed-the-marked-ok-issue)
2. [Recovery Method 1: Instant Direct SOAP Pull (Recommended)](#2-recovery-method-1-instant-direct-soap-pull-recommended)
3. [Recovery Method 2: HTTP Push / ADMS Resend Command (DATA QUERY ATTLOG)](#3-recovery-method-2-http-push--adms-resend-command-data-query-attlog)
4. [Deduplication & Safety Safeguards](#4-deduplication--safety-safeguards)
5. [API Endpoints Reference](#5-api-endpoints-reference)
6. [Artisan CLI Reference](#6-artisan-cli-reference)
7. [How to Verify & Inspect Recovered Logs](#7-how-to-verify--inspect-recovered-logs)
8. [Device Attendance Logs Deletion & Scheduled Maintenance](#8-device-attendance-logs-deletion--scheduled-maintenance)
9. [Database Log Retention & 1-Year Pruning](#9-database-log-retention--1-year-pruning)

---

## 1. Why Logs Can Be Missed (The "Marked OK" Issue)

### The Problem
When a ZKTeco biometric terminal pushes logs over HTTP (`POST /iclock/cdata`), the server responds with `"OK"`. Once the device receives `"OK"`, it considers the transmission complete and clears the pending push status in its local buffer.

However, if:
- A device is designated for event attendance (`for_attendance == 1`) but no matching active attendance event was opened for that date, or
- There was an unhandled database condition or brief connection glitch,

the server previously sent `"OK"` back to the terminal without saving the records. Because the terminal received `"OK"`, it never attempted to push those records again automatically.

### The Good News
**Physical ZKTeco terminals keep all historical attendance logs stored in their internal flash memory** (often holding 50,000 to 100,000+ records) until manually cleared. You can pull or request a resend of these records at any time!

---

## 2. Recovery Method 1: Instant Direct SOAP Pull (Recommended)

This method connects directly to the terminal's IP address over SOAP (port 80) via `TADPHP`, reads the device's internal memory buffer directly, and immediately saves any missing logs to the database.

> **Execution Speed**: ~0.5 to 1.5 seconds per device.

### Via Artisan Command Line:
```powershell
# Pull today's logs from Device ID 6:
php artisan devices:pull-logs 6 --date=2026-09-09

# Pull a date range from Device ID 6:
php artisan devices:pull-logs 6 --start-date=2026-09-01 --end-date=2026-09-09

# Pull from ALL active devices:
php artisan devices:pull-logs --all --date=2026-09-09

# Pull only for a specific employee biometric ID (PIN):
php artisan devices:pull-logs 6 --pin=1437
```

### Via Web Browser / GET URL:
Paste into your browser or trigger via Postman / curl:
- **Single device for today**:
  ```
  http://<SERVER_IP>:8000/api/devices/6/pull-logs?date=2026-09-09
  ```
- **Single device for a date range**:
  ```
  http://<SERVER_IP>:8000/api/devices/6/pull-logs?start_date=2026-09-01&end_date=2026-09-09
  ```
- **All active devices for today**:
  ```
  http://<SERVER_IP>:8000/api/devices/pull-logs?date=2026-09-09
  ```

---

## 3. Recovery Method 2: HTTP Push / ADMS Resend Command (DATA QUERY ATTLOG)

If a device is located on a remote network segment where the server cannot open an inbound socket to the device's port 80, use this ADMS push method.

### How It Works:
1. The server queues an ADMS command:
   ```plaintext
   C:<id>:DATA QUERY ATTLOG	StartTime=2026-09-01 00:00:00	EndTime=2026-09-09 23:59:59
   ```
2. The next time the device polls `/iclock/getrequest?SN=...` (every 5–10 seconds), it fetches the command.
3. The device queries its flash memory and re-uploads all matching attendance records to `POST /iclock/cdata?table=ATTLOG`.
4. The server processes and saves the incoming records, automatically skipping any duplicates.

### Via Artisan Command Line:
```powershell
# Request Device 6 to re-send logs for a date range:
php artisan devices:pull-logs 6 --resend --start-date=2026-09-01 --end-date=2026-09-09

# Request ALL active devices to re-send logs for today:
php artisan devices:pull-logs --all --resend --date=2026-09-09

# For older ZKTeco firmware variants that require the 'LOG' command syntax:
php artisan devices:pull-logs 6 --resend --type=LOG --start-date=2026-09-01 --end-date=2026-09-09
```

### Via Web Browser / GET URL:
- **Request single device to resend**:
  ```
  http://<SERVER_IP>:8000/api/devices/6/request-resend?start_date=2026-09-01&end_date=2026-09-09
  ```
- **Request all active devices to resend**:
  ```
  http://<SERVER_IP>:8000/api/devices/request-resend?start_date=2026-09-01&end_date=2026-09-09
  ```

---

## 4. Deduplication & Safety Safeguards

1. **In-Memory Batch Deduplication**:
   When pulling thousands of records from a device buffer, the system pre-fetches existing records from `device_logs` and `attendance_information`. Duplicate records are skipped in memory (O(1) lookup), resulting in near-instant processing with **zero duplicate database rows**.
2. **Automatic Attendance Fallback**:
   If an attendance-tagged device (`for_attendance == 1`) receives logs for a date that lacks an active event, the system **automatically falls back to saving into `device_logs`**. Employee punches are never lost into the void again.
3. **Multi-Store Persistence**:
   Every pulled or resent log is written to:
   - MySQL database (`device_logs` / `attendance_information`)
   - Daily local storage files (`storage/app/private/device_logs_YYYY-MM-DD.txt`)
   - Structured JSON logs (`storage/logs/device_logs-YYYY-MM-DD.log`)

---

## 5. API Endpoints Reference

### 1. Direct SOAP Pull Endpoints
| Method | Route | Description |
| :--- | :--- | :--- |
| `GET / POST` | `/api/devices/pull-logs` | Pull from all active devices (or pass `device_id`) |
| `GET / POST` | `/api/devices/{id}/pull-logs` | Pull from a specific device by ID `{id}` |

### 2. ADMS Resend Endpoints
| Method | Route | Description |
| :--- | :--- | :--- |
| `GET / POST` | `/api/devices/request-resend` | Queue resend command for all active devices |
| `GET / POST` | `/api/devices/{id}/request-resend` | Queue resend command for specific device `{id}` |

#### Supported Query / Body Parameters:
| Parameter | Type | Default | Example | Description |
| :--- | :--- | :--- | :--- | :--- |
| `device_id` | `int` | *(Optional)* | `6` | Target device ID (if calling `/pull-logs`) |
| `date` | `string` | *(Optional)* | `2026-09-09` | Pull logs for a single day (`YYYY-MM-DD`) |
| `start_date` | `string` | Today | `2026-09-01` | Start date or timestamp |
| `end_date` | `string` | Today | `2026-09-09` | End date or timestamp |
| `pin` / `biometric_id` | `int` | *(Optional)* | `1437` | Filter by specific employee PIN |
| `type` | `string` | `DATA QUERY ATTLOG` | `LOG`, `BOTH`, `CHECK` | Command type (for resend endpoints) |

#### Example JSON Response:
```json
{
  "success": true,
  "data": {
    "device_id": 6,
    "device_name": "Live - Admin-Lobby",
    "ip_address": "192.168.5.158",
    "status": "success",
    "total_pulled": 949,
    "filtered_count": 380,
    "new_saved": 380,
    "duplicates_skipped": 0,
    "sample": [
      {
        "biometric_id": 1437,
        "date_time": "2026-09-09 05:22:55",
        "status": "255"
      }
    ]
  }
}
```

---

## 6. Artisan CLI Reference

```bash
# Basic pull for today across all devices:
php artisan devices:pull-logs --date=2026-09-09

# Pull specific device ID:
php artisan devices:pull-logs 6 --date=2026-09-09

# Pull date range:
php artisan devices:pull-logs 6 --start-date=2026-09-01 --end-date=2026-09-09

# ADMS Push Resend mode:
php artisan devices:pull-logs 6 --resend --start-date=2026-09-01 --end-date=2026-09-09

# ADMS Push Resend for all devices:
php artisan devices:pull-logs --all --resend --date=2026-09-09
```

---

## 7. How to Verify & Inspect Recovered Logs

1. **Terminal Table Output**:
   Running the Artisan command prints a formatted table displaying `Total In Device`, `Filtered`, `New Saved`, and `Duplicates Skipped`.
2. **Web Alert Dashboard**:
   Open `http://<SERVER_IP>:8000/logs/alert` in your browser. The calendar view and table will immediately reflect the recovered entries.
3. **Database Records**:
   ```sql
   -- View recovered logs:
   SELECT * FROM device_logs WHERE dtr_date = '2026-09-09' ORDER BY date_time DESC;
   ```
4. **Local Text Log Files**:
   Open `storage/app/private/device_logs_YYYY-MM-DD.txt` to inspect human-readable appended lines.

---

## 8. Device Attendance Logs Deletion & Scheduled Maintenance

### 8.1 Why Device Attendance Logs Are Cleared
Physical ZKTeco terminals store punch logs in local flash memory (typically holding 50,000 to 100,000+ records). Over months of continuous hospital operations, this buffer fills up, causing:
- Slower punch recognition and visual lags on terminal screens.
- Memory full warnings and rejected employee punches when capacity reaches 100%.

Clearing terminal attendance logs periodically keeps device performance optimal and ensures devices never run out of flash storage.

### 8.2 Firmware Realities & Data Preservation
> [!IMPORTANT]
> **Selective Date Deletion is NOT Supported by Terminal Hardware**: ZKTeco firmware does not support deleting only older dates while keeping recent ones. The clear command purges the entire attendance punch buffer (`ATTLOG`).
>
> **What is Preserved on the Terminal:**
> - Enrolled users (`USER`) — **Untouched**
> - Fingerprint templates (`FINGERTMP`) — **Untouched**
> - Face templates (`BIODATA`) — **Untouched**
> - Passwords, badges, network IP configuration — **Untouched**
>
> **Historical Punch Records are Permanently Kept in Server Database**:
> All punches ever recorded remain safe indefinitely in MySQL (`device_logs` / `attendance_information` / `dtr`) and daily text backups (`storage/app/private/device_logs_YYYY-MM-DD.txt`).

### 8.3 Zero-Data-Loss Pre-Sync Safety Gate
Before any clear command (`CLEAR LOG` or direct SOAP `ClearData(1)`) is dispatched:
1. The server performs an **automated pre-wipe pull** (`pullLogsFromDevice`) via SOAP.
2. It verifies that **100% of all punches** from that device have been committed to the MySQL database.
3. If the pre-sync pull fails or cannot be verified, **the wipe is automatically aborted**.

### 8.4 Offline Device Protection & Catch-Up Workflow
> [!CAUTION]
> **Never Blindly Queue `CLEAR LOG` for Offline Devices**:
> If a device is powered off or disconnected, it may contain offline punches made by employees that have not yet reached the server. Blindly queueing a wipe would cause the terminal to delete those punches immediately upon reconnecting before uploading them.

**Our Fail-Safe Strategy:**
1. **Offline Devices are Automatically Skipped**: If a device is offline, the clear operation is deferred. Its flash memory remains 100% intact.
2. **Buffer Capacity is Never an Emergency**: Physical devices easily hold 50k–100k punches. Deferring clearing for offline devices poses zero risk of memory overflow.
3. **Daily Catch-Up Routine**: Once the device reconnects and goes online, the daily catch-up job runs pre-sync verification, pulls any missing logs to the database, and only then clears the terminal memory.

### 8.5 Automated Schedules
Configured in `routes/console.php`:
* **Weekly Primary Clear (Every Sunday at 23:55)**:
  Clears all online active devices with pre-sync verification.
  ```bash
  php artisan devices:clear-logs --all --force --method=both
  ```
* **Daily Catch-Up Run (Every Day at 12:00 PM)**:
  Runs for any devices that were offline during Sunday's run and have not had their logs cleared in $\ge 7$ days.
  ```bash
  php artisan devices:clear-logs --all --catch-up --older-than=7 --force --method=both
  ```

### 8.6 Artisan CLI Reference for Log Clearing
```powershell
# 1. Simulate clearance and test pre-sync without deleting any device data (Dry Run):
php artisan devices:clear-logs --dry-run

# 2. Clear a specific device by ID (with confirmation prompt):
php artisan devices:clear-logs 6

# 3. Clear a specific device immediately (bypass prompt):
php artisan devices:clear-logs 6 --force

# 4. Clear all active devices immediately:
php artisan devices:clear-logs --all --force

# 5. Run catch-up for devices not cleared in 7+ days:
php artisan devices:clear-logs --all --catch-up --older-than=7 --force

# 6. Specify protocol method (both = SOAP instant with ADMS fallback):
php artisan devices:clear-logs 6 --method=soap --force
php artisan devices:clear-logs 6 --method=adms --force
```

### 8.7 REST API Reference for Log Clearing
| Method | Endpoint | Description | Query / Body Parameters |
| :--- | :--- | :--- | :--- |
| `POST / GET` | `/api/devices/clear-logs` | Clear all active devices | `force`, `dry_run`, `method`, `catch_up`, `older_than` |
| `POST / GET` | `/api/devices/{id}/clear-logs` | Clear a specific device by ID `{id}` | `force`, `dry_run`, `method`, `skip_sync` |

#### Example JSON Response:
```json
{
  "success": true,
  "data": {
    "device_id": 6,
    "device_name": "Live - Admin-Lobby",
    "serial_number": "CLR123456",
    "ip_address": "192.168.5.158",
    "status": "success",
    "method": "soap",
    "is_online": true,
    "pre_sync": {
      "total_pulled": 957,
      "new_saved": 0,
      "duplicates_skipped": 957,
      "pull_status": "success"
    },
    "last_cleared_at": "2026-09-09 16:00:00",
    "message": "Device attendance logs cleared immediately via direct SOAP."
  }
}
```

---

## 9. Database Log Retention & 1-Year Pruning

### 9.1 Overview & Retention Policy
While terminal flash memory is cleared weekly to keep devices responsive, raw punch logs in the central MySQL database (`device_logs`) accumulate rapidly (hundreds of thousands of records annually).

To prevent database bloat and optimize query performance, historical punch logs in `device_logs` older than **1 year** are automatically pruned.
- **Computed DTR Records are Preserved**: Processed daily time records stored in the `dtr` table are **never** touched by this command.
- **Permanent Cold Storage**: Pruned records can be automatically compressed and archived to disk before deletion (`storage/app/archive/`).

### 9.2 Chunked Deletion & Table Lock Avoidance
Executing a bulk `DELETE FROM device_logs WHERE dtr_date < ...` across 100,000+ rows can lock the MySQL table and block incoming live attendance pushes from hospital terminals.

The pruning routine executes in **safe chunks** (default: 2,000 records per transaction) by primary key ID:
1. Queries batch of eligible IDs: `SELECT id FROM device_logs WHERE dtr_date < ? LIMIT 2000`
2. Streams rows into a gzip-compressed archive file (if enabled).
3. Executes fast indexed delete: `DELETE FROM device_logs WHERE id IN (...)`
4. Yields 10ms between chunks to allow live attendance ingestion to proceed without disruption.

### 9.3 Compressed Archiving
When `--archive` is passed, pruned rows are streamed into a `.json.gz` file located in `storage/app/archive/`:
```
storage/app/archive/device_logs_archived_before_YYYY-MM-DD_YYYYMMDD_HHmmss.json.gz
```
This reduces disk space by over 90% while guaranteeing that any historical raw punch can be decompressed and retrieved for future audits.

### 9.4 Monthly Automation Schedule
Configured in `routes/console.php`:
* **Monthly Database Prune (1st of every month at 01:00 AM)**:
  ```bash
  php artisan device-logs:prune --years=1 --archive --force
  ```

### 9.5 Artisan CLI Reference for Database Pruning
```powershell
# 1. Simulate pruning and view eligible record counts without deleting (Dry Run):
php artisan device-logs:prune --dry-run

# 2. Prune records older than 1 year with automatic compressed archive:
php artisan device-logs:prune --years=1 --archive

> [!IMPORTANT]
> **Strict 1-Year Guardrail**: The system strictly enforces that database punch logs can **ONLY** be cleared if they are **1 year before today or older** (`dtr_date <= now() - 1 year`). Any attempt to specify a cutoff date newer than 1 year ago will be rejected with a `Safety Violation` error across the CLI, REST API, and repository layers.

# 3. Prune records older than 1 year immediately (bypass confirmation prompt):
php artisan device-logs:prune --years=1 --archive --force

# 4. Prune records older than a custom day threshold (must be >= 365 days):
php artisan device-logs:prune --days=365 --archive --force

# 5. Prune records before a specific calendar date (must be >= 1 year before today):
php artisan device-logs:prune --before=2025-09-09 --archive --force

# 6. Customize deletion chunk size:
php artisan device-logs:prune --years=1 --chunk=5000 --archive --force
```

### 9.6 REST API Reference for Database Pruning
| Method | Endpoint | Description | Query / Body Parameters |
| :--- | :--- | :--- | :--- |
| `POST / GET` | `/api/device-logs/prune` | Prune historical database logs | `years`, `days`, `before`, `chunk`, `archive`, `dry_run` |

#### Example JSON Response:
```json
{
  "success": true,
  "data": {
    "cutoff_date": "2025-09-09",
    "total_eligible": 45120,
    "deleted_count": 45120,
    "archived_count": 45120,
    "archive_file": "device_logs_archived_before_2025-09-09_20260909_010000.json.gz",
    "archive_path": "d:\\ZCMC_SYSTEMS\\DTRService\\storage\\app/archive/device_logs_archived_before_2025-09-09_20260909_010000.json.gz",
    "duration_seconds": 12.45,
    "dry_run": false
  }
}
```

