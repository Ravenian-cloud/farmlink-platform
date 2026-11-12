# FarmLink web frontend

The web frontend is served by the Laravel application in `backend-php`; there is no separate React/Vite runtime.

## PHP frontend structure

- `routes/web.php` maps `/marketplace`, `/dashboard`, `/community`, `/impact`, `/feedback`, `/account`, `/community-admin`, and `/ads` to the shared Blade view.
- `resources/views/app.blade.php` renders the page shell and page-specific initial markup.
- `public/css/farmlink.css` contains the responsive styles.
- `public/js/farmlink.js` handles browser interactions and calls the same-origin `/api/v1` endpoints with the user's bearer token.

Run `php artisan serve` from `backend-php` and open `http://127.0.0.1:8000`. Configure Apache to use `backend-php/public` as its document root when deploying under XAMPP.

## UI behavior

The PHP-served pages provide account registration and sign-in; marketplace browsing and buyer offers; role-specific listings, bid negotiation and orders; community articles, forum discussions and moderation; farmer and partner impact reports; pilot feedback; and supplier advertising. The API remains authoritative for roles, authorization, validation, payment state, and moderation.

Use semantic controls, labeled form fields, visible loading/error states, keyboard-accessible actions, and responsive layouts. Escape user-provided text before inserting it into HTML. Payment and escrow status must reflect server responses; escrow custody is simulated and must not be represented as real fund custody.
