# Usamos a imagem Alpine com PHP-FPM (extremamente leve)
FROM php:8.3-fpm-alpine

# Instala o Nginx, Supervisor e as bibliotecas do SQLite
RUN apk add --no-cache nginx supervisor sqlite-dev && \
    docker-php-ext-install pdo pdo_sqlite

# Copia as configurações do Nginx e do Supervisor para dentro do Linux
COPY nginx.conf /etc/nginx/nginx.conf
COPY supervisord.conf /etc/supervisord.conf

# Copia os arquivos do projeto
COPY . /var/www/html/
WORKDIR /var/www/html/

# Backup dos ícones (para o nosso entrypoint inteligente)
RUN mkdir -p /var/www/html/icons_default && \
    cp -R /var/www/html/icons/. /var/www/html/icons_default/ 2>/dev/null || true

# Cria pastas e ajusta permissões 
# (Curiosidade: A imagem oficial do PHP-Alpine mantém o usuário www-data!)
RUN mkdir -p /var/www/html/database /var/www/html/icons && \
    chown -R www-data:www-data /var/www/html

# Copia e dá permissão ao Entrypoint
COPY entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh

# Expõe a porta 80
EXPOSE 80

# Define o entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# O comando final agora não é mais o Apache, e sim o Supervisor!
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]