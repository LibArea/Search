<?php declare(strict_types=1);
/**
 * @copyright 2020-2023 Roman Parpalak
 * @license   MIT
 */

namespace Search\Entity;

use Search\Exception\InvalidArgumentException;

class ExternalId
{
    protected string $id;
    protected ?int $instanceId;

    /**
     * @param string|int|float $id
     */
    public function __construct($id, ?int $instanceId = null)
    {
        if (($instanceId !== null) && !($instanceId > 0)) {
            // @codeCoverageIgnoreStart
            throw new InvalidArgumentException('Instance id must be positive.');
            // @codeCoverageIgnoreEnd
        }

        if (!\is_string($id) && !\is_int($id) && !\is_float($id)) {
            // @codeCoverageIgnoreStart
            throw new InvalidArgumentException('External id must be string or int or float.');
            // @codeCoverageIgnoreEnd
        }

        $this->id         = (string)$id;
        $this->instanceId = $instanceId;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getInstanceId(): ?int
    {
        return $this->instanceId;
    }

    public function toString(): string
    {
        return $this->instanceId . ':' . $this->id;
    }

    public static function fromString(string $string): self
    {
        $data = explode(':', $string, 2);

        return new static($data[1], $data[0] !== '' ? (int)$data[0] : null);
    }
}
