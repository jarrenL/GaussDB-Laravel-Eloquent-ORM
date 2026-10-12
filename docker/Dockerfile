ARG PHP_BASE_IMAGE=gaussdb-php:8.3-arm64-odbc
FROM ${PHP_BASE_IMAGE}

# Eloquent test runtime: composer + unzip for dist installs.
# Base image already provides PHP 8.3 + PDO_ODBC + the official GaussDB ODBC driver.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --quiet \
    && composer --version \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /workspace
