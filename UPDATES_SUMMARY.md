# 📄 ZCMC DTRService — System Updates & Release Summary

**Project:** ZCMC Daily Time Record & Biometric Service (`DTRService`)  
**Date:** August 2026  
**Document Version:** 1.0  

---

## 📌 Executive Summary

Recent updates to the **ZCMC DTRService** introduce centralized biometric template management, automated multi-terminal synchronization, terminal self-healing mechanisms, streamlined onboarding for new devices, performance optimizations, and enhanced schedule resolution for both regular and external hospital personnel.

---

## 🚀 Key Updates & Enhancements

### 1. 🔄 Real-Time Biometric Template Relay & Synchronization
* **Automatic Multi-Terminal Broadcasting:** Enrolling or updating an employee's fingerprint on any registration terminal automatically propagates and syncs the user profile and biometric templates to all active biometric terminals across the hospital network.
* **Persistent Template Storage:** Biometric templates (fingerprint data) are parsed and stored in the central database (`biometrics` table), preventing template loss when devices are reset.
* **Offline Catch-Up Queue:** Terminals that are temporarily disconnected or powered off will automatically receive pending updates sequentially once reconnected via the ADMS push protocol (`/iclock/getrequest`).

### 2. 🛡️ Self-Healing & Terminal-Tampering Protection
* **Central Database as Master Authority:** Prevents accidental or unauthorized employee/fingerprint deletions performed directly on terminal keypads/screens.
* **Automated Rollback/Restoration:** When a terminal reports a local deletion operation log (`OPLOG`), the server checks the database masterlist. If the employee remains active in the DB, the server automatically re-queues and restores the user and fingerprint templates back onto that terminal.
* **Controlled Server Deletions:** Deletions are securely executed from the server/database, dispatching clean removal commands (`DATA DELETE`) to all connected devices.

### 3. 📟 New Device Bootstrapping & Provisioning
* **Full-Masterlist Sync Command:** Easily provision a newly installed or replacement biometric terminal with all existing active employees and biometric templates using a single command:
  ```bash
  php artisan biometrics:sync-device <SERIAL_NUMBER>
  ```
* **Comprehensive Onboarding Documentation:** Detailed setup instructions for network configuration, ADMS cloud server settings, and database registration are documented in [NEW_DEVICE_GUIDE.md](./NEW_DEVICE_GUIDE.md).

### 4. ⚡ Performance & Memory Optimization
* **Command Deduplication:** Prevents redundant template sync jobs from flooding device command queues.
* **Batch Processing:** Implemented chunked queue processing and streamlined JSON storage (`storage/app/device_commands.json`), minimizing memory footprint during bulk synchronizations.
* **Faster Acknowledgment Handling:** Accelerated ACK processing for `/iclock/devicecmd` responses.

### 5. 📅 Employee Schedule Resolution & DTR Calculations
* **External Employee Support:** Added schedule resolution fallback for external employees (`ExternalEmployee` and `ExternalSchedule`).
* **Soft-Delete Filtering:** Ensured all schedule and employee profile queries strictly respect `deleted_at IS NULL` and deactivation states.
* **Cross-Midnight Shift Computation:** Resolved calculation issues for night/graveyard shifts that span across midnight.
* **Leave Type Display:** Integrated approved leave types directly into DTR reporting and view templates.

### 6. 📊 Monitoring, Alerting & Audit Logging
* **Enhanced Log Viewer & Alert Dashboard:** Real-time visibility into attendance logs, device statuses, and synchronization events at `/logs/alert`.
* **Printable DTR Logs:** Dedicated print layout for device attendance logs.
* **Daily Verification Audit Trail:** Automated daily human-readable audit text files generated at `storage/logs/registration_verified_YYYY-MM-DD.txt`, recording PINs, names, finger IDs, device serial numbers, and synced terminal counts.

