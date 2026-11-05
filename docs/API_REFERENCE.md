# FarmLink API Reference

**Status:** Proposed contract for the Laravel 11 / PHP 8.2 API and PostgreSQL.  
**Base path:** `/api/v1`  
**Transport:** HTTPS only; JSON request and response bodies use UTF-8.

This document defines the intended first marketplace release: farmer, buyer and supplier accounts; produce and agricultural-input listings; offers and counteroffers; and a M-Pesa-backed payment lifecycle. The Phase 2 Laravel scaffold implements a subset of this contract; see the implementation-path note below and the setup guide before treating it as production-ready.

The Phase 2 code currently uses `/products` for the listing resource, `/products/{product}/bids` and `/bids` for offer negotiation, and `/transactions` for accepted purchases and payments. Product payloads use `name`, `price_minor`, and `quantity_available`; bid payloads use `unit_price_minor`. The endpoint tables below describe the intended normalized API contract and should be reconciled before publishing a stable external API.

## Conventions

- IDs are UUIDs. Timestamps are ISO 8601 UTC strings.
- Money is represented as integer minor units with an ISO 4217 currency code. Never use floating-point amounts.
- The current Daraja checkout implementation supports `KES` only and requires accepted order totals to be positive whole-KES amounts.
- Quantities include an explicit unit (`kg`, `bag`, `litre`, `piece`, etc.).
- Authenticated routes require `Authorization: Bearer <token>`. Use a Laravel-compatible token/session implementation; never store raw tokens.
- Users have one primary role: `farmer`, `buyer`, `supplier`, or `transport_provider`. A transport provider must pass KYC and register a county-level service before accepting delivery requests.
- Paginated list endpoints accept `page`, `per_page` (default 20, maximum 100), and documented filters. Responses include `data` and `meta`.
- Mutations that may be retried accept an `Idempotency-Key` UUID. Reuse of a key with a different request body returns `409 IDEMPOTENCY_KEY_REUSED`.
- All writes are validated server-side; prices, ownership, permissions and escrow state are never trusted from the client.

