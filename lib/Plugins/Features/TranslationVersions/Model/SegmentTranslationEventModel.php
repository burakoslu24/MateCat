<?php
/**
 * Created by PhpStorm.
 * User: fregini
 * Date: 07/02/2018
 * Time: 17:34
 */

namespace Features\TranslationVersions\Model;

use Exception;
use TransactionableTrait;
use Translations_SegmentTranslationStruct;

class SegmentTranslationEventModel  {
    use TransactionableTrait ;

    /**
     * @var Translations_SegmentTranslationStruct
     */
    protected $old_translation ;

    /**
     * @var Translations_SegmentTranslationStruct
     */
    protected $translation ;

    protected $user ;
    protected $propagated_ids ;
    protected $source_page_code ;

    /**
     * @var int|SegmentTranslationEventStruct
     */
    protected $prior_event = -1 ;

    protected $current_event = -1 ;

    public function __construct( Translations_SegmentTranslationStruct $old_translation,
                                 Translations_SegmentTranslationStruct $translation,
                                 $user, $source_page_code) {

        $this->old_translation  = $old_translation ;
        $this->translation      = $translation ;
        $this->user             = $user ;
        $this->source_page_code = $source_page_code ;
    }

    public function setPropagatedIds( $propagated_ids ) {
        $this->propagated_ids = $propagated_ids ;
    }

    public function getPropagatedIds() {
        return $this->propagated_ids ;
    }

    /**
     *
     */
    public function save() {

        if ( $this->current_event !== -1 ) {
            throw new Exception('The current event was persisted already. Use getCurrentEvent to retrieve it.') ;
        }

        if ( !$this->_saveRequired() ) {
            return ;
        }

        $this->openTransaction() ;

        $this->current_event                 = new SegmentTranslationEventStruct() ;
        $this->current_event->id_job         = $this->translation['id_job'] ;
        $this->current_event->id_segment     = $this->translation['id_segment'] ;
        $this->current_event->uid            = ( $this->user->uid != null ? $this->user->uid : 0 );
        $this->current_event->status         = $this->translation['status'] ;
        $this->current_event->version_number = $this->translation['version_number'] ;
        $this->current_event->source_page    = $this->source_page_code ;

        $this->current_event->setTimestamp('create_date', time() );

        $this->current_event->id = SegmentTranslationEventDao::insertStruct( $this->current_event ) ;

        if ( ! empty( $this->propagated_ids ) ) {
            $dao = new SegmentTranslationEventDao();
            $dao->insertForPropagation($this->propagated_ids, $this->current_event);
        }

        $this->translation->getChunk()
                ->getProject()
                ->getFeatures()
                ->run('translationEventSaved', $this );

        $this->commitTransaction() ;
    }

    /**
     * @return bool
     */
    protected function _saveRequired() {
        return (
                $this->old_translation->translation != $this->translation->translation ||
                $this->old_translation->status      != $this->translation->status ||
                $this->source_page_code             != $this->getPriorEvent()->source_page
        );
    }

    /**
     * @return Translations_SegmentTranslationStruct
     */
    public function getOldTranslation() {
        return $this->old_translation;
    }

    /**
     * @return Translations_SegmentTranslationStruct
     */
    public function getTranslation() {
        return $this->translation;
    }

    protected function _getPriorSourcePageCode() {
        $this->getPriorEvent();
        return $this->prior_event == null ? null : $this->prior_event->source_page ;
    }

    public function getPriorEvent() {
        if ( $this->prior_event === -1 ) {
            $this->prior_event = ( new SegmentTranslationEventDao() )->getLatestEventForSegment(
                    $this->old_translation->id_job,
                    $this->old_translation->id_segment
            ) ;
        }
        return $this->prior_event ;
    }

    public function getCurrentEvent() {
        if ( $this->current_event == -1 ) {
            throw new Exception('The current segment was not persisted yet. Run save() first.');
        }
        return $this->current_event ;
    }



}