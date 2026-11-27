# Builds a review copy. Does not modify D:\Bedda\Ionos Bedda Website.
from pathlib import Path
import json
import shutil

LIVE = Path(r"D:\Bedda\Ionos Bedda Website")
OUT = Path(r"D:\Bedda\bedda-pr-for-gemini\site")

REL = [
    "products.html",
    "bundles.html",
    "main.js",
    "index.html",
    "account.html",
    ".htaccess",
    "api/lib/store-catalog.php",
    "api/lib/order-totals.php",
    "api/submit-order.php",
    "api/create-payment-intent.php",
    "api/data/store-stock.json",
    "api/get-shipping-quote.php",
    "api/shipping.php",
    "api/ai-engine.php",
    "api/website-kb.json",
    "analytics-backend.html",
]

IMAGES = {
    "holiday-ginger.jpg": Path(r"C:\Users\ViperonpcYT\.cursor\projects\empty-window\assets\21437-4d48e7a7-6c14-40ba-a76d-563125cc2508.jpg"),
    "holiday-basket.jpg": Path(r"C:\Users\ViperonpcYT\.cursor\projects\empty-window\assets\21365-3522d7c6-39b6-41ea-8d66-a32aefca99d0.jpg"),
    "holiday-gift-bag.jpg": Path(r"C:\Users\ViperonpcYT\.cursor\projects\d-Bedda-Ionos-Bedda-Website\assets\image-f2359e36-bfc8-4e92-9fbc-c16288738663.jpg"),
}

GINGER = r'''                <!-- Holiday Ginger Soap -->
                <div class="product-card card-hover bg-white rounded-lg p-6 border border-stone-200" data-category="soap holiday" data-product-id="holiday-ginger" data-price="9.99" data-sku="BEDDA-HOLIDAY-GINGER">
                    <div class="product-media" data-view="photo">
                        <div class="product-media-stage">
                            <img src="images/holiday-ginger.jpg" alt="Holiday Ginger soap with a festive red bow" class="product-2d-view" loading="lazy" decoding="async">
                            <div class="absolute top-2 left-2 bg-stone-800 text-white px-2 py-1 rounded text-xs font-semibold">Holiday</div>
                        </div>
                    </div>
                    <div class="product-card-body">
                    <h3 class="font-playfair text-xl font-semibold text-stone-800 mb-2">Holiday Ginger Soap</h3>
                    <div class="mb-4">
                        <p class="benefit-text mb-2">
                            <span class="benefit-highlight">Ginger with a warm holiday spice</span>, poured as a 120g bar and tied with a festive red bow. Made to give, or to keep.
                        </p>
                        <p class="ingredients-text mb-2 hidden">The full ingredient list is printed on the label.</p>
                        <button class="toggle-ingredients-btn" onclick="toggleIngredients(this)">View Ingredients →</button>
                    </div>
                    </div>
                    <div class="product-card-footer">
                    <div class="price-comparison">
                        <span class="current-price">$9.99</span>
                    </div>
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-sm text-stone-500 italic">Holiday 120g bar</span>
                        <span class="text-sm text-stone-600 font-medium">120g bar</span>
                    </div>
                    <div class="mb-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs text-stone-500">Quantity</span>
                        </div>
                        <div class="flex items-center border border-stone-300 rounded-lg overflow-hidden">
                            <button class="qty-minus px-3 py-2 bg-stone-100 hover:bg-stone-200 text-stone-700 font-medium transition-colors">−</button>
                            <input type="number" class="qty-input w-12 text-center border-0 bg-white text-stone-800 font-medium focus:outline-none" value="1" min="1" max="10">
                            <button class="qty-plus px-3 py-2 bg-stone-100 hover:bg-stone-200 text-stone-700 font-medium transition-colors">+</button>
                        </div>
                    </div>
                    <button class="add-to-cart w-full bg-sage-600 text-white py-3 rounded-lg hover:bg-sage-700 transition-colors font-semibold shadow-md hover:shadow-lg" data-product="Holiday Ginger Soap" data-price="9.99">
                        Add to Bag
                    </button>
                    </div>
                    <div class="product-card-upsell is-empty" aria-hidden="true"></div>
                </div>

'''