### 7. 🗑️ Biometric Device Attendance Logs Deletion & Weekly Maintenance Schedule
* **Weekly Automated Maintenance:** Runs every Sunday at 23:55 to safely purge physical terminal attendance buffers (`ATTLOG`), keeping device performance responsive and preventing memory full alarms.
* **Zero-Data-Loss Pre-Sync Gate:** Before executing `CLEAR LOG` or direct SOAP `ClearData(1)`, the system automatically pulls all punches from the terminal into the MySQL database (`device_logs`). Deletion is aborted if pre-sync cannot be verified.
* **Offline Device Protection:** Disconnected or unreachable devices are safely skipped—no clear commands are queued, protecting offline punches in hardware flash memory.
* **Daily Reconnection Catch-Up:** A daily catch-up job runs at 12:00 PM to safely sync and clear reconnected terminals that missed Sunday's run.
* **Preserved Biometric Data:** Clearing terminal attendance logs only purges the punch buffer. Registered users, fingerprints, face templates, and device IP configurations remain 100% intact.

### 8. 📦 Database Log Retention & 1-Year Cold Archiving
* **1-Year Retention Policy:** Automated monthly pruning of raw punch records in `device_logs` older than 1 year (default cutoff: 365 days).
* **Strict 1-Year Safety Guardrail:** The system enforces that logs can **only** be cleared if they are 1 year before today or older (`dtr_date <= now() - 1 year`). Any request attempting to prune logs newer than 1 year is rejected.
* **Zero Disruption to Live Ingestion:** Deletes in safe chunks of 2,000 IDs with 10ms pacing to eliminate MySQL table lock contention and prevent blocking `/iclock/cdata`.
* **Automated Compressed Archive:** Pruned records are streamed to `.json.gz` files in `storage/app/archive/` before removal, cutting storage footprint by 90%+ while preserving permanent historical audit trails.
* **DTR Tables Untouched:** All computed attendance entries in the `dtr` table are permanently preserved.

---

## 🛠️ Quick Reference: Management Commands

| Task | Command |
| :--- | :--- |
| **Safely Clear All Devices (Weekly Auto)** | `php artisan devices:clear-logs --all --force` |
| **Simulate Clear (Dry-Run / Test Sync)** | `php artisan devices:clear-logs --dry-run` |
| **Clear Specific Device by ID** | `php artisan devices:clear-logs <DEVICE_ID>` |
| **Catch-Up Offline Devices ($\ge 7$ Days)** | `php artisan devices:clear-logs --all --catch-up --older-than=7 --force` |
| **Prune DB Logs > 1 Year (Monthly Auto)** | `php artisan device-logs:prune --years=1 --archive --force` |
| **Simulate DB Prune (Dry-Run / Counts)** | `php artisan device-logs:prune --dry-run` |
| **Sync Full DB to New Device** | `php artisan biometrics:sync-device <SERIAL_NUMBER>` |
| **Sync Single Employee PIN to Device** | `php artisan biometrics:sync-device <SERIAL_NUMBER> --pin=<PIN>` |
| **Broadcast Masterlist to ALL Devices** | `php artisan biometrics:sync-device --all-devices` |
| **Delete Specific Fingerprint Globally** | `php artisan biometrics:delete-finger <PIN> <FID>` |
| **Reconstruct Templates from Logs** | `php artisan biometrics:import-from-logs --sync-devices` |
| **Run Full Test Suite** | `php artisan test` |

---

## 📁 Key Modified Files & Components

* **Controllers & Push Endpoints:**
  * `app/Http/Controllers/DeviceController.php` — ADMS handshake, push parser, live relay, and command dispatch.
  * `app/Http/Controllers/DeviceLogAlertController.php` — Real-time attendance monitoring and alert feeds.
* **Core Synchronization Services:**
  * `app/Services/BiometricSyncService.php` — Biometric relay, self-healing logic, and template compilation.
  * `app/Services/DeviceCommandService.php` — Device queue management, chunking, and deduplication.
  * `app/Services/RegistrationLogger.php` — Daily human-readable audit trail generation.
* **Repositories & Models:**
  * `app/Repositories/ScheduleRepository.php` — Schedule resolution with external employee support.
  * `app/Repositories/DtrReportRepository.php` — DTR calculations, leaves, and midnight cross handling.
  * `app/Models/Biometrics.php` — Biometric template persistence and model event hooks.
* **Documentation & Guides:**
  * `NEW_DEVICE_GUIDE.md` — Setup and onboarding guide for new terminals.
