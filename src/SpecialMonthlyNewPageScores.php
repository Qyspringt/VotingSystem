<?php
namespace MediaWiki\Extension\VotingSystem;

use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class SpecialMonthlyNewPageScores extends SpecialPage {

    public function __construct() {
        parent::__construct( 'MonthlyNewPageScores' );
    }

    public function isIncludable(): bool {
        return true;
    }

    public function execute( $par ): void {
        $this->setHeaders();
        $out = $this->getOutput();
        $request = $this->getRequest();

        $limit = null;
        $order = null;
        $month = null;

        if ( $par !== null && $par !== '' ) {
            $params = [];
            parse_str( $par, $params );
            if ( isset( $params['limit'] ) ) {
                $limit = (int)$params['limit'];
            }
            if ( isset( $params['order'] ) ) {
                $order = $params['order'];
            }
            if ( isset( $params['month'] ) ) {
                $month = $params['month'];
            }
        }

        if ( $order === null ) {
            $order = $request->getVal( 'order', 'desc' );
        }
        if ( $limit === null ) {
            $limit = (int)$request->getVal( 'limit', 100 );
        }
        if ( $month === null ) {
            $month = $request->getVal( 'month', date( 'Y-m' ) );
        }

        if ( !preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
            $month = date( 'Y-m' );
        }

        $limit = max( 1, min( $limit, 1000 ) );
        $sortAsc = ( $order === 'asc' );

        $out->addModuleStyles( [ 'ext.voting' ] );

        $pageIds = VotingCalculator::getMonthlyNewPageIds( $month );

        if ( empty( $pageIds ) ) {
            $out->addHTML( '<p>' . $this->msg( 'votingsystem-no-monthly-pages', $month )->escaped() . '</p>' );
            return;
        }

        $scores = VotingCalculator::calculateScores( $pageIds );

        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbr = $lb->getConnection( DB_REPLICA );

        $pageTitles = [];
        $titleRes = $dbr->select(
            'page',
            [ 'page_id', 'page_namespace', 'page_title' ],
            [
                'page_id' => $pageIds,
                'page_namespace' => NS_MAIN,
                'page_is_redirect' => 0,
            ],
            __METHOD__
        );
        foreach ( $titleRes as $row ) {
            $pageTitles[(int)$row->page_id] = Title::makeTitle(
                (int)$row->page_namespace,
                $row->page_title
            );
        }

        $rows = [];
        foreach ( $pageIds as $pid ) {
            if ( !isset( $pageTitles[$pid] ) ) {
                continue;
            }

            $data = $scores[$pid] ?? [ 'score' => null, 'voteCount' => 0 ];
            $score = $data['score'];
            $voteCount = $data['voteCount'];

            if ( $score === null ) {
                $score = 0.0;
            }

            $rows[] = [
                'pageId' => $pid,
                'title' => $pageTitles[$pid],
                'score' => $score,
                'voteCount' => $voteCount,
            ];
        }

        usort( $rows, function ( $a, $b ) use ( $sortAsc ) {
            if ( $a['score'] !== $b['score'] ) {
                $cmp = $a['score'] < $b['score'] ? -1 : 1;
                return $sortAsc ? $cmp : -$cmp;
            }
            if ( $a['voteCount'] !== $b['voteCount'] ) {
                $cmp = $a['voteCount'] < $b['voteCount'] ? -1 : 1;
                return $sortAsc ? $cmp : -$cmp;
            }
            return strcmp( $a['title']->getText(), $b['title']->getText() );
        } );

        $total = count( $rows );
        $displayRows = array_slice( $rows, 0, $limit );

        $linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

        $html = '<div class="voting-scores-wrapper">';

        if ( !$this->including() ) {
            $out->setPageTitle( $this->msg( 'votingsystem-monthly-title', $month )->text() );

            $prevMonth = date( 'Y-m', strtotime( $month . '-01 -1 month' ) );
            $nextMonth = date( 'Y-m', strtotime( $month . '-01 +1 month' ) );

            $toggleOrder = $sortAsc ? 'desc' : 'asc';
            $toggleLabel = $sortAsc
                ? $this->msg( 'votingsystem-order-desc' )->text()
                : $this->msg( 'votingsystem-order-asc' )->text();
            $toggleUrl = $this->getPageTitle()->getLocalURL( [
                'order' => $toggleOrder,
                'limit' => $limit,
                'month' => $month
            ] );

            $html .= '<div class="voting-controls">';
            $html .= '<a href="' . htmlspecialchars( $this->getPageTitle()->getLocalURL( [
                'month' => $prevMonth, 'order' => $order, 'limit' => $limit
            ] ) ) . '">← ' . $this->msg( 'votingsystem-prev-month' )->escaped() . '</a>';
            $html .= ' | ';
            $html .= '<a href="' . htmlspecialchars( $this->getPageTitle()->getLocalURL( [
                'month' => $nextMonth, 'order' => $order, 'limit' => $limit
            ] ) ) . '">' . $this->msg( 'votingsystem-next-month' )->escaped() . ' →</a>';
            $html .= ' | ';
            $html .= '<a href="' . htmlspecialchars( $toggleUrl ) . '" class="voting-toggle-order">'
                . htmlspecialchars( $toggleLabel ) . '</a>';
            $html .= '</div>';
        }

        $html .= '<table class="wikitable voting-scores-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . htmlspecialchars( $this->msg( 'votingsystem-table-rank' )->text() ) . '</th>';
        $html .= '<th>' . htmlspecialchars( $this->msg( 'votingsystem-table-page' )->text() ) . '</th>';
        $html .= '<th>' . htmlspecialchars( $this->msg( 'votingsystem-table-score' )->text() ) . '</th>';
        $html .= '<th>' . htmlspecialchars( $this->msg( 'votingsystem-table-votecount' )->text() ) . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ( $displayRows as $i => $row ) {
            $rank = $i + 1;
            $link = $linkRenderer->makeLink(
                $row['title'],
                htmlspecialchars( $row['title']->getPrefixedText() )
            );
            $html .= '<tr>';
            $html .= '<td>' . $rank . '</td>';
            $html .= '<td>' . $link . '</td>';
            $html .= '<td>' . htmlspecialchars( (string)$row['score'] ) . '</td>';
            $html .= '<td>' . $row['voteCount'] . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        if ( $this->including() && $total > $limit ) {
            $fullUrl = $this->getPageTitle()->getLocalURL( [ 'month' => $month ] );
            $html .= '<div class="voting-more-link"><a href="' . htmlspecialchars( $fullUrl ) . '">'
                . $this->msg( 'votingsystem-view-full' )->escaped() . '</a></div>';
        }

        $html .= '</div>';

        $out->addHTML( $html );
    }

    protected function getGroupName(): string {
        return 'voting';
    }
}