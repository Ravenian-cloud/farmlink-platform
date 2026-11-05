# Phase 4: Google Cloud deployment

This deployment template targets **GKE Standard**, regional Cloud SQL for PostgreSQL, Cloud SQL Auth Proxy v2, the GKE external HTTP(S) load balancer, Secret Manager, and Artifact Registry. It is an initial production baseline for the stated 5,000+ farmers and 2,000 buyers/suppliers, not a capacity guarantee. Measure real requests, database connections, queue lag, and regional network behavior before opening registration.

## What is included

- `backend-php/Dockerfile`: production PHP 8.3/Apache image, PostgreSQL driver, non-root runtime, `/up` health check.
- `backend-php/k8s/production.yaml`: API and queue deployments, HPA (3-30 API replicas), PDB, scheduler, HTTPS ingress and Cloud SQL proxy sidecars.
- `backend-php/k8s/migrate-job.yaml`: one-off migration job. Run it explicitly before rolling out an image.
- `backend-php/k8s/secret-provider-class.yaml`: Secret Manager CSI references; add the secret versions and grant the workload identity access before applying. The container entrypoint reads mounted secret files directly; no Kubernetes Secret values are copied into the cluster.
- Durable database queue/cache schema and Laravel connection settings are included. The database queue is an initial simple backend; evaluate Cloud Tasks/Pub/Sub or a managed Redis queue if queue load or database contention justifies it.

The manifests contain placeholders (`PROJECT_ID`, `REGION`, `INSTANCE_NAME`, `RELEASE_SHA`, domains and service accounts). Replace them with deployment-specific values. Never store real secret values in manifests or source control. The config map leaves `PREMIUM_MONTHLY_MINOR` and `AD_CAMPAIGN_MONTHLY_MINOR` at `0` to keep paid Phase 5 features disabled until approved prices are set; populate `AD_ADMIN_IDS` only with trusted administrator UUIDs.

The current backend checkout does not contain `composer.lock`. Before building a production image, resolve and review PHP dependencies with Composer, commit the generated lock file, and use that reviewed lock for CI builds. The Dockerfile intentionally fails without it rather than silently resolving floating dependency versions at image-build time.

## GCP resources and initial sizing

1. Select a region close to the launch population, establish project billing/budgets, and enable `container.googleapis.com`, `sqladmin.googleapis.com`, `secretmanager.googleapis.com`, `artifactregistry.googleapis.com`, `compute.googleapis.com`, and `iam.googleapis.com`.
2. Reserve a global static IP named `farmlink-api-ip`. Create a **regional** GKE Standard cluster with autoscaling node pools in at least three zones, Workload Identity Federation for GKE, the Secret Manager CSI add-on, the GKE HTTP load-balancing add-on, and private nodes. Keep the control plane and nodes in a dedicated VPC; restrict administrative access to approved networks. Add Cloud NAT or an approved egress gateway for private workloads calling Safaricom/weather providers; use controlled egress rules and a reserved NAT address if a provider requires allowlisting.
3. Begin with a regional Cloud SQL PostgreSQL instance using HA, private IP, automated backups, point-in-time recovery, and a separately tested restore procedure. Set a conservative connection budget before allowing HPA scale-out. Cloud SQL Proxy authenticates using the pod's Google service account; PostgreSQL still requires its own least-privilege application user.
4. Create an Artifact Registry Docker repository and a dedicated GCP service account for the API workload. Grant only Cloud SQL Client and Secret Manager Secret Accessor for the named secrets. Bind the KSA `farmlink/farmlink-api` to that GSA using Workload Identity.
5. Size GKE nodes and Cloud SQL using load tests, not the user count alone. The manifest starts at three 250m/384Mi API requests and autoscaling targets 65% CPU / 75% memory up to 30 pods. These are tuning starting points. Test peak concurrent requests, connection exhaustion, queue backlog, database failover and autoscaler behavior.

Use cost alerts before enabling a public load balancer or regional HA services. Cloud costs vary by region, uptime, traffic, storage, backup retention, and node/database sizes; obtain a live estimate in the Google Cloud Pricing Calculator rather than treating an estimate in this document as a quote. Set budget alerts at 50%, 80%, and 100% of the approved monthly budget.

## Secrets and database setup

