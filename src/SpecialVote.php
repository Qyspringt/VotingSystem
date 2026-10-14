<?php
namespace MediaWiki\Extension\VotingSystem;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class SpecialVote extends SpecialPage {

    public function __construct() {
        parent::__construct( 'Vote' );
    }

    public function execute( $par ): void {
        $this->setHeaders();
        $out = $this->getOutput();

        $pageId = 0;
        if ( $par !== null && $par !== '' ) {
            $title = Title::newFromText( $par );
            if ( $title && $title->exists() ) {
                $pageId = $title->getArticleID();
            }
        }

        if ( !$pageId ) {
            $request = $this->getRequest();
            $pageId = (int)$request->getVal( 'pageid', 0 );
        }

        if ( !$pageId ) {
            $out->addWikiMsg( 'votingsystem-vote-no-page' );
            return;
        }

        $title = Title::newFromID( $pageId );
        if ( !$title ) {
            $out->addWikiMsg( 'votingsystem-vote-no-page' );
            return;
        }

        $out->setPageTitle(
            $this->msg( 'votingsystem-vote-title', $title->getPrefixedText() )->text()
        );

        $html = '<div class="voting-panel-special">';
        $html .= '<p>' . $this->msg( 'votingsystem-vote-intro' )->text() . '</p>';
        $html .= '</div>';

        $out->addHTML( $html );
    }

    protected function getGroupName(): string {
        return 'voting';
    }
}