# 🏥 ZCMC DTRService & Biometric Synchronization System

**DTRService** is the centralized Daily Time Record (DTR) and Biometric Terminal Management microservice for Zamboanga City Medical Center (ZCMC). It handles real-time attendance logging, multi-device biometric template synchronization, automated self-healing terminal restoration, and DTR computation/reporting.

---

## 🚀 Key Features

* **Multi-Terminal Live Sync**: Automatically broadcasts employee user profiles and enrolled biometric templates (fingerprint/face) to all active connected ZKTeco devices in real time upon registration.
* **Database-Driven Masterlist**: The central database (`biometrics`, `employee_profiles`) is the authoritative source of truth.
* **Self-Healing Protection**: Protects against unauthorized terminal tampering or accidental deletions on physical devices. If an employee or template is deleted directly on a terminal screen (`OPLOG`), the server automatically rejects the deletion and re-provisions the data from the database.
* **Automatic Catch-Up for Offline Devices**: Disconnected or temporarily offline devices automatically receive missing updates via persistent command queues upon reconnecting.
* **Audit & Verification Logging**: Comprehensive logging via Monolog channels (`device_logs`, `registration_logs`) and daily human-readable audit text files (`storage/logs/registration_verified_YYYY-MM-DD.txt`).

---

## 📟 Adding a New Device

For complete, step-by-step instructions on connecting, configuring ADMS cloud settings, database registration, and initial masterlist synchronization for new or replaced biometric terminals, please refer to the dedicated guide:

👉 **[New Biometric Device Setup & Onboarding Guide (NEW_DEVICE_GUIDE.md)](./NEW_DEVICE_GUIDE.md)**

### Quick Sync Command for a New Device:
```bash
# Push full database masterlist (all users & templates) to a new device:
php artisan biometrics:sync-device <DEVICE_SERIAL_NUMBER>
```

---

## 📥 Manual Log Pulling & Recovery

If logs were missed by the server or an offline/misconfigured terminal needs its historical punch records retrieved, you can pull logs directly via SOAP or queue an ADMS push re-send command. For full details and API endpoints, refer to:

👉 **[Manual Biometric Log Pulling & Missed Log Recovery Guide (MANUAL_DEVICE_LOG_PULLING_GUIDE.md)](./MANUAL_DEVICE_LOG_PULLING_GUIDE.md)**

---

## 🛠️ Common Artisan Commands

| Command | Description |
| :--- | :--- |
| `php artisan devices:pull-logs --date=YYYY-MM-DD` | Pull attendance logs directly from all active devices |
| `php artisan devices:pull-logs <ID> --date=YYYY-MM-DD` | Pull attendance logs directly from a specific device |
| `php artisan devices:pull-logs <ID> --resend` | Queue ADMS `DATA QUERY ATTLOG` resend command |
| `php artisan devices:clear-logs --all` | Safely clear device punch memory (Sunday weekly auto-run) |
| `php artisan devices:clear-logs --dry-run` | Simulate device memory clear and test pre-sync verification |
| `php artisan device-logs:prune --years=1` | Prune database punch logs older than 1 year (Monthly auto-run) |
| `php artisan device-logs:prune --dry-run` | Simulate database log pruning and display eligible counts |
| `php artisan biometrics:sync-device <SN>` | Provision full DB masterlist to a specific device |
| `php artisan biometrics:sync-device <SN> --pin=<PIN>` | Provision a specific employee PIN to a device |
| `php artisan biometrics:sync-device --all-devices` | Sync DB masterlist across all active devices |
| `php artisan biometrics:delete-finger <PIN> <FID>` | Delete specific fingerprint across all connected devices |
| `php artisan biometrics:import-from-logs` | Recover and reconstruct dropped templates from raw logs |
| `php artisan dtr:process-records` | Compute daily time records |
| `php artisan test` | Run complete automated test suite |

---

## 🧪 Testing

Run the automated test suite to verify device command queuing, push parser, multi-device relay, and model synchronization:

```bash
php artisan test
```