# Phase 4: Full launch readiness

## AI and market analytics: current implementation

The API now computes a daily, privacy-thresholded historical market baseline from delivered, paid, completed produce transactions. It exposes `GET /api/v1/analytics/market?county=Nairobi&crop=maize`; the response includes median and quartile prices, recent order counts, and a simple demand-change signal. Cohorts below five recent trades are suppressed. Run `php artisan analytics:refresh-market` to refresh; Laravel scheduling runs it daily at 02:15 UTC. Results are advisory, historical, and not guaranteed prices or sales.

This is a deterministic baseline, **not an activated trained AI model**. No trained model artifact, validated input/crop mapping, weather history, or evaluation dataset exists in the project. The displayed next-30-day baseline repeats last month's order volume and must not be marketed as a forecast. Before adding model inference, collect lawful, quality-reviewed data; establish baselines and holdout evaluation; version models; define drift, fairness and safety monitoring; document human oversight; and approve rollback criteria. Input/crop-specific recommendations remain unavailable until agronomic evidence and a validated model/catalog mapping are built. Do not claim accuracy or clinical/agronomic benefit without evaluation.

## Android and iOS release process

### Before signing

1. Confirm production API/domain, TLS, CORS, privacy notice URL, support contact, app name, icon, screenshots, age/content rating and store listing translations.
2. Replace any development endpoint and remove test data/credentials. Verify offline sync, idempotency, token handling, localization, accessibility, low-bandwidth behavior, permission prompts, deletion/account support and backend compatibility.
3. Review Flutter dependencies and licenses; run `flutter analyze`, unit/widget/integration tests, and device tests on supported Android and iOS versions. Build from a tagged commit in CI and archive source maps/debug symbols securely.
4. Document data collected/shared in Google Play Data safety and Apple App Privacy forms. Declare location and other permissions only when needed and explain their purpose in context. Provide a public privacy policy and data-retention/contact details.

### Google Play

1. Create/verify the organization developer account and complete identity, contact, payments and policy forms.
2. Generate a unique Android `applicationId`, version code/name, release signing key and upload key. Store signing material in a controlled CI secret store; restrict access and preserve offline recovery copies.
3. Build a signed Android App Bundle (`flutter build appbundle --release`) with production flavor/configuration. Check target SDK, permissions, 64-bit support, Play Integrity/policy declarations and crash reporting.
4. Upload to internal testing, then closed testing with representative farmers/buyers; collect and triage crashes, offline sync conflicts and accessibility feedback.
5. Complete store listing, content rating, Data safety, target audience and app access instructions. Roll out gradually; monitor Android vitals and pause the rollout on serious regressions.

### Apple App Store

1. Enroll the organization in the Apple Developer Program; verify legal entity, App Store Connect access, agreements, tax and banking details.
2. Register a unique bundle identifier, configure signing certificates/profiles and capabilities, and protect the distribution signing identity in approved CI/Apple tooling.
3. Build and archive the release (`flutter build ipa --release`), validate signing/entitlements and upload through Xcode/Transporter. Test the exact archive with TestFlight on supported devices.
4. Complete App Privacy, age rating, export-compliance/encryption answers, review notes, support URL and privacy policy. Ensure login/review credentials and backend test accounts are supplied securely if review requires them.
5. Submit for review, release manually or phased, and monitor crashes, review feedback and backend errors. Keep the previous supported API behavior during rollout; mobile clients may update slowly.

Store requirements change. Confirm current Apple and Google policies immediately before submission; this checklist is not a guarantee of acceptance. Never include signing keys or store credentials in the repository.

## Agribusiness promotion and advertising

### Positioning

- Lead with practical value: local produce/input discovery, transparent offers, and multilingual/low-connectivity workflows that have been demonstrated in the pilot.
- Describe analytics only as “historical market baselines” until validated models are deployed and independently evaluated. Do not promise optimal prices, guaranteed demand, yield improvement, disease diagnosis or competitor superiority.
- Separate sponsored placements from organic recommendations; clearly label advertisements and disclose commercial relationships. Do not sell user/KYC/location data to advertisers.

### 90-day acquisition plan

1. **Weeks 1-3: pilot evidence.** Recruit county-based farmer groups, cooperatives, buyers, and agricultural suppliers. Onboard with assisted training and consented support. Track activation, first listing, first qualified offer, completed trade, repeat use, offline sync success, support burden and safety reports.
2. **Weeks 4-6: trust and partnerships.** Publish short Swahili/English how-to materials, host cooperative demonstrations, and partner with vetted agribusinesses and extension groups. Give suppliers transparent campaign rules and aggregate reporting only.
3. **Weeks 7-10: measured acquisition.** Test referral incentives, local radio/community events and targeted digital ads by county. Use capped campaigns and randomized comparisons where feasible. Optimize for verified active users and successful transactions, not raw installs.
4. **Weeks 11-13: scale or pause.** Compare acquisition cost, retained users, completed-trade rate, dispute/fraud rate, support response time, and user-reported value by role and county. Expand only where fulfilment, trust and support capacity meet approved thresholds.

### Agribusiness advertising model

Offer clearly marked sponsored input listings, seasonal campaign packages, opt-in educational sponsorships and aggregate campaign reporting. Require advertiser/supplier verification, product authorization and truthful claims; prohibit unsafe pesticide claims and targeting based on sensitive KYC or precise farm location. Define moderation, complaint/removal, refund, reporting, ad labeling and conflict-of-interest policies before accepting payment. Keep organic ranking criteria documented and independent of ad spend.

## Go/no-go gates

- Production security/privacy review complete, critical/high findings addressed, KYC operational and jurisdictional counsel approval obtained.
- Payment provider production approval, reconciliation and dispute/refund workflows verified. Real escrow remains blocked until a licensed custody arrangement exists.
- GCP backup restore, failover, load test, alerting and rollback drills passed with owners on call.
- Android/iOS staged release builds pass automated and manual QA; privacy/store declarations match actual SDK/data behavior.
- Pilot metrics, support staffing, moderation, agronomic safety, incident response and advertising disclosure standards meet documented thresholds.
