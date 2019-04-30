<?php

namespace Features ;

use Features\TranslationVersions\Model\SegmentTranslationEventModel;

class TranslationVersions extends BaseFeature {

    const FEATURE_CODE = 'translation_versions';

    public function preSetTranslationCommitted( $params ) {
        // evaluate if the record is to be created, either the
        // status changed or the translation changed
        $user = $params['user'] ;

        /** @var \Translations_SegmentTranslationStruct $translation */
        $translation = $params['translation'] ;

        /** @var \Translations_SegmentTranslationStruct $old_translation */
        $old_translation  = $params['old_translation'];

        $propagated_ids   = $params['propagated_ids'];
        $source_page_code = $params['source_page_code'];

        $event = new SegmentTranslationEventModel($old_translation,
                $translation, $user, $source_page_code );

        $event->setPropagatedIds( $propagated_ids ) ;
        $event->save() ;


    }

}
