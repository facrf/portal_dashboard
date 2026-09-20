# Imagem Alpine com PHP-FPM.
FROM php:8.3-fpm-alpine

ENV PORTAL_DB_PATH=/var/www/db_data/bd.db

# Instala Nginx, Supervisor e SQLite.
RUN apk add --no-cache nginx supervisor sqlite-dev ca-certificates && \
    docker-php-ext-install pdo pdo_sqlite

# Configura os processos da imagem.
COPY nginx.conf /etc/nginx/nginx.conf
COPY supervisord.conf /etc/supervisord.conf
COPY php-security.ini /usr/local/etc/php/conf.d/zz-portal-security.ini

# Copia os arquivos do projeto.
# Por padrão, estes arquivos pertencerão ao root:root
COPY . /var/www/html/
WORKDIR /var/www/html/

# Cria as pastas persistentes.
RUN mkdir -p /var/www/db_data /var/www/html/icons

# Instala o entrypoint.
COPY entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh

# Expõe a porta HTTP.
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1/ || exit 1

# Define o entrypoint.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# Inicia Nginx e PHP-FPM pelo Supervisor.
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
