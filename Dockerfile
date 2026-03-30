FROM php:8.3

# Increase PHP memory limit
RUN echo "memory_limit=3512M" > /usr/local/etc/php/conf.d/memory.ini

# Update and install dependencies
RUN apt-get update -y && apt-get install -y \
    openssl zip unzip git libonig-dev libzip-dev \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
    libpq-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip pdo_mysql pdo_pgsql

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set the working directory
WORKDIR /app

# Copy the application code
COPY . /app

# Set up the environment and update Composer dependencies
RUN cp .env.example .env \
    && composer update --no-dev --optimize-autoloader --ignore-platform-req=ext-gd \

RUN php artisan general

# Expose the application port
EXPOSE 8004

# Start the application
#CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
