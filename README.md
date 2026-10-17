# HomeLab Griller by Pengu

**Scan. Choose. Grill. Enjoy.** A self-hosted BBQ guest and order management app designed for mobile phones and private events.

![HomeLab Griller promotional artwork](docs/social-preview.png)

Create a BBQ event, choose the available burgers, sides, drinks and ingredients, and share the event's QR code. Guests join from their phones, customize meals and send orders. The grill master sees each guest's requests, can drag orders between preparation stages and marks food ready. Designed to stay easy to use even for parties with **50 guests**.

> **Private network recommended:** Host HomeLab Griller on a separate **guest network / VLAN** or a restricted local network. Allow guests access **only to the app**, not to Proxmox, Docker management or private HomeLab services. Guest Wi-Fi client isolation may require a firewall exception to reach the app. Complete the **initial admin setup immediately** on a trusted network. Do not expose the first-run setup page to the public Internet.

## Docker — install

```bash
mkdir -p homelab-griller/data && cd homelab-griller
nano compose.yml
```

```yaml
services:
  homelab-griller:
    image: ghcr.io/borderlane-ha/homelab-griller:latest
    container_name: homelab-griller
    restart: unless-stopped
    ports:
      - "8091:80"
      # Optional HTTPS (self-signed by default):
      # - "8443:443"
    environment:
      TZ: Europe/Berlin
      GRILLER_DEFAULT_LANGUAGE: de
    volumes:
      - ./data:/var/www/html/data
```

```bash
docker compose up -d
```

Open **`http://SERVER-IP:8091`** and complete the browser first-run setup. Choose your own admin username, password (**at least 6 characters**) and theme. **No installation-log token is needed.**

## Docker — update

From the Compose directory:

```bash
docker compose pull
docker compose up -d
```

Your database and uploaded language packs are in `./data`. Back them up before any update (stop the container for a consistent SQLite copy):

```bash
docker compose down
cp -a data "data-backup-$(date +%Y%m%d-%H%M)"
docker compose up -d
```

## Proxmox VE — install

Run **on the Proxmox host** (installs Debian LXC + Docker):

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/Borderlane-HA/homelab-griller/main/scripts/proxmox-lxc-install.sh)"
```

Choose a free CT ID, hostname, storage, network bridge, DHCP/static IP and application port (default **8091**). The installer displays the **LXC root password** at the end. Open the displayed application URL and create the admin credentials in the browser. For full Docker compatibility, a small Debian VM is another supported deployment approach.

## Proxmox VE — update

Run on the **Proxmox host**:

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/Borderlane-HA/homelab-griller/main/scripts/proxmox-lxc-update.sh)"
```

Enter your CT ID. The updater stops the application briefly, backs up its data, pulls the latest image and restarts it.

## Optional HTTPS

Container port **443** supports HTTPS. To expose it, uncomment **`8443:443`** in Compose and run `docker compose up -d`. Proxmox installations expose port **8443** by default.

HTTPS initially uses a **self-signed certificate** (your browser will warn). In **Admin → Settings → HTTPS**, upload your own PEM certificate/fullchain and its matching private key, then restart the container:

```bash
docker compose restart homelab-griller
```

Open `https://SERVER-IP:8443` or use the domain covered by your certificate. The uploaded key is stored persistently in `./data/tls/` (not in the public web directory). You can alternatively use a trusted reverse proxy for HTTPS termination.

## What can it do?

- Event QR codes, reusable guest PINs, custom menus and ingredient groups
- Guided mobile orders; edit or cancel before preparation begins
- Per-order ready progress and live status without page reload
- Grill-master Kanban with search, colored lanes, drag-and-drop for mouse **and touch**, plus status buttons
- Today's events at the top of the admin dashboard, event cloning and full administration
- Four themes, **German / English / Spanish**, uploadable JSON language packs
- Open-Meteo hourly weather cache, place search and event-day forecasts
- Editable footer text, page visibility and optional version display
- Admin first-run account setup; optional HTTPS with uploaded certificates
- SQLite storage; single Docker container; multi-architecture GHCR images

## Releases

Release tags such as `v2.0.0-beta.3` publish the Docker image and attach **FULL ZIP** and **UPDATE ZIP** for source installations. Docker/Proxmox users should update by pulling the container, **not** by replacing files inside it.

**Upgrading from v1 / early v2:** back up the existing `data` directory and read [UPGRADE-v1-to-v2.md](UPGRADE-v1-to-v2.md). Do not delete your database. Test on a copy first.

## License

[PolyForm Noncommercial License 1.0.0](LICENSE) — source available for permitted **noncommercial use**. This license is **not an OSI-approved open-source license**. Earlier versions already published under MIT remain available under their original terms; relicensing a new release does not revoke prior grants.

*Promotional banner is a concept illustration, not a screenshot of the live application.*
