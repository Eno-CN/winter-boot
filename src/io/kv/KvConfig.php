<?php
declare(strict_types=1);

namespace dev\winterframework\io\kv;

class KvConfig {

    public function __construct(
        protected int $port,
        protected ?string $address = null,
        protected ?string $phpBinary = null,
    ) {
        if (!is_int($port) || !$port || $port < 1 || $port > 65535) {
            throw new KvException('KV Server port must be a number between 1 - 65535');
        }

        if (!$this->address) {
            $this->address = '127.0.0.1';
        }

        if ($this->phpBinary) {
            if (preg_match('/[^a-zA-Z0-9\-_\/]+/', $this->phpBinary)) {
                throw new KvException('KV Server php binary path has special characters');
            }
        } else {
            if (isset($_SERVER['_'])) {
                $this->phpBinary = $_SERVER['_'];
            } else if (isset($_ENV['_'])) {
                $this->phpBinary = $_ENV['_'];
            } else {
                $this->phpBinary = 'php';
            }
        }
    }

    public function getPort(): int {
        return $this->port;
    }

    public function getAddress(): string {
        return $this->address;
    }

    public function getPhpBinary(): string {
        return $this->phpBinary;
    }

}