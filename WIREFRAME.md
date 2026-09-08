# SMVS Storage — Full Wireframe & Functionality Document
**Project:** SMVS Storage (Multimedia Asset Management System)  
**Stack:** Laravel 13 · Inertia.js · React · TypeScript  
**Last Updated:** June 2026

---

## Table of Contents
1. [System Overview](#1-system-overview)
2. [User Roles & Permissions Matrix](#2-user-roles--permissions-matrix)
3. [Authentication Flow](#3-authentication-flow)
   - 3.1 Login Page
   - 3.2 Register Page
4. [Main Application Layout](#4-main-application-layout)
5. [Browse Panel (Dashboard)](#5-browse-panel-dashboard)
6. [Upload Panel](#6-upload-panel)
7. [Access Control Panel](#7-access-control-panel)
8. [Settings Panel](#8-settings-panel)
9. [Preview Modal](#9-preview-modal)
10. [Category Modal](#10-category-modal)
11. [Floating Action Bar](#11-floating-action-bar)
12. [Profile Page](#12-profile-page)
13. [Data Models & Database Schema](#13-data-models--database-schema)
14. [API Routes Reference](#14-api-routes-reference)
15. [Toast Notification System](#15-toast-notification-system)
16. [Responsive / Mobile Behaviour](#16-responsive--mobile-behaviour)

---

## 1. System Overview

SMVS Storage is a **role-based multimedia asset management system** for internal teams. It allows uploading, browsing, searching, categorising, and downloading media files (images, videos, audio, documents) with strict access controls based on four user roles.

### High-Level Architecture
```
┌─────────────────────────────────────────────────────────────────┐
│                        SMVS Storage                             │
│                                                                 │
│   ┌─────────────┐    ┌──────────────┐    ┌──────────────────┐  │
│   │    Auth     │    │  Dashboard   │    │    Profile       │  │
│   │  /login     │    │  / (root)    │    │  /profile        │  │
│   │  /register  │    │              │    │                  │  │
│   └─────────────┘    └──────┬───────┘    └──────────────────┘  │
│                             │                                   │
│              ┌──────────────┼──────────────────┐               │
│              │              │                  │               │
│        ┌─────▼─────┐  ┌────▼────┐  ┌─────────▼──────┐        │
│        │  Browse   │  │ Upload  │  │ Access Control │        │
│        │  Panel    │  │  Panel  │  │    Panel       │        │
│        └───────────┘  └─────────┘  └────────────────┘        │
│                                                                 │
│        ┌────────────┐  ┌──────────────────────────────┐        │
│        │ Settings   │  │  Preview Modal (overlay)     │        │
│        │  Panel     │  │  Category Modal (overlay)    │        │
│        └────────────┘  └──────────────────────────────┘        │
└─────────────────────────────────────────────────────────────────┘
```

### Key User Flows
```
[Guest] ──► /login ──► Dashboard (/) ──► Browse / Upload / Manage
[Guest] ──► /register ──► Dashboard (/) as Viewer
[Any User] ──► /profile ──► Edit name / email / phone / password
[Super-Admin] ──► Access Control ──► Change user roles
```

---

## 2. User Roles & Permissions Matrix

| Permission               | Super-Admin | Admin | Uploader | Viewer |
|--------------------------|:-----------:|:-----:|:--------:|:------:|
| Browse & search files    | ✅          | ✅    | ✅       | ✅     |
| Preview files            | ✅          | ✅    | ✅       | ✅     |
| Download files           | ✅          | ✅    | ✅       | ✅     |
| Download ZIP (bulk)      | ✅          | ✅    | ✅       | ✅     |
| Upload files             | ✅          | ✅    | ✅       | ❌     |
| Delete files             | ✅          | ✅    | ❌       | ❌     |
| Create categories        | ✅          | ✅    | ❌       | ❌     |
| Delete categories        | ✅          | ✅    | ❌       | ❌     |
| View Access Control tab  | ✅          | ✅    | ❌       | ❌     |
| Change user roles        | ✅          | ❌    | ❌       | ❌     |
| View Settings tab        | ✅          | ✅    | ❌       | ❌     |
| Role simulation feature  | ✅          | ❌    | ❌       | ❌     |
| Edit own profile         | ✅          | ✅    | ✅       | ✅     |

### Role Descriptions

| Role         | Description                                                    |
|--------------|----------------------------------------------------------------|
| super-admin  | Full system control. Manages users, roles, categories, files  |
| admin        | Manages categories, uploads, deletes. Cannot change roles     |
| uploader     | Uploads and browses files only                                |
| viewer       | Read-only: browse, preview, download only                     |

> **Note:** New registered accounts are always assigned the **Viewer** role. Super-Admin must manually upgrade them.

---

## 3. Authentication Flow

### 3.1 Login Page
**Route:** `GET /login`  
**Access:** Guest only (redirects to dashboard if already logged in)

```
┌────────────────────────────────────────────────────────────┐
│                   [Full-screen centered]                    │
│                                                            │
│   ┌────────────────────────────────────────────────────┐   │
│   │                  🏛  SMVS Storage                  │   │
│   │                                                    │   │
│   │            Welcome back                            │   │
│   │            Sign in to your account to continue    │   │
│   │                                                    │   │
│   │  ┌──────────────────────────────────────────────┐ │   │
│   │  │ Email address                                │ │   │
│   │  │ [you@example.com                           ] │ │   │
│   │  └──────────────────────────────────────────────┘ │   │
│   │                                                    │   │
│   │  ┌──────────────────────────────────────────────┐ │   │
│   │  │ Password                                     │ │   │
│   │  │ [••••••••                                  ] │ │   │
│   │  └──────────────────────────────────────────────┘ │   │
│   │                                                    │   │
│   │  ☐ Remember me                                    │   │
│   │                                                    │   │
│   │  ┌──────────────────────────────────────────────┐ │   │
│   │  │              Sign in                         │ │   │
│   │  └──────────────────────────────────────────────┘ │   │
│   │                                                    │   │
│   │      Don't have an account? Create one ──────────► │   │
│   └────────────────────────────────────────────────────┘   │
│                                                            │
└────────────────────────────────────────────────────────────┘
```

**Fields:**
| Field     | Type     | Validation            | Required |
|-----------|----------|-----------------------|----------|
| email     | email    | valid email format    | ✅       |
| password  | password | any                   | ✅       |
| remember  | checkbox | boolean               | ❌       |

**Behaviour:**
- Invalid credentials → error on email field: *"These credentials do not match our records."*
- Valid credentials → redirect to `/` (dashboard)
- Processing state → button text changes to "Signing in…"

---

### 3.2 Register Page
**Route:** `GET /register`  
**Access:** Guest only

```
┌────────────────────────────────────────────────────────────┐
│                   [Full-screen centered]                    │
│                                                            │
│   ┌────────────────────────────────────────────────────┐   │
│   │                  🏛  SMVS Storage                  │   │
│   │                                                    │   │
│   │            Create account                         │   │
│   │            New accounts are assigned Viewer role  │   │
│   │                                                    │   │
│   │  ┌──────────────────────────────────────────────┐ │   │
│   │  │ Full name                                    │ │   │
│   │  │ [John Doe                                  ] │ │   │
│   │  └──────────────────────────────────────────────┘ │   │
│   │                                                    │   │
│   │  ┌──────────────────────────────────────────────┐ │   │
│   │  │ Email address                                │ │   │
│   │  │ [you@example.com                           ] │ │   │
│   │  └──────────────────────────────────────────────┘ │   │
│   │                                                    │   │
│   │  ┌──────────────────────────────────────────────┐ │   │
│   │  │ Phone (optional)                             │ │   │
│   │  │ [+1 234 567 8900                           ] │ │   │
│   │  └──────────────────────────────────────────────┘ │   │
│   │                                                    │   │
│   │  ┌──────────────────┐  ┌───────────────────────┐  │   │
│   │  │ Password         │  │ Confirm password      │  │   │
│   │  │ [Min. 8 chars  ] │  │ [Repeat password    ] │  │   │
│   │  └──────────────────┘  └───────────────────────┘  │   │
│   │                                                    │   │
│   │  ┌──────────────────────────────────────────────┐ │   │
│   │  │              Create account                  │ │   │
│   │  └──────────────────────────────────────────────┘ │   │
│   │                                                    │   │
│   │      Already have an account? Sign in ───────────► │   │
│   └────────────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────────────┘
```

**Fields:**
| Field                | Type     | Validation                  | Required |
|----------------------|----------|-----------------------------|----------|
| name                 | text     | max 255 chars               | ✅       |
| email                | email    | unique in users table       | ✅       |
| phone                | tel      | max 20 chars                | ❌       |
| password             | password | min 8 chars                 | ✅       |
| password_confirmation| password | must match password         | ✅       |

**Behaviour:**
- On success → auto-login → redirect to `/` (dashboard) with Viewer role
- Inline errors shown below each field

---

## 4. Main Application Layout

**Route:** `/` (and all authenticated pages)  
**Access:** Authenticated users only

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  SIDEBAR (280px)           │  HEADER (70px height)                          │
│                            │                                                 │
│  ┌─────────────────────┐   │  ☰  [Search files, tags, projects…   🔍]       │
│  │  🏛  SMVS Storage   │   │                              [Simulate: ▼]     │
│  └─────────────────────┘   │                              [☀/🌙] [Avatar ►] │
│                            │                              [← Logout]        │
│  NAV (role-dependent)      ├─────────────────────────────────────────────────
│  ┌─────────────────────┐   │
│  │ 📁 Browse Files   ● │   │  MAIN CONTENT (scrollable)
│  │ ⬆ Batch Upload      │   │  ┌─────────────────────────────────────────┐
│  │ 🛡 Access Control   │   │  │  [ Active Panel renders here ]          │
│  │ ⚙  Settings         │   │  │                                         │
│  └─────────────────────┘   │  │  • Browse Panel (default)               │
│                            │  │  • Upload Panel                         │
│  CATEGORIES                │  │  • Access Control Panel                 │
│  ─────────────────         │  │  • Settings Panel                       │
│  All Files          (142)  │  │                                         │
│  ▶ 📂 Portraits     (32)   │  └─────────────────────────────────────────┘
│  ▼ 📂 Backgrounds   (18)   │
│    └─ 📁 Day         (8)   │
│    └─ 📁 Night      (10)   │
│  ▶ 📂 Documents     (45)   │
│  ▶ 📂 Audio         (27)   │
│  [+] Add Category          │
│                            │
└────────────────────────────┘
```

### Sidebar Elements
| Element              | Visible To              | Behaviour                                  |
|----------------------|-------------------------|--------------------------------------------|
| Browse Files         | All roles               | Always shown, switches to browse panel     |
| Batch Upload         | super-admin, admin, uploader | Hidden for viewer                   |
| Access Control       | super-admin, admin      | Hidden for uploader, viewer               |
| Settings             | super-admin, admin      | Hidden for uploader, viewer               |
| Category tree        | All roles               | Expandable/collapsible, click to filter   |
| Add Category [+]     | super-admin, admin      | Opens Category Modal                       |

### Header Elements
| Element              | Visible To              | Behaviour                                  |
|----------------------|-------------------------|--------------------------------------------|
| ☰ Menu toggle        | All roles               | Opens sidebar on mobile                    |
| Search input         | All roles               | Debounced 250ms → filters files            |
| Simulate role ▼      | super-admin only        | Switches session-based simulated view      |
| ☀/🌙 Theme toggle   | All roles               | Toggles dark/light, saves to localStorage  |
| User Avatar          | All roles               | Clickable → navigates to `/profile`        |
| Logout button        | All roles               | POST /logout → redirect to /login          |

### Theme System
- Default: **Dark** (set in blade template)
- Toggle persisted in `localStorage` key `smvs_theme`
- CSS variables switch via `data-theme="dark"` | `data-theme="light"` on `<html>`

---

## 5. Browse Panel (Dashboard)

**Default panel when dashboard loads**

```
┌──────────────────────────────────────────────────────────────────────────┐
│  Browse Multimedia Files                                                  │
│  142 files · sorted by newest                                            │
│                                                                          │
│  ┌─────────────────┐ ┌────────────────┐ ┌────────────────┐             │
│  │ 📦 Total Storage│ │ 📁 Total Files │ │ 👤 Your Role   │             │
│  │    2.4 GB       │ │      142       │ │   super admin  │             │
│  └─────────────────┘ └────────────────┘ └────────────────┘             │
│                                                                          │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │  [All ✓] [🖼 Images] [🎬 Video] [🎵 Audio] [📄 Document]        │   │
│  │                                     Sort: [Newest ▼]  ☐ Select All│  │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                          │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐      │
│  │ ☐        │ │ ☐        │ │ ☐        │ │ ☐        │ │ ☐        │      │
│  │          │ │  ▶       │ │  ♫       │ │  📄      │ │          │      │
│  │ [img]    │ │ [thumb]  │ │ [wave]   │ │ [icon]   │ │ [img]    │      │
│  │ IMAGE    │ │ VIDEO    │ │ AUDIO    │ │ DOCUMENT │ │ IMAGE    │      │
│  │          │ │          │ │          │ │          │ │          │      │
│  │ photo.jpg│ │ clip.mp4 │ │ song.mp3 │ │ brief.pdf│ │ scene.png│      │
│  │ 2.4 MB   │ │ 18.2 MB  │ │ 4.1 MB   │ │ 890 KB   │ │ 1.2 MB   │      │
│  │ 14/06/26 │ │ 14/06/26 │ │ 14/06/26 │ │ 14/06/26 │ │ 14/06/26 │      │
│  │ #tag1    │ │ #tag2    │ │ #music   │ │ #brief   │ │ #bg      │      │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘      │
│                                                                          │
│  [Empty state if no files: "No files match your current filters"]       │
└──────────────────────────────────────────────────────────────────────────┘
```

### Stats Cards
| Card           | Value Source           | Icon          |
|----------------|------------------------|---------------|
| Total Storage  | Sum of all file sizes  | Package icon  |
| Total Files    | Count of filtered files| Folder icon   |
| Your Role      | simulatedRole          | Shield icon   |

### Filter Bar
| Control      | Options                              | Behaviour                         |
|--------------|--------------------------------------|-----------------------------------|
| Type chips   | All / Image / Video / Audio / Document | Active chip highlighted in primary color |
| Sort select  | Newest / Oldest / Name A-Z / Size ↓ | Re-fetches with sort parameter     |
| Select All   | Checkbox                             | Selects all visible files          |

### Media Card
| Element         | Behaviour                                              |
|-----------------|--------------------------------------------------------|
| Thumbnail area  | Click → opens Preview Modal                            |
| Checkbox overlay| Appears on hover/selected; click → toggles selection  |
| Image preview   | Loads from `/storage/uploads/images/...`               |
| Video card      | Shows thumbnail + play icon overlay                    |
| Audio card      | Shows waveform animation                               |
| Document card   | Shows coloured icon (PDF=red, DOCX=blue, XLSX=green…) |
| Tags            | Max 3 shown, +N badge for overflow                    |

### URL Parameters (Filter State)
```
/?search=portrait&type=image&sort=newest&category_id=3&subcategory_id=7
```

---

## 6. Upload Panel

**Route (tab switch):** visible to super-admin, admin, uploader  
**Trigger:** Click "Batch Upload" in sidebar

```
┌──────────────────────────────────────────────────────────────────────────┐
│  ⬆  Batch Upload                                                         │
│  Images (JPG, PNG, WEBP…) · Videos (MP4, MOV…) · Audio · Documents     │
│                                                                          │
│  ┌────────────────────────────────────┐  ┌─────────────────────────┐   │
│  │                                    │  │  BATCH SETTINGS         │   │
│  │  ┌──────────────────────────────┐  │  │                         │   │
│  │  │          [⬆ icon]            │  │  │ Category *              │   │
│  │  │  Drag & drop files here      │  │  │ [-- Select Category --▼]│   │
│  │  │  or click to browse          │  │  │                         │   │
│  │  │  Max 200 MB · All formats    │  │  │ Sub-category            │   │
│  │  │  [Browse Local Files]        │  │  │ [-- Select Sub ------▼] │   │
│  │  └──────────────────────────────┘  │  │                         │   │
│  │                                    │  │ Tags (comma separated)  │   │
│  │  UPLOAD QUEUE  (3 files)  [Clear] │  │ [portrait, scene 1    ] │   │
│  │                                    │  │ Applied to all files    │   │
│  │  ┌──────────────────────────────┐  │  │                         │   │
│  │  │ Uploading files… 65%         │  │  │ ┌─────────────────────┐ │   │
│  │  │ [████████████░░░░░░░░░░░░░] │  │  │ │ ▶ Start Batch Upload│ │   │
│  │  └──────────────────────────────┘  │  │ └─────────────────────┘ │   │
│  │                                    │  │  (disabled if no queue  │   │
│  │  [🖼 preview] photo.jpg  2.4MB    │  │   or no category)       │   │
│  │              ● uploading           │  └─────────────────────────┘   │
│  │  [🖼 preview] scene.png  1.2MB    │                                  │
│  │              ✓ success             │                                  │
│  │  [📄 icon  ] brief.pdf   890KB   │                                  │
│  │              ⏳ pending   [🗑]     │                                  │
│  └────────────────────────────────────┘                                  │
└──────────────────────────────────────────────────────────────────────────┘
```

### Supported File Formats
| Category  | Extensions                                                       |
|-----------|------------------------------------------------------------------|
| Images    | jpg, jpeg, png, webp, gif, svg, bmp, tiff, tif, ico, heic, heif, avif, raw |
| Videos    | mp4, mov, avi, mkv, webm, flv, wmv, m4v, 3gp                     |
| Audio     | mp3, wav, aac, ogg, m4a, flac, wma, opus                         |
| Documents | pdf, doc, docx, xls, xlsx, ppt, pptx, txt, csv, rtf, zip, rar, 7z |

### Upload Flow
```
1. Drop files or click Browse
       │
       ▼
2. Files added to queue (image thumbnails shown immediately via Object URL)
       │
       ▼
3. Select Category (required) + optional Subcategory + Tags
       │
       ▼
4. Click "Start Batch Upload"
       │
       ▼
5. Each file uploaded sequentially:
   POST /files (FormData: file, category_id, subcategory_id?, tags?)
   → Success: status = "success"
   → Failure: status = "error", toast shown
       │
       ▼
6. After all done → queue cleared → router.reload({ only: ['files','stats','categories'] })
   → File grid updates automatically (no full page refresh)
```

### Queue Item States
| Status    | Badge colour  | Remove btn visible |
|-----------|---------------|--------------------|
| pending   | Grey          | ✅ (trash icon)    |
| uploading | Blue          | ❌                 |
| success   | Green         | ❌                 |
| error     | Red           | ❌                 |

---

## 7. Access Control Panel

**Visible to:** super-admin, admin  
**Tab trigger:** Click "Access Control" in sidebar

```
┌──────────────────────────────────────────────────────────────────────────┐
│  🛡  Access Control                                                      │
│  Manage user permissions and roles                                       │
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │  PROFILE        EMAIL                 PHONE        ACCESS LEVEL    │ │
│  ├────────────────────────────────────────────────────────────────────┤ │
│  │  [SA] Super Admin   admin@example.invalid   +44 7700…   [super-admin ▼]  │ │
│  │       (You)                                       (disabled/self)  │ │
│  ├────────────────────────────────────────────────────────────────────┤ │
│  │  [AD] Admin User    admin2@example.invalid   +44 7700…   [admin       ▼]  │ │
│  │                                                   [Reset Pwd]      │ │
│  ├────────────────────────────────────────────────────────────────────┤ │
│  │  [UP] Upload User   upload@example.invalid   +44 7700…   [uploader    ▼]  │ │
│  │                                                   [Reset Pwd]      │ │
│  ├────────────────────────────────────────────────────────────────────┤ │
│  │  [VW] Viewer User   viewer@example.invalid  +44 7700…   [viewer      ▼]  │ │
│  │                                                   [Reset Pwd]      │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                                                          │
│  ROLE PERMISSIONS                                                        │
│  ┌──────────────────┐ ┌──────────────────┐ ┌──────────────┐ ┌────────┐ │
│  │ 👑 Super Admin   │ │ ⚙  Admin         │ │ ⬆ Uploader  │ │ 👁 Viewer│ │
│  │ Full system ctrl │ │ Manage content   │ │ Upload only  │ │ Read   │ │
│  │ Manage users     │ │ Categories       │ │ Browse files │ │ only   │ │
│  │ All permissions  │ │ Upload/delete    │ │              │ │        │ │
│  └──────────────────┘ └──────────────────┘ └──────────────┘ └────────┘ │
└──────────────────────────────────────────────────────────────────────────┘
```

### User Table Columns
| Column         | Description                                        |
|----------------|----------------------------------------------------|
| Profile        | Avatar (initials) + full name                     |
| Email          | User email address                                 |
| Phone          | Phone number (or — if not set)                    |
| Access Level   | Role dropdown (editable by super-admin only)       |
| Responsibilities | Role description text                           |
| Actions        | Reset Password button (placeholder)               |

### Role Dropdown Behaviour
| Condition                        | Dropdown State |
|----------------------------------|----------------|
| Current user is super-admin      | Enabled for all others |
| Current user is admin or lower   | Disabled (display only) |
| Row is the current logged-in user | Always disabled (cannot change own role) |

---

## 8. Settings Panel

**Visible to:** super-admin, admin  
**Tab trigger:** Click "Settings" in sidebar

```
┌──────────────────────────────────────────────────────────────────────────┐
│  ⚙  Settings                                                             │
│  Manage categories and system configuration                              │
│                                                                          │
│  ┌──────────────────────────────────┐ ┌─────────────────────────────┐  │
│  │  CATEGORY MANAGER                │ │  STORAGE OVERVIEW            │  │
│  │                                  │ │                              │  │
│  │  [+ Add Main Category]           │ │  ┌────────────────────────┐ │  │
│  │                                  │ │  │   [Doughnut Chart]     │ │  │
│  │  📂 Portraits            (32)    │ │  │                        │ │  │
│  │     [+ Add Sub] [🗑 Delete]      │ │  │   🎬 Videos  1.8 GB   │ │  │
│  │     └─ 📁 Headshots      (12)   │ │  │   🖼 Images  0.4 GB   │ │  │
│  │         [🗑]                     │ │  │   🎵 Audios  0.1 GB   │ │  │
│  │     └─ 📁 Groups         (20)   │ │  │   📄 Docs    0.1 GB   │ │  │
│  │         [🗑]                     │ │  └────────────────────────┘ │  │
│  │                                  │ │                              │  │
│  │  📂 Backgrounds           (18)   │ │  Total used: 2.4 GB         │  │
│  │     [+ Add Sub] [🗑 Delete]      │ │  Quota: 10 GB               │  │
│  │     └─ 📁 Day             (8)    │ │  ████████░░░░░ 24%          │  │
│  │     └─ 📁 Night          (10)   │ │                              │  │
│  │                                  │ │  Files breakdown:            │  │
│  │  📂 Documents             (45)   │ │  Videos   98 files          │  │
│  │     [+ Add Sub] [🗑 Delete]      │ │  Images   28 files          │  │
│  │                                  │ │  Audio    12 files          │  │
│  └──────────────────────────────────┘ │  Docs     4 files           │  │
│                                        └─────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────┘
```

### Category Manager Actions
| Action              | Who Can     | Confirmation | Endpoint                       |
|---------------------|-------------|--------------|--------------------------------|
| Add Main Category   | admin+      | No           | POST /categories               |
| Add Sub-category    | admin+      | No           | POST /categories/{id}/subcategories |
| Delete Category     | admin+      | Yes (confirm dialog) | DELETE /categories/{id} |
| Delete Sub-category | admin+      | Yes (confirm dialog) | DELETE /subcategories/{id} |

### Storage Overview
- Doughnut chart: proportional breakdown by file type (videos, images, audio, documents)
- Fixed quota display: 10 GB (hardcoded UI)
- Actual used size: calculated from MediaFile sum

---

## 9. Preview Modal

**Trigger:** Click any media card in Browse Panel  
**Type:** Full-page overlay (z-index 1000)

```
┌────────────────────────────────────────────────────────────────────────┐
│  MODAL OVERLAY (click outside to close)                                │
│                                                                        │
│  ┌──────────────────────────────┬──────────────────────────────────┐  │
│  │  PREVIEW PANE (dark bg)      │  INFO PANE                       │  │
│  │                              │                                  │  │
│  │                              │  photo_portrait.jpg       [✕]   │  │
│  │                              │  [IMAGE badge]                   │  │
│  │   [IMAGE renders here]       │                                  │  │
│  │   or                         │  ─────────────────────────────   │  │
│  │   [VIDEO player]             │  Category:    Portraits          │  │
│  │   or                         │  Sub-category: Headshots         │  │
│  │   [AUDIO player + icon]      │  File Size:   2.4 MB            │  │
│  │   or                         │  Resolution:  1920×1080         │  │
│  │   [DOCUMENT icon + open link]│  Upload Date: 14/06/2026 10:32  │  │
│  │                              │  Uploaded By: Arjun Mehta       │  │
│  │                              │                                  │  │
│  │                              │  Tags:                           │  │
│  │                              │  [portrait] [headshot] [2026]   │  │
│  │                              │                                  │  │
│  │                              │  ─────────────────────────────   │  │
│  │                              │                                  │  │
│  │                              │  [⬇ Download Asset] [🗑 Delete] │  │
│  └──────────────────────────────┴──────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────┘
```

### Preview Types by File Type
| Type     | Preview Pane Content                            | Special Notes                     |
|----------|-------------------------------------------------|-----------------------------------|
| image    | `<img>` with contain fit                        | Shows resolution in metadata      |
| video    | `<video>` with controls, autoplay muted         | Shows video_url                   |
| audio    | `<audio>` with controls + headphones icon       | Shows audio waveform visual       |
| document | Coloured file icon + ext badge + "Open in new tab" link | No inline preview possible |

### Info Pane Actions
| Button         | Visible When | Behaviour                                    |
|----------------|--------------|----------------------------------------------|
| Download Asset | Always       | Triggers browser download via anchor tag    |
| Delete         | canDelete = true (admin+) | Confirms then calls bulkDelete |
| ✕ Close        | Always       | Closes modal                                 |
| Click overlay  | Always       | Closes modal                                 |

---

## 10. Category Modal

**Trigger:** Click [+] in sidebar category header OR "+ Add Sub" in Settings  
**Type:** Overlay modal

```
┌─────────────────────────────────────────────┐
│  OVERLAY                                    │
│                                             │
│  ┌───────────────────────────────────────┐  │
│  │  Create Main Category                 │  │
│  │  (or "Add folder to {ParentName}")    │  │
│  │                                       │  │
│  │  Category Name                        │  │
│  │  [Enter category name…             ]  │  │
│  │                                       │  │
│  │  [Cancel]           [Create Category] │  │
│  └───────────────────────────────────────┘  │
└─────────────────────────────────────────────┘
```

**Behaviour:**
- `parentId = null` → Creates main category via `POST /categories`
- `parentId = {id}` → Creates subcategory via `POST /categories/{parentId}/subcategories`
- On success → modal closes + success toast
- On error → error toast
- Empty name → blocked by required validation

---

## 11. Floating Action Bar

**Trigger:** Appears from bottom when 1+ files selected  
**Type:** Fixed positioned, animated slide-up

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                 │
│  (Dashboard main content)                                       │
│                                                                 │
│                                                                 │
│     ┌────────────────────────────────────────────────────┐     │
│     │  ✓ 3 files selected   [✕ Clear] [⬇ ZIP] [🗑 Del] │     │
│     └────────────────────────────────────────────────────┘     │
│  ▲ slides up from bottom when files selected                    │
└─────────────────────────────────────────────────────────────────┘
```

### Bar Buttons
| Button      | Visible When          | Behaviour                              |
|-------------|-----------------------|----------------------------------------|
| ✕ Clear     | Always (when visible) | Deselects all files                    |
| ⬇ ZIP       | Always (when visible) | Fetches files, creates ZIP, downloads  |
| 🗑 Delete   | canDelete = true      | Confirms then bulk-deletes selected    |

### ZIP Download Flow
```
1. Collect selected files
2. For each: fetch(file_url) → get blob
3. Add to JSZip archive
4. Generate ZIP blob
5. Trigger download as "smvs_assets.zip"
```

---

## 12. Profile Page

**Route:** `GET /profile`  
**Access:** All authenticated users  
**Trigger:** Click user avatar in header

```
┌─────────────────────────────────────────────────────────────────────────┐
│  ← Back to Dashboard          🏛  SMVS Storage           [← Logout]    │
│  ─────────────────────────────────────────────────────────────────────  │
│                                                                         │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │  [AM]  Arjun Mehta                                                 │ │
│  │        super admin                                                  │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                                                         │
│  ┌ ✓ Profile updated successfully ──────────────────────────────────┐  │
│  │  (green flash message, dismisses automatically)                  │  │
│  └──────────────────────────────────────────────────────────────────┘  │
│                                                                         │
│  ┌────────────────────────────────┐  ┌────────────────────────────────┐ │
│  │  👤 PERSONAL INFORMATION       │  │  🔒 CHANGE PASSWORD            │ │
│  │  ─────────────────────────── │  │  ─────────────────────────── │ │
│  │                                │  │                                │ │
│  │  FULL NAME                     │  │  CURRENT PASSWORD              │ │
│  │  [Super Admin                ]  │  │  [•••••••••••••••••••••••••] │ │
│  │                                │  │                                │ │
│  │  EMAIL ADDRESS                 │  │  NEW PASSWORD                  │ │
│  │  [admin@example.invalid      ]  │  │  [Min. 8 characters        ]  │ │
│  │                                │  │                                │ │
│  │  PHONE (optional)              │  │  CONFIRM NEW PASSWORD          │ │
│  │  [+44 7700 900001           ]  │  │  [Repeat password          ]  │ │
│  │                                │  │                                │ │
│  │  ROLE                          │  │  [Update password]             │ │
│  │  [super admin] (read-only)     │  │                                │ │
│  │                                │  │                                │ │
│  │  [Save changes]                │  │                                │ │
│  └────────────────────────────────┘  └────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────┘
```

### Personal Info Form
| Field  | Editable | Validation              | Endpoint         |
|--------|----------|-------------------------|------------------|
| name   | ✅       | required, max 255       | PATCH /profile   |
| email  | ✅       | valid, unique (excl self)| PATCH /profile   |
| phone  | ✅       | optional, max 20 chars  | PATCH /profile   |
| role   | ❌ (display badge) | N/A         | N/A              |

### Change Password Form
| Field                | Validation                             | Endpoint                 |
|----------------------|----------------------------------------|--------------------------|
| current_password     | must match current DB password         | PATCH /profile/password  |
| password             | min 8 chars                            |                          |
| password_confirmation| must match password                    |                          |

**Success feedback:** Green flash banner at top of page (shared via Inertia flash props)

---

## 13. Data Models & Database Schema

### Entity Relationship Diagram
```
┌────────────────────┐       ┌────────────────────────────────────────┐
│      users         │       │              media_files               │
│────────────────────│       │────────────────────────────────────────│
│ id (PK)            │       │ id (PK)                                │
│ name               │       │ name                                   │
│ email (unique)     │◄──────┤ uploaded_by (FK → users.id, nullable) │
│ password (hashed)  │       │ type (enum: image/video/audio/document)│
│ phone (nullable)   │       │ size (bytes)                          │
│ role (enum)        │       │ file_path                              │
│ email_verified_at  │       │ thumbnail_path (nullable)              │
│ remember_token     │       │ resolution (nullable, e.g. "1920x1080")│
│ timestamps         │       │ tags (JSON array)                      │
└────────────────────┘       │ category_id (FK → categories.id, null) │
                             │ subcategory_id (FK → subcategories.id) │
                             │ timestamps                              │
                             └──────────┬────────────────┬────────────┘
                                        │                │
                          ┌─────────────▼──┐   ┌────────▼───────────┐
                          │   categories   │   │   subcategories    │
                          │────────────────│   │────────────────────│
                          │ id (PK)        │   │ id (PK)            │
                          │ name           │◄──┤ category_id (FK)   │
                          │ timestamps     │   │ name               │
                          └────────────────┘   │ timestamps         │
                                               └────────────────────┘
```

### User Roles Enum
```
'super-admin' | 'admin' | 'uploader' | 'viewer'
```

### File Type Enum
```
'image' | 'video' | 'audio' | 'document'
```

### Computed URL Attributes (MediaFile model)
| Attribute      | Generated From        | When Null          |
|----------------|-----------------------|--------------------|
| thumbnail_url  | url('/storage/' + thumbnail_path) | if thumbnail_path is null |
| video_url      | url('/storage/' + file_path)      | if type ≠ video           |
| audio_url      | url('/storage/' + file_path)      | if type ≠ audio           |
| document_url   | url('/storage/' + file_path)      | if type ≠ document        |

> `url()` uses the current **request host** (not APP_URL), ensuring correct URLs regardless of domain or port.

### Storage Structure
```
storage/app/public/
└── uploads/
    ├── images/      ← jpg, png, webp, gif, svg, bmp, etc.
    ├── videos/      ← mp4, mov, avi, mkv, etc.
    ├── audios/      ← mp3, wav, flac, ogg, etc.
    └── documents/   ← pdf, docx, xlsx, pptx, zip, etc.

public/storage/  ← symlink → storage/app/public/
```

---

## 14. API Routes Reference

### Authentication (Guest Only)
| Method | Route       | Controller          | Name       | Description              |
|--------|-------------|---------------------|------------|--------------------------|
| GET    | /login      | AuthController      | login      | Show login page          |
| POST   | /login      | AuthController      | —          | Process login            |
| GET    | /register   | AuthController      | register   | Show register page       |
| POST   | /register   | AuthController      | —          | Process registration     |

### Authenticated Routes
| Method | Route                            | Controller          | Name                | Middleware           |
|--------|----------------------------------|---------------------|---------------------|----------------------|
| POST   | /logout                          | AuthController      | logout              | auth                 |
| GET    | /                                | DashboardController | dashboard           | auth                 |
| POST   | /simulate-role                   | DashboardController | simulate-role       | auth                 |
| POST   | /files                           | MediaFileController | files.store         | auth                 |
| DELETE | /files/bulk-delete               | MediaFileController | files.bulk-delete   | auth                 |
| POST   | /categories                      | CategoryController  | categories.store    | auth                 |
| DELETE | /categories/{category}           | CategoryController  | categories.destroy  | auth                 |
| POST   | /categories/{category}/subcategories | CategoryController | subcategories.store | auth              |
| DELETE | /subcategories/{subcategory}     | CategoryController  | subcategories.destroy| auth               |
| GET    | /profile                         | ProfileController   | profile             | auth                 |
| PATCH  | /profile                         | ProfileController   | profile.update      | auth                 |
| PATCH  | /profile/password                | ProfileController   | profile.password    | auth                 |
| PATCH  | /users/{user}/role               | UserController      | users.update-role   | auth, role:super-admin |

### Request / Response Summary

**POST /login**
```
Request:  { email, password, remember? }
Response: 302 → /  (success)  |  302 → /login (fail, with errors)
```

**POST /register**
```
Request:  { name, email, phone?, password, password_confirmation }
Response: 302 → /  (auto-logged in as viewer)
```

**POST /files**
```
Request:  multipart/form-data { file, category_id, subcategory_id?, tags? }
Response: 201 { message: "Uploaded successfully" }  |  422 (validation errors)
Max file: 200 MB
```

**DELETE /files/bulk-delete**
```
Request:  { ids: [1, 2, 3] }
Response: 302 (back)
Side effect: Deletes physical files from storage
```

**PATCH /profile**
```
Request:  { name, email, phone? }
Response: 303 (back) with flash.success
```

**PATCH /profile/password**
```
Request:  { current_password, password, password_confirmation }
Response: 303 (back) with flash.success  |  422 (wrong current password)
```

**PATCH /users/{user}/role**
```
Request:  { role: 'super-admin'|'admin'|'uploader'|'viewer' }
Response: 302 (back)
Auth:     super-admin only (403 for others)
```

---

## 15. Toast Notification System

**Position:** Fixed, top-right corner (z-index 9999)

```
                              ┌──────────────────────────────┐
                              │  ✓ Successfully uploaded 3   │  ← success (green left border)
                              │    files!                    │
                              └──────────────────────────────┘
                              ┌──────────────────────────────┐
                              │  ✕ Failed to upload           │  ← error (red left border)
                              │    report.pdf                 │
                              └──────────────────────────────┘
```

| Type    | Left border colour | Icon | Auto-dismiss |
|---------|--------------------|------|--------------|
| success | `--color-success`  | ✓    | 4 seconds    |
| error   | `--color-danger`   | ✕    | 4 seconds    |
| info    | `--color-info`     | ℹ    | 4 seconds    |

**Trigger events:**
- Upload success/failure
- Role switch (simulation)
- File deletion
- Category operations
- Download preparation

---

## 16. Responsive / Mobile Behaviour

### Breakpoints
| Breakpoint | Layout Changes                                             |
|------------|-----------------------------------------------------------|
| > 900px    | Full 2-column grid (sidebar + content)                    |
| ≤ 900px    | Sidebar hidden by default; slides in via ☰ menu button   |
| ≤ 700px    | Profile page: single-column cards                         |
| ≤ 1024px   | Preview modal: stacked layout (preview above info)        |

### Mobile Header
```
┌──────────────────────────────────────────────────────────┐
│  ☰  [Search…                    🔍]  [☀/🌙] [Avatar] [←]│
└──────────────────────────────────────────────────────────┘
```

### Mobile Sidebar (slide-in)
```
┌──────────────────┐────────────────────────────────────────
│  🏛 SMVS Storage │  MAIN CONTENT (behind overlay)
│                  │
│  📁 Browse Files │
│  ⬆ Upload        │
│  🛡 Access       │
│  ⚙  Settings     │
│                  │
│  All Files (142) │
│  📂 Portraits    │
│  📂 Backgrounds  │
└──────────────────┘
```

Clicking anywhere outside sidebar or nav items closes it.

### Floating Action Bar (Mobile)
```
Position: center of screen bottom (not offset by sidebar on mobile)
left: 50%  (vs left: calc(50% + 140px) on desktop)
```

---

## Appendix A: Fresh Installation Accounts

Production fresh installation does not seed demo/test accounts. `SuperAdminSeeder` creates only the configured Super Admin. The login email is supplied as `SUPER_ADMIN_EMAIL`; Coolify generates the initial password as `SERVICE_PASSWORD_64_SUPERADMIN`.

---

## Appendix B: Technology Stack

| Layer        | Technology                    | Version     |
|--------------|-------------------------------|-------------|
| Backend      | Laravel                       | 13.x        |
| Frontend SPA | React + TypeScript            | 19.x        |
| SPA Bridge   | Inertia.js                    | 3.x         |
| Build tool   | Vite                          | 8.x         |
| CSS          | Vanilla CSS (design tokens)   | —           |
| Icons        | Lucide React                  | —           |
| ZIP          | JSZip                         | —           |
| PHP          | PHP                           | 8.4.x       |
| Database     | MySQL (via Laragon)           | 8.0.x       |
| File Storage | Laravel public disk           | local       |
| Auth         | Laravel Session Auth          | built-in    |

---

*End of SMVS Storage Wireframe Documentation*


---

## v05.00 Lifecycle UI Additions

### File Details -> Version History
- Current version badge
- Version rows: version number, filename, size, Changed By, Changed Date, Change Note
- Historical-version download
- Restore (Department Admin/Super Admin)
- Upload New Version with chunked progress
- Archive / Restore from Archive
- Potential Duplicate warning when checksum matches another logical asset

### Settings -> Lifecycle / Recycle Bin
- Soft-deleted assets list
- Restore action for authorized Department Admin/Super Admin
- Permanent delete action for Super Admin only
- Auto-purge disabled until retention policy is approved


---

## v07.00 - Upload & Bulk Operations UI

### Super Admin -> Settings -> Upload & Integrity

Controls:
- Allowed file extensions (checkbox list).
- Maximum file size in MB (`0` = no application-level limit).
- Maximum files per batch (`0` = no application-level count limit).
- Simple/chunk threshold MB.
- Chunk size MB (5-20).
- Retry count.
- Exact SHA-256 duplicate detection ON/OFF.
- Possible duplicate preflight warning ON/OFF.
- Inline guide explaining application limits vs server/proxy limits.

### Upload queue

For each active file show actual transfer and percent, for example:
`3.26 MB / 53.50 MB     6%`

The queue uses Central Admin settings to decide direct vs resumable upload. A preflight blocks disallowed files before transfer.

### Browse Files -> Multi-select -> Bulk Edit

Tabs/actions:
1. Metadata - explicitly check each field to change.
2. Tags - Add / Remove / Replace (hidden if free-form tags are disabled).
3. Move - Category/Sub-category; Super Admin may also transfer Owner Department.
4. Archive - Department Admin/Super Admin only.
5. Access Policy - Public / Protected / Private + download flag.

All operations are server-authorized, audited per asset and followed by batch search re-indexing.


---

## v08.00 - Notifications, Audit & Reports UI

### Header Bell -> Notification Center
- Unread badge and compact dropdown.
- View all -> full Notification Center.
- Filters: All / Unread / Access / File / Storage / Security.
- User channel preferences: Portal / Email / WhatsApp / Text SMS.
- User category preferences: Access / File / Storage / Security.
- Central Admin master switches remain authoritative.

### Super Admin -> Settings -> Notifications
- Portal/Email/WhatsApp/Text SMS master switches.
- SMTP / HTTP provider fields with encrypted secrets.
- Test Channel action.
- Event-wise channel matrix.
- Pending access escalation: Enable, First After Hours, Repeat Hours, Maximum Escalations.

### Dashboards & Reports
Role cards:
- Super Admin: users, departments, files, storage, pending approvals, broken assets, failed notification deliveries, audit integrity.
- Department Admin: department files, approvals, recent uploads, department activity.
- User: accessible files, favorites, recent files, my requests.

Reports:
- Access / Download report.
- Activity / Security report.
- Notification Delivery report (Super Admin).
- CSV export and Print / Save PDF.
- Audit Integrity verification.


---

## v09.00 Organization / Users / Access UI

### Access Control
- Protected request cards show requester, level, status and expiry. Authorized reviewer selects View/View+Download and optional expiry hours.
- Bulk Access Request: scope = Event/Prasang, Category or Sub-category; level + business reason.
- Delegated Approval Windows: delegate, start, end, reason, active/disabled history.
- User Lifecycle table: Active/Disabled/Inactive, Department, Sub-department/Team, Base Role, Permission Set, same-department Successor, Temporary Password and Logout All.
- CSV Access Review export + CSV import.
- Organization hierarchy editor: Department -> Sub-department -> Team.
- Custom Permission Sets: restrict upload/delete/policy/lifecycle/review/category capabilities below the base role.

### Settings -> Security & Access
- failed login limit
- lockout minutes
- inactivity session timeout
- password minimum length
- default temporary approval expiry (`0` = no default)
- signed protected-download link minutes
- max files per bulk access request

### Profile -> Active Sessions
- Current/other session, IP, browser/user agent, last activity
- Logout individual session
- Logout All Other Sessions


---

## v10.00 - Integrations & Storage Health UI

### Integrations & Health

Dashboard cards: Healthy Connections, Degraded, Unavailable, Broken Sources.

Super Admin connection form:
- Name / Type: Local, NAS, Google Drive, YouTube
- Root Path / Base URL
- Encrypted Google API Key / Access Token
- Check/Sync Frequency minutes
- Storage Warning %
- Active/Inactive
- Check Now / Edit / Delete (delete blocked when linked)

Connection cards show Status, Last Check, Last Success, Capacity, Used, Free and last error.

### Reference-only asset

Catalog NAS/Drive/YouTube/Local content without uploading duplicate bytes. Form includes category/access and the same governed structured metadata needed by normal uploads.

### File Details -> Sources

Each source row shows source type/connection, Primary flag, Active/Inactive/Missing/Broken, Last Check, Last Success and error. Actions: Open, Retry Check, Disable/Enable, Replace/Repair, Remove. Authorized users can add alternate sources and choose Primary.

### Broken-source queue

Admin health view lists Asset, Source, Status, Broken Detected, Last Success, Owner and Error. Notifications route through the existing channel/event matrix.


---

## v11.00 – Backup & Disaster Recovery UI

### Settings -> Backup & DR (Super Admin only)

- DR Readiness card: Ready / Warning / Policy Pending.
- Latest successful backup age and latest passed restore-test duration.
- RPO/RTO target + MET/MISSED status.
- Destination: Protected Local or existing absolute filesystem/NAS path.
- Responsible Owner.
- Scope toggles: DB/metadata, System Settings/configuration, Audit/security.
- Daily / Weekly / Monthly schedule controls.
- Daily / Weekly / Monthly retention days; `0` = Policy Pending/no pruning.
- RPO / RTO minutes; `0` = Policy Pending.
- External-source responsibility statement.
- Actions: Save Settings, Create Backup Now, Run Retention Scan.
- Backup History: status, class, date, size, verified time, Verify, encrypted Download.
- Restore Test Verification: backup, Passed/Failed, duration, target environment, evidence notes.
- Safety guide with console-only fresh-database restore command and APP_KEY custody warning.


---

## v12.00 UAT / Go-Live UI

### Settings -> UAT / Go-Live

- Overall readiness badge and KPI cards: UAT Passed, Signed Off, Blocking Checks, Pending Decisions.
- Run Automated Checks, Export CSV, Print/Save PDF.
- Automated checks list with PASS/WARN/FAIL and BLOCKING labels.
- P0 UAT cards: Result, Evidence Reference, Execution Notes, Save Result, Sign Off.
- Management Dependencies: Pending / Resolved / Risk Accepted + decision reference.
- Go-Live Runbook Ownership: deployment, rollback, business sign-off, smoke-test, support, planned window, rollback window, change freeze.
- Final Go-Live Review: Reject / APPROVE GO-LIVE. Approval is enforced server-side against a fresh readiness snapshot.


---

## v12.01 Stabilization Additions

### UAT evidence
- Show current release version in the UAT / Go-Live header.
- Each UAT row shows the release it was executed against.
- Sign Off is disabled while edits are unsaved or the execution belongs to another release.
- Saved approval stores the approved release version.

### Management dependencies
- Resolved / Risk Accepted requires a written decision, owner or approval-reference note.
- Blank formal decisions are rejected by the backend readiness service.

### Backup / restore gate
- Latest backup must have a current Passed checksum verification.
- Passed restore evidence cannot be recorded for an unverified backup.
- Final readiness requires a Passed restore test linked to a verified backup created by the current application release.


---

## v13.00 Remaining Feature Completion UI Map

### Settings -> Completion / Policy controls

- Branding: portal name, login text, default language, raster logo upload/reset.
- Maintenance Mode: enable, notice, Super Admin bypass.
- Security: password reset expiry, TOTP 2FA policy, IP/CIDR/VPN allow rules, trusted VPN proxy ranges and emergency Super Admin bypass.
- Metadata/Lifecycle: type-required fields, allowed status transitions, watermark defaults.
- Storage: department quota bytes/warning %, physical usage status.
- Reports: scheduled weekly/monthly jobs, CSV/XLSX format, recipients, enable/pause/manual run and run history.
- Retention: Audit Retention and Recycle Bin retention; `0` means policy pending and performs no automatic destructive action.

### Browse / File Details

- Folder assignment and hierarchy.
- Collection membership.
- Related Assets.
- Governed lifecycle transition/history.
- Watermark toggle.
- Localized core Browse/Search/Preview/Download labels.

### Authentication

- Forgot Password / reset link.
- Two-factor setup/challenge/recovery.
- Network/VPN policy is enforced when credentials are submitted, not only after authentication.

### Reports

- CSV, XLSX and browser Print / Save PDF.
- Scheduled Report scope is revalidated at generation/download time.
