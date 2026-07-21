# 🌐 Portal Dashboard

> A lightweight, extremely customizable, and privacy-focused dashboard for your Homelab / Homeserver.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.0-777bb4.svg)](https://www.php.net/)
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
* 📱 **Responsive Design:** Access and manage your homelab perfectly from your computer, tablet, or phone.
* 💾 **Simple Persistence:** Settings stored locally in a single-file SQLite database.
* 🎨 **Highly Customizable:** Create categories, add service links, and organize the layout according to your needs.

---

## 🛠️ Technologies Used

* **Backend:** PHP (8.0+)
* **Web Server:** Nginx
* **Database:** SQLite 3
* **License:** GNU GPL v3

---

## 🚀 How to Install

You can run Portal Dashboard directly on your preferred web server or via Docker.

### Method 1: Local Web Server (Apache / Nginx)

1. **Prerequisites:**
   * Web Server (Apache, Nginx, etc.)
   * PHP 8.0 or higher installed.
   * `php-sqlite3` extension enabled.

2. **Clone the Repository:**
   ```bash
   git clone https://github.com/facrf/portal_dashboard.git
   cd portal_dashboard
   ```

3. **Configure Permissions:**
   Make sure the web server user (e.g., `www-data`) has read and write permissions in the project folder to manipulate the SQLite database:
   ```bash
   sudo chown -R www-data:www-data /path/to/portal_dashboard
   ```

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

---

## ⚙️ Customization

All configuration is done directly through the admin panel interface (or by directly manipulating the SQLite database if you prefer the command line).

You can:
* Add new cards with custom icons.
* Group services by categories (e.g., Media, Monitoring, Network).
* Define internal links (for local use) and external links (via tunnels/reverse proxy) for the same service.

---

## 📄 License

This project is licensed under the GNU GPL v3 license. This means you are free to use, modify, and distribute the software, as long as you keep the changes under the same open-source license. See the LICENSE file for more details.

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
