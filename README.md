# HomeLab Griller by Pengu

A clean, modern BBQ event planner for your HomeLab. Run it as a Docker container, create BBQ events as admin, publish them for guests and work through all guest orders in a grill-master todo board.

![HomeLab Griller by Pengu](app/assets/logo.svg)

## Features

- Docker-ready PHP + SQLite app
- Password-protected admin area
- Default admin user: `Griller`
- Admin password through `GRILLER_ADMIN_PASSWORD`
- German and English included
- Extendable language packs as JSON uploads from the admin area
- Global reusable categories
- Product catalog with selectable toppings/options
- Deactivate or delete products, delete categories if unused
- Doneness selection for beef/steak products
- Event creation, publishing, closing and deletion
- Clone existing BBQ events
- Public guest event page
- Guest joins with name and creates a menu/order
- Quantity per item
- Guest order status page with auto-refresh, shown above the menu
- Admin sees all joined guests per event including order/item counts
- Guests can be deleted in the event admin area, including their orders
- Grill-master todo board with `Pending`, `In progress`, `Done`
- SVG logo and hero illustration included
- Persistent SQLite database in `./data`

## Screens / Areas

- `/` public dashboard with public events
- `?r=admin_login` admin login
- Admin dashboard for events, categories, products and language packs
- Event kitchen board for the grill master
- Guest overview and guest deletion inside each event admin page
- Public event link per BBQ event

## Quick start

```bash
cp .env.example .env
nano .env
```

Set a strong password:

```env
GRILLER_ADMIN_PASSWORD=your-strong-password
TZ=Europe/Berlin
```

Start the container:

```bash
docker compose up -d --build
```

Open:

```text
http://localhost:8088
```

Login:

```text
User: Griller
Password: value from GRILLER_ADMIN_PASSWORD
```

## Update

```bash
docker compose pull
docker compose up -d --build
```

Your data is stored in `./data` and survives container rebuilds.

## Backup

Stop the container and copy the data directory:

```bash
docker compose down
cp -a data data-backup-$(date +%Y%m%d)
docker compose up -d
```

The SQLite database is located at:

```text
./data/griller.sqlite
```

Uploaded language packs are stored in:

```text
./data/lang/
```

## Language packs

Built-in languages:

- `de`
- `en`

Admins can upload additional JSON language packs in the admin area. Use a short language code such as `fr`, `it`, `es`, `nl`.

Example:

```json
{
  "hero_title": "Le planificateur barbecue moderne pour ton HomeLab.",
  "join_event": "Rejoindre l'événement",
  "menu": "Menu",
  "send_order": "Envoyer la commande",
  "done": "Terminé"
}
```

Missing keys automatically fall back to the built-in German/English texts.

A sample file is included at:

```text
docs/example-fr.json
```

## Suggested GitHub repository name

```text
homelab-griller-by-pengu
```

## Security notes

- Change `GRILLER_ADMIN_PASSWORD` before exposing the app.
- Put the container behind a reverse proxy with HTTPS when using it outside your LAN.
- SQLite is perfect for small private BBQ events. For very large public use, a larger database backend would be a future improvement.

## Roadmap ideas

- QR code per public event
- Optional guest PIN or invitation code
- Export shopping list grouped by category
- Print-friendly kitchen tickets
- Push notifications via Home Assistant webhook
- Dark mode
- Drag-and-drop sorting for categories and products

## License

MIT
