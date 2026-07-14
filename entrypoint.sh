#!/bin/sh
set -e

# Garante que a pasta 'database' (mesmo que tenha sido montada pelo Docker como root)
# passe a pertencer ao usuário do Apache (www-data)
chown -R www-data:www-data /var/www/html/database

# Executa o comando padrão do container (que é iniciar o Apache)
exec "$@"