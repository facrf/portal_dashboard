#!/bin/sh
set -e

# Cria os diretórios persistentes. A imagem não embute a coleção opcional de
# ícones: use URLs nos serviços ou monte sua própria pasta em /var/www/html/icons.
PORTAL_DB_PATH="${PORTAL_DB_PATH:-/var/www/db_data/bd.db}"
PORTAL_DB_DIR="$(dirname "$PORTAL_DB_PATH")"
mkdir -p "$PORTAL_DB_DIR" /var/www/html/icons

# Garante permissões para o processo PHP-FPM (www-data).
# Ignora falhas caso o diretório esteja em montagem de rede (NFS/CIFS/SMB) sem permissão de chown.
chown -R www-data:www-data "$PORTAL_DB_DIR" 2>/dev/null || true
# Ícones são apenas lidos pela aplicação; volumes podem ser montados como somente leitura.

exec "$@"
