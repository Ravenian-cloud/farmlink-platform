<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}"><title>FarmLink · {{ ucfirst(str_replace('-', ' ', $page)) }}</title>
  <link rel="stylesheet" href="/css/farmlink.css">
</head>
<body><div class="app-shell">
  <header class="site-header"><a class="brand" href="/marketplace"><span class="brand-mark">F</span><span>FarmLink<small>Markets that grow together</small></span></a>
    <nav aria-label="Main navigation">
      @foreach(['marketplace'=>'Marketplace','dashboard'=>'Dashboard','community'=>'Community','impact'=>'Impact','feedback'=>'Feedback','account'=>'Sign in'] as $route=>$label)
        <a class="{{ $page === $route ? 'active' : '' }}" href="/{{ $route }}">{{ $label }}</a>
      @endforeach
      <a id="admin-link" href="/community-admin" hidden>Community admin</a><a id="ads-link" href="/ads" hidden>Advertising</a>
    </nav>
  </header>
  <main id="app-main" data-page="{{ $page }}">
    @if($page === 'marketplace')
      <h1>FarmLink marketplace</h1><p>Find local produce and agricultural inputs, then connect directly with the people who grow and supply them.</p>
      <nav aria-label="Marketplace categories"><button data-category="produce" aria-pressed="true">Produce</button><button data-category="input" aria-pressed="false">Agricultural inputs</button></nav>
      <p id="notice" role="status"></p><section id="listing-grid" aria-label="Listings"><p>Loading listings…</p></section>
    @elseif($page === 'account')
      <h1>Your FarmLink account</h1><section id="account-state"><p>Checking your account…</p></section>
    @elseif($page === 'feedback')
      <h1>Pilot feedback</h1><p>Help us improve FarmLink. Do not include passwords, payment details, or sensitive personal information.</p>
      <p id="notice" role="status"></p><form id="feedback-form"><label>Feedback type<select name="category"><option>general</option><option>bug</option><option>negotiation</option><option>recommendation</option></select></label><label>Rating<select name="rating">@for($i=1;$i<=5;$i++)<option>{{$i}}</option>@endfor</select></label><label>Summary (optional)<input name="summary" maxlength="160"></label><label>Tell us more<textarea name="details" required minlength="10" maxlength="5000"></textarea></label><button>Send feedback</button></form>
    @elseif($page === 'community')
      <h1>Knowledge Hub & Community</h1><nav aria-label="Community sections"><button data-community="articles">Knowledge Hub</button><button data-community="forum">Community forum</button></nav><p id="notice" role="status"></p><div id="community-content">Loading community content…</div>
    @elseif($page === 'dashboard')
      <h1>Your dashboard</h1><p>Manage your listings, offers, and orders in one place.</p><p id="notice" role="status"></p><div id="dashboard-content">Sign in to view your account activity.</div>
    @elseif($page === 'impact')
      <h1>Farmer impact</h1><p>Track the outcomes and impact of FarmLink participation.</p><p id="notice" role="status"></p><div id="impact-content">Sign in to view impact reports.</div>
    @elseif($page === 'community-admin')
      <h1>Community administration</h1><p id="notice" role="status"></p><div id="admin-content">Checking administrator access…</div>
    @else
      <h1>Advertising</h1><p>Manage sponsored campaigns for your agricultural business.</p><p id="notice" role="status"></p><div id="ads-content">Sign in as a supplier to manage campaigns.</div>
    @endif
  </main>
  <footer class="site-footer"><span>FarmLink</span><span>Connecting growers, buyers, and suppliers.</span></footer>
</div><script src="/js/farmlink.js" defer></script></body></html>
