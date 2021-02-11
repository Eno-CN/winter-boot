<?php
/** @noinspection PhpUnused */
/** @noinspection PhpPureAttributeCanBeAddedInspection */
declare(strict_types=1);

namespace dev\winterframework\stereotype\util;

use dev\winterframework\exception\InvalidSyntaxException;

class UriPathPart {
    const REGEX_STR = '[a-zA-Z_0-9\-\:\;\$\=\+\&\%]+';
    const REGEX_INT = '[0-9]+';
    const REGEX_FLOAT = '[0-9]+\.[0-9]+';
    const REGEX_BOOL = '(true|false)';

    const REGEX_VAR = '/^[a-zA-Z_][a-zA-Z_0-9]*$/';
    const REGEX_NAME = '/^[a-zA-Z_0-9\-]+$/';
    const VALUE = 'VAR_REGEX';

    private bool $isPathVariable = false;

    public function __construct(
        public string $part,
        public string $type = ''
    ) {
        $this->parsePath();
    }

    private function parsePath() {
        $len = strlen($this->part);

        if ($len > 1 && ($this->part[0] === '{' || $this->part[$len - 1] === '}')) {
            if ($this->part[0] !== '{' || $this->part[$len - 1] !== '}') {
                throw new InvalidSyntaxException('Path value missing either start or end braces \'{\' or \'}\' ');
            }

            $this->part = substr($this->part, 1, $len - 2);
            $this->isPathVariable = true;
        }

        if ($this->isPathVariable) {
            if (!preg_match(self::REGEX_VAR, $this->part)) {
                throw new InvalidSyntaxException('Invalid Path Variable value ' . $this->part
                    . ', special characters are not allowed.');
            }
        } else {
            if (!preg_match(self::REGEX_NAME, $this->part)) {
                throw new InvalidSyntaxException('Invalid Path part value ' . $this->part
                    . ', special characters are not allowed.');
            }
        }
    }

    /**
     * @return bool
     */
    public function isPathVariable(): bool {
        return $this->isPathVariable;
    }

    public function getRegex(): string {
        if (!$this->isPathVariable) {
            return preg_quote($this->part);
        }

        $regex = '(?<' . $this->part . '>';

        $regex .= match ($this->type) {
            'int' => self::REGEX_INT,
            'float' => self::REGEX_FLOAT,
            'bool' => self::REGEX_BOOL,
            default => self::REGEX_STR,
        };

        return $regex . ')';
    }

    public function getNormalized(): string {
        if (!$this->isPathVariable) {
            return preg_quote($this->part);
        }

        return self::VALUE;
    }
}