SETS = r'''<!-- Holiday Basket -->
<section class="py-16 bg-white border-t border-stone-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bundle-card rounded-2xl p-8 md:p-12 border-2 border-sage-300 relative overflow-hidden">
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <div class="relative">
                    <img src="images/holiday-basket.jpg" alt="Bedda holiday gift basket with a red bow" class="w-full rounded-xl shadow-2xl">
                </div>
                <div class="space-y-6">
                    <div class="inline-flex items-center px-3 py-1 rounded-full bg-sage-200 text-sage-800 text-sm font-semibold">Holiday gift</div>
                    <h2 class="font-playfair text-4xl font-bold text-stone-800">Holiday Basket</h2>
                    <p class="text-lg text-stone-600">A finished basket for the holidays. Spring Clean linen spray is only in this set.</p>
                    <ul class="space-y-3">
                        <li class="flex items-start"><span class="text-sage-600 mr-3">1</span><div><span class="font-semibold text-stone-800">Uni Exfoliating Soap (100g)</span></div></li>
                        <li class="flex items-start"><span class="text-sage-600 mr-3">1</span><div><span class="font-semibold text-stone-800">Massager Soap (120g)</span></div></li>
                        <li class="flex items-start"><span class="text-sage-600 mr-3">1</span><div><span class="font-semibold text-stone-800">Holiday Ginger (120g)</span><p class="text-sm text-stone-600">Red bow</p></div></li>
                        <li class="flex items-start"><span class="text-sage-600 mr-3">1</span><div><span class="font-semibold text-stone-800">Holy Grail Whipped Face &amp; Body Balm</span><p class="text-sm text-stone-600">Amber jar, 1 oz</p></div></li>
                        <li class="flex items-start"><span class="text-sage-600 mr-3">1</span><div><span class="font-semibold text-stone-800">Minty Lip Balm</span><p class="text-sm text-stone-600">Amber jar, 0.5 oz</p></div></li>
                        <li class="flex items-start"><span class="text-sage-600 mr-3">1</span><div><span class="font-semibold text-stone-800">Spring Clean Linen Spray</span><p class="text-sm text-stone-600">Amber mist, 30 ml. Exclusive to this set.</p></div></li>
                        <li class="flex items-start"><span class="text-sage-600 mr-3">1</span><div><span class="font-semibold text-stone-800">Cotton facecloth, microfibre headband, soap saver bag, and gua sha</span></div></li>
                    </ul>
                    <div class="flex items-end gap-4 pt-4 border-t border-sage-300">
                        <div><div class="text-4xl font-bold text-stone-800">$89.99</div><div class="text-sm text-stone-600">One set price</div></div>
                        <button type="button" class="flex-1 bg-sage-700 text-white py-4 rounded-lg font-bold text-lg hover:bg-sage-800 transition-colors shadow-lg add-to-cart-bundle" data-sell-as="set" data-bundle="holiday-basket" data-product="Holiday Basket" data-price="89.99">Add Holiday Basket, $89.99</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Holiday Gift Bag -->
<section class="py-16 bg-stone-50 border-t border-stone-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bundle-card rounded-2xl p-8 md:p-12 border-2 border-stone-200 relative overflow-hidden">
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <div class="relative">
                    <img src="images/holiday-gift-bag.jpg" alt="Bedda holiday gift bag with a red bow, massager soap, creamsicle balm, minty lip balm, and She-Ra soap" class="w-full rounded-xl shadow-2xl">
                </div>
                <div class="space-y-6">
                    <div class="inline-flex items-center px-3 py-1 rounded-full bg-stone-200 text-stone-800 text-sm font-semibold">Holiday gift</div>
                    <h2 class="font-playfair text-4xl font-bold text-stone-800">Holiday Gift Bag</h2>
                    <p class="text-lg text-stone-600">A smaller festive bag. The price is a little under buying the four pieces on their own.</p>
                    <ul class="space-y-3">
                        <li class="flex items-start"><span class="text-sage-600 mr-3">1</span><div><span class="font-semibold text-stone-800">Massager Soap (120g)</span></div></li>
                        <li class="flex items-start"><span class="text-sage-600 mr-3">1</span><div><span class="font-semibold text-stone-800">She-Ra Exfoliating Soap (100g)</span></div></li>
                        <li class="flex items-start"><span class="text-sage-600 mr-3">1</span><div><span class="font-semibold text-stone-800">Creamsicle Face &amp; Body Balm (1 oz)</span></div></li>
                        <li class="flex items-start"><span class="text-sage-600 mr-3">1</span><div><span class="font-semibold text-stone-800">Minty Lip Balm</span><p class="text-sm text-stone-600">0.2 oz metal tin</p></div></li>
                    </ul>
                    <div class="flex items-end gap-4 pt-4 border-t border-stone-200">
                        <div>
                            <div class="text-sm text-stone-400 line-through">$26.84</div>
                            <div class="text-4xl font-bold text-stone-800">$24.99</div>
                            <div class="text-sm text-stone-600">Bag included</div>
                        </div>
                        <button type="button" class="flex-1 bg-stone-800 text-white py-4 rounded-lg font-bold text-lg hover:bg-stone-900 transition-colors shadow-lg add-to-cart-bundle" data-sell-as="set" data-bundle="holiday-gift-bag" data-product="Holiday Gift Bag" data-price="24.99">Add Holiday Gift Bag, $24.99</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

'''

