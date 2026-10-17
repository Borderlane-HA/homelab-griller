# Changelog

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
