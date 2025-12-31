# Synchronization Log API Documentation

## Overview
The Synchronization Log API provides comprehensive endpoints to retrieve, filter, and analyze synchronization records from the `syn_logs` table.

---

## Endpoints

### 1. List Synchronization Logs (Default)
**Endpoint:** `GET /synchronize_log.php`

**Description:** Retrieves a paginated list of all synchronization logs with advanced filtering, sorting, and statistics.

#### Required Headers:
```
X-DB-Host: localhost (optional, defaults to localhost)
X-DB-User: your_db_user
X-DB-Pass: your_db_password
X-DB-Name: your_db_name
X-DB-Port: 3306 (optional, defaults to 3306)
```

#### Query Parameters:
| Parameter | Type | Description | Example |
|-----------|------|-------------|---------|
| `page` | integer | Page number (default: 1) | `page=1` |
| `per_page` | integer | Records per page (1-100, default: 50) | `per_page=25` |
| `user_id` | integer | Filter by user ID | `user_id=5` |
| `table_name` | string | Filter by table name | `table_name=products` |
| `module` | string | Filter by module | `module=inventory` |
| `action` | string | Filter by action | `action=INSERT` |
| `sync_status` | string | Filter by sync status ('synced' or 'pending') | `sync_status=pending` |
| `start_date` | date | Filter records created from this date (YYYY-MM-DD) | `start_date=2025-12-01` |
| `end_date` | date | Filter records created until this date (YYYY-MM-DD) | `end_date=2025-12-31` |
| `synced_start_date` | date | Filter synced records from this date (YYYY-MM-DD) | `synced_start_date=2025-12-01` |
| `synced_end_date` | date | Filter synced records until this date (YYYY-MM-DD) | `synced_end_date=2025-12-31` |
| `search` | string | Search in table_name, module, action, user name/email | `search=inventory` |

#### Example Request:
```bash
curl -X GET "http://localhost/jpos2xapi/synchronize_log.php?page=1&per_page=20&sync_status=pending" \
  -H "X-DB-User: root" \
  -H "X-DB-Pass: password" \
  -H "X-DB-Name: pos_v2"
```

#### Response Structure:
```json
{
  "success": true,
  "message": "Synchronization logs retrieved successfully",
  "data": {
    "filters_applied": {
      "user_id": null,
      "table_name": null,
      "module": null,
      "action": null,
      "sync_status": "pending",
      "start_date": null,
      "end_date": null,
      "synced_start_date": null,
      "synced_end_date": null,
      "page": 1,
      "per_page": 20,
      "search": null
    },
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total_items": 150,
      "total_pages": 8,
      "has_next_page": true,
      "has_prev_page": false
    },
    "summary": {
      "total_records": 150,
      "synced_count": 120,
      "pending_count": 30,
      "sync_rate": 80.0,
      "date_range": {
        "first_record": "2025-12-01 08:00:00",
        "last_record": "2025-12-31 20:30:00",
        "first_synced": "2025-12-01 08:05:00",
        "last_synced": "2025-12-31 20:35:00"
      }
    },
    "top_tables": [
      {
        "table_name": "products",
        "count": 45
      },
      {
        "table_name": "sales",
        "count": 38
      }
    ],
    "top_modules": [
      {
        "module": "inventory",
        "count": 62
      },
      {
        "module": "sales",
        "count": 55
      }
    ],
    "top_users": [
      {
        "user": {
          "id": 1,
          "name": "John Doe",
          "email": "john@example.com"
        },
        "sync_count": 45
      }
    ],
    "sync_logs": [
      {
        "id": 1,
        "user": {
          "id": 1,
          "name": "John Doe",
          "email": "john@example.com"
        },
        "table_name": "products",
        "module": "inventory",
        "action": "INSERT",
        "sync_status": "pending",
        "synced_at": null,
        "created_at": "2025-12-31 10:00:00",
        "updated_at": "2025-12-31 10:00:00",
        "formatted_created_at": "2025-12-31 10:00:00",
        "formatted_synced_at": null,
        "sync_duration": null
      }
    ]
  },
  "meta": {
    "available_tables": ["products", "sales", "customers"],
    "available_modules": ["inventory", "sales", "purchases"],
    "available_actions": ["INSERT", "UPDATE", "DELETE"],
    "all_users": [
      {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com",
        "role": 1
      }
    ]
  }
}
```

---

### 2. Get Full Details of Single Log Entry
**Endpoint:** `GET /synchronize_log.php?action=details&id={log_id}`

**Description:** Retrieves complete details of a single synchronization log record with comprehensive information.

#### Required Headers:
```
X-DB-User: your_db_user
X-DB-Pass: your_db_password
X-DB-Name: your_db_name
```

#### Query Parameters:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | Must be set to `details` |
| `id` | integer | Yes | The ID of the sync log record to retrieve |

