# Storefront Expected Delivery — Merchant Guide (M7)

Since v1.24.0, WC Inventory Overview can replace WooCommerce's "Out of stock"
text on the storefront with one carefully governed fact: the earliest
credible date an out-of-stock item is expected back, worded by how sure we
are of it.

## What customers see

For an item WooCommerce reports as **not in stock**, one of three things
replaces the default "Out of stock" text:

| Customer sees | When |
|---|---|
| **Expected back around 1 September** | A specific expected date exists that we're confident in (an exact date from the supplier). |
| **Expected during week 36** | A specific expected date exists but it's an estimate (e.g. the supplier only gave a week number). |
| **Expected soon** | Stock is genuinely on its way, but no date we hold is safe enough to publish (see "Why a date can disappear" below). |

If the item has **no** incoming supply at all, or is **in stock**, nothing
changes — the customer sees exactly what WooCommerce and your theme already
show.

## What customers never see

Never: supplier name, purchase order number, order quantity, cost, or the
raw "delayed" flag. The plugin exposes exactly one governed fact and nothing
else. This is a permanent architectural boundary (see
`docs/adr/0003-storefront-expected-delivery-ownership.md`), not a setting.

## The one setting

**Inventory & Profit → Settings → Storefront → "Enable Expected Delivery
display"**

- **Yes** (default): the wording above replaces "Out of stock" text
  wherever your theme renders it (product page, catalog cards that show
  stock status, variation selection).
- **No**: storefront output returns to stock WooCommerce immediately — no
  deploy needed, nothing else in the plugin changes behavior.

No other configuration exists. There is no per-product override, no
wording-template editor, and no separate delay threshold — customization
beyond translation is available to developers via a filter (see below).

## Why a date can disappear without anyone editing anything

A date that was safe to publish yesterday can stop being safe today, with
**no PO or receipt change at all** — because "is this date still in the
future?" is checked against *today*, every time the page renders. A
purchase order expecting stock on 1 September will show "Expected back
around 1 September" right up until 1 September, and from the next day
onward that date is no longer future-dated, so the storefront falls back to
"Expected soon" (if stock is still coming) or drops the message entirely (if
it isn't). This is expected, not a bug — the alternative would be showing a
customer a date that has already passed.

## "Expected soon" is a deliberate refusal, not a vaguer guess

"Expected soon" does **not** mean "we have a date but we're less sure of
it" — that case already gets its own wording ("Expected during week 36").
"Expected soon" means: **we know more stock is coming, but every candidate
date failed our internal safety check** (it's overdue, unconfirmed, or has
no date attached at all). The plugin would rather say less than say
something it can't stand behind.

## Products with variations

If a variable product (e.g. a T-shirt with Size/Color options) is out of
stock as a whole, the **product page** shows "Expected soon" — never a
specific date — even if individual sizes/colors do have confirmed dates.
Once the customer picks the exact variation they want, that variation shows
its own precise wording. This is deliberate: "Expected soon" is the
strongest true statement that holds regardless of which variation the
customer ends up choosing.

## For developers

See `docs/api-expected-delivery.md` for the public API, and the
`wc_io_storefront_render_expected_delivery` / `wc_io_expected_delivery_text`
filters for customizing or replacing this behavior without touching plugin
code.
