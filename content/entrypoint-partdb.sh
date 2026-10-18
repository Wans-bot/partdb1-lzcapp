#!/bin/bash
# Part-DB 应用容器入口（替代 setup_script：manifest 里 setup_script 与 entrypoint/command 互斥）。
#
# 做两件事：
#   1) 建好持久化目录并修属主（/lzcapp/var 挂进来默认属主是 root，而应用以 www-data 跑）；
#   2) 后台等数据库迁移建完默认管理员后，把它标记为「无需强制改密」——
#      Part-DB 首次建库会给 admin 置 need_pw_change=1，用户一登录就被 PasswordChangeNeededSubscriber
#      强制跳到「用户设置」页，进不了仪表盘（审核第一眼就是「打开的页面不是应用本体」）。
#      我们安装时已经让用户填过管理员密码，强制改密在这里是多余的。
set -u

mkdir -p /var/www/html/var /var/www/html/uploads
# The upstream entrypoint creates SQLite before dropping to www-data. Pre-create
# it with the application owner so the first migration can write to it.
if [ ! -e /var/www/html/uploads/app.db ]; then
  install -o 33 -g 33 -m 0660 /dev/null /var/www/html/uploads/app.db
fi
chown -R 33:33 /var/www/html/var /var/www/html/uploads 2>/dev/null || true
chmod 0660 /var/www/html/uploads/app.db 2>/dev/null || true

(
  i=0
  while [ "$i" -lt 120 ]; do
    if php /lzcapp/pkg/content/fix-admin.php >/dev/null 2>&1; then
      break
    fi
    i=$((i + 1))
    sleep 5
  done
) &

# 保留镜像原本的启动逻辑（等库 → 迁移 → apache）
exec /usr/local/bin/partdb-entrypoint.sh /usr/local/bin/apache2-foreground
