<?php

namespace App\Actions\System;

use App\Support\Shell;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PDO;
use RuntimeException;
use Throwable;

/**
 * نسخةٌ احتياطية من قاعدة البيانات قبل أي هجرة.
 *
 * تُفضَّل أداة mysqldump متى وُجدت، وإن غابت — وهو الغالب على الاستضافات
 * المشتركة التي تعطّل proc_open — سقطنا إلى تفريغٍ خالصٍ بلغة PHP يمرّ على
 * الجداول صفاً صفاً بمؤشّرٍ غير مخزَّن، فلا يبتلع الذاكرة.
 *
 * النسخة تُكتب مضغوطةً في storage/app/backups، وهو خارج المسار العام.
 */
class BackupDatabaseAction
{
    /** @var list<string> */
    private const MYSQLDUMP_PATHS = [
        '/usr/bin/mysqldump',
        '/usr/local/bin/mysqldump',
        '/usr/local/mysql/bin/mysqldump',
        '/opt/cpanel/ea-mysql-client/bin/mysqldump',
    ];

    private const ROW_CHUNK = 200;

    /**
     * @return string مسار ملف النسخة الاحتياطية
     */
    public function handle(?string $connection = null, int $keep = 10): string
    {
        $connection ??= (string) config('database.default');

        /** @var array<string, mixed>|null $config */
        $config = config("database.connections.{$connection}");

        if ($config === null) {
            throw new RuntimeException("لا يوجد اتصال قاعدة بيانات باسم [{$connection}].");
        }

        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory);
        $this->protect($directory);

        $stamp = now()->format('Y-m-d_His');
        $driver = (string) ($config['driver'] ?? '');

        $path = match ($driver) {
            'sqlite' => $this->backupSqlite($connection, $config, "{$directory}/db-{$stamp}.sqlite"),
            'mysql', 'mariadb' => $this->backupMysql($connection, $config, "{$directory}/db-{$stamp}.sql.gz"),
            default => throw new RuntimeException("النسخ الاحتياطي غير مدعوم لمحرّك [{$driver}]."),
        };

        $this->prune($directory, $keep);

        return $path;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function backupSqlite(string $connection, array $config, string $path): string
    {
        $database = (string) ($config['database'] ?? '');

        if ($database === '' || $database === ':memory:' || ! is_file($database)) {
            throw new RuntimeException('ملف قاعدة بيانات SQLite غير موجود، فلا نسخة تُؤخذ.');
        }

        // نُفرغ سجلّ الكتابة المؤجّلة قبل النسخ حتى لا نلتقط ملفاً نصف مكتوب.
        DB::connection($connection)->statement('PRAGMA wal_checkpoint(TRUNCATE)');

        if (! File::copy($database, $path)) {
            throw new RuntimeException('تعذّر نسخ ملف قاعدة البيانات.');
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function backupMysql(string $connection, array $config, string $path): string
    {
        $binary = Shell::locate('mysqldump', self::MYSQLDUMP_PATHS);

        if ($binary !== null) {
            try {
                return $this->dumpWithMysqldump($binary, $config, $path);
            } catch (Throwable $e) {
                // على الاستضافات المشتركة تُمنع الأداة أحياناً بعد أن تُوجد،
                // فلا نُسقط النشر لأجلها ما دام لدينا بديلٌ يعمل.
                report($e);
            }
        }

        return $this->dumpWithPdo($connection, $path);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function dumpWithMysqldump(string $binary, array $config, string $path): string
    {
        $plain = substr($path, 0, -3); // نكتب SQL خاماً ثم نضغطه تدفّقاً

        $command = [
            $binary,
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
            '--skip-lock-tables',
            '--routines',
            '--default-character-set=utf8mb4',
            '--result-file='.$plain,
        ];

        $socket = (string) ($config['unix_socket'] ?? '');

        if ($socket !== '') {
            $command[] = '--socket='.$socket;
        } else {
            $command[] = '--host='.(string) ($config['host'] ?? '127.0.0.1');
            $command[] = '--port='.(string) ($config['port'] ?? 3306);
        }

        $command[] = '--user='.(string) ($config['username'] ?? '');
        $command[] = (string) ($config['database'] ?? '');

        $result = Process::timeout(1800)
            ->env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])
            ->run($command);

        if ($result->failed()) {
            @unlink($plain);

            throw new RuntimeException('فشل mysqldump: '.trim($result->errorOutput() ?: $result->output()));
        }

        $this->compress($plain, $path);

        return $path;
    }

    private function dumpWithPdo(string $connection, string $path): string
    {
        $db = DB::connection($connection);
        $pdo = $db->getPdo();
        $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        $handle = gzopen($path, 'wb6');

        if ($handle === false) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);

            throw new RuntimeException('تعذّر فتح ملف النسخة الاحتياطية للكتابة.');
        }

        try {
            gzwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($this->tables($db) as $table) {
                $create = (array) $db->selectOne('SHOW CREATE TABLE `'.$table.'`');

                gzwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                gzwrite($handle, (string) (array_values($create)[1] ?? '').";\n\n");

                $this->writeRows($handle, $pdo, $table);
            }

            gzwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (Throwable $e) {
            gzclose($handle);
            @unlink($path);

            throw $e;
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }

        gzclose($handle);

        return $path;
    }

    /**
     * @return list<string>
     */
    private function tables(Connection $db): array
    {
        $rows = $db->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");

        return array_values(array_map(
            fn ($row): string => (string) array_values((array) $row)[0],
            $rows
        ));
    }

    /**
     * @param  resource  $handle
     */
    private function writeRows($handle, PDO $pdo, string $table): void
    {
        $statement = $pdo->query('SELECT * FROM `'.$table.'`');

        if ($statement === false) {
            return;
        }

        $values = [];
        $columns = null;

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $columns ??= '`'.implode('`, `', array_keys($row)).'`';

            $values[] = '('.implode(', ', array_map(
                fn ($value): string => $value === null ? 'NULL' : $pdo->quote((string) $value),
                array_values($row)
            )).')';

            if (count($values) >= self::ROW_CHUNK) {
                $this->flushInsert($handle, $table, $columns, $values);
            }
        }

        if ($values !== [] && $columns !== null) {
            $this->flushInsert($handle, $table, $columns, $values);
        }

        $statement->closeCursor();
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $values
     */
    private function flushInsert($handle, string $table, string $columns, array &$values): void
    {
        gzwrite($handle, "INSERT INTO `{$table}` ({$columns}) VALUES\n".implode(",\n", $values).";\n");

        $values = [];
    }

    private function compress(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');

        if ($in === false || $out === false) {
            throw new RuntimeException('تعذّر ضغط ملف النسخة الاحتياطية.');
        }

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1048576));
        }

        fclose($in);
        gzclose($out);
        @unlink($source);
    }

    private function protect(string $directory): void
    {
        $ignore = $directory.'/.gitignore';

        if (! is_file($ignore)) {
            File::put($ignore, "*\n!.gitignore\n");
        }
    }

    private function prune(string $directory, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        collect(File::files($directory))
            ->filter(fn ($file): bool => str_starts_with($file->getFilename(), 'db-'))
            ->sortByDesc(fn ($file): int => $file->getMTime())
            ->values()
            ->slice($keep)
            ->each(fn ($file) => File::delete($file->getPathname()));
    }
}
