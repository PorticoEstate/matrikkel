# Use PHP 8.2 with Apache
FROM php:8.2-cli

# Set proxy environment variables if needed
ARG HTTP_PROXY
ARG HTTPS_PROXY
ARG NO_PROXY
ENV HTTP_PROXY=${HTTP_PROXY}
ENV HTTPS_PROXY=${HTTPS_PROXY}
ENV NO_PROXY=${NO_PROXY}

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    libicu-dev \
    libssl-dev \
    ca-certificates \
    zip \
    unzip \
    sqlite3 \
    libsqlite3-dev

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_sqlite \
    pdo_pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    soap \
    ctype \
    iconv \
    intl \
    sysvsem \
    sysvshm \
    sysvmsg

# Configure PHP settings for stream wrappers and SSL
RUN echo "allow_url_fopen = On" >> /usr/local/etc/php/php.ini \
    && echo "allow_url_include = Off" >> /usr/local/etc/php/php.ini \
    && echo "user_agent = 'Mozilla/5.0 (compatible; Matrikkel-Client/1.0)'" >> /usr/local/etc/php/php.ini \
    && echo "default_socket_timeout = 300" >> /usr/local/etc/php/php.ini \
    && echo "openssl.cafile = /etc/ssl/certs/ca-certificates.crt" >> /usr/local/etc/php/php.ini

# Update CA certificates for SSL connections
RUN update-ca-certificates

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy existing application directory contents
COPY . /var/www/html

# Copy existing application directory permissions
COPY --chown=www-data:www-data . /var/www/html

# Create required directories
RUN mkdir -p /var/www/html/var/cache /var/www/html/var/log \
    && chown -R www-data:www-data /var/www/html/var \
    && chmod -R 775 /var/www/html/var

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Expose port 8000
EXPOSE 8000

# Start PHP built-in server
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]