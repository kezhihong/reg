#!/bin/sh
# backend 容器启动脚本（docs/05 §2.1 初始化顺序）
set -e

echo "[entrypoint] waiting for mysql..."
i=0
until php -r '
    try {
        new PDO(
            sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
                getenv("MYSQL_HOST"), getenv("MYSQL_PORT") ?: "3306", getenv("MYSQL_DATABASE")),
            getenv("MYSQL_USER"), getenv("MYSQL_PASSWORD")
        );
        echo "mysql ready\n";
        exit(0);
    } catch (Throwable $e) {
        exit(1);
    }
' 2>/dev/null; do
    i=$((i+1))
    if [ "$i" -ge 60 ]; then
        echo "[entrypoint] mysql not ready after 120s, abort"
        exit 1
    fi
    sleep 2
done

echo "[entrypoint] running migrate..."
php bin/hyperf.php migrate

echo "[entrypoint] running seed..."
php bin/hyperf.php db:seed

echo "[entrypoint] starting swoole server..."
exec php bin/hyperf.php start
