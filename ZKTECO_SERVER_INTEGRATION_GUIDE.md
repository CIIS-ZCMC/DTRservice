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
ZK_SERVER_DEVICE_SN=DTRSERVICE_8181
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

Once enabled, DTRService automatically mirrors attendance punches into the ZKTeco server across three ingestion points:

1. **Real-Time Physical Clocks Push (`/iclock/cdata` via `LogsService`)**:  
   When a biometric device pushes an attendance punch (`ATTLOG`), DTRService saves it to MySQL and forwards it to `http://127.0.0.1:8080/api/punches`.

2. **External Systems & Kiosks API (`POST /api/device-logs` via `DeviceLogController`)**:  
   When kiosks or external portals post attendance records, DTRService resolves canonical IDs and forwards them to Port 8080.

3. **Manual / Scheduled Device Pulling (`php artisan devices:pull-logs`)**:  
   Any newly pulled logs are automatically mirrored to Port 8080.

4. **Fail-Safe Isolation**:  
   Forwarding requests use a 3-second non-blocking timeout and are wrapped in isolated `try / catch` blocks. If the ZKTeco proxy is temporarily restarting, **DTRService operations and local database recording will never be interrupted or slowed down**.

---

## 4. Backfilling / Syncing Existing Punches

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
