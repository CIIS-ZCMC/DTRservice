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
    NOW(),
    NOW()
);
```

> **IMPORTANT:**
> Ensure **`is_active = 1`** and **`serial_number`** matches the terminal's hardware serial number exactly. Only active devices receive live synchronization broadcasts.

---

## 4. Initial Masterlist Synchronization (Provisioning Existing Users)

Because this is a **new device**, past employee enrollments and fingerprint templates were not queued for its serial number when they originally occurred.

Run the Artisan sync command to queue the entire DB masterlist to the new device:

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
php artisan biometrics:sync-device CKFT230860012 --pin=493
```

### Option C: Push Masterlist to All Active Connected Devices
```bash
php artisan biometrics:sync-device --all-devices
```

---

## 5. How Live Sync & Catch-Up Mechanics Work

Once the provisioning command is executed:

1. **Queueing**: The server compiles all user profiles (`DATA USER`) and fingerprint templates (`DATA UPDATE fingertmp`) into `storage/app/device_commands.json`.
2. **Gradual Polling**:
   - The device regularly polls `/iclock/getrequest?SN=<SERIAL_NUMBER>`.
   - The server delivers **10 commands per poll cycle**.
   - The terminal stores each user profile and template in its local memory.
3. **Execution Acknowledgment (`ACK`)**:
   - The device reports success back to `/iclock/devicecmd` (`Return=0`).
   - The server marks the command as `SUCCESS`.
4. **Live Future Updates**:
   - Any time an employee is registered or updated on **any other device** (or in the database), the server **automatically broadcasts** the new profile and templates to this device in real time.

---

## 6. Self-Healing & Terminal-Tampering Protection

The system enforces the **Central Database as the sole Masterlist authority**, not the individual physical terminals:

* **Unauthorized Deletion Protection**:
  If an administrator or user deletes an employee or clears fingerprints directly on the physical terminal screen, the terminal reports an operation log (`OPLOG 2`, `OPLOG 4`, `OPLOG 8`, `OPLOG 9`, `OPLOG 71`).
* **Automated Self-Healing**:
  The server inspects the database masterlist. Because the user still exists in the DB, the server **rejects the terminal deletion** and automatically re-queues the user profile and biometric templates back onto that terminal.
* **Legitimate Deletions**:
  Deletions must be performed from the server/database. When a user or fingerprint is deleted from the DB masterlist (e.g. `php artisan biometrics:delete-finger <pin> <fid>`), the server dispatches `DATA DELETE` commands to all connected devices.

---

## 7. Useful Maintenance & Diagnostic Commands

| Task | Command |
| :--- | :--- |
| **Sync DB to a New Device** | `php artisan biometrics:sync-device <SERIAL_NUMBER>` |
| **Sync Single PIN to Device** | `php artisan biometrics:sync-device <SERIAL_NUMBER> --pin=<PIN>` |
| **Sync DB to ALL Active Devices** | `php artisan biometrics:sync-device --all-devices` |
| **Delete Fingerprint Across All Devices** | `php artisan biometrics:delete-finger <PIN> <FID>` |
| **Recover Templates from Raw Logs** | `php artisan biometrics:import-from-logs --sync-devices` |
| **Run System Test Suite** | `php artisan test` |

** Added --no-clean flag in case you ever want to sync without deleting unenrolled finger slots (php artisan biometrics:sync-device --no-clean

---

## 8. Troubleshooting & Verification

### 1. Verify Device Heartbeat & Connection
* Check the database `devices` table: `last_seen_at` should update within the last 2 minutes when the device is actively polling.
* Check the Web Log Viewer: Navigate to `/logs/alert` to monitor live incoming attendance and device events.

### 2. Verify Command Queue Execution
* Check `storage/app/device_commands.json` to inspect pending, sent, or acknowledged commands.
* Check Monolog device logs in `storage/logs/device_logs.log` or Monolog registration logs in `storage/logs/registration_logs.log`.

### 3. Verify Daily Audit Logs
* View the human-readable daily audit trail located at:
  `storage/logs/registration_verified_YYYY-MM-DD.txt`
  *(Contains timestamps, PINs, employee names, finger IDs, device IPs/SNs, and synced device counts).*
