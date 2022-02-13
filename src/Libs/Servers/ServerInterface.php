<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Entity\StateEntity;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Mappers\ImportInterface;
use DateTimeInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

interface ServerInterface
{
    /**
     * Initiate Server. It should return **NEW OBJECT**
     *
     * @param string $name
     * @param UriInterface $url
     * @param null|int|string $token
     * @param array $options
     *
     * @return self
     */
    public function setUp(string $name, UriInterface $url, null|string|int $token = null, array $options = []): self;

    /**
     * Inject Logger.
     *
     * @param LoggerInterface $logger
     *
     * @return ServerInterface
     */
    public function setLogger(LoggerInterface $logger): ServerInterface;

    /**
     * Parse Server Specific Webhook event. for play/unplayed event.
     *
     * @param ServerRequestInterface $request
     * @return StateEntity|null
     */
    public static function parseWebhook(ServerRequestInterface $request): StateEntity|null;

    /**
     * Import Watch state.
     *
     * @param ImportInterface $mapper
     * @param DateTimeInterface|null $after
     *
     * @return array<array-key,ResponseInterface>
     */
    public function pull(ImportInterface $mapper, DateTimeInterface|null $after = null): array;

    /**
     * Export Watch State to Server.
     *
     * @param ExportInterface $mapper
     * @param DateTimeInterface|null $after
     *
     * @return array<array-key,ResponseInterface>
     */
    public function push(ExportInterface $mapper, DateTimeInterface|null $after = null): array;
}
