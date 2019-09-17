<?php

namespace Features;

use API\V2\Exceptions\ValidationError;
use BasicFeatureStruct;
use Chunks_ChunkCompletionEventStruct;
use Chunks_ChunkDao;
use Chunks_ChunkStruct;
use Constants;
use Contribution\ContributionSetStruct;
use Database;
use Exception;
use Exceptions\NotFoundException;
use Features;
use Features\ProjectCompletion\CompletionEventStruct;
use Features\ReviewExtended\IChunkReviewModel;
use Features\ReviewExtended\Model\ArchivedQualityReportModel;
use Features\ReviewExtended\Model\QualityReportModel;
use Features\ReviewExtended\ReviewUtils;
use FilesStorage\FilesStorageFactory;
use INIT;
use Jobs_JobStruct;
use Log;
use LQA\ChunkReviewDao;
use LQA\ChunkReviewStruct;
use LQA\ModelDao;
use Projects_ProjectDao;
use Projects_ProjectStruct;
use RevisionFactory;
use SegmentTranslationChangeVector;
use Utils;
use ZipArchive;

abstract class AbstractRevisionFeature extends BaseFeature {

    protected $revisionInstance;

    protected static $dependencies = [
            Features::TRANSLATION_VERSIONS
    ];

    public function __construct( BasicFeatureStruct $feature ) {
        parent::__construct( $feature );
        RevisionFactory::getInstance( $this );
    }

    /**
     * In ReviewExtended, UI forces the `propagation` parameter to false to avoid prompt and autopropagation of
     * revision status changes.
     *
     * This param must be reset to default value `true` when contribution is evaluted, otherwise the
     * TM won't receive UPDATE when a segment is updated.
     *
     * XXX: not sure this was the best way to solve this problem.
     *
     * @param ContributionSetStruct  $contributionStruct
     * @param Projects_ProjectStruct $project
     *
     * @return ContributionSetStruct
     */
    public function filterContributionStructOnSetTranslation( ContributionSetStruct $contributionStruct, Projects_ProjectStruct $project ) {

        if ( $contributionStruct->fromRevision ) {
            $contributionStruct->propagationRequest = true;
        }

        return $contributionStruct;
    }

    /**
     * filter_review_password
     *
     * If this method is reached it means that the project we are
     * working on has ReviewExtended feature enabled, and that we
     * are in reivew mode.
     *
     * Assuming the provided password is a "review_password".
     * This review password is checked against the `qa_chunk_reviews`.
     * If not found, raise an exception.
     * If found, override the input password with job password.
     *
     */
    public function filter_review_password_to_job_password( $review_password, $id_job ) {
        $chunk_review = ChunkReviewDao::findByReviewPasswordAndJobId(
                $review_password, $id_job );

        if ( !$chunk_review ) {
            throw new NotFoundException( 'Review record was not found' );
        }

        return $chunk_review->password;
    }

    /**
     * @param $password
     * @param $id_job
     *
     * @return mixed
     * @throws NotFoundException
     */
    public function filter_job_password_to_review_password( $password, $id_job ) {

        $chunk_review = ( new ChunkReviewDao() )->findChunkReviews( new Chunks_ChunkStruct( [ 'id' => $id_job, 'password' => $password ] ) )[ 0 ];

        if ( !$chunk_review ) {
            throw new NotFoundException( 'Review record was not found' );
        }

        return $chunk_review->review_password;
    }


    public function filter_get_segments_segment_data( $seg ) {
        if ( isset( $seg[ 'edit_distance' ] ) ) {
            $seg[ 'edit_distance' ] = round( $seg[ 'edit_distance' ] / 1000, 2 );
        } else {
            $seg[ 'edit_distance' ] = 0;
        }

        return $seg;
    }

    public function filter_get_segments_optional_fields( $options ) {
        $options[ 'optional_fields' ][] = 'edit_distance';

        return $options;
    }

