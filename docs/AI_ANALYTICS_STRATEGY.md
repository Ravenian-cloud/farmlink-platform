# FarmLink AI and Analytics Integration Strategy

**Purpose:** Define safe data contracts and processing flows for price analytics, regional demand signals, input recommendations, and weather/disease alerts. These are advisory tools; marketplace and transaction APIs remain authoritative.

## Current implementation status

The backend now contains a daily baseline job (`analytics:refresh-market`) and a read-only `GET /api/v1/analytics/market` endpoint. It derives recent median/quartile prices and order-count changes from paid, delivered, completed produce transactions, grouped by crop/county/unit/currency. Groups with fewer than five current-window trades are omitted. The “next 30 day” figure is only the prior 30-day count repeated as a transparent baseline; it is **not a trained forecast or an AI prediction**. No model artifact or validated agronomic input recommendation data is present, so personalized crop/input recommendations are not available. See [PHASE4_FULL_LAUNCH.md](./PHASE4_FULL_LAUNCH.md) for deployment and launch gates.

## Architecture and data flow

```text
Web/mobile clients
       | versioned REST API
PHP/Laravel API ---- PostgreSQL (transactional source of truth)
       |                  |
       | outbox events     +--> scheduled/streaming ETL --> analytics store
       v                                           |             |
Laravel queue + Redis/SQS                         |             +--> model jobs
       |                                           |                    |
       +--> weather/provider adapters              +<--- aggregates <---+
       +--> notification delivery                         |
       +--> analytics jobs                                 v
                                                     versioned insights
                                                           |
                                            GET analytics endpoints
                                                           |
                                              web/mobile clients
```

Start with scheduled aggregation and rules-based recommendations. Introduce trained models only after data quality, consent, monitoring and measurable evaluation are established. Do not run costly model inference inside synchronous listing, offer or payment requests.

## Data requirements and governance

### Transactional inputs

Use finalized, timestamped marketplace events:

- Listing category/crop/input type, unit, currency, county/region, quantity and availability window.
- Listing price history and status changes.
- Accepted order quantity, agreed unit price, currency, order completion/cancellation and fulfillment timestamps.
- Offer/revision counts and outcomes where permitted.
- Input product metadata and verified regulatory attributes where available.
- Weather observations/forecasts and public or licensed advisories keyed by provider, observation time and coarse geospatial cell.

Store event time, ingestion time, source, schema version, quality flags and lineage. Convert units and currencies consistently before aggregation; retain the originals. Exclude test accounts, suspicious/fraudulent transactions, canceled/unfulfilled orders and duplicate callbacks from realized-price training data. Distinguish listing/asking price from accepted and completed transaction price.

### Privacy, retention and access

Analytics should use aggregated or pseudonymized records with coarse geographic regions. Do not train or expose outputs containing phone numbers, names, exact farm coordinates, KYC documents or private messages. Apply role-based access, data minimization, retention/deletion policy and audit logging. Establish lawful basis/consent for optional location and any third-party data sharing. Ensure model and aggregate deletion/correction procedures are defined for personal-data obligations.

## Insight contracts

Expose insights as read-only, cached API resources. Every result includes freshness and limitations, and may be absent when data is insufficient.

### Price analytics

`GET /api/v1/analytics/prices?crop=maize&county=Nakuru&unit=kg&currency=KES&from=2026-10-01&to=2026-11-01`

```json
{
  "data": {
    "metric": "completed_trade_price",
    "crop": "maize",
    "region": { "county": "Nakuru" },
    "unit": "kg",
    "currency": "KES",
    "period": { "from": "2026-10-01", "to": "2026-11-01" },
    "sample_size": 42,
    "median_minor": 4600,
    "p25_minor": 4200,
    "p75_minor": 5100,
    "updated_at": "2026-11-05T09:00:00Z",
    "limitations": ["Indicative only; final prices depend on grade and delivery."]
  }
}
```

Suppress or coarsen small cohorts below an agreed privacy threshold. Return `insufficient_data` rather than false precision. Clearly label whether an estimate comes from listing prices, accepted offers, or completed orders.

### Regional demand forecast

`GET /api/v1/analytics/demand?category=produce&county=Nakuru&horizon_days=30`

Return forecast period, commodity/category, region, demand index or unit-aware estimate, uncertainty interval, model version, data freshness, sample coverage and a plain-language limitation. Do not present a forecast as guaranteed sales or a buyer commitment.

### Input recommendations

`GET /api/v1/analytics/recommendations/inputs?crop=maize&county=Nakuru&season=long_rains`

