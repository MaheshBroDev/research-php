FROM php:8.1-fpm-alpine

# Install system dependencies
RUN apk update && apk add --no-cache \
    libzip-dev \
    zip \
    unzip

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql zip

# Set working directory
WORKDIR /app

# Copy application files
COPY . /app

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Database initialization
# ARG DB_USER
# ARG DB_PASS
# ARG DB_NAME
# RUN echo "CREATE TABLE users ( \
#     id INT AUTO_INCREMENT PRIMARY KEY, \
#     username VARCHAR(50) UNIQUE NOT NULL, \
#     password VARCHAR(255) NOT NULL, \
#     token VARCHAR(255) UNIQUE NOT NULL \
# ); \
# \
# CREATE TABLE items ( \
#     id INT AUTO_INCREMENT PRIMARY KEY, \
#     name VARCHAR(100) NOT NULL, \
#     value TEXT NOT NULL \
# ); \
# \
# INSERT INTO users (username, password, token)  \
# VALUES ('admin', 'password123', 'test123');" | mysql -u ${DB_USER} -p${DB_PASS} -h localhost ${DB_NAME}

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Run the PHP application
CMD ["php", "-S", "0.0.0.0:9000", "app.php"]