    /**
     * @param $project
     *
     * @return Projects_ProjectStruct
     */
    public function filter_manage_single_project( $project ) {
        $chunks = [];

        foreach ( $project[ 'jobs' ] as $job ) {
            $ch           = new Chunks_ChunkStruct();
            $ch->id       = $job[ 'id' ];
            $ch->password = $job[ 'password' ];
            $chunks[]     = $ch;
        }

        $chunk_reviews = [];

        foreach ( ( new ChunkReviewDao() )->findChunkReviewsForList( $chunks ) as $chunk_review ) {
            $key = $chunk_review->id_job . $chunk_review->password;
            if ( !isset( $chunk_reviews[ $key ] ) ) {
                $chunk_reviews[ $key ] = [];
            }
            $chunk_reviews[ $key ] [] = $chunk_review;
        }

        foreach ( $project[ 'jobs' ] as $kk => $job ) {

            $key = $job[ 'id' ] . $job[ 'password' ];

            /**
             * Inner cycle to match chunk_reviews records and modify
             * the data structure.
             */
            foreach ( $chunk_reviews[ $key ] as $chunk_review ) {

                if ( $chunk_review->id_job == $job[ 'id' ] && $chunk_review->password == $job[ 'password' ] && $chunk_review->source_page <= Constants::SOURCE_PAGE_REVISION ) {
                    $project[ 'jobs' ][ $kk ][ 'revise_passwords' ][] = [
                            'revision_number' => 1,
                            'password'        => $chunk_review->review_password
                    ];
                }

            }

            if ( !isset( $project[ 'jobs' ][ $kk ] [ 'stats' ] [ 'revises' ] ) ) {
                $project[ 'jobs' ][ $kk ] [ 'stats' ] = ReviewUtils::formatStats( $project[ 'jobs' ][ $kk ] [ 'stats' ], $chunk_reviews[ $key ] );
            }

        }

        return $project;
    }

    public function filter_single_job(  ){
        
    }

    /**
     * Performs post project creation tasks for the current project.
     * Evaluates if a qa model is present in the feature options.
     * If so, then try to assign the defined qa_model.
     * If not, then try to find the qa_model from the project structure.
     *
     * @param $projectStructure
     *
     * @throws Exception
     */
    public function postProjectCreate( $projectStructure ) {
        $this->setQaModelFromJsonFile( $projectStructure );
        $this->createChunkReviewRecords( $projectStructure );
    }

    /**
     * @param       $id_job
     * @param       $id_project
     * @param array $options
     *
     * @return ChunkReviewStruct[]
     * @throws Exception
     */
    public function createQaChunkReviewRecord( $id_job, $id_project, $options = [] ) {

        $project        = Projects_ProjectDao::findById( $id_project );
        $chunks         = Chunks_ChunkDao::getByIdProjectAndIdJob( $id_project, $id_job, 0 );
        $createdRecords = [];

        // expect one chunk
        if ( !isset( $options[ 'source_page' ] ) ) {
            $options[ 'source_page' ] = Constants::SOURCE_PAGE_REVISION;
        }

        foreach ( $chunks as $k => $chunk ) {
            $data = [
                    'id_project'  => $id_project,
                    'id_job'      => $chunk->id,
                    'password'    => $chunk->password,
                    'source_page' => $options[ 'source_page' ]
            ];

            if ( $k == 0 && array_key_exists( 'first_record_password', $options ) != null ) {
                $data[ 'review_password' ] = $options[ 'first_record_password' ];
            }

            $chunkReview = ChunkReviewDao::createRecord( $data );
            $project->getFeatures()->run( 'chunkReviewRecordCreated', $chunkReview, $project );
            $createdRecords[] = $chunkReview;
        }

        return $createdRecords;
    }

