# FarmLink Web Frontend UI/UX Structure

**Stack:** React 18, React Router, Axios. This document proposes the web app architecture; the current frontend scaffold contains only the package manifest and empty page folders.

## Product structure

### Route map

```text
/                         Public landing page
/login                    Sign-in
/register                 Role selection and registration
/marketplace              Search and browse listings
/marketplace/:listingId   Listing detail and offer entry
/knowledge                Knowledge hub index
/knowledge/:articleSlug   Tutorial/article
/forum                    Community discussions
/forum/:topicId           Topic and replies
/app                       Authenticated, role-aware dashboard shell
/app/dashboard             Role-specific overview
/app/listings              My listings (farmer/supplier)
/app/listings/new          Create listing (farmer/supplier)
/app/offers                Offers and negotiation
/app/orders                Orders and payment status
/app/profile               Account, location, language and verification
/app/saved                 Saved listings (buyer)
```

Protect `/app/*` with an authentication boundary and resolve role permissions from `/me`; redirect unauthenticated users to `/login` with a safe return path. Use route-level permission checks as a UX aid, while the API remains authoritative. Unknown routes render a 404 page. Deep links must survive refresh through server fallback configuration.

## Component hierarchy

```text
<App>
  <QueryClientProvider>
    <AuthProvider>
      <RouterProvider>
        <PublicLayout | AppLayout>
          <GlobalHeader />
          <PrimaryNavigation />
          <Outlet />
          <ToastRegion />
        </PublicLayout>
      </RouterProvider>
    </AuthProvider>
  </QueryClientProvider>
</App>
```

Feature areas:

- `auth/`: `LoginForm`, `RegistrationWizard`, `RolePicker`, `VerificationStatus`.
- `dashboard/`: `DashboardRouter`, `FarmerDashboard`, `BuyerDashboard`, `SupplierDashboard`, shared `MetricCard`, `ActivityFeed`, `QuickAction`.
- `marketplace/`: `ListingSearch`, `FilterPanel`, `ListingGrid`, `ListingCard`, `ListingDetail`, `OfferComposer`, `LocationRadiusFilter`.
- `listings/`: `ListingForm`, `InventorySummary`, `ListingStatusBadge`, `ImageUploader`.
- `offers/`: `OfferInbox`, `OfferThread`, `OfferRevision`, `CounterOfferForm`, `AcceptOfferDialog`.
- `orders/`: `OrderTimeline`, `PaymentStatus`, `MpesaInstructions`, `DeliveryConfirmation`, `DisputeForm`.
- `knowledge/`: `ArticleSearch`, `CategoryTabs`, `ArticleCard`, `ArticleReader`.
- `forum/`: `TopicList`, `TopicDetail`, `ReplyComposer`, `ReportAction`.
- `shared/`: accessible form controls, skeletons, empty/error states, pagination, badges and confirmation dialogs.

Keep components organized by feature rather than by generic file type. Pages compose feature components; they should not duplicate API/business rules.

## State and data strategy

- **Remote server state:** TanStack Query (recommended addition) for API data, caching, query invalidation, retries on transient safe requests, and loading/error states.
- **Local UI state:** React state/reducer for open panels, form drafts and transient filters. Use URL search params for shareable marketplace filters and pagination.
- **Identity:** a small `AuthProvider` owns current user and auth lifecycle. Prefer secure, HttpOnly, SameSite cookies when deployment permits; if bearer tokens are used, follow a reviewed secure storage/refresh strategy and never put long-lived tokens in `localStorage`.
- **Forms:** controlled, validated forms with server validation mapped to fields. Do not trust role, price, totals or ownership supplied by client state.
- **Cache:** clear user-specific query data on logout/account switch. Avoid caching sensitive KYC or payment provider payloads. Treat a successful mutation as confirmed only after the server response.
- **API client:** central Axios instance with base URL, request ID, JSON headers, consistent error parsing and auth handling. Do not automatically replay payment/listing mutations unless the same idempotency key is reused.
- **Accessibility:** semantic headings/landmarks, keyboard operation, visible focus, labels and error announcements; responsive layout and adequate contrast. Avoid conveying order/payment state by color alone.

## Shared application shell

Desktop shell uses a persistent left sidebar and a compact top bar; mobile widths collapse navigation to a menu/bottom navigation. Header includes FarmLink mark, current role, search shortcut, notifications and account menu. Main content follows a consistent max-width grid, page title, brief context, primary action and breadcrumbs when nested. Include offline/network status only where a feature can actually queue work.

