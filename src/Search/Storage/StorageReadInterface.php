<?php
/**
 * @copyright 2016-2023 Roman Parpalak
 * @license   MIT
 */

namespace Search\Storage;

use Search\Entity\ExternalIdCollection;
use Search\Entity\TocEntryWithMetadata;
use Search\Storage\Dto\SnippetResult;
use Search\Storage\Dto\SnippetQuery;

interface StorageReadInterface
{
    /**
     * @param string[] $words
     */
    public function fulltextResultByWords(array $words, ?int $instanceId): FulltextIndexContent;

    public function isExcludedWord(string $word): bool;

    /**
     * @return TocEntryWithMetadata[]
     */
    public function getTocByExternalIds(ExternalIdCollection $externalIds): array;

    public function getSnippets(SnippetQuery $snippetQuery): SnippetResult;

    public function getTocSize(?int $instanceId): int;
}
