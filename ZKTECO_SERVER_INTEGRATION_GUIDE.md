# ZKTeco Server & Inbound Punch Ingestion Integration Guide
**ZCMC DTRService (`:8181`) & ZKTeco Integration Server (`:8080` / `:4370`)**

This guide explains how **DTRService** connects with the co-hosted **ZKTeco ADMS & Inbound Punch Ingestion Server** on your live production server (`192.168.9.243`).

---

## 1. System Architecture & Port Allocation

On the live production host (`192.168.9.243`), services are organized as follows:

```
                         ┌──────────────────────────────────────────────────┐
                         │       PHYSICAL CLOCKS / KIOSKS / CLIENTS         │
                         └───────────┬──────────────────────────┬───────────┘
                                     │                          │
                 Push to DTRService  │                          │  Push to ZKTeco
                 (Port 8181)         │                          │  (Port 8080)
                                     ▼                          ▼
┌───────────────────────────────────────────┐      ┌───────────────────────────────────────────┐
│               DTRService                  │      │      ZKTeco ADMS & Ingestion Server       │
│           (Port 8181 / Laravel)           │      │                (Port 8080)                │
├───────────────────────────────────────────┤      ├───────────────────────────────────────────┤
│ • Receives logs via ADMS & /api/device-logs│      │ • SQLite Local Attendance Database        │
│ • MySQL: biometrics, schedules, attendance│      │ • Flagged downloaded = 0                  │
│ • Auto-forwards punches to Port 8080 ─────┼─────►│ • Inbound endpoint: /api/punches          │
└───────────────────────────────────────────┘      └─────────────────────┬─────────────────────┘
                                                                         │
                                                                         │ Serves logs over
                                                                         │ ZKTeco Protocol
                                                                         ▼
                                                   ┌───────────────────────────────────────────┐
                                                   │          ZKTeco TCP/UDP Proxy             │
                                                   │                (Port 4370)                │
                                                   ├───────────────────────────────────────────┤
                                                   │ Desktop Payroll Software (ZKTime / SDK)   │
                                                   └───────────────────────────────────────────┘
```

### Port Breakdown on `192.168.9.243`
| Port | Service | Purpose |
|:---:|---|---|
| **`8181`** | **DTRService** | Main web portal, REST API (`/api/device-logs`), employee biometrics mapping, and DTR calculation. |
| **`8080`** | **ZKTeco Inbound API & ADMS** | Ingestion listener for attendance logs. Automatically flags punches with `downloaded = 0` for desktop software. |
| **`4370`** | **ZKTeco TCP/UDP Proxy** | Simulates a physical ZKTeco biometric clock so legacy desktop payroll programs (e.g. ZKTime) can connect and download attendance. |

---

## 2. Production Server Configuration (`192.168.9.243`)

Because **DTRService** and the **ZKTeco Integration Server** are co-hosted on the exact same server machine (`192.168.9.243`), DTRService reaches the ZKTeco API using **localhost loopback (`http://127.0.0.1:8080`)** for zero network latency and maximum resilience.

### Step 1: Update `.env` on the Live Production Server
Open your `.env` file on `192.168.9.243:8181` and configure:

```env
# ZKTeco Inbound Punch Ingestion API & ADMS Integration Server (Port 8080)
ZK_SERVER_FORWARD_ENABLED=true
ZK_SERVER_HOST=http://127.0.0.1:8080
ZK_SERVER_TIMEOUT=3
ZK_SERVER_DEVICE_SN=RKQ4242300018
ZK_SERVER_BATCH_SIZE=50

# Specific device ID(s) permitted to forward to ZKTeco (e.g. 7 or 7,14)
# Leave blank to allow all active devices, or specify the exact device ID
ZK_FORWARD_DEVICE_IDS=

# Map canonical biometric_id (e.g. 493) to hrbliz_biometric_id (e.g. 5180) for ZKTeco Server
ZK_FORWARD_USE_HRBLIZ_ID=true

# Strictly classify hardware status 255 into 0 (Check-In) or 1 (Check-Out) using UMIS shift windows
ZK_STRICT_HRBLIZ_STATUS=true
```

> [!TIP]
> If testing from another computer on the hospital LAN, set `ZK_SERVER_HOST=http://192.168.9.243:8080`.  
> On the production server itself, `http://127.0.0.1:8080` is recommended.