## Dashboard wireframes

### Farmer dashboard

```text
+--------------------------------------------------------------------+
| Header: FarmLink | Search | Alerts | Amina (Farmer)                 |
+------------------+-------------------------------------------------+
| Dashboard        | Good morning, Amina       [+ Add produce]        |
| My produce       | [Active listings] [Open offers] [Orders to fulfil]|
| Offers           |-------------------------------------------------|
| Orders           | Offers needing a response                        |
| Marketplace      | Listing       Buyer       Offer        [Review]  |
| Knowledge        |-------------------------------------------------|
| Forum            | My listings: crop, available quantity, status    |
| Profile          | Recent activity / local farming tips            |
+------------------+-------------------------------------------------+
```

Prioritize fast listing creation, current stock, buyer offers, order fulfilment and relevant local learning. Offer review displays the full terms and expiry; accepting requires an explicit confirmation.

### Buyer dashboard

```text
+--------------------------------------------------------------------+
| Header: FarmLink | Search | Alerts | Buyer account                  |
+------------------+-------------------------------------------------+
| Dashboard        | Find produce or inputs       [Browse listings]    |
| Browse           | [Saved] [Pending offers] [Orders in progress]     |
| My offers        |-------------------------------------------------|
| Orders           | Recommended nearby listings (with location consent)|
| Saved            | Listing cards: crop/input, price/unit, county     |
| Knowledge        | Offer activity | Delivery/payment milestones     |
| Forum            |-------------------------------------------------|
| Profile          | Price trends link / recent marketplace activity   |
+------------------+-------------------------------------------------+
```

Support search by crop/input, unit price, availability and approximate distance. Clearly show quantity units, seller verification status and total estimated price. Never imply that a recommendation or seller verification guarantees quality.

### Supplier dashboard

```text
+--------------------------------------------------------------------+
| Header: FarmLink | Search | Alerts | Supplier account               |
+------------------+-------------------------------------------------+
| Dashboard        | Input business overview       [+ Add input]       |
| My inputs        | [Active SKUs] [Open enquiries] [Orders]           |
| Offers           |-------------------------------------------------|
| Orders           | Enquiries/offers requiring action                 |
| Marketplace      | Product   County   Quantity   Status     [Review]|
| Knowledge        | Inventory and availability                        |
| Forum            | Recent orders / input safety & use resources       |
| Profile          |-------------------------------------------------|
+------------------+-------------------------------------------------+
```

Emphasize stock accuracy, input type/brand, packaging unit, availability area, enquiries and order status. Keep any supplier verification claims explicit and scoped.

## Marketplace, knowledge hub and forum

- **Marketplace:** searchable, paginated listing grid with filters; retain filters in URL. Listing cards show category, title, unit price, available quantity, county and timestamp. Detail page includes description, seller summary, approximate location and an offer action for eligible buyers. Provide clear empty, loading and retry states.
- **Knowledge hub:** searchable topic index and article reader with category, reading time, language and last-updated date. Separate editorial content from user posts; show source/reviewer where available. Do not present general education as individualized agronomic advice.
- **Forum:** topic list by category, reply thread and report/moderation actions. Sanitize/render user content safely; implement pagination and abuse controls through the backend. Do not silently hide server moderation or report errors.

## Responsive behavior and UX states

At narrow widths, sidebar becomes an accessible drawer, cards become one column, and tables become stacked summaries. Forms should save only safe drafts when explicitly designed to do so. Every query has loading, empty, error and retry UI; every mutation has pending and confirmed/failed feedback. Payment screens must instruct users to follow the M-Pesa prompt and wait for server-confirmed status—never claim payment succeeded from a button tap.

## Suggested source layout

```text
src/
  app/                 # router, providers, app shell
  features/
    auth/ dashboard/ marketplace/ listings/
    offers/ orders/ knowledge/ forum/ profile/
  services/            # api client and endpoint modules
  components/          # small shared accessible primitives
  hooks/
  types/
```

## Delivery sequence

1. Build router, auth shell, error/loading patterns and `/me` integration.
2. Implement role dashboard shells and marketplace browse/detail.
3. Add listing management and offer history/actions.
4. Add orders/payment status from server-confirmed state.
5. Add knowledge hub/forum, accessibility checks and responsive QA.

Add tests for role-aware routing, API errors, offer submission/idempotency behavior, accessible form validation and responsive navigation.
