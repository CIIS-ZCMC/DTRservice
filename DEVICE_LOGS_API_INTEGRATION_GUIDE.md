# Device Logs API Integration Guide
**ZCMC DTRService — External & Client System Integration**

This document provides complete instructions for developers and external systems integrating with the **DTRService Device Logs API**. Use this API to submit attendance punches, sync physical biometric logs, push self-service time recordings, or query historical punch logs.

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
| `biometric_id` | `string` / `integer` | **Yes** | — | Unique employee PIN or biometric identifier (e.g. `"1001"`). |
| `date_time` | `string` | **Yes**\* | Current timestamp | Log timestamp in format `YYYY-MM-DD HH:MM:SS` (e.g. `"2026-10-23 08:00:00"`). |
| `status` | `integer` | No | `0` | Punch status state (see Status Reference below). |
| `name` | `string` | No | Auto-resolved | Employee full name. If omitted or null, the API automatically resolves the name from the `biometrics` table. |
| `dtr_date` | `string` | No | Auto-extracted | Work date (`YYYY-MM-DD`). Automatically extracted from `date_time` if omitted. |
| `device_name` | `string` | No | `"API"` | Name or identifier of the source terminal, kiosk, or external application. |
| `is_Shifting` | `integer` | No | `0` | Set `1` if employee is on a shifting schedule, otherwise `0`. |
| `schedule` | `string` | No | `null` | Optional schedule identifier or JSON metadata. |
| `active` | `integer` | No | `1` | `1` if the employee is currently active; `0` if inactive. |

> \* *Tip:* If your system sends `dtr_date` (`"2026-10-23"`) and `dtr_time` (`"08:00:00"`) separately, the API will automatically combine them into a valid `date_time`.

### Punch Status (`status`) Reference Codes
| Value | Punch Type | Notes |
|:---:|---|---|
| `0` | **Check-In** (Time-In / AM In) | Standard arrival punch |
| `1` | **Check-Out** (Time-Out / PM Out) | Standard departure punch |
| `2` | **Break-Out** (Lunch Out) | Departure for lunch or midday break |
| `3` | **Break-In** (Lunch In) | Return from lunch or midday break |
| `4` | **Overtime-In** (OT In) | Overtime shift start |
| `5` | **Overtime-Out** (OT Out) | Overtime shift completion |

---

## 3. Endpoints

### 3.1. Insert Single Device Log
* **Method:** `POST`
* **Path:** `/api/device-logs`

#### Request Payload
```json
{
  "biometric_id": "1001",
  "date_time": "2026-10-23 08:00:00",
  "status": 0,
  "name": "Juan Dela Cruz",
  "device_name": "GATE_ENTRANCE_01",
  "prevent_duplicates": true
}
```

#### Query / Control Parameters
- **`prevent_duplicates`** (`boolean`, default: `true`): When true, skips inserting if a record with the same `biometric_id` and exact `date_time` already exists.
- **`is_hrbliz`** (`boolean`, default: `false`): Targets the dedicated `device_logs_hrbliz` table when set to `true`.

#### Response: Created (`201 Created`)
```json
{
  "success": true,
  "message": "Device log created successfully.",
  "data": {
    "id": 14205,
    "biometric_id": "1001",
    "name": "Juan Dela Cruz",
    "dtr_date": "2026-10-23",
    "date_time": "2026-10-23 08:00:00",
    "status": 0,
    "is_Shifting": 0,
    "schedule": null,
    "active": 1,
    "device_name": "GATE_ENTRANCE_01",
    "created_at": "2026-10-23T08:00:00.000000Z",
    "updated_at": "2026-10-23T08:00:00.000000Z"
  }
}
```

#### Response: Duplicate Skipped (`200 OK`)
When `prevent_duplicates=true` and the record already exists:
```json
{
  "success": true,
  "is_duplicate": true,
  "message": "Log already exists in database (duplicate skipped).",
  "data": {
    "id": 14205,
    "biometric_id": "1001",
    "name": "Juan Dela Cruz",
    "dtr_date": "2026-10-23",
    "date_time": "2026-10-23 08:00:00",
    "status": 0,
    "device_name": "GATE_ENTRANCE_01"
  }
}
```

---

### 3.2. Batch Insert Logs
For syncing offline punches, bulk uploads, or periodic device dumps:

* **Method:** `POST`
* **Path:** `/api/device-logs`

#### Request Payload
```json
{
  "prevent_duplicates": true,
  "logs": [
    {
      "biometric_id": "1001",
      "date_time": "2026-10-23 08:00:00",
      "status": 0,
      "device_name": "OFFLINE_SYNC_KIOSK"
    },
    {
      "biometric_id": "1002",
      "date_time": "2026-10-23 08:03:12",
      "status": 0,
      "device_name": "OFFLINE_SYNC_KIOSK"
    },
    {
      "biometric_id": "1001",
      "date_time": "2026-10-23 12:00:15",
      "status": 1,
      "device_name": "OFFLINE_SYNC_KIOSK"
    }
  ]
}
```
*(Top-level JSON arrays `[ {...}, {...} ]` are also accepted)*

