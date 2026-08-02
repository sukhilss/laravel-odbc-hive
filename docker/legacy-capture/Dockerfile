FROM php:8.0-cli

RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