### Common error format

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The request contains invalid fields.",
    "details": { "quantity": ["Must be greater than zero."] },
    "request_id": "req_01J..."
  }
}
```

Common HTTP responses: `400` malformed request, `401` unauthenticated, `403` forbidden, `404` not found, `409` state or idempotency conflict, `422` validation failure, `429` rate limited, `500` unexpected server error. Do not return stack traces or provider secrets.

## Roles and authorization

| Resource/action | Farmer | Buyer | Supplier |
|---|---:|---:|---:|
| Browse active listings | Yes | Yes | Yes |
| Create/update own produce listing | Yes | No | No |
| Create/update own input listing | No | No | Yes |
| Submit/counter an offer | No, unless a future multi-role account is enabled | Yes | Yes, for input listings |
| Accept/reject offer on own listing | Yes | No | Yes, for own input listing |
| Initiate payment for own accepted purchase | No | Yes | No |
| Confirm delivery / raise dispute | Buyer on own purchase | Yes | No |
| View own orders, transactions and profile | Yes | Yes | Yes |

Authorization is enforced by policies/services for every resource, not by hiding UI controls. Never allow a user to purchase their own listing.

## Endpoints

### Authentication and account

| Method | Path | Access | Purpose |
|---|---|---|---|
| `POST` | `/auth/register` | Public | Create account and role-specific profile |
| `POST` | `/auth/login` | Public | Authenticate and issue access credentials |
| `POST` | `/auth/logout` | Authenticated | Revoke current credential |
| `GET` | `/me` | Authenticated | Return account and profile |
| `PATCH` | `/me` | Authenticated | Update allowed account/profile fields |
| `PATCH` | `/profile` | Authenticated | Update the caller's county used for county-scoped services |

Register request:

```json
{
  "name": "Amina Farmer",
  "phone": "+254700000000",
  "password": "use-a-strong-secret",
  "role": "farmer",
  "profile": { "farm_name": "Green Valley", "county": "Nakuru" }
}
```

Response `201`:

```json
{
  "data": {
    "id": "uuid",
    "name": "Amina Farmer",
    "phone": "+254700000000",
    "role": "farmer",
    "verification_status": "pending",
    "profile": { "farm_name": "Green Valley", "county": "Nakuru" },
    "created_at": "2026-11-05T10:00:00Z"
  }
}
```

The supplier profile should capture business name and registration details where applicable; buyer profiles may capture organization and delivery preferences. Collect only data necessary for the product and applicable KYC requirements. Do not expose KYC documents through public profile/listing endpoints.

### KYC administrator review

| Method | Path | Access | Purpose |
|---|---|---|---|
| `PATCH` | `/admin/kyc/users/{userId}/review` | JWT for an ID in `KYC_ADMIN_IDS`; no self-review | Record a KYC decision and append an audit entry |

The JSON body permits only `status` and `reason`; additional fields (including role or identity fields) are rejected. `reason` is required (10–2,000 characters) for every transition. Allowed transitions are `pending -> verified`, `pending -> rejected`, and `rejected -> pending`; all others are rejected. The audit stores the authenticated reviewer, target user, previous and new statuses, reason, and review timestamp. Audit records cannot be updated or deleted by the database.

This endpoint does not automate identity verification and does not receive or store evidence. An authorized reviewer must inspect evidence outside this workflow using the approved secure evidence process before making a decision. Keep sensitive identity details and evidence out of the audit reason. Configure production reviewer UUIDs through `KYC_ADMIN_IDS` in both backend ConfigMaps.

### Listings

| Method | Path | Access | Purpose |
|---|---|---|---|
| `GET` | `/listings` | Public | Browse active listings |
| `POST` | `/listings` | Farmer or supplier | Create listing matching role and category |
| `GET` | `/listings/{listingId}` | Public | Listing detail |
| `PATCH` | `/listings/{listingId}` | Owner | Edit while eligible |
| `POST` | `/listings/{listingId}/archive` | Owner | Archive listing |

Query filters: `category=produce|input`, `crop`, `input_type`, `county`, `min_price`, `max_price`, `available=true`, `lat`, `lng`, `radius_km`, `sort=recent|price_asc|price_desc|distance`. Geo filters require valid coordinate pairs; avoid returning precise farm coordinates to anonymous users.

Create listing request:

```json
{
  "category": "produce",
  "title": "Fresh maize",
  "description": "Dry, sorted maize available for collection.",
  "crop": "maize",
  "quantity": 500,
  "unit": "kg",
  "unit_price_minor": 4500,
  "currency": "KES",
  "county": "Nakuru",
  "location": { "latitude": -0.3031, "longitude": 36.0800 },
  "available_until": "2026-12-01T00:00:00Z"
}
```

Input listings use `category: "input"` plus `input_type`, `brand` and optionally `registration_reference`; seller-submitted safety/registration claims must not be presented as independently verified unless checked. Response `201` returns the created listing with `status: "active"`, owner summary and timestamps. Public detail must not disclose exact private location or owner phone number.

The authenticated implementation also provides `GET /my/products` for the caller's own listings; it does not accept a seller/user ID filter.

### Offers and negotiation

| Method | Path | Access | Purpose |
|---|---|---|---|
| `POST` | `/listings/{listingId}/offers` | Buyer; supplier for input listing | Start an offer |
| `GET` | `/offers` | Participants | List offers as buyer or seller |
| `GET` | `/offers/{offerId}` | Participants | Offer and negotiation history |
| `POST` | `/offers/{offerId}/counter` | Participants, when pending | Submit counteroffer |
| `POST` | `/offers/{offerId}/accept` | Listing owner, when pending | Accept current offer |
| `POST` | `/offers/{offerId}/reject` | Listing owner, when pending | Reject offer |
| `POST` | `/offers/{offerId}/withdraw` | Offer creator, when pending | Withdraw offer |

Start/counter request:

```json
{
  "quantity": 200,
  "unit_price_minor": 4200,
  "currency": "KES",
  "message": "Can collect on Friday."
}
```

Response `201` for create/counter:

```json
{
  "data": {
    "id": "uuid",
    "listing_id": "uuid",
    "buyer_id": "uuid",
    "seller_id": "uuid",
    "status": "pending",
    "current_offer": {
      "quantity": 200,
      "unit_price_minor": 4200,
      "currency": "KES",
      "created_by": "uuid",
      "created_at": "2026-11-05T10:00:00Z"
    },
    "expires_at": "2026-11-08T10:00:00Z"
  }
}
```

Acceptance returns `200`, transitions the offer to `accepted`, and creates exactly one order with a fixed agreed quantity, unit price, currency and total. Reject duplicate acceptance and concurrent changes with `409`. Use a database transaction and row locks for transitions. Negotiation history is append-only; do not overwrite earlier terms.

### Orders and escrow payments

The authenticated implementation provides `GET /transactions` to list only transactions where the caller is the buyer or seller, and `GET /transactions/{transactionId}` for one participant's payment status. These routes use the implementation's `/transactions` resource names; the normalized `/orders` table below remains a proposed API contract.

| Method | Path | Access | Purpose |
|---|---|---|---|
| `GET` | `/orders` | Participants | List own orders |
| `GET` | `/orders/{orderId}` | Participants | Order status and permitted payment summary |
| `POST` | `/orders/{orderId}/payments` | Buyer, accepted/unpaid order | Request M-Pesa STK push |
| `GET` | `/orders/{orderId}/payments` | Participants | Payment status (no provider secrets) |
| `POST` | `/orders/{orderId}/delivery-confirmation` | Buyer, paid order | Confirm receipt and release escrow |
| `POST` | `/orders/{orderId}/disputes` | Buyer or seller, eligible order | Open dispute |

Payment initiation request:

```json
{
  "provider": "mpesa",
  "phone": "+254700000000"
}
```

Response `202 Accepted`:

```json
{
  "data": {
    "payment_id": "uuid",
    "order_id": "uuid",
    "status": "pending",
    "provider": "mpesa",
    "checkout_reference": "opaque-reference",
    "amount_minor": 840000,
    "currency": "KES"
  }
}
```

The amount comes from the immutable accepted order; the client cannot set it. A provider callback updates payment state through the private webhook described below. An STK request being accepted is **not** proof of payment. Keep escrow funds in the configured regulated/provider arrangement; an application database record is not a custodial wallet.

The current Phase 2 code records `escrow_mode: "simulated"` only. Daraja STK Push transfers a payment to the configured merchant and does not itself create escrow or hold funds for conditional release. Do not represent this database state as real escrow or enable real-money release until a regulated escrow/custody and payout integration has been selected, legally reviewed and independently tested.

Order state: `awaiting_payment -> escrow_held -> completed`, with `cancelled` and `disputed` branches. Payment state: `initiated -> pending -> succeeded|failed|cancelled|refunded`. Escrow state: `not_funded -> held -> released|refunded|disputed`. Only an authorized, verified transition can move funds. Define timeout/refund and dispute resolution policy before production.

Delivery confirmation request:

```json
{ "received_quantity": 200, "note": "Received in good condition." }
```

Response `200` returns the order as `completed`. In production, release escrow only after server-side eligibility checks and a successful provider/payout operation; represent payout pending/failure separately and retry safely.

### Provider callback and operational endpoints

| Method | Path | Access | Purpose |
|---|---|---|---|
| `POST` | `/webhooks/mpesa/{providerAccount}` | Provider-authenticated | Receive payment result |
| `GET` | `/health` | Public, minimal | Liveness only |

Webhook implementation requirements:

1. Verify provider signature/authenticity using the provider's current documented mechanism, constant-time comparisons where applicable, and HTTPS. IP allowlisting is only supplemental.
2. Validate merchant/account, currency, amount, checkout reference and provider transaction ID against the pending payment.
3. Persist the raw event with restricted access and appropriate retention; redact credentials and sensitive payer data from logs.
4. Enforce uniqueness on provider event/receipt identifiers. Process duplicate callbacks idempotently and return the provider-required success response.
5. Commit payment and order state transitions atomically; enqueue downstream notifications only after commit.
6. Handle out-of-order, invalid and replayed events without downgrading a terminal state. Alert on mismatched amount/reference and reconcile against provider reports.

Do not expose a public endpoint that releases escrow or trusts a client-supplied "payment successful" flag.

### Weather alerts and pilot feedback (Phase 3)

| Method | Path | Access | Purpose |
|---|---|---|---|
| `POST` | `/webhooks/weather` | Shared secret over HTTPS | Validate weather feed; create county alerts and in-app notifications |
| `GET` | `/notifications` | Authenticated | List the current user's notifications |
| `POST` | `/notifications/{notificationId}/read` | Owner | Mark an in-app notification read |
| `POST` | `/pilot-feedback` | Authenticated | Submit bug, negotiation, recommendation, or general UAT feedback |
| `GET` | `/pilot-feedback/summary` | Allowlisted pilot admin | Aggregated category/rating metrics |

Weather webhook requests require `X-FarmLink-Weather-Secret` matching a configured secret of at least 32 characters. Payload includes a provider event ID, county, observation timestamp and optional rainfall (mm), temperature (C), and pest-risk labels. Duplicate events are idempotently ignored; observations older than seven days or in the future are rejected. Current alert rules are initial heuristics, not agronomic advice. Notifications are stored in the in-app table and available from `/notifications`; no push/SMS provider is configured.

Feedback requires a category and 10–5000 character details field. Negotiation feedback must include `negotiation_rating` (1–5); recommendation feedback must include `recommendation_accuracy_rating` (1–5). The authenticated user's ID is assigned by the server, not accepted from the request. Configure `PILOT_FEEDBACK_ADMIN_IDS` as a comma-separated allowlist; the summary endpoint returns only aggregate counts and averages.

### Market analytics baseline (Phase 4)

| Method | Path | Access | Purpose |
|---|---|---|---|
| `GET` | `/analytics/market?county=Nairobi&crop=maize` | Public, rate-limited | Read aggregate completed-trade price and recent demand baselines |

Example response:

```json
{
  "data": [
    {
      "dimensions": {
        "crop": "maize",
        "county": "nairobi",
        "unit": "kg",
        "currency": "KES"
      },
      "metrics": {
        "sample_size": 8,
        "price_median_minor": 4500,
        "price_p25_minor": 4300,
        "price_p75_minor": 4700,
        "completed_orders_30d": 8,
        "completed_orders_previous_30d": 5,
        "demand_change_percent": 60,
        "baseline_demand_next_30d": 8,
        "baseline_method": "last_30_days_volume_not_a_trained_forecast"
      },
      "computed_at": "2026-11-05T02:15:00Z"
    }
  ],
  "meta": {
    "count": 1,
    "privacy_threshold": 5,
    "classification": "historical_market_baseline_not_personalized_advice"
  }
}
```

Only paid, delivered and completed produce trades are included. Results are recomputed daily; cohorts with fewer than five current-window trades are omitted. `baseline_demand_next_30d` repeats the latest 30-day count and is **not** a model forecast. No trained AI model or crop-specific input recommendation engine is currently configured. This endpoint is advisory and does not guarantee prices, sales or agronomic results.

### Premium, logistics, policy insights and ads (Phase 5)

| Method | Path | Access | Purpose |
|---|---|---|---|
| `GET` | `/subscription` | Authenticated | Current Premium plan, status, manual renewal and configured fee |
| `POST` | `/subscription/purchase` | Verified KYC + idempotency key | Start 30-day M-Pesa Premium purchase |
| `GET` | `/analytics/market/advanced` | Premium JWT | View price spread and recent aggregate market metrics |
| `POST` | `/transport/service` | Verified transport provider | Register/update county-based transport service |
| `GET` | `/transport/providers?county=Nakuru` | Authenticated | Find verified providers serving a county |
| `GET` | `/transport/deliveries` | Verified transport provider | List open requests in provider's service county |
| `POST` | `/transactions/{transactionId}/delivery` | Buyer; paid, undisputed order | Request a delivery linked to the transaction |
| `POST` | `/deliveries/{deliveryId}/accept` | Verified transport provider | Accept an open delivery request |
| `PATCH` | `/deliveries/{deliveryId}/status` | Assigned provider | Advance delivery status |
| `GET` | `/deliveries/{deliveryId}` | Transaction participants or assigned provider | Read delivery progress |
| `GET` | `/policy/insights` | Provisioned `X-Partner-Key` | Access coarse, thresholded county-level aggregates |
| `GET` | `/ads/pricing` | Authenticated | Read configured 30-day ad fee |
| `GET` | `/ads` | Authenticated | Select a role-targeted sponsored placement |
| `POST` | `/ads` | Verified supplier; multipart | Upload a draft image campaign |
| `POST` | `/ads/{campaignId}/purchase` | Campaign owner | Start M-Pesa payment |
| `POST` | `/admin/ads/{campaignId}/review` | Configured ad administrator | Approve or reject a paid campaign |

Premium and ad purchases use provider-verified M-Pesa callbacks; they are one-time 30-day purchases and do not auto-renew. Prices must be configured as whole-KES integer minor units. Paid ad creative is not published until approved. Provider matching is county-based and does not use precise GPS. Policy aggregates omit direct identifiers but should not be represented as legally anonymous without a context-specific assessment. See [PHASE5_SCALING.md](./PHASE5_SCALING.md) for payload details, provisioning and launch limitations.

## PostgreSQL migration blueprint

Use Laravel migrations (`database/migrations`) with PostgreSQL UUIDs, foreign keys, check constraints and indexes. The following is the logical SQL blueprint; adapt to Laravel schema builders and the selected UUID generation extension/function. Run migrations through reviewed, versioned migrations, not by applying this reference directly.

```sql
CREATE TABLE users (
  id uuid PRIMARY KEY,
  name varchar(160) NOT NULL,
  phone varchar(32) NOT NULL UNIQUE,
  email varchar(254) UNIQUE,
  password varchar(255) NOT NULL,
  role varchar(16) NOT NULL CHECK (role IN ('farmer','buyer','supplier')),
  verification_status varchar(16) NOT NULL DEFAULT 'pending'
    CHECK (verification_status IN ('pending','verified','rejected')),
  locale varchar(12) NOT NULL DEFAULT 'en',
  phone_verified_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  deleted_at timestamptz
);

