# QR Task Check — Developer Technical Guide & Architecture Reference

Welcome to the **QR Task Check (QRTS)** Developer Guide. This technical manual is intended for software engineers, systems administrators, and DevOps personnel maintaining, extending, or integrating with the QR Task Check codebase.

---

## 1. System Architecture & Tech Stack

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                                CLIENT TIER                                      │
│  Modern Web Browsers (Mobile, Tablet, Desktop)                                   │
│  HTML5 + Vanilla CSS (Design Tokens) + Vanilla JS (Modular ES6)                 │
│  Libraries: GSAP 3.12 (Animations), FontAwesome 6, html5-qrcode (Camera Reader)│
└──────────────────────────────────────┬──────────────────────────────────────────┘
                                       │ HTTP / HTTPS / SSE (EventStream)
┌──────────────────────────────────────▼──────────────────────────────────────────┐
│                             APPLICATION TIER                                    │
│  Apache 2.4 / PHP 8.1+ (Procedural / Modular Functional Architecture)           │
│                                                                                 │
│  ┌───────────────────────┐  ┌──────────────────────┐  ┌──────────────────────┐  │
│  │ Server-Rendered Views │  │   JSON REST APIs     │  │ Realtime SSE Service │  │
│  │ (pages/*.php)         │  │   (api/**/*.php)     │  │ (api/realtime/events)│  │
│  └──────────┬────────────┘  └──────────┬───────────┘  └──────────┬───────────┘  │
│             │                          │                         │              │
│             ▼                          ▼                         ▼              │
│  ┌───────────────────────────────────────────────────────────────────────────┐  │
│  │ Domain Rules & Services (rules/*.php, authz/*.php, conn/db.php)          │  │
│  └─────────────────────────────────────┬─────────────────────────────────────┘  │
└────────────────────────────────────────┼────────────────────────────────────────┘
                                         │ PDO (pdo_sqlsrv)
┌────────────────────────────────────────▼────────────────────────────────────────┐
│                              DATABASE TIER                                      │
│  Microsoft SQL Server (Shared LRNPH_OJT Database, dbo.qrs_* tables)             │
│  External Corporate Tables: dbo.lrn_master_list, dbo.lrnph_users                │
└─────────────────────────────────────────────────────────────────────────────────┘
```

### Core Technologies
* **Backend Runtime**: PHP 8.1+ (running under Apache / XAMPP or IIS).
* **Database Driver**: Microsoft SQL Server via `pdo_sqlsrv` extension (`TrustServerCertificate=true`).
* **Session Management**: Native PHP Sessions (`$_SESSION`) with security checks against corporate user rosters.
* **Frontend**: Vanilla JavaScript (no heavy frontend framework like React/Vue); Vanilla CSS with strict variable design tokens; GSAP for animations.
* **Hardware Integration**: HTML5 MediaDevices API (`navigator.mediaDevices.getUserMedia`) for on-device camera feeds and QR scanning.
* **Real-time Engine**: Server-Sent Events (`text/event-stream`) for zero-polling instant synchronization across active staff screens.

---

## 2. Directory Structure & File Map

```text
c:\xampp\htdocs\QRTS\
├── api/                           # RESTful JSON & partial rendering endpoints
│   ├── auth/                      # Session heartbeat & authentication helpers
│   ├── dashboard/                 # Dashboard chart & calendar AJAX partials
│   ├── locations/                 # Location CRUD, CSV import/export, bulk delete
│   ├── realtime/                  # Server-Sent Events (SSE) streaming engine
│   ├── scan/                      # Field inspection lookup, start check, complete check
│   ├── task_locations/            # Checkpoint assignment, unassignment, expiration
│   ├── tasks/                     # Task creation, deletion, details modal partials
│   └── users/                     # User provisioning, role switching, status toggling
├── assets/                        # Static assets (favicons, brand badges, system icons)
├── auth/                          # Login handlers, logout, corporate auth bridges
├── authz/                         # Security: Capabilities matrix, RBAC, audit logger, rate limiter
│   ├── audit.php                  # Central audit logger (records to dbo.qrs_audit_log)
│   ├── authz.php                  # Authentication guard (requireAuth, requireCapability)
│   ├── capabilities.php           # Role-to-permission mapping matrix
│   └── rate_limit.php             # Action throttling & brute-force defense
├── components/                    # Reusable UI partials (app shell, sidebar, modal wrappers)
│   ├── appshell_start.php         # Global sidebar, top brand header, user profile dock
│   └── appshell_end.php           # Closing HTML tags, global toast dock, accessibility popover
├── conn/                          # Database connection singleton & credentials
│   ├── config.php                 # Host, DB name, credentials, table prefix
│   └── db.php                     # PDO connection factory (db() singleton)
├── documentation/                 # User guides and technical manuals
├── pages/                         # Main server-rendered application pages
│   ├── audit_logs.php             # System activity & security audit timeline
│   ├── dashboard.php              # Analytics, completion percentages, inspection trends
│   ├── manage_locations.php       # Checkpoint catalog, CSV importer, QR generator
│   ├── qradmin.php                # Task management & location assignment hub
│   ├── scan.php                   # Primary field inspector interface (mobile-optimized)
│   ├── task_report.php            # Historical reports, checklist answers, photo evidence
│   ├── tasks.php                  # All tasks overview & quick filters
│   └── user_management.php        # Staff roster, role assignment, active accounts
├── rules/                         # Business rules, domain constants, status derivation
│   ├── constants.php              # Table definitions, expiration seconds, master list SQL
│   ├── status.php                 # Canonical task/location state machine derivations
│   └── validation.php             # Input validation utilities
├── scripts/                       # Client-side JavaScript controllers
│   ├── date-picker.js             # Custom accessible date calendar picker
│   ├── filters.js                 # Universal table & list filter engine
│   ├── manage_locations.js        # Location actions, CSV modal, printable QR generator
│   ├── modal.js                   # Universal dialog open/close/keyboard ESC controller
│   ├── realtime-sync.js           # SSE event stream listener & DOM region patcher
│   ├── scan.js                    # Step 1 / Step 2 scan workflow, camera, photo uploads
│   ├── select-dropdown.js         # Custom accessible select dropdown listbox
│   ├── task_report.js             # Photo viewer modal, date range filtering
│   ├── tasks.js                   # Task creation form, dual-panel location assigner
│   ├── theme.js                   # Accent color switcher & dark mode controller
│   └── user_management.js         # User dialogs, role assignment, lookup handlers
├── sql/                           # Deployment schema, seed data, and location catalogs
│   └── schema.sql                 # Baseline T-SQL schema definition
└── styles/                        # CSS stylesheets
    ├── app.css                    # Core design system tokens, typography, grid, cards
    ├── dashboard.css              # Dashboard analytics & chart styles
    ├── manage_locations.css       # Location tables, badge states, print layouts
    ├── scan.css                   # Mobile-first field scan screens, step indicators
    ├── task_report.css            # Report tables, photo thumbnails, filter drawer
    ├── tasks.css                  # Task creation layout & location assigner rail
    └── user_management.css        # User table, role badges, avatar swatches
```

---

## 3. Database Schema & Data Models

All QRS tables reside in the `dbo` schema of the SQL Server database `LRNPH_OJT` and are prefixed with `qrs_` to avoid namespace collisions with other corporate applications.

```
┌─────────────────────────────────┐
│           qrs_tasks             │
├─────────────────────────────────┤
│ id (PK)                         │
│ name                            │
│ owner_id                        │
│ task_type (Treatment|Monitoring)│
│ created_at / updated_at         │
└───────────────┬─────────────────┘
                │ 1
                │
                │ N
┌───────────────▼─────────────────┐            ┌────────────────────────────────┐
│      qrs_task_locations         │     N    1 │         qrs_locations          │
├─────────────────────────────────┤◄───────────┤────────────────────────────────┤
│ id (PK)                         │            │ id (PK)                        │
│ task_id (FK-app)                │            │ name                           │
│ location_id (FK-app)            │            │ qr_token (CHAR 16, UNIQUE)     │
│ task_date (DATE)                │            │ location_type                  │
│ scheduled_at (DATETIME2, NULL)  │            │                                │
│ status (pending|in_progress|...)│            │ is_active (BIT)                │
│ spot_spray_answer               │            │ created_at / updated_at        │
│ misting_answer                  │            └────────────────────────────────┘
│ mist_blower_answer              │
│ monitoring_answer               │
│ findings_observation            │
│ completion_remark               │
│ start_time / end_time           │
│ scanned_by / completed_by       │
│ unassigned_at                   │
└───────────────┬─────────────────┘
                │ 1
                │
                │ N
┌───────────────▼─────────────────┐
│    qrs_task_location_photos     │
├─────────────────────────────────┤
│ id (PK)                         │
│ task_location_id (FK-app)       │
│ stored_filename                 │
│ original_filename               │
│ mime_type                       │
│ uploaded_by / uploaded_at       │
└─────────────────────────────────┘
```

### Key Tables & Conventions

#### 1. `dbo.qrs_tasks`
* Represents an inspection or treatment campaign.
* **`task_type`**: `VARCHAR(12)` with `CHECK` constraint: `'Treatment'` or `'Monitoring'`. Constrains which checkpoints may be assigned to the task.
* Note: A task has **no status column** stored in the database. Its status is dynamically derived on read by inspecting the progress of its assigned locations.

#### 2. `dbo.qrs_locations`
* Physical checkpoints (bait stations, misting rooms, perimeter traps).
* **`qr_token`**: Unique 16-character random hexadecimal string (e.g., `4f9a12c8e3b7041d`). This is the value encoded in the printed QR code—**never the location ID or location name**.
* **`location_type`**: `'Treatment'` or `'Monitoring'`. Must match the task type when being assigned.

#### 3. `dbo.qrs_task_locations`
* Represents an active checkpoint assignment for a specific day.
* **`status`**: `'pending'`, `'in_progress'`, `'completed'`, or `'missed'`.
* **Checklist columns**: 4 standardized observation questions (`spot_spray_answer`, `misting_answer`, `mist_blower_answer`, `monitoring_answer`) storing `'Yes'`, `'No'`, or `'N/A'`. Paired with corresponding remark columns (`*_remark`).
* **`unassigned_at`**: If set, the location has been detached from active rotation (e.g. by auto-sweep or manual unassign), keeping audit history intact.

#### 4. `dbo.qrs_task_location_photos`
* Photo attachments proving task completion (maximum 3 photos per checkpoint).
* **`stored_filename`**: Random cryptographically generated string (e.g., `64f8a1bc2e4a8.jpg`). Never uses client-provided filenames to prevent directory traversal.
* File storage location: `assets/uploads/inspection_photos/`.

#### 5. `dbo.qrs_audit_log`
* Immutable security log tracking logins, task creation, check-ins, unassignments, and modifications.
* Fields: `user_id`, `action`, `entity_type`, `entity_id`, `details` (JSON string), `ip_address`, `user_agent`, `created_at`.

### Integration with External Corporate Tables
QR Task Check integrates with company-wide tables in `LRNPH_OJT` rather than maintaining its own user roster or passwords:
* **`dbo.lrn_master_list`**: Corporate HR roster. Single source of truth for employee names (`LastName`, `FirstName`, `MiddleName`). Linked via `qrs_users.employee_id = lrn_master_list.EmployeeID`.
* **`dbo.lrnph_users`**: Shared authentication credentials. `auth/login_handler.php` matches user credentials against this table using `BiometricsID`.

---

## 4. Business Logic & State Machines

### A. Checkpoint Lifecycle

```
[ Assigned ]
     │
     ▼
[ Pending ]  ──(Scan QR + Submit Checklist)──► [ In Progress ]
                                                     │
                             ┌───────────────────────┴───────────────────────┐
                             │ (Submit Photos & Biometrics)                  │ (24h Elapsed)
                             ▼                                               ▼
                       [ Completed ]                                   [ Missed Out ]
                             │                                               │
                             └───────────────────────┬───────────────────────┘
                                                     ▼
                                            [ Auto-Sweep Unassign ]
                                        (Location returns to Available)
```

1. **Pending**: Assigned to today's task, waiting for the inspector to arrive.
2. **In Progress**: Step 1 complete. Inspector scanned the QR code and answered the 4 checklist items. The shared **24-hour window** continues from the later of `scheduled_at` (or the legacy date-only anchor) and `assigned_at` (`TASK_LOCATION_EXPIRATION_SECONDS = 86400`).
3. **Completed**: Step 2 complete. Inspector attached 1-3 photos and confirmed with staff ID/biometrics code.
4. **Missed Out**: 24 hours elapsed without Step 2 submission. Automatically expired by `rules/status.php` or `api/task_locations/expire.php`.
5. **Auto-Sweep**: Once a location reaches a resolved state (`completed` or `missed`), the background cleaner sets `unassigned_at = SYSDATETIME()`. This frees the physical location so it can be assigned to new tasks.

### B. Derived Task Status Logic (`rules/status.php`)

Task status is computed on the fly via `deriveTaskStatus($total, $completed, $inProgress, $isFutureScheduled, $dateLabel)`:

| Conditions | Derived Code | Display Badge |
| :--- | :--- | :--- |
| `total == 0` | `assign` | Assign Locations |
| `completed == 0 && inProgress == 0` (Scheduled future date/time) | `scheduled` | Scheduled: [Date/Time] |
| `completed == 0 && inProgress == 0` (Scheduled today) | `not_started` | Not Started |
| `completed == total` | `completed` | Completed |
| `completed > 0 || inProgress > 0` | `ongoing` | On-going |

---

## 5. Security & Access Control (RBAC)

The system enforces a strict 2-role capability matrix defined in [`authz/capabilities.php`](file:///c:/xampp/htdocs/QRTS/authz/capabilities.php):

```php
const ROLE_ADMIN = 'Admin';
const ROLE_USER = 'User';

const QRS_CAPABILITIES = [
    'task.create'           => [ROLE_ADMIN],
    'task.delete'           => [ROLE_ADMIN],
    'location.create'       => [ROLE_ADMIN],
    'location.update'       => [ROLE_ADMIN],
    'location.delete'       => [ROLE_ADMIN],
    'location.import_csv'   => [ROLE_ADMIN],
    'location.export_csv'   => [ROLE_ADMIN, ROLE_USER],
    'task_location.assign'  => [ROLE_ADMIN],
    'task_location.unassign'=> [ROLE_ADMIN],
    'scan.start'            => [ROLE_ADMIN, ROLE_USER],
    'scan.complete'         => [ROLE_ADMIN, ROLE_USER],
    'report.view'           => [ROLE_ADMIN, ROLE_USER],
    'user.manage'           => [ROLE_ADMIN],
];
```

### Protecting Routes & API Endpoints
Every API script must verify credentials and permissions before execution:

```php
require_once __DIR__ . '/../../authz/authz.php';

// 1. Ensure user is logged in
$user = requireAuth();

// 2. Ensure user has capability
requireCapability($user['role_name'], 'task.create');
```

---

## 6. Key API Endpoints Reference

All API endpoints return JSON conforming to standard response envelopes:
```json
{
  "success": true,
  "data": { ... },
  "message": "Optional user-friendly message"
}
```
Or on failure:
```json
{
  "success": false,
  "error": "Descriptive error message"
}
```

### Primary Endpoints Table

| Endpoint | Method | Required Capability | Payload / Parameters | Description |
| :--- | :---: | :---: | :--- | :--- |
| `api/scan/lookup.php` | `POST` | `scan.start` | `qr_token`, `task_id` | Verifies scanned QR code against assigned task checkpoints. |
| `api/scan/start.php` | `POST` | `scan.start` | `task_location_id`, checklist answers, remarks | Submits Step 1; transitions checkpoint to `in_progress`. |
| `api/scan/complete.php`| `POST` | `scan.complete` | `task_location_id`, `photos[]`, `confirm_code` | Submits Step 2 with photo proofs; marks checkpoint `completed`. |
| `api/tasks/create.php` | `POST` | `task.create` | `name`, `task_type`, `schedule_date` | Creates a new inspection task. |
| `api/task_locations/assign.php` | `POST` | `task_location.assign` | `task_id`, `location_ids[]` | Binds checkpoints to an inspection task. |
| `api/locations/import_csv.php` | `POST` | `location.import_csv` | `csv_file` (multipart) | Batch imports checkpoints. Rejects empty/invalid types. |
| `api/realtime/events.php` | `GET` | `requireAuth` | — | SSE EventSource stream emitting live task/location changes. |

---

## 7. Realtime Sync Architecture (Server-Sent Events)

Instead of resource-heavy WebSocket servers or high-frequency polling, QR Task Check uses **Server-Sent Events (SSE)**.

### How It Works:
1. The client browser initializes the connection in `scripts/realtime-sync.js`:
   ```javascript
   const evtSource = new EventSource('../api/realtime/events.php');
   evtSource.addEventListener('sync', (e) => {
       const data = JSON.parse(e.data);
       handleRealtimeUpdate(data);
   });
   ```
2. [`api/realtime/events.php`](file:///c:/xampp/htdocs/QRTS/api/realtime/events.php) keeps the HTTP connection open, streaming lightweight diff events whenever a checkpoint transitions state:
   ```text
   event: sync
   data: {"type":"location_completed","task_id":12,"location_id":45}
   ```
3. Targeted DOM regions with the `data-realtime-region` attribute (e.g. `data-realtime-region="scan-manual-section"`) are dynamically refreshed without reloading the entire page.

---

## 8. Frontend Guidelines & Design System

### Strict Page Isolation Rule
When implementing responsive fixes or styling changes for a specific page:
* **NEVER modify global styles in `styles/app.css`** unless the user explicitly orders a global design change.
* Add scoped styles to the page's dedicated stylesheet (e.g. `styles/scan.css`, `styles/tasks.css`).
* Wrap page cards in unique parent selector classes (e.g. `.scan-picker-card`, `.scan-layout`) to prevent rule leakage.

### Cache Busting Rule
Whenever you modify CSS or JS files, always increment the version query parameter in `<head>` or script tags across referencing PHP pages:
```html
<link rel="stylesheet" href="../styles/scan.css?v=6">
<script src="../scripts/scan.js?v=7"></script>
```

### Custom Dropdowns (`select-dropdown`)
Because native `<select>` popups cannot be custom-styled, the application uses custom listboxes (`.select-dropdown`).
* In free-flowing cards, ensure the menu container has `position: relative !important;` and `.select-dropdown-menu` has `position: absolute !important; top: calc(100% + 6px) !important;` so options scroll naturally with the document rather than detaching.
* Options must include `white-space: normal !important; word-break: break-word !important;` to ensure long checkpoint titles never clip off-screen.

---

## 9. Developer Setup & Local Environment

### Requirements
1. **PHP 8.1 or 8.2** with extensions: `pdo`, `pdo_sqlsrv`, `sqlsrv`, `fileinfo`, `gd` (or `imagick`), `mbstring`.
2. **Microsoft ODBC Driver 17 or 18 for SQL Server** installed on the host OS.
3. **Apache 2.4** with `mod_rewrite` enabled.

### Quick Setup Steps
1. Clone the repository into your web server root:
   ```powershell
   git clone <repo_url> C:\xampp\htdocs\QRTS
   ```
2. Configure database credentials in [`conn/config.php`](file:///c:/xampp/htdocs/QRTS/conn/config.php):
   ```php
   define('QRS_DB_HOST', 'localhost'); // or remote SQL Server IP
   define('QRS_DB_NAME', 'LRNPH_OJT');
   define('QRS_DB_USER', 'your_db_user');
   define('QRS_DB_PASS', 'your_db_password');
   ```
3. Initialize the schema:
   Execute `sql/schema.sql` using SQL Server Management Studio (SSMS) or `sqlcmd`.
4. Run syntax verification:
   ```powershell
   php -l pages/scan.php
   node -c scripts/scan.js
   ```
5. Access the application in your browser:
   `http://localhost/QRTS/pages/dashboard.php`

---

## 10. Common Pitfalls & Troubleshooting

### 1. Camera / QR Scanner Not Opening
* **Root Cause**: The HTML5 `getUserMedia` API requires a **Secure Context** (`https://` or `localhost`).
* **Fix**: If accessing the dev server from a physical mobile device over LAN (e.g. `http://192.168.1.50/QRTS`), the browser will block the camera. Configure an SSL certificate in Apache or use port forwarding / ngrok to provide HTTPS.

### 2. SQL Server UTF-8 Character Corruption
* **Root Cause**: Accented names or non-ASCII characters appearing garbled.
* **Fix**: Ensure PDO is instantiated with `PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8` (configured by default in `conn/db.php`).

### 3. File Uploads Failing
* **Root Cause**: PHP configuration limits or folder write permissions.
* **Fix**: Check `upload_max_filesize = 10M` and `post_max_size = 20M` in `php.ini`. Verify that Apache has write permissions to `assets/uploads/inspection_photos/`.
