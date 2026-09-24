# 📟 New Biometric Device Setup & Onboarding Guide

This guide details the step-by-step procedure for connecting, configuring, and synchronizing a **new or replacement ZKTeco biometric device** with the **ZCMC DTRService** system.

---

## 📋 Table of Contents
1. [Prerequisites & Network Setup](#1-prerequisites--network-setup)
2. [Physical Device Configuration (ADMS / Cloud Server Settings)](#2-physical-device-configuration-adms--cloud-server-settings)
3. [Registering the Device in the Database](#3-registering-the-device-in-the-database)
4. [Initial Masterlist Synchronization (Provisioning Existing Users)](#4-initial-masterlist-synchronization-provisioning-existing-users)
5. [How Live Sync & Catch-Up Mechanics Work](#5-how-live-sync--catch-up-mechanics-work)
6. [Self-Healing & Terminal-Tampering Protection](#6-self-healing--terminal-tampering-protection)
7. [Useful Maintenance & Diagnostic Commands](#7-useful-maintenance--diagnostic-commands)
8. [Troubleshooting & Verification](#8-troubleshooting--verification)

---

## 1. Prerequisites & Network Setup

Before adding the new device to the network, ensure:
* The device has a stable static IP or DHCP reservation on the local hospital/office network.
* The device can route HTTP traffic to the DTRService server IP and Port (e.g., `http://<SERVER_IP>:<PORT>/iclock/`).
* Note down the device's **Serial Number (SN)**, located on the device sticker or in `Menu -> System Info -> Device Info`.

---

## 2. Physical Device Configuration (ADMS / Cloud Server Settings)

On the physical ZKTeco terminal keypad/screen:

1. Press **Menu / M/OK** and authenticate as administrator.
2. Navigate to **Comm. (Communication)** -> **Cloud Server Setting** (or **ADMS / Web Server Setting** depending on firmware).
3. Configure the following parameters:

| Setting | Recommended Value | Description |
| :--- | :--- | :--- |
| **Server Address / IP** | `192.168.x.x` (or Server Domain) | IP address or hostname of the DTRService server |
| **Server Port** | `80` (or your Web Server Port, e.g. `8000`) | Web server HTTP port hosting DTRService |
| **Enable Domain Name** | `OFF` (unless using DNS hostname) | Toggle if using IP address |
| **Push / Request Interval** | `5` to `10` seconds | Frequency of polling `/iclock/getrequest` |
| **Push Mode / Protocol** | `ADMS / Push Protocol / IClock` | Standard ZKTeco push communication |

4. Save settings and restart the terminal if prompted.
5. Once connected to the network, the device will initiate its handshake request to `/iclock/cdata`.

---

## 3. Registering the Device in the Database

To enable the server to recognize and broadcast biometric updates to the new device, add its entry to the `devices` database table.

### Via SQL / Database Interface:
```sql
INSERT INTO devices (
    device_name,
    device_id,
    serial_number,
    ip_address,
    com_key,
    soap_port,
    udp_port,
    is_active,
    is_registration,
    for_attendance,
    is_hrbliz,
    receiver_by_default,
    created_at,
    updated_at
) VALUES (
    'OPD Biometrics 1',       -- Descriptive name
    'DEV_OPD_01',             -- Device ID identifier
    'CKFT230860012',          -- Exact Serial Number (case-sensitive)
    '192.168.1.150',          -- Assigned IP address
    0,                        -- Comm Key (default: 0)
    80,                       -- SOAP port (default: 80)
    4370,                     -- UDP port (default: 4370)
    1,                        -- 1 = Active, 0 = Inactive
    0,                        -- 1 if used for registration, 0 for attendance only
    1,                        -- 1 if used for attendance logs
    0,                        -- 1 if HRBLIZ terminal, 0 for Standard terminal
    1,                        -- 1 to receive sync updates, 0 for send-only (attend-only)
    NOW(),
    NOW()
);
```

> **IMPORTANT:**
> - Ensure **`is_active = 1`** and **`serial_number`** matches the terminal's hardware serial number exactly. Only active devices receive live synchronization broadcasts.
> - **HRBLIZ Terminal Configuration:** For HRBLIZ terminals (`is_hrbliz = 1`), set **`receiver_by_default = 0`** if the terminal only captures attendances without receiving user provisioning. Set **`receiver_by_default = 1`** if it should receive biometric synchronization.

---

## 4. Initial Masterlist Synchronization (Provisioning Existing Users)

Because this is a **new device**, past employee enrollments and fingerprint templates were not queued for its serial number when they originally occurred.

Run the Artisan sync command to queue the entire DB masterlist to the new device:

> **🛡️ HRBLIZ Device & `receiver_by_default` Guardrail:**
> - `php artisan biometrics:sync-device` will **NOT** run on any device where `is_hrbliz = 1` and `receiver_by_default = 0`.
> - Synchronization will **only run if `receiver_by_default` is set to `1`**.
> - When generating / throwing biometric commands:
>   - **`is_hrbliz = 1`** $\rightarrow$ strictly uses **`hrbliz_biometric_id`** (`PIN=<hrbliz_biometric_id>`).
>   - **`is_hrbliz = 0`** $\rightarrow$ strictly uses canonical **`biometric_id`** (`PIN=<biometric_id>`).
> - When targeted directly (`php artisan biometrics:sync-device <SERIAL_NUMBER>`), if `receiver_by_default = 0` on an HRBLIZ device, execution will immediately abort with exit code `1`. In `--all-devices` fleet sync, such terminals are automatically skipped.

### Option A: Sync the Full Masterlist (All Active Employees & Fingerprints)
```bash
php artisan biometrics:sync-device <SERIAL_NUMBER>
```
*Example:*
```bash
php artisan biometrics:sync-device CKFT230860012
```

### Option B: Sync a Specific Employee PIN Only
```bash
php artisan biometrics:sync-device <SERIAL_NUMBER> --pin=493
```

### Option C: Push Masterlist to All Active Connected Devices (Full Deep Clean)
By default, this provisions all profiles, enrolled fingerprints, and cleans out unused finger slots (0-9) so no old ghost fingerprints remain:
```bash
php artisan biometrics:sync-device --all-devices
```

### Option D: Fast Masterlist Push to All Devices (Skip Unused Finger Cleaning)
If you want a significantly faster sync (up to 75% fewer commands) that only uploads active enrolled fingerprints without deleting unassigned slots:
```bash
php artisan biometrics:sync-device --all-devices --no-clean
```

---

## 5. How Live Sync & Catch-Up Mechanics Work

Once the provisioning command is executed:

1. **Queueing & Multi-File Rotation**:
   - The server compiles all user profiles (`DATA USER`), fingerprint templates (`DATA UPDATE fingertmp`), and deletion commands into `storage/app/device_commands.json`.
   - **50MB Rotation**: If a file reaches 50MB, it automatically rotates to numbered files (`device_commands_1.json`, `device_commands_2.json`, etc.) while preserving all existing commands.
2. **Gradual Polling**:
   - The device regularly polls `/iclock/getrequest?SN=<SERIAL_NUMBER>`.
   - The server delivers **10 commands per poll cycle**.
   - The terminal stores each user profile and template in its local memory.
3. **Execution Acknowledgment (`ACK`) & In-Place Seeking**:
   - The device reports success back to `/iclock/devicecmd` (`Return=0`).
   - The server marks the command as `SUCCESS` via **in-place byte seeking (`fseek`)** in `< 0.5ms` without rewriting the entire 50MB file, ensuring zero disk I/O bottlenecks and instant responses.
4. **Automatic File Cleanup (Zero Wasted Disk Space)**:
   - Once every command in a file reaches `SUCCESS`, the file is **automatically deleted** from disk.
   - Any file that still has commands with status `PENDING`, `SENT`, or non-success is **retained** until all devices finish acknowledging.
5. **Live Future Updates & Registration Broadcasting**:
   - Any time an employee is registered or updated on **any other device** (or in the database), the server **automatically broadcasts** the new profile and templates to eligible devices in real time.
   - Devices marked `is_hrbliz = 1` will **only** receive live registration broadcasts if `receiver_by_default = 1`, and commands sent to them will strictly use `hrbliz_biometric_id`. Terminals with `receiver_by_default = 0` operate in attend-only mode and are excluded.

---

## 6. Self-Healing & Database as the Source of Truth

The system enforces the **Central Database as the sole Masterlist authority**, not individual physical terminals:

* **Unauthorized Deletion Protection & Self-Healing**:
  If an administrator or user deletes an employee or clears fingerprints directly on the physical terminal screen, the terminal reports an operation log (`OPLOG 2`, `OPLOG 4`, `OPLOG 8`, `OPLOG 9`, `OPLOG 71`). Because the user still exists in the DB, the server **rejects the terminal deletion** and automatically re-queues the user profile and biometric templates back onto that terminal.
* **Cleaning Ghost/Unenrolled Finger Slots**:
  Running `php artisan biometrics:sync-device --all-devices` automatically compares enrolled fingerprints in the DB against slots 0–9, dispatching `DATA DELETE FINGERTMP` commands for unassigned slots so no ghost templates linger on terminals.
* **Purging Orphan Users from Devices**:
  If a user profile exists on physical devices that does not exist in the database (or was created during testing), purge it from all devices using:
  ```bash
  php artisan biometrics:delete-user <PIN> --all-devices
  ```
* **Legitimate Masterlist Deletions**:
  Deletions must be performed from the server/database. When a user or fingerprint is deleted from the DB masterlist (e.g. `php artisan biometrics:delete-finger <pin> <fid>`), the server dispatches `DATA DELETE` commands to all connected devices.

---

## 7. Useful Maintenance & Diagnostic Commands

### Command Quick Reference Table

| Task | Command |
| :--- | :--- |
| **Sync DB to a New Device** | `php artisan biometrics:sync-device <SERIAL_NUMBER>` |
| **Sync Single PIN to Device** | `php artisan biometrics:sync-device <SERIAL_NUMBER> --pin=<PIN>` |
| **Sync DB to ALL Active Devices (Deep Clean)** | `php artisan biometrics:sync-device --all-devices` |
| **Sync DB to ALL Devices (Fast / No-Clean)** | `php artisan biometrics:sync-device --all-devices --no-clean` |
| **Check Live Enrolled Fingers on Device** | `php artisan biometrics:check-device <PIN> <SERIAL_NUMBER>` |
| **Check Live Enrolled Fingers Across ALL Devices** | `php artisan biometrics:check-device <PIN> --all-devices` |
| **Auto-Fix Missing Templates & Ghosts (Single Device)** | `php artisan biometrics:check-device <PIN> <SERIAL_NUMBER> --fix` |
| **Auto-Fix Missing Templates & Ghosts (ALL Devices)** | `php artisan biometrics:check-device <PIN> --all-devices --fix` |
| **Force Auto-Fix (Bypass Confirmation Prompt)** | `php artisan biometrics:check-device <PIN> --all-devices --fix --force` |
| **Inspect & Purge Ghost Fingers on Device** | `php artisan biometrics:check-device <PIN> <SERIAL_NUMBER> --clean` |
| **Inspect & Purge Ghosts Across ALL Devices** | `php artisan biometrics:check-device <PIN> --all-devices --clean` |
| **Detect Identical Templates Matching Other PINs** | `php artisan biometrics:check-device-match <PIN>` |
| **Check Identical Templates on Specific Terminal** | `php artisan biometrics:check-device-match <PIN> <SERIAL_NUMBER>` |
| **Direct Template Comparison Between Two PINs** | `php artisan biometrics:check-device-match <PIN> --compare-pin=<OTHER_PIN>` |
| **Purge Duplicate/Conflicting Slots from Device** | `php artisan biometrics:check-device-match <PIN> --clean` |
| **Database Masterlist Duplicate Search** | `php artisan biometrics:find-duplicates <PIN>` |
| **Check Live Command Queue & Sync Status** | `php artisan biometrics:command-status` |
| **Instantly Stop & Clear Command Queue** | `php artisan biometrics:clear-queue` |
| **Purge User Profile from All Devices** | `php artisan biometrics:delete-user <PIN> --all-devices` |
| **Delete User from Devices AND Database** | `php artisan biometrics:delete-user <PIN> --all-devices --with-db` |
| **Delete Specific Fingerprint Across Devices** | `php artisan biometrics:delete-finger <PIN> <FID>` |
| **Recover Templates from Raw Logs** | `php artisan biometrics:import-from-logs --sync-devices` |
| **Run System Test Suite** | `php artisan test` |

---

### Detailed Command Usage

#### 1. Monitor Queue & Device Execution Status
Inspect real-time counts of Total, Pending, Sent, Synced/Success, and Failed commands:
```bash
# View summary table and latest 50 commands
php artisan biometrics:command-status

# Filter by a specific device serial number
php artisan biometrics:command-status --device=CKFT230860012

# Filter by employee PIN
php artisan biometrics:command-status --pin=1

# Filter by status (PENDING, SENT, SUCCESS, FAILED)
php artisan biometrics:command-status --status=PENDING --limit=100
```

#### 2. Stop and Clear the Queue Instantly
If a large sync is running and you need to stop devices from executing commands immediately:
```bash
php artisan biometrics:clear-queue
```
*Deletes all numbered queue files, clears in-memory caches, and resets `device_commands.json` to 0 commands. Connected devices immediately stop executing on their next poll.*

#### 3. Delete a User Profile from Devices (Orphan Cleanup)
```bash
# Delete user from ALL connected devices:
php artisan biometrics:delete-user 99499 --all-devices

# Delete user from a specific device only:
php artisan biometrics:delete-user 99499 CKFT230860012

# Delete from devices AND remove record from the database:
php artisan biometrics:delete-user 99499 --all-devices --with-db
```

#### 4. Delete a Specific Fingerprint Template
```bash
# Delete finger ID 2 (0-9) for PIN 493 across all devices and database:
php artisan biometrics:delete-finger 493 2
```

#### 5. Inspect Live Registered Fingerprints on Physical Device(s) & Auto-Fix
Connects directly to the terminal's hardware memory via TAD/SOAP and inspects slots 0–9, comparing them with the database:
```bash
# Check a specific terminal for employee PIN 493:
php artisan biometrics:check-device 493 UCR6254000009

# Check across ALL active registered terminals:
php artisan biometrics:check-device 493 --all-devices

# Auto-Fix: Push missing enrolled templates (e.g. Slot 3) & purge ghost slots across ALL devices:
php artisan biometrics:check-device 493 --all-devices --fix

# Auto-Fix for a specific terminal only:
php artisan biometrics:check-device 493 UCR6254000009 --fix

# Force auto-fix without confirmation prompt:
php artisan biometrics:check-device 493 --all-devices --fix --force

# Inspect and automatically purge all detected ghost slots:
php artisan biometrics:check-device 493 --all-devices --clean

# Force purge ghosts without confirmation prompt:
php artisan biometrics:check-device 493 --all-devices --clean --force
```

#### 6. Inspect Device & Database for Identical Template Duplicates Across PINs
Connects directly to physical terminals in real-time to extract raw enrolled fingerprint templates (slots 0–9) and checks if any template is **100% identical to another employee's PIN** (e.g. **PIN 1162 slot 9** matching **PIN 8084**):

```bash
# Inspect PIN 1162 across all active devices and detect matches with any other PIN:
php artisan biometrics:check-device-match 1162

# Inspect a specific physical device:
php artisan biometrics:check-device-match 1162 UCR6254000009

# Compare directly between two specific PINs (e.g. PIN 1162 vs PIN 8084) on devices and DB:
php artisan biometrics:check-device-match 1162 --compare-pin=8084

# Automatically purge detected conflicting duplicate/ghost slots from physical devices:
php artisan biometrics:check-device-match 1162 --clean

# Force clean without confirmation prompt:
php artisan biometrics:check-device-match 1162 --clean --force

# Inspect database templates only (fast mode, skips physical terminal network queries):
php artisan biometrics:check-device-match 1162 --db-only

# Output results in structured JSON format:
php artisan biometrics:check-device-match 1162 --json
```

> **Why Identical Fingerprint Templates Across PINs Must Be Resolved:**  
> When a biometric terminal has identical fingerprint templates registered under two different PINs (e.g. PIN 1162 and PIN 8084), the terminal's 1:N recognition algorithm cannot distinguish between the two employees. This causes punches to be misattributed to the wrong employee or discarded, leading to missing attendance logs. Clean conflicting slots using the `--clean` flag or permanently delete via `php artisan biometrics:delete-finger <PIN> <FID>`.

---

## 8. Troubleshooting & Verification

### 1. Device Globe Icon Shows an `X` (Disconnected)
* **Web Server Not Running**: Verify `php artisan serve --host=0.0.0.0 --port=8000` (or Apache/IIS) is active and listening on the server port.
* **Network Connectivity**: Ping the device IP from the server to verify local routing.
* **Server Port / IP**: In the physical terminal menu (`Comm. -> Cloud Server`), verify the Server IP and Port match your server.
* Once the server responds to `/iclock/cdata` with HTTP `200 OK`, the `X` on the globe icon disappears automatically within 15–30 seconds.

### 2. Verify Command Queue Execution
* Run `php artisan biometrics:command-status` to inspect live progress.
* Check Monolog device logs: `storage/logs/device_logs.log`
* Check Monolog registration logs: `storage/logs/registration_logs.log`

### 3. Verify Real-Time Audit Logs
* **Device ACK Confirmations**:
  `storage/logs/sync_ack_YYYY-MM-DD.txt`
  *(Shows exact timestamp, return code, command ID, PIN, employee name, device name/SN, and command type executed by the terminal).*
* **Push Sync Dispatches**:
  `storage/logs/sync_pushed_YYYY-MM-DD.txt`
  *(Logs each push event dispatched to terminals).*
* **User Profile & Template Verifications**:
  `storage/logs/registration_verified_YYYY-MM-DD.txt`
  *(Contains employee profiles and fingerprint templates verified and stored in the database).*
