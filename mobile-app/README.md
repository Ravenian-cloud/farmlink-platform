# FarmLink mobile MVP

## Run

This checkout currently contains Flutter/Dart sources but no generated Android
or iOS platform folders. With the Flutter SDK installed, generate the mobile
targets once from `mobile-app` using
`flutter create --platforms=android,ios .`. Then run `flutter pub get` and
`flutter run`. Android emulators use
`http://10.0.2.2:8000/api/v1` by default. Override the API URL for a device or
deployment with:

```powershell
flutter run --dart-define=FARMLINK_API_BASE_URL=https://your-api-host/api/v1
```

The app's token field accepts an existing Laravel bearer access token. Tokens are
kept in memory only and are not written to the offline database. Product
publishing requires a valid token, a verified KYC account, and the matching
backend role: farmers create produce listings and suppliers create input
listings. Authentication/account creation is not part of this MVP.
The local queue is scoped to one configured device account ID
(`FARMLINK_LOCAL_ACCOUNT_ID`, default `local-device`); do not change the token to
another seller while that queue contains unsynced listings.

## Listings and offline behavior

The listing form sends only fields accepted by `POST /api/v1/products`.
Prices are entered in KES and stored as integer minor units; listing payloads
include `currency: KES`. An offline listing is saved locally and retried with a
stable idempotency key and bounded request timeout/backoff. Its local sync state,
attempt count, last error, and created server product ID are retained in SQLite.
The screen also loads the authenticated seller's listings from
`GET /api/v1/my/products`. Use **Sync now** or **Retry** after restoring connectivity
or supplying a valid token. Validation, authorization, and KYC rejections remain
visible for action.

Language preference (English or Kiswahili) is stored locally. Offline bid
acceptance is intentionally unsupported; the existing queue keeps acceptance
online-only.
