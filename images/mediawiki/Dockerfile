# Resolve and supply the multi-architecture digest before building for release.
# Example: --build-arg MEDIAWIKI_BASE=mediawiki:1.43.9@sha256:...
ARG MEDIAWIKI_BASE
FROM ${MEDIAWIKI_BASE}
# Keep every libpq connection on the authenticated login, which owns MediaWiki tables.
# The operator otherwise defaults connections to its database group role.
ENV PGOPTIONS="-c role=none"
USER root
RUN test "$MEDIAWIKI_VERSION" = "1.43.9" \
    && apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev ghostscript poppler-utils restic util-linux unzip \
    && docker-php-ext-install pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && test -f skins/Vector/skin.json \
    && test -f skins/MinervaNeue/skin.json \
    && test -f extensions/VisualEditor/extension.json \
    && test -f extensions/TemplateData/extension.json \
    && test -f extensions/PdfHandler/extension.json \
    && a2dissite 000-default \
    && a2disconf short-url \
    && a2enmod rewrite headers \
    && sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && mkdir -p /var/run/apache2 /var/lock/apache2 /data/uploads /data/control \
    && chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /data \
    && printf 'ok\n' > /var/www/html/healthz
# MobileFrontend is not bundled; use the branch matching MediaWiki 1.43.
ADD https://github.com/wikimedia/mediawiki-extensions-MobileFrontend/archive/refs/heads/REL1_43.tar.gz /tmp/mobilefrontend.tar.gz
RUN mkdir -p extensions/MobileFrontend \
    && tar -xzf /tmp/mobilefrontend.tar.gz --strip-components=1 -C extensions/MobileFrontend \
    && rm /tmp/mobilefrontend.tar.gz \
    && test -f extensions/MobileFrontend/extension.json
# Widgets has a separate Composer dependency (Smarty), not bundled by MediaWiki.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ADD https://github.com/wikimedia/mediawiki-extensions-Widgets/archive/refs/tags/1.7.1.tar.gz /tmp/widgets.tar.gz
# The release has no lock file; omit development constraints before resolving
# production dependencies, while preserving Composer's security checks.
RUN mkdir -p extensions/Widgets \
    && tar -xzf /tmp/widgets.tar.gz --strip-components=1 -C extensions/Widgets \
    && rm /tmp/widgets.tar.gz \
    && php -r '$p = "extensions/Widgets/composer.json"; $c = json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR); unset($c["require-dev"]); file_put_contents($p, json_encode($c, JSON_THROW_ON_ERROR));' \
    && composer install --working-dir=extensions/Widgets --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist --optimize-autoloader \
    && test -f extensions/Widgets/extension.json \
    && test -f extensions/Widgets/vendor/autoload.php \
    && mkdir -p /tmp/mediawiki-widgets \
    && chown www-data:www-data /tmp/mediawiki-widgets
COPY apache.conf /etc/apache2/sites-enabled/mediawiki.conf
COPY php.ini /usr/local/etc/php/conf.d/mediawiki.ini
COPY initialize.php backup.sh /opt/mediawiki/
RUN php -l /opt/mediawiki/initialize.php && sh -n /opt/mediawiki/backup.sh
# LocalSettings.php is mounted by Kubernetes. The installation Job deliberately
# does not mount it at the document root because install.php must start without it.
USER www-data
EXPOSE 8080
CMD ["apache2-foreground"]
