<?php
namespace MediaWiki\Extension\VotingSystem;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\DerivativeRequest;
use Wikimedia\ParamValidator\ParamValidator;

class ApiVote extends ApiBase {

    public function __construct( ApiMain $main, string $name ) {
        parent::__construct( $main, $name );
    }

    public function execute(): void {
        $user = $this->getUser();
        $config = MediaWikiServices::getInstance()->getMainConfig();

        if ( !$user->isRegistered() ) {
            $this->dieWithError( 'votingsystem-vote-login-required', 'login-required' );
        }

        if ( !$user->isAllowed( 'voting' ) ) {
            $this->dieWithError( 'votingsystem-vote-autoconfirmed-required', 'permission-denied' );
        }

        $params = $this->extractRequestParams();
        $sourcePageId = (int)$params['pageid'];
        $value = (float)$params['value'];
        $commentText = trim( (string)$params['commenttext'] );
        $hideScore = (bool)$params['hidescore'];

        $pageId = VotingCalculator::getScoringRootId( $sourcePageId );

        $allowedValues = [ -1.0, -0.5, 0.0, 0.5, 1.0 ];
        $valid = false;
        foreach ( $allowedValues as $av ) {
            if ( abs( $value - $av ) < 0.001 ) {
                $value = $av;
                $valid = true;
                break;
            }
        }
        if ( !$valid ) {
            $this->dieWithError( 'votingsystem-vote-invalid-value', 'invalid-value' );
        }

        $hasComment = $commentText !== '';
        if ( $config->get( 'VotingSystemRequireComment' ) && !$hasComment ) {
            $this->dieWithError( 'votingsystem-vote-comment-required', 'comment-required' );
        }

        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();

        $dbr = $lb->getConnection( DB_REPLICA );
        $pageRow = $dbr->selectRow(
            'page',
            [ 'page_id', 'page_namespace' ],
            [ 'page_id' => $pageId ],
            __METHOD__
        );

        if ( !$pageRow ) {
            $this->dieWithError( 'votingsystem-vote-invalid-page', 'invalid-page' );
        }
        if ( (int)$pageRow->page_namespace !== NS_MAIN ) {
            $this->dieWithError( 'votingsystem-vote-invalid-namespace', 'invalid-namespace' );
        }

        $existing = $dbr->selectRow(
            'voting_votes',
            'vote_id',
            [
                'vote_page_id' => $pageId,
                'vote_user_id' => $user->getId()
            ],
            __METHOD__
        );

        // 标准 RateLimiter，仅对新投票生效
        if ( !$existing ) {
            if ( $user->pingLimiter( 'voting-hourly' ) ) {
                $this->dieWithError( 'votingsystem-vote-rate-limited', 'rate-limited-hourly' );
            }
            if ( $user->pingLimiter( 'voting-daily' ) ) {
                $this->dieWithError( 'votingsystem-vote-rate-limited', 'rate-limited-daily' );
            }
        }

        // 尝试发评论（如果装了 CommentStreams）。
        // 无论成功与否都不影响权重计算。
        $commentPageId = null;
        if ( $hasComment && VotingCalculator::isCommentStreamsAvailable() ) {
            $commentPageId = $this->postComment( $sourcePageId, $commentText, $value, $hideScore );
        }

        // 权重完全取决于用户是否写了理由，与 CS 返回无关
        $weight = VotingCalculator::getUserWeight( $user, $hasComment );

        $dbw = $lb->getConnection( DB_PRIMARY );
        $timestamp = wfTimestampNow();

        $rowData = [
            'vote_value' => $value,
            'vote_weight' => $weight,
            'vote_comment_id' => $commentPageId,
            'vote_comment_text' => $hasComment ? $commentText : null,
            'vote_comment_timestamp' => $hasComment ? $timestamp : null,
            'vote_hide_score' => $hideScore ? 1 : 0,
            'vote_timestamp' => $timestamp,
            'vote_namespace' => NS_MAIN,
        ];

        if ( $existing ) {
            $dbw->update( 'voting_votes', $rowData, [ 'vote_id' => $existing->vote_id ], __METHOD__ );
        } else {
            $rowData['vote_page_id'] = $pageId;
            $rowData['vote_user_id'] = $user->getId();
            $rowData['vote_user_text'] = $user->getName();
            $dbw->insert( 'voting_votes', $rowData, __METHOD__ );
        }

        $scoreResult = VotingCalculator::calculateScore( $pageId );

        $this->getResult()->addValue( null, 'result', 'ok' );
        $this->getResult()->addValue( null, 'pageid', $pageId );
        $this->getResult()->addValue( null, 'sourcepageid', $sourcePageId );
        $this->getResult()->addValue( null, 'votevalue', $value );
        $this->getResult()->addValue( null, 'voteweight', $weight );
        $this->getResult()->addValue( null, 'hascomment', $hasComment );
        $this->getResult()->addValue( null, 'commentposted', $commentPageId !== null );
        $this->getResult()->addValue( null, 'hidescore', $hideScore );
        $this->getResult()->addValue( null, 'score', $scoreResult['score'] );
        $this->getResult()->addValue( null, 'votecount', $scoreResult['voteCount'] );
    }

