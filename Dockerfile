FROM php:8.4-cli
WORKDIR /app
COPY . .
EXPOSE 10000
CMD ["sh", "-c", "php db/seed.php --reset && php -S 0.0.0.0:${PORT:-10000} -t public"]
