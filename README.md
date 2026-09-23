# H+ Hotel Reservation System

Hotel reservation and staff management application with a React frontend and a Laravel API.

## Project layout

- `react/`: React 19 frontend built with Vite, including guest booking and staff interfaces.
- `thesis-react/`: Laravel 12 API, migrations, seeders, and backend tests.
- `docs/training/`: Staff training guides and demonstration worksheets.
- `thesis-react/docs/`: Operational and feature documentation.

## Local setup

Requirements: PHP 8.2 or newer with the extensions required by Composer, Composer, Node.js compatible with the locked Vite version, npm, and MySQL.

From a fresh clone, install the backend dependencies and create local configuration:

```powershell
cd thesis-react
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Create a dedicated local MySQL database. Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `thesis-react/.env`. Set `APP_URL=http://localhost:8000` and keep `FRONTEND_URL` and `CORS_ALLOWED_ORIGINS` consistent with the frontend origin (`http://localhost:5173` by default).

Run migrations against that local database and start the API:

```powershell
php artisan migrate
php artisan serve
```

In another terminal, prepare and start the frontend:

```powershell
cd react
npm ci
Copy-Item .env.example .env
npm run dev
```

The frontend example points to `http://localhost:8000/api`. Keep local mail delivery set to `log` and payment integration disabled until explicitly configured. Review seeders before running them; the default seeder creates demonstration records and accounts.

## Builds and checks

From `react/`:

```powershell
npm run build:staging
npm run build:production
```

Configure the appropriate local environment files before building. Staging and production require separate builds and API URLs. Never put server secrets in `VITE_` variables: their values are included in the browser bundle.

Backend tests are in `thesis-react/tests/`. Run `php artisan test` from `thesis-react/` after reviewing `phpunit.xml` and ensuring the test database configuration is isolated from real data.

## Repository contents

Environment files, installed dependencies, compiled builds, runtime storage, uploaded files, database dumps, and local deployment records are excluded. Sanitized `.env.example` templates, dependency lockfiles, migrations, source code, and bundled hotel images are included. A fresh clone does not contain existing reservations, uploaded payment evidence, or production credentials.

Publishing this repository does not deploy the application. Hosting configuration and deployment are managed separately.
