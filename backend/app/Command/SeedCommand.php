<?php

declare(strict_types=1);

namespace App\Command;

use App\Constants\UserStatus;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\DbConnection\Db;
use Psr\Container\ContainerInterface;

/**
 * 种子数据命令（docs/05 §2.1）：演示账号 demo / Demo@123456（bcrypt 落库），
 * 幂等：仅演示账号不存在时插入。
 */
#[Command]
class SeedCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('db:seed');
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('写入演示数据（幂等：仅缺失时插入）');
    }

    public function handle(): void
    {
        $now = time();

        $exists = Db::table('users')->where('username', 'demo')->exists();
        if (! $exists) {
            Db::table('users')->insert([
                'username' => 'demo',
                'password_hash' => password_hash('Demo@123456', PASSWORD_BCRYPT, ['cost' => 12]),
                'country_code' => '+86',
                'phone' => '13800138000',
                'email' => 'demo@example.com',
                'status' => UserStatus::NORMAL,
                'is_email_verified' => 1,
                'is_phone_verified' => 1,
                'login_failed_count' => 0,
                'locked_until' => 0,
                'token_version' => 0,
                'totp_secret_encrypted' => '',
                'totp_enabled' => 0,
                'kyc_level' => 1,
                'is_admin' => 1,
                'last_login_at' => 0,
                'last_login_ip' => '',
                'created_at' => $now,
                'updated_at' => $now,
                'is_deleted' => 0,
            ]);
            $this->output->writeln('seed: 演示账号 demo / Demo@123456 已创建（is_admin=1，可查审计日志）');
        } else {
            $this->output->writeln('seed: 演示账号已存在，跳过');
        }
    }
}
