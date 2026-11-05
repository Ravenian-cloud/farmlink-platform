# Community and SDG impact reporting

## Knowledge Hub and forum

Run the `2026_11_05_000012_create_community_and_impact_tables.php` migration. Configure `COMMUNITY_ADMIN_IDS` as a comma-separated allowlist of trusted user UUIDs; the production and bootstrap ConfigMaps intentionally leave it empty until administrators are appointed.

Public Knowledge Hub routes:

- `GET /api/v1/knowledge/articles?type=article|tutorial&search=...`
- `GET /api/v1/knowledge/articles/{slug}`

Only published content is visible. Articles are plain text rendered as text by the web client. Tutorials may include an HTTPS video link; FarmLink links out rather than embedding third-party players.

Community routes:

- `GET /api/v1/forum/threads`
- `GET /api/v1/forum/threads/{threadUuid}`
- Authenticated `POST /api/v1/forum/threads` with `{ "title": "...", "body": "..." }`
- Authenticated `POST /api/v1/forum/threads/{threadUuid}/posts` with `{ "body": "...", "parent_id": "optional-post-uuid" }`

Replies are associated with their parent post and must belong to the same discussion. Thread and reply submissions require an idempotency key and are rate-limited. Administrators can create/edit/publish/archive content through `POST|PATCH /api/v1/admin/community/articles`, review discussions and replies via `GET /api/v1/admin/community/forum` and `/posts`, and change moderation status using the corresponding `PATCH` moderation routes. Moderation is allowlisted by `COMMUNITY_ADMIN_IDS`; API authorization remains authoritative even if a client exposes the tools UI.

## Farmer impact observations

Farmers can submit one voluntary observation per calendar month with `POST /api/v1/impact/observations`. The API `reporting_period` uses the first day of the selected month:

```json
{
  "reporting_period": "2026-11-01",
  "baseline_monthly_income_minor": 100000,
  "current_monthly_income_minor": 115000,
  "baseline_post_harvest_loss_percent": 20,
  "current_post_harvest_loss_percent": 15,
  "consent": true
}
```

Income is entered as KES minor units (100 units = KES 1). The county is taken from the farmer's profile, not the observation payload; farmers can set it with authenticated `PATCH /api/v1/profile`. The farmer can read their own submissions at `GET /api/v1/impact/observations`. One row per farmer and reporting period is retained; updates require renewed consent.

Self-reported observations contain sensitive financial information. Restrict database access, apply approved retention/deletion rules, and obtain consent before collection. Do not expose individual observations to partner accounts or use them to make decisions about individual farmers.

## Partner impact dashboard and reports

The partner API key is the same county-scoped `X-Partner-Key` used by `/api/v1/policy/insights`. The reporting endpoint `GET /api/v1/policy/impact/report` returns the latest county snapshot only for counties assigned to that partner. `GET /api/v1/policy/impact` is an alias. Cohorts below five reporting farmers are omitted. Reports contain no farmer, account, listing, or transaction identifiers.

The scheduler runs `farmlink:refresh-impact-reports` daily at 02:45 and stores refreshed aggregates in `analytics_snapshots`. Run `php artisan schedule:run` each minute through the configured scheduler deployment. The web partner dashboard can export the returned aggregate as JSON.

Reports distinguish observed comparisons from targets:

- Observed monthly income change is calculated from the latest paired farmer-reported baseline/current values in the past 12 months.
- The requested 15–20% income increase is shown only as a target scenario, with a projected value range derived from reported baseline income. It is not presented as achieved or attributed to FarmLink.
- Post-harvest loss change is shown as a percentage-point difference from paired self-reported values; a positive reduction is not guaranteed.
- Completed produce trades are included only as a county-level context metric and only when at least five qualifying trades exist.
- SDG 1, 2 and 9 are described as alignment areas, not verified impact certification.

These are descriptive, observational indicators; they do not establish causation, independent measurement, program eligibility, or legal anonymization. Confirm methodology and data-sharing agreements with participating farmers and partners before external publication.

Configure partner keys using the existing `farmlink:partner-key` and `farmlink:partner-revoke` Artisan commands. Keep keys out of browser persistent storage and rotate/revoke them if exposed.
