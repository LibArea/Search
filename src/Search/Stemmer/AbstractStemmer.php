<?php
/**
 * @copyright 2020-2024 Roman Parpalak
 * @license   MIT
 */

declare(strict_types=1);

namespace Search\Stemmer;

abstract class AbstractStemmer
{
    protected ?StemmerInterface $nextStemmer;

    public function __construct(StemmerInterface $nextStemmer = null)
    {
        $this->nextStemmer = $nextStemmer;
    }
}
