🌐 Portal Dashboard

A lightweight, extremely customizable, and privacy-focused dashboard for your Homelab / Homeserver.

Portal Dashboard is a minimalist and secure alternative to tools like Heimdall and Homepage. It is designed for those who want to centralize access to their home server without giving up complete control over their data.

🎯 Project Philosophy
Zero Telemetry: No trackers, no pingbacks, no external data analytics. What happens on your server, stays on your server.

100% Local: Zero dependency on third-party APIs or clouds.

Maximum Efficiency: Built purely with PHP and SQLite, consuming the minimum possible hardware (ideal for running on mini PCs, old laptops, or Raspberry Pi).

Freedom of Customization: Configure and organize your services simply and directly.

✨ Features
🚀 Instant Startup: No heavy databases or complex setup processes.

📱 Responsive Design: Access and manage your homelab perfectly from your computer, tablet, or phone.

💾 Simple Persistence: Settings stored locally in a single-file SQLite database.

🎨 Highly Customizable: Create categories, add service links, and organize the layout according to your needs.

🛠️ Technologies Used
Backend: PHP (8.0+)

Nginx

Database: SQLite 3

License: GNU GPL v3

🚀 How to Install
You can run Portal Dashboard directly on your preferred web server or via Docker.

Method 1: Local Web Server (Apache / Nginx)
Prerequisites:

Web Server (Apache, Nginx, etc.)

PHP 8.0 or higher installed.

php-sqlite3 extension enabled.

2 Clone the Repository:

```
git clone [https://github.com/facrf/portal_dashboard.git](https://github.com/facrf/portal_dashboard.git)
cd portal_dashboard
```

3 Tips: Configure Permissions:
Make sure the web server user (e.g., www-data) has read and write permissions in the project folder to manipulate the SQLite database:
```
sudo chown -R www-data:www-data /path/to/portal_dashboard
```

Access through your browser: http://localhost/portal_dashboard (or your server's IP).

🐳 Installation with Docker Compose (Recommended)
Since the Portal Dashboard image is automatically built and hosted on GitHub Container Registry (GHCR), you do not need to clone this repository to run the project on your server. It supports amd64/arm64/arm32v7.

1 - Create a file named docker-compose.yml (or create a new Stack in your Portainer).

2 - Paste the following content:

```
version: '3.8'

services:
  portal-dashboard:
    image: ghcr.io/facrf/portal_dashboard:latest
    container_name: portal_dashboard
    ports:
      - "8080:80" # Port where the dashboard will be accessible (change if necessary)
    volumes:
      # Map the folder where your SQLite database will be saved persistently
      - /path/on/your/server/database:/var/www/database
      # Map custom icons .png, .jpg, etc.
      - /path/on/your/server/icons:/var/www/html/icons
    restart: unless-stopped
```

⚙️ Customization
All configuration is done directly through the admin panel interface (or by directly manipulating the SQLite database if you prefer the command line).

You can:

Add new cards with custom icons.

Group services by categories (e.g., Media, Monitoring, Network).

Define internal links (for local use) and external links (via tunnels/reverse proxy) for the same service.

📄 License
This project is licensed under the GNU GPL v3 license. This means you are free to use, modify, and distribute the software, as long as you keep the changes under the same open-source license. See the LICENSE file for more details.

🤝 Contributions
Feedback, bug reports, and Pull Requests are extremely welcome!

Fork the project.

Create a branch for your modification (git checkout -b feature/new-feature).

Submit your changes (git commit -am 'Add new feature').

Push the branch (git push origin feature/new-feature).

Open a Pull Request.

Created with ☕ by facrf.
