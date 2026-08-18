# backend 镜像（docs/05 §2.1）：官方 hyperf-swoole 镜像 + 启动执行 migration + seed
FROM hyperf/hyperf:8.2-alpine-v3.19-swoole

LABEL maintainer="mem-reg"

ARG APP_ENV=dev
ENV APP_ENV=${APP_ENV}

WORKDIR /opt/www

# 依赖与源码（国内镜像源 + BuildKit 缓存挂载 + 重试：codeload 间歇性 429，
# 缓存使每轮仅重试失败的包，避免整轮重下；缓存挂载不进入镜像层）
COPY composer.json composer.lock* ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/ && \
    for i in 1 2 3 4 5 6 7 8; do \
      composer install --no-dev --no-scripts --no-autoloader --ignore-platform-reqs --prefer-dist \
        && break \
        || { echo "== composer install 第 $i 次失败，20s 后重试 =="; sleep 20; }; \
    done && \
    test -d vendor && test -f vendor/autoload.php && echo "== composer install OK =="

COPY . .
RUN composer dump-autoload --optimize --no-scripts && \
    composer run post-autoload-dump || true

# 启动脚本：wait-for mysql → migrate → seed → Swoole Server（docs/05 §2.1）
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 9501

ENTRYPOINT ["docker-entrypoint.sh"]
