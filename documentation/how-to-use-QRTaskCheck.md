# QR Task Check — Official User Guide

Welcome to the **QR Task Check** User Guide. This manual provides a complete, step-by-step walkthrough of the system—from setting up checkpoints and creating inspection tasks to scanning on-site QR codes, recording field observations, uploading photo proof, and generating audit reports.

---

## 1. System Overview: What is QR Task Check?

**QR Task Check** is a digitized field inspection, verification, and accountability platform designed for facilities maintenance, pest control, sanitation, and monitoring operations.

### The Core Problem It Solves
Traditional paper checklists often suffer from missed checkpoints, delayed reporting, and lack of physical verification. QR Task Check guarantees that field technicians are physically present at each designated checkpoint by combining:
1. **Physical QR Code Checkpoints**: Posted at physical equipment, rooms, or bait/treatment stations.
2. **Digital Checklists**: Standardized observation questions for treatments and inspections.
3. **Photo Proof of Work**: High-resolution image capture (up to 3 photos per location) proving task completion.
4. **Biometrics/Staff ID Sign-off**: Secure electronic confirmation verifying the inspector's identity.
5. **Real-Time Visibility**: Instant dashboard updates showing live completion percentages across active field teams.

```
┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐
│  1. Create Task │  ───> │ 2. Scan QR Code │  ───> │  3. Observation │  ───> │ 4. Photo Proof  │
│   & Assign Locs │       │    at Station   │       │    Checklists   │       │ & Biometric Sign│
└─────────────────┘       └─────────────────┘       └─────────────────┘       └─────────────────┘
                                                                                       │
                                                                                       ▼
                                                                              ┌─────────────────┐
                                                                              │ 5. Audit Report │
                                                                              │ & Auto-Sweep    │
                                                                              └─────────────────┘
```

---

## 2. User Roles & Navigation

The system provides tailored interfaces depending on your user role:

| Feature / Page | Administrator | Field Staff / Operator | Purpose |
| :--- | :---: | :---: | :--- |
| **Dashboard** | Full Access | Full Access | High-level metrics, progress charts, and task type filters. |
| **Task Manager / All Tasks** | Create & Manage | Read-Only View | Admin creates tasks and assigns locations; staff views active work. |
| **Manage Locations** | Full Access | Read-Only Catalog | Add/edit physical checkpoints, import via CSV, print QR codes. |
| **Scan QR** | Full Access | Full Access | The primary field interface for scanning, checklists, and sign-offs. |
| **Task Report** | Full Access | Full Access | Review historical records, observation notes, and uploaded photos. |
| **User Management** | Full Access | — | Manage user accounts, roles, and biometrics codes. |
| **Audit Logs** | Full Access | — | Immutable timeline of system actions and scan events. |

---

## 3. Core Concepts & Lifecycle

### A. Task Types
Every task in the system belongs to one of two categories:
* **Treatment**: Tasks requiring active intervention (e.g., Spot Spraying, Space Misting, Mist Blowers).
* **Monitoring**: Routine checks and inspections (e.g., bait station status, pest traps, room conditions).

> [!NOTE]
> Locations are categorized as either *Treatment* or *Monitoring*. When creating a task, only locations matching the selected task type will appear for assignment.

### B. Location Lifecycle & Statuses
When a task is active, each assigned location moves through the following stages:

```
[ Scheduled ] ──> [ Pending ] ──> [ In Progress ] ──> [ Completed ]
                                         │
                             (24 Hours Expired)
                                         ▼
                                   [ Missed Out ]
```

* **Scheduled**: The task was planned for an upcoming date. Locations remain on standby until the scheduled date arrives.
* **Pending**: The task is active for today. The location is waiting for a field technician to arrive and begin.
* **In Progress**: A technician has scanned the QR code and submitted Step 1 (Observation Checklist). A **24-hour countdown timer** runs until work is completed.
* **Completed**: The technician submitted Step 2 (Photo proof and Biometrics sign-off). The checkpoint is fully resolved.
* **Missed Out**: The 24-hour window elapsed without completing Step 2.
* **Automatic Sweep**: Once a location is resolved (*Completed* or *Missed Out*), the system automatically unassigns it so the location can immediately be reused in future tasks.

---

## 4. End-to-End Workflow Guide

### Step 1: Setting Up Locations & Printing QR Codes (Admin)
Before assigning tasks, ensure your physical locations are registered:
1. Navigate to **Manage Locations** from the sidebar.
2. Click **Add Location** to enter a single checkpoint, or use **Import CSV** for bulk additions.
3. Categorize the location under **Treatment** or **Monitoring**.
4. Click the **QR Code** icon next to any location to preview and print its unique QR code sticker to affix on-site.

---

