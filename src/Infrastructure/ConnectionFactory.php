<?php
namespace App\Infrastructure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
final class ConnectionFactory {
    public static function create(string $url): Connection {
        $parser = new DsnParser(['postgresql'=>'pdo_pgsql','postgres'=>'pdo_pgsql','pgsql'=>'pdo_pgsql','mysql'=>'pdo_mysql','mariadb'=>'pdo_mysql','sqlite'=>'pdo_sqlite']);
        $params=$parser->parse($url);
        if (($params['driver'] ?? '') === 'pdo_mysql') { $params['charset']='utf8mb4'; }
        $c=DriverManager::getConnection($params);
        if ($c->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            $c->executeStatement('PRAGMA foreign_keys = ON');
            $c->executeStatement('PRAGMA busy_timeout = 5000');
        }
        return $c;
    }
}
