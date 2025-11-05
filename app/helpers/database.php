<?php

declare(strict_types=1);

/**
 * Create (or reuse) a PDO connection to PostgreSQL using the provided configuration array.
 *
 * @param array{host:string,port:int,name:string,user:string,password:string} $config
 */
function db(array $config): \PDO
{
    /** @var \PDO|null $connection */
    static $connection = null;

    if ($connection instanceof \PDO) {
        return $connection;
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $config['host'],
        $config['port'],
        $config['name']
    );

    try {
        $connection = new \PDO($dsn, $config['user'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    } catch (\PDOException $exception) {
        throw new \PDOException('Unable to connect to the database: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
    }

    return $connection;
}
