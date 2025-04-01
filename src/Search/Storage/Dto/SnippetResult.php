<?php declare(strict_types=1);
/**
 * @copyright 2023 Roman Parpalak
 * @license   MIT
 */

namespace Search\Storage\Dto;

use Search\Entity\ExternalId;
use Search\Entity\Metadata\SnippetSource;

class SnippetResult
{
    private array $data = [];

    public function attach(ExternalId $externalId, SnippetSource $snippet): void
    {
        $this->data[$externalId->toString()][] = $snippet;
    }

    public function iterate(callable $callback): void
    {
        foreach ($this->data as $serializedId => $snippets) {
            $callback(ExternalId::fromString($serializedId), ...$snippets);
        }
    }
}
