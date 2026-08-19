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
#
# phpredis 是**必需**的，不是性能优化：`webman/redis-queue` 的 RedisConnection
#   直接 `extends \Redis`，没有扩展连投递消息都会致命错误。
#   缓存那边仍然走 predis（`app\common\support\Cache`），两者并存没有冲突——
#   缓存的连接是我们自己管的，队列的连接归插件管。
#   曾经因为「pecl 拉不到包」而放弃过 phpredis，现在能装上了；
#   若哪天 pecl.php.net 又不可达，可改用 `--build-arg PECL_MIRROR` 或临时锁版本。
#
# 不要用 apk 的 php84-pecl-redis：那是给 Alpine 自带的 PHP 编的，
#   与官方 php:8.4-cli-alpine 镜像的扩展目录和构建参数都不一致，装上也加载不了
#
# libzip-dev 只在编译时需要，libzip 是运行时的动态库，删了扩展就加载不起来
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers libzip-dev \
    && apk add --no-cache git unzip libzip \
    && docker-php-ext-install -j"$(nproc)" pcntl pdo_mysql sockets zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
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