CREATE TABLE user_profiles (
  user_id uuid PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  farm_name varchar(160),
  business_name varchar(160),
  organization_name varchar(160),
  county varchar(100),
  country_code char(2) NOT NULL DEFAULT 'KE',
  latitude numeric(9,6),
  longitude numeric(9,6),
  profile_data jsonb NOT NULL DEFAULT '{}',
  CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
  CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180)
);

CREATE TABLE listings (
  id uuid PRIMARY KEY,
  owner_id uuid NOT NULL REFERENCES users(id),
  category varchar(12) NOT NULL CHECK (category IN ('produce','input')),
  title varchar(180) NOT NULL,
  description text NOT NULL DEFAULT '',
  crop varchar(100),
  input_type varchar(100),
  brand varchar(120),
  quantity_available numeric(14,3) NOT NULL CHECK (quantity_available > 0),
  unit varchar(24) NOT NULL,
  unit_price_minor bigint NOT NULL CHECK (unit_price_minor > 0),
  currency char(3) NOT NULL,
  county varchar(100),
  latitude numeric(9,6),
  longitude numeric(9,6),
  status varchar(12) NOT NULL DEFAULT 'active'
    CHECK (status IN ('draft','active','paused','sold_out','archived')),
  available_until timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK ((category = 'produce' AND crop IS NOT NULL AND input_type IS NULL)
      OR (category = 'input' AND input_type IS NOT NULL AND crop IS NULL))
);
CREATE INDEX listings_browse_idx ON listings(category, status, created_at DESC);
CREATE INDEX listings_owner_idx ON listings(owner_id, status);
CREATE INDEX listings_county_idx ON listings(county, category, status);