#### Example Request:
```bash
curl -X GET "http://localhost/jpos2xapi/synchronize_log.php?action=details&id=1" \
  -H "X-DB-User: root" \
  -H "X-DB-Pass: password" \
  -H "X-DB-Name: pos_v2"
```

#### Response Structure:
```json
{
  "success": true,
  "message": "Full details of synchronization log retrieved successfully",
  "data": {
    "id": 1,
    "table_name": "products",
    "module": "inventory",
    "action": "INSERT",
    "sync_status": "synced",
    "user": {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "role": 1
    },
    "timestamps": {
      "created_at": "2025-12-31 10:00:00",
      "created_at_formatted": "2025-12-31 10:00:00",
      "synced_at": "2025-12-31 10:00:05",
      "synced_at_formatted": "2025-12-31 10:00:05",
      "updated_at": "2025-12-31 10:00:05",
      "updated_at_formatted": "2025-12-31 10:00:05"
    },
    "sync_details": {
      "sync_duration_seconds": 5,
      "sync_duration_formatted": "5 seconds",
      "is_synced": true
    }
  }
}
```

---

### 3. Get User by Username, Email, or ID
**Endpoint:** `GET /synchronize_log.php?action=get-user`

**Description:** Retrieve user information by username, email, or user ID.

#### Required Headers:
```
X-DB-User: your_db_user
X-DB-Pass: your_db_password
X-DB-Name: your_db_name
```

#### Query Parameters:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | Must be set to `get-user` |
| `user_id` | integer | No | Search by user ID |
| `username` | string | No | Search by username (exact match) |
| `email` | string | No | Search by email (exact match) |

**Note:** At least one of `user_id`, `username`, or `email` must be provided.

#### Example Requests:

**Get user by ID:**
```bash
curl -X GET "http://localhost/jpos2xapi/synchronize_log.php?action=get-user&user_id=1" \
  -H "X-DB-User: root" \
  -H "X-DB-Pass: password" \
  -H "X-DB-Name: pos_v2"
```

**Get user by username:**
```bash
curl -X GET "http://localhost/jpos2xapi/synchronize_log.php?action=get-user&username=Admin" \
  -H "X-DB-User: root" \
  -H "X-DB-Pass: password" \
  -H "X-DB-Name: pos_v2"
```

**Get user by email:**
```bash
curl -X GET "http://localhost/jpos2xapi/synchronize_log.php?action=get-user&email=admin@gmail.com" \
  -H "X-DB-User: root" \
  -H "X-DB-Pass: password" \
  -H "X-DB-Name: pos_v2"
```

#### Response Structure (Single User):
```json
{
  "success": true,
  "message": "User found",
  "data": {
    "id": 1,
    "name": "Admin",
    "email": "admin@gmail.com",
    "role": 0,
    "role_label": "Admin",
    "email_verified": false,
    "created_at": "2025-12-23 06:27:33",
    "updated_at": "2025-12-23 06:27:33"
  },
  "count": 1
}
```

---

### 4. Get All Users
**Endpoint:** `GET /synchronize_log.php?action=all-users`

**Description:** Retrieve a complete list of all users in the system with their roles and details.

#### Required Headers:
```
X-DB-User: your_db_user
X-DB-Pass: your_db_password
X-DB-Name: your_db_name
```

#### Example Request:
```bash
curl -X GET "http://localhost/jpos2xapi/synchronize_log.php?action=all-users" \
  -H "X-DB-User: root" \
  -H "X-DB-Pass: password" \
  -H "X-DB-Name: pos_v2"
```

#### Response Structure:
```json
{
  "success": true,
  "message": "All users retrieved successfully",
  "data": [
    {
      "id": 1,
      "name": "Admin",
      "email": "admin@gmail.com",
      "role": 0,
      "role_label": "Admin",
      "email_verified": false,
      "created_at": "2025-12-23 06:27:33",
      "updated_at": "2025-12-23 06:27:33"
    },
    {
      "id": 2,
      "name": "Manager",
      "email": "manager@gmail.com",
      "role": 1,
      "role_label": "Manager",
      "email_verified": false,
      "created_at": "2025-12-23 06:27:33",
      "updated_at": "2025-12-24 08:51:43"
    },
    {
      "id": 3,
      "name": "Cashier",
      "email": "cashier@gmail.com",
      "role": 2,
      "role_label": "Cashier",
      "email_verified": false,
      "created_at": "2025-12-23 06:27:33",
      "updated_at": "2025-12-23 10:41:12"
    },
    {
      "id": 4,
      "name": "Stock-Keeper",
      "email": "stockkeeper@gmail.com",
      "role": 3,
      "role_label": "Stock-Keeper",
      "email_verified": false,
      "created_at": "2025-12-23 06:27:34",
      "updated_at": "2025-12-23 06:27:34"
    }
  ],
  "total_users": 4,
  "role_definitions": {
    "0": "Admin",
    "1": "Manager",
    "2": "Cashier",
    "3": "Stock-Keeper"
  }
}
```

---

## Error Responses

