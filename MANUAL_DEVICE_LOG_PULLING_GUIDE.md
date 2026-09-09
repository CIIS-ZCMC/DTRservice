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
