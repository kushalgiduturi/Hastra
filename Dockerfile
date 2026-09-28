# Hastra — production image: PHP 8.2 + Apache.
#
# Apache (not Nginx) on purpose: the app's security boundary lives in its
# .htaccess files — they keep config/*.key (the encryption keys), tools/,
# tests/, backups and include-only partials off the web. Nginx ignores
# .htaccess, so serving this tree with Nginx would publish all of them.
#
# Built and run by docker-compose.yml (MariaDB + Caddy for automatic HTTPS).
# See DEPLOY.md.
FROM php:8.2-apache

# mysqli is what the app uses (not PDO). curl, openssl, mbstring, sodium and
# Argon2 password hashing are built into the official image.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev unzip mariadb-client \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" mysqli gd zip opcache \
 && a2enmod rewrite headers expires remoteip \
 && rm -rf /var/lib/apt/lists/*

# PHP: production defaults, plus OPcache and upload limits
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { \
      echo 'expose_php = Off'; \
      echo 'memory_limit = 256M'; \
      echo 'upload_max_filesize = 64M'; \
      echo 'post_max_size = 72M'; \
      echo 'max_execution_time = 120'; \
      echo 'opcache.enable = 1'; \
      echo 'opcache.memory_consumption = 128'; \
      echo 'opcache.interned_strings_buffer = 16'; \
      echo 'opcache.max_accelerated_files = 10000'; \
      echo 'opcache.validate_timestamps = 0'; \
      echo 'session.use_strict_mode = 1'; \
    } > "$PHP_INI_DIR/conf.d/hastra.ini"

COPY docker/apache-hastra.conf /etc/apache2/conf-available/hastra.conf
RUN a2enconf hastra

# The app, served from the site root.
WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html

# Local development serves the app under /Hastra/; production serves it at
# the root. Rewrite the base path baked into .htaccess to match
# HASTRA_BASE_PATH (config.php reads the same variable at runtime).
ARG HASTRA_BASE_PATH=/
RUN sed -i "s#/Hastra/#${HASTRA_BASE_PATH}#g" .htaccess \
 && grep -q "RewriteBase ${HASTRA_BASE_PATH}" .htaccess \
 && mkdir -p /data/keys uploads \
 && chown -R www-data:www-data /data/keys uploads \
 && chmod 700 /data/keys

COPY docker/entrypoint.sh /usr/local/bin/hastra-entrypoint
# strip CRs in case the file was checked out on Windows
RUN sed -i 's/\r$//' /usr/local/bin/hastra-entrypoint && chmod +x /usr/local/bin/hastra-entrypoint

ENV HASTRA_BASE_PATH=${HASTRA_BASE_PATH}
EXPOSE 80
ENTRYPOINT ["hastra-entrypoint"]
CMD ["apache2-foreground"]
