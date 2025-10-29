# Nexus AAC Skeleton

This directory contains a minimal XAMPP-friendly AAC (Account Control) skeleton for a TFS 10.98 server. The structure is intentionally lightweight so you can build a custom site quickly.

## Getting Started

1. Copy the `Site` directory into your XAMPP `htdocs` folder.
2. Update the database credentials and secrets in `config.php`.
3. Import the SQL schema from `sql/NexusDB.sql`.
4. Visit the site in your browser (e.g. `http://localhost/Site/?p=home`).

## Features

- Simple router powered by `index.php` and `routes.php`.
- Helper utilities for database access, authentication, CSRF protection, flash messaging, and JSON responses.
- Ready-to-fill page templates for the most common AAC sections.
- Admin and API scaffolding for future development.
- Themeable front-end with a default starter theme and global assets.

## Next Steps

- Flesh out the admin panel and API endpoints.
- Replace placeholder content in `/pages` with real data from your TFS database.
- Build additional themes under `/themes` and allow users to switch between them.
