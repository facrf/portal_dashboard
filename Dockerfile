FROM php:8.3-apache

# Ativa o mod_rewrite do Apache
RUN a2enmod rewrite

# Copia os arquivos do projeto para o container
COPY . /var/www/html/

# Cria um backup dos ícones padrão dentro do container antes de qualquer mapeamento
RUN mkdir -p /var/www/html/icons_default && \
    cp -R /var/www/html/icons/. /var/www/html/icons_default/ 2>/dev/null || true

# Cria as pastas necessárias e define permissões iniciais
RUN mkdir -p /var/www/html/database /var/www/html/icons && \
    chown -R www-data:www-data /var/www/html

# Copia o script de inicialização e dá permissão de execução
COPY entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

CMD ["apache2-foreground"]