# FarmLink Phase 3 Pilot Testing

## Backend automated tests

From `backend-php`, install Composer dependencies, then run:

```powershell
composer install
composer test
```

The PHPUnit suite uses an in-memory SQLite database and covers bid submit/accept/reject authorization and state transitions, frozen order pricing/stock decrement, M-Pesa callback success/failure/amount mismatch with mocked HTTP, county-targeted rainfall/temperature/pest notifications, duplicate/stale weather events, weather webhook authentication, and pilot feedback submission/summary authorization.

Run the production database migrations against a disposable PostgreSQL database before the pilot as well; SQLite tests do not replace PostgreSQL migration/integration checks.

Run the PostgreSQL-only transport registration regression with the test database configured to PostgreSQL:

```powershell
$env:DB_CONNECTION = 'pgsql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '5432'
$env:DB_DATABASE = 'farmlink_test'
$env:DB_USERNAME = 'farmlink_test'
$env:DB_PASSWORD = '<locally-provisioned-test-password>'
composer test -- --filter=TransportProviderRegistrationPostgresTest
```

Use a disposable test database; `RefreshDatabase` runs the migrations and test transaction against it. The test is skipped when the configured driver is not PostgreSQL.

## Flutter checks

From `mobile-app`:

```powershell
flutter pub get
flutter test
```

The offline sync unit test uses an in-memory SQLite database and mocked HTTP/connection state. The device integration test uses SQLite on-device. Both verify a produce listing queues offline and syncs after reconnection; bid acceptance is **refused offline** and succeeds only through the server after connectivity is restored. Offer drafts may be saved offline but are submitted explicitly while online. Acceptance requires a caller-held idempotency UUID so the same key can be reused after an ambiguous timeout. The language widget test switches English to Kiswahili and checks visible labels.

Run the device integration test with `flutter drive --driver=test_driver/integration_test.dart --target=integration_test/offline_sync_integration_test.dart` on representative Android devices to validate storage behavior and device/API reachability. Network state in this test is injected; verify real connectivity transitions separately on pilot devices.

For staging and production release builds, set `FARMLINK_API_BASE_URL` to the approved HTTPS API origin plus `/api/v1` using `--dart-define`; do not ship the Android emulator default (`10.0.2.2`). Confirm the endpoint resolves from representative devices, has a valid TLS certificate, and matches backend CORS and callback configuration.

## PHP web checks

From `backend-php`, run the Laravel test suite and serve the application:

```powershell
composer test
php artisan serve
```

**Frontend plan:** Laravel Blade templates and vanilla JavaScript are the supported web app and should remain the canonical web client. `frontend-web` has no tracked application source or package manifest; it is not a deployable React app. The empty root npm lockfile was removed. Do not build or release it as a second client. If a standalone React client is approved later, create it as a new, separately scoped project with its own manifest, source, API contract, tests and deployment plan. For this pilot, manually test the Laravel marketplace, account registration/sign-in, negotiation, feedback categories, community/forum, impact reporting and supplier advertising; verify server validation, duplicate-submit handling, mobile layout and accessible keyboard/screen-reader labels.

## Weather/alert pilot safety

Configure a unique `WEATHER_WEBHOOK_SECRET` in the deployment secret store and require HTTPS. Send only provider-authorized feeds, monitor rejected/stale events, and verify county targeting before enabling alerts for real users. Alerts are heuristic and informational; no push/SMS service is configured, and no alert should be presented as a diagnosis or guaranteed forecast.

## Pilot controls and exit criteria

- Start with a small, consented group of farmers, buyers, and suppliers; provide a support contact and a way to report bugs.
- Use sandbox M-Pesa only. Escrow remains simulated and must not be used to hold, release, or promise custody of real funds.
- Before staging sign-off, provision separate staging secrets (app key, JWT secret, database password, sandbox M-Pesa credentials, and webhook secrets) in Secret Manager, and grant access only to the staging workload identity. Generate secrets through the approved secret-management process; do not commit them, paste them into manifests, or use production values in staging.
- Create named administrator accounts, verify their owners and MFA, then set the exact account UUIDs in `KYC_ADMIN_IDS`, `COMMUNITY_ADMIN_IDS`, and `AD_ADMIN_IDS` as needed. These accounts and UUIDs are not provisioned yet. Keep the lists least-privileged, never use a shared admin identity, and verify an ordinary account is denied each admin route. Do not leave required production admin allowlists empty.
- KYC reviewers: use two named, trained reviewers; prohibit self-review and record a reason for every decision. MFA is required, but the current API does not enforce it, so admin use is blocked until an approved MFA-backed identity layer is in place. Before onboarding, the organization must still approve evidence types/checks, secure evidence storage and access, retention/deletion period and owner, and an appeal channel, independent review and response target. Evidence stays outside this API; do not collect or attach identity documents to pilot feedback or support tickets. The API records decisions and an append-only audit trail, but does not verify identity or store evidence.
- Reconcile sandbox M-Pesa activity against provider reports at least daily during pilot: compare checkout/request IDs, receipt, amount, currency, final status and timestamps; investigate missing, duplicate, delayed, out-of-order or mismatched callbacks; test callback retries/idempotency and document who owns reconciliation, refunds and disputes. Define an approved manual refund/dispute process before accepting pilot payments. Do not enable live payments or describe simulated escrow as custody or conditional release.
- Assign named owners before inviting pilot users: support contact and response coverage; incident commander/escalation; community/ad moderation; payment reconciliation/refund decisions; database backup and restore. Run and record a backup restore drill on staging, confirm moderator access and escalation, and rehearse a user onboarding/support path without collecting unnecessary personal data.
- Pilot mobile offline behavior with consenting users on representative devices: publish/retry a listing offline and online, verify stable idempotency and visible sync failures, test account/token expiry and recovery, and ensure queues do not cross accounts. The mobile app currently keeps bearer tokens in memory only, so production release is blocked until secure token storage and its threat/privacy review are complete.
- Before store submission, finish platform-specific signing/account setup, Data safety/App Privacy and privacy-policy disclosures, support/contact details, and internal/closed-track review using the exact production API URL. Follow the Android/iOS release checklist in `PHASE4_FULL_LAUNCH.md`.
- Release gate: apply migrations to a disposable PostgreSQL database and pass the PostgreSQL registration regression, `composer test`, `flutter analyze`, `flutter test`, and a signed staging build with the approved HTTPS API URL. Validate `kubectl kustomize k8s/staging`, staging health/readiness, TLS/CORS, callback delivery, logs/alerts, backup restore, rollback and mobile smoke tests before any production promotion. Production remains blocked until all placeholders and pilot sign-offs are resolved.
- Record consent, app/version, feedback category, and severity without collecting passwords, payment credentials, or unnecessary personal data.
- Review critical defects, authorization failures, payment mismatches, lost/duplicated offline submissions, and accessibility blockers before increasing pilot size.
- Review negotiation and recommendation ratings alongside sample counts and qualitative reports; do not treat small pilot averages as model validation.
- Do not graduate to production payments, automated financial custody, or safety-sensitive AI recommendations without separate operational, legal, security, and agronomy reviews.
