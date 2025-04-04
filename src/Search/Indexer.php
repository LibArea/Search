<?php
/**
 * Creates search index
 *
 * @copyright 2010-2024 Roman Parpalak
 * @license   MIT
 */

namespace Search;

use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Search\Entity\ContentWithMetadata;
use Search\Entity\ExternalId;
use Search\Entity\Indexable;
use Search\Exception\RuntimeException;
use Search\Exception\UnknownException;
use Search\Extractor\DefaultExtractorFactory;
use Search\Extractor\ExtractorInterface;
use Search\Helper\StringHelper;
use Search\Stemmer\StemmerInterface;
use Search\Storage\Exception\EmptyIndexException;
use Search\Storage\StorageEraseInterface;
use Search\Storage\StorageWriteInterface;
use Search\Storage\TransactionalStorageInterface;

class Indexer
{
    use LoggerAwareTrait;

    protected StorageWriteInterface $storage;
    protected StemmerInterface $stemmer;
    protected ExtractorInterface $extractor;
    private bool $autoErase = false;

    public function __construct(
        StorageWriteInterface $storage,
        StemmerInterface      $stemmer,
        ?ExtractorInterface   $extractor = null,
        ?LoggerInterface      $logger = null
    ) {
        $this->storage   = $storage;
        $this->stemmer   = $stemmer;
        $this->extractor = $extractor ?? DefaultExtractorFactory::create();
        $this->logger    = $logger;
    }

    /**
     * Cleaning up an HTML string.
     */
    public static function titleStrFromHtml(string $content, string $allowedSymbols = ''): string
    {
        $content = mb_strtolower($content);
        $content = str_replace(['&nbsp;', "\xc2\xa0"], ' ', $content);
        /** @var string $content */
        $content = preg_replace('#&[^;]{1,20};#', '', $content);

        // We allow letters, digits and some punctuation: ".,-"
        $content = preg_replace('#[^\\-.,0-9\\p{L}^_' . $allowedSymbols . ']+#u', ' ', $content);

        // These punctuation characters are meant to be inside words and numbers.
        // We'll remove trailing characters when splitting the words.
        $content .= ' ';

        return $content;
    }

    /**
     * @return string[]
     */
    protected static function arrayFromStr(string $contents): array
    {
        $words = preg_split('#[\\-.,]*?[ ]+#S', $contents);
        StringHelper::removeLongWords($words);

        return $words;
    }

    protected function addToIndex(ExternalId $externalId, string $title, ContentWithMetadata $content, string $keywords): void
    {
        $sentenceCollection = $content->getSentenceMap()->toSentenceCollection();
        $contentWordsArray  = $sentenceCollection->getWordsArray();

        foreach ($contentWordsArray as $i => $word) {
            if ($this->storage->isExcludedWord($word)) {
                unset($contentWordsArray[$i]);
            }
        }

        $titleWordsArray = self::arrayFromStr($title);
        $keywordsArray   = self::arrayFromStr($keywords);

        $this->storage->addMetadata($externalId, \count($titleWordsArray) + \count($contentWordsArray), $content->getImageCollection());
        $this->storage->addSnippets($externalId, ...$sentenceCollection->getSnippetSources());
        $this->storage->addToFulltextIndex(
            $this->getStemsWithComponents($titleWordsArray),
            $this->getStemsWithComponents($keywordsArray), // TODO consider different semantics of space and comma?
            $this->getStemsWithComponents($contentWordsArray),
            $externalId
        );
    }

    public function removeById(string $id, ?int $instanceId): void
    {
        $externalId = new ExternalId($id, $instanceId);
        $this->storage->removeFromIndex($externalId);
        $this->storage->removeFromToc($externalId);
    }

    /**
     * @throws RuntimeException
     * @throws UnknownException
     */
    public function index(Indexable $indexable): void
    {
        try {
            $this->doIndex($indexable);
        } catch (EmptyIndexException $e) {
            if (!$this->autoErase || !$this->storage instanceof StorageEraseInterface) {
                throw $e;
            }

            $this->storage->erase();
            $this->doIndex($indexable);
        }
    }

    public function setAutoErase(bool $autoErase): void
    {
        $this->autoErase = $autoErase;
    }

    /**
     * @throws RuntimeException
     * @throws UnknownException
     */
    protected function doIndex(Indexable $indexable): void
    {
        if ($this->storage instanceof TransactionalStorageInterface) {
            $this->storage->startTransaction();
        }

        try {
            $externalId  = $indexable->getExternalId();
            $oldTocEntry = $this->storage->getTocByExternalId($externalId);

            $this->storage->addEntryToToc($indexable->toTocEntry(), $externalId);

            if ($oldTocEntry === null || $oldTocEntry->getHash() !== $indexable->calcHash()) {
                $this->storage->removeFromIndex($externalId);

                $extractionResult = $this->extractor->extract($indexable->getContent());
                $extractionErrors = $extractionResult->getErrors();
                if ($this->logger && $extractionErrors->hasErrors()) {
                    $this->logger->warning(sprintf(
                        'Found warnings on indexing "%s" (id="%s", instance="%s", url="%s")',
                        $indexable->getTitle(),
                        $indexable->getExternalId()->getId(),
                        $indexable->getExternalId()->getInstanceId() ?? '',
                        $indexable->getUrl()
                    ), $extractionErrors->getFormattedLines());
                }

                // strtolower in titleStrFromHtml is important
                $this->addToIndex(
                    $externalId,
                    self::titleStrFromHtml($indexable->getTitle()),
                    $extractionResult->getContentWithMetadata(),
                    self::titleStrFromHtml($indexable->getKeywords())
                );
            }

            if ($this->storage instanceof TransactionalStorageInterface) {
                $this->storage->commitTransaction();
            }
        } catch (\Exception $e) {
            if ($this->storage instanceof TransactionalStorageInterface) {
                $this->storage->rollbackTransaction();
            }
            if (!($e instanceof RuntimeException)) {
                throw new UnknownException('Unknown exception occurred while indexing.', 0, $e);
            }
            throw $e;
        }
    }

    /**
     * Replaces words with stems. Also, this method detects compound words and adds the component stems to the result.
     *
     * The keys in the result arrays are the positions of the word. For compound words a string representation
     * of a float is used to map one index to several words. For example, for input
     *
     * [10 => 'well-known', 11 => 'facts']
     *
     * this method returns
     *
     * [10 => 'well-known', 11 => 'fact', '10.001' => 'well', '10.002' => 'known']
     *
     * @param array $words
     * @return array
     */
    private function getStemsWithComponents(array $words): array
    {
        $componentsOfCompoundWords = [];
        foreach ($words as $i => &$word) {
            $stemmedWord = $this->stemmer->stemWord($word, false);

            // If the word contains punctuation marks like hyphen, add a variant without it
            if (false !== strpbrk($stemmedWord, StringHelper::WORD_COMPONENT_DELIMITERS)) {
                foreach (preg_split('#(?<=[\p{L}\d])[\-.,]+|[\-.,]++(?=[\p{L}\d])#u', $word) as $k => $subWord) {
                    if ($subWord !== '' && $subWord !== $word) {
                        $componentsOfCompoundWords[(string)($i + 0.001 * ($k + 1))] = $this->stemmer->stemWord($subWord, false);
                    }
                }
            }

            $word = $stemmedWord;
        }
        unset($word);

        return array_merge($words, $componentsOfCompoundWords);
    }
}
