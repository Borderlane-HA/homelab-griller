# HomeLab Griller by Pengu

A clean, modern and self-hosted BBQ event planner for HomeLab setups. Create BBQ events, publish them for guests, let guests build their own orders and work through everything in a simple grill-master todo board.

![HomeLab Griller by Pengu](app/assets/logo.svg)

## In short: What does this tool do?

HomeLab Griller is a self-hosted BBQ event planner and order dashboard for private grill events, parties and HomeLab setups.

As an admin, you can define what you bought for your BBQ event and create custom products such as a “PenguBurger”. For each product, you can add selectable options like cucumber, onions, tomato, cheese, toasted burger bun, BBQ sauce or garlic sauce.

Guests can join the public event page, enter their name and build their own order exactly the way they want it. They can choose options, set quantities and add notes.

As the grill master, you get a clean todo board with all guest orders. You can see who ordered what, prepare each item step by step and mark orders as in progress or finished. Guests can then see the status of their order directly on their own event page.


## Features

- Self-hosted Docker container
- PHP + SQLite, no external database required
- Password-protected admin area
- Default admin user: `Griller`
- Admin password is configured at container runtime with `GRILLER_ADMIN_PASSWORD`
- German and English included
- Extendable JSON language packs uploadable in the admin area
- Global reusable categories such as burgers, sausages, sides, drinks and desserts
- Product catalog with toppings/options
- Mobile-friendly guest order page with large `-` / `+` quantity buttons
- Doneness selection for beef/steak products
- BBQ events can be created, published, closed, deleted and cloned
- Guests join public events with their name
- Guests can build their own menu/order and see the order status at the top of the event page
- Admin can see all joined guests per event
- Guests can be removed from an event, including their orders
- Grill-master todo board with `Pending`, `In progress` and `Done`
- Persistent SQLite database in the mounted `data` directory

## Screenshots

| Screenshot | Area |
| --- | --- |
| ![Admin](docs/screenshots/screen1.png)` | Admin |
| ![Eat](docs/screenshots/screen2.png)` | Menu |
| ![Guest](docs/screenshots/screen3.png)` | NewGuest |
| ![Order](docs/screenshots/screen4.png)` | Guestorder |
| ![Status](docs/screenshots/screen5.png)` | Meal status |
| ![Admin](docs/screenshots/screen6.png)` | Admin |
| ![ToDoList](docs/screenshots/screen7.png)` | ToDoList |


## Container image

The recommended public image name for this repository is:

```text
ghcr.io/borderlane-ha/homelab-griller:latest
```

If you fork or rename the repository, replace `borderlane-ha/homelab-griller` with your own GitHub owner and repository name.

## Run with Docker Compose

Create a folder on your Docker host:

```bash
mkdir -p homelab-griller/data homelab-griller/lang
cd homelab-griller
```

Create `docker-compose.yml`:

```yaml
services:
  homelab-griller:
    image: ghcr.io/borderlane-ha/homelab-griller:latest
    container_name: homelab-griller
    restart: unless-stopped
    ports:
      - "8091:80"
    environment:
      GRILLER_ADMIN_PASSWORD: "change-this-password"
      GRILLER_DEFAULT_LANGUAGE: "de"
      TZ: "Europe/Berlin"
    volumes:
      - ./data:/var/www/html/data
      - ./lang:/var/www/html/lang/custom
```

Start the container:

```bash
docker compose up -d
```

Open the app:

```text
http://SERVER-IP:8091
```

Admin login:

```text
User: Griller
Password: value from GRILLER_ADMIN_PASSWORD
```

## Run with Docker CLI

```bash
docker run -d \
  --name homelab-griller \
  --restart unless-stopped \
  -p 8091:80 \
  -e GRILLER_ADMIN_PASSWORD="change-this-password" \
  -e GRILLER_DEFAULT_LANGUAGE="de" \
  -e TZ="Europe/Berlin" \
  -v ./data:/var/www/html/data \
  -v ./lang:/var/www/html/lang/custom \
  ghcr.io/borderlane-ha/homelab-griller:latest
```

## Password handling

The admin password is not baked into the Docker image. It is read when the container starts.

Set it with:

```yaml
environment:
  GRILLER_ADMIN_PASSWORD: "your-secure-password"
