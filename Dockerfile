FROM php:8.3-apache

# Ativa o mod_rewrite do Apache (útil para rotas amigáveis)
RUN a2enmod rewrite

# Copia os arquivos do projeto para o diretório padrão do Apache no container
COPY . /var/www/html/

# Garante que o Apache (usuário www-data) tenha permissão de escrita/leitura na pasta 'data'
# para criar e atualizar o banco de dados SQLite
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html

# Expõe a porta 80
EXPOSE 80