    /**
     * @param Chunks_ChunkStruct[]   $chunksArray
     * @param Projects_ProjectStruct $project
     * @param array                  $options
     *
     * @return array
     * @throws Exception
     */
    public function createQaChunkReviewRecords( Array $chunksArray, Projects_ProjectStruct $project, $options = [] ) {

        $createdRecords = [];

        // expect one chunk
        if ( !isset( $options[ 'source_page' ] ) ) {
            $options[ 'source_page' ] = Constants::SOURCE_PAGE_REVISION;
        }

        foreach ( $chunksArray as $k => $chunk ) {
            $data = [
                    'id_project'  => $project->id,
                    'id_job'      => $chunk->id,
                    'password'    => $chunk->password,
                    'source_page' => $options[ 'source_page' ]
            ];

            if ( $k == 0 && array_key_exists( 'first_record_password', $options ) != null ) {
                $data[ 'review_password' ] = $options[ 'first_record_password' ];
            }

            $chunkReview = ChunkReviewDao::createRecord( $data );
            $project->getFeatures()->run( 'chunkReviewRecordCreated', $chunkReview, $project );
            $createdRecords[] = $chunkReview;
        }

        return $createdRecords;
    }

    /**
     * @param $projectStructure
     *
     * @throws Exception
     */
    protected function createChunkReviewRecords( $projectStructure ) {
        $project = Projects_ProjectDao::findById( $projectStructure[ 'id_project' ], 86400 );
        foreach ( $projectStructure[ 'array_jobs' ][ 'job_list' ] as $id_job ) {
            $this->createQaChunkReviewRecords( \Jobs_JobDao::getById( $id_job, 0, new Chunks_ChunkStruct() ), $project );
        }
    }

    /**
     * postJobSplitted
     *
     * Deletes the previously created record and creates the new records matching the new chunks.
     *
     * @param \ArrayObject $projectStructure
     *
     * @throws Exception
     *
     */
    public function postJobSplitted( \ArrayObject $projectStructure ) {

        /**
         * By definition, when running postJobSplitted callback the job is not splitted.
         * So we expect to find just one record in chunk_reviews for the job.
         * If we find more than one record, it's one record for each revision.
         *
         */

        $id_job                  = $projectStructure[ 'job_to_split' ];
        $previousRevisionRecords = ChunkReviewDao::findByIdJob( $id_job );
        $project                 = Projects_ProjectDao::findById( $projectStructure[ 'id_project' ], 86400 );

        $revisionFactory = RevisionFactory::initFromProject( $project );

        ChunkReviewDao::deleteByJobId( $id_job );

        $chunksStructArray = \Jobs_JobDao::getById( $id_job, 0, new Chunks_ChunkStruct() );

        $reviews = [];
        foreach ( $previousRevisionRecords as $review ) {
            $reviews = array_merge( $reviews, $this->createQaChunkReviewRecords( $chunksStructArray, $project,
                    [
                            'first_record_password' => $review->review_password,
                            'source_page'           => $review->source_page
                    ]
            )
            );
        }

        foreach ( $reviews as $review ) {
            $model = $revisionFactory->getChunkReviewModel( $review );
            $model->recountAndUpdatePassFailResult( $project );
        }

    }

    /**
     * postJobMerged
     *
     * Deletes the previously created record and creates the new records matching the new chunks.
     *
     * @param $projectStructure
     *
     * @throws Exception
     */
    public function postJobMerged( $projectStructure ) {

        $id_job      = $projectStructure[ 'job_to_merge' ];
        $old_reviews = ChunkReviewDao::findByIdJob( $id_job );
        $project     = Projects_ProjectDao::findById( $projectStructure[ 'id_project' ], 86400 );

        $revisionFactory = RevisionFactory::initFromProject( $project );

        $reviewGroupedData = [];

        foreach ( $old_reviews as $review ) {
            if ( !isset( $reviewGroupedData[ $review->source_page ] ) ) {
                $reviewGroupedData[ $review->source_page ] = [
                        'first_record_password' => $review->review_password
                ];
            }
        }

        ChunkReviewDao::deleteByJobId( $id_job );

        $chunksStructArray = \Jobs_JobDao::getById( $id_job, 0, new Chunks_ChunkStruct() );

        $reviews = [];
        foreach ( $reviewGroupedData as $source_page => $data ) {
            $reviews = array_merge( $reviews, $this->createQaChunkReviewRecords(
                    $chunksStructArray,
                    $project,
                    [
                            'first_record_password' => $data[ 'first_record_password' ],
                            'source_page'           => $source_page
                    ]
            )
            );
        }

        foreach ( $reviews as $review ) {
            $model = $revisionFactory->getChunkReviewModel( $review );
            $model->recountAndUpdatePassFailResult( $project );
        }
    }

