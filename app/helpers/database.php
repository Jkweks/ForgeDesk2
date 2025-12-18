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

/**
 * Start a transaction, rolling back any abandoned transaction state first.
 *
 * @return bool True when this call started the transaction, false if one was already active.
 */
function dbBeginTransactionSafe(\PDO $db): bool
{
    if ($db->inTransaction()) {
        try {
            // Validate the current transaction so we do not reuse an aborted state.
            $db->query('SELECT 1');

            return false;
        } catch (\PDOException $exception) {
            if (stripos($exception->getMessage(), 'current transaction is aborted') !== false) {
                $db->rollBack();
            } else {
                throw $exception;
            }
        }
    }

    try {
        $db->beginTransaction();

        return true;
    } catch (\PDOException $exception) {
        if (stripos($exception->getMessage(), 'active transaction') !== false) {
            try {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
            } catch (\Throwable $rollbackException) {
                // If rollback fails, bubble up the original exception to avoid masking errors.
                throw $exception;
            }

            $db->beginTransaction();

            return true;
        }

        throw $exception;
    }
}
