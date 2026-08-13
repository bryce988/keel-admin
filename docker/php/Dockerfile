FROM php:8.2-cli-alpine

# 可选的国内镜像源：网络受限时传入可大幅加速构建
#   docker compose build --build-arg APK_MIRROR=mirrors.aliyun.com \
#                        --build-arg COMPOSER_MIRROR=https://mirrors.aliyun.com/composer/
# 不传则使用官方源，保持开源项目对海外用户的默认行为
ARG APK_MIRROR=""
ARG COMPOSER_MIRROR=""

RUN if [ -n "$APK_MIRROR" ]; then \
      sed -i "s|dl-cdn.alpinelinux.org|$APK_MIRROR|g" /etc/apk/repositories; \
    fi

# webman 依赖 pcntl；数据库用 pdo_mysql
# Redis 走 predis（纯 PHP 客户端），不编译 phpredis 扩展：
#   构建更快、不依赖 pecl.php.net，生产环境如需极致性能可自行加装 phpredis
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && apk add --no-cache git unzip \
    && docker-php-ext-install -j"$(nproc)" pcntl pdo_mysql sockets \
    && apk del .build-deps \
    && rm -rf /tmp/pear /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN if [ -n "$COMPOSER_MIRROR" ]; then \
      composer config -g repos.packagist composer "$COMPOSER_MIRROR"; \
    fi

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /app
EXPOSE 8787
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