    /**
     * Entry point for project data validation for this feature.
     *
     * @param $projectStructure
     *
     * @throws \Predis\Connection\ConnectionException
     * @throws \ReflectionException
     */
    public function validateProjectCreation( $projectStructure ) {
        self::loadAndValidateModelFromJsonFile( $projectStructure );
    }

    /**
     * @param TranslationVersions\Model\BatchEventCreator $eventCreator
     *
     * @internal param SegmentTranslationEventModel $event
     */
    public function batchEventCreationSaved( Features\TranslationVersions\Model\BatchEventCreator $eventCreator ) {
        $batchReviewProcessor = new Features\ReviewExtended\Model\BatchReviewProcessor( $eventCreator );
        $batchReviewProcessor->process();
    }

    /**
     * project_completion_event_saved
     *
     * @param Chunks_ChunkStruct    $chunk
     * @param CompletionEventStruct $event
     * @param                       $completion_event_id
     */
    public function project_completion_event_saved( Chunks_ChunkStruct $chunk, CompletionEventStruct $event, $completion_event_id ) {
        if ( $event->is_review ) {
            $model = new ArchivedQualityReportModel( $chunk );
            $model->saveWithUID( $event->uid );
        } else {
            $model = new QualityReportModel( $chunk );
            $model->resetScore( $completion_event_id );
        }
    }

    /**
     *
     * @param Chunks_ChunkCompletionEventStruct $event
     *
     * @throws ValidationError
     * @throws \Exceptions\ValidationError
     * @throws \ReflectionException
     */
    public function alter_chunk_review_struct( Chunks_ChunkCompletionEventStruct $event ) {

        $review = ( new ChunkReviewDao() )->findChunkReviews( new Chunks_ChunkStruct( [ 'id' => $event->id_job, 'password' => $event->password ] ) )[ 0 ];

        $undo_data = $review->getUndoData();

        if ( is_null( $undo_data ) ) {
            throw new ValidationError( 'undo data is not available' );
        }

        $this->_validateUndoData( $event, $undo_data );

        $review->is_pass              = $undo_data[ 'is_pass' ];
        $review->penalty_points       = $undo_data[ 'penalty_points' ];
        $review->reviewed_words_count = $undo_data[ 'reviewed_words_count' ];
        $review->undo_data            = null;

        ChunkReviewDao::updateStruct( $review, [
                'fields' => [
                        'is_pass',
                        'penalty_points',
                        'reviewed_words_count',
                        'undo_data'
                ]
        ] );

        Log::doJsonLog( "CompletionEventController deleting event: " . var_export( $event->getArrayCopy(), true ) );

    }

    /**
     * @param Chunks_ChunkCompletionEventStruct $event
     * @param                                   $undo_data
     *
     * @throws ValidationError
     */
    protected function _validateUndoData( Chunks_ChunkCompletionEventStruct $event, $undo_data ) {

        try {
            Utils::ensure_keys( $undo_data, [
                    'reset_by_event_id', 'penalty_points', 'reviewed_words_count', 'is_pass'
            ] );

        } catch ( Exception $e ) {
            throw new ValidationError( 'undo data is missing some keys. ' . $e->getMessage() );
        }

        if ( $undo_data[ 'reset_by_event_id' ] != (string)$event->id ) {
            throw new ValidationError( 'event does not match with latest revision data' );
        }

    }

    public function job_password_changed( Jobs_JobStruct $job, $old_password ) {
        $dao = new ChunkReviewDao();
        $dao->updatePassword( $job->id, $old_password, $job->password );
    }

