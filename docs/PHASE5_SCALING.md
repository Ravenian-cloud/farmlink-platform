# Phase 5: Scaling and monetization

## Premium Accounts

- `GET /api/v1/subscription` reports the current plan, expiry, manual-renewal flag, price and currency.
- `POST /api/v1/subscription/purchase` accepts `{ "phone": "+254..." }` and initiates an M-Pesa STK checkout. It requires verified KYC and an `Idempotency-Key`.
- A successful provider-verified callback activates a 30-day Premium entitlement. Renewals extend from the current expiry; failed or unverified callbacks never grant access.
- `GET /api/v1/analytics/market/advanced` requires JWT auth and an unexpired subscription. The marketplace orders active Premium sellers' products before other listings; there is no guarantee of sale or search placement.
- M-Pesa Daraja is not a recurring billing authorization. Renewal is manual; no automatic charges are made.

Set `PREMIUM_MONTHLY_MINOR` to the approved whole-KES price before enabling checkout. A value below 100 or not divisible by 100 disables purchases.

## Logistics

- Add a `transport_provider` user, complete the manual KYC verification process, then call `POST /api/v1/transport/service` with county, vehicle type, optional capacity/rate and availability.
- A buyer with a paid, undisputed order requests delivery at `POST /api/v1/transactions/{transaction}/delivery`. The response confirms verified providers are available in the pickup/delivery counties.
- Authenticated users can query `GET /api/v1/transport/providers?county=Nakuru`. A provider browses open county-matched requests at `GET /api/v1/transport/deliveries`, accepts at `POST /api/v1/deliveries/{delivery}/accept`, and only the assigned provider can advance statuses through `PATCH /api/v1/deliveries/{delivery}/status`. The transaction participants and assigned provider can read status at `GET /api/v1/deliveries/{delivery}`.
- Delivery records are linked to the marketplace transaction but do not alter payment, simulated escrow, or release funds.
- Matching is county-level, not GPS/routing. Exact farm/customer coordinates are not collected or exposed. Capacity is stored but not yet used to automatically accept or price a shipment; operational quotes and provider confirmation are still required.

## Policymaker / NGO aggregates

- Partners use a provisioned `X-Partner-Key` to call `GET /api/v1/policy/insights`. Each key is scoped to one or more approved counties, and the endpoint returns only the precomputed county/commodity aggregates without account, listing or transaction identifiers. Cohorts below the analytics minimum of five trades are suppressed.
- Issue a scoped key with `php artisan farmlink:partner-key "Partner name" Nairobi Nakuru`. It is shown once; store it in the partner's approved secret store. Revoke a key with `php artisan farmlink:partner-revoke PARTNER_UUID`; issue a replacement rather than recovering a lost secret.
- Apply partner contracts, named purpose/scope, rate limits, key rotation, access monitoring, data retention and jurisdiction-specific review before sharing real data. Aggregation and thresholding reduce risk but do not guarantee legal anonymization.
- Current reports are historical paid/delivered completed trades only, recomputed daily. They are not forecasts or policy recommendations.

## Advertising

- Verified supplier accounts can upload a JPG/PNG/WebP creative up to 2 MB using `POST /api/v1/ads` (multipart form), select a role audience and provide an HTTPS destination.
- `GET /api/v1/ads/pricing` returns the configured 30-day fee. `POST /api/v1/ads/{campaign}/purchase` requests M-Pesa payment. A verified payment changes the campaign to `pending_review`; only IDs in `AD_ADMIN_IDS` can approve/reject at `POST /api/v1/admin/ads/{campaign}/review`.
- Approved ads run for 30 days and appear in a randomized sponsored dashboard placement for the selected role. They are explicitly labelled Sponsored and are not organic recommendations.
- Impression/click events are pseudonymized by campaign/account hash and deduplicated by event type for 24 hours. Advertisers see aggregate counts only. Uploaded creatives are served only for active campaigns; campaigns can be rejected or removed by the configured administrator.

Configure `AD_CAMPAIGN_MONTHLY_MINOR` to an approved whole-KES price; zero disables paid uploads. Set `AD_ADMIN_IDS` to a comma-separated list of trusted administrator UUIDs through deployment configuration, never a public form. Define refund rules for rejected creative before accepting payments.

The advertiser UI reports upload/payment errors; its impression/click events use a per-account pseudonymous hash and are deduplicated once per event type per day. Add malware/content scanning, a retention schedule for creative files, and a moderation/refund escalation process before accepting real advertising spend.

## Deployment and readiness

The Phase 5 database changes are in `2026_11_05_000010_create_phase5_marketplace_services.php`; run the migration with the regular reviewed migration job. Add the monetization price/admin variables to deployment config and keep checkout disabled until pricing, support, provider onboarding, moderation, refund, tax and partner agreements are approved.

The implementation does not add automated credit-card billing, GPS-based route optimization, logistics payouts, third-party policy datasets, audience profiling, advertiser user-level analytics or real escrow. Run the new PHPUnit tests, PostgreSQL migration/integration checks, upload scanning review, payment callback tests and partner access review before enabling features.
