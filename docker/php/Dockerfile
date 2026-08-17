# PHP 8.4：openspout ^5（导入导出）要求 ~8.4 || ~8.5。
# 现有依赖没有一个设了 PHP 上界（webman/workerman >=8.1、illuminate ^8.2），
# 所以直接上 8.4，而不是把 openspout 钉在 v4.28 —— 那种约束过两年没人记得为什么。
FROM php:8.4-cli-alpine

# 可选的国内镜像源：网络受限时传入可大幅加速构建
#   docker compose build --build-arg APK_MIRROR=mirrors.aliyun.com \
#                        --build-arg COMPOSER_MIRROR=https://mirrors.aliyun.com/composer/
# 不传则使用官方源，保持开源项目对海外用户的默认行为
ARG APK_MIRROR=""
ARG COMPOSER_MIRROR=""

RUN if [ -n "$APK_MIRROR" ]; then \
      sed -i "s|dl-cdn.alpinelinux.org|$APK_MIRROR|g" /etc/apk/repositories; \
    fi

# webman 依赖 pcntl；数据库用 pdo_mysql；zip 给 openspout 读写 xlsx/ods
#   （xlsx 与 ods 本质是 zip 包，纯 CSV 用不到，但 composer 强制要求该扩展）
# Redis 走 predis（纯 PHP 客户端），不编译 phpredis 扩展：
#   构建更快、不依赖 pecl.php.net，生产环境如需极致性能可自行加装 phpredis
#
# libzip-dev 只在编译时需要，libzip 是运行时的动态库，删了扩展就加载不起来
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers libzip-dev \
    && apk add --no-cache git unzip libzip \
    && docker-php-ext-install -j"$(nproc)" pcntl pdo_mysql sockets zip \
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
