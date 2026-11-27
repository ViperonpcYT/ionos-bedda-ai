# Bedda holiday revamp — for Gemini to compare

This is a review copy. It was not applied to `D:\Bedda\Ionos Bedda Website` and it was not uploaded to Ionos.

Do not merge this into `master`. That branch is the OnlyBikes shop. This pull request is against `cursor/bedda-production-fixes-dbca`. The files under `review/holiday-revamp/site` match the Ionos working copy plus these edits. They are not a drop-in replace for the older `website/` tree on that branch.

The live shop folder and this copy started from the same files. Gemini can diff them and ship one version.

Photos are the phone pictures we have. Replace them when the Drive originals arrive. Paths:

- `images/holiday-ginger.jpg`
- `images/holiday-basket.jpg` (wrapped basket)
- `images/holiday-gift-bag.jpg`

## Prices

Card prices the server already charges, used as the anchors:

| Piece | Price |
| --- | --- |
| Uni Exfoliating Soap | $5.60 |
| She-Ra Exfoliating Soap | $5.75 |
| The Massager Soap | $6.25 |
| Minty Lip Balm | $4.85 |
| Holy Grail balm, regular | $15.50 |
| Creamsicle balm, regular | $15.50 |

Holy Grail and Creamsicle now show **$15.50** struck out and charge **$9.99**. That regular price is the real card price, so the strike is honest. Plain Jane stays $15.50. Rewards for the two sale balms move from 310 points to **200** points, because a point is worth 5 cents and $9.99 is about 200 points. Plain Jane stays 310.

**Holiday Ginger Soap — $9.99.** 120g, red bow. No ingredient list was invented; the card says the list is on the label.

**Holiday Gift Bag — $24.99.**  
Massager $6.25 + She-Ra $5.75 + Creamsicle on sale $9.99 + Minty $4.85 = **$26.84**.  
The bag is $1.85 under that sum. The struck figure on the card is $26.84, the sum of those four cards, not an old price that was never charged.  
Cost $14.59 including labour. Profit about $10.40. Margin about 42%.

**Holiday Basket — $89.99.**  
The five pieces that already have card prices add up to $36.68 at the sale balm price. The spray, cloth, headband, soap saver, gua sha, and the basket are the rest of the price. Spring Clean is not given a made-up standalone price, and the card does not show a fake “was” price.  
Cost $56.56 including labour. $89.99 is 1.59× that cost, about $33 over cost, about 37% margin.  
A price near $70 would clear the cost and still feel cheap for the labour already inside $56.56. $89.99 is the number in the files.

Both new sets are **one cart line at one server price**. They do not split into cheaper component prices. Splitting is how the current sets accidentally allow a lower price on a single item.

## What came off

- Special Occasion soaps (Hidden Heart and Lavender Heart), including their product cards.
- Little Heart Surprise and the Lavender Heart curated sets.

## Why stock and the charged price can disagree today

`api/lib/store-catalog.php` reads `api/products.html` before the shop `products.html`. The copy in `api/` is from September. The shop page is from November. This review puts the same updated HTML in both places. Upload both, or the server keeps the old prices.

Stock counts in `store-stock.json` are still the placeholder **99**. That is not a real count. Josie needs to set them. A basket or bag sale now asks for the set and for each catalogued piece inside it, so a ginger bar at 0 blocks the basket.

## Payments

Already true, and left in place: Stripe amount is recalculated from the catalog, and an order is not saved when Stripe does not confirm the PaymentIntent.

Changed in this copy:

- The coupon **amount** from the browser is ignored. The code is looked up in `coupon_codes`. A fake discount no longer reduces the Stripe charge.
- An order is saved only when Stripe status is `succeeded`. `requires_action` is not paid yet, so it no longer saves an order.
- If the captured amount does not match the server total, the order is refused. The old path saved the cheaper captured amount and treated it as the order.
- A shipping order with a $0 shipping quote is refused at payment setup. Pickup can still be $0.

Left on purpose: older bundles still list component prices, including a $0 “included” lip balm, and those prices are allowed on their own. Fixing that would change checkout for Sensitive Skin and Be Glamorous. The new sets do not add another $0 price.

Confirm the server Stripe secret is a live key before a real charge. The payment file used to say “test mode only” in a comment. The key itself is environment config, not in this review.

## Analytics

`analytics-backend.html` is a public file. The deep-dive button only gets its click handler after the data calls succeed. Those calls require an admin session, so a logged-out visitor gets a dead button, and anyone can still open the page.

This copy redirects `/analytics.html` and `/analytics-backend.html` to `analytics-backend.php`, which sends logged-out people to `/email-admin/`. The deep-dive click reports an error in the panel instead of failing silently.

`email-admin/analytics.php` on the live tree has a database password written in the file. This review does not copy that file. Move that password into the existing config and rotate it. Do not ship that file in git.

## Not in this pass

No visual overhaul. Same type, same sage and stone, two new sections and one soap card.

No Ionos upload. No commit on the live working tree.
