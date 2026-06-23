# IBC Office Tracker

A self-hosted web app for **(1) tracking invoices submitted to finance** and **(2) tracking new fit-out projects** across four locations — DD, 1-OAR, GE, KP — with a visual "building bars" view of office occupancy.

Built to last: **plain PHP 8 + MySQL**, no frameworks, no build step, no dependencies to rot. Runs on standard cPanel / InMotion shared hosting (incl. the *WP Power* plan).

---

## Features

**Invoices**
- Add invoices with date, vendor, invoice #, amount, category, status (Draft → Submitted → Approved → Paid / Rejected), finance-submission date, notes
- Attach the invoice scan/PDF; **View** button opens it inline
- Filter by vendor, status, category, date range + free-text **search**
- **Sort** ascending/descending on every column
- Filtered **summary** (count, total value, average) and **CSV export**
- Duplicate heads-up (same vendor + amount)
- Dashboard: monthly trend chart, top vendors, totals

**Projects**
- Name, location, tower, floor, handover date, status, client, area, notes
- **Project type** field (e.g. CS / Fit-out / Other) with a matching filter
- Upload **LOI & other documents** per project
- Three views: **🏙️ Building bars** (each location drawn as towers × floors; active projects glow in the building's colour, vacant floors are empty outlines, click a floor → project drawer) · **🗂️ Open-project cards** (handover shown as a colour-coded countdown) · **📋 Table**
- Filter by location / status / project type / open-only + search
- Handover countdown colour-codes urgency (red ≤ 7 days, amber ≤ 30, green beyond, grey overdue)

**Asset Management**
- Track equipment (name, tag, category, serial, status) and **who it's assigned to and since when**
- Assignees come from a **People directory** that can be synced from **Microsoft 365** (or maintained manually)
- Per-asset **history trail** (assigned / returned / status changes) in the detail drawer
- Filter by status / assignee, search, and CSV export

**Brochures**
- Provider document library (**Spintly, TATA, ACT**, …) grouped by provider — no pricing
- One file per brochure (PDF/image/doc); everyone signed in can view & download

**Accounts & security**
- Individual logins with **three roles**:
  - **Admin** — full control: users, buildings, settings, the People directory & M365 sync, delete anything
  - **Member** — add anything; edit/delete their own records (invoices, projects, assets, brochures)
  - **View only** — read-only access to **Projects & Brochures only**; no invoices, no assets, and **no pricing** anywhere (enforced server-side)
- A custom date picker on every date field so any past/future month is reachable
- Hashed passwords, session login, CSRF protection
- Uploaded files live **outside the web root** and are streamed only to logged-in users — finance documents are blocked from view-only users

> **Note on AI auto-extraction:** it is intentionally **off**. Your Claude Max subscription cannot power a multi-user server (it's a personal, interactive plan). The code has a dormant hook for the paid Anthropic API — set `ai.enabled => true` and add a key in `config.php` later if you ever want it. Until then, invoice details are entered manually.

---

## Deploy to InMotion (cPanel) — step by step

### 1. Create the database
cPanel → **MySQL® Databases**:
1. Create a database (e.g. `tracker`). Note the full name, e.g. `usr1234_tracker`.
2. Create a database user with a strong password.
3. **Add the user to the database** and grant **ALL PRIVILEGES**.

### 2. Upload the files
Decide where it lives:
- **Subdomain (recommended):** cPanel → *Domains* → create `tracker.yourdomain.com`. Point its document root at a new folder, e.g. `tracker/`.
- **Subfolder:** just use `public_html/tracker/`.

Then upload **all the files in this folder** into that location. Two ways:
- cPanel → **File Manager** → upload a ZIP of this folder and *Extract*, **or**
- Use the `git` repo (Git Version Control in cPanel, or FTP).

> The `_preview/` folder is only a local design tool — it's git-ignored and does not need to be uploaded.

### 3. Make the uploads folder writable
In File Manager, set permissions on `storage/` and `storage/uploads/` to **755** (or 775 if 755 doesn't allow writes). This is where attached files are saved.

### 4. Run the installer
Visit **`https://tracker.yourdomain.com/install.php`** in your browser. Fill in:
- the database name / user / password from step 1,
- your admin name, username, password,
- app name + currency symbol.

Click **Install**. It builds all tables, seeds the four buildings, and creates your admin account.

### 5. Lock it down
**Delete `install.php`** from the server (File Manager → select → Delete). The app refuses to re-run setup once installed, but removing the file is cleaner.

### 6. Done
Open the site, sign in, and:
- **Buildings** (admin) → set the real towers / floor counts / colours for DD, 1-OAR, GE, KP so the visual matches your actual buildings.
- **Users** (admin) → add your colleagues.
- Start adding invoices and projects.

---

## Upgrading an existing install
Just **upload the new/changed files over the old ones** (keep your `config.php`). On the next page load the app detects the schema is behind and **migrates the database itself** — it adds the `viewer` role, the project `project_type` column, and the new `people` / `assets` / `asset_events` / `brochures` tables. It is safe to re-run and never touches your existing data. No SQL to run by hand, no `install.php` needed.

## Connect Microsoft 365 (optional)
Asset assignment works straight away by adding people manually under **Directory** (admin). To pull your staff list in automatically:

1. In the **Microsoft Entra admin center** → *App registrations* → **New registration** (single tenant is fine).
2. Under **API permissions** add **Microsoft Graph → Application permission → `User.Read.All`**, then click **Grant admin consent**.
3. Under **Certificates & secrets** → **New client secret**; copy the secret **Value** (not the ID).
4. From the app's **Overview**, copy the **Directory (tenant) ID** and **Application (client) ID**.
5. Edit `config.php` and fill the `m365` block, then set `enabled => true`:
   ```php
   'm365' => [
       'enabled'       => true,
       'tenant_id'     => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
       'client_id'     => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
       'client_secret' => 'the-secret-value',
       'sync_disabled_users' => false, // set true to also deactivate people disabled in M365
   ],
   ```
6. In the app, go to **Directory** → **Sync now**.

> Login stays username/password — this only syncs the people directory (no SSO). The secret lives only in `config.php` (git-ignored) and is never sent to the browser.

## Requirements
- PHP **8.0+** with PDO MySQL (standard on InMotion)
- MySQL / MariaDB
- HTTPS (use cPanel's free AutoSSL) — recommended, since the login cookie is marked Secure under HTTPS

## Tech notes
- `index.php` — app shell + login · `api.php` — JSON/file API · `install.php` — one-time setup
- `lib/` — bootstrap, db, auth, helpers, **migrate** (auto schema upgrades), **m365** (Graph sync), request handlers incl. `assets` / `brochures` / `people` (blocked from direct web access)
- `assets/` — CSS + JS (vanilla, no build), incl. `datepicker.js` · `sql/schema.sql` — schema + seed data
- Config & secrets live in `config.php` (generated by the installer; git-ignored)

## Local design preview (optional, for developers)
`_preview/demo.html` renders the full UI with mock data and no backend. Serve the project folder with any static server (the included `_preview/serve.ps1` works on Windows/PowerShell) and open `/_preview/demo.html`.
