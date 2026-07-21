# 🌐 Portal Dashboard

> Un dashboard ligero, extremadamente personalizable y enfocado en la privacidad para tu Homelab / Homeserver.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.0-777bb4.svg)](https://www.php.net/)
[![SQLite Version](https://img.shields.io/badge/SQLite-3-003b57.svg)](https://www.sqlite.org/)

El **Portal Dashboard** es una alternativa minimalista y segura a herramientas como Heimdall y Homepage. Está diseñado para quienes desean centralizar los accesos de su servidor doméstico sin renunciar al control total sobre sus datos.

---

## 🎯 Filosofía del Proyecto

* **Cero Telemetría incorporada:** Sin rastreadores, sin pingbacks, sin análisis de datos externo. Lo que pasa en tu servidor, se queda en tu servidor.
* **100% Local:** Dependencia cero de APIs o nubes de terceros.
* **Máxima Eficiencia:** Construído puramente con **PHP** y **SQLite**, consumiendo el mínimo de hardware posible (ideal para ejecutar en mini PCs, portátiles antiguos o Raspberry Pi).
* **Libertad de Personalización:** Configura y organiza tus servicios de forma simple y directa.

---

## ✨ Características

* 🚀 **Inicio Instantáneo:** Sin bases de datos pesadas ni procesos de configuración complejos.
* 📱 **Diseño Responsivo:** Accede y gestiona tu homelab perfectamente desde tu ordenador, tableta o móvil.
* 💾 **Persistencia Simple:** Configuraciones almacenadas localmente en una base de datos SQLite de un solo archivo.
* 🎨 **Altamente Personalizable:** Crea categorías, añade enlaces de servicios y organiza el diseño según tus necesidades.

---

## 🛠️ Tecnologías Utilizadas

* **Backend:** PHP (8.0+)
* **Servidor Web:** Nginx
* **Base de Datos:** SQLite 3
* **Licencia:** GNU GPL v3

---

## 🚀 Cómo Instalar

Puedes ejecutar Portal Dashboard directamente en tu servidor web preferido o mediante Docker.

### Método 1: Servidor Web Local (Apache / Nginx)

1. **Requisitos Previos:**
   * Servidor Web (Apache, Nginx, etc.)
   * PHP 8.0 o superior instalado.
   * Extensión `php-sqlite3` habilitada.

2. **Clonar el Repositorio:**
   ```bash
   git clone https://github.com/facrf/portal_dashboard.git
   cd portal_dashboard
   ```

3. **Configurar Permisos:**
   Asegúrate de que el usuario del servidor web (por ejemplo, `www-data`) tenga permisos de lectura y escritura en la carpeta del proyecto para manipular la base de datos SQLite:
   ```bash
   sudo chown -R www-data:www-data /ruta/a/portal_dashboard
   ```

4. **Acceder en el Navegador:**
   Accede a `http://localhost/portal_dashboard` (o la IP de tu servidor).

---

### 🐳 Instalación con Docker Compose (Recomendado)

Dado que la imagen de **Portal Dashboard** se compila automáticamente y se aloja en GitHub Container Registry (GHCR), no necesitas clonar este repositorio para ejecutar el proyecto en tu servidor. Es compatible con `amd64`, `arm64` y `arm32v7`.

1. Crea un archivo llamado `docker-compose.yml` (o crea una nueva **Stack** en tu Portainer).
2. Pega el siguiente contenido:

```yaml
version: '3.8'

services:
  portal-dashboard:
    image: ghcr.io/facrf/portal_dashboard:latest
    container_name: portal_dashboard
    ports:
      - "8080:80" # Puerto donde el panel estará accesible (cámbialo si es necesario)
    volumes:
      # Mapeo seguro para la base de datos SQLite
      - /tu_ruta/banco_portal:/var/www/db_data
      # Mapeo para los iconos
      - /tu_ruta/icons:/var/www/html/icons
    restart: unless-stopped
```

Si el portal está detrás de un proxy inverso, confía únicamente en la IP (o un CIDR restringido) de ese proxy:

```yaml
    environment:
      - PORTAL_TRUSTED_PROXIES=172.20.0.10
```

Separa varios proxies con comas. No utilices todos los rangos privados (`10.0.0.0/8`, `172.16.0.0/12` o `192.168.0.0/16`); prefiere la IP fija de Nginx Proxy Manager, Traefik o Cloudflare Tunnel. El proxy debe sobrescribir o añadir correctamente `X-Forwarded-For` y `X-Forwarded-Proto`. En acceso directo, deja la variable sin definir.

---

## ⚙️ Personalización

Toda la configuración se realiza directamente a través de la interfaz del panel de administración (o manipulando directamente la base de datos SQLite si prefieres la línea de comandos).

Puedes:
* Añadir nuevas tarjetas con iconos personalizados.
* Agrupar servicios por categorías (por ejemplo, Multimedia, Monitoreo, Red).
* Definir enlaces internos (para uso local) y externos (mediante túneles/proxy inverso) para el mismo servicio.

---

## 📄 Licencia

Este proyecto está bajo la licencia GNU GPL v3. Esto significa que eres libre de usar, modificar y distribuir el software, siempre que mantengas los cambios bajo la misma licencia de código abierto. Consulta el archivo LICENSE para más detalles.

---

## 🤝 Contribuciones

¡Los comentarios, informes de errores y Pull Requests son sumamente bienvenidos!

1. Haz un Fork del proyecto.
2. Crea una rama para tu modificación (`git checkout -b feature/nueva-caracteristica`).
3. Envía tus cambios (`git commit -am 'Añade nueva característica'`).
4. Sube la rama (`git push origin feature/nueva-caracteristica`).
5. Abre un Pull Request.

---

Creado con ☕ por **facrf**.
