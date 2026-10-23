# Device Logs API Integration Guide (HRBLIZ Biometric ID Plan)
**ZCMC DTRService — External & Client System Integration**

This document provides complete technical specifications for integrating client and external systems with the **ZCMC DTRService Device Logs API**. 

> [!IMPORTANT]
> **Biometric ID Mapping for this Integration Plan:**
> - **External Client System Identifier:** Use **`hrbliz_biometric_id`** as the employee identifier when submitting logs.
> - **Internal System Identifier:** By default, DTRService maintains canonical **`biometric_id`** across payroll, schedules, and DTR calculation.
> - **Automatic Dual-ID Resolution:** When your system submits **`hrbliz_biometric_id`**, the API automatically looks up the enrolled employee, maps it to their canonical `biometric_id`, auto-resolves the employee name, and records the punch log without requiring manual lookup.
> - **Backward Compatibility:** Standard `biometric_id` is also accepted as a fallback if `hrbliz_biometric_id` is not present.

---

## 1. Overview & Base Configuration

### Base URL
```
http://<your-server-domain-or-ip>/api
```
*Example Local/LAN:* `http://192.168.1.100:8000/api` or `http://localhost:8000/api`

### Common Headers
All requests must specify JSON content type:
```http
Content-Type: application/json
Accept: application/json
```

---

## 2. Data Dictionary & Field Specifications

| Field | Type | Required | Default | Description |
|---|---|:---:|---|---|
| **`hrbliz_biometric_id`** | `string` / `integer` | **Yes**\* | — | **Primary identifier for this plan**: The HRBLIZ employee biometric PIN/ID (e.g. `"8765"`). Auto-mapped to canonical `biometric_id`. |
| `biometric_id` | `string` / `integer` | Optional\* | — | Standard ZCMC biometric ID. Used if `hrbliz_biometric_id` is omitted. |
| `date_time` | `string` | **Yes**\*\* | Current timestamp | Timestamp of punch in `YYYY-MM-DD HH:MM:SS` (e.g. `"2026-10-23 08:00:00"`). |
| `status` | `integer` | No | `0` | Punch state (see Punch Status Reference below). |
| `name` | `string` | No | Auto-resolved | Employee full name. If omitted, the API automatically resolves the employee's name from the `biometrics` table via `hrbliz_biometric_id`. |
| `dtr_date` | `string` | No | Auto-extracted | Date of punch (`YYYY-MM-DD`). Automatically extracted from `date_time` if omitted. |
| `device_name` | `string` | No | `"API"` | Name or identifier of source terminal, kiosk, or external application. |
| `is_Shifting` | `integer` | No | `0` | Set `1` if employee is on a shifting schedule, otherwise `0`. |
| `schedule` | `string` | No | `null` | Optional schedule identifier or metadata. |
| `active` | `integer` | No | `1` | `1` if the employee is currently active; `0` if inactive. |

> \* *Note:* Either `hrbliz_biometric_id` or `biometric_id` is required. For this plan, provide `hrbliz_biometric_id`.  
> \*\* *Tip:* If your system sends `dtr_date` (`"2026-10-23"`) and `dtr_time` (`"08:00:00"`) separately, the API automatically combines them into `date_time`.

### Punch Status (`status`) Reference Codes
| Value | Punch Type | Description |
|:---:|---|---|
| `0` | **Check-In** (Time-In / AM In) | Employee arrival / start of shift |
| `1` | **Check-Out** (Time-Out / PM Out) | Employee departure / end of shift |
| `2` | **Break-Out** (Lunch Out) | Departure for lunch or midday break |
| `3` | **Break-In** (Lunch In) | Return from lunch or midday break |
| `4` | **Overtime-In** (OT In) | Overtime shift start |
| `5` | **Overtime-Out** (OT Out) | Overtime shift completion |

---

## 3. Endpoints

### 3.1. Insert Single Device Log
* **Method:** `POST`
* **Path:** `/api/device-logs`

#### Request Payload (Using `hrbliz_biometric_id`)
```json
{
  "hrbliz_biometric_id": "8765",
  "date_time": "2026-10-23 08:00:00",
  "status": 0,
  "device_name": "HRBLIZ_ENTRANCE_01",
  "prevent_duplicates": true
}
```

#### Query / Control Parameters
- **`prevent_duplicates`** (`boolean`, default: `true`): Prevents duplicate records for the same employee and exact `date_time`.
- **`is_hrbliz`** (`boolean`, default: `false`): Targets the dedicated `device_logs_hrbliz` table when set to `true` (otherwise saves to standard `device_logs`).

