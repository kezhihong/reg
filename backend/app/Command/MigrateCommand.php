<?php

declare(strict_types=1);

namespace App\Command;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\DbConnection\Db;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * 幂等迁移命令（docs/03 §6）：扫描 migrations/*.sql，按 sc_migrations 版本表
 * 跳过已执行文件，重复执行安全（全新安装 + 升级安装双路径）。
 */
#[Command]
class MigrateCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('migrate');
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('执行幂等数据库迁移（migrations/*.sql）');
        $this->addOption('fresh', null, InputOption::VALUE_NONE, '全新安装：先建迁移版本表');
    }

    public function handle(): void
    {
        Db::statement('CREATE TABLE IF NOT EXISTS `sc_migrations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'主键\',
            `name` VARCHAR(128) NOT NULL COMMENT \'迁移文件名\',
            `executed_at` BIGINT UNSIGNED NOT NULL COMMENT \'执行时间（Unix 秒）\',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_migration_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT=\'迁移版本表\'');

        $dir = BASE_PATH . '/migrations';
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);

        $done = Db::table('migrations')->pluck('name')->map(fn ($v) => (string) $v)->toArray();
        $executed = 0;

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                $this->output->writeln(sprintf('  skipped  %s（已执行）', $name));
                continue;
            }
            $sql = (string) file_get_contents($file);
            if (trim($sql) === '') {
                continue;
            }
            Db::unprepared($sql);
            Db::table('migrations')->insert([
                'name' => $name,
                'executed_at' => time(),
            ]);
            $this->output->writeln(sprintf('  migrated %s', $name));
            $executed++;
        }

        $this->output->writeln(sprintf('migrate 完成：本次执行 %d 个，共 %d 个迁移', $executed, count($files)));
    }
}