Create Secret Manager secrets for `FARMLINK_APP_KEY`, `FARMLINK_JWT_SECRET`, `FARMLINK_DB_PASSWORD`, `FARMLINK_MPESA_CONSUMER_KEY`, `FARMLINK_MPESA_CONSUMER_SECRET`, `FARMLINK_MPESA_PASSKEY`, `FARMLINK_MPESA_SHORTCODE`, and `FARMLINK_WEATHER_WEBHOOK_SECRET`. Add values using a controlled operator session or approved CI secret injection. Never paste secrets into a shell history, ticket, or commit.

Create a PostgreSQL role/database for the application. Do not use the Cloud SQL administrative account for Laravel. The config map sets `DB_USERNAME=farmlink_app`; provision a matching role, strong generated password, and appropriate schema/table privileges. Configure database backup/PITR retention to meet the approved recovery point and recovery time objectives.

The Secret Manager CSI add-on must be enabled and the workload identity must have access to each secret. The Laravel container entrypoint reads the mounted secret files and exports the required environment variables; it fails closed if a required secret is missing. This avoids copying secret values into Kubernetes Secret objects. Rotate secrets by adding a new Secret Manager version and restarting workloads after validation. The JWT key rotation procedure must account for existing access tokens.

## Build, migrate and deploy

Run commands from `backend-php` after setting local, non-secret shell variables:

```powershell
docker build -t REGION-docker.pkg.dev/PROJECT_ID/farmlink/backend:RELEASE_SHA .
docker push REGION-docker.pkg.dev/PROJECT_ID/farmlink/backend:RELEASE_SHA
gcloud container clusters get-credentials CLUSTER_NAME --region REGION --project PROJECT_ID
kubectl apply -f k8s/bootstrap.yaml
kubectl apply -f k8s/secret-provider-class.yaml
kubectl apply -f k8s/migrate-job.yaml
kubectl wait --for=condition=complete job/farmlink-migrate-RELEASE_SHA -n farmlink --timeout=10m
kubectl apply -f k8s/production.yaml
kubectl wait --for=condition=Ready pod -l app=farmlink-api -n farmlink --timeout=10m
```

In the release copy of each manifest, replace every image tag with the exact immutable image digest or release SHA. The migration Job name must be unique per release. Apply only backward-compatible migrations before the application rollout; use expand/migrate/contract steps for schema changes. Review `kubectl logs` and the migration Job exit status. Roll out the new API/worker image only after migration succeeds.

Point `api.example.com` at the ingress IP, `app.example.com` at the approved web host, update the CORS allowlist, and wait for Google-managed TLS certificate provisioning. Use HTTPS-only DNS/ingress; the app is not ready until an external HTTPS health check succeeds. Apply Cloud Armor WAF/rate-limit policies at the load balancer and review their logs before enforcing aggressive rules.

For rollout and rollback, pin images by digest, use readiness checks, watch 5xx/latency/queue/database metrics, and keep the previous image available. Roll back the Deployment image if application health regresses; do not blindly reverse a destructive database migration. Restore from backups only through the documented incident process.

## Operations, backups and launch gates

- Export structured application logs to Cloud Logging; alert on elevated 5xx, authentication failures, database connection saturation, queue age/failures, disk/CPU pressure, certificate expiry, and failed scheduled jobs.
- Retain backups and audit/security logs according to an approved jurisdiction-specific retention schedule. Perform and record restore drills before launch.
- Use a separate staging project with masked/synthetic data. CI must run PHP tests, migration checks, image scanning, dependency checks and deployment manifest validation before production promotion.
- Load-test the complete path, including PostgreSQL, payments callbacks, queue workers, retries, rate limits and mobile retry behavior. Validate HPA against DB connection limits, not only pod CPU.
- Keep M-Pesa sandbox enabled until Safaricom production onboarding, callback allowlisting, reconciliation, refund/dispute handling and operational ownership are complete.
- The app's current escrow state is simulated; do not describe it as custody or release real funds through this flow. Obtain an appropriately licensed escrow/payout arrangement and legal review before handling conditional fund release.
- Confirm independent security review, privacy notices/consent, KYC operations, incident response, data-subject request handling, support coverage, and a tested disaster-recovery plan before public launch.