#### Response: Created (`201 Created`)
```json
{
  "success": true,
  "message": "Device log created successfully.",
  "data": {
    "id": 14205,
    "biometric_id": "5005",
    "hrbliz_biometric_id": "8765",
    "name": "Basilio Santos",
    "dtr_date": "2026-10-23",
    "date_time": "2026-10-23 08:00:00",
    "status": 0,
    "is_Shifting": 0,
    "schedule": null,
    "active": 1,
    "device_name": "HRBLIZ_ENTRANCE_01",
    "created_at": "2026-10-23T08:00:00.000000Z",
    "updated_at": "2026-10-23T08:00:00.000000Z"
  }
}
```
*Note: Both the canonical `biometric_id` (`"5005"`) and the originating `hrbliz_biometric_id` (`"8765"`) are returned in the response for your convenience.*

#### Response: Duplicate Skipped (`200 OK`)
When `prevent_duplicates=true` and the punch was already recorded:
```json
{
  "success": true,
  "is_duplicate": true,
  "message": "Log already exists in database (duplicate skipped).",
  "data": {
    "id": 14205,
    "biometric_id": "5005",
    "name": "Basilio Santos",
    "dtr_date": "2026-10-23",
    "date_time": "2026-10-23 08:00:00",
    "status": 0,
    "device_name": "HRBLIZ_ENTRANCE_01"
  }
}
```

---

### 3.2. Batch Insert Logs
Use batch insert to sync multiple punches, process offline logs, or execute periodic transfers.

* **Method:** `POST`
* **Path:** `/api/device-logs`

#### Request Payload
```json
{
  "prevent_duplicates": true,
  "logs": [
    {
      "hrbliz_biometric_id": "8765",
      "date_time": "2026-10-23 08:00:00",
      "status": 0,
      "device_name": "HRBLIZ_SYNC_TERMINAL"
    },
    {
      "hrbliz_biometric_id": "8766",
      "date_time": "2026-10-23 08:02:45",
      "status": 0,
      "device_name": "HRBLIZ_SYNC_TERMINAL"
    },
    {
      "hrbliz_biometric_id": "8765",
      "date_time": "2026-10-23 12:01:10",
      "status": 1,
      "device_name": "HRBLIZ_SYNC_TERMINAL"
    }
  ]
}
```

#### Response (`201 Created`)
```json
{
  "success": true,
  "message": "Batch processed: 3 inserted, 0 skipped duplicates out of 3 total.",
  "total_received": 3,
  "inserted_count": 3,
  "skipped_count": 0,
  "data": [
    {
      "id": 14206,
      "biometric_id": "5005",
      "hrbliz_biometric_id": "8765",
      "name": "Basilio Santos",
      "dtr_date": "2026-10-23",
      "date_time": "2026-10-23 08:00:00",
      "status": 0,
      "device_name": "HRBLIZ_SYNC_TERMINAL"
    },
    {
      "id": 14207,
      "biometric_id": "5006",
      "hrbliz_biometric_id": "8766",
      "name": "Crispin Cruz",
      "dtr_date": "2026-10-23",
      "date_time": "2026-10-23 08:02:45",
      "status": 0,
      "device_name": "HRBLIZ_SYNC_TERMINAL"
    },
    {
      "id": 14208,
      "biometric_id": "5005",
      "hrbliz_biometric_id": "8765",
      "name": "Basilio Santos",
      "dtr_date": "2026-10-23",
      "date_time": "2026-10-23 12:01:10",
      "status": 1,
      "device_name": "HRBLIZ_SYNC_TERMINAL"
    }
  ]
}
```

---

### 3.3. Query & Filter Device Logs
* **Method:** `GET`
* **Path:** `/api/device-logs`

#### Supported Query Parameters
| Parameter | Type | Example | Description |
|---|---|---|---|
| **`hrbliz_biometric_id`** | `string` / `integer` | `8765` | Query using HRBLIZ ID (auto-maps to canonical ID) |
| `biometric_id` | `string` / `integer` | `5005` | Query using standard canonical ID |
| `date` | `string` | `2026-10-23` | Specific DTR date (`YYYY-MM-DD`) |
| `date_from` | `string` | `2026-10-01` | Start of date range |
| `date_to` | `string` | `2026-10-23` | End of date range |
| `status` | `integer` | `0` | Filter by punch status |
| `device_name` | `string` | `HRBLIZ` | Match device name |
| `per_page` | `integer` | `25` | Page size (max: 200, default: 20) |
| `page` | `integer` | `1` | Page number |

#### Example Request
```http
GET /api/device-logs?hrbliz_biometric_id=8765&date_from=2026-10-01&date_to=2026-10-23
```

#### Response (`200 OK`)
```json
{
  "success": true,
  "source": "device_logs",
  "data": [
    {
      "id": 14205,
      "biometric_id": "5005",
      "name": "Basilio Santos",
      "dtr_date": "2026-10-23",
      "date_time": "2026-10-23 08:00:00",
      "status": 0,
      "device_name": "HRBLIZ_ENTRANCE_01"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```

---

