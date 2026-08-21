# Phase 6C — POS / Orders Visual Migration

Status: complete  
Date: 2026-08-21  
Scope: `public/orders.php`, its shared stylesheet rules, and POS-specific UI contracts

## Decision

The Orders page now presents a two-zone cashier workbench: a catalog workspace for
scanning, searching, filtering, and selecting products, and a checkout workspace for
the current order, transaction type, customer/supplier selection, totals, and submit
action. The migration uses the Phase 6A Signal Workbench tokens and component
patterns already used by the Dashboard. No frontend framework, build step, route,
schema, or server-side order behavior was added or changed.

The layout is intentionally operational rather than decorative:

- desktop keeps catalog and checkout visible together;
- tablet preserves the two-zone workflow with a narrower catalog rail;
- mobile stacks catalog before checkout and keeps controls touch-sized;
- barcode scanning remains the first-class cashier path;
- product cards and checkout controls have visible keyboard focus;
- the existing reduced-motion rule remains in effect, with POS card/button motion
  kept restrained.

## Preserved contracts

The migration retains the existing PHP data flow and JavaScript contracts, including:

- `#barcodeInput`, `#searchProduct`, `name="product_search"`, and `#productGrid`;
- `.product-item`, `.product-card`, `.category-pill`, and the existing
  `data-name`, `data-category-id`, `data-barcode`, `data-product-id`,
  `data-product-name`, `data-product-price`, and `data-product-stock` attributes;
- `#clearCartBtn`, `#cartCount`, `#cartTableBody`, `#emptyCartMsg`, `#cartSubtotal`,
  `#cartTotal`, and the existing cart quantity/remove hooks;
- `#orderForm`, `csrf_token`, `cart_data`, `complete_order`, `order_type`,
  `customer_id`, and `supplier_id` form names;
- `#typeSale`, `#typePurchase`, `#formCustomerGroup`, `#formSupplierGroup`,
  `#customerSelect`, `#supplierSelect`, and `#completeOrderBtn`;
- barcode lookup, stock limits, quantity controls, order-type switching, clear-cart
  behavior, checkout validation, CSRF rejection, and administrator-only purchase
  controls.

The only JavaScript additions are keyboard activation for focusable product cards,
an intentional empty catalog state, and shared visual classes on dynamically-created
cart rows. Existing IDs, data attributes, event behavior, validation, and hidden
form values remain intact.

## Component ownership

POS-specific presentation is owned by the `Phase 6C: POS / Orders visual migration`
block in `public/assets/css/style.css`:

- `.pos-page*` owns the page header and workflow status;
- `.pos-workbench`, `.pos-catalog-zone`, and `.pos-checkout-zone` own the two-zone
  composition;
- `.pos-barcode-panel`, `.pos-search-panel`, and `.pos-category-bar` own catalog
  input hierarchy;
- `.pos-product-*` and `.product-empty-state` own cards, stock, imagery, and empty
  states;
- `.pos-checkout-*`, `.pos-cart-*`, `.pos-quantity-*`, and `.pos-selector-field`
  own the checkout surface.

This keeps the orders page markup expressive while preventing a second isolated
visual vocabulary. Bootstrap remains responsible for baseline form/button behavior
and the existing shared stylesheet remains responsible for global shell, type,
focus, and reduced-motion behavior.

## Retired or retained selectors

The former fixed-height POS layout, gradient category treatment, oversized hover
transforms, and page-specific legacy card/cart rules were removed after repository-wide
searches showed the affected POS selectors were only used by `public/orders.php` and
the old POS stylesheet block. The migrated declarations are replaced by the scoped
`.pos-*` component block.

The shared `.pulse-btn` selector remains because it has non-POS callers. The shared
`.table-row-hidden` selector remains because it is used by other pages. Compatibility
classes such as `.category-pill`, `.product-card`, `.cart-qty-btn`,
`.cart-qty-input`, `.supplier-form-hidden`, and `.product-filter-hidden` remain in
the markup and behavior because the existing JavaScript and page contracts depend on
them.

## Visual evidence

The Phase 6A before-baselines are preserved and were compared visually with the
sanitized Phase 6C after captures. Product names, values, account labels, and form
values are masked; the captures use disposable browser-QA data.

| Viewport | Before | After |
| --- | --- | --- |
| 375px | [mobile before](baselines/phase-6a-before/mobile-375-cashier-orders.png) | [mobile after](baselines/phase-6c-after/mobile-375-cashier-orders-cart.png) |
| 768px | [tablet before](baselines/phase-6a-before/tablet-768-cashier-orders.png) | [tablet after](baselines/phase-6c-after/tablet-768-cashier-orders-cart.png) |
| 1440px | [desktop before](baselines/phase-6a-before/desktop-1440-cashier-orders.png) | [desktop after](baselines/phase-6c-after/desktop-1440-cashier-orders-cart.png) |

The after directory also contains the standard cross-page browser-QA captures from
the same disposable run. The three `*-cashier-orders-cart.png` captures are the
Phase 6C interaction-state evidence.

## Verification

- Focused POS source contracts: **108 assertions passed**.
- Browser QA: **21/21 passed** across 375px, 768px, and 1440px. This includes
  search, category filtering, barcode lookup, add/quantity/remove behavior,
  transaction-type switching, safe checkout validation, invalid-CSRF rejection,
  cashier restrictions, keyboard smoke checks, and horizontal-overflow checks.
- Full disposable PHP regression: **3,703 assertions passed** (`2,076` unit and
  `1,627` integration).
- PHP lint: **93/93 tracked files passed**.
- JavaScript syntax: **4/4 tracked files passed** with `node --check`.
- Repository security scan: passed (**11 assertions**).
- Supply-chain scan: passed (**12 assertions**).
- Release-integrity scan: passed (**9 assertions**).
- `git diff --check`: passed.

No backend PHP decision logic, database query, authorization, CSRF path, order
contract, stock validation, pricing behavior, or business behavior was changed.
No push or merge was performed.
