<?php
/** Regenerate products.html from includes/products-grid.html — run: php dev/build-products.php */
declare(strict_types=1);

$root = dirname(__DIR__);
$grid = file_get_contents($root . '/includes/products-grid.html');

$head = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Shop E-Moto Parts | OnlyBikes Canada</title>
<meta name="description" content="Ultra Bee brake kits, titanium bolts, Talaria supermoto wheels, Baja style lights, off-road tire sets. All sales final — verify fitment before you buy.">
<link rel="canonical" href="https://onlybikes.shop/products.html">
<link rel="icon" href="images/onlybikes-logo.svg" type="image/svg+xml">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { theme: { extend: { colors: { accent: '#22c55e' } } } }</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/onlybikes.css">
</head>
<body class="ob-mobile-safe pt-16">
HTML;

$nav = file_get_contents($root . '/includes/site-nav-snippet.html');
$nav = str_replace('class="relative bg-accent', 'class="relative ob-btn ob-btn-primary', $nav);
$nav = str_replace('hover:bg-accent/90', '', $nav);

$main = <<<'HTML'
<main class="max-w-7xl mx-auto px-4 py-10">
<header class="mb-8">
<p class="ob-badge">Parts catalog</p>
<h1 class="font-display mt-4 text-4xl md:text-6xl uppercase">Shop upgrades</h1>
<p class="mt-4 max-w-2xl text-zinc-400">Same lineup riders use to finish a build — performance foundations, style add-ons, and wear parts. Filter by bike or by what you are trying to do.</p>
<div class="ob-legal-banner mt-6"><strong>All sales final.</strong> No refunds, returns, or exchanges — including misfit, wrong fitment, or change of mind. You are 100% responsible for verifying fitment before purchase and for installation. OnlyBikes is not liable for any damage, injury, loss, defect claim, shipping delay, or any other issue. See <a href="terms.html">Terms</a> and <a href="returns.html">No-Return Policy</a>.</div>
</header>
<div class="ob-scroll-tabs flex gap-2 pb-3 mb-4">
<button data-filter="all" class="ob-btn ob-btn-ghost whitespace-nowrap">All</button>
<button data-filter="surron" class="ob-btn ob-btn-ghost whitespace-nowrap">Surron</button>
<button data-filter="talaria" class="ob-btn ob-btn-ghost whitespace-nowrap">Talaria</button>
<button data-filter="eride" class="ob-btn ob-btn-ghost whitespace-nowrap">E-Ride</button>
</div>
<div class="ob-scroll-tabs flex flex-wrap gap-2 pb-4 mb-8 border-b border-zinc-900">
<button data-filter="build" class="rounded-full border border-zinc-800 px-4 py-2 text-sm text-zinc-300">Build center</button>
<button data-filter="style" class="rounded-full border border-zinc-800 px-4 py-2 text-sm text-zinc-300">Style add-ons</button>
<button data-filter="maintenance" class="rounded-full border border-zinc-800 px-4 py-2 text-sm text-zinc-300">Maintenance</button>
<button data-filter="soon" class="rounded-full border border-zinc-800 px-4 py-2 text-sm text-zinc-300">Coming soon</button>
</div>
<div id="products-grid" class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
HTML;

$footer = <<<'HTML'
</div>
</main>
<div class="sticky-mobile-cta md:hidden">
<div class="product-info"><div class="product-name">Ready to upgrade?</div><div class="product-price">Open bag anytime</div></div>
<button id="mobile-sticky-cart" class="add-to-cart-sticky" type="button">View bag</button>
</div>
<footer class="border-t border-zinc-800 bg-zinc-950 py-10">
<div class="max-w-7xl mx-auto px-4 text-sm text-zinc-500 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
<p>&copy; 2026 OnlyBikes. Ships from Canada.</p>
<div class="flex flex-wrap gap-4">
<a class="hover:text-green-300" href="fitment.html">Fitment</a>
<a class="hover:text-green-300" href="privacy.html">Privacy</a>
<a class="hover:text-green-300" href="terms.html">Terms</a>
<a class="hover:text-green-300" href="returns.html">Returns</a>
</div>
</div>
</footer>
<script src="js/site-config.js"></script>
<script src="main-security.js"></script>
<script src="logger.js"></script>
<script src="main.js"></script>
<script src="js/onlybikes-ui.js" defer></script>
<script src="js/site-layout.js" defer></script>
<script src="js/conversion.js" defer></script>
<script src="js/stock-badges.js" defer></script>
</body>
</html>
HTML;

$html = $head . $nav . $main . $grid . $footer;
file_put_contents($root . '/products.html', $html);
echo "Wrote products.html (" . strlen($html) . " bytes)\n";
