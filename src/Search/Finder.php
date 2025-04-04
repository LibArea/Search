<?php
/**
 * Fulltext search
 *
 * @copyright 2010-2024 Roman Parpalak
 * @license   MIT
 */

namespace Search;

use Search\Entity\ExternalId;
use Search\Entity\ExternalIdCollection;
use Search\Entity\FulltextQuery;
use Search\Entity\FulltextResult;
use Search\Entity\Query;
use Search\Entity\ResultSet;
use Search\Exception\ImmutableException;
use Search\Exception\LogicException;
use Search\Exception\UnknownIdException;
use Search\Snippet\SnippetBuilder;
use Search\Stemmer\StemmerInterface;
use Search\Storage\Dto\SnippetQuery;
use Search\Storage\StorageReadInterface;

class Finder
{
    protected StorageReadInterface $storage;
    protected StemmerInterface $stemmer;
    protected ?string $highlightTemplate = null;
    protected ?string $snippetLineSeparator = null;

    /**
     * @var string[]
     */
    protected array $highlightMaskRegexArray = [];

    public function __construct(StorageReadInterface $storage, StemmerInterface $stemmer)
    {
        $this->storage = $storage;
        $this->stemmer = $stemmer;
    }

    public function setHighlightMaskRegexArray(array $highlightMaskRegexArray): self
    {
        $this->highlightMaskRegexArray = $highlightMaskRegexArray;

        return $this;
    }

    public function setHighlightTemplate(string $highlightTemplate): self
    {
        $this->highlightTemplate = $highlightTemplate;

        return $this;
    }

    public function setSnippetLineSeparator(string $snippetLineSeparator): self
    {
        $this->snippetLineSeparator = $snippetLineSeparator;

        return $this;
    }

    /**
     * @throws ImmutableException
     */
    public function find(Query $query, bool $isDebug = false): ResultSet
    {
        $resultSet = new ResultSet($query->getLimit(), $query->getOffset(), $isDebug);
        if ($this->highlightTemplate !== null) {
            $resultSet->setHighlightTemplate($this->highlightTemplate);
        }

        $rawWords = $query->valueToArray();
        $resultSet->addProfilePoint('Input cleanup');

        if (\count($rawWords) > 0) {
            $this->findFulltext($rawWords, $query->getInstanceId(), $resultSet);
            $resultSet->addProfilePoint('Fulltext search');
        }

        $resultSet->freeze();

        $sortedExternalIds = $resultSet->getSortedExternalIds();

        $resultSet->addProfilePoint('Sort results');

        foreach ($this->storage->getTocByExternalIds($sortedExternalIds) as $tocEntryWithExternalId) {
            $resultSet->attachToc($tocEntryWithExternalId);
        }

        $resultSet->addProfilePoint('Fetch TOC');

        $relevanceByExternalIds = $resultSet->getSortedRelevanceByExternalId();
        if (\count($relevanceByExternalIds) > 0) {
            $this->buildSnippets($relevanceByExternalIds, $resultSet);
        }

        return $resultSet;
    }

    /**
     * Ignore frequent words encountering in indexed items.
     */
    public static function fulltextRateExcludeNum(int $tocSize): int
    {
        return max($tocSize * 0.5, 20);
    }

    /**
     * @throws ImmutableException
     */
    protected function findFulltext(array $words, ?int $instanceId, ResultSet $resultSet): void
    {
        $fulltextQuery        = new FulltextQuery($words, $this->stemmer);
        $fulltextIndexContent = $this->storage->fulltextResultByWords($fulltextQuery->getWordsWithStems(), $instanceId);
        $fulltextResult       = new FulltextResult(
            $fulltextQuery,
            $fulltextIndexContent,
            $this->storage->getTocSize($instanceId)
        );

        $fulltextResult->fillResultSet($resultSet);
    }

    public function buildSnippets(array $relevanceByExternalIds, ResultSet $resultSet): void
    {
        $snippetQuery = new SnippetQuery(ExternalIdCollection::fromStringArray(array_keys($relevanceByExternalIds)));
        try {
            $foundWordPositionsByExternalId = $resultSet->getFoundWordPositionsByExternalId();
        } catch (ImmutableException $e) {
            throw new LogicException($e->getMessage(), 0, $e);
        }
        foreach ($foundWordPositionsByExternalId as $serializedExtId => $wordsInfo) {
            if (!isset($relevanceByExternalIds[$serializedExtId])) {
                // Out of limit and offset scope, no need to fetch snippets.
                continue;
            }
            $externalId   = ExternalId::fromString($serializedExtId);
            $allPositions = array_merge(...array_values($wordsInfo));
            $snippetQuery->attach($externalId, $allPositions);
        }
        $resultSet->addProfilePoint('Snippets: make query');

        $snippetResult = $this->storage->getSnippets($snippetQuery);

        $resultSet->addProfilePoint('Snippets: obtaining');

        $sb = new SnippetBuilder($this->stemmer, $this->snippetLineSeparator);
        $sb->setHighlightMaskRegexArray($this->highlightMaskRegexArray);
        try {
            $sb->attachSnippets($resultSet, $snippetResult);
        } catch (ImmutableException|UnknownIdException $e) {
            throw new LogicException($e->getMessage(), 0, $e);
        }

        $resultSet->addProfilePoint('Snippets: building');
    }
}
