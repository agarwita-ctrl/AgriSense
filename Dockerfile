FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2dismod -f mpm_event mpm_worker || true \
    && a2enmod mpm_prefork rewrite headers expires remoteip

# The app relies on its .htaccess files (routing + access rules).
RUN { \
      echo '<Directory /var/www/html>'; \
      echo '    AllowOverride All'; \
      echo '    Require all granted'; \
      echo '</Directory>'; \
      echo 'ServerName localhost'; \
      echo 'ServerTokens Prod'; \
      echo 'ServerSignature Off'; \
      echo 'RemoteIPHeader X-Forwarded-For'; \
      echo 'RemoteIPInternalProxy 10.0.0.0/8 100.64.0.0/10 172.16.0.0/12 fd00::/8'; \
    } > /etc/apache2/conf-available/agrisense.conf \
    && a2enconf agrisense \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
