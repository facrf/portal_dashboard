# 🌐 Portal Dashboard

> A lightweight, extremely customizable, and privacy-focused dashboard for your Homelab / Homeserver.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.1-777bb4.svg)](https://www.php.net/)
[![SQLite Version](https://img.shields.io/badge/SQLite-3-003b57.svg)](https://www.sqlite.org/)

**Portal Dashboard** is a minimalist and secure alternative to tools like Heimdall and Homepage. It is designed for those who want to centralize access to their home server without giving up complete control over their data.

---

## 🎯 Project Philosophy

* **Zero Embedded Telemetry:** No trackers, no pingbacks, no external data analytics. What happens on your server, stays on your server.
* **100% Local:** Zero dependency on third-party APIs or clouds.
* **Maximum Efficiency:** Built purely with **PHP** and **SQLite**, consuming the minimum possible hardware (ideal for running on mini PCs, old laptops, or Raspberry Pi).
* **Freedom of Customization:** Configure and organize your services simply and directly.

---

## ✨ Features

* 🚀 **Instant Startup:** No heavy databases or complex setup processes.
* 📶 **Smart Monitoring:** The dashboard automatically tests in real-time whether your services are online, validating both HTTP requests and direct connections to local IPs or (TCP/NTP) ports.
* 📱 **Responsive Design:** Access and manage your homelab perfectly from your computer, tablet, or phone.
* 💾 **Simple Persistence:** Settings stored locally in a single-file SQLite database.
* 🎨 **Highly Customizable:** Create categories, add service links, and organize the layout according to your needs.
* 🔒 **Total Privacy:** 100% self-hosted fonts and assets. No third-party API calls (like Google Fonts).

---

## 🛠️ Technologies Used

* **Backend:** PHP (8.1+)
* **Web Server:** Nginx
* **Database:** SQLite 3
* **License:** GNU GPL v3

---

## 🚀 How to Install

You can run Portal Dashboard directly on your preferred web server or via Docker.

### Method 1: Local Web Server (Apache / Nginx)

1. **Prerequisites:**
   * Web Server (Apache, Nginx, etc.)
   * PHP 8.1 or higher installed.
   * `pdo_sqlite`, `mbstring` and `curl` extensions enabled; `yaml` for Homepage imports.

2. **Clone the Repository:**
   ```bash
   git clone https://github.com/facrf/portal_dashboard.git
   cd portal_dashboard
   ```

3. **Configure Permissions:**
   The local database is stored at `db_data/bd.db`. Grant write access only to that directory:
   ```bash
   mkdir -p /path/to/portal_dashboard/db_data
   sudo chown -R www-data:www-data /path/to/portal_dashboard/db_data
   ```
   Set `PORTAL_DB_PATH` to a full file path if you want to store it elsewhere.

4. **Access in Browser:**
   Access `http://localhost/portal_dashboard` (or your server's IP).

---

### 🐳 Installation with Docker Compose (Recommended)

Since the **Portal Dashboard** image is automatically built and hosted on GitHub Container Registry (GHCR), you do not need to clone this repository to run the project on your server. It supports `amd64`, `arm64`, and `arm32v7`.

1. Create a file named `docker-compose.yml` (or create a new **Stack** in your Portainer).
2. Paste the following content:

```yaml
version: '3.8'

services:
  portal-dashboard:
    image: ghcr.io/facrf/portal_dashboard:latest
    container_name: portal_dashboard
    ports:
      - "8080:80" # Port where the dashboard will be accessible (change if necessary)
    volumes:
      # Safe mapping for the SQLite database
      - /your_path/portal_db:/var/www/db_data
      # Mapping for icons
      - /your_path/icons:/var/www/html/icons
    restart: unless-stopped
```

If the portal is behind a reverse proxy, configure only that proxy's IP (or narrow CIDR) as trusted:

```yaml
    environment:
      - PORTAL_TRUSTED_PROXIES=172.20.0.10
```

Separate multiple proxies with commas. Do not use all private ranges (`10.0.0.0/8`, `172.16.0.0/12`, or `192.168.0.0/16`); prefer the fixed IP of Nginx Proxy Manager, Traefik, or Cloudflare Tunnel. The proxy must overwrite or correctly append `X-Forwarded-For` and `X-Forwarded-Proto`. Leave this variable unset for direct access.

The image does not bundle third-party icons. Mount your own directory at `/var/www/html/icons`, use a file name from that volume, or enter an icon URL.

---

## ⚙️ Customization

All configuration is done directly through the admin panel interface (or by directly manipulating the SQLite database if you prefer the command line).

You can:
* Add new cards with custom icons.
* Group services by categories (e.g., Media, Monitoring, Network).
* Define internal links (for local use) and external links (via tunnels/reverse proxy) for the same service.

Service health is checked in batches and cached for 45 seconds.

To run the same validation used by CI:

```bash
docker build -t portal-dashboard:test .
docker run --rm portal-dashboard:test php tests/run.php
```

---

## 📄 License

This project is licensed under the GNU GPL v3 license. This means you are free to use, modify, and distribute the software, as long as you keep the changes under the same open-source license. See the LICENSE file for more details.

See the [changelog](CHANGELOG.md) for upgrade and migration details.

---

## 🤝 Contributions

Feedback, bug reports, and Pull Requests are extremely welcome!

1. Fork the project.
2. Create a branch for your modification (`git checkout -b feature/new-feature`).
3. Submit your changes (`git commit -am 'Add new feature'`).
4. Push the branch (`git push origin feature/new-feature`).
5. Open a Pull Request.

---

Created with ☕ by **facrf**.

---

## Monitoring, sessions and imports

Services support `auto`, `http`, `tcp` and `ntp` checks, a monitoring target separate from the card link, and accepted HTTP codes. HTTP defaults to `200-399`; use `200-399,401,403` for services that require authentication. Checks use HEAD, do not follow redirects and verify TLS certificates. HTTPS on port 8443 stays HTTPS. TCP checks connection establishment; NTP validates server mode and stratum 1–15. Automatic `udp://` targets are NTP; other UDP protocols are unsupported.

The shared cache lasts 45 seconds, including legacy endpoints. An eight-second reservation prevents duplicate checks. The browser runs at most two batches of five services concurrently. Portal request failures appear as **unknown**. Indicators update when the page loads; reload to refresh them.

Password changes revoke every session for that user, including the current one. The server enforces the configured absolute lifetime from login. Existing sessions require a new login after migration. Shorter lifetimes apply on the next request.

Imports require a preview and an explicit apply action. The preview lists valid services, skipped items and errors, expires after ten minutes, and becomes invalid when portal data changes. Applying is transactional. Native backups replace settings, categories and services, preserve accounts, and include monitoring, order and tags. JSON exports do not contain users or passwords. Back up before restoring. Heimdall and Homepage append only reviewed valid services. YAML uses the PHP `yaml` extension, supports comments, quoted values and multiline text, and rejects multiple documents, anchors, aliases and explicit tags. Limits: 5 MB, 500 categories, 5,000 services and 20,000 YAML lines.

Provided Nginx and Apache rules deny database files and their `-wal`, `-shm` and `-journal` sidecars. Apache must honor `.htaccess`. Custom servers must deny `db_data`, `tests`, `templates`, `lang` and internal PHP modules. Prefer a database path outside the public root. Icon volumes can be read-only.

Reordering reports save success and restores the previous interface order on failure. External search requires clicking a clearly labeled DuckDuckGo link. Configured external images also contact their servers.

## Development and publishing

`db.php` loads `database.php` (SQLite and migrations), `i18n.php` (translations) and `auth.php` (sessions and access). Monitoring lives in `health.php`, imports in `imports.php`, shared templates in `templates/`, and shared JavaScript in `assets/ui.js`.

CI validates pull requests. Main pushes, releases and manual runs validate before publishing GHCR images for `linux/amd64`, `linux/arm64`, `linux/arm/v7` and `linux/riscv64`. Tags: `latest` on the default branch, `sha-<full commit SHA>`, and semantic release versions. GitHub must allow Packages writes with `GITHUB_TOKEN`. Mirrored repositories trigger publication once the mirror reaches GitHub.

Run the PHP and JavaScript checks and the HTTP integration suite as documented in [README.md](README.md#desenvolvimento-e-publicação). The HTTP suite must target a disposable container with an empty database: it creates accounts, changes passwords and restores test services. The PHP suite removes its temporary database automatically.
