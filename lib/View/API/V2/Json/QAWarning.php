<?php
/**
 * Created by PhpStorm.
 * User: vincenzoruffa
 * Date: 04/09/2018
 * Time: 12:40
 */

namespace API\V2\Json;

use QA;

class QAWarning {

    protected $structure;

    const TAGS_CATEGORY = "TAGS";
    const MISMATCH_CATEGORY = "MISMATCH";

    protected function pushErrorSegment( $error_type, $error_category, $content ) {

        switch ( $error_category ) {
            case QA::ERR_TAG_MISMATCH:
            case QA::ERR_TAG_ID:
            case QA::ERR_UNCLOSED_X_TAG:
            case QA::ERR_TAG_ORDER:
            case QA::ERR_UNCLOSED_G_TAG:
                $category = self::TAGS_CATEGORY;
                break;
            case QA::ERR_SPACE_MISMATCH_TEXT:
            case QA::ERR_TAB_MISMATCH:
            case QA::ERR_SPACE_MISMATCH:
            case QA::ERR_SYMBOL_MISMATCH:
            case QA::ERR_NEWLINE_MISMATCH:
                $category = self::MISMATCH_CATEGORY;
                break;
            default:
                throw new \RuntimeException("Undefined Category");
                break;
        }

        if ( !isset( $this->structure[ $error_type ][ 'Categories' ][ $category ] ) ) {
            $this->structure[ $error_type ][ 'Categories' ][ $category ] = [];
        }

        if ( !in_array( $content, $this->structure[ $error_type ][ 'Categories' ][ $category ] ) ) {
            $this->structure[ $error_type ][ 'Categories' ][ $category ][] = $content;
        }

    }
}