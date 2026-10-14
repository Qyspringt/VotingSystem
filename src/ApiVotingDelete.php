<?php
namespace MediaWiki\Extension\VotingSystem;

use ManualLogEntry;
use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

class ApiVotingDelete extends ApiBase {

    public function __construct( ApiMain $main, string $name ) {
        parent::__construct( $main, $name );
    }

    public function execute(): void {
        $user = $this->getUser();

        if ( !$user->isAllowed( 'voting-delete' ) ) {
            $this->dieWithError( 'apierror-permissiondenied', 'permission-denied' );
        }

        $params = $this->extractRequestParams();
        $voteId = (int)$params['voteid'];
        $reason = trim( (string)$params['reason'] );

        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $lb->getConnection( DB_PRIMARY );

        $row = $dbw->selectRow(
            'voting_votes',
            [
                'vote_id',
                'vote_page_id',
                'vote_user_text',
                'vote_value',
                'vote_weight',
                'vote_comment_text',
            ],
            [ 'vote_id' => $voteId ],
            __METHOD__
        );

        if ( !$row ) {
            $this->dieWithError( 'votingsystem-vote-delete-not-found', 'vote-not-found' );
        }

        // 撤销前的信息，用于审计日志
        $removedUser = $row->vote_user_text;
        $removedValue = (float)$row->vote_value;
        $removedWeight = (float)$row->vote_weight;
        $hadComment = ( $row->vote_comment_text !== null && trim( $row->vote_comment_text ) !== '' );
        $pageId = (int)$row->vote_page_id;

        $dbw->delete(
            'voting_votes',
            [ 'vote_id' => $voteId ],
            __METHOD__
        );

        // 写审计日志
        $title = Title::newFromID( $pageId );
        if ( $title ) {
            $valueText = ( $removedValue > 0 ? '+' : '' )
                . rtrim( rtrim( number_format( $removedValue, 1, '.', '' ), '0' ), '.' );
            if ( $valueText === '+' || $valueText === '' ) {
                $valueText = '0';
            }

            $logEntry = new ManualLogEntry( 'voting', 'revoke' );
            $logEntry->setPerformer( $user );
            $logEntry->setTarget( $title );
            $logEntry->setParameters( [
                '4::removedUser' => $removedUser,
                '5::value' => $valueText,
                '6::weight' => number_format( $removedWeight, 2 ),
                '7::hadComment' => $hadComment ? '1' : '0',
            ] );
            if ( $reason !== '' ) {
                $logEntry->setComment( $reason );
            }
            $logId = $logEntry->insert();
            $logEntry->publish( $logId );
        }

        $this->getResult()->addValue( null, 'result', 'ok' );
        $this->getResult()->addValue( null, 'voteid', $voteId );
    }

    public function getAllowedParams(): array {
        return [
            'voteid' => [
                ParamValidator::PARAM_TYPE => 'integer',
                ParamValidator::PARAM_REQUIRED => true,
            ],
            'reason' => [
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => false,
                ParamValidator::PARAM_DEFAULT => '',
            ],
        ];
    }

    public function needsToken() {
        return 'csrf';
    }

    public function isWriteMode() {
        return true;
    }

    public function mustBePosted() {
        return true;
    }
}