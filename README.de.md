# HomeLab Griller by Pengu

Ein moderner, cleaner Grillplaner für dein HomeLab. Als Docker-Container starten, Grillevents als Admin anlegen, für Gäste veröffentlichen und alle Bestellungen in einer Grillmeister-Todo-Liste abarbeiten.

![HomeLab Griller by Pengu](app/assets/logo.svg)

## Funktionen

- Docker-fähige PHP + SQLite App
- Passwortgeschützter Adminbereich
- Standard-Admin-Benutzer: `Griller`
- Admin-Passwort über `GRILLER_ADMIN_PASSWORD`
- Deutsch und Englisch enthalten
- Erweiterbare Sprachpakete als JSON-Upload im Adminbereich
- Globale wiederverwendbare Kategorien
- Produktkatalog mit auswählbaren Toppings/Optionen
- Produkte deaktivieren oder löschen, Kategorien löschen falls ungenutzt
- Gargrad-Abfrage für Beef-/Steak-Produkte
- Events erstellen, veröffentlichen, schließen und löschen
- Bestehende Grillevents klonen
- Öffentliche Event-Seite für Gäste
- Gast joint mit Namen und stellt Menü/Bestellung zusammen
- Anzahl pro Produkt auswählbar
- Bestellstatus für Gäste mit Auto-Refresh, direkt oberhalb des Menüs
- Admin sieht je Event alle beigetretenen Gäste inkl. Bestell-/Positionsanzahl
- Gäste können im Event-Adminbereich gelöscht werden, inklusive ihrer Bestellungen
- Grillmeister-Todo-Board mit `Offen`, `In Arbeit`, `Fertig`
- SVG-Logo und Hero-Grafik enthalten
- Persistente SQLite-Datenbank in `./data`

## Schnellstart

```bash
cp .env.example .env
nano .env
```

Passwort setzen:

```env
GRILLER_ADMIN_PASSWORD=dein-sicheres-passwort
TZ=Europe/Berlin
```

Container starten:

```bash
docker compose up -d --build
```

Öffnen:

```text
http://localhost:8088
```

Login:

```text
Benutzer: Griller
Passwort: Wert aus GRILLER_ADMIN_PASSWORD
```

## Ablauf

1. Admin einloggen.
2. Kategorien und Produkte prüfen, anpassen, deaktivieren oder löschen.
3. Neues Grillevent erstellen.
4. Produkte für das Event auswählen.
5. Event auf `Öffentlich` stellen.
6. Public Link an Gäste schicken.
7. Gäste geben ihren Namen ein und bestellen Burger, Würstchen, Beef, Beilagen, Getränke usw.
8. Im Event-Adminbereich sieht der Admin alle beigetretenen Gäste und kann Gäste bei Bedarf löschen.
9. Grillmeister öffnet das Todo-Board und setzt Tickets auf `In Arbeit` oder `Fertig`.
10. Gäste sehen oben in ihrer Event-Ansicht automatisch, wenn ihre Bestellung fertig ist.

## Update

```bash
docker compose pull
docker compose up -d --build
```

Die Daten liegen in `./data` und bleiben bei Container-Neubuilds erhalten.

## Backup

Container stoppen und Datenordner sichern:

```bash
docker compose down
cp -a data data-backup-$(date +%Y%m%d)
docker compose up -d
```

SQLite-Datenbank:

```text
./data/griller.sqlite
```

Hochgeladene Sprachpakete:

```text
./data/lang/
```

## Sprachpakete

Enthalten:

- `de`
- `en`

Weitere Sprachen können im Adminbereich als JSON-Datei hochgeladen werden. Nutze einen Sprachcode wie `fr`, `it`, `es`, `nl`.

Beispiel:

```json
{
  "hero_title": "Le planificateur barbecue moderne pour ton HomeLab.",
  "join_event": "Rejoindre l'événement",
  "menu": "Menu",
  "send_order": "Envoyer la commande",
  "done": "Terminé"
}
```

Fehlende Texte fallen automatisch auf Deutsch/Englisch zurück.

Eine Beispieldatei liegt hier:

```text
docs/example-fr.json
```

## GitHub Repository Name

```text
homelab-griller-by-pengu
```

## Sicherheit

- `GRILLER_ADMIN_PASSWORD` unbedingt ändern.
- Für Zugriff außerhalb des LANs einen Reverse Proxy mit HTTPS nutzen.
- SQLite ist für private Grillevents ideal. Für sehr große öffentliche Nutzung wäre später eine größere Datenbank sinnvoll.

## Ideen für spätere Versionen

- QR-Code pro Event
- Optionaler Gäste-PIN oder Einladungscode
- Einkaufsliste nach Kategorien exportieren
- Druckansicht für Grilltickets
- Home-Assistant-Webhook für Push-Nachrichten
- Dark Mode
- Drag-and-drop Sortierung für Kategorien und Produkte

## Lizenz

MIT
