<?php declare(strict_types=1);
/**
 * @copyright 2023 Roman Parpalak
 * @license   MIT
 */

namespace Search\Extractor;

use Search\Extractor\HtmlDom\DomExtractor;
use Search\Extractor\HtmlRegex\RegexExtractor;

class DefaultExtractorFactory
{
    public static function create(): ChainExtractor
    {
        $extractor = new ChainExtractor();
        if (DomExtractor::available()) {
            $extractor->attachExtractor(new DomExtractor());
        }
        $extractor->attachExtractor(new RegexExtractor());

        return $extractor;
    }
}
