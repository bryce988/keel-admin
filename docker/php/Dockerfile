FROM php:8.2-cli-alpine

# webman 依赖 pcntl；数据库用 pdo_mysql
# Redis 走 predis（纯 PHP 客户端），不编译 phpredis 扩展：
#   构建更快、不依赖 pecl.php.net，生产环境如需极致性能可自行加装 phpredis
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && apk add --no-cache git unzip \
    && docker-php-ext-install -j"$(nproc)" pcntl pdo_mysql sockets \
    && apk del .build-deps \
    && rm -rf /tmp/pear /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 国内网络可放开下面这行加速依赖安装
# RUN composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /app
EXPOSE 8787
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
