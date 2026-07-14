FROM php:8.3-apache

RUN a2enmod rewrite

COPY . /var/www/html/

RUN mkdir -p /var/www/html/database && chown -R www-data:www-data /var/www/html

COPY entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh

# Mudança aqui: usando o caminho completo para o script
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

CMD ["apache2-foreground"]