<?php /** @noinspection PhpComposerExtensionStubsInspection */
/** @noinspection PhpUnnecessaryLocalVariableInspection */
/** @noinspection SqlDialectInspection */
/**
 * @copyright 2020-2023 Roman Parpalak
 * @license   MIT
 */

declare(strict_types=1);

namespace Search\Storage\Database;

use Search\Entity\ExternalId;
use Search\Entity\ExternalIdCollection;
use Search\Entity\Metadata\SnippetSource;
use Search\Entity\TocEntry;
use Search\Exception\InvalidArgumentException;
use Search\Exception\UnknownException;
use Search\Storage\Dto\SnippetQuery;
use Search\Storage\Exception\EmptyIndexException;
use Search\Storage\Exception\InvalidEnvironmentException;

abstract class AbstractRepository
{
    public const TOC                    = 'toc';
    public const WORD                   = 'word';
    public const METADATA               = 'metadata';
    public const SNIPPET                = 'snippet';
    public const FULLTEXT_INDEX         = 'fulltext_index';

    protected const DEFAULT_TABLE_NAMES     = [
        self::TOC                    => 'toc',
        self::WORD                   => 'word',
        self::METADATA               => 'metadata',
        self::SNIPPET                => 'snippet',
        self::FULLTEXT_INDEX         => 'fulltext_index',
    ];

    protected const POSITION_PREFIX_KEYWORD = 'k';
    protected const POSITION_PREFIX_TITLE   = 't';

    protected string $prefix;
    protected array $options;
    protected \PDO $pdo;
    protected bool $inExternalTransaction = false;

    public function __construct(\PDO $pdo, string $prefix = '', array $options = [])
    {
        $this->pdo     = $pdo;
        $this->prefix  = $prefix;
        $this->options = array_merge(self::DEFAULT_TABLE_NAMES, $options);
    }

    /**
     * @throws InvalidEnvironmentException
     */
    public static function create(\PDO $pdo, string $prefix, array $options = [])
    {
        $driverName = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        switch ($driverName) {
            case 'mysql':
                return new MysqlRepository($pdo, $prefix, $options);

            case 'pgsql':
                return new PostgresRepository($pdo, $prefix, $options);

            case 'sqlite':
                return new SqliteRepository($pdo, $prefix, $options);

            default:
                throw new InvalidEnvironmentException(sprintf('Driver "%s" is not supported.', $driverName));
        }
    }

