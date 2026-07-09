# NetPulse

A modern, self-hosted **network monitoring & mapping** web application — a spiritual successor to MikroTik's *The Dude*, rebuilt in plain PHP with a clean web UI.

NetPulse renders your network as interactive maps (devices, links, sub-maps), monitors availability in real time (ICMP / TCP / SNMP), shows **live Rx/Tx traffic per interface** with utilization-based link colors, records traffic history into graphs, and sends **Telegram** alerts — all from a single PHP app you run on your own server.

It can **import an existing `The Dude` database** (`dude.db`) so you don't have to rebuild your topology from scratch, and you can keep editing maps, devices and links directly from the browser.

> Built by a WISP operator to replace an aging Dude install on a real network. No Java, no Windows agent — just PHP + your browser.

## Screenshots

> The screenshots below use a **fictional example network** — no real device data.

![Network map (dark theme)](docs/screenshots/example-map-dark.png)

*Interactive map with device icons, sub-maps, three-state status colors, live per-interface Rx/Tx and utilization-based link colors (blue -> green -> yellow -> orange -> red).*

![Network map (light theme)](docs/screenshots/example-map-light.png)

*The same map in light theme.*

![Traffic graph](docs/screenshots/example-graph.png)

*Per-link traffic history recorded from SNMP polling.*

![Sign-in](docs/screenshots/login-en.png)

*Sign-in screen with a language picker — pick your language on first launch.*

## Features

- **Interactive maps** — devices, links and sub-maps rendered as SVG, faithfully following the Dude layout (positions, link styles: solid / dotted / dashed, thickness). Auto-sized sub-map circles.
- **Import from The Dude** — one-click import from an existing `dude.db` (maps, devices, device types, icons, probes, services, links, SNMP profiles). Maps keep their original Dude order.
- **Live monitoring** — three-state status (up / pending / down) like Dude. Uses `fping` for fast parallel ICMP, with a **TCP-connect fallback** so it works even on shared hosting where ICMP/`exec` is blocked.
- **Real per-interface traffic** — SNMP polling of `ifHCInOctets`/`ifHCOutOctets` (64-bit, with 32-bit fallback) turned into live Rx/Tx bps on each link.
- **Utilization-based link colors** — link color shifts across a full spectrum based on interface utilization %, visible even at low load.
- **Graphs** — traffic history recorded over time per link, with a dedicated *Graphs* section.
- **Telegram notifications** — native PHP (no `wget`/`curl` shell-out), fully configurable in the UI.
- **User roles** — `administrator` (full access incl. backup/restore & resetting others' passwords), `admin` (user management), `user` (read-only + change own password).
- **Editing from the web** — add/edit/delete devices, links (line type via dialog) and maps directly in the browser.
- **Backup / restore** and re-import from the Settings screen.
- **Multi-language** — UI available in English, Slovak, Czech, German, Polish and Hungarian; pick your language on the login screen or later in Settings -> Appearance (saved in your browser).
- **Modern UI** — light / dark / auto themes, responsive layout with touch controls, sortable tables with sticky headers, clean SVG device icons.

## Requirements

- PHP **8.1+** with PDO (`pdo_sqlite` or `pdo_mysql`)
- A web server (nginx + php-fpm recommended; Apache works too)
- Optional: `fping` for fast ICMP monitoring (`apt install fping`)
- Optional: `net-snmp` CLI tools (`snmpget`, `snmpwalk`) or the PHP `snmp` extension for traffic polling
- SQLite works out of the box; MySQL/MariaDB is supported for larger / multi-user setups

## Quick start (SQLite)

```bash
git clone https://github.com/<your-user>/netpulse.git
cd netpulse

# make the data dir writable by the web server
mkdir -p data && chmod 775 data

# serve it (dev only) — or point nginx/apache at this folder
php -S 0.0.0.0:8080
```

Open `http://localhost:8080/`. On first run the schema is created automatically.
Default login is **admin / admin** — **change the password immediately** in Settings.

### Import your existing Dude database

Copy your `dude.db` into `data/` and run:

```bash
php import_dude.php data/dude.db
```

...or use **Settings -> Backup & import -> Import from Dude** in the web UI. Your `dude.db` is private and is **git-ignored** — it never ends up in the repository.

## Production (nginx + php-fpm + systemd)

1. Point an nginx `server {}` block at the project folder with a PHP-FPM pool.
2. Ensure `data/` is writable by the FPM pool user.
3. Enable background workers:
   - **Monitor** — `php monitor.php loop` (availability checks)
   - **SNMP poller** — `php snmp_poll.php loop` (reads the poll interval from Settings)

Run both as `systemd` services (or cron) so monitoring and traffic history keep updating.

Configuration lives in `config.php` — set the DB driver, MySQL credentials (if used), check method (`auto` / `tcp` / `icmp`), and the down-after grace period. Sensible defaults are provided; several values can also be overridden via environment variables.

## Configuration highlights (`config.php`)

| Key | Purpose |
|---|---|
| `APP_NAME` | Name shown in header, title and login |
| `DB_DRIVER` | `sqlite` (default) or `mysql` |
| `CHECK_METHOD` | `auto` (ping if possible else TCP), `tcp`, or `icmp` |
| `TCP_FALLBACK_PORTS` | Ports probed for TCP availability (Winbox 8291, 80, 443, 22, ...) |
| `DOWN_AFTER` | Seconds of no response before a device is marked down |
| `USE_FPING` | Use `fping` for fast parallel ICMP |

Telegram, SNMP profiles and poll interval are configured in the **Settings** screen and stored in the database.

## Languages

The interface ships in **English, Slovencina, Cestina, Deutsch, Polski and Magyar**. Translations live in `assets/i18n.js` — add a language by copying one of the dictionaries and translating the values. Contributions welcome.

## Project layout

```
index.php          SPA shell + navigation
api.php            JSON API (maps, devices, links, users, settings, graphs...)
assets/app.js      Frontend SPA (map rendering, dialogs, tables)
assets/i18n.js     UI translations + language switcher
assets/style.css   Themes (light/dark/auto), responsive layout
DudeParser.php     Reverse-engineered parser for The Dude's binary object blobs
importer.php       Shared import logic (dude.db -> app schema)
import_dude.php    CLI import entry point
monitor.php        Availability monitoring (loop worker)
snmp_lib.php       SNMP get/walk helpers (v1/v2c/v3)
snmp_poll.php      SNMP traffic poller (loop worker)
telegram.php       Native Telegram sender
db.php / config.php  PDO layer & configuration
schema_*.sql       SQLite / MySQL schema
```

## Security notes

- Real network data (`dude.db`, `data/app.db`, sessions) is **git-ignored** — this repo ships clean, with no topology or credentials.
- Change default credentials on first login.
- SNMP community strings / v3 credentials and Telegram tokens are stored in your local database, not in the code.

## Roadmap

- Optional ping/latency graphs and PNG export of graphs.
- More UI languages.

## License

Released under the [MIT License](LICENSE). (c) 2026 Juraj Chudy.
