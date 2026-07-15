es_content = """# 🌐 Portal Dashboard

> Un dashboard ligero, extremadamente personalizable y enfocado en la privacidad para tu Homelab / Homeserver.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.0-777bb4.svg)](https://www.php.net/)
[![SQLite Version](https://img.shields.io/badge/SQLite-3-003b57.svg)](https://www.sqlite.org/)

El **Portal Dashboard** es una alternativa minimalista y segura a herramientas como Heimdall y Homepage. Está diseñado para quienes desean centralizar los accesos de su servidor doméstico sin renunciar al control total sobre sus datos.

---

## 🎯 Filosofía del Proyecto

* **Cero Telemetría:** Sin rastreadores, sin pingbacks, sin análisis de datos externo. Lo que pasa en tu servidor, se queda en tu servidor.
* **100% Local:** Dependencia cero de APIs o nubes de terceros.
* **Máxima Eficiencia:** Construido puramente con **PHP** y **SQLite**, consumiendo el mínimo de hardware posible (ideal para ejecutar en mini PCs, portátiles antiguos o Raspberry Pi).
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
* **Nginx**
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

Saída de código
Files generated successfully.

```bash
   git clone [https://github.com/facrf/portal_dashboard.git](https://github.com/facrf/portal_dashboard.git)
   cd portal_dashboard
```
Consejos: Configurar Permisos:
Asegúrate de que el usuario del servidor web (por ejemplo, www-data) tenga permisos de lectura y escritura en la carpeta del proyecto para manipular la base de datos SQLite:

```
sudo chown -R www-data:www-data /ruta/a/portal_dashboard
```

🐳 Instalación con Docker Compose (Recomendado)
Dado que la imagen de Portal Dashboard se compila automáticamente y se aloja en GitHub Container Registry (GHCR), no necesitas clonar este repositorio para ejecutar el proyecto en tu servidor. Es compatible con amd64/arm64/arm32v7.

Crea un archivo llamado docker-compose.yml (o crea una nueva Stack en tu Portainer).

Pega el siguiente contenido:
```
version: '3.8'

services:
  portal-dashboard:
    image: ghcr.io/facrf/portal_dashboard:latest
    container_name: portal_dashboard
    ports:
      - "8080:80" # Puerto donde el panel estará accesible (cámbialo si es necesario)
    volumes:
      # Mapea la carpeta donde se guardará tu base de datos SQLite de forma persistente
      - /ruta/en/tu/servidor/database:/var/www/html/database
      # Mapea los iconos personalizados .png, .jpg, etc.
      - /ruta/en/tu/servidor/icons:/var/www/html/icons
    restart: unless-stopped

```

⚙️ Personalización
Toda la configuración se realiza directamente a través de la interfaz del panel de administración (o manipulando directamente la base de datos SQLite si prefieres la línea de comandos).

Puedes:

Añadir nuevas tarjetas con iconos personalizados.

Agrupar servicios por categorías (por ejemplo, Multimedia, Monitoreo, Red).

Definir enlaces internos (para uso local) y externos (mediante túneles/proxy inverso) para el mismo servicio.

📄 Licencia
Este proyecto está bajo la licencia GNU GPL v3. Esto significa que eres libre de usar, modificar y distribuir el software, siempre que mantengas los cambios bajo la misma licencia de código abierto. Consulta el archivo LICENSE para más detalles.

🤝 Contribuciones
¡Los comentarios, informes de errores y Pull Requests son sumamente bienvenidos!

Haz un Fork del proyecto.

Crea una rama para tu modificación (git checkout -b feature/nueva-caracteristica).

Envía tus cambios (git commit -am 'Añade nueva característica').

Sube la rama (git push origin feature/nueva-caracteristica).

Abre un Pull Request.

Creado con ☕ por facrf.
"""