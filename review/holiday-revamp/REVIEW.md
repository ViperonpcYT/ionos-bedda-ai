# Gemini — owner decisions override the earlier prices

Josie (owner) locked these. Do not talk her out of them.

- Holiday Ginger ingredients, highest to lowest: sodium olivate, sodium tallowate, sodium cocoate, orange essential oil, dried ginger root essential oil, cedarwood essential oil, cinnamon leaf essential oil, clove bud essential oil, zinc oxide, cocoa powder, turmeric powder, spirulina powder, rubia cordifolia powder. Spelling is corrected. The list is not invented.
- Plain Jane balm is on the Black Friday price too: strike $15.50, charge $9.99. Leaving the bestseller off the sale makes the sale look like leftovers. Points for Plain Jane, Holy Grail, and Creamsicle are 200 while the cash price is $9.99. That is the promo rule: a point stays worth 5 cents, so redemption matches the cash price, and there is no extra earn rate on top of the sale. Put the points back to 310 when the cash price returns to $15.50. Frontend and `order-totals.php` both say 200.
- Bedda Holiday Sanctuary Basket is **$89.99**. That is the floor she accepted. Pickup only. Do not ship it.
- Holiday Gift Bag stays **$24.99**. Pickup only. Do not ship it. Do not call it a saving while Creamsicle is $9.99.
- Sensitive Skin Bundle is **$37.69**. Shown as 5 Plain Jane soaps $17.70, 2 Plain Jane balms $19.99, soap saver free. Charged as one line so those splits do not become a cheaper price for a single bar.
- Be Glamorous Bundle is **$47.96**. Shown as 2 Uni $10.99, 3 She-Ra $16.99, Holy Grail $9.99, Creamsicle $9.99, Minty free. One line. Minty at $0 is not added to the allowlist.
- Sensitive Skin reward points are **754**, because $37.69 is the new price and a point is 5 cents. It was 949 when the set was $47.45.
- Curated page has an inquiry form. It opens a note to orders@bedda.ca. No account.
- Stripe stays live. Do not switch the store to test keys. Nothing in this pull request uploads to Ionos.

# Gemini — read this before you ship anything

Cursor did not touch `D:\Bedda\Ionos Bedda Website` and did not upload to Ionos. This pull request is the proposal. You decide whether it ships.

Do not merge into `master`. That branch is OnlyBikes. The base here is `cursor/bedda-production-fixes-dbca`. The files under `review/holiday-revamp/site` are the Ionos working copy plus these edits. They are not a drop-in replace for the older `website/` tree on that branch. If you ship, copy these files onto the Ionos tree, and upload `products.html` and `api/products.html` together. The server reads `api/products.html` first. A shop-only upload leaves September prices in charge.

Photos are phone pictures. Swap them when the Drive files arrive.

- `images/holiday-ginger.jpg`
- `images/holiday-basket.jpg`
- `images/holiday-gift-bag.jpg`

## Prices — use these

Holy Grail and Creamsicle: strike **$15.50**, charge **$9.99**. That $15.50 is the real card price, so the strike is honest. Plain Jane stays $15.50. Reward points on the two sale balms go from 310 to 200 (a point is 5 cents). Plain Jane stays 310.

The cross-sell script was still adding Plain Jane at $14.75. The server allowlist is $15.50 or the set split $13.60, so that button failed checkout. It is now $15.50.

Holiday Ginger is **$9.99**, 120g, red bow. No ingredient list was invented. The card says the list is on the label.

Holiday Gift Bag is **$24.99**. Cost $14.59 including labour, about 42% margin. At regular Creamsicle ($15.50) the three shelf pieces are $27.50 and the 0.2 oz tin is not sold alone. While Creamsicle is $9.99, Massager + She-Ra + Creamsicle is $21.99, so the bag costs **more** than buying those three on sale. Do not strike a higher number and do not call it a saving. The card is one price.

Bedda Holiday Sanctuary Basket is **$89.99**, pickup only. Cost $56.56 including labour. The pieces a customer can already buy add up to less than that cost, so the price is the basket, the exclusive spray, and the tools, not a discount. No crossed-out total. Profit about $33.43 before Stripe fees.

Removed: both Special Occasion soaps, Little Heart Surprise, and the Lavender Heart set.

## How the new sets are charged

Old sets explode into component lines. Those line prices join the allowlist, which is why Minty can be sent at $0 outside Be Glamorous, and why Holy Grail can be sent at $14.30 or $13.18. Leave the old sets alone. The new ones are one line, one price, and the server owns that price.

The button:

```javascript
if (button.dataset.sellAs === 'set') {
    const setName = button.dataset.product;
    const setPrice = button.dataset.price;
    cartManager.addItem(setName, setPrice, 1, {
        bundleName: setName,
        bundleKey: button.dataset.bundle || setName
    });
    return;
}
```

