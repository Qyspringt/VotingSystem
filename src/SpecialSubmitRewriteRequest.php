<?php
namespace MediaWiki\Extension\VotingSystem;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class SpecialSubmitRewriteRequest extends SpecialPage {

    public function __construct() {
        parent::__construct( 'SubmitRewriteRequest' );
    }

    public function execute( $par ): void {
        $this->setHeaders();
        $out = $this->getOutput();
        $user = $this->getUser();

        if ( !$user->isRegistered() ) {
            $out->addWikiMsg( 'votingsystem-rewrite-login-required' );
            return;
        }

        $out->setPageTitle( $this->msg( 'votingsystem-rewrite-submit-title' )->text() );

        $formDescriptor = [
            'page' => [
                'type' => 'title',
                'name' => 'page',
                'label-message' => 'votingsystem-rewrite-page-label',
                'required' => true,
                'creatable' => false,
                'exists' => true,
            ],
            'reason' => [
                'type' => 'textarea',
                'name' => 'reason',
                'label-message' => 'votingsystem-rewrite-reason-label',
                'required' => true,
                'rows' => 5,
                'validation-callback' => function ( $value ) {
                    if ( strlen( $value ) < 10 ) {
                        return wfMessage( 'votingsystem-rewrite-reason-too-short' )->text();
                    }
                    return true;
                }
            ],
        ];

        $htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
        $htmlForm->setSubmitTextMsg( 'votingsystem-rewrite-submit-btn' );
        $htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );
        $htmlForm->show();

        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbr = $lb->getConnection( DB_REPLICA );

        $res = $dbr->select(
            'voting_rewrite_requests',
            [ 'request_id', 'request_page_id', 'request_status', 'request_timestamp' ],
            [ 'request_user_id' => $user->getId() ],
            __METHOD__,
            [ 'ORDER BY' => 'request_timestamp DESC', 'LIMIT' => 20 ]
        );

        $requests = [];
        foreach ( $res as $row ) {
            $requests[] = $row;
        }

        if ( !empty( $requests ) ) {
            $out->addHTML( '<h2>' . $this->msg( 'votingsystem-rewrite-my-requests' )->escaped() . '</h2>' );
            $statusMap = [
                0 => 'votingsystem-rewrite-status-pending',
                1 => 'votingsystem-rewrite-status-approved',
                2 => 'votingsystem-rewrite-status-rejected',
            ];

            $out->addHTML( '<table class="wikitable">' );
            $out->addHTML( '<tr><th>页面</th><th>状态</th><th>申请时间</th></tr>' );

            foreach ( $requests as $req ) {
                $title = Title::newFromID( (int)$req->request_page_id );
                $pageText = $title ? $title->getPrefixedText() : '#' . $req->request_page_id;
                $statusKey = $statusMap[(int)$req->request_status] ?? 'votingsystem-rewrite-status-unknown';
                $out->addHTML( '<tr>' );
                $out->addHTML( '<td>' . htmlspecialchars( $pageText ) . '</td>' );
                $out->addHTML( '<td>' . $this->msg( $statusKey )->escaped() . '</td>' );
                $out->addHTML( '<td>' . htmlspecialchars( wfTimestamp( TS_RFC2822, $req->request_timestamp ) ) . '</td>' );
                $out->addHTML( '</tr>' );
            }

            $out->addHTML( '</table>' );
        }
    }

    public function onSubmit( array $data ): bool {
        $user = $this->getUser();
        $title = Title::newFromText( $data['page'] );

        if ( !$title || !$title->exists() ) {
            return wfMessage( 'votingsystem-rewrite-invalid-page' )->text();
        }

        if ( $title->getNamespace() !== NS_MAIN ) {
            return wfMessage( 'votingsystem-rewrite-invalid-namespace' )->text();
        }

        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $lb->getConnection( DB_PRIMARY );

        $existing = $dbw->selectRow(
            'voting_rewrite_requests',
            'request_id',
            [
                'request_page_id' => $title->getArticleID(),
                'request_user_id' => $user->getId(),
                'request_status' => 0,
            ],
            __METHOD__
        );

        if ( $existing ) {
            return wfMessage( 'votingsystem-rewrite-already-pending' )->text();
        }

        $dbw->insert(
            'voting_rewrite_requests',
            [
                'request_page_id' => $title->getArticleID(),
                'request_user_id' => $user->getId(),
                'request_user_text' => $user->getName(),
                'request_reason' => $data['reason'],
                'request_status' => 0,
                'request_timestamp' => wfTimestampNow(),
            ],
            __METHOD__
        );

        $this->getOutput()->addWikiMsg( 'votingsystem-rewrite-submitted' );
        return true;
    }

    protected function getGroupName(): string {
        return 'voting';
    }
}