### Step 2: Clear Cached Configuration
Run the following in the production console:
```bash
php artisan config:clear
```

### Step 3: Test Connectivity
Run the built-in diagnostic command:
```bash
php artisan zk:test-connection
```

**Expected Output:**
```
=== Testing ZKTeco Server Connection ===
Target Host    : http://127.0.0.1:8080
Forward Status : ENABLED
Checking health endpoint (http://127.0.0.1:8080/api/punches)...

✓ CONNECTION SUCCESSFUL (Latency: 24 ms)
+----------------------+---------------------------------------------------------------------------+
| Field                | Value                                                                     |
+----------------------+---------------------------------------------------------------------------+
| Service              | ZKTeco Inbound Punch Ingestion API                                        |
| Status               | online                                                                    |
| Message              | Endpoint is active and ready to accept POST requests with biometric logs. |
| Total Punches Stored | 2766                                                                      |
| Today Punches        | 28                                                                        |
+----------------------+---------------------------------------------------------------------------+
DTRService is ready to forward punches to http://127.0.0.1:8080!
```

---

## 3. How the Integration Operates Automatically

Once enabled, DTRService automatically mirrors attendance punches into the ZKTeco server across four ingestion points:

1. **Real-Time Physical Clocks Push (`/iclock/cdata` via `LogsService`)**:  
   When a biometric device pushes an attendance punch (`ATTLOG`), DTRService saves it to MySQL and forwards it to `http://127.0.0.1:8080/api/punches`.

2. **Manual Punch Input Endpoint (`POST /api/manual-punch` & `POST /api/device-logs/manual`)**:  
   Allows administrators, HR staff, or external systems to manually insert attendance punches. DTRService resolves employee details, records the punch in `device_logs`, and immediately forwards it **synchronously** to `http://192.168.9.243:8080/api/punches` (bypassing physical device ID restrictions).

3. **External Systems & Kiosks API (`POST /api/device-logs` via `DeviceLogController`)**:  
   When kiosks or external portals post attendance records, DTRService resolves canonical IDs and forwards them to Port 8080. Pass `"sync_zkteco": true` to force synchronous forwarding.

4. **Manual / Scheduled Device Pulling (`php artisan devices:pull-logs`)**:  
   Any newly pulled logs are automatically mirrored to Port 8080.

5. **Fail-Safe Isolation**:  
   Forwarding requests use a 3-second non-blocking timeout and are wrapped in isolated `try / catch` blocks. If the ZKTeco proxy is temporarily restarting, **DTRService operations and local database recording will never be interrupted or slowed down**.

---

## 4. Manual Punch API Endpoint Reference

Use this endpoint when you need to manually insert punches and have them synchronously recorded in **both** DTRService and the ZKTeco integration server on Port 8080.

### 4.1. Single Manual Punch
* **Method:** `POST`
* **Path:** `/api/manual-punch` *(or `/api/device-logs/manual`)*
* **Headers:** `Content-Type: application/json`, `Accept: application/json`

#### Request Payload
```json
{
  "biometric_id": "493",
  "date_time": "2026-11-09 08:00:00",
  "status": 0,
  "device_name": "RKQ4242300018",
  "sync_zkteco": true,
  "prevent_duplicates": true
}
```

> [!TIP]
> - You can provide either **`biometric_id`** (e.g. `"493"`) or **`hrbliz_biometric_id`** (e.g. `"5180"`). The API automatically resolves the employee and translates to the correct identifier for Port 8080.
> - **`status`** accepts integers (`0`=Check-In, `1`=Check-Out, `2`=Break-Out, `3`=Break-In, `4`=OT-In, `5`=OT-Out, `255`=Auto) or strings (`"in"`, `"out"`, `"break-out"`, `"break-in"`). If omitted or `255`, it is auto-classified using the employee's UMIS shift schedule.
> - **`sync_zkteco`** defaults to `true`, guaranteeing synchronous push to `192.168.9.243:8080`.

