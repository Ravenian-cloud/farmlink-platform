# FarmLink Phase 2 Setup

The Phase 2 files are starter implementations. Install the required runtimes and configure secrets locally before running them. Never commit `.env` or production credentials.

## Backend and PHP web frontend

Requirements: PHP 8.2+, Composer, MySQL or PostgreSQL, and PHP extensions for the selected database driver plus `openssl`, `mbstring`, `tokenizer`, `xml`, `ctype`, `fileinfo`, and `json`.

1. Enable the PDO extension for your database in `C:\xampp\php\php.ini` (for MySQL, `pdo_mysql`; for PostgreSQL, `pdo_pgsql`), then restart Apache/CLI processes. Verify with `C:\xampp\php\php.exe -m`.
2. From `backend-php`, install Composer dependencies. Copy `.env.example` to `.env` only if a local `.env` does not already exist; preserve existing local configuration.
3. Configure the database credentials and generate the Laravel key:

   ```powershell
   C:\xampp\php\php.exe artisan key:generate
   ```

4. Generate a separate, random JWT signing secret and set it as `JWT_SECRET` in `.env`. Use at least 32 cryptographically random bytes; do not reuse `APP_KEY`.
5. Configure M-Pesa **sandbox** credentials and an HTTPS callback URL. The callback must be reachable by Safaricom; `localhost` alone will not receive provider callbacks.
6. Run migrations and start the web application and API:

   ```powershell
   C:\xampp\php\php.exe artisan migrate
   C:\xampp\php\php.exe artisan serve --host=0.0.0.0
   ```

   The PHP-rendered web interface is served from the same Laravel application at `http://127.0.0.1:8000`; its API base is `/api/v1`. The web UI uses Blade templates, CSS, and vanilla JavaScript, so Node.js and Vite are not required to run it.

The Laravel migrations define the schema expected by this application. They are not interchangeable with the separate integer-ID SQL schema described in the database proposal.

The database escrow fields are simulated state only. Daraja STK Push does not provide conditional escrow custody or payout. Do not use production funds until a regulated custody/escrow arrangement and payout integration are designed, reviewed and tested.

## Flutter mobile

Requirements: Flutter and the Android/iOS toolchain.

From `mobile-app`, run `flutter pub get`. Construct `OfflineSync` with the API base URL, the current account ID, and a secure-storage-backed access-token provider. The API URL must use the machine/LAN address reachable from the device or emulator, not the device's own `localhost`.

The sync engine retries offline listing creation. Offline bids remain drafts and require explicit online submission. It does not silently replay payments, escrow actions, or negotiation decisions.

## Validation status

PHP syntax and JSON manifest checks passed in the current workspace. Full Laravel migrations/API execution could not be run here because Composer is unavailable and `pdo_pgsql` is currently commented out in the local XAMPP PHP configuration. Node/npm and Flutter/Dart are also unavailable, so frontend build and Flutter analysis remain to be run after those tools are installed.

Phase 3 automated checks and pilot setup are documented in [PILOT_TESTING.md](./PILOT_TESTING.md).