### Step 2: Creating and Assigning a Task (Admin)
1. Go to **Task Manager** and click the **Create Task** button.
2. **Task Name**: Enter a clear, descriptive name (e.g., *"Weekly Building Treatment"* or *"Warehouse Monitoring"*).
3. **Task Type**: Click the card for **Treatment** or **Monitoring**.
4. **Schedule Date**:
   * *For immediate work:* Leave the date set to **Today**. The task activates immediately.
   * *For future planning:* Click the calendar box and choose a future date (see [Advance Scheduling](#5-advance-scheduling-feature) below).
5. **Select Locations**: Check the boxes for the checkpoints to include, or use **Select All** or the search box.
6. Click **Create Task**.

> [!TIP]
> **Need to add more checkpoints to an existing task later?**  
> Click **View** on the task card in Task Manager, select additional locations, and click Save. New locations inherit the task's schedule date automatically.

---

### Step 3: Conducting Field Checks via Scan QR (Field Operators)
When technicians arrive on-site:
1. Open the sidebar and click **Scan QR**.
2. If arriving from the general menu, select the task you are inspecting.
3. **Initiate the Check**:
   * **Option A (Recommended):** Tap **Scan QR Code**, point the camera at the physical QR sticker posted at the station.
   * **Option B (Manual Fallback):** If the QR code is damaged or unreadable, select the checkpoint name from the **Choose Location Manually** dropdown.
4. The system recognizes the location and loads **Step 1 of 2**.

---

### Step 4: Step 1 — Observation & Recommendation Checklist
Upon scanning, the operator completes the required checklist:
1. **Answer the 4 Standard Checklist Items**:
   * **Spot Spray**: *Was the area spot sprayed?*
   * **Misting**: *Was misting conducted?*
   * **Mist Blower**: *Was a mist blower utilized?*
   * **Monitoring**: *Was general monitoring conducted?*
2. Select **Yes**, **No**, or **N/A** for each item.
   * *Important:* If answering **No** or **N/A**, a brief explanatory remark is mandatory (e.g., *"Area dry; misting not required"*).
3. **Findings / Observations (Optional)**: Add any notable field conditions (e.g., *"Evidence of pest activity near doorway"*).
4. Click **Start Check**. The location transitions to **In Progress**, recording your start timestamp.

---

### Step 5: Step 2 — Work Execution, Photo Proof & Biometrics Sign-off
After performing the physical inspection or treatment:
1. Return to the location screen in **Scan QR** (or tap the location from the sidebar roster).
2. **Attach Proof Photos (Required, max 3)**:
   * Tap **Take Photo** to open the device camera directly, or **Choose Photos** to upload from the device gallery.
   * Photos must be in JPG or PNG format (up to 8MB each).
3. **Completion Remarks (Optional)**: Type any final notes regarding the treatment applied.
4. **Staff / Biometrics Code Confirmation (Required)**:
   * Retype your 4-digit Staff / Biometrics ID to electronically sign off.
   * Click the eye icon if you need to verify your entered code.
5. Click **Submit & Complete**.
6. The location is instantly stamped as **Completed** with green checkmarks, and the system moves to the next location.

---

### Step 6: Reviewing Progress & Audit Reports

#### Real-Time Dashboard
* Supervisors and operators can watch live progress on the **Dashboard** and **Scan QR** sidebar.
* Progress bars update instantly as locations are checked off.
* Active countdown timers show remaining hours before 24-hour expiration.

#### Task Report
* Navigate to **Task Report** to view completed inspections.
* Filter by task name, location, or date.
* Click on any completed entry to view full observation answers, before/after proof photos, completion timestamps, and the inspector's name.

---

## 5. Advance Scheduling Feature

While daily tasks are typically created for **Today**, QR Task Check supports planning tasks days or weeks ahead:

* **Standby Mode**: Tasks scheduled for future dates remain strictly dormant until 12:00 AM on the scheduled date.
* **No Premature Scanning**: Field technicians cannot accidentally scan or start a future-dated location ahead of time.
* **Preserved 24-Hour Window**: The 24-hour countdown timer does not begin until the actual scheduled calendar date arrives.
* **Clean Scan Lists**: Future tasks are kept off daily scanner pick-lists to prevent confusion among technicians.

---

## 6. Pro-Tips & Best Practices

* **Ensure Good Lighting for Proof Photos**: When taking completion photos in basements or machinery rooms, ensure adequate lighting so equipment serial numbers and treated areas are clearly visible.
* **Multi-Technician Collaboration**: Multiple operators can work on the same task simultaneously. As Technician A completes Location 1, Technician B can scan Location 2.
* **Dark Mode**: Working in dark plant areas or night shifts? Toggle the **Dark Mode** switch in the lower left corner of the sidebar.
* **Battery & Connectivity**: If working in low-signal areas, keep your browser tab active. If a scan fails due to intermittent connectivity, use the manual location dropdown.

---

## 7. Frequently Asked Questions & Troubleshooting

### What if a physical QR code is scratched or missing?
Use the **Choose Location Manually** dropdown directly on the Scan QR screen. Select the checkpoint name to load the checklist without waiting for a camera read. Report the damaged code to your administrator for reprinting.

### Why does the camera not turn on when clicking "Scan QR Code"?
Ensure your browser has permission to access your device's camera. In mobile browsers (Chrome/Safari), tap the lock or site settings icon in the URL bar and grant **Camera** permissions.

### What happens if a task isn't finished within 24 hours?
Locations left in progress past 24 hours automatically switch to **Missed Out**. They will be logged in the Task Report for management review, and the location is freed for future re-assignment.

### Can an administrator unassign a location mid-task?
Yes. Administrators can open the task in **Task Manager**, click **View**, and unassign any pending location that no longer requires inspection.

---

## Need Assistance?
For account assistance, badge ID resets, or system questions, contact your internal IT / Quality Assurance Administrator.
