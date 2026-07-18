# Usamos a imagem Alpine com PHP-FPM (extremamente leve)[cite: 1]
FROM php:8.3-fpm-alpine

# Instala o Nginx, Supervisor e as bibliotecas do SQLite[cite: 1]
RUN apk add --no-cache nginx supervisor sqlite-dev && \
    docker-php-ext-install pdo pdo_sqlite

# Copia as configurações do Nginx e do Supervisor para dentro do Linux[cite: 1]
COPY nginx.conf /etc/nginx/nginx.conf
COPY supervisord.conf /etc/supervisord.conf

# Copia os arquivos do projeto[cite: 1]
# Por padrão, estes arquivos pertencerão ao root:root
COPY . /var/www/html/
WORKDIR /var/www/html/

# Backup dos ícones (para o nosso entrypoint inteligente)[cite: 1]
RUN mkdir -p /var/www/html/icons_default && \
    cp -R /var/www/html/icons/. /var/www/html/icons_default/ 2>/dev/null || true

# Cria pastas necessárias (sem dar chown global!)[cite: 1]
RUN mkdir -p /var/www/db_data /var/www/html/icons

# Copia e dá permissão ao Entrypoint[cite: 1]
COPY entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh

# Expõe a porta 80[cite: 1]
EXPOSE 80

# Define o entrypoint[cite: 1]
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# O comando final agora não é mais o Apache, e sim o Supervisor![cite: 1]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]