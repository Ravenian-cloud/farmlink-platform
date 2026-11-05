# FarmLink Flutter Mobile Architecture

**Status:** Proposed screen map and offline-first design. The current mobile scaffold contains a minimal `OfflineSync` placeholder and no state-management or persistence dependencies yet.

## Screen map and navigation

```text
Launch
├── Onboarding / language selection
├── Sign in / create account / verification status
└── Authenticated shell (role-aware)
    ├── Home dashboard
    │   ├── Farmer: produce, queued listings, offers, orders
    │   ├── Buyer: nearby produce/inputs, offers, orders
    │   └── Supplier: input inventory, enquiries, orders
    ├── Marketplace
    │   ├── Search and filters
    │   ├── Listing detail
    │   └── Offer / counteroffer (online required)
    ├── My activity
    │   ├── My listings and local drafts
    │   ├── Offers and negotiation history
    │   └── Orders and payment status
    ├── Knowledge hub
    ├── Community forum
    └── Profile / settings
        ├── Language
        ├── Approximate location and permissions
        ├── Pending sync queue and item status
        └── Data/privacy information
```

Use a persistent bottom navigation for the primary four or five destinations and a role-aware dashboard within Home. Keep navigation and action names consistent with the web client. Deep-linked screens should handle expired sessions and missing/offline content gracefully.

## UI state and code organization

Recommended state management: Riverpod providers/notifiers (or Bloc if the team standardizes on it; choose one rather than mixing). Split transient presentation state from durable local data.

```text
lib/
  app/                 # app bootstrap, router, themes, localization delegates
  features/
    auth/ marketplace/ listings/ offers/ orders/
    dashboard/ knowledge/ forum/ profile/ sync/
  data/
    api/                # authenticated API client and DTOs
    database/            # local DB, schema and migrations
    repositories/        # remote/local coordination
  l10n/                 # ARB translation catalogs
  services/             # connectivity, location, notifications
  utils/
```

- UI watches immutable feature state and emits user intents; repositories own data access and synchronization decisions.
- Remote JSON is parsed into typed DTOs and mapped to local/domain models. Never pass raw maps across screens.
- Feature state models represent loading, loaded, empty, offline/stale, and error states explicitly.
- Persist only data needed for offline use. Store credentials using platform secure storage; do not put tokens in the SQLite database or logs.
- API calls that create offers/payments require connectivity and use server idempotency keys. Do not queue an M-Pesa payment initiation for a later silent replay.

## Offline data entry and synchronization

### Offline-supported actions

In v1, allow a farmer/supplier to create or edit an **unpublished local listing draft** offline, and optionally queue explicitly safe listing create/update operations for synchronization. Label each item `Draft on this device`, `Waiting to sync`, `Syncing`, `Synced`, or `Needs attention`. Confirm before a user discards a local draft.

Do not allow offline acceptance/rejection of offers, negotiation, payment, escrow release, delivery confirmation, or any action whose validity depends on current server state. Browsed listings and articles may be shown from a clearly marked cached snapshot, subject to freshness and privacy policy.

### Local outbox model

Persist pending work transactionally in a local database (Drift/SQLite or equivalent). Suggested record:

```text
PendingOperation
  operationId: UUID (primary key)
  entityType: listing
  operation: create | update
  localEntityId: UUID
  serverEntityId: UUID?       # set after create acknowledgement
  payload: versioned JSON
  idempotencyKey: UUID
  baseVersion: integer?       # optimistic concurrency, if API supports it
  createdAt: UTC timestamp
  attemptCount: integer
  nextAttemptAt: UTC timestamp?
  state: pending | syncing | failed | conflict
  lastErrorCode: string?
```

Use a separate local listing table with local/server IDs and `syncState`. Store operation and local entity changes in one local DB transaction. Encrypt sensitive local data where the threat model/platform requires it, apply retention and logout-clearing rules, and never store KYC documents or payment secrets in an outbox.

### Sync algorithm