    public function drop(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->getTableName(self::TOC) . ';');
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->getTableName(self::METADATA) . ';');
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->getTableName(self::SNIPPET) . ';');
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->getTableName(self::FULLTEXT_INDEX) . ';');
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->getTableName(self::WORD) . ';');
    }

    abstract public function erase(): void;

    abstract public function addToToc(TocEntry $entry, ExternalId $externalId): void;

    abstract public function getSimilar(ExternalId $externalId, ?int $instanceId = null, int $minCommonWords = 4, int $limit = 10): array;

    abstract public function getIndexStat(): array;

    /**
     * @param string[] $words
     */
    abstract public function insertWords(array $words): void;

    abstract protected function isUnknownTableException(\PDOException $e): bool;

    abstract protected function isLockWaitingException(\PDOException $e): bool;

    abstract protected function isUnknownColumnException(\PDOException $e): bool;

    /**
     * Converts array of (long) words to array of word parts no longer than 255 chars.
     *
     * @param string[]|int[] $words
     *
     * @return string[]
     */
    public static function prepareWords(array $words): array
    {
        $partWords = [];
        foreach ($words as $fullWord) {
            $partWords[$fullWord] = mb_substr((string)$fullWord, 0, 255);
        }

        return $partWords;
    }

    protected function getTableName(string $key): string
    {
        if (!isset($this->options[$key])) {
            throw new InvalidArgumentException(sprintf('Unknown table "%s"', $key));
        }

        return $this->prefix . $this->options[$key];
    }

    /**
     * @param string[]|int[] $words
     *
     * @throws UnknownException
     */
    public function findIdsByWords(array $words): array
    {
        $partWords = static::prepareWords($words);

        $sql = '
			SELECT name, id
			FROM ' . $this->getTableName(self::WORD) . ' AS w
			WHERE name IN (' . implode(',', array_fill(0, \count($partWords), '?')) . ')
		';

        try {
            $st = $this->pdo->prepare($sql);
            $st->execute(array_values($partWords));
        } catch (\PDOException $e) {
            throw new UnknownException(sprintf(
                'Unknown exception "%s" occurred while reading word dictionary: "%s".',
                $e->getCode(),
                $e->getMessage()
            ), 0, $e);
        }

        $data = $st->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_COLUMN | \PDO::FETCH_UNIQUE) ?: [];

        $result = [];
        foreach ($partWords as $fullWord => $partWord) {
            if (isset($data[$partWord])) {
                $result[$fullWord] = $data[$partWord];
            }
        }

        return $result;
    }

    /**
     * @throws UnknownException
     */
    public function insertFulltext(array $titleWords, array $keywords, array $contentWords, array $wordIds, int $internalId): void
    {
        $data = [];
        foreach (
            [
                [$titleWords, self::POSITION_PREFIX_TITLE],
                [$keywords, self::POSITION_PREFIX_KEYWORD],
                [$contentWords, ''],
            ] as [$words, $prefix]
        ) {
            foreach ($words as $position => $word) { // float $position
                $wordId          = $wordIds[$word];
                $data[$wordId][] = $prefix . ((string)(int)$position);
            }
        }

        if (\count($data) === 0) {
            return;
        }
        $sqlParts = '';
        foreach ($data as $wordId => $prefixedPositions) {
            $sqlParts .= ($sqlParts !== '' ? ',' : '') . '(' . $wordId . ',' . $internalId . ',\'' . implode(',', $prefixedPositions) . '\')';
        }

        $sql = 'INSERT INTO ' . $this->getTableName(self::FULLTEXT_INDEX)
            . ' (word_id, toc_id, positions) VALUES ' . $sqlParts;

        try {
            $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            throw new UnknownException(sprintf(
                'Unknown exception with code "%s" occurred while fulltext indexing: "%s".',
                $e->getCode(),
                $e->getMessage()
            ), 0, $e);
        }
    }

    /**
     * @throws EmptyIndexException
     * @throws UnknownException
     */
    public function findFulltextByWords(array $words, int $instanceId = null): \Generator
    {
        $sql = '
			SELECT w.name AS word, t.external_id, t.instance_id, f.positions, COALESCE(m.word_count, 0) AS word_count, t.relevance_ratio
			FROM ' . $this->getTableName(self::FULLTEXT_INDEX) . ' AS f
			JOIN ' . $this->getTableName(self::WORD) . ' AS w ON w.id = f.word_id
			JOIN ' . $this->getTableName(self::TOC) . ' AS t ON t.id = f.toc_id
			LEFT JOIN ' . $this->getTableName(self::METADATA) . ' AS m ON t.id = m.toc_id
			WHERE w.name IN (' . implode(',', array_fill(0, \count($words), '?')) . ')
		';

        $parameters = $words;
        if ($instanceId !== null) {
            $sql          .= ' AND t.instance_id = ?';
            $parameters[] = $instanceId;
        }

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
        } catch (\PDOException $e) {
            if ($this->isUnknownTableException($e)) {
                throw new EmptyIndexException('There are no storage tables in the database. Call ' . __CLASS__ . '::erase() first.', 0, $e);
            }
            if ($this->isUnknownColumnException($e)) {
                throw new EmptyIndexException('There are outdated tables in the database. Call ' . __CLASS__ . '::erase() first.', 0, $e);
            }
            throw new UnknownException(sprintf(
                'Unknown exception "%s" occurred while fulltext searching: "%s".',
                $e->getCode(),
                $e->getMessage()
            ), 0, $e);
        }

        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $row['title_positions'] = $row['keyword_positions'] = $row['content_positions'] = [];
            foreach (explode(',', $row['positions']) as $positionWithPrefix) {
                if ($positionWithPrefix[0] === self::POSITION_PREFIX_TITLE) {
                    $row['title_positions'][] = (int)substr($positionWithPrefix, 1);
                } else if ($positionWithPrefix[0] === self::POSITION_PREFIX_KEYWORD) {
                    $row['keyword_positions'][] = (int)substr($positionWithPrefix, 1);
                } else {
                    $row['content_positions'][] = (int)$positionWithPrefix;
                }
            }
            yield $row;
        }
    }

    /**
     * @throws UnknownException
     */
    public function insertMetadata(int $internalId, int $wordCount, string $imagesJson): void
    {
        $st = $this->pdo->prepare('INSERT INTO ' . $this->getTableName(self::METADATA) . ' (toc_id, word_count, images) VALUES (?, ?, ?)');
        try {
            $st->execute([$internalId, $wordCount, $imagesJson]);
        } catch (\PDOException $e) {
            throw new UnknownException(sprintf(
                'Unknown exception "%s" occurred while inserting metadata: "%s".',
                $e->getCode(),
                $e->getMessage()
            ), 0, $e);
        }
    }

    public function insertSnippets(int $internalId, SnippetSource ...$snippetInfo): void
    {
        $data = array_map(fn(SnippetSource $s) => $s->getMinPosition() . ','
            . $s->getMaxPosition() . ','
            . $this->pdo->quote($s->getText()) . ','
            . $s->getFormatId() . ','
            . $internalId, $snippetInfo);

        $sql = 'INSERT INTO ' . $this->getTableName(self::SNIPPET)
            . ' (min_word_pos, max_word_pos, snippet, format_id, toc_id) VALUES ( ' . implode('),(', $data) . ')';
        try {
            $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            throw new UnknownException(sprintf(
                'Unknown exception "%s" occurred while inserting snippets: "%s".',
                $e->getCode(),
                $e->getMessage()
            ), 0, $e);
        }
    }

    public function getSnippets(SnippetQuery $snippetQuery): array
    {
        $internalIds   = $this->selectInternalIds(...$snippetQuery->getExternalIds());
        $orWhere       = [];
        $fallbackWhere = [];
        $snippetQuery->iterate(function (ExternalId $externalId, ?array $positions) use ($internalIds, &$orWhere, &$fallbackWhere) {
            // Add a first sentence to snippets if there are no matched snippets.
            $fallbackWhere[] = 's.toc_id = ' . $internalIds[$externalId->toString()];
            if (\count($positions ?? []) === 0) {
                // Seems like fallback snippets must be fetched here. But fulltext index can contain
                // some "fantom" entries with positions out of scope (e.g. keywords).
                // In that case there will be no snippets returned. So now the fallback snippets are fetched anyway.
                return;
            }

            $orWhere[] = 's.toc_id = ' . $internalIds[$externalId->toString()] . ' AND ('
                . implode(' OR ', array_map(
                    static fn(int $pos) => sprintf('s.min_word_pos <= %1$s AND s.max_word_pos >= %1$s', $pos),
                    $positions
                ))
                . ')';
        });

        if (\count($orWhere) === 0) {
            return [];
        }

        $sql = '(SELECT s.*
			FROM ' . $this->getTableName(self::SNIPPET) . ' AS s
			WHERE ' . implode(' OR ', $orWhere) . '
			)';

        foreach ($fallbackWhere as $fallbackWhereItem) {
            $sql .= ' UNION (
                SELECT s.*
                FROM ' . $this->getTableName(self::SNIPPET) . ' AS s
                WHERE ' . $fallbackWhereItem . '
                ORDER BY s.max_word_pos
                LIMIT 2
			)';
        }

        $sql .= ' ORDER BY toc_id, max_word_pos';

        $statement = $this->pdo->query($sql);

        $data = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $externalIds = array_flip($internalIds);
        foreach ($data as &$row) {
            $row['externalId'] = ExternalId::fromString($externalIds[$row['toc_id']]);
        }

        return $data;
    }

    /**
     * @throws EmptyIndexException
     * @throws UnknownException
     */
    public function removeFromIndex(ExternalId $externalId): void
    {
        $tocId = $this->selectInternalId($externalId);
        if ($tocId === null) {
            return;
        }

        try {
            $st = $this->pdo->prepare("DELETE FROM {$this->getTableName(self::FULLTEXT_INDEX)} WHERE toc_id = ?");
            $st->execute([$tocId]);

            $st = $this->pdo->prepare("DELETE FROM {$this->getTableName(self::METADATA)} WHERE toc_id = ?");
            $st->execute([$tocId]);

            $st = $this->pdo->prepare("DELETE FROM {$this->getTableName(self::SNIPPET)} WHERE toc_id = ?");
            $st->execute([$tocId]);
        } catch (\PDOException $e) {
            if ($this->isUnknownTableException($e)) {
                throw new EmptyIndexException('There are missing storage tables in the database. Is ' . __CLASS__ . '::erase() running in another process?', 0, $e);
            }
            throw new UnknownException(sprintf(
                'Unknown exception with code "%s" occurred while removing from index: "%s"',
                $e->getCode(),
                $e->getMessage()
            ), 0, $e);
        }
    }

    /**
     * @throws EmptyIndexException
     * @throws UnknownException
     */
    public function getTocEntries(array $criteria = []): array
    {
        try {
            if (isset($criteria['title'])) {
                // TODO remove
                $sql = '
					SELECT *
					FROM ' . $this->getTableName(self::TOC) . ' AS t
					WHERE t.title LIKE ? ESCAPE \'=\'
				';

                $statement = $this->pdo->prepare($sql);
                $statement->execute(['%' . $this->escapeLike($criteria['title'], '=') . '%']);
            } elseif (isset($criteria['ids'])) {
                if (!($criteria['ids'] instanceof ExternalIdCollection)) {
                    throw new InvalidArgumentException('Ids must be an ExternalIdCollection.');
                }
                $ids = $criteria['ids']->toArray();
                if (\count($ids) === 0) {
                    return [];
                }
                $sql = '
					SELECT t.*, m.*
					FROM ' . $this->getTableName(self::TOC) . ' AS t
					LEFT JOIN ' . $this->getTableName(self::METADATA) . ' AS m ON m.toc_id = t.id
					WHERE (t.external_id, t.instance_id) IN (' . implode(',', array_fill(0, \count($ids), '(?, ?)')) . ')';

                $statement = $this->pdo->prepare($sql);
                $params    = [];
                foreach ($ids as $id) {
                    $params[] = $id->getId();
                    $params[] = (int)$id->getInstanceId();
                }
                $statement->execute($params);
            } else {
                throw new InvalidArgumentException('Criteria must contain title or ids conditions.');
            }
        } catch (\PDOException $e) {
            if ($this->isUnknownTableException($e)) {
                throw new EmptyIndexException('There are no storage tables in the database. Call ' . __CLASS__ . '::erase() first.', 0, $e);
            }
            throw new UnknownException(sprintf(
                'Unknown exception with code "%s" occurred while reading TOC: "%s".',
                $e->getCode(),
                $e->getMessage()
            ), 0, $e);
        }

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @throws EmptyIndexException
     * @throws UnknownException
     */
    public function getTocSize(?int $instanceId): int
    {
        $sql = 'SELECT count(*) FROM ' . $this->getTableName(self::TOC);
        if ($instanceId !== null) {
            $sql .= ' WHERE instance_id = ?';
        }

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($instanceId !== null ? [$instanceId] : []);
        } catch (\PDOException $e) {
            if ($this->isUnknownTableException($e)) {
                throw new EmptyIndexException('There are no storage tables in the database. Call ' . __CLASS__ . '::erase() first.', 0, $e);
            }
            throw new UnknownException(sprintf(
                'Unknown exception with code "%s" occurred while obtaining TOC size: "%s".',
                $e->getCode(),
                $e->getMessage()
            ), 0, $e);
        }

        $result = $statement->fetch(\PDO::FETCH_COLUMN);

        return (int)$result;
    }

    /**
     * @throws EmptyIndexException
     * @throws UnknownException
     */
    public function removeFromToc(ExternalId $externalId): void
    {
        $sql = '
			DELETE FROM ' . $this->getTableName(self::TOC) . '
			WHERE external_id = ? AND instance_id = ?
		';

        try {
            $st = $this->pdo->prepare($sql);
            $st->execute([$externalId->getId(), (int)$externalId->getInstanceId()]);
        } catch (\PDOException $e) {
            if ($this->isUnknownTableException($e)) {
                throw new EmptyIndexException('There are no storage tables in the database. Call ' . __CLASS__ . '::erase() first.', 0, $e);
            }
            throw new UnknownException(sprintf(
                'Unknown exception with code "%s" occurred while removing from TOC: "%s".',
                $e->getCode(),
                $e->getMessage()
            ), 0, $e);
        }
    }

    /**
     * @throws EmptyIndexException
     * @throws UnknownException
     */
    public function selectInternalId(ExternalId $externalId): ?int
    {
        $sql = 'SELECT id FROM ' . $this->getTableName(self::TOC) . ' WHERE external_id = ? AND instance_id = ?';

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([$externalId->getId(), (int)$externalId->getInstanceId()]);

        } catch (\PDOException $e) {
            if ($this->isUnknownTableException($e)) {
                throw new EmptyIndexException('There are no storage tables in the database. Call ' . __CLASS__ . '::erase() first.', 0, $e);
            }
            throw new UnknownException(sprintf(
                'Unknown exception with code "%s" occurred while removing from index: "%s"',
                $e->getCode(),
                $e->getMessage()
            ), 0, $e);
        }

        $internalId = $statement->fetch(\PDO::FETCH_COLUMN);

        return $internalId === false ? null : (int)$internalId;
    }

    public function selectInternalIds(ExternalId ...$externalIds): array
    {
        $sql = '
            SELECT id, external_id, instance_id
            FROM ' . $this->getTableName(self::TOC) . ' AS t
            WHERE (t.external_id, t.instance_id) IN (' . implode(',', array_fill(0, \count($externalIds), '(?, ?)')) . ')';

        $params = [];
        foreach ($externalIds as $id) {
            $params[] = $id->getId();
            $params[] = (int)$id->getInstanceId();
        }
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);

        } catch (\PDOException $e) {
            if ($this->isUnknownTableException($e)) {
                throw new EmptyIndexException('There are no storage tables in the database. Call ' . __CLASS__ . '::erase() first.', 0, $e);
            }
            throw new UnknownException(sprintf(
                'Unknown exception with code "%s" occurred while removing from index: "%s"',
                $e->getCode(),
                $e->getMessage()
            ), 0, $e);
        }

        $data = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($data as $row) {
            $result[$this->getExternalIdFromRow($row)->toString()] = $row['id'];
        }

        return $result;
    }

    /**
     * @throws UnknownException
     */
    public function rollbackTransaction(): void
    {
        try {
            $this->pdo->rollBack();
        } catch (\PDOException $e) {
            throw new UnknownException(sprintf(
                'Unknown exception "%s" occurred while transaction rollback: "%s".',
                $e->getCode(),
                $e->getMessage()
            ), 0, $e);
        }
    }

    /**
     * @throws UnknownException
     */
    public function startTransaction(): void
    {
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $this->pdo->beginTransaction();
            } else {
                $this->inExternalTransaction = true;
            }
        } catch (\PDOException $e) {
            throw new UnknownException(sprintf(
                'Unknown exception "%s" occurred while starting transaction: "%s".',
                $e->getCode(),
                $e->getMessage()
            ), 0, $e);
        }
    }

    /**
     * @throws UnknownException
     */
    public function commitTransaction(): void
    {
        try {
            if ($this->inExternalTransaction) {
                $this->inExternalTransaction = false;
            } else {
                $this->pdo->commit();
            }
        } catch (\PDOException $e) {
            throw new UnknownException(sprintf(
                'Unknown exception "%s" occurred while committing transaction: "%s".',
                $e->getCode(),
                $e->getMessage()
            ), 0, $e);
        }
    }

    /**
     * @see http://stackoverflow.com/questions/3683746/escaping-mysql-wild-cards
     */
    private function escapeLike(string $s, string $e): string
    {
        return str_replace([$e, '_', '%'], [$e . $e, $e . '_', $e . '%'], $s);
    }

    private function getExternalIdFromRow(array $row): ExternalId
    {
        return new ExternalId($row['external_id'], is_numeric($row['instance_id']) && $row['instance_id'] > 0 ? (int)$row['instance_id'] : null);
    }
}
