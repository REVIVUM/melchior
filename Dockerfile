FROM php:8.2-fpm-alpine

# Dependências do sistema
RUN apk add --no-cache nginx supervisor curl

# Composer a partir da imagem oficial
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Extensões PHP
RUN docker-php-ext-install opcache

# Configuração do Supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Configuração do Nginx
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

WORKDIR /var/www/html

# Instalar dependências (camada cacheável)
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copiar aplicação
COPY . .

# Permissões e diretórios
RUN mkdir -p /run/nginx \
    && chown -R www-data:www-data /var/www/html

EXPOSE 8080

CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