#### Response: Batch Created (`201 Created`)
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
      "biometric_id": "1001",
      "name": "Juan Dela Cruz",
      "dtr_date": "2026-10-23",
      "date_time": "2026-10-23 08:00:00",
      "status": 0,
      "device_name": "OFFLINE_SYNC_KIOSK"
    },
    {
      "id": 14207,
      "biometric_id": "1002",
      "name": "Maria Santos",
      "dtr_date": "2026-10-23",
      "date_time": "2026-10-23 08:03:12",
      "status": 0,
      "device_name": "OFFLINE_SYNC_KIOSK"
    },
    {
      "id": 14208,
      "biometric_id": "1001",
      "name": "Juan Dela Cruz",
      "dtr_date": "2026-10-23",
      "date_time": "2026-10-23 12:00:15",
      "status": 1,
      "device_name": "OFFLINE_SYNC_KIOSK"
    }
  ]
}
```

---

### 3.3. Query & List Device Logs
* **Method:** `GET`
* **Path:** `/api/device-logs`

#### Supported Query Parameters
| Parameter | Type | Example | Description |
|---|---|---|---|
| `biometric_id` | `string` / `integer` | `1001` | Filter by employee PIN |
| `date` | `string` | `2026-10-23` | Specific DTR date (`YYYY-MM-DD`) |
| `date_from` | `string` | `2026-10-01` | Inclusive start date |
| `date_to` | `string` | `2026-10-23` | Inclusive end date |
| `status` | `integer` | `0` | Filter by punch status |
| `device_name` | `string` | `ENTRANCE` | Partial match on device name |
| `per_page` | `integer` | `50` | Pagination page size (max: 200, default: 20) |
| `page` | `integer` | `1` | Page number |

#### Example Request
```http
GET /api/device-logs?biometric_id=1001&date_from=2026-10-01&date_to=2026-10-23&per_page=15
```

#### Response (`200 OK`)
```json
{
  "success": true,
  "source": "device_logs",
  "data": [
    {
      "id": 14205,
      "biometric_id": "1001",
      "name": "Juan Dela Cruz",
      "dtr_date": "2026-10-23",
      "date_time": "2026-10-23 08:00:00",
      "status": 0,
      "is_Shifting": 0,
      "schedule": null,
      "active": 1,
      "device_name": "GATE_ENTRANCE_01",
      "created_at": "2026-10-23T08:00:00.000000Z",
      "updated_at": "2026-10-23T08:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 1,
    "last_page": 1
  }
}
```

---

### 3.4. Retrieve Single Log by ID
* **Method:** `GET`
* **Path:** `/api/device-logs/{id}`

#### Example Request
```http
GET /api/device-logs/14205
```

#### Response (`200 OK`)
```json
{
  "success": true,
  "data": {
    "id": 14205,
    "biometric_id": "1001",
    "name": "Juan Dela Cruz",
    "dtr_date": "2026-10-23",
    "date_time": "2026-10-23 08:00:00",
    "status": 0,
    "is_Shifting": 0,
    "schedule": null,
    "active": 1,
    "device_name": "GATE_ENTRANCE_01"
  }
}
```

---

## 4. Code Examples for Integration

### 4.1. cURL / Bash
```bash
curl -X POST "http://localhost:8000/api/device-logs" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "biometric_id": "1001",
    "date_time": "2026-10-23 08:00:00",
    "status": 0,
    "device_name": "TERMINAL_A"
  }'
```

---

### 4.2. PHP (Laravel HTTP Client / Guzzle)
```php
use Illuminate\Support\Facades\Http;

$response = Http::post('http://localhost:8000/api/device-logs', [
    'biometric_id' => '1001',
    'date_time'    => '2026-10-23 08:00:00',
    'status'       => 0, // 0 = Check In
    'device_name'  => 'HR_KIOSK',
]);

if ($response->successful()) {
    $log = $response->json('data');
    // Log ID: $log['id']
} else {
    // Handle error: $response->json('errors')
}
```

---

### 4.3. JavaScript / TypeScript (fetch)
```javascript
async function sendDeviceLog(biometricId, dateTime, status = 0, deviceName = 'WEB_KIOSK') {
  const response = await fetch('http://localhost:8000/api/device-logs', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify({
      biometric_id: biometricId,
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
sendDeviceLog('1001', '2026-10-23 08:00:00', 0)
  .then(data => console.log('Log saved:', data))
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
    "biometric_id": "1001",
    "date_time": "2026-10-23 08:00:00",
    "status": 0,
    "device_name": "PYTHON_SYNC_SERVICE"
}

response = requests.post(url, json=payload, headers=headers)
if response.status_code in [200, 201]:
    print("Log saved successfully:", response.json()["data"])
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
            biometric_id = "1001",
            date_time = "2026-10-23 08:00:00",
            status = 0,
            device_name = "CSHARP_SERVICE"
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

| HTTP Status | Meaning | Typical Cause & Remedy |
|:---:|---|---|
| `422 Unprocessable Entity` | Validation Error | Missing `biometric_id` or invalid `date_time` format (expected `YYYY-MM-DD HH:MM:SS`). |
| `404 Not Found` | Resource Not Found | Log ID does not exist when accessing `/api/device-logs/{id}`. |
| `500 Internal Server Error` | Database or Server Error | Check Laravel log (`storage/logs/laravel.log`) or database connectivity. |

#### Example 422 Validation Error Response
```json
{
  "success": false,
  "message": "Validation failed for one or more device log records.",
  "errors": {
    "logs.0": [
      "The biometric id field is required."
    ]
  }
}
```

---

## 6. Best Practices for Device Integrators

1. **Always Set `prevent_duplicates: true`**:
   Biometric devices often retry unacknowledged packets. Enabling duplicate prevention prevents double-punching when an identical timestamp is re-transmitted.
2. **Batch Syncing Offline Punches**:
   If a network drop occurs, accumulate logs locally and post them using the batch `logs: [...]` endpoint once connection restores. All batch items are committed atomically in a single database transaction.
3. **Automated Name Resolution**:
   You do not need to look up or store the employee's name locally. If omitted, the DTRService will automatically match the `biometric_id` to the enrolled name in the ZCMC database.
