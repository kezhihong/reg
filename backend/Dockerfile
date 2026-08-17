# backend 镜像（docs/05 §2.1）：官方 hyperf-swoole 镜像 + 启动执行 migration + seed
FROM hyperf/hyperf:8.2-alpine-v3.19-swoole

LABEL maintainer="mem-reg"

ARG APP_ENV=dev
ENV APP_ENV=${APP_ENV}

WORKDIR /opt/www

# 依赖与源码
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --ignore-platform-reqs && \
    composer clear-cache

COPY . .
RUN composer dump-autoload --optimize --no-scripts && \
    composer run post-autoload-dump || true

# 启动脚本：wait-for mysql → migrate → seed → Swoole Server（docs/05 §2.1）
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 9501

ENTRYPOINT ["docker-entrypoint.sh"]
