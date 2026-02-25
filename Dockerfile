FROM php:8.0-cli-alpine

RUN docker-php-ext-configure pcntl --enable-pcntl
RUN docker-php-ext-install sockets
RUN docker-php-ext-install pcntl

ENV BASE_WEB_DIR "/html/"

COPY server.php /joojoo
RUN chmod +x /joojoo

EXPOSE 8000

ENTRYPOINT ["php", "/joojoo"]
