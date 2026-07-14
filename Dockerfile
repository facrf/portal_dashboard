FROM php:8.3-apache

# Ativa o mod_rewrite do Apache
RUN a2enmod rewrite

# Copia os arquivos do projeto para o container
COPY . /var/www/html/

# Cria a pasta database interna e garante permissões iniciais
RUN mkdir -p /var/www/html/database && chown -R www-data:www-data /var/www/html

# Copia o script de inicialização e dá permissão de execução para ele
COPY entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh

# Define o script como o ENTRYPOINT do container
ENTRYPOINT ["entrypoint.sh"]

# Comando padrão que o entrypoint vai executar no final (iniciar o Apache)
CMD ["apache2-foreground"]