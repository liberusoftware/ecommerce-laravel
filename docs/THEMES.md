# Storefront themes

A theme changes how a storefront looks. It never changes what the storefront does: prices,
stock, variants, options, the cart and checkout stay in the core, and every theme gets them the
same way.

## How a theme is chosen and applied

1. `ResolveChannel` resolves the request's host to a `Channel`.
2. `channels.theme` names the theme. The default, `theme-ecommerce`, is the core's own views and
   needs no directory.
3. `App\Support\StorefrontTheme` reads `themes/<name>/theme.json`, follows `parent`, and puts each
   theme's `resources/views` in front of the core's `resources/views`.
4. When a view is rendered, Laravel uses the first file it finds. A theme file overrides the core
   file at the same path, and anything the theme does not ship falls back to the core.

A theme is chosen per request, so two channels on one deployment can look completely different.
The initial channel takes its theme from `STOREFRONT_THEME` (`config('ecommerce.storefront_theme')`)
when the database is first migrated; after that, `channels.theme` is the source of truth.
An unknown theme name falls back to the core views and logs a warning.

## Layout of a theme

```
themes/<name>/
├── theme.json
└── resources/
    ├── css/app.css          colours, fonts, spacing
    ├── js/app.js            optional
    └── views/               only the files you override, at the core's paths
        ├── layouts/app.blade.php
        ├── components/home-navbar.blade.php
        ├── components/product-card.blade.php
        ├── components/storefront/variant-picker.blade.php
        ├── components/storefront/option-groups.blade.php
        ├── products/show.blade.php
        └── livewire/shopping-cart.blade.php
```

`theme.json` uses the fleet schema; only `name` is required:

```json
{
    "name": "floralgo",
    "display_name": "FloralGo",
    "type": "public",
    "parent": "theme-ecommerce",
    "assets": { "css": ["resources/css/app.css"], "js": ["resources/js/app.js"] }
}
```

Names are lowercase letters, digits and hyphens. **Do not add a `composer.json`.** The
`sync-packages` workflow publishes any `themes/*` directory that has one as a public package.

## Where to change what

| To change | Override |
| --- | --- |
| Page shell, header, footer | `layouts/app.blade.php`, `components/home-navbar.blade.php` |
| Product cards in grids, home and wishlist | `components/product-card.blade.php` |
| Product detail layout | `products/show.blade.php` |
| How variants are chosen | `components/storefront/variant-picker.blade.php` |
| How product options are chosen | `components/storefront/option-groups.blade.php` |
| Cart | `livewire/shopping-cart.blade.php`, `cart/*.blade.php` |
| Pagination, mail and other package views | `vendor/<namespace>/…`, e.g. `vendor/pagination/tailwind.blade.php` |
| Colours, typography, radii | `resources/css/app.css` (tokens) |

Override only what differs. Each file you copy is one you keep in step with the core.

## Colours and typography

The core defines its design tokens in the `@theme` block of `resources/css/app.css`. A theme's
stylesheet imports the core one and redefines the tokens it changes:

```css
@import '../../../../resources/css/app.css';
@import '@fontsource-variable/fraunces';

@theme {
    --font-sans: 'Fraunces Variable', serif;
    --color-primary-600: oklch(0.492 0.090 118);
    --color-primary-700: oklch(0.406 0.072 120);
}
```

Shared views colour state with the semantic scales `success`, `warning` and `danger`, never a stock
Tailwind hue. `success` aliases `primary` in the core, so a theme whose brand is not green
redefines `--color-success-*` as well.

Every `assets` entry is added to the Vite build automatically, and the storefront layouts load
the active theme's entries via `StorefrontTheme::entries()`.
A layout a theme overrides must do the same, `@vite(app(\App\Support\StorefrontTheme::class)->entries())`;
a copied `@vite(['resources/css/app.css', …])` loads the core's stylesheet instead of the theme's.

## The presentation contract

Views that show commerce data get it already priced and judged from
`App\View\Storefront\ProductPresenter`. Use it; do not recompute it in a theme.

Every view also receives `$currentStore` (the channel's `Store`, or null) and `$storeName`. Name
the merchant with these, not `config('app.name')`, which is the platform's name.

| Method | Gives you |
| --- | --- |
| `card($product)` | `url`, `add_url`, `name`, `image`, `summary`, `price`, `from_price`, `was_price`, `stock`, `stock_state` (`in`/`low`/`out`), `rating`, `review_count`, `requires_choice` |
| `variantPicker($product, old('variant_id'))` | `attributes`, `variants` (`id`, `values`, `label`, display `price` with tax and its numeric `amount`, `available`, `stock`, `sku`), `selected` |
| `defaultChoiceIds($product)` | option choices selected by default |

Forms post to the core routes with the core field names. The server reads these and nothing
else, and it re-prices and re-checks stock whatever the page showed:

| Form | Route | Fields |
| --- | --- | --- |
| Add to cart | `cart.add` (`POST /cart/add/{product}`) | `quantity`, `variant_id` (products with variants), `options[]` (choice ids), `price` (pay-what-you-want only) |
| Cart line | `cart.update` / `cart.remove` | `quantity` |

The core variant picker dispatches a `variant-changed` browser event with the chosen variant,
which the core add-to-cart form uses to enable its button. A theme's picker should do the same.
Each option input carries `data-price-adjustment`, so a theme can show a running total. The total
is only a guide: the server prices the line.

## What does not belong in a theme

- Queries, prices, tax, stock or discount logic. Use the presenter, or add to it in the core.
- Controllers, routes, Livewire classes or models. A storefront that needs new behaviour needs a
  core change, so every storefront gets it.
- Copies of core views you have not changed.

## Testing a theme

`tests/Feature/StorefrontThemeTest.php` points `StorefrontTheme` at `tests/Fixtures/themes` and
switches the channel's theme. Do the same for a site theme: render its pages and assert both its
own markup and the core behaviour (prices, variant choice, add to cart).
