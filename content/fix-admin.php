<?php
/**
 * 首次启动校正（幂等，DB 未就绪时返回非 0 由入口脚本重试）
 *
 * 做三件事：
 *   1) 把管理员账号的密码设成确定值（PARTDB_ADMIN_USER / PARTDB_ADMIN_PW）。
 *      背景：本包曾经用部署参数渲染 INITIAL_ADMIN_PW，但部署参数文件没有打进 LPK，
 *      模板 {{ index .U "admin_password" }} 渲染成字面量 "<no value>"，于是
 *      admin 的真实密码、以及自动登录注入填的账号密码全成了 "<no value>"，
 *      而库里账号名是 admin —— 审核侧表现为 "Invalid credentials" / 无法登陆。
 *      现在不再依赖部署参数：这里在**首次启动**把密码设成固定值，与
 *      lzc-manifest.yml 里自动登录注入用的凭据一致。
 *      用哨兵文件保证只做一次，之后用户在应用内自己改的密码不会被覆盖。
 *   2) 清掉首次建库给 admin 打的 need_pw_change 标记（否则登录后被强制跳「用户设置」页）。
 *   3) 把系统语言/时区设成简体中文 / Asia/Shanghai（保证打开即中文）。
 */
declare(strict_types=1);

$db = getenv('DATABASE_URL') ?: 'sqlite:///%kernel.project_dir%/uploads/app.db';
if (!str_starts_with($db, 'sqlite:///')) {
    fwrite(STDERR, "unsupported DATABASE_URL: {$db}\n");
    exit(1);
}
$path = str_replace('%kernel.project_dir%', '/var/www/html', substr($db, strlen('sqlite:///')));

$lang = getenv('DEFAULT_LANG') ?: 'zh';
$tz = getenv('DEFAULT_TIMEZONE') ?: 'Asia/Shanghai';

$adminUser = getenv('PARTDB_ADMIN_USER') ?: 'admin';
$adminPw = getenv('PARTDB_ADMIN_PW') ?: '';
// 兜底：环境变量缺失或被渲染成 Go 模板占位符时，用文档里公布的固定密码
if ($adminPw === '' || str_contains($adminPw, '<no value>') || str_contains($adminPw, '{{')) {
    $adminPw = 'PartDB123456';
}
$sentinel = '/var/www/html/var/.partdb-lzc-bootstrap';

try {
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    foreach (['users', 'settings_entry'] as $t) {
        $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$t}'")->fetchColumn();
        if (!$exists) {
            fwrite(STDERR, "table {$t} not ready\n");
            exit(1);
        }
    }

    // 1) 首次启动：设定确定的管理员账号密码
    $bootstrapped = is_file($sentinel) && trim((string)@file_get_contents($sentinel)) === 'done';
    if (!$bootstrapped) {
        $row = $pdo->prepare('SELECT id FROM users WHERE name = :n');
        $row->execute([':n' => $adminUser]);
        $id = $row->fetchColumn();
        if ($id === false) {
            fwrite(STDERR, "admin user {$adminUser} not created yet\n");
            exit(1);
        }
        $hash = password_hash($adminPw, PASSWORD_BCRYPT, ['cost' => 12]);
        $up = $pdo->prepare('UPDATE users SET password = :p, need_pw_change = 0, disabled = 0 WHERE id = :id');
        $up->execute([':p' => $hash, ':id' => $id]);
        @file_put_contents($sentinel, 'done');
        echo "admin credential initialized for '{$adminUser}'\n";
    }

    // 2) 每次启动都保证不会被强制改密拦截
    $st = $pdo->prepare('UPDATE users SET need_pw_change = 0 WHERE need_pw_change <> 0');
    $st->execute();
    $pwRows = $st->rowCount();

    // 3) 语言/时区（Jbtronics SettingsBundle 落库在 settings_entry）
    $st = $pdo->prepare('SELECT key, data FROM settings_entry');
    $st->execute();
    $touched = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string) $row['key'];
        if (str_ends_with($key, '.locale')) {
            $pdo->prepare('UPDATE settings_entry SET data = :v WHERE key = :k')
                ->execute([':v' => json_encode($lang), ':k' => $key]);
            $touched[] = $key . '=' . $lang;
        } elseif (str_ends_with($key, '.timezone')) {
            $pdo->prepare('UPDATE settings_entry SET data = :v WHERE key = :k')
                ->execute([':v' => json_encode($tz), ':k' => $key]);
            $touched[] = $key . '=' . $tz;
        }
    }

    echo 'admin need_pw_change normalized (rows=' . $pwRows . ")\n";
    echo 'settings touched: ' . (implode(', ', $touched) ?: '(none yet)') . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'not ready: ' . $e->getMessage() . "\n");
    exit(1);
}
