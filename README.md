# Reconcile

[![CI](https://github.com/bleedingdeacons/reconcile/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/reconcile/actions/workflows/ci.yml)
[![Coverage Status](https://coveralls.io/repos/github/bleedingdeacons/reconcile/badge.svg?branch=main)](https://coveralls.io/github/bleedingdeacons/reconcile?branch=main)
![PHPStan](https://img.shields.io/badge/PHPStan-level%205-brightgreen)
![Version](https://img.shields.io/badge/version-1.13.22-blue)
![PHP](https://img.shields.io/badge/php-8.1%2B-777bb4)
![Licence](https://img.shields.io/badge/licence-MIT%20(Modified)-green)

**Import and export member, group, and position data from spreadsheets using the Unity framework.**

Reconcile adds spreadsheet-based import/export workflows to the [Unity](https://github.com/bleeding-deacons/unity) intergroup management plugin. Upload `.xlsx`, `.xls`, or `.csv` files to bulk-create or update members, groups, and positions; download the current data as formatted spreadsheets for offline editing or reporting.

**Requires:** WordPress 6.0+ · PHP 8.0+
**Dependencies:** Unity, Scrutiny
**License:** MIT (Modified — see [License](#license))
**Author:** [The Bleeding Deacons](mailto:thebleedingdeacons@gmail.com)

---

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Requirements](#requirements)
- [Usage](#usage)
  - [Importing Data](#importing-data)
  - [Exporting Data](#exporting-data)
  - [Admin Pages](#admin-pages)
- [Architecture](#architecture)
- [Column Mapping](#column-mapping)
- [Building for Production](#building-for-production)
- [License](#license)

---

## Features

- **Member import** — upload a spreadsheet to create or update intergroup members in bulk. Matching is performed by member name or ID; existing records are updated and new ones are created.
- **Member export** — download all members as a formatted spreadsheet with group and position data included.
- **Group import** — bulk-create or update groups from a spreadsheet with configurable column mapping.
- **Group export** — download all groups with their associated metadata.
- **Position import** — bulk-create or update intergroup service positions from a spreadsheet.
- **Position export** — download all positions to a spreadsheet.
- **Column mapping** — each importer uses a dedicated column mapper to translate spreadsheet headers to Unity field names, tolerating variations in column naming.
- **Operation results** — every import/export returns a structured `OperationResult` with counts of created, updated, skipped, and errored rows plus per-row messages.
- **Spreadsheet reader** — a unified reader that handles `.xlsx`, `.xls`, and `.csv` formats through a single interface.
- **Audit integration** — uses Scrutiny's audit logger to record all import and export operations for GDPR compliance.
- **AJAX handlers** — import and export operations are handled via AJAX with progress feedback in the admin UI.

---

## Installation

### From a .zip archive

1. Ensure the **Unity** and **Scrutiny** plugins are installed and activated.
2. Download or build the `reconcile.zip` archive.
3. In WordPress, go to **Plugins → Add New → Upload Plugin**.
4. Upload the `.zip` file and click **Install Now**.
5. Activate the plugin.

### Manual installation

1. Clone or copy the `reconcile` directory into `wp-content/plugins/`.
2. Activate the plugin from the WordPress admin.

---

## Requirements

- **WordPress** 6.0+
- **PHP** 8.0+
- **Unity** plugin — installed and activated
- **Scrutiny** plugin — installed and activated (GDPR compliance)
- **PhpSpreadsheet** — included via Composer for reading spreadsheet files

---

## Usage

### Importing Data

Navigate to the Reconcile admin pages (under the Intergroup menu). Each entity type (Members, Groups, Positions) has its own import page:

1. Select a `.xlsx`, `.xls`, or `.csv` file.
2. Upload the file — Reconcile reads the spreadsheet, maps columns to Unity fields, and displays a preview.
3. Confirm the import — records are created or updated in bulk. A summary shows how many rows were created, updated, skipped, or failed.

### Exporting Data

Each entity type also has an export option:

1. Click the **Export** button on the relevant admin page.
2. Reconcile generates a formatted spreadsheet and triggers a download in your browser.

Exports include related data (e.g. member exports include group and position columns).

### Admin Pages

Reconcile registers three admin sub-pages under the Intergroup menu:

- **Members** — import/export member data
- **Groups** — import/export group data
- **Positions** — import/export position data

Each page includes file upload forms, column-mapping feedback, and result summaries.

---

## Architecture

Reconcile follows a service-oriented architecture, registering its services into Unity's existing container via Scrutiny.

```
reconcile/
├── reconcile.php                        # Plugin bootstrap & hooks
├── composer.json                        # Dependencies & PSR-4 autoloading
├── build.php                            # Cross-platform build/packaging script
├── assets/
│   ├── css/admin.css                    # Admin page styles
│   ├── docs/reconsile.html              # Bundled HTML documentation
│   └── js/
│       ├── admin.js                     # Common admin JS
│       ├── group-admin.js               # Group import/export UI
│       └── position-admin.js            # Position import/export UI
└── src/
    ├── Plugin.php                       # Service registration & menu setup
    ├── Core/
    │   ├── OperationResult.php          # Import/export result value object
    │   └── SpreadsheetReader.php        # Unified xlsx/xls/csv reader
    ├── Admin/
    │   ├── MembersAdmin.php             # Member import/export admin page
    │   ├── GroupsAdmin.php              # Group import/export admin page
    │   └── PositionsAdmin.php           # Position import/export admin page
    ├── Member/
    │   ├── MemberColumnMapper.php       # Spreadsheet → Unity field mapping
    │   ├── MemberImporter.php           # Member create/update logic
    │   ├── MemberImportHandler.php      # AJAX import handler
    │   ├── MemberExporter.php           # Member export builder
    │   └── MemberExportHandler.php      # AJAX export handler
    ├── Group/
    │   ├── GroupColumnMapper.php        # Spreadsheet → Unity field mapping
    │   ├── GroupImporter.php            # Group create/update logic
    │   ├── GroupImportHandler.php       # AJAX import handler
    │   ├── GroupExporter.php            # Group export builder
    │   ├── GroupExportHandler.php       # AJAX export handler
    │   └── GroupLookup.php              # Group resolution by name/ID
    └── Position/
        ├── PositionColumnMapper.php     # Spreadsheet → Unity field mapping
        ├── PositionImporter.php         # Position create/update logic
        ├── PositionImportHandler.php    # AJAX import handler
        ├── PositionExporter.php         # Position export builder
        ├── PositionExportHandler.php    # AJAX export handler
        └── PositionLookup.php           # Position resolution by name/ID
```

**Service dependency graph:**

- `SpreadsheetReader` — standalone
- `MemberImporter` → MemberRepository, MemberFactory, GroupRepository, PositionRepository, AuditLogger
- `GroupImporter` → GroupRepository, GroupFactory, AuditLogger
- `PositionImporter` → PositionRepository, PositionFactory, AuditLogger
- All exporters → corresponding repositories and factories
- All handlers → corresponding importers/exporters

---

## Column Mapping

Each importer uses a dedicated column mapper that translates spreadsheet headers to Unity field names. The mappers are tolerant of common header variations — for example, a member import will recognise `First Name`, `first_name`, `FirstName`, etc. Unmapped columns are ignored.

---

## Building for Production

The included `build.php` script packages the plugin into a distributable `.zip` archive, stripping development files.

```bash
# Production build
php build.php build:production

# Development build (includes tests)
php build.php build:dev

# Clean the build directory
php build.php clean
```

You can override the version number with `--version=X.X` and add `--clean` to wipe the build directory before packaging.

---

## License

MIT License (Modified) — Copyright © 2025 The Bleeding Deacons.

This software is provided under the standard MIT license with one additional restriction: the licensee may not sell the Software, alone or as part of an aggregate software distribution containing the Software.

See [LICENSE](./LICENSE) for the full text.
