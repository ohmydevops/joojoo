FROM php:8.4-cli-alpine

# Install build dependencies for PHP extensions
RUN apk add --no-cache linux-headers
RUN docker-php-ext-install sockets pcntl

ENV BASE_WEB_DIR "/html/"

COPY server.php /joojoo
RUN chmod +x /joojoo

EXPOSE 8000

ENTRYPOINT ["php", "/joojoo"]
