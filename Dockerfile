# Imagem Alpine com PHP-FPM.
FROM php:8.3-fpm-alpine

ENV PORTAL_DB_PATH=/var/www/db_data/bd.db

# Instala Nginx, Supervisor e SQLite.
# A imagem oficial já fornece PDO SQLite, mbstring e curl; compile apenas YAML.
# Download fora do PECL evita o problema PHP 8.3/riscv64 com operações de rede PEAR.
ARG YAML_VERSION=2.3.0
ARG YAML_SHA256=bc8404807a3a4dc896b310af21a7f8063aa238424ff77f27eb6ffa88b5874b8a
RUN apk add --no-cache nginx supervisor ca-certificates yaml && \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS yaml-dev && \
    mkdir -p /usr/src/yaml && \
    curl --fail --show-error --location --retry 3 "https://pecl.php.net/get/yaml-${YAML_VERSION}.tgz" -o /usr/src/yaml/source.tgz && \
    echo "${YAML_SHA256}  /usr/src/yaml/source.tgz" | sha256sum -c - && \
    tar -xzf /usr/src/yaml/source.tgz -C /usr/src/yaml --strip-components=1 && \
    cd /usr/src/yaml && phpize && ./configure --with-yaml && \
    make -j"$(getconf _NPROCESSORS_ONLN)" && make install && \
    docker-php-ext-enable yaml && apk del .build-deps && \
    php -r 'foreach (["pdo_sqlite", "mbstring", "curl", "yaml"] as $extension) { if (!extension_loaded($extension)) { throw new RuntimeException("Missing PHP extension: " . $extension); } }' && \
    cd / && rm -r /usr/src/yaml


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
