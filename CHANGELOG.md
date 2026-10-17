# Changelog

## 2.0.0-beta.7

- Replaced the oversized Kitchen Display weather forecast with a compact, collapsed-by-default weather strip.
- Current event weather essentials (temperature range, rain probability, and wind speed) always remain visible.
- Native accessible disclosure reveals hourly forecast, sun hours and Open-Meteo attribution on demand, without any page reload.
- Added English, German and Spanish weather-detail labels with responsive dark/light theme styling.
- Brought forward the corrected beta.6 theme smoke test (CSRF extraction and persisted-theme check).
- No database schema change, no modifications to guest ordering or inventory behavior.

## 2.0.0-beta.6

- Six fully coordinated themes: Classic Grill, Summer Garden, Dark Mode, Nordic Light, Smokehouse and Kids Party; original theme keys remain backward compatible.
- New theme gallery with miniature previews, one-click live application and immediate CSRF-protected AJAX persistence.
- Custom Theme with constrained accent color, light/dark mode and card finish; automatically saved without interrupting admin work.
- Optional securely validated branding images (logo and hero banner), persisted in the mounted data directory.
- Semantic contrast tokens for type, buttons, status labels and overlays throughout desktop and mobile views.
- Fixed beta.5 CI smoke test CSRF lookup after the admin Events split, and added theme-related integration checks.
- No database schema change; existing events, guests, orders, recipes, and inventory remain untouched.


## 2.0.0-beta.5

- Desktop sidebar and mobile bottom navigation with an accessible More drawer.
- New live dashboard with featured event, event-scoped KPIs, recent orders, weather and stock alerts (15-second AJAX refresh without page reload).
- Events management moved into a dedicated view with mobile-friendly event cards.
- Navigation links for Grill Board, menus, ingredients, stock, guests, language packs and settings.
- Guest ordering, inventory transactions, database schema and theme selection unchanged.
- Updated admin integration smoke checks.


## 2.0.0-beta.4

- Public event cards redesigned with a dedicated weather panel, date, and clearer mobile join action.
- Event-specific optional stock management: ingredient quantities, direct product caps, required recipes, selectable toppings and sold-out notices.
- Atomic checkout inventory validation under SQLite write lock, with a per-order ingredient reservation ledger so later recipe edits do not alter existing consumption.
- Automatic stock release for cancelled/deleted orders and order edits; completed orders keep stock consumed.
- Event cloning copies starting stock and caps without copying orders.
- Guest stock availability updates without reloading the order wizard.
- Include safe Proxmox updater with automatic CT detection and pull-before-stop.
- Integration smoke checks for stock cap, overselling and cancellation release.

## 2.0.0-beta.3

- Fix guest order wizard scrolling to the top when selecting ingredients, quantities, doneness or notes: category click handler is now bound only to category navigation buttons.
- Version the guest JavaScript asset URL to avoid serving a cached, buggy script after container updates.

## 2.0.0-beta.2

- First-run admin account without log token; minimum password length 6
- Newly created events are public by default; today's events prioritized in admin dashboard
- Event title links directly to grill-master board
- Guest option selection preserves scroll and auto-sets quantity from 0 to 1 only
- Partial readiness per order and targeted ready notifications
- Distinct kitchen status colors; mouse and mobile long-press drag-and-drop
- Weather location search, hourly Open-Meteo cache, event preview and grill-master hourly forecast
- Configurable footer text, visible pages and version display
- Optional Apache HTTPS listener and in-admin certificate upload (restart required)
- README streamlined to Docker / Proxmox installation and updates, promotional artwork only
- License changed for **this release** to PolyForm Noncommercial 1.0.0 (prior MIT grants remain in effect)

## 2.0.0-beta.1

- New responsive interface, mobile order wizard, guest order editing and cancellation
- Ingredient library, live kitchen board, first-run account wizard and themes
- DE, EN, ES language packs, Open-Meteo widget and multi-arch Docker releases
