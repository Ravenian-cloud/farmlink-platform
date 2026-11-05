# FarmLink Phase 3 Pilot Testing

## Backend automated tests

From `backend-php`, install Composer dependencies, then run:

```powershell
composer install
composer test
```

The PHPUnit suite uses an in-memory SQLite database and covers bid submit/accept/reject authorization and state transitions, frozen order pricing/stock decrement, M-Pesa callback success/failure/amount mismatch with mocked HTTP, county-targeted rainfall/temperature/pest notifications, duplicate/stale weather events, weather webhook authentication, and pilot feedback submission/summary authorization.

Run the production database migrations against a disposable PostgreSQL database before the pilot as well; SQLite tests do not replace PostgreSQL migration/integration checks.

## Flutter checks

From `mobile-app`:

```powershell
flutter pub get
flutter test
```

The offline sync unit test uses an in-memory SQLite database and mocked HTTP/connection state. The device integration test uses SQLite on-device. Both verify a produce listing queues offline and syncs after reconnection; bid acceptance is **refused offline** and succeeds only through the server after connectivity is restored. Offer drafts may be saved offline but are submitted explicitly while online. Acceptance requires a caller-held idempotency UUID so the same key can be reused after an ambiguous timeout. The language widget test switches English to Kiswahili and checks visible labels.

Run the device integration test with `flutter drive --driver=test_driver/integration_test.dart --target=integration_test/offline_sync_integration_test.dart` on representative Android devices to validate storage behavior and device/API reachability. Network state in this test is injected; verify real connectivity transitions separately on pilot devices.

## Web checks

From `frontend-web`:

```powershell
npm install
npm run build
```

Manually pilot the feedback form with bug, negotiation, and recommendation categories; verify server validation, duplicate-submit handling, mobile layout, and accessible keyboard/screen-reader labels. Add a JavaScript component test runner before relying on automated UI regression tests.

## Weather/alert pilot safety

Configure a unique `WEATHER_WEBHOOK_SECRET` in the deployment secret store and require HTTPS. Send only provider-authorized feeds, monitor rejected/stale events, and verify county targeting before enabling alerts for real users. Alerts are heuristic and informational; no push/SMS service is configured, and no alert should be presented as a diagnosis or guaranteed forecast.

## Pilot controls and exit criteria

- Start with a small, consented group of farmers, buyers, and suppliers; provide a support contact and a way to report bugs.
- Use sandbox M-Pesa only. Escrow remains simulated and must not be used to hold, release, or promise custody of real funds.
- Record consent, app/version, feedback category, and severity without collecting passwords, payment credentials, or unnecessary personal data.
- Review critical defects, authorization failures, payment mismatches, lost/duplicated offline submissions, and accessibility blockers before increasing pilot size.
- Review negotiation and recommendation ratings alongside sample counts and qualitative reports; do not treat small pilot averages as model validation.
- Do not graduate to production payments, automated financial custody, or safety-sensitive AI recommendations without separate operational, legal, security, and agronomy reviews.