CREATE TABLE offers (
  id uuid PRIMARY KEY,
  listing_id uuid NOT NULL REFERENCES listings(id),
  buyer_id uuid NOT NULL REFERENCES users(id),
  seller_id uuid NOT NULL REFERENCES users(id),
  status varchar(12) NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending','accepted','rejected','withdrawn','expired')),
  expires_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK (buyer_id <> seller_id)
);
CREATE INDEX offers_buyer_idx ON offers(buyer_id, status, updated_at DESC);
CREATE INDEX offers_seller_idx ON offers(seller_id, status, updated_at DESC);

CREATE TABLE offer_revisions (
  id uuid PRIMARY KEY,
  offer_id uuid NOT NULL REFERENCES offers(id),
  created_by uuid NOT NULL REFERENCES users(id),
  revision_number integer NOT NULL CHECK (revision_number > 0),
  quantity numeric(14,3) NOT NULL CHECK (quantity > 0),
  unit_price_minor bigint NOT NULL CHECK (unit_price_minor > 0),
  currency char(3) NOT NULL,
  message varchar(1000),
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (offer_id, revision_number)
);

CREATE TABLE orders (
  id uuid PRIMARY KEY,
  offer_id uuid NOT NULL UNIQUE REFERENCES offers(id),
  listing_id uuid NOT NULL REFERENCES listings(id),
  buyer_id uuid NOT NULL REFERENCES users(id),
  seller_id uuid NOT NULL REFERENCES users(id),
  quantity numeric(14,3) NOT NULL CHECK (quantity > 0),
  unit varchar(24) NOT NULL,
  unit_price_minor bigint NOT NULL CHECK (unit_price_minor > 0),
  total_minor bigint NOT NULL CHECK (total_minor >= 0),
  currency char(3) NOT NULL,
  status varchar(20) NOT NULL CHECK (status IN
    ('awaiting_payment','escrow_held','completed','cancelled','disputed')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK (buyer_id <> seller_id)
);

CREATE TABLE payments (
  id uuid PRIMARY KEY,
  order_id uuid NOT NULL REFERENCES orders(id),
  provider varchar(24) NOT NULL,
  provider_checkout_id varchar(160),
  provider_receipt varchar(160),
  status varchar(16) NOT NULL CHECK (status IN
    ('initiated','pending','succeeded','failed','cancelled','refunded')),
  amount_minor bigint NOT NULL CHECK (amount_minor >= 0),
  currency char(3) NOT NULL,
  phone_masked varchar(32),
  escrow_status varchar(16) NOT NULL DEFAULT 'not_funded'
    CHECK (escrow_status IN ('not_funded','held','release_pending','released','refund_pending','refunded','disputed')),
  provider_payload jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (provider, provider_checkout_id),
  UNIQUE (provider, provider_receipt)
);
CREATE INDEX payments_order_idx ON payments(order_id, created_at DESC);

CREATE TABLE webhook_events (
  id uuid PRIMARY KEY,
  provider varchar(24) NOT NULL,
  provider_event_id varchar(180) NOT NULL,
  payment_id uuid REFERENCES payments(id),
  event_type varchar(100) NOT NULL,
  payload jsonb NOT NULL,
  processed_at timestamptz,
  received_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (provider, provider_event_id)
);

CREATE TABLE idempotency_keys (
  user_id uuid NOT NULL REFERENCES users(id),
  idempotency_key uuid NOT NULL,
  request_hash char(64) NOT NULL,
  response_status smallint,
  response_body jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  expires_at timestamptz NOT NULL,
  PRIMARY KEY (user_id, idempotency_key)
);
```

Further migrations should add disputes, delivery confirmations, audit records, outbox notifications, media metadata, rate-limit/verification support and optional PostGIS indexes. Do not place image binaries or KYC documents in JSON columns. Apply retention, encryption-at-rest and access-control policies to sensitive provider/KYC data. Add domain-level checks for role/category compatibility and accepted-offer transitions in application services within transactions.

### Knowledge Hub, forum, and SDG impact (ongoing optimization)

| Method | Path | Access | Purpose |
|---|---|---|---|
| `GET` | `/knowledge/articles?type=article|tutorial&search=...` | Public | Search published learning content |
| `GET` | `/knowledge/articles/{slug}` | Public | Read a published article or tutorial |
| `GET` | `/forum/threads` | Public | Browse published discussions |
| `GET` | `/forum/threads/{threadId}` | Public | Read a discussion and published threaded replies |
| `POST` | `/forum/threads` | Authenticated | Start a discussion |
| `POST` | `/forum/threads/{threadId}/posts` | Authenticated | Reply to a thread or same-thread post |
| `GET` | `/community/admin-access` | Authenticated | Check whether community admin tools are available |
| `POST` / `PATCH` | `/admin/community/articles[/{articleId}]` | `COMMUNITY_ADMIN_IDS` | Draft, publish, update, or archive learning content |
| `GET` | `/admin/community/forum` and `/admin/community/forum/posts` | `COMMUNITY_ADMIN_IDS` | Review forum threads and replies |
| `PATCH` | `/admin/community/forum/threads/{threadId}` | `COMMUNITY_ADMIN_IDS` | Publish, hide, or remove a thread |
| `PATCH` | `/admin/community/forum/threads/{threadId}/posts/{postId}` | `COMMUNITY_ADMIN_IDS` | Publish, hide, or remove a reply and its descendants |
| `GET` | `/impact/observations` | Farmer account | Read the authenticated farmer's own reports |
| `POST` | `/impact/observations` | Farmer account, consent required | Submit/update monthly paired income and loss observations |
| `GET` | `/policy/impact/report` | County-scoped `X-Partner-Key` | Read automated SDG impact aggregates (also `/policy/impact`) |

Forum writes require an `Idempotency-Key` and are rate-limited. Article and reply text is plain text; clients must escape it rather than render submitted HTML. Moderation status is enforced server-side. Configure trusted administrator UUIDs through `COMMUNITY_ADMIN_IDS`.

The farmer impact report contains a 15–20% **target scenario**, not an observed result or causal estimate. Observed income and post-harvest loss changes are calculated from consented farmer-reported comparisons. The scheduled aggregation suppresses counties with fewer than five participating farmers and partner keys remain scoped to provisioned counties. See `docs/COMMUNITY_AND_IMPACT.md` for payload details, scheduled operation, interpretation, and privacy limitations.

## Implementation notes

- Use Form Requests, API Resources, policies, service classes and queued jobs; keep provider-specific M-Pesa code behind a payment adapter.
- Store provider secrets in a secrets manager/environment configuration, not source control. Keep `.env` local and untracked; provide a sanitized `.env.example`.
- Add feature tests for role authorization, validation, idempotent retries, concurrent offer acceptance, duplicate/out-of-order callbacks and payment/escrow transitions.
- Version breaking contract changes under a new API version. Publish rate limits and data-retention policy before production.
