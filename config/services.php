<?php

declare(strict_types=1);

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use App\Libs\Extends\ConsoleOutput;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\QueueRequests;
use App\Libs\Uri;
use Monolog\Logger;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

return (function (): array {
    return [
        LoggerInterface::class => [
            'class' => fn() => new Logger(name: 'logger', processors: [new LogMessageProcessor()])
        ],

        HttpClientInterface::class => [
            'class' => function (): HttpClientInterface {
                return new CurlHttpClient(
                    defaultOptions: Config::get('http.default.options', []),
                    maxHostConnections: Config::get('http.default.maxHostConnections', 25),
                    maxPendingPushes: Config::get('http.default.maxPendingPushes', 50),
                );
            }
        ],

        StateInterface::class => [
            'class' => fn() => new StateEntity([])
        ],

        QueueRequests::class => [
            'class' => fn() => new QueueRequests()
        ],

        CacheInterface::class => [
            'class' => function () {
                if (false === (bool)config::get('cache.enabled')) {
                    return new Psr16Cache(new NullAdapter());
                }

                $ns = getAppVersion();

                if (null !== ($prefix = Config::get('cache.prefix')) && true === isValidName($prefix)) {
                    $ns .= '.' . $prefix;
                }

                try {
                    if (!extension_loaded('redis')) {
                        throw new RuntimeException('Redis extension is not loaded.');
                    }

                    $uri = new Uri(Config::get('cache.url'));
                    $params = [];

                    if (!empty($uri->getQuery())) {
                        parse_str($uri->getQuery(), $params);
                    }

                    $redis = new Redis();

                    $redis->connect($uri->getHost(), $uri->getPort() ?? 6379);

                    if (null !== ag($params, 'password')) {
                        $redis->auth(ag($params, 'password'));
                    }

                    if (null !== ag($params, 'db')) {
                        $redis->select((int)ag($params, 'db'));
                    }

                    $backend = new RedisAdapter(
                        redis: $redis,
                        namespace: $ns,
                    );
                } catch (Throwable) {
                    // -- in case of error, fallback to file system cache.
                    $backend = new FilesystemAdapter(
                        namespace: $ns,
                        directory: Config::get('cache.path')
                    );
                }

                return new Psr16Cache($backend);
            }
        ],

        UriInterface::class => [
            'class' => fn() => new Uri(''),
            'shared' => false,
        ],

        OutputInterface::class => [
            'class' => fn(): OutputInterface => new ConsoleOutput()
        ],

        PDO::class => [
            'class' => function (): PDO {
                $pdo = new PDO(dsn: Config::get('database.dsn'), options: Config::get('database.options', []));

                foreach (Config::get('database.exec', []) as $cmd) {
                    $pdo->exec($cmd);
                }

                return $pdo;
            },
        ],

        iDB::class => [
            'class' => function (LoggerInterface $logger, PDO $pdo): iDB {
                $adapter = new PDOAdapter($logger, $pdo);

                if (true !== $adapter->isMigrated()) {
                    $adapter->migrations(iDB::MIGRATE_UP);
                    $adapter->ensureIndex();
                    $adapter->migrateData(
                        Config::get('database.version'),
                        Container::get(LoggerInterface::class)
                    );
                }

                return $adapter;
            },
            'args' => [
                LoggerInterface::class,
                PDO::class,
            ],
        ],

        MemoryMapper::class => [
            'class' => function (LoggerInterface $logger, iDB $db): iImport {
                return (new MemoryMapper(logger: $logger, db: $db))
                    ->setOptions(options: Config::get('mapper.import.opts', []));
            },
            'args' => [
                LoggerInterface::class,
                iDB::class,
            ],
        ],

        iImport::class => [
            'class' => function (iImport $mapper): iImport {
                return $mapper;
            },
            'args' => [
                MemoryMapper::class
            ],
        ],
    ];
})();
