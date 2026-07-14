#!/bin/sh
set -e

# 1. Se a pasta de ícones mapeada pelo usuário estiver vazia,
# copia os ícones padrão de volta para ela para não quebrar o layout
if [ -z "$(ls -A /var/www/html/icons 2>/dev/null)" ]; then
    echo "Pasta de ícones vazia. Copiando ícones padrão..."
    cp -R /var/www/html/icons_default/. /var/www/html/icons/ 2>/dev/null || true
fi

# 2. Garante permissões corretas para o Apache (www-data)
# nas pastas de persistência
chown -R www-data:www-data /var/www/html/database
chown -R www-data:www-data /var/www/html/icons

exec "$@"