#### Successful Response (`201 Created`)
```json
{
  "success": true,
  "message": "Manual punch recorded successfully and synchronized with ZKTeco server.",
  "data": {
    "id": 14210,
    "biometric_id": "493",
    "hrbliz_biometric_id": "5180",
    "name": "Juan Dela Cruz",
    "dtr_date": "2026-11-09",
    "date_time": "2026-11-09 08:00:00",
    "status": 0,
    "status_label": "Check-In",
    "device_name": "RKQ4242300018",
    "created_at": "2026-11-09T08:00:00.000000Z"
  },
  "zkteco_sync": {
    "success": true,
    "target_host": "http://192.168.9.243:8080",
    "status_code": 200,
    "latency_ms": 28.5,
    "pin_sent": "5180",
    "status_sent": 0,
    "payload": {
      "pin": "5180",
      "time": "2026-11-09 08:00:00",
      "status": 0,
      "device_sn": "RKQ4242300018"
    },
    "response": {
      "success": true,
      "message": "Punch saved"
    }
  }
}
```

### 4.2. Batch Manual Punches
* **Method:** `POST /api/manual-punch`
```json
{
  "sync_zkteco": true,
  "punches": [
    {
      "biometric_id": "493",
      "date_time": "2026-11-09 08:00:00",
      "status": 0,
      "device_sn": "RKQ4242300018"
    },
    {
      "biometric_id": "493",
      "date_time": "2026-11-09 17:00:00",
      "status": 1,
      "device_sn": "RKQ4242300018"
    }
  ]
}
```

### 4.3. Direct ZKTeco Server Endpoint (Port 8080)
If you wish to submit a punch directly to the ZKTeco SQLite database (bypassing DTRService MySQL):
* **Method:** `POST`
* **URL:** `http://192.168.9.243:8080/api/punches`
* **Payload:**
```json
{
  "pin": "5180",
  "time": "2026-11-09 08:00:00",
  "status": 0,
  "device_sn": "RKQ4242300018"
}
```
*(Note: Submitting directly to 8080 will NOT record into DTRService MySQL database or calculate UMIS DTR reports. Use `POST /api/manual-punch` on DTRService for full dual-system synchronous recording).*

---

## 5. Backfilling / Syncing Existing Punches

To transfer historical or today's punches from DTRService into the ZKTeco database for legacy desktop payroll:

### Dry Run (Preview without sending)
```bash
php artisan zk:sync-punches --dry-run --today
```

### Sync Today's Punches
```bash
php artisan zk:sync-punches --today
```

### Sync a Specific Date
```bash
php artisan zk:sync-punches --date=2026-10-28
```

### Sync a Date Range with a Batch Limit
```bash
php artisan zk:sync-punches --start-date=2026-10-01 --end-date=2026-10-28 --limit=1000
```

> [!NOTE]
> Duplicate logs are **safely skipped** by the ZKTeco server without throwing errors. You can run synchronization as often as needed without creating duplicate hours in payroll.

---

## 5. Network Firewall Checklist

Ensure the following inbound ports are permitted in Windows Defender Firewall on `192.168.9.243`:

| Port | Protocol | Scope | Required For |
|:---:|:---:|:---:|---|
| **8181** | TCP | Local LAN (`192.168.0.0/16`) | Accessing DTRService Web UI & API endpoints |
| **8080** | TCP | Local LAN (`192.168.0.0/16`) | Inbound push listener for physical clocks & DTRService |
| **4370** | TCP & UDP | Legacy Payroll Workstations | ZKTeco standalone SDK / ZKTime payroll desktop client |

### PowerShell Firewall Commands (Run as Administrator on `192.168.9.243`):
```powershell
# Open Port 8181 for DTRService
New-NetFirewallRule -DisplayName "DTRService Port 8181" -Direction Inbound -LocalPort 8181 -Protocol TCP -Action Allow

# Open Port 8080 for ZKTeco HTTP Inbound API
New-NetFirewallRule -DisplayName "ZKTeco API Port 8080" -Direction Inbound -LocalPort 8080 -Protocol TCP -Action Allow

# Open Port 4370 (TCP & UDP) for Legacy Payroll Desktop SDK
New-NetFirewallRule -DisplayName "ZKTeco Proxy Port 4370 TCP" -Direction Inbound -LocalPort 4370 -Protocol TCP -Action Allow
New-NetFirewallRule -DisplayName "ZKTeco Proxy Port 4370 UDP" -Direction Inbound -LocalPort 4370 -Protocol UDP -Action Allow
```