SALE_PRICE = '''                    <div class="price-comparison">
                        <span class="original-price">$15.50</span>
                        <span class="current-price">$9.99</span>
                    </div>
                    <p class="text-xs font-semibold text-stone-700 mb-3">Black Friday sale</p>'''

SETS_PHP = r'''
function bedda_holiday_sets(): array
{
    return [
        'holiday basket' => [
            'sku' => 'BEDDA-SET-HOLIDAY-BASKET',
            'name' => 'Holiday Basket',
            'price' => 89.99,
            'components' => [
                'Uni Exfoliating Soap',
                'The Massager Soap',
                'Holiday Ginger Soap',
                'Holy Grail Balm',
                'Minty Lip Balm',
            ],
        ],
        'holiday gift bag' => [
            'sku' => 'BEDDA-SET-HOLIDAY-BAG',
            'name' => 'Holiday Gift Bag',
            'price' => 24.99,
            'components' => [
                'The Massager Soap',
                'She-Ra Exfoliating Soap',
                'Creamsicle Balm',
                'Minty Lip Balm',
            ],
        ],
    ];
}

function bedda_store_set_by_name(string $name): ?array
{
    $key = bedda_store_normalize_name($name);
    $sets = bedda_holiday_sets();
    return $sets[$key] ?? null;
}

'''

COUPON_FN = r'''
function bedda_lookup_coupon_discount(array $data, float $subtotal): float
{
    if ($subtotal <= 0 || empty($data['coupon']) || !is_array($data['coupon'])) {
        return 0.0;
    }
    $code = strtoupper(trim((string) ($data['coupon']['code'] ?? '')));
    if ($code === '' || !function_exists('getCouponDatabase')) {
        return 0.0;
    }
    try {
        $pdo = getCouponDatabase();
        $stmt = $pdo->prepare('SELECT type, value, min_total, expires_at, usage_limit, used_count FROM coupon_codes WHERE code = ? AND active = TRUE AND deleted = FALSE LIMIT 1');
        $stmt->execute([$code]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$coupon) {
            return 0.0;
        }
        if (!empty($coupon['expires_at']) && new DateTime((string) $coupon['expires_at']) < new DateTime()) {
            return 0.0;
        }
        if ($subtotal < (float) $coupon['min_total']) {
            return 0.0;
        }
        if (!empty($coupon['usage_limit']) && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
            return 0.0;
        }
        if (($coupon['type'] ?? '') === 'percent') {
            return round($subtotal * ((float) $coupon['value'] / 100), 2);
        }
        return round(min((float) $coupon['value'], $subtotal), 2);
    } catch (Throwable $e) {
        error_log('[BEDDA] Coupon lookup failed: ' . $e->getMessage());
        return 0.0;
    }
}

'''


def read(rel: str) -> str:
    return (OUT / rel).read_text(encoding="utf-8")


def write(rel: str, text: str) -> None:
    (OUT / rel).write_text(text, encoding="utf-8", newline="\n")


def must_replace(rel: str, old: str, new: str, count: int = 1) -> None:
    text = read(rel)
    found = text.count(old)
    if found < count:
        raise SystemExit(f"{rel}: expected at least {count} of marker, found {found}: {old[:80]!r}")
    write(rel, text.replace(old, new, count))


