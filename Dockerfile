FROM php:8.1-cli

RUN apt-get update \
  && apt-get install -y --no-install-recommends libzip-dev unzip git libpng-dev \
  && docker-php-ext-install pdo_mysql zip \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html
RUN mkdir -p /var/www/html/uploads/driver-cvs && chown -R www-data:www-data /var/www/html/uploads

EXPOSE 80
CMD ["php", "-S", "0.0.0.0:80", "-t", "."]