1. Enqueue only an allowlisted operation with validated local payload, a generated operation ID and stable idempotency key.
2. Start sync on app foreground, explicit user request, and connectivity recovery. Connectivity signals are hints; confirm the API is reachable.
3. Acquire a single-flight lock so multiple app lifecycle/connectivity events cannot send the same queue concurrently.
4. Send oldest eligible operation with its idempotency key, auth, schema version and (for edits) expected server version if supported. Keep per-account queues isolated.
5. On acknowledged success, atomically persist server ID/version and mark the operation synced. Remove outbox row only after the entity update commits locally.
6. Retry network timeouts, `429` and transient `5xx` with exponential backoff and jitter; respect `Retry-After`, cap delay/attempts, and keep the operation visible. Reuse the same idempotency key on retry.
7. On `401`, pause and request re-authentication; resume only for the same account. On `422`, mark `needs attention` with field errors and let the user edit. On `403` or deleted/invalid resources, stop automatic retries and explain the issue.
8. On a version conflict (`409`), fetch the current server version when online. Show a comparison and require user resolution; do not silently overwrite server data or discard the local draft.
9. After sync, refresh affected queries and show a clear outcome. A queued listing is not public until the server confirms creation and validation.

If offline create then edit occurs before synchronization, update the queued create payload rather than sending a create followed by unnecessary updates. If an operation is explicitly canceled, remove it only after confirming the corresponding local draft can safely be retained or discarded.

### Background execution and limitations

Use platform background scheduling only as a best-effort optimization; iOS/Android may defer or stop work. Foreground sync and a visible manual “Sync now” action are required. The server remains the source of truth. Do not promise immediate sync or exactly-once network delivery; use server idempotency for effectively-once processing.

### Conflict rules

The server wins for authoritative status, payments, offers and inventory committed by other users. For user-authored listing fields, preserve local edits and offer a deliberate merge/retry after comparing server and local versions. Quantity is especially sensitive: synchronize an absolute intended value only with version checking; do not apply repeated increments blindly.

## Localization and accessibility

- Use Flutter localization delegates and ARB catalogs (e.g. English and Swahili to start); select from device preference with an in-app override.
- Localize UI strings, validation, date/number formatting and units; never use translated labels as persisted role/status identifiers.
- Cache required locale catalogs with the app. User-generated content and articles may only be available in their authored language; label language and offer translation as a separate, explicit feature.
- Support larger text, screen readers, semantic labels, keyboard/accessibility focus, adequate contrast and non-color status indicators.
- Store user language preference on server and locally; define how preference changes synchronize when offline.

## Location-based matching

Request location permission only after a user action explaining the benefit (nearby listings/suppliers). Provide manual county/area selection as a full alternative.

- Use coarse coordinates for marketplace distance/radius queries; keep exact farm coordinates private unless a specific fulfillment workflow requires sharing and both parties are informed.
- Send coordinate and radius only with consent and over HTTPS. Avoid persistent tracking/background location in v1.
- Explain permission denial and provide settings/manual-search recovery; do not block browsing.
- The backend should perform filtering and return approximate distance/county, not other users' precise coordinates. Ensure cached location results expire and are scoped by account/location settings.
- Avoid location in analytics/logging unless aggregated and explicitly required; obtain consent and follow applicable privacy rules.

## Payments and offline safety

Payment, escrow, offer acceptance, and order completion require a live authenticated server response. Keep payment provider credentials exclusively on the backend. The mobile UI can display cached order details marked stale, but must fetch current server status before enabling financial actions. A client timeout means “status unknown”; refresh payment status rather than creating another payment blindly.

## Testing and rollout

Test repository behavior with mocked API and local database; test crash/restart recovery, duplicate queue triggers, retry/idempotency, account switch, auth expiry, validation errors and conflicts. Add integration tests for airplane-mode listing drafts and later sync. Exercise location denied/manual selection, language switching, long translations and screen readers. Start with offline drafts, observe reliability, then enable queued creates/updates behind a feature flag.

### Phase 2 implementation scope

The current `OfflineSync` scaffold persists new product submissions locally and retries them with a stable idempotency key. Bid/negotiation data is saved as a local draft and is submitted only after the user explicitly requests it while online; it is not automatically replayed after connectivity returns. Listing edits, background platform schedulers, local encryption, localization UI and location UI remain follow-up work.