The only prices the server will accept:

```php
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
```

Stock for a basket also asks for those component SKUs. A ginger count of 0 blocks the basket. The JSON counts are still the placeholder **99**. Josie has to set real numbers. Do not treat 99 as inventory.

Balm card, both Holy Grail and Creamsicle:

```html
<div class="price-comparison">
    <span class="original-price">$15.50</span>
    <span class="current-price">$9.99</span>
</div>
<p class="text-xs font-semibold text-stone-700 mb-3">Black Friday sale</p>
```

`data-price` on the card and the button is `9.99`. The struck $15.50 is not a `data-price`, so it is not an allowed charge.

## Payments — what you should keep

Stripe keys on the server are live (`sk_live_` / `pk_live_`). The old comment that said “test mode only” was wrong.

Item prices were already recomputed from the catalog. These were not:

- The browser sent `coupon.discount` and both the PaymentIntent and the saved order believed it. A fake discount could drop a real cart to the $0.50 Stripe minimum. This copy ignores that number and looks the code up in `coupon_codes`.
- `requires_action` was treated as paid. It is not captured. A SetupIntent (`seti_`) was accepted too, and a succeeded SetupIntent saves a card without charging. This copy requires an id that starts with `pi_`, status `succeeded`, and at least 50 cents.
- If a succeeded PaymentIntent did not match the server total, the old code rewrote `grand_total` to the smaller captured amount and still emailed the full cart. This copy returns 400 and does not save the order.
- A shipping order with a $0 quote was chargeable. Pickup can still be $0. A shipped order at $0 postage is refused at payment setup.

The gate:

```php
if (strpos((string) $stripePaymentIntentId, 'pi_') !== 0 || $verifiedPaymentStatus !== 'succeeded' || $verifiedAmount < 50) {
    orderErrorResponse('Payment was not successful. Please check your payment details and try again.', 400);
}

$expectedCents = intval(round($finalGrandTotal * 100));

if ($verifiedAmount > 0 && abs($verifiedAmount - $expectedCents) > 1) {
    orderErrorResponse('Payment amount does not match order total. Please refresh and try again.', 400);
}
```

Still open, and you should close these if you ship:

1. `email-admin/submit-order.php` is on the web, sums `item.price` from the body, never calls Stripe, and still sends both emails. Deny that file or run it through the same gate. This review does not replace it.
2. Shipping dollars still come from `shipping_option.total` except for the $0 reject. Quote postage on the server.
3. Checkout decrements `store-stock.json`. Mark shipped decrements again when `inventory_synced` is missing or 0. Decrement once, on the insert, and set that flag in the same step.
4. The webhook does not insert the one-time order and does not decrement stock. That is fine if the browser always reaches `api/submit-order.php` after `succeeded`. A closed tab after pay currently leaves stock untouched until someone marks it shipped.
5. Old bundle component prices, including Minty at $0, stay on the allowlist. Do not “fix” that in the same pass as the holiday sets unless you re-price Sensitive Skin and Be Glamorous on purpose.

## Analytics — why the deep dive is blank

The page is public HTML. The button only gets a click handler after both fetches return 200. They never do.

`logger-backend.js` posts to `api/log-event.php`, which inserts `analytics_events` (`payload`, `created_at`).

`get-analytics.php` and `get-summary.php` were selecting `events` (`data`, `timestamp`, `referrer`). That table is not the one being written. The API returns 500, the dashboard stays on “Failed to Load Analytics”, and the deep dive never runs. Login does not fix that.

This copy reads the table that actually has rows:

```php
$stmt = $pdo->query("
    SELECT id, session_id, user_id, event_type, page,
           NULL AS referrer, payload AS data, created_at AS timestamp
    FROM analytics_events
    WHERE 1=1 $timeFilter
    ORDER BY created_at DESC
    LIMIT $limit
");
```

`$timeFilter` is only the three whitelisted intervals, and `$limit` is an int capped at 10000. There is no referrer column, so top referrers is an empty list.

`/analytics.html` and `/analytics-backend.html` redirect to `analytics-backend.php`, which sends a logged-out visitor to `/email-admin/`. Do not put an API key in the page.

`email-admin/analytics.php` on the live tree has a database password in the file. This review does not copy that file. Point it at the shared config and rotate the password. Do not commit that file.

## What not to redo

Same sage and stone, same type. One new soap card, two new set sections, the sale strike on two balms. No homepage rewrite.

Stock file price for the loaf still says 44.99 in the old JSON. The charge is 38.99 from the card. The JSON price is not what Stripe uses once the card has `data-price`. Leave it unless you are already in that file setting real counts.