Return ranked input categories/products with rationale, evidence/source date, region/season context, safety caveat and freshness. Eligibility/filtering for listing availability and seller authorization must come from the marketplace API. Do not prescribe chemical application rates or diagnose disease without expert-validated sources and appropriate regulatory review. Allow users to report unsafe or irrelevant recommendations.

## PHP API and service boundaries

Suggested services under `backend-php/app/Services`: `PriceAnalyticsService`, `DemandForecastService`, `InputRecommendationService`, `WeatherService`, and `NotificationService`. Controllers validate inputs, authorize the requested scope, and serialize stable DTOs; services fetch precomputed results. Keep model version/provider internals out of client contracts.

Suggested persistence additions: `analytics_runs` (model/pipeline version, status, time window, metrics, errors), `insight_snapshots` (type, dimensions, payload, freshness, model version), `weather_observations`, `alert_events`, `user_notification_preferences`, `notifications`, and `outbox_events`. Index snapshots by insight type/dimensions/freshness; avoid high-cardinality exact coordinates as keys. Store external raw payloads only when contractually permitted and with explicit retention.

## Background processing and reliable events

### Queue strategy

Use Laravel queues with a durable backend (Redis or managed queue) and separate queues/priorities for:

- `payments`: provider callbacks and reconciliation (high priority; strict idempotency).
- `alerts`: weather/disease provider ingestion and notification fanout.
- `analytics`: scheduled aggregation, forecast and recommendation refresh.
- `notifications`: push/SMS/email delivery with provider-specific throttling.

API database writes should insert an outbox event in the same transaction as the domain change. A dispatcher publishes it and marks it delivered; consumers keep an inbox/deduplication key. This avoids losing an event between database commit and queue publish. Retry transient failures with bounded exponential backoff/jitter and a dead-letter queue; expose operational alerts and a replay procedure. Consumers must be idempotent.

### Weather/disease alert flow

1. Scheduled job or authenticated provider webhook ingests new observations/advisories.
2. Verify provider authenticity, schema, timestamp, geographic scope, severity and source; reject stale or malformed data.
3. Normalize into a canonical event (`provider`, `provider_event_id`, `hazard`, `severity`, `valid_from`, `valid_to`, coarse region/grid, source URL/version).
4. Deduplicate by provider event ID and canonical event fingerprint; upsert alert state transactionally and publish an outbox event.
5. Match affected users/listings using consented, coarse region and user preferences. Respect locale, quiet hours, opt-outs, rate caps and alert expiry.
6. Queue push/in-app delivery; optionally SMS only for opted-in critical alerts and within budget/legal policy. Store delivery outcomes and deduplicate notifications.
7. Clients fetch the canonical notification from the API and mark it read with an authenticated, idempotent request. Push payloads contain minimal information and no sensitive farm data.

Example internal event:

```json
{
  "event_id": "uuid",
  "schema_version": 1,
  "type": "weather.alert.created",
  "occurred_at": "2026-11-05T09:00:00Z",
  "data": {
    "alert_id": "uuid",
    "hazard": "heavy_rain",
    "severity": "high",
    "region_code": "KE-32",
    "valid_until": "2026-11-06T09:00:00Z",
    "source": "configured-provider"
  }
}
```

External webhook endpoints must authenticate signatures, prevent replay, validate payload sizes, rate-limit, persist before acknowledging and avoid trusting callback-supplied user IDs. If a provider has no reliable webhook, poll at a documented interval with provider rate limits and freshness checks.

## Model lifecycle and quality controls

- Establish baseline rules/statistics and a target metric before training a model.
- Use time-based validation splits to prevent future leakage; evaluate by region, crop, season and relevant user group.
- Track forecast error/calibration, recommendation coverage, data drift, missingness and outcome feedback. Compare to baseline and roll back if thresholds regress.
- Version datasets, features, code, model artifact and outputs. Record reproducible training/evaluation metadata.
- Require human agronomy review for safety-sensitive input advice and disease content. Include source attribution, uncertainty and a feedback/reporting path.
- Monitor stale snapshots and return an explicit unavailable/insufficient-data response instead of silently serving outdated forecasts.

## Client behavior and operations

Clients treat analytics as advisory and cache with `updated_at`/expiry. Provide last-updated and limitations. A missing insight must not block marketplace use. Push notifications are prompts to fetch authoritative detail; they are not proof of transaction/payment state.

Monitor queue depth/age, failed/dead-letter jobs, provider latency/error rates, callback reconciliation gaps, stale insight age, alert delivery failures and data-quality metrics. Keep structured logs free of credentials, phone numbers, exact coordinates and raw sensitive payloads. Define on-call ownership, provider outage fallback and documented replay/reconciliation controls before enabling production alerts or payments.