    /**
     * 尝试通过 CommentStreams API 发表评论。
     * 失败或不装 CS 都不影响投票，只影响评论是否出现在评论区。
     */
private function postComment( int $pageId, string $text, float $value, bool $hideScore ): ?int {
    if ( !VotingCalculator::isCommentStreamsAvailable() ) {
        return null;
    }

    if ( $hideScore ) {
        $title = wfMessage( 'votingsystem-vote-title-hidden' )->text();
    } else {
        $scoreText = $this->formatScore( $value );
        $title = wfMessage( 'votingsystem-vote-title-score', $scoreText )->text();
    }

    $api = new ApiMain(
        new DerivativeRequest(
            $this->getRequest(),
            [
                'action' => 'cspostcomment',
                'format' => 'json',
                'commenttitle' => $title,
                'wikitext' => $text,
                'associatedid' => $pageId,
                'token' => $this->getUser()->getEditToken(),
            ]
        ),
        true
    );

    try {
        $api->execute();
    } catch ( \Exception $e ) {
        wfDebugLog(
            'VotingSystem',
            'CS postComment failed for page ' . $pageId . ': ' . $e->getMessage()
        );
        return null;
    }

    $result = $api->getResult()->getResultData();

    if ( isset( $result['error'] ) ) {
        wfDebugLog(
            'VotingSystem',
            'CS postComment API error for page ' . $pageId . ': '
                . ( $result['error']['info'] ?? 'unknown' )
        );
        return null;
    }

    // 尝试各种可能的结构
    if ( isset( $result['cspostcomment']['commentid'] ) ) {
        return (int)$result['cspostcomment']['commentid'];
    }
    if ( isset( $result['cspostcomment']['pageid'] ) ) {
        return (int)$result['cspostcomment']['pageid'];
    }
    if ( isset( $result['cspostcomment']['id'] ) ) {
        return (int)$result['cspostcomment']['id'];
    }
    if ( isset( $result['cspostcomment']['commentId'] ) ) {
        return (int)$result['cspostcomment']['commentId'];
    }

    return null;
}

    private function formatScore( float $value ): string {
        if ( $value > 0 ) {
            return '+' . rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' );
        }
        if ( $value < 0 ) {
            return rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' );
        }
        return '0';
    }

    public function getAllowedParams(): array {
        return [
            'pageid' => [ ParamValidator::PARAM_TYPE => 'integer', ParamValidator::PARAM_REQUIRED => true ],
            'value' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_REQUIRED => true ],
            'commenttext' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_REQUIRED => false, ParamValidator::PARAM_DEFAULT => '' ],
            'hidescore' => [ ParamValidator::PARAM_TYPE => 'boolean', ParamValidator::PARAM_REQUIRED => false, ParamValidator::PARAM_DEFAULT => false ],
        ];
    }

    public function needsToken(): string { return 'csrf'; }
    public function isWriteMode(): bool { return true; }

    protected function getExamplesMessages(): array {
        return [ 'action=voting&pageid=123&value=1' => 'apihelp-voting-example-1' ];
    }
}