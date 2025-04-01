<?php declare(strict_types=1);
/**
 * @copyright 2020-2023 Roman Parpalak
 * @license   MIT
 */

namespace Search\Entity;

use Search\Entity\Metadata\Img;
use Search\Entity\Metadata\ImgCollection;

class TocEntryWithMetadata
{
    private TocEntry $tocEntry;
    private ExternalId $externalId;
    private ImgCollection $imgCollection;

    public function __construct(TocEntry $tocEntry, ExternalId $externalId, ImgCollection $imgCollection)
    {
        $this->tocEntry      = $tocEntry;
        $this->externalId    = $externalId;
        $this->imgCollection = $imgCollection;
    }

    public function getTocEntry(): TocEntry
    {
        return $this->tocEntry;
    }

    public function getExternalId(): ExternalId
    {
        return $this->externalId;
    }

    /**
     * @return ImgCollection|Img[]
     */
    public function getImgCollection(): ImgCollection
    {
        return $this->imgCollection;
    }
}