## 4. Code Examples for Integration (HRBLIZ Biometric ID)

### 4.1. cURL / Bash
```bash
curl -X POST "http://localhost:8000/api/device-logs" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "hrbliz_biometric_id": "8765",
    "date_time": "2026-10-23 08:00:00",
    "status": 0,
    "device_name": "HRBLIZ_KIOSK"
  }'
```

---

### 4.2. PHP (Laravel HTTP Client / Guzzle)
```php
use Illuminate\Support\Facades\Http;

$response = Http::post('http://localhost:8000/api/device-logs', [
    'hrbliz_biometric_id' => '8765',
    'date_time'           => '2026-10-23 08:00:00',
    'status'              => 0, // 0 = Check In
    'device_name'         => 'HRBLIZ_KIOSK',
    'prevent_duplicates'  => true,
]);

if ($response->successful()) {
    $logData = $response->json('data');
    // Canonical ID: $logData['biometric_id']
    // Employee Name: $logData['name']
} else {
    // Errors: $response->json('errors')
}
```

---

### 4.3. JavaScript / TypeScript (fetch)
```javascript
async function sendHrblizLog(hrblizBiometricId, dateTime, status = 0, deviceName = 'HRBLIZ_CLIENT') {
  const response = await fetch('http://localhost:8000/api/device-logs', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify({
      hrbliz_biometric_id: String(hrblizBiometricId),
      date_time: dateTime,
      status: status,
      device_name: deviceName,
      prevent_duplicates: true
    }),
  });

  const result = await response.json();
  if (!response.ok) {
    throw new Error(result.message || 'Failed to submit log');
  }

  return result.data;
}

// Example usage:
sendHrblizLog('8765', '2026-10-23 08:00:00', 0)
  .then(data => console.log('Saved log:', data))
  .catch(err => console.error('Error:', err));
```

---

### 4.4. Python (requests)
```python
import requests

url = "http://localhost:8000/api/device-logs"
headers = {
    "Content-Type": "application/json",
    "Accept": "application/json"
}

payload = {
    "hrbliz_biometric_id": "8765",
    "date_time": "2026-10-23 08:00:00",
    "status": 0,
    "device_name": "PYTHON_HRBLIZ_SYNC",
    "prevent_duplicates": True
}

response = requests.post(url, json=payload, headers=headers)
if response.status_code in [200, 201]:
    print("Success:", response.json()["data"])
else:
    print("Error:", response.status_code, response.json())
```

---

### 4.5. C# (.NET HttpClient)
```csharp
using System;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;

public class DtrIntegration
{
    private static readonly HttpClient client = new HttpClient();

    public static async Task SendLogAsync()
    {
        var log = new
        {
            hrbliz_biometric_id = "8765",
            date_time = "2026-10-23 08:00:00",
            status = 0,
            device_name = "CSHARP_HRBLIZ_SERVICE",
            prevent_duplicates = true
        };

        var json = JsonSerializer.Serialize(log);
        var content = new StringContent(json, Encoding.UTF8, "application/json");

        var response = await client.PostAsync("http://localhost:8000/api/device-logs", content);
        var responseString = await response.Content.ReadAsStringAsync();

        Console.WriteLine(responseString);
    }
}
```

---

## 5. Error Responses & Troubleshooting

| HTTP Status / Error | Meaning | Typical Cause & Remedy |
|:---:|---|---|
| **`Connection Timeout (5000ms)`** | TCP Handshake Failed | The server process is either not running on port 8000, not bound to `--host=0.0.0.0`, or blocked by Windows Firewall on the host machine. (See Troubleshooting below). |
| `422 Unprocessable Entity` | Validation Error | Missing `hrbliz_biometric_id` / `biometric_id` or invalid `date_time` format (`YYYY-MM-DD HH:MM:SS`). |
| `404 Not Found` | Resource Not Found | Log ID does not exist when accessing `/api/device-logs/{id}`. |
| `500 Internal Server Error` | Database or Server Error | Check Laravel log (`storage/logs/laravel.log`) or database connectivity. |

### Resolving "Connection Timeout 5000ms"
If your client says `Connection timeout: 5000ms` or `ETIMEDOUT`:
1. **Ensure the Server is Running on All Interfaces**:
   On the host machine, make sure the service is started with `--host=0.0.0.0`:
   ```bash
   php artisan serve --host=0.0.0.0 --port=8000
   ```
2. **Verify Correct Host IP**:
   If connecting across the local network (LAN), use the host's LAN IP instead of `localhost`:
   ```
   http://192.168.36.49:8000/api/device-logs
   ```
3. **Open Inbound Port 8000 on Windows Firewall**:
   Run this in an elevated PowerShell/Command Prompt on the server:
   ```powershell
   netsh advfirewall firewall add rule name="Laravel DTRService Port 8000" dir=in action=allow protocol=TCP localport=8000
   ```
