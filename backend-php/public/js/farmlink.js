(() => {
  const root = document.querySelector('#app-main');
  if (!root) return;

  const page = root.dataset.page;
  const tokenKey = 'farmlink_access_token';
  const apiRoot = '/api/v1';
  const escapeHtml = (value = '') => String(value).replace(/[&<>"']/g, (character) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[character]));
  const uuid = () => crypto.randomUUID();
  const money = (minor, currency = 'KES') => new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
  }).format(Number(minor || 0) / 100);
  const notice = (message, error = false) => {
    const element = document.querySelector('#notice');
    if (!element) return;
    element.textContent = message;
    element.className = error ? 'error-message' : 'success-message';
    element.setAttribute('role', error ? 'alert' : 'status');
  };
  const showError = (element, error, fallback) => {
    if (element) element.innerHTML = `<p role="alert">${escapeHtml(error?.message || fallback)}</p>`;
  };

  async function api(path, options = {}) {
    const includeMeta = options.includeMeta === true;
    if (includeMeta) {
      options = { ...options };
      delete options.includeMeta;
    }
    const headers = {
      Accept: 'application/json',
      ...(sessionStorage.getItem(tokenKey)
        ? { Authorization: `Bearer ${sessionStorage.getItem(tokenKey)}` }
        : {}),
      ...(options.headers || {}),
    };
    const body = options.body;
    if (body !== undefined && !(body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options = { ...options, body: JSON.stringify(body) };
    }
    const method = (options.method || 'GET').toUpperCase();
    if (['POST', 'PATCH', 'PUT', 'DELETE'].includes(method) && !headers['Idempotency-Key']) {
      headers['Idempotency-Key'] = uuid();
    }
    const response = await fetch(`${apiRoot}${path}`, { ...options, method, headers });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
      const validationMessage = Object.values(result.errors || {}).flat()[0];
      throw new Error(result.error?.message || result.message || validationMessage || 'Request failed. Please try again.');
    }
    return includeMeta ? result : result.data ?? result;
  }

  const asList = (value) => Array.isArray(value) ? value : [];

  async function loadUser() {
    try {
      window.farmlinkUser = await api('/me');
    } catch {
      window.farmlinkUser = null;
    }
    const user = window.farmlinkUser;
    const accountLink = document.querySelector('a[href="/account"]');
    if (accountLink) accountLink.textContent = user?.name || 'Sign in';
    if (user && document.querySelector('#admin-link')) document.querySelector('#admin-link').hidden = false;
    if (user?.role === 'supplier' && document.querySelector('#ads-link')) document.querySelector('#ads-link').hidden = false;
    return user;
  }

  async function loadListings(category = 'produce') {
    const grid = document.querySelector('#listing-grid');
    grid.innerHTML = '<p role="status">Loading listings…</p>';
    try {
      const products = asList(await api(`/products?category=${encodeURIComponent(category)}&per_page=24`));
      grid.innerHTML = products.length
        ? products.map((product) => `
          <article>
            <h2>${escapeHtml(product.name)}</h2>
            <p>${escapeHtml(category === 'produce' ? product.crop : product.input_type)}${product.brand ? ` · ${escapeHtml(product.brand)}` : ''}</p>
            <p class="listing-price">${money(product.price_minor, product.currency || 'KES')} <span>per ${escapeHtml(product.unit)}</span></p>
            <p>${escapeHtml(product.quantity_available)} ${escapeHtml(product.unit)} available · ${escapeHtml(product.county || 'Location not specified')}</p>
            ${window.farmlinkUser?.role === 'buyer' ? `<button type="button" data-offer="${escapeHtml(product.id)}" data-name="${escapeHtml(product.name)}" data-unit="${escapeHtml(product.unit)}" data-currency="${escapeHtml(product.currency || 'KES')}" data-max="${Number(product.quantity_available)}">Make an offer</button>` : ''}
          </article>`).join('')
        : `<p>No active ${category === 'produce' ? 'produce' : 'input'} listings are available.</p>`;
    } catch (error) {
      showError(grid, error, 'Listings could not be loaded.');
    }
  }

  function initMarketplace() {
    loadUser().then(() => loadListings());
    document.querySelectorAll('[data-category]').forEach((button) => {
      button.addEventListener('click', () => {
        document.querySelectorAll('[data-category]').forEach((item) => {
          item.setAttribute('aria-pressed', String(item === button));
        });
        loadListings(button.dataset.category);
      });
    });

    const grid = document.querySelector('#listing-grid');
    grid.addEventListener('click', (event) => {
      const button = event.target.closest('[data-offer]');
      if (!button) return;
      const dialog = document.createElement('div');
      dialog.setAttribute('role', 'dialog');
      dialog.setAttribute('aria-modal', 'true');
      dialog.setAttribute('aria-labelledby', 'offer-title');
      dialog.innerHTML = `
        <form id="offer-form">
          <h2 id="offer-title">Offer for ${escapeHtml(button.dataset.name)}</h2>
          <label>Quantity (${escapeHtml(button.dataset.unit)})
            <input name="quantity" type="number" min="0.001" max="${Number(button.dataset.max)}" step="0.001" required>
          </label>
          <label>Your unit price (${escapeHtml(button.dataset.currency)})
            <input name="price" type="number" min="0.01" step="0.01" required>
          </label>
          <label>Message (optional)<textarea name="message" maxlength="1000"></textarea></label>
          <p id="offer-error" role="alert"></p>
          <button type="submit">Submit offer</button>
          <button type="button" data-close>Cancel</button>
        </form>`;
      root.append(dialog);
      dialog.querySelector('[data-close]').addEventListener('click', () => dialog.remove());
      dialog.querySelector('form').addEventListener('submit', async (submitEvent) => {
        submitEvent.preventDefault();
        const form = new FormData(submitEvent.currentTarget);
        const quantity = Number(form.get('quantity'));
        const price = Number(form.get('price'));
        if (!Number.isFinite(quantity) || quantity <= 0 || !Number.isFinite(price) || price <= 0) {
          dialog.querySelector('#offer-error').textContent = 'Enter a valid quantity and price.';
          return;
        }
        const submitButton = submitEvent.currentTarget.querySelector('[type="submit"]');
        submitButton.disabled = true;
        try {
          await api(`/products/${encodeURIComponent(button.dataset.offer)}/bids`, {
            method: 'POST',
            body: {
              quantity,
              unit_price_minor: Math.round(price * 100),
              message: String(form.get('message') || '').trim() || undefined,
            },
          });
          dialog.remove();
          notice('Your offer has been submitted.');
        } catch (error) {
          dialog.querySelector('#offer-error').textContent = error.message;
          submitButton.disabled = false;
        }
      });
    });
  }

  function authFields(registering) {
    return `
      ${registering ? `
        <label>Full name<input name="name" autocomplete="name" required maxlength="160" placeholder="e.g. Amina Wanjiku"></label>
        <label>I am joining as a<select name="role"><option value="farmer">Farmer</option><option value="buyer">Buyer</option><option value="supplier">Agricultural supplier</option></select></label>
        <label data-role-field="farmer">Farm name (optional)<input name="farm_name" maxlength="160"></label>
        <label data-role-field="supplier" hidden>Business name (optional)<input name="business_name" maxlength="160"></label>
        <label>County (optional)<input name="county" maxlength="100" placeholder="e.g. Nakuru"></label>
      ` : ''}
      <label>${registering ? 'Phone number' : 'Phone number or email'}
        <input name="${registering ? 'phone' : 'login'}" ${registering ? 'type="tel" autocomplete="tel" maxlength="32"' : 'autocomplete="username" maxlength="254"'} required>
      </label>
      ${registering ? '<label>Email address (optional)<input name="email" type="email" maxlength="254" autocomplete="email"></label>' : ''}
      <label>Password<input name="password" type="password" autocomplete="${registering ? 'new-password' : 'current-password'}" ${registering ? 'minlength="12"' : ''} required></label>
      <label class="show-password"><input type="checkbox" data-show-password> Show password</label>
      <button class="auth-submit" type="submit">${registering ? 'Create account' : 'Sign in'}</button>`;
  }

  function initAccount() {
    const target = document.querySelector('#account-state');
    loadUser().then((user) => {
      if (user) {
        target.innerHTML = `
          <section class="account-card">
            <span class="auth-step">YOUR FARMLINK ACCOUNT</span>
            <h2>You’re signed in.</h2>
            <p>Signed in as <strong>${escapeHtml(user.name)}</strong> (${escapeHtml(user.role)}).</p>
            <p>Account verification: <strong>${escapeHtml(user.kyc_status || 'pending')}</strong></p>
            <button type="button" id="signout">Sign out</button>
          </section>`;
        target.querySelector('#signout').addEventListener('click', () => {
          sessionStorage.removeItem(tokenKey);
          location.href = '/marketplace';
        });
        return;
      }

      let registering = false;
      const renderForm = (message = '', error = false) => {
        target.innerHTML = `
          <div class="auth-page">
            <section class="auth-intro">
              <span class="auth-eyebrow">GROW WITH YOUR COMMUNITY</span>
              <h1>${registering ? 'Make your next move.' : 'Welcome back.'}</h1>
              <p>${registering ? 'Join local farmers, buyers, and suppliers building better markets together.' : 'Sign in to manage listings, offers, and orders in one place.'}</p>
              <div class="auth-points"><span>Local marketplace</span><span>Direct negotiation</span><span>Built for growers</span></div>
            </section>
            <section class="auth-card">
              <span class="auth-step">${registering ? 'GET STARTED · FREE ACCOUNT' : 'YOUR FARMLINK ACCOUNT'}</span>
              <h2>${registering ? 'Create your account' : 'Sign in'}</h2>
              <p class="auth-subtitle">${registering ? 'A few details to get your account ready.' : 'Enter your phone number or email and password.'}</p>
              ${message ? `<p class="auth-message ${error ? 'error-message' : 'success-message'}" role="${error ? 'alert' : 'status'}">${escapeHtml(message)}</p>` : ''}
              <form id="auth-form">${authFields(registering)}</form>
              <p class="auth-switch">${registering ? 'Already have an account?' : 'New to FarmLink?'}
                <button class="text-button" type="button" id="auth-switch">${registering ? 'Sign in' : 'Create an account'}</button>
              </p>
            </section>
          </div>`;
        const form = target.querySelector('#auth-form');
        const role = form.querySelector('[name="role"]');
        role?.addEventListener('change', () => {
          form.querySelectorAll('[data-role-field]').forEach((field) => {
            field.hidden = field.dataset.roleField !== role.value;
          });
        });
        form.querySelector('[data-show-password]').addEventListener('change', (event) => {
          form.querySelector('[name="password"]').type = event.target.checked ? 'text' : 'password';
        });
        form.addEventListener('submit', async (event) => {
          event.preventDefault();
          const submit = form.querySelector('[type="submit"]');
          submit.disabled = true;
          const values = new FormData(form);
          try {
            if (registering) {
              const role = String(values.get('role'));
              await api('/auth/register', {
                method: 'POST',
                body: {
                  name: String(values.get('name')).trim(),
                  phone: String(values.get('phone')).trim(),
                  email: String(values.get('email') || '').trim() || undefined,
                  password: values.get('password'),
                  role,
                  profile: {
                    county: String(values.get('county') || '').trim() || undefined,
                    ...(role === 'farmer' ? { farm_name: String(values.get('farm_name') || '').trim() || undefined } : {}),
                    ...(role === 'supplier' ? { business_name: String(values.get('business_name') || '').trim() || undefined } : {}),
                  },
                },
              });
              registering = false;
              renderForm('Your account was created. Sign in with your phone number or email and password.');
              return;
            }
            const result = await api('/auth/login', {
              method: 'POST',
              body: { login: String(values.get('login')).trim(), password: values.get('password') },
            });
            const token = result.access_token || result.token;
            if (typeof token !== 'string' || token.length === 0) throw new Error('The authentication response did not contain an access token.');
            sessionStorage.setItem(tokenKey, token);
            location.href = '/dashboard';
          } catch (error) {
            submit.disabled = false;
            renderForm(error.message, true);
          }
        });
        target.querySelector('#auth-switch').addEventListener('click', () => {
          registering = !registering;
          renderForm();
        });
      };
      renderForm();
    });
  }

  function initFeedback() {
    const form = document.querySelector('#feedback-form');
    const category = form.querySelector('[name="category"]');
    const optionalRatings = document.createElement('div');
    optionalRatings.id = 'feedback-specific-rating';
    category.after(optionalRatings);
    category.addEventListener('change', () => {
      optionalRatings.innerHTML = category.value === 'negotiation'
        ? '<label>Negotiation system rating (1 to 5)<select name="negotiation_rating" required></select></label>'
        : category.value === 'recommendation'
          ? '<label>Recommendation accuracy (1 to 5)<select name="recommendation_accuracy_rating" required></select></label>'
          : '';
      optionalRatings.querySelectorAll('select').forEach((select) => {
        select.innerHTML = [1, 2, 3, 4, 5].map((value) => `<option value="${value}">${value}</option>`).join('');
      });
    });
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const values = new FormData(form);
      const payload = {
        category: values.get('category'),
        rating: Number(values.get('rating')),
        summary: String(values.get('summary') || '').trim() || undefined,
        details: String(values.get('details')).trim(),
        ...(values.get('negotiation_rating') ? { negotiation_rating: Number(values.get('negotiation_rating')) } : {}),
        ...(values.get('recommendation_accuracy_rating') ? { recommendation_accuracy_rating: Number(values.get('recommendation_accuracy_rating')) } : {}),
      };
      try {
        await api('/pilot-feedback', { method: 'POST', body: payload });
        notice('Thank you. Your pilot feedback has been recorded.');
        form.reset();
        optionalRatings.innerHTML = '';
      } catch (error) {
        notice(error.message, true);
      }
    });
  }

  function initCommunity() {
    const content = document.querySelector('#community-content');
    let mode = 'articles';
    let selectedThread = null;

    async function loadArticles() {
      content.innerHTML = '<p role="status">Loading Knowledge Hub content…</p>';
      try {
        const articles = asList(await api('/knowledge/articles'));
        content.innerHTML = articles.length ? articles.map((article) => `
          <article>
            <h2>${escapeHtml(article.title)}</h2><p>${escapeHtml(article.summary)}</p>
            <small>${article.content_type === 'tutorial' ? 'Tutorial' : 'Article'}</small>
            <button type="button" data-article="${escapeHtml(article.slug)}">Read more</button>
          </article>`).join('') : '<p>Articles and tutorials will appear here when published.</p>';
      } catch (error) {
        showError(content, error, 'Knowledge Hub content could not be loaded.');
      }
    }

    async function openArticle(slug) {
      try {
        const article = await api(`/knowledge/articles/${encodeURIComponent(slug)}`);
        content.innerHTML = `
          <article>
            <button type="button" data-back>Back to Knowledge Hub</button>
            <h2>${escapeHtml(article.title)}</h2><p>${escapeHtml(article.summary)}</p>
            ${article.video_url ? `<p><a href="${escapeHtml(article.video_url)}" target="_blank" rel="noopener noreferrer">Watch tutorial video</a></p>` : ''}
            <div class="pre-wrap">${escapeHtml(article.body)}</div>
          </article>`;
      } catch (error) {
        notice(error.message, true);
      }
    }

    async function loadThreads() {
      content.innerHTML = '<p role="status">Loading community discussions…</p>';
      try {
        const threads = asList(await api('/forum/threads'));
        content.innerHTML = `
          <form id="thread-form">
            <h2>Share an experience or ask the community</h2>
            <label>Topic<input name="title" minlength="5" maxlength="180" required></label>
            <label>Your post<textarea name="body" minlength="10" maxlength="10000" required></textarea></label>
            <button type="submit">Start discussion</button>
          </form>
          ${threads.map((thread) => `
            <article>
              <h2>${escapeHtml(thread.title)}</h2><p>${escapeHtml(thread.body)}</p>
              <small>${escapeHtml(thread.author?.name || 'FarmLink member')} · ${Number(thread.posts_count || 0)} replies</small>
              <button type="button" data-thread="${escapeHtml(thread.id)}">Open discussion</button>
            </article>`).join('')}
          ${threads.length ? '' : '<p>No discussions yet. Start the first one.</p>'}`;
        content.querySelector('#thread-form').addEventListener('submit', async (event) => {
          event.preventDefault();
          const values = new FormData(event.currentTarget);
          try {
            await api('/forum/threads', { method: 'POST', body: { title: values.get('title'), body: values.get('body') } });
            notice('Your discussion has been posted.');
            await loadThreads();
          } catch (error) {
            notice(error.message, true);
          }
        });
      } catch (error) {
        showError(content, error, 'Community discussions could not be loaded.');
      }
    }

    async function openThread(id) {
      try {
        selectedThread = await api(`/forum/threads/${encodeURIComponent(id)}`);
        renderThread();
      } catch (error) {
        notice(error.message, true);
      }
    }

    function renderThread() {
      const thread = selectedThread;
      const posts = asList(thread.posts);
      content.innerHTML = `
        <button type="button" data-back>Back to discussions</button>
        <article>
          <h2>${escapeHtml(thread.title)}</h2><p>${escapeHtml(thread.body)}</p>
          <small>Started by ${escapeHtml(thread.author?.name || 'FarmLink member')} · ${Number(thread.posts_count || posts.length)} replies</small>
          <ul aria-label="Thread replies">${posts.map((post) => `
            <li${post.parent_id ? ' class="nested-reply"' : ''}>
              <p>${escapeHtml(post.body)}</p><small>${escapeHtml(post.author?.name || 'FarmLink member')}</small>
              <form data-reply-form="${escapeHtml(post.id)}">
                <label>Reply to this comment<textarea name="body" minlength="2" maxlength="5000" required></textarea></label>
                <button type="submit">Post reply</button>
              </form>
            </li>`).join('')}</ul>
          <form id="thread-reply-form">
            <label>Add a reply<textarea name="body" minlength="2" maxlength="5000" required></textarea></label>
            <button type="submit">Post reply</button>
          </form>
        </article>`;
      content.querySelectorAll('[data-reply-form]').forEach((form) => {
        form.addEventListener('submit', (event) => submitReply(event, form.dataset.replyForm));
      });
      content.querySelector('#thread-reply-form').addEventListener('submit', (event) => submitReply(event));
    }

    async function submitReply(event, parentId) {
      event.preventDefault();
      const values = new FormData(event.currentTarget);
      try {
        await api(`/forum/threads/${encodeURIComponent(selectedThread.id)}/posts`, {
          method: 'POST',
          body: { body: String(values.get('body')).trim(), parent_id: parentId || undefined },
        });
        await openThread(selectedThread.id);
      } catch (error) {
        notice(error.message, true);
      }
    }

    document.querySelectorAll('[data-community]').forEach((button) => {
      button.addEventListener('click', () => {
        mode = button.dataset.community;
        selectedThread = null;
        document.querySelectorAll('[data-community]').forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
        mode === 'articles' ? loadArticles() : loadThreads();
      });
    });
    content.addEventListener('click', (event) => {
      const articleButton = event.target.closest('[data-article]');
      const threadButton = event.target.closest('[data-thread]');
      if (event.target.closest('[data-back]')) {
        selectedThread = null;
        mode === 'articles' ? loadArticles() : loadThreads();
      } else if (articleButton) {
        openArticle(articleButton.dataset.article);
      } else if (threadButton) {
        openThread(threadButton.dataset.thread);
      }
    });
    loadArticles();
  }

  async function loadDashboard(user) {
    const element = document.querySelector('#dashboard-content');
    if (!user) {
      element.innerHTML = '<p>Sign in to view your account activity.</p><a href="/account">Sign in</a>';
      return;
    }
    const canListProducts = user.role === 'farmer' || user.role === 'supplier';
    const content = user.role === 'farmer'
      ? { title: 'Farmer dashboard', description: 'Manage your produce, review buyer offers, and track orders.', category: 'produce', field: 'crop', label: 'Crop' }
      : user.role === 'supplier'
        ? { title: 'Supplier dashboard', description: 'Manage agricultural inputs, respond to enquiries, and track orders.', category: 'input', field: 'input_type', label: 'Input type' }
        : { title: 'Buyer dashboard', description: 'Discover produce and inputs, negotiate prices, and follow orders.' };
    element.innerHTML = `
      <h2>${content.title}</h2><p>${content.description}</p>
      ${user.kyc_status !== 'verified' ? `<p role="status">Account verification: ${escapeHtml(user.kyc_status || 'pending')}. Marketplace actions that require verification will remain unavailable until your account is approved.</p>` : ''}
      <nav aria-label="Dashboard sections">
        <button type="button" data-section="overview" aria-pressed="true">Overview</button>
        ${canListProducts ? '<button type="button" data-section="products">My listings</button>' : ''}
        <button type="button" data-section="bids">Offers</button><button type="button" data-section="orders">Orders</button>
        <button type="button" data-refresh>Refresh</button>
      </nav><p data-dashboard-error role="alert"></p>
      <div id="dashboard-panel"><p role="status">Loading your dashboard…</p></div>`;

    let products = [];
    let bids = [];
    let transactions = [];
    const panel = element.querySelector('#dashboard-panel');
    const errorElement = element.querySelector('[data-dashboard-error]');
    const setError = (error) => { errorElement.textContent = error || ''; };

    async function refresh() {
      setError('');
      try {
        const requests = [api('/bids'), api('/transactions')];
        if (canListProducts) requests.push(api('/my/products'));
        const results = await Promise.all(requests);
        bids = asList(results[0]);
        transactions = asList(results[1]);
        products = canListProducts ? asList(results[2]) : [];
        await renderSection(element.querySelector('[data-section][aria-pressed="true"]')?.dataset.section || 'overview');
      } catch (error) {
        setError(error.message);
      }
    }

    function renderProductForm() {
      return `
        <form id="product-form">
          <h3>Create a listing</h3>
          <label>Name<input name="name" required maxlength="180"></label>
          <label>${content.label}<input name="type" required maxlength="100"></label>
          ${content.category === 'input' ? '<label>Brand (optional)<input name="brand" maxlength="120"></label>' : ''}
          <label>Description<textarea name="description" maxlength="10000"></textarea></label>
          <label>Quantity<input name="quantity" type="number" min="0.001" step="0.001" required></label>
          <label>Unit (e.g. kg, bag)<input name="unit" maxlength="24" value="kg" required></label>
          <label>Price per unit (KES)<input name="price" type="number" min="0.01" step="0.01" required></label>
          <label>County (optional)<input name="county" maxlength="100"></label>
          <button type="submit">Publish listing</button>
        </form>
        ${products.length ? products.map((product) => `
          <article><h3>${escapeHtml(product.name)}</h3><p>${escapeHtml(product.status)} · ${escapeHtml(product.quantity_available)} ${escapeHtml(product.unit)}</p>
          <p>${money(product.price_minor, product.currency)} per ${escapeHtml(product.unit)} · ${escapeHtml(product.county || 'County not specified')}</p><p>${escapeHtml(product.description)}</p></article>`).join('') : '<p>You have not created any listings yet.</p>'}`;
    }

    function renderBid(bid) {
      const revisions = asList(bid.history || bid.revisions);
      const offer = bid.current_offer || revisions[revisions.length - 1];
      const isSeller = bid.seller_id === user.id;
      const canRespond = bid.status === 'pending' && offer?.created_by !== user.id;
      return `<article>
        <h3>${escapeHtml(bid.product?.name || 'Marketplace listing')}</h3><p>Status: ${escapeHtml(bid.status)}</p>
        ${offer ? `<p>Latest offer: ${escapeHtml(offer.quantity)} at ${money(offer.unit_price_minor)} per unit${offer.message ? ` — ${escapeHtml(offer.message)}` : ''}</p>` : ''}
        <p>Role: ${isSeller ? 'Seller' : 'Buyer'} · Offer expires ${bid.expires_at ? escapeHtml(new Date(bid.expires_at).toLocaleString()) : 'not specified'}</p>
        ${revisions.length ? `<details><summary>Offer history</summary><ol>${revisions.map((revision) => `<li>${escapeHtml(revision.quantity)} × ${money(revision.unit_price_minor)} — ${escapeHtml(revision.message || 'No message')}</li>`).join('')}</ol></details>` : ''}
        ${canRespond ? `
          ${isSeller ? `<button type="button" data-bid-action="accept" data-bid="${escapeHtml(bid.id)}">Accept offer</button><button type="button" data-bid-action="reject" data-bid="${escapeHtml(bid.id)}">Reject offer</button>` : ''}
          <form data-counter="${escapeHtml(bid.id)}">
            <h4>Counter-offer</h4>
            <label>Quantity<input name="quantity" type="number" min="0.001" step="0.001" value="${Number(offer?.quantity || 0) || ''}" required></label>
            <label>Unit price (KES)<input name="price" type="number" min="0.01" step="0.01" value="${Number(offer?.unit_price_minor || 0) / 100 || ''}" required></label>
            <label>Message (optional)<input name="message" maxlength="1000"></label>
            <button type="submit">Send counter-offer</button>
          </form>` : ''}
      </article>`;
    }

    function renderTransaction(transaction) {
      const isBuyer = transaction.buyer_id === user.id;
      return `<article>
        <h3>${escapeHtml(transaction.product?.name || 'Marketplace order')}</h3>
        <p>${escapeHtml(transaction.quantity)} ${escapeHtml(transaction.unit)} · ${money(transaction.total_minor, transaction.currency)}</p>
        <p>Order: ${escapeHtml(transaction.status)} · Payment: ${escapeHtml(transaction.payment_status)} · Escrow: ${escapeHtml(transaction.escrow_status)} (simulated)</p>
        <p>${isBuyer ? 'Seller' : 'Buyer'}: ${escapeHtml(isBuyer ? transaction.seller?.name : transaction.buyer?.name)}</p>
        ${isBuyer && transaction.status === 'awaiting_payment' && transaction.payment_status !== 'succeeded' ? `
          <form data-payment="${escapeHtml(transaction.id)}"><label>M-Pesa phone<input name="phone" type="tel" maxlength="32" placeholder="+2547..." required></label>
          <button type="submit">Request M-Pesa payment</button></form>` : ''}
        ${isBuyer && transaction.payment_status === 'succeeded' && transaction.status !== 'completed'
          ? `<p>Payment is recorded. Escrow custody is simulated; the app does not hold or release funds.</p><button type="button" data-confirm-delivery="${escapeHtml(transaction.id)}">Confirm goods received</button>` : ''}
      </article>`;
    }

    async function renderOverview() {
      const activeCount = products.filter((product) => product.status === 'active').length;
      panel.innerHTML = `
        <section aria-label="Dashboard summary"><h2>At a glance</h2><ul>
          ${canListProducts ? `<li>Your active listings: ${activeCount}</li>` : ''}
          <li>Open offers: ${bids.filter((bid) => bid.status === 'pending').length}</li><li>Orders: ${transactions.length}</li>
        </ul></section><section id="sponsored-slot" aria-label="Sponsored advertisement"></section>
        <section id="premium-slot"></section><section id="market-slot"></section>`;
      loadSponsoredAd(panel.querySelector('#sponsored-slot'));
      loadPremium(panel.querySelector('#premium-slot'));
      loadMarketInsights(panel.querySelector('#market-slot'));
    }

    async function renderSection(section) {
      element.querySelectorAll('[data-section]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.section === section));
      });
      if (section === 'overview') {
        await renderOverview();
      } else if (section === 'products') {
        panel.innerHTML = `<section><h2>My listings</h2>${renderProductForm()}</section>`;
        panel.querySelector('#product-form')?.addEventListener('submit', async (event) => {
          event.preventDefault();
          const values = new FormData(event.currentTarget);
          const payload = {
            category: content.category,
            name: String(values.get('name')).trim(),
            [content.field]: String(values.get('type')).trim(),
            brand: String(values.get('brand') || '').trim() || undefined,
            description: String(values.get('description') || '').trim(),
            quantity_available: Number(values.get('quantity')),
            unit: String(values.get('unit')).trim(),
            price_minor: Math.round(Number(values.get('price')) * 100),
            currency: 'KES',
            county: String(values.get('county') || '').trim() || undefined,
          };
          try {
            await api('/products', { method: 'POST', body: payload });
            notice('Your listing has been published.');
            await refresh();
            await renderSection('products');
          } catch (error) {
            setError(error.message);
          }
        });
      } else if (section === 'bids') {
        const visibleBids = bids.filter((bid) => bid.buyer_id === user.id || bid.seller_id === user.id);
        panel.innerHTML = `<section><h2>Offers and negotiation</h2>${visibleBids.length ? visibleBids.map(renderBid).join('') : '<p>You have no offers yet. Browse the marketplace to get started.</p>'}</section>`;
        panel.querySelectorAll('[data-bid-action]').forEach((button) => {
          button.addEventListener('click', async () => {
            try {
              await api(`/bids/${encodeURIComponent(button.dataset.bid)}/${button.dataset.bidAction}`, { method: 'POST', body: {} });
              await refresh();
            } catch (error) {
              setError(error.message);
            }
          });
        });
        panel.querySelectorAll('[data-counter]').forEach((form) => {
          form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const values = new FormData(form);
            try {
              await api(`/bids/${encodeURIComponent(form.dataset.counter)}/counter`, {
                method: 'POST',
                body: {
                  quantity: Number(values.get('quantity')),
                  unit_price_minor: Math.round(Number(values.get('price')) * 100),
                  message: String(values.get('message') || '').trim() || undefined,
                },
              });
              await refresh();
            } catch (error) {
              setError(error.message);
            }
          });
        });
      } else if (section === 'orders') {
        panel.innerHTML = `<section><h2>Orders</h2>${transactions.length ? transactions.map(renderTransaction).join('') : '<p>You do not have any orders yet.</p>'}
          <p>Payment and escrow state displayed here is application-recorded; escrow custody is simulated.</p></section>`;
        panel.querySelectorAll('[data-payment]').forEach((form) => {
          form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const values = new FormData(form);
            try {
              await api(`/transactions/${encodeURIComponent(form.dataset.payment)}/payments`, { method: 'POST', body: { phone: values.get('phone') } });
              notice('Payment request started. Approve the M-Pesa prompt on your phone.');
              await refresh();
              await renderSection('orders');
            } catch (error) {
              setError(error.message);
            }
          });
        });
        panel.querySelectorAll('[data-confirm-delivery]').forEach((button) => {
          button.addEventListener('click', async () => {
            if (!window.confirm('Confirm delivery only after receiving the goods. Escrow custody is simulated.')) return;
            try {
              await api(`/transactions/${encodeURIComponent(button.dataset.confirmDelivery)}/delivery-confirmation`, { method: 'POST', body: {} });
              await refresh();
              await renderSection('orders');
            } catch (error) {
              setError(error.message);
            }
          });
        });
      }
    }

    element.querySelectorAll('[data-section]').forEach((button) => {
      button.addEventListener('click', () => renderSection(button.dataset.section));
    });
    element.querySelector('[data-refresh]').addEventListener('click', refresh);
    await refresh();
  }

  async function loadSponsoredAd(slot) {
    try {
      const ad = await api('/ads');
      if (!ad) {
        slot.remove();
        return;
      }
      slot.innerHTML = `<p><strong>${escapeHtml(ad.label)}</strong></p><a href="${escapeHtml(ad.destination_url)}" data-sponsored-link rel="sponsored nofollow"><img src="${escapeHtml(ad.creative_url)}" alt="${escapeHtml(ad.name)}" loading="lazy" style="max-width:100%;height:auto"></a>`;
      slot.querySelector('[data-sponsored-link]').addEventListener('click', async (event) => {
        event.preventDefault();
        try {
          await api(`/ads/${encodeURIComponent(ad.id)}/events/click`, { method: 'POST', body: {} });
        } catch {
          notice('Click tracking failed; opening the advertiser link anyway.', true);
        }
        location.assign(ad.destination_url);
      });
      api(`/ads/${encodeURIComponent(ad.id)}/events/impression`, { method: 'POST', body: {} }).catch(() => {
        slot.insertAdjacentHTML('beforeend', '<p role="status">Ad impression tracking is temporarily unavailable.</p>');
      });
    } catch {
      slot.remove();
    }
  }

  async function loadMarketInsights(slot) {
    slot.innerHTML = '<h2>Market snapshots</h2><p role="status">Loading market snapshots…</p>';
    try {
      const snapshots = asList(await api('/analytics/market'));
      let advanced = [];
      try {
        const account = await api('/subscription');
        if (account.status === 'active') advanced = asList(await api('/analytics/market/advanced'));
      } catch {
        // Basic market insights remain available if premium status cannot be checked.
      }
      slot.innerHTML = `
        <h2>Market snapshots</h2><p>Historical completed-trade baselines; not a price guarantee or AI forecast.</p>
        ${snapshots.length ? `<ul>${snapshots.map(({ dimensions, metrics, computed_at }) => `
          <li><h3>${escapeHtml(dimensions.crop)} · ${escapeHtml(dimensions.county)}</h3>
            <p>Median completed-trade price: ${money(metrics.price_median_minor)} per ${escapeHtml(dimensions.unit)} (${escapeHtml(metrics.sample_size)} recent trades)</p>
            <p>Recent orders: ${escapeHtml(metrics.completed_orders_30d)} in 30 days.${metrics.demand_change_percent === null ? ' No prior-period comparison is available.' : ` Change from prior 30 days: ${escapeHtml(metrics.demand_change_percent)}%.`}</p>
            <small>Updated ${escapeHtml(new Date(computed_at).toLocaleString())}</small></li>`).join('')}</ul>` : '<p>There is not enough recent regional trading data to show an insight yet.</p>'}
        ${advanced.length ? `<section aria-label="Premium price spread analysis"><h3>Premium price spread analysis</h3><ul>${advanced.map((item) => `<li>${escapeHtml(item.dimensions.crop)} · ${escapeHtml(item.dimensions.county)}: middle-50% price spread ${money(item.price_interquartile_range_minor)} per ${escapeHtml(item.dimensions.unit)} from ${escapeHtml(item.sample_size)} completed trades.</li>`).join('')}</ul></section>` : ''}`;
    } catch {
      slot.innerHTML = '<h2>Market snapshots</h2><p role="status">Market insights are temporarily unavailable.</p>';
    }
  }

  async function loadPremium(slot) {
    try {
      const subscription = await api('/subscription');
      const paymentPending = ['initiated', 'pending'].includes(subscription.latest_payment_status);
      slot.innerHTML = `
        <h2>FarmLink Premium</h2>
        <p>${subscription.status === 'active' ? `Premium active until ${escapeHtml(new Date(subscription.ends_at).toLocaleDateString())}.` : 'Get advanced market insights and increased visibility for your listings.'}</p>
        ${subscription.price_minor > 0 ? `<p>Price: ${money(subscription.price_minor)} per 30 days.</p>` : ''}
        <p>30-day subscription. Renewal is manual; no automatic recurring charge.</p>
        ${subscription.status !== 'active' && !paymentPending && subscription.price_minor >= 100 && subscription.price_minor % 100 === 0
          ? '<form id="premium-form"><label>M-Pesa phone number<input name="phone" type="tel" maxlength="32" required></label><button type="submit">Continue with M-Pesa</button></form>'
          : subscription.status !== 'active' && (!subscription.price_minor || subscription.price_minor < 100 || subscription.price_minor % 100 !== 0)
            ? '<p>Premium checkout is not enabled yet.</p>'
            : ''}
        ${paymentPending ? '<p role="status">Payment confirmation is pending. Do not retry; contact support if it does not resolve.</p>' : ''}
        <p data-premium-message role="status"></p>`;
      slot.querySelector('#premium-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const values = new FormData(form);
        const message = slot.querySelector('[data-premium-message]');
        try {
          const response = await api('/subscription/purchase', { method: 'POST', body: { phone: values.get('phone') } });
          message.textContent = response.customer_message || 'Approve the M-Pesa prompt on your phone. Premium activates after payment confirmation.';
          const button = form.querySelector('[type="submit"]');
          button.disabled = true;
          let attempts = 0;
          const checkStatus = async () => {
            attempts += 1;
            try {
              const latest = await api('/subscription');
              if (latest.status === 'active') {
                await loadPremium(slot);
                return;
              }
              if (['failed', 'cancelled'].includes(latest.latest_payment_status)) {
                message.textContent = 'Premium payment was not completed. You can try again.';
                message.className = 'error-message';
                message.setAttribute('role', 'alert');
                button.disabled = false;
                return;
              }
              if (attempts < 12) {
                message.textContent = 'Waiting for M-Pesa payment confirmation…';
                setTimeout(checkStatus, 5000);
              } else {
                message.textContent = 'Payment confirmation is still pending. Do not retry; contact support if it does not resolve.';
              }
            } catch {
              message.textContent = 'Payment started, but subscription status could not be refreshed.';
              message.className = 'error-message';
              message.setAttribute('role', 'alert');
              button.disabled = false;
            }
          };
          setTimeout(checkStatus, 5000);
        } catch (error) {
          message.textContent = error.message;
          message.className = 'error-message';
          message.setAttribute('role', 'alert');
        }
      });
    } catch {
      slot.innerHTML = '<h2>FarmLink Premium</h2><p>Could not load subscription details.</p>';
    }
  }

  function initImpact(user) {
    const target = document.querySelector('#impact-content');
    const today = new Date().toISOString().slice(0, 10);
    const month = today.slice(0, 7);
    const farmerForm = user?.role === 'farmer' ? `
      <section><h2>Submit a voluntary farm outcome observation</h2>
        <p>Enter monthly income in KES minor units (100 units = KES 1) and estimated post-harvest loss percentages.</p>
        <form id="observation-form">
          <label>County<input name="county" value="${escapeHtml(user.profile?.county || '')}" minlength="2" maxlength="100" required></label>
          <label>Reporting month<input name="period" type="month" max="${month}" value="${month}" required></label>
          <label>Baseline monthly income (KES minor units)<input name="baseline_monthly_income_minor" type="number" min="0" max="1000000000000" required></label>
          <label>Current monthly income (KES minor units)<input name="current_monthly_income_minor" type="number" min="0" max="1000000000000" required></label>
          <label>Baseline post-harvest loss (%)<input name="baseline_post_harvest_loss_percent" type="number" min="0" max="100" step="0.01" required></label>
          <label>Current post-harvest loss (%)<input name="current_post_harvest_loss_percent" type="number" min="0" max="100" step="0.01" required></label>
          <label><input name="consent" type="checkbox" required> I consent to my report being used in aggregated county-level impact reporting.</label>
          <button type="submit">Save observation</button>
        </form>
        <button type="button" id="load-observations">View my saved reports</button><div id="observations"></div>
      </section>` : '';
    target.innerHTML = `
      <section><p>County-level, privacy-thresholded indicators aligned with SDGs 1, 2, and 9. Reported results are observational; targets are not proof of impact.</p></section>
      ${farmerForm}
      <section><h2>Partner impact dashboard</h2>
        <p>Use a county-scoped partner key issued by FarmLink. The key is kept only for this browser session.</p>
        <form id="partner-report-form"><label>Partner API key<input name="partner_key" type="password" autocomplete="off" required></label><button type="submit">Load impact reports</button></form>
        <div id="partner-report"></div>
      </section>`;

    async function loadObservations() {
      const box = target.querySelector('#observations');
      try {
        const observations = asList(await api('/impact/observations'));
        box.innerHTML = observations.length
          ? `<ul>${observations.map((item) => `<li>${escapeHtml(item.reporting_period)}: baseline income ${escapeHtml(item.baseline_monthly_income_minor)}, current income ${escapeHtml(item.current_monthly_income_minor)} (KES minor units).</li>`).join('')}</ul>`
          : '<p>No impact observations recorded yet.</p>';
      } catch (error) {
        box.innerHTML = `<p role="alert">${escapeHtml(error.message)}</p>`;
      }
    }
    target.querySelector('#load-observations')?.addEventListener('click', loadObservations);
    target.querySelector('#observation-form')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const values = new FormData(event.currentTarget);
      try {
        await api('/profile', { method: 'PATCH', body: { county: values.get('county') } });
        await api('/impact/observations', {
          method: 'POST',
          body: {
            reporting_period: `${values.get('period')}-01`,
            baseline_monthly_income_minor: Number(values.get('baseline_monthly_income_minor')),
            current_monthly_income_minor: Number(values.get('current_monthly_income_minor')),
            baseline_post_harvest_loss_percent: Number(values.get('baseline_post_harvest_loss_percent')),
            current_post_harvest_loss_percent: Number(values.get('current_post_harvest_loss_percent')),
            consent: values.get('consent') === 'on',
          },
        });
        notice('Your voluntary impact observation was saved.');
        await loadObservations();
      } catch (error) {
        notice(error.message, true);
      }
    });

    target.querySelector('#partner-report-form').addEventListener('submit', async (event) => {
      event.preventDefault();
      const key = new FormData(event.currentTarget).get('partner_key');
      const report = target.querySelector('#partner-report');
      report.innerHTML = '<p role="status">Loading impact reports…</p>';
      try {
        const response = await api('/policy/impact/report', {
          headers: { 'X-Partner-Key': String(key) },
          includeMeta: true,
        });
        const reports = asList(response.data);
        const meta = response.meta || {};
        report.innerHTML = `
          <p>${escapeHtml(meta.privacy || '')}</p><p>${escapeHtml(meta.limitations || '')}</p>
          <button type="button" data-download ${reports.length === 0 ? 'disabled' : ''}>Download JSON report</button>
          ${reports.length ? reports.map(({ region, metrics }) => `
            <article><h3>${escapeHtml(region.county)}</h3>
              <p>Reporting farmers: ${escapeHtml(metrics.farmer_sample_size)}</p>
              <p>Average monthly income: ${escapeHtml(metrics.average_baseline_monthly_income_minor)} → ${escapeHtml(metrics.average_current_monthly_income_minor)} KES minor units${metrics.observed_income_change_percent === null ? '.' : ` (${escapeHtml(metrics.observed_income_change_percent)}% observed change).`}</p>
              <p>Income target scenario: +${escapeHtml(metrics.income_target_percent.minimum)}% to +${escapeHtml(metrics.income_target_percent.maximum)}%; projected range ${escapeHtml(metrics.projected_target_monthly_income_minor.minimum)}–${escapeHtml(metrics.projected_target_monthly_income_minor.maximum)} KES minor units.</p>
              <p>Average post-harvest loss: ${escapeHtml(metrics.average_baseline_post_harvest_loss_percent)}% → ${escapeHtml(metrics.average_current_post_harvest_loss_percent)}% (${escapeHtml(metrics.post_harvest_loss_reduction_percentage_points)} percentage-point change).</p>
              <p>Completed produce trades in 12 months: ${escapeHtml(metrics.completed_produce_trades_12m ?? 'not reported (small cohort)')}</p>
              <small>${escapeHtml(metrics.methodology)}</small></article>`).join('') : '<p>No county currently meets the minimum reporting cohort.</p>'}`;
        report.querySelector('[data-download]').addEventListener('click', () => {
          const url = URL.createObjectURL(new Blob([JSON.stringify({ meta, data: reports }, null, 2)], { type: 'application/json' }));
          const link = document.createElement('a');
          link.href = url;
          link.download = `farmlink-impact-report-${today}.json`;
          link.click();
          setTimeout(() => URL.revokeObjectURL(url), 1000);
        });
      } catch (error) {
        report.innerHTML = `<p role="alert">${escapeHtml(error.message)}</p>`;
      }
    });
  }

  async function initCommunityAdmin(user) {
    const target = document.querySelector('#admin-content');
    if (!user) {
      target.innerHTML = '<p>Sign in to access community administration.</p><a href="/account">Sign in</a>';
      return;
    }
    let articles = [];
    let threads = [];
    let posts = [];
    let editingId = null;

    async function loadTools() {
      const [access, articleRows, threadRows, postRows] = await Promise.all([
        api('/community/admin-access'),
        api('/admin/community/articles'),
        api('/admin/community/forum'),
        api('/admin/community/forum/posts'),
      ]);
      if (!access.authorized) throw new Error('Community administrator access is required.');
      articles = asList(articleRows);
      threads = asList(threadRows);
      posts = asList(postRows);
      renderTools();
    }

    function renderTools() {
      target.innerHTML = `
        <section><h2>${editingId ? 'Edit Knowledge Hub content' : 'Create a Knowledge Hub article or tutorial'}</h2>
          <form id="article-form">
            <label>Title<input name="title" minlength="5" maxlength="180" required></label>
            <label>Summary<input name="summary" minlength="10" maxlength="500" required></label>
            <label>Content<textarea name="body" minlength="20" maxlength="50000" required></textarea></label>
            <label>Type<select name="content_type"><option value="article">Article</option><option value="tutorial">Tutorial</option></select></label>
            <label>HTTPS video URL (optional)<input name="video_url" type="url"></label>
            <label>Status<select name="status"><option value="draft">Draft</option><option value="published">Publish</option><option value="archived">Archive</option></select></label>
            <button type="submit">Save article</button>${editingId ? '<button type="button" data-cancel-edit>Cancel edit</button>' : ''}
          </form>
        </section>
        <section><h2>Articles</h2>${articles.map((item) => `
          <article><h3>${escapeHtml(item.title)}</h3><p>Status: ${escapeHtml(item.status)}</p>
            <button type="button" data-edit-article="${escapeHtml(item.id)}">Edit</button>
            ${item.status !== 'published' ? `<button type="button" data-article-status="published" data-id="${escapeHtml(item.id)}">Publish</button>` : '<button type="button" data-article-status="archived" data-id="' + escapeHtml(item.id) + '">Archive</button>'}
          </article>`).join('')}</section>
        <section><h2>Forum moderation</h2>${threads.map((thread) => `
          <article><h3>${escapeHtml(thread.title)}</h3><p>${escapeHtml(thread.body)}</p><p>Status: ${escapeHtml(thread.status)}</p>
            ${thread.status !== 'published' ? `<button type="button" data-thread-status="published" data-id="${escapeHtml(thread.id)}">Restore</button>` : `<button type="button" data-thread-status="hidden" data-id="${escapeHtml(thread.id)}">Hide</button>`}
            ${thread.status !== 'removed' ? `<button type="button" data-thread-status="removed" data-id="${escapeHtml(thread.id)}">Remove</button>` : ''}
          </article>`).join('')}
          <h3>Replies</h3>${posts.map((post) => `
            <article><p>In “${escapeHtml(post.thread?.title || 'discussion')}”, ${escapeHtml(post.author?.name || 'member')} wrote: ${escapeHtml(post.body)}</p><p>Status: ${escapeHtml(post.status)}</p>
              ${post.status !== 'published' ? `<button type="button" data-post-status="published" data-thread="${escapeHtml(post.thread_id)}" data-id="${escapeHtml(post.id)}">Restore reply</button>` : `<button type="button" data-post-status="hidden" data-thread="${escapeHtml(post.thread_id)}" data-id="${escapeHtml(post.id)}">Hide reply</button>`}
              ${post.status !== 'removed' ? `<button type="button" data-post-status="removed" data-thread="${escapeHtml(post.thread_id)}" data-id="${escapeHtml(post.id)}">Remove reply</button>` : ''}
            </article>`).join('')}
        </section>`;
      const articleForm = target.querySelector('#article-form');
      if (editingId) {
        const article = articles.find((item) => item.id === editingId);
        if (article) {
          for (const [key, value] of Object.entries(article)) {
            const field = articleForm.elements.namedItem(key);
            if (field && typeof value === 'string') field.value = value;
          }
        }
      }
      articleForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const values = new FormData(articleForm);
        const payload = Object.fromEntries(values.entries());
        try {
          await api(editingId ? `/admin/community/articles/${encodeURIComponent(editingId)}` : '/admin/community/articles', {
            method: editingId ? 'PATCH' : 'POST',
            body: payload,
          });
          editingId = null;
          notice('Article saved.');
          await loadTools();
        } catch (error) {
          notice(error.message, true);
        }
      });
      target.querySelector('[data-cancel-edit]')?.addEventListener('click', () => { editingId = null; renderTools(); });
      target.querySelectorAll('[data-edit-article]').forEach((button) => {
        button.addEventListener('click', () => { editingId = button.dataset.editArticle; renderTools(); });
      });
      target.querySelectorAll('[data-article-status]').forEach((button) => {
        button.addEventListener('click', () => updateArticle(button.dataset.id, button.dataset.articleStatus));
      });
      target.querySelectorAll('[data-thread-status]').forEach((button) => {
        button.addEventListener('click', () => moderateThread(button.dataset.id, button.dataset.threadStatus));
      });
      target.querySelectorAll('[data-post-status]').forEach((button) => {
        button.addEventListener('click', () => moderatePost(button.dataset.thread, button.dataset.id, button.dataset.postStatus));
      });
    }

    async function updateArticle(id, status) {
      const item = articles.find((article) => article.id === id);
      try {
        await api(`/admin/community/articles/${encodeURIComponent(id)}`, {
          method: 'PATCH',
          body: { title: item.title, slug: item.slug, summary: item.summary, body: item.body, content_type: item.content_type, video_url: item.video_url || undefined, status },
        });
        await loadTools();
      } catch (error) {
        notice(error.message, true);
      }
    }
    async function moderateThread(id, status) {
      try {
        await api(`/admin/community/forum/threads/${encodeURIComponent(id)}`, { method: 'PATCH', body: { status } });
        await loadTools();
      } catch (error) {
        notice(error.message, true);
      }
    }
    async function moderatePost(threadId, id, status) {
      try {
        await api(`/admin/community/forum/threads/${encodeURIComponent(threadId)}/posts/${encodeURIComponent(id)}`, { method: 'PATCH', body: { status } });
        await loadTools();
      } catch (error) {
        notice(error.message, true);
      }
    }

    try {
      await loadTools();
    } catch (error) {
      target.innerHTML = `<p role="alert">${escapeHtml(error.message)}</p>`;
    }
  }

  async function initAds(user) {
    const target = document.querySelector('#ads-content');
    if (user?.role !== 'supplier') {
      target.innerHTML = '<p>Sign in as a supplier to manage campaigns.</p><a href="/account">Sign in</a>';
      return;
    }
    try {
      const [campaigns, pricing] = await Promise.all([api('/ads/mine'), api('/ads/pricing')]);
      let pendingCampaign = null;
      function render() {
        target.innerHTML = `
          <p>Only HTTPS destinations and image creatives up to 2 MB are accepted. Paid ads are reviewed before they appear.</p>
          <p>Campaign fee: ${pricing.enabled ? `${money(pricing.monthly_price_minor)} per ${escapeHtml(pricing.duration_days)} days.` : 'Checkout is not enabled yet.'}</p>
          ${pricing.enabled ? `<form id="campaign-form">
            <label>Campaign name<input name="name" required maxlength="120"></label>
            <label>Audience<select name="target_role"><option value="farmer">Farmers</option><option value="buyer">Buyers</option><option value="supplier">Suppliers</option></select></label>
            <label>HTTPS destination URL<input name="destination_url" type="url" required></label>
            <label>Creative (JPG, PNG or WebP)<input name="creative" type="file" accept="image/jpeg,image/png,image/webp" required></label>
            <button type="submit">Upload campaign</button>
          </form>` : ''}
          ${pendingCampaign ? `<form id="campaign-payment"><h2>Pay for ${escapeHtml(pendingCampaign.name)}</h2><label>M-Pesa phone number<input name="phone" type="tel" maxlength="32" required></label><button type="submit">Pay with M-Pesa</button></form>` : ''}
          <p data-ads-message role="status"></p><h2>Your campaigns</h2>
          ${campaigns.length ? `<ul>${campaigns.map((campaign) => `
            <li>${escapeHtml(campaign.name)}: ${escapeHtml(campaign.status)}; payment ${escapeHtml(campaign.payment_status || 'not started')}; ${Number(campaign.impressions_count)} impressions, ${Number(campaign.clicks_count)} clicks.
              ${campaign.status === 'draft' && !['initiated', 'pending'].includes(campaign.payment_status) ? `<button type="button" data-pay-campaign="${escapeHtml(campaign.id)}">Complete payment</button>` : ''}
            </li>`).join('')}</ul>` : '<p>You have no campaigns.</p>'}`;
        target.querySelector('#campaign-form')?.addEventListener('submit', async (event) => {
          event.preventDefault();
          const form = new FormData(event.currentTarget);
          try {
            pendingCampaign = await api('/ads', { method: 'POST', body: form });
            notice('Advertisement uploaded. Complete payment to submit it for review.');
            campaigns.unshift({ ...pendingCampaign, payment_status: null, impressions_count: 0, clicks_count: 0 });
            render();
          } catch (error) {
            notice(error.message, true);
          }
        });
        target.querySelectorAll('[data-pay-campaign]').forEach((button) => {
          button.addEventListener('click', () => {
            pendingCampaign = campaigns.find((campaign) => campaign.id === button.dataset.payCampaign);
            render();
          });
        });
        target.querySelector('#campaign-payment')?.addEventListener('submit', async (event) => {
          event.preventDefault();
          const values = new FormData(event.currentTarget);
          try {
            const response = await api(`/ads/${encodeURIComponent(pendingCampaign.id)}/purchase`, { method: 'POST', body: { phone: values.get('phone') } });
            const message = response.customer_message || 'Approve the M-Pesa prompt. The paid ad will be reviewed before publication.';
            notice(message);
            pendingCampaign = null;
            campaigns.splice(0, campaigns.length, ...asList(await api('/ads/mine')));
            render();
          } catch (error) {
            notice(error.message, true);
          }
        });
      }
      render();
    } catch (error) {
      showError(target, error, 'Could not load advertising campaign pricing or history.');
    }
  }

  loadUser().then((user) => {
    if (page === 'marketplace') initMarketplace();
    else if (page === 'account') initAccount();
    else if (page === 'feedback') initFeedback();
    else if (page === 'community') initCommunity();
    else if (page === 'dashboard') loadDashboard(user);
    else if (page === 'impact') initImpact(user);
    else if (page === 'community-admin') initCommunityAdmin(user);
    else if (page === 'ads') initAds(user);
  });
})();