```

The admin username is always:

```text
Griller
```

If you change `GRILLER_ADMIN_PASSWORD`, recreate or restart the container:

```bash
docker compose up -d
```

The SQLite database remains untouched because it is stored in the mounted `./data` folder.



## Update

When using the published image:

```bash
docker compose pull
docker compose up -d
```

When building locally from the source code:

```bash
git pull
docker compose up -d --build
```

## Backup

Stop the container and copy the data directory:

```bash
docker compose down
cp -a data data-backup-$(date +%Y%m%d)
docker compose up -d
```

The SQLite database is stored here:

```text
./data/griller.sqlite
```

Uploaded language packs are stored here:

```text
./data/lang/
```

## Run on Proxmox VE

The cleanest Proxmox setup is to run HomeLab Griller inside a small Debian VM and run Docker Compose there. This keeps Docker separated from the Proxmox host and gives you clean backups, snapshots and network isolation.

A lightweight Debian LXC container can also work, but a VM is the most compatible option, especially if you want the fewest Docker-related permission issues.

### Recommended setup: Debian VM with Docker Compose

Suggested VM size for a normal private BBQ/event setup:

| Resource | Recommended value |
| --- | --- |
| OS | Debian 12 or Debian 13 minimal |
| CPU | 1 vCPU |
| RAM | 512 MB to 1 GB |
| Disk | 4 GB to 8 GB |
| Network | VirtIO bridge, DHCP reservation or static IP |

In Proxmox:

1. Upload a Debian ISO to your Proxmox ISO storage.
2. Create a new VM.
3. Use `q35` or the default machine type.
4. Use a VirtIO disk and VirtIO network adapter.
5. Install Debian without a desktop environment.
6. Give the VM a static IP address or create a DHCP reservation.
7. SSH into the VM.

Install Docker Engine and the Docker Compose plugin inside the VM:

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

Create the application folder:

```bash
mkdir -p ~/homelab-griller/data
cd ~/homelab-griller
```

Create `docker-compose.yml`:

```yaml
services:
  homelab-griller:
    image: ghcr.io/borderlane-ha/homelab-griller:latest
    container_name: homelab-griller
    restart: unless-stopped
    ports:
      - "8091:80"
    environment:
      GRILLER_ADMIN_PASSWORD: "change-this-password"
      GRILLER_DEFAULT_LANGUAGE: "de"
      TZ: "Europe/Berlin"
    volumes:
      - ./data:/var/www/html/data
```

Start HomeLab Griller:

```bash
docker compose up -d
```

Open the app:

```text
http://VM-IP:8091
```

Admin login:

```text
User: Griller
Password: value from GRILLER_ADMIN_PASSWORD
```

If the Proxmox firewall is enabled for the VM, allow TCP port `8091` or use a reverse proxy in front of the app.

### Alternative setup: Debian LXC container

A Debian LXC container is more lightweight than a VM, but Docker inside LXC needs nesting support. Use this only if you are comfortable troubleshooting LXC permissions. If Docker behaves unexpectedly, switch to the VM setup above.

In Proxmox, create a Debian LXC container with approximately:

| Resource | Recommended value |
| --- | --- |
| CT type | Unprivileged container |
| OS | Debian 12 or Debian 13 |
| CPU | 1 core |
| RAM | 512 MB to 1 GB |
| Disk | 4 GB to 8 GB |
| Features | `Nesting` enabled |

Enable nesting from the Proxmox UI:

```text
Container → Options → Features → Nesting
```

Or from the Proxmox host shell, replace `<CTID>` with your container ID:

```bash
pct set <CTID> -features nesting=1,keyctl=1
pct restart <CTID>
```

Then enter the container shell and install Docker with the same Docker installation commands shown in the VM section. After that, use the same `docker-compose.yml` and start command.

### Proxmox backup recommendation

Back up either the whole VM/LXC via Proxmox Backup or at least the mounted data directory:

```bash
cd ~/homelab-griller
cp -a data data-backup-$(date +%Y%m%d-%H%M)
```

The important persistent files are stored here:

```text
~/homelab-griller/data/griller.sqlite
~/homelab-griller/data/lang/
```

Do not install Docker containers directly on the Proxmox host unless you intentionally manage Docker there. Keeping the app inside a VM or LXC makes updates, backups and troubleshooting cleaner.

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

Missing translation keys fall back to the built-in language files.

A sample file is included at:

```text
docs/example-fr.json
```

## Recommended first setup inside the app

1. Open the admin area.
2. Check the default categories.
3. Add or edit products.
4. Create a BBQ event.
5. Select the products available for that event.
6. Publish the event.
7. Share the public event link or QR code with guests.
8. Open the grill-master todo board during the BBQ.

## Security notes

- Always change `GRILLER_ADMIN_PASSWORD` before exposing the app.
- Use a reverse proxy with HTTPS when the app is reachable outside your LAN.
- SQLite is ideal for small private BBQ events. For very large public use, a larger database backend would be a future improvement.

## Roadmap ideas

- Built-in QR code per public event
- Optional guest PIN or invitation code
- Shopping list export grouped by category
- Print-friendly kitchen tickets
- Home Assistant webhook notifications
- Dark mode
- Drag-and-drop sorting for categories and products

## License

MIT