    /**
     * Sets the QA model fom the uploaded file which was previously validated
     * and added to the project structure.
     */
    private function setQaModelFromJsonFile( $projectStructure ) {

        $model_json = $projectStructure[ 'features' ][ 'review_extended' ][ '__meta' ][ 'qa_model' ];

        $model_record = ModelDao::createModelFromJsonDefinition( $model_json );

        $project = Projects_ProjectDao::findById(
                $projectStructure[ 'id_project' ]
        );

        $dao = new Projects_ProjectDao( Database::obtain() );
        $dao->updateField( $project, 'id_qa_model', $model_record->id );
    }

    /**
     * Validate the project is valid in the scope of ReviewExtended feature.
     * A project is valid if we area able to find a qa_model.json file inside
     * a __meta folder. The qa_model.json file must be valid too.
     *
     * If validation fails, adds errors to the projectStructure.
     *
     * @param             $projectStructure
     * @param null|string $jsonPath
     *
     * @throws \Predis\Connection\ConnectionException
     * @throws \ReflectionException
     */

    public static function loadAndValidateModelFromJsonFile( $projectStructure, $jsonPath = null ) {
        // detect if the project created was a zip file, in which case try to detect
        // id_qa_model from json file.
        // otherwise assign the default model

        $qa_model = false;
        $fs       = FilesStorageFactory::create();
        $zip_file = $fs->getTemporaryUploadedZipFile( $projectStructure[ 'uploadToken' ] );

        Log::doJsonLog( $zip_file );

        if ( $zip_file !== false ) {
            $zip = new ZipArchive();
            $zip->open( $zip_file );
            $qa_model = $zip->getFromName( '__meta/qa_model.json' );
        }

        // File is not a zip OR model was not found in zip

        Log::doJsonLog( "QA model is : " . var_export( $qa_model, true ) );

        if ( $qa_model === false ) {
            if ( $jsonPath == null ) {
                $qa_model = file_get_contents( INIT::$ROOT . '/inc/qa_model.json' );
            } else {
                $qa_model = file_get_contents( $jsonPath );
            }
        }

        $decoded_model = json_decode( $qa_model, true );

        if ( $decoded_model === null ) {
            $projectStructure[ 'result' ][ 'errors' ][] = [
                    'code'    => '-900',  // TODO: decide how to assign such errors
                    'message' => 'QA model failed to decode'
            ];
        }

        /**
         * Append the qa model to the project structure for later use.
         */
        if ( !array_key_exists( 'features', $projectStructure ) ) {
            $projectStructure[ 'features' ] = [];
        }

        $projectStructure[ 'features' ] = [
                'review_extended' => [
                        '__meta' => [
                                'qa_model' => $decoded_model
                        ]
                ]
        ];
    }

    /**
     * @param ChunkReviewStruct $chunkReviewStruct
     *
     * @return IChunkReviewModel
     */
    public function getChunkReviewModel( ChunkReviewStruct $chunkReviewStruct ) {
        $class_name = get_class( $this ) . '\ChunkReviewModel';

        return new $class_name( $chunkReviewStruct );
    }

    /**
     * @param SegmentTranslationChangeVector $translation
     *
     * @param ChunkReviewStruct[]            $chunkReviews
     *
     * @return ISegmentTranslationModel
     */
    public function getSegmentTranslationModel( SegmentTranslationChangeVector $translation, array $chunkReviews = [] ) {
        $class_name = get_class( $this ) . '\SegmentTranslationModel';

        return new $class_name( $translation, $chunkReviews );
    }

    /**
     * @param $id_job
     * @param $password
     * @param $issue
     *
     * @return mixed
     */
    public function getTranslationIssueModel( $id_job, $password, $issue ) {
        $class_name = get_class( $this ) . '\TranslationIssueModel';

        return new $class_name( $id_job, $password, $issue );
    }


    public function revise_summary_project_type( $old_value ) {
        return 'new';
    }

}
