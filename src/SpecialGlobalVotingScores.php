<?php
namespace MediaWiki\Extension\VotingSystem;

use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class SpecialGlobalVotingScores extends SpecialPage {

    public function __construct() {
        parent::__construct( 'GlobalVotingScores' );
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

        if ( $par !== null && $par !== '' ) {
            $params = [];
            parse_str( $par, $params );
            if ( isset( $params['limit'] ) ) {
                $limit = (int)$params['limit'];
            }
            if ( isset( $params['order'] ) ) {
                $order = $params['order'];
            }
        }

        if ( $order === null ) {
            $order = $request->getVal( 'order', 'desc' );
        }
        if ( $limit === null ) {
            $limit = (int)$request->getVal( 'limit', 500 );
        }

        $limit = max( 1, min( $limit, 5000 ) );
        $sortAsc = ( $order === 'asc' );

        $out->addModuleStyles( [ 'ext.voting' ] );

        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbr = $lb->getConnection( DB_REPLICA );

        // 遍历所有主命名空间、非重定向页面
        $res = $dbr->select(
            'page',
            [ 'page_id', 'page_namespace', 'page_title' ],
            [
                'page_namespace' => NS_MAIN,
                'page_is_redirect' => 0,
            ],
            __METHOD__
        );

        $excludedList = VotingCalculator::getExcludedPageList();

        $pageIds = [];
        $pageTitles = [];
        foreach ( $res as $row ) {
            $pid = (int)$row->page_id;
            $title = Title::makeTitle(
                (int)$row->page_namespace,
                $row->page_title
            );
            if ( !$title ) {
                continue;
            }

            // 只保留评分根页面（子页面聚合到根页面）
            $rootId = VotingCalculator::getScoringRootIdForTitle( $title );
            if ( $rootId !== $pid ) {
                continue;
            }

            if ( VotingCalculator::matchesExclusionList( $title, $excludedList ) ) {
                continue;
            }

            $pageIds[] = $pid;
            $pageTitles[$pid] = $title;
        }

        if ( empty( $pageIds ) ) {
            $out->addHTML( '<p>' . $this->msg( 'votingsystem-no-votes' )->escaped() . '</p>' );
            return;
        }

        // 一次批量算分；没票的页面在 $scores 里对应 null
        $scores = VotingCalculator::calculateScores( $pageIds );

        $rows = [];
        foreach ( $pageIds as $pid ) {
            $data = $scores[$pid] ?? [ 'score' => null, 'voteCount' => 0 ];
            $rows[] = [
                'pageId' => $pid,
                'title' => $pageTitles[$pid],
                'score' => $data['score'] ?? 0.0,
                'voteCount' => $data['voteCount'],
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
            $out->setPageTitle( $this->msg( 'votingsystem-global-title' )->text() );

            $toggleOrder = $sortAsc ? 'desc' : 'asc';
            $toggleLabel = $sortAsc
                ? $this->msg( 'votingsystem-order-desc' )->text()
                : $this->msg( 'votingsystem-order-asc' )->text();
            $toggleUrl = $this->getPageTitle()->getLocalURL( [
                'order' => $toggleOrder,
                'limit' => $limit
            ] );

            $html .= '<div class="voting-controls">';
            $html .= '<a href="' . htmlspecialchars( $toggleUrl ) . '" class="voting-toggle-order">'
                . htmlspecialchars( $toggleLabel ) . '</a>';
            $html .= '</div>';
        }

        $html .= '<table class="wikitable voting-scores-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . $this->msg( 'votingsystem-table-rank' )->escaped() . '</th>';
        $html .= '<th>' . $this->msg( 'votingsystem-table-page' )->escaped() . '</th>';
        $html .= '<th>' . $this->msg( 'votingsystem-table-score' )->escaped() . '</th>';
        $html .= '<th>' . $this->msg( 'votingsystem-table-votecount' )->escaped() . '</th>';
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
            $html .= '<td>' . (int)$row['voteCount'] . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        if ( !$this->including() && $total > $limit ) {
            $html .= '<p>' . $this->msg( 'votingsystem-showing-partial', $limit, $total )->escaped() . '</p>';
        }

        if ( $this->including() && $total > $limit ) {
            $fullUrl = $this->getPageTitle()->getLocalURL();
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