def main() -> None:
    if OUT.exists():
        shutil.rmtree(OUT)
    for rel in REL:
        dest = OUT / rel
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(LIVE / rel, dest)
    img_dir = OUT / "images"
    img_dir.mkdir(parents=True, exist_ok=True)
    for name, src in IMAGES.items():
        if not src.is_file():
            raise SystemExit(f"missing photo {src}")
        shutil.copy2(src, img_dir / name)

    products = read("products.html")
    start = "                <!-- Special Occasion Soap — Hidden Heart -->"
    end = "                <!-- Holy Grail - Face and Body Balm -->"
    a = products.find(start)
    b = products.find(end)
    if a < 0 or b < 0 or b <= a:
        raise SystemExit("special occasion block not found")
    products = products[:a] + GINGER + products[b:]
    products = products.replace(
        'data-product-id="holy-grail" data-price="15.50"',
        'data-product-id="holy-grail" data-price="9.99"',
        1,
    )
    products = products.replace(
        'data-product-id="creamsicle" data-price="15.50"',
        'data-product-id="creamsicle" data-price="9.99"',
        1,
    )
    products = products.replace(
        'data-product="Holy Grail Balm" data-price="15.50"',
        'data-product="Holy Grail Balm" data-price="9.99"',
    )
    products = products.replace(
        'data-product="Creamsicle Balm" data-price="15.50"',
        'data-product="Creamsicle Balm" data-price="9.99"',
    )
    old_price = '''                    <div class="price-comparison">
                        <span class="current-price">$15.50</span>
                    </div>'''
    # First two $15.50 prices are Holy Grail and Creamsicle. Plain Jane stays.
    products = products.replace(old_price, SALE_PRICE, 2)
    products = products.replace(
        ">Popular</div>",
        ">Black Friday</div>",
        1,
    )
    products = products.replace(
        ">New</div>\n                            <button type=\"button\" class=\"product-view-toggle\" aria-label=\"View in 3D\" aria-pressed=\"false\" title=\"View in 3D\">",
        ">Black Friday</div>\n                            <button type=\"button\" class=\"product-view-toggle\" aria-label=\"View in 3D\" aria-pressed=\"false\" title=\"View in 3D\">",
        1,
    )
    # Cross-sell labels that still show the old number next to these two names.
    products = products.replace(
        """<div class="text-xs text-stone-600">Holy Grail Balm</div>
                                <div class="text-sm font-semibold text-stone-800">$15.50</div>""",
        """<div class="text-xs text-stone-600">Holy Grail Balm</div>
                                <div class="text-sm font-semibold text-stone-800"><span class="line-through text-stone-400">$15.50</span> $9.99</div>""",
    )
    products = products.replace(
        """<div class="text-xs text-stone-600">Creamsicle Balm</div>
                                <div class="text-sm font-semibold text-stone-800">$15.50</div>""",
        """<div class="text-xs text-stone-600">Creamsicle</div>
                                <div class="text-sm font-semibold text-stone-800"><span class="line-through text-stone-400">$15.50</span> $9.99</div>""",
    )
    if "Special Occasion" in products:
        raise SystemExit("Special Occasion still in products.html")
    if products.count("current-price\">$15.50") != 1:
        raise SystemExit("Plain Jane $15.50 count changed")
    write("products.html", products)

    bundles = read("bundles.html")
    bstart = "<!-- Bundle 4: Mother's Day Gift Package - Mama 1 - UPDATED PRICING -->"
    bend = '<section class="py-20 bg-stone-50 border-t border-stone-200">'
    a = bundles.find(bstart)
    b = bundles.find(bend, a)
    if a < 0 or b < 0:
        raise SystemExit("bundle sections not found")
    bundles = bundles[:a] + SETS + bundles[b:]
    bundles = bundles.replace(
        "The Little Heart Surprise and the Lavender Heart set are pickup only, the same as their buttons. Each includes the gift bag on the card. There is no personalized note, and these two sets are not shipped.",
        "The Holiday Basket and the Holiday Gift Bag ship like the rest of the shop. Spring Clean linen spray is only in the basket.",
    )
    if "Little Heart" in bundles or "Lavender Heart" in bundles:
        raise SystemExit("old gift sets still in bundles.html")
    write("bundles.html", bundles)

    # Server catalog reads api/*.html first. Keep those copies identical to the shop pages.
    (OUT / "api").mkdir(exist_ok=True)
    shutil.copy2(OUT / "products.html", OUT / "api" / "products.html")
    shutil.copy2(OUT / "bundles.html", OUT / "api" / "bundles.html")

    must_replace(
        "main.js",
        """            else if (button.classList.contains('add-to-cart-bundle')) {
                const bundle = button.dataset.bundle;
                const price = button.dataset.price;""",
        """            else if (button.classList.contains('add-to-cart-bundle')) {
                if (button.dataset.sellAs === 'set') {
                    const setName = button.dataset.product;
                    const setPrice = button.dataset.price;
                    if (!setName || !setPrice) return;
                    cartManager.addItem(setName, setPrice, 1, {
                        bundleName: setName,
                        bundleKey: button.dataset.bundle || setName
                    });
                    showButtonFeedback(button, '✓ Added to Cart!');
                    return;
                }
                const bundle = button.dataset.bundle;
                const price = button.dataset.price;""",
    )
    must_replace(
        "main.js",
        """    'Special Occasion Soap — Hidden Heart': 120,
    'Special Occasion Soap — Lavender Heart': 120,""",
        """    'Holiday Ginger Soap': 120,
    'Holiday Basket': 1100,
    'Holiday Gift Bag': 320,""",
    )
    must_replace(
        "main.js",
        "Object.freeze({ id: 'holygrail', name: 'Holy Grail Balm', points: 310 }),",
        "Object.freeze({ id: 'holygrail', name: 'Holy Grail Balm', points: 200 }),",
    )
    must_replace(
        "main.js",
        "Object.freeze({ id: 'creamsicle', name: 'Creamsicle Balm', points: 310 }),",
        "Object.freeze({ id: 'creamsicle', name: 'Creamsicle Balm', points: 200 }),",
    )
    must_replace(
        "index.html",
        "<span class=\"font-semibold\">310 points</span>, Holy Grail Balm",
        "<span class=\"font-semibold\">200 points</span>, Holy Grail Balm",
    )
    must_replace(
        "index.html",
        "<span class=\"font-semibold\">310 points</span>, Creamsicle Balm",
        "<span class=\"font-semibold\">200 points</span>, Creamsicle Balm",
    )
    must_replace(
        "account.html",
        'data-reward-id="holygrail" data-points="310"',
        'data-reward-id="holygrail" data-points="200"',
    )
    must_replace(
        "account.html",
        "<span class=\"text-2xl font-bold\">310</span> points for <span class=\"font-bold\">Holy Grail Balm</span>.",
        "<span class=\"text-2xl font-bold\">200</span> points for <span class=\"font-bold\">Holy Grail Balm</span>.",
    )
    must_replace(
        "account.html",
        "Not yet. Needs 310 more points.</p>\n                        </li>\n                        <li class=\"reward-card bg-white rounded-xl p-4 text-left border border-stone-200\" data-reward-id=\"creamsicle\"",
        "Not yet. Needs 200 more points.</p>\n                        </li>\n                        <li class=\"reward-card bg-white rounded-xl p-4 text-left border border-stone-200\" data-reward-id=\"creamsicle\"",
    )
    must_replace(
        "account.html",
        'data-reward-id="creamsicle" data-points="310"',
        'data-reward-id="creamsicle" data-points="200"',
    )
    must_replace(
        "account.html",
        "<span class=\"text-2xl font-bold\">310</span> points for <span class=\"font-bold\">Creamsicle Balm</span>.",
        "<span class=\"text-2xl font-bold\">200</span> points for <span class=\"font-bold\">Creamsicle Balm</span>.",
    )

    quote = read("api/get-shipping-quote.php")
    quote = quote.replace(
        "'Mama 2 - My heart Soap' => 120, 'Holy Grail Balm' => 37,",
        "'Holiday Ginger Soap' => 120, 'Holiday Basket' => 1100, 'Holiday Gift Bag' => 320, 'She-Ra Exfoliating Soap' => 120, 'Holy Grail Balm' => 37,",
    )
    write("api/get-shipping-quote.php", quote)
    must_replace(
        "api/shipping.php",
        "        'Special Occasion Soap' => 130,\n",
        "        'Holiday Ginger Soap' => 120,\n        'Holiday Basket' => 1100,\n        'Holiday Gift Bag' => 320,\n",
    )
    must_replace(
        "api/ai-engine.php",
        "Special Occasion ($8.99, hidden heart, lemon/lavender/Rubia Cordifolia), ",
        "Holiday Ginger soap ($9.99, 120g, red bow), Holiday Basket ($89.99), Holiday Gift Bag ($24.99). Holy Grail and Creamsicle whipped balms are $9.99 for Black Friday, regular price $15.50. Do not offer Special Occasion soaps, Little Heart Surprise, or the Lavender Heart set. ",
    )
    kb = read("api/website-kb.json")
    kb = kb.replace(
        "Special Occasion Soap. $8.99 for 100g bar. SKU: BEDDA-SPECIAL. Features a hidden heart design inside the bar. Scented with lemon and lavender essential oils. Contains Rubia Cordifolia (Manjistha) for its skin-brightening properties. Perfect as a gift.",
        "Holiday Ginger soap. $9.99 for a 120g bar with a red bow. Holiday Basket is $89.99 and includes Spring Clean linen spray, which is not sold on its own. Holiday Gift Bag is $24.99. Holy Grail and Creamsicle balms are $9.99 (regular $15.50) for Black Friday. Special Occasion soaps and the Little Heart and Lavender Heart sets are no longer sold.",
    )
    write("api/website-kb.json", kb)

    stock_path = OUT / "api" / "data" / "store-stock.json"
    stock = json.loads(stock_path.read_text(encoding="utf-8"))
    stock.pop("BEDDA-SPEC-HRT", None)
    stock.pop("BEDDA-SPEC-LAV", None)
    stock["BEDDA-HOLYGRAIL-BALM"]["price"] = 9.99
    stock["BEDDA-CREAMSICLE-BALM"]["price"] = 9.99
    stock["BEDDA-HOLIDAY-GINGER"] = {
        "name": "Holiday Ginger Soap",
        "price": 9.99,
        "stock_in_stock": 99,
        "low_stock_threshold": 10,
        "stock_sold": 0,
        "cost": 0,
    }
    stock["BEDDA-SET-HOLIDAY-BASKET"] = {
        "name": "Holiday Basket",
        "price": 89.99,
        "stock_in_stock": 99,
        "low_stock_threshold": 5,
        "stock_sold": 0,
        "cost": 56.56,
    }
    stock["BEDDA-SET-HOLIDAY-BAG"] = {
        "name": "Holiday Gift Bag",
        "price": 24.99,
        "stock_in_stock": 99,
        "low_stock_threshold": 5,
        "stock_sold": 0,
        "cost": 14.59,
    }
    stock_path.write_text(json.dumps(stock, indent=4) + "\n", encoding="utf-8")

    catalog = read("api/lib/store-catalog.php")
    if "function bedda_holiday_sets" not in catalog:
        catalog = catalog.replace(
            "function bedda_store_bundle_only_sku(string $name): string",
            SETS_PHP + "function bedda_store_bundle_only_sku(string $name): string",
            1,
        )
    needle = "    usort($merged, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));"
    insert = '''    foreach (bedda_holiday_sets() as $set) {
        if (isset($bySku[$set['sku']])) {
            continue;
        }
        $entry = [
            'sku' => $set['sku'],
            'name' => $set['name'],
            'cart_names' => [$set['name']],
            'product_id' => '',
            'category' => 'set',
            'price' => $set['price'],
            'allowed_prices' => [round((float) $set['price'], 2)],
            'cost' => 0.0,
            'stock_in_stock' => 99,
            'low_stock_threshold' => 5,
            'stock_sold' => 0,
            'revenue' => 0.0,
            'profit' => 0.0,
            'is_low_stock' => 0,
            'is_out_of_stock' => 0,
        ];
        $merged[] = $entry;
        $bySku[$set['sku']] = $entry;
    }

''' + needle
    if needle not in catalog:
        raise SystemExit("catalog usort missing")
    catalog = catalog.replace(needle, insert, 1)
    old_demand = '''        if (!isset($demand[$sku])) {
            $demand[$sku] = ['qty' => 0, 'label' => $label];
        }
        $demand[$sku]['qty'] += $qty;
'''
    new_demand = old_demand + '''
        $set = bedda_store_set_by_name($label);
        if ($set !== null) {
            foreach ($set['components'] as $componentName) {
                $component = bedda_store_lookup_catalog_item(['product' => $componentName]);
                if ($component === null || empty($component['sku'])) {
                    $errors[] = $label . ' includes ' . $componentName . ', which is not in the catalog';
                    continue;
                }
                $componentSku = (string) $component['sku'];
                if (!isset($demand[$componentSku])) {
                    $demand[$componentSku] = ['qty' => 0, 'label' => $componentName];
                }
                $demand[$componentSku]['qty'] += $qty;
            }
        }
'''
    # $errors is created later. Add component demand in the existing error loop's source
    # by expanding inside deduct and inside validate after $errors = [].
    if old_demand not in catalog:
        raise SystemExit("demand block missing")
    catalog = catalog.replace(old_demand, new_demand, 1)
    # The component loop above references $errors before it exists. Move that check
    # to the later $errors array instead: rewrite to stash names.
    catalog = catalog.replace(
        '''                if ($component === null || empty($component['sku'])) {
                    $errors[] = $label . ' includes ' . $componentName . ', which is not in the catalog';
                    continue;
                }''',
        '''                if ($component === null || empty($component['sku'])) {
                    $demand['__missing__' . $componentName] = ['qty' => $qty, 'label' => $label . ' includes ' . $componentName . ', which is not in the catalog', 'missing' => true];
                    continue;
                }''',
        1,
    )
    catalog = catalog.replace(
        '''    $errors = [];
    foreach ($demand as $sku => $info) {
        $available = bedda_store_sku_stock_available($sku);
''',
        '''    $errors = [];
    foreach ($demand as $sku => $info) {
        if (!empty($info['missing'])) {
            $errors[] = $info['label'];
            continue;
        }
        $available = bedda_store_sku_stock_available($sku);
''',
        1,
    )
    old_deduct = '''        $qtyBySku[$sku] = ($qtyBySku[$sku] ?? 0) + $qty;
    }
'''
    new_deduct = '''        $qtyBySku[$sku] = ($qtyBySku[$sku] ?? 0) + $qty;
        $set = bedda_store_set_by_name((string) ($item['product'] ?? $item['name'] ?? $catalog['name'] ?? ''));
        if ($set !== null) {
            foreach ($set['components'] as $componentName) {
                $component = bedda_store_lookup_catalog_item(['product' => $componentName]);
                $componentSku = trim((string) ($component['sku'] ?? ''));
                if ($componentSku === '') {
                    $errors[] = 'No SKU for set component ' . $componentName;
                    continue;
                }
                $qtyBySku[$componentSku] = ($qtyBySku[$componentSku] ?? 0) + $qty;
            }
        }
    }
'''
    if old_deduct not in catalog:
        raise SystemExit("deduct block missing")
    catalog = catalog.replace(old_deduct, new_deduct, 1)
    write("api/lib/store-catalog.php", catalog)

    totals = read("api/lib/order-totals.php")
    if "function bedda_lookup_coupon_discount" not in totals:
        totals = totals.replace(
            "function bedda_calculate_order_grand_total(array $data, float $subtotal, ?int $availablePoints = null): array",
            COUPON_FN + "function bedda_calculate_order_grand_total(array $data, float $subtotal, ?int $availablePoints = null): array",
            1,
        )
    totals = totals.replace(
        '''    $itemDiscount = 0.0;

    if (!empty($data['coupon']) && isset($data['coupon']['discount'])) {
        $itemDiscount = min(floatval($data['coupon']['discount']), $subtotal);
    }
''',
        '''    // Ignore the discount number sent by the browser. Look the code up.
    $itemDiscount = bedda_lookup_coupon_discount($data, $subtotal);
''',
        1,
    )
    write("api/lib/order-totals.php", totals)

    must_replace(
        "api/submit-order.php",
        '''if (!empty($data['coupon']) && isset($data['coupon']['discount'])) {
    $discountAmount = floatval($data['coupon']['discount']);
    $discountAmount = min($discountAmount, $originalSubtotal);
''',
        '''if (!empty($data['coupon']['code'])) {
    $discountAmount = bedda_lookup_coupon_discount($data, $originalSubtotal);
''',
    )
    must_replace(
        "api/submit-order.php",
        "if ($verifiedPaymentStatus !== 'succeeded' && $verifiedPaymentStatus !== 'requires_action') {",
        "if ($verifiedPaymentStatus !== 'succeeded') {",
    )
    must_replace(
        "api/submit-order.php",
        '''if ($verifiedAmount > 0 && abs($verifiedAmount - $expectedCents) > 1) { // 1 cent tolerance
    if ($verifiedPaymentStatus === 'succeeded') {
        error_log("[BEDDA] Amount mismatch after successful payment for order {$orderNumber}. Server calc: {$expectedCents}c, PI: {$verifiedAmount}c. Saving order with PI amount.");
        $finalGrandTotal = round($verifiedAmount / 100, 2);
        $orderData['grand_total'] = $finalGrandTotal;
    } else {
        error_log("[BEDDA] REJECTED: Amount mismatch. Expected {$expectedCents}c, got {$verifiedAmount}c");
        orderErrorResponse('Payment amount does not match order total. Please refresh and try again.', 400);
    }
}
''',
        '''if ($verifiedAmount > 0 && abs($verifiedAmount - $expectedCents) > 1) {
    error_log("[BEDDA] REJECTED: Amount mismatch. Expected {$expectedCents}c, got {$verifiedAmount}c");
    orderErrorResponse('Payment amount does not match order total. Please refresh and try again.', 400);
}
''',
    )
    pay = read("api/create-payment-intent.php")
    pay = pay.replace(
        " * Test mode only. Server-side amount verification prevents tampering.\n",
        " * Charges the server total. The browser total is not the amount Stripe collects.\n",
    )
    pay = pay.replace(
        "$amountInCents = intval(round($grandTotal * 100));\n",
        '''if (($data['fulfillment_method'] ?? 'shipping') === 'shipping' && $shippingCost <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'A shipping quote is required before payment.']);
    exit;
}

$amountInCents = intval(round($grandTotal * 100));
''',
        1,
    )
    write("api/create-payment-intent.php", pay)

    ht = read(".htaccess")
    rule = '''    RewriteBase /

    # Shop analytics pages are not public. The HTML stays on disk; this opens the login gate.
    RewriteRule ^analytics(?:-backend)?\\.html$ /analytics-backend.php [R=302,L]
'''
    if "analytics-backend.php" not in ht:
        ht = ht.replace("    RewriteBase /\n", rule, 1)
        write(".htaccess", ht)
    (OUT / "analytics-backend.php").write_text(
        """<?php
declare(strict_types=1);

require_once __DIR__ . '/email-admin/config.php';
session_name(EMAIL_ADMIN_SESSION);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$loggedIn = !empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
if (!$loggedIn) {
    header('Location: /email-admin/index.php');
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
readfile(__DIR__ . '/analytics-backend.html');
""",
        encoding="utf-8",
        newline="\n",
    )
    dash = read("analytics-backend.html")
    dash = dash.replace(
        "const summaryResponse = await fetch(`${this.summaryEndpoint}?period=${this.currentPeriod}`);",
        "const summaryResponse = await fetch(`${this.summaryEndpoint}?period=${this.currentPeriod}`, { credentials: 'same-origin' });",
    )
    dash = dash.replace(
        "const analyticsResponse = await fetch(`${this.apiEndpoint}?period=${this.currentPeriod}&limit=1000`);",
        "const analyticsResponse = await fetch(`${this.apiEndpoint}?period=${this.currentPeriod}&limit=1000`, { credentials: 'same-origin' });",
    )
    dash = dash.replace(
        """                        loading.classList.add('hidden');
                        results.classList.remove('hidden');
                        this.renderDeepInsights(this.allEvents);
""",
        """                        loading.classList.add('hidden');
                        results.classList.remove('hidden');
                        try {
                            this.renderDeepInsights(this.allEvents || []);
                        } catch (err) {
                            results.innerHTML = '<p class="text-sm text-stone-700">Deep dive could not finish. ' + (err && err.message ? err.message : 'Unknown error') + '</p>';
                        }
""",
    )
    write("analytics-backend.html", dash)

    print("review copy ready", OUT)


if __name__ == "__main__":
    main()