### Invalid Request Method
```json
{
  "error": true,
  "status_code": 405,
  "message": "Only GET method is allowed"
}
```

### Missing Database Configuration
```json
{
  "error": true,
  "status_code": 400,
  "message": "Database configuration incomplete. Required: X-DB-User and X-DB-Name"
}
```

### Database Connection Failed
```json
{
  "error": true,
  "status_code": 500,
  "message": "Database connection failed: [error details]"
}
```

### Record Not Found (Details Endpoint)
```json
{
  "error": true,
  "status_code": 404,
  "message": "Synchronization log record not found with ID: 999"
}
```

### Invalid Filter Parameters
```json
{
  "error": true,
  "status_code": 400,
  "message": "Invalid [filter_name] format. Use YYYY-MM-DD"
}
```

---

## Usage Examples

### Example 1: Get Pending Syncs
```bash
curl -X GET "http://localhost/jpos2xapi/synchronize_log.php?sync_status=pending&per_page=10" \
  -H "X-DB-User: root" \
  -H "X-DB-Pass: password" \
  -H "X-DB-Name: pos_v2"
```

### Example 2: Get Syncs by User and Date Range
```bash
curl -X GET "http://localhost/jpos2xapi/synchronize_log.php?user_id=5&start_date=2025-12-01&end_date=2025-12-31" \
  -H "X-DB-User: root" \
  -H "X-DB-Pass: password" \
  -H "X-DB-Name: pos_v2"
```

### Example 3: Search for Inventory Module Syncs
```bash
curl -X GET "http://localhost/jpos2xapi/synchronize_log.php?module=inventory&page=1&per_page=25" \
  -H "X-DB-User: root" \
  -H "X-DB-Pass: password" \
  -H "X-DB-Name: pos_v2"
```

### Example 4: Get Full Details of Specific Log
```bash
curl -X GET "http://localhost/jpos2xapi/synchronize_log.php?action=details&id=42" \
  -H "X-DB-User: root" \
  -H "X-DB-Pass: password" \
  -H "X-DB-Name: pos_v2"
```

### Example 5: Get Latest Synced Records with Sync Rate
```bash
curl -X GET "http://localhost/jpos2xapi/synchronize_log.php?sync_status=synced&per_page=50&page=1" \
  -H "X-DB-User: root" \
  -H "X-DB-Pass: password" \
  -H "X-DB-Name: pos_v2"
```

### Example 6: Get User by ID
```bash
curl -X GET "http://localhost/jpos2xapi/synchronize_log.php?action=get-user&user_id=1" \
  -H "X-DB-User: root" \
  -H "X-DB-Pass: password" \
  -H "X-DB-Name: pos_v2"
```

### Example 7: Get User by Username
```bash
curl -X GET "http://localhost/jpos2xapi/synchronize_log.php?action=get-user&username=Admin" \
  -H "X-DB-User: root" \
  -H "X-DB-Pass: password" \
  -H "X-DB-Name: pos_v2"
```

### Example 8: Get User by Email
```bash
curl -X GET "http://localhost/jpos2xapi/synchronize_log.php?action=get-user&email=manager@gmail.com" \
  -H "X-DB-User: root" \
  -H "X-DB-Pass: password" \
  -H "X-DB-Name: pos_v2"
```

### Example 9: Get All Users in System
```bash
curl -X GET "http://localhost/jpos2xapi/synchronize_log.php?action=all-users" \
  -H "X-DB-User: root" \
  -H "X-DB-Pass: password" \
  -H "X-DB-Name: pos_v2"
```

---

## Data Fields Description

### Sync Log Record
- **id**: Unique identifier for the sync log record
- **table_name**: Name of the database table affected
- **module**: Module/feature identifier (e.g., 'inventory', 'sales')
- **action**: Type of action (INSERT, UPDATE, DELETE)
- **sync_status**: Current status ('synced' or 'pending')
- **synced_at**: Timestamp when the record was synchronized (null if pending)
- **created_at**: Timestamp when the record was created
- **updated_at**: Timestamp when the record was last updated
- **user**: User information who triggered the action

### Summary Statistics
- **total_records**: Total number of records matching filters
- **synced_count**: Number of successfully synced records
- **pending_count**: Number of pending sync records
- **sync_rate**: Percentage of synced records (0-100)
- **date_range**: First and last sync record dates

---

## Best Practices

1. **Always provide database credentials** via headers for security
2. **Use pagination** to avoid loading large result sets
3. **Apply filters** to narrow down results for better performance
4. **Check sync_rate** in summary to monitor synchronization health
5. **Review pending_count** regularly to ensure timely syncing
6. **Use date_range filters** for historical analysis

---

## HTTP Status Codes

| Code | Meaning |
|------|---------|
| 200 | Success |
| 400 | Bad Request (invalid parameters) |
| 404 | Not Found (resource doesn't exist) |
| 405 | Method Not Allowed (only GET allowed) |
| 500 | Internal Server Error (database connection failed) |
