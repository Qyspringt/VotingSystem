<?php
namespace MediaWiki\Extension\VotingSystem;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

class ApiVotingDetails extends ApiBase {

    public function __construct( ApiMain $main, string $name ) {
        parent::__construct( $main, $name );
    }

    public function execute(): void {
        $user = $this->getUser();

        $params = $this->extractRequestParams();
        $pageId = (int)$params['pageid'];

        $title = Title::newFromID( $pageId );
        if ( !$title ) {
            $this->dieWithError( 'votingsystem-vote-invalid-page', 'invalid-page' );
        }

        $scoringRootId = VotingCalculator::getScoringRootIdForTitle( $title );

        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbr = $lb->getConnection( DB_REPLICA );

        $myVote = null;
        if ( $user->isRegistered() ) {
            $row = $dbr->selectRow(
                'voting_votes',
                'vote_value',
                [
                    'vote_page_id' => $scoringRootId,
                    'vote_user_id' => $user->getId()
                ],
                __METHOD__
            );
            if ( $row ) {
                $myVote = (float)$row->vote_value;
            }
        }

        $canView = $user->isAllowed( 'voting-review' );
        $canDelete = $user->isAllowed( 'voting-delete' );

        $this->getResult()->addValue( null, 'pageid', $pageId );
        $this->getResult()->addValue( null, 'scoringRootId', $scoringRootId );
        $this->getResult()->addValue( null, 'canView', $canView ? 1 : 0 );
        $this->getResult()->addValue( null, 'canDelete', $canDelete ? 1 : 0 );
        $this->getResult()->addValue( null, 'myVote', $myVote );

        if ( !$canView ) {
            $this->getResult()->addValue( null, 'votes', [] );
            return;
        }

        $res = $dbr->select(
            'voting_votes',
            [ 'vote_id', 'vote_user_text', 'vote_value', 'vote_weight', 'vote_timestamp' ],
            [ 'vote_page_id' => $scoringRootId ],
            __METHOD__,
            [ 'ORDER BY' => 'vote_timestamp DESC' ]
        );

        $linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
        $votes = [];

        foreach ( $res as $row ) {
            $value = (float)$row->vote_value;
            $valueText = ( $value > 0 ? '+' : '' ) . rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' );
            if ( $valueText === '+' || $valueText === '' ) {
                $valueText = '0';
            }

            $userPageTitle = Title::makeTitleSafe( NS_USER, $row->vote_user_text );
            $userHtml = $userPageTitle
                ? $linkRenderer->makeLink( $userPageTitle, $row->vote_user_text )
                : htmlspecialchars( $row->vote_user_text );

            $votes[] = [
                'voteId' => (int)$row->vote_id,
                'user' => $row->vote_user_text,
                'userHtml' => $userHtml,
                'value' => $valueText,
                'weight' => number_format( (float)$row->vote_weight, 2 ),
                'timestamp' => wfTimestamp( TS_RFC2822, $row->vote_timestamp ),
            ];
        }

        $this->getResult()->addValue( null, 'votes', $votes );
    }

    public function getAllowedParams(): array {
        return [
            'pageid' => [
                ParamValidator::PARAM_TYPE => 'integer',
                ParamValidator::PARAM_REQUIRED => true,
            ],
        ];
    }

    public function needsToken() {
        return false;
    }

    public function isWriteMode() {
        return false;
    }

    public function mustBePosted() {
        return false;
    }
}