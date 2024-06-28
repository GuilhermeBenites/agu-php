# Usando a imagem oficial do PHP 8.1
FROM php:8.1-fpm

# Instalar extensões necessárias
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql zip

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar o diretório de trabalho
WORKDIR /var/www/html

# Copiar arquivos da aplicação
COPY . .

# Instalar dependências do Symfony
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer config --no-plugins allow-plugins.symfony/flex true
RUN composer install

# Expor a porta
EXPOSE 9000

# Comando para iniciar o servidor
CMD ["php-fpm"]
