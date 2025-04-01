<?php declare(strict_types=1);
/**
 * @copyright 2023 Roman Parpalak
 * @license   MIT
 */

namespace Search\Entity;

use Search\Entity\Metadata\ImgCollection;
use Search\Entity\Metadata\SentenceMap;

class ContentWithMetadata
{
    private SentenceMap $sentenceMap;
    private ImgCollection $imageCollection;

    public function __construct(SentenceMap $sentenceMap, ImgCollection $images)
    {
        $this->sentenceMap     = $sentenceMap;
        $this->imageCollection = $images;
    }

    public function getSentenceMap(): SentenceMap
    {
        return $this->sentenceMap;
    }

    public function getImageCollection(): ImgCollection
    {
        return $this->imageCollection;
    }
}
