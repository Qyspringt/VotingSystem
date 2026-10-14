<?php
namespace MediaWiki\Extension\VotingSystem;

use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class SpecialReviewRewriteRequests extends SpecialPage {

    public function __construct() {
        parent::__construct( 'ReviewRewriteRequests' );
    }

    public function execute( $par ): void {
        $this->setHeaders();
        $out = $this->getOutput();
        $user = $this->getUser();

        if ( !$user->isAllowed( 'voting-review' ) ) {
            $out->addWikiMsg( 'votingsystem-review-no-permission' );
            return;
        }

        $out->setPageTitle( $this->msg( 'votingsystem-review-title' )->text() );

        $request = $this->getRequest();
        $action = $request->getVal( 'action', '' );
        $requestId = (int)$request->getVal( 'requestid', 0 );

        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();

        if ( in_array( $action, [ 'approve', 'reject' ], true ) && $requestId > 0 ) {
            $csrfTokenSet = $this->getContext()->getCsrfTokenSet();
            if ( !$csrfTokenSet->matchToken( $request->getVal( 'token', '' ) ) ) {
                $out->addWikiMsg( 'votingsystem-review-invalid-token' );
                return;
            }

            $dbw = $lb->getConnection( DB_PRIMARY );
            $newStatus = ( $action === 'approve' ) ? 1 : 2;

            $affected = $dbw->update(
                'voting_rewrite_requests',
                [
                    'request_status' => $newStatus,
                    'request_reviewer_id' => $user->getId(),
                    'request_reviewer_text' => $user->getName(),
                    'request_review_timestamp' => wfTimestampNow(),
                ],
                [
                    'request_id' => $requestId,
                    'request_status' => 0,
                ],
                __METHOD__
            );

            if ( $affected > 0 ) {
                $msg = ( $action === 'approve' )
                    ? 'votingsystem-review-approved'
                    : 'votingsystem-review-rejected';
                $out->addWikiMsg( $msg );
            } else {
                $out->addWikiMsg( 'votingsystem-review-not-found' );
            }
        }

        $dbr = $lb->getConnection( DB_REPLICA );
        $res = $dbr->select(
            'voting_rewrite_requests',
            '*',
            [ 'request_status' => 0 ],
            __METHOD__,
            [ 'ORDER BY' => 'request_timestamp ASC' ]
        );

        $pending = [];
        foreach ( $res as $row ) {
            $pending[] = $row;
        }

        if ( empty( $pending ) ) {
            $out->addWikiMsg( 'votingsystem-review-no-pending' );
            return;
        }

        $linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
        $csrfTokenSet = $this->getContext()->getCsrfTokenSet();
        $token = $csrfTokenSet->getToken();

        $out->addHTML( '<table class="wikitable">' );
        $out->addHTML( '<tr>'
            . '<th>' . $this->msg( 'votingsystem-review-table-page' )->escaped() . '</th>'
            . '<th>' . $this->msg( 'votingsystem-review-table-user' )->escaped() . '</th>'
            . '<th>' . $this->msg( 'votingsystem-review-table-reason' )->escaped() . '</th>'
            . '<th>' . $this->msg( 'votingsystem-review-table-time' )->escaped() . '</th>'
            . '<th>' . $this->msg( 'votingsystem-review-table-actions' )->escaped() . '</th>'
            . '</tr>' );

        foreach ( $pending as $req ) {
            $title = Title::newFromID( (int)$req->request_page_id );
            $pageText = $title ? $title->getPrefixedText() : '#' . $req->request_page_id;
            $pageLink = $title
                ? $linkRenderer->makeLink( $title, htmlspecialchars( $pageText ) )
                : htmlspecialchars( $pageText );

            $approveUrl = $this->getPageTitle()->getLocalURL( [
                'action' => 'approve',
                'requestid' => $req->request_id,
                'token' => $token,
            ] );
            $rejectUrl = $this->getPageTitle()->getLocalURL( [
                'action' => 'reject',
                'requestid' => $req->request_id,
                'token' => $token,
            ] );

            $out->addHTML( '<tr>' );
            $out->addHTML( '<td>' . $pageLink . '</td>' );
            $out->addHTML( '<td>' . htmlspecialchars( $req->request_user_text ) . '</td>' );
            $out->addHTML( '<td>' . nl2br( htmlspecialchars( $req->request_reason ) ) . '</td>' );
            $out->addHTML( '<td>' . htmlspecialchars( wfTimestamp( TS_RFC2822, $req->request_timestamp ) ) . '</td>' );
            $out->addHTML( '<td>'
                . '<a href="' . htmlspecialchars( $approveUrl ) . '" class="voting-review-approve">'
                . $this->msg( 'votingsystem-review-approve' )->escaped() . '</a> | '
                . '<a href="' . htmlspecialchars( $rejectUrl ) . '" class="voting-review-reject">'
                . $this->msg( 'votingsystem-review-reject' )->escaped() . '</a>'
                . '</td>' );
            $out->addHTML( '</tr>' );
        }

        $out->addHTML( '</table>' );
    }

    protected function getGroupName(): string {
        return 'voting';
    }
}