<?php
namespace MediaWiki\Extension\VotingSystem;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class VotingCalculator {

    private const PRIOR_WEIGHT = 3;
    private const PRIOR_MEAN = 0.0;

    /** @var array 请求级评分根缓存 */
    private static $scoringRootCache = [];

    /** @var array|null 请求级排除名单缓存 */
    private static $excludedListCache = null;

    public static function calculateScore( int $pageId ): array {
        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbr = $lb->getConnection( DB_REPLICA );

        $res = $dbr->select(
            'voting_votes',
            [ 'vote_value', 'vote_weight', 'vote_comment_text' ],
            [ 'vote_page_id' => $pageId ],
            __METHOD__
        );

        $weighted = [];
        foreach ( $res as $row ) {
            $weight = self::resolveWeight( (float)$row->vote_weight, $row->vote_comment_text );
            $weighted[] = [ 'value' => (float)$row->vote_value, 'weight' => $weight ];
        }

        return self::computeFromValues( $weighted );
    }

    public static function calculateScores( array $pageIds ): array {
        if ( empty( $pageIds ) ) {
            return [];
        }

        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbr = $lb->getConnection( DB_REPLICA );

        $res = $dbr->select(
            'voting_votes',
            [ 'vote_page_id', 'vote_value', 'vote_weight', 'vote_comment_text' ],
            [ 'vote_page_id' => $pageIds ],
            __METHOD__,
            [ 'ORDER BY' => 'vote_page_id ASC' ]
        );

        $grouped = [];
        foreach ( $res as $row ) {
            $pid = (int)$row->vote_page_id;
            $weight = self::resolveWeight( (float)$row->vote_weight, $row->vote_comment_text );
            $grouped[$pid][] = [ 'value' => (float)$row->vote_value, 'weight' => $weight ];
        }

        $result = [];
        foreach ( $pageIds as $pid ) {
            $result[$pid] = self::computeFromValues( $grouped[$pid] ?? [] );
        }

        return $result;
    }

    public static function getScoringRootId( int $pageId ): int {
        $title = Title::newFromID( $pageId );
        if ( !$title ) {
            return $pageId;
        }
        return self::getScoringRootIdForTitle( $title );
    }

    public static function getScoringRootIdForTitle( Title $title ): int {
        $cacheKey = $title->getNamespace() . ':' . $title->getText();
        if ( isset( self::$scoringRootCache[$cacheKey] ) ) {
            return self::$scoringRootCache[$cacheKey];
        }

        $text = $title->getText();
        $parts = explode( '/', $text );
        $namespace = $title->getNamespace();

        if ( count( $parts ) <= 1 ) {
            $result = self::followRedirects( $title->getArticleID() );
            self::$scoringRootCache[$cacheKey] = $result;
            return $result;
        }

        $prefixes = self::getIndependentPrefixes();

        $deepestIndex = -1;
        foreach ( $parts as $i => $part ) {
            foreach ( $prefixes as $prefix ) {
                if ( $prefix !== '' && strpos( $part, $prefix ) === 0 ) {
                    $deepestIndex = $i;
                    break;
                }
            }
        }

        if ( $deepestIndex === -1 ) {
            $rootText = $parts[0];
        } else {
            $rootText = implode( '/', array_slice( $parts, 0, $deepestIndex + 1 ) );
        }

        $rootTitle = Title::newFromText( $rootText, $namespace );
        if ( !$rootTitle || $rootTitle->getArticleID() <= 0 ) {
            $result = $title->getArticleID();
            self::$scoringRootCache[$cacheKey] = $result;
            return $result;
        }

        $result = self::followRedirects( $rootTitle->getArticleID() );
        self::$scoringRootCache[$cacheKey] = $result;
        return $result;
    }

    public static function isExcludedPage( Title $title ): bool {
        $list = self::getExcludedPageList();
        if ( empty( $list ) ) {
            return false;
        }

        if ( self::matchesExclusionList( $title, $list ) ) {
            return true;
        }

        $rootId = self::getScoringRootIdForTitle( $title );
        if ( $rootId && $rootId !== $title->getArticleID() ) {
            $rootTitle = Title::newFromID( $rootId );
            if ( $rootTitle && self::matchesExclusionList( $rootTitle, $list ) ) {
                return true;
            }
        }

        return false;
    }

    public static function getExcludedPageList(): array {
        if ( self::$excludedListCache !== null ) {
            return self::$excludedListCache;
        }

        $text = self::getMessagePageText( 'VotingSystem-excluded-pages' );
        if ( $text === '' ) {
            self::$excludedListCache = [];
            return [];
        }

        $lines = explode( "\n", $text );
        $result = [];

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            if ( $line[0] === '#' ) {
                continue;
            }
            $result[] = $line;
        }

        self::$excludedListCache = $result;
        return $result;
    }

    public static function matchesExclusionList( Title $title, array $list ): bool {
        $prefixedText = $title->getPrefixedText();
        $text = $title->getText();

        foreach ( $list as $item ) {
            if ( $item === $prefixedText || $item === $text ) {
                return true;
            }
        }

        return false;
    }

    private static function getIndependentPrefixes(): array {
        $text = self::getMessagePageText( 'VotingSystem-independent-prefixes' );
        if ( $text === '' ) {
            return [];
        }

        $lines = explode( "\n", $text );
        $result = [];
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' || $line[0] === '#' ) {
                continue;
            }
            $result[] = $line;
        }
        return $result;
    }

    private static function getMessagePageText( string $pageName ): string {
        $title = Title::makeTitleSafe( NS_MEDIAWIKI, $pageName );
        if ( !$title || !$title->exists() ) {
            return '';
        }

        $services = MediaWikiServices::getInstance();
        $wikiPage = $services->getWikiPageFactory()->newFromTitle( $title );
        if ( !$wikiPage ) {
            return '';
        }

        $content = $wikiPage->getContent();
        if ( !$content ) {
            return '';
        }

        return $content->getText();
    }

    private static function followRedirects( int $pageId ): int {
        if ( $pageId <= 0 ) {
            return $pageId;
        }

        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbr = $lb->getConnection( DB_REPLICA );

        $visited = [];
        $currentId = $pageId;

        while ( $currentId > 0 && !isset( $visited[$currentId] ) && count( $visited ) < 10 ) {
            $visited[$currentId] = true;

            $row = $dbr->selectRow(
                'redirect',
                [ 'rd_namespace', 'rd_title', 'rd_interwiki' ],
                [ 'rd_from' => $currentId ],
                __METHOD__
            );

            if ( !$row ) {
                return $currentId;
            }

            if ( $row->rd_interwiki !== null && $row->rd_interwiki !== '' ) {
                return $currentId;
            }

            $targetId = (int)$dbr->selectField(
                'page',
                'page_id',
                [
                    'page_namespace' => (int)$row->rd_namespace,
                    'page_title' => $row->rd_title,
                ],
                __METHOD__
            );

            if ( $targetId <= 0 ) {
                return $currentId;
            }

            $currentId = $targetId;
        }

        return $currentId;
    }

    /**
     * 有理由 → 用存储的 vote_weight
     * 无理由 → 用 VotingSystemNoCommentWeight
     */
    private static function resolveWeight( float $storedWeight, $commentText ): float {
        $config = MediaWikiServices::getInstance()->getMainConfig();
        if ( $commentText !== null && trim( $commentText ) !== '' ) {
            return $storedWeight;
        }
        return (float)$config->get( 'VotingSystemNoCommentWeight' );
    }

    public static function getUserWeight( $user, bool $hasComment = false ): float {
        $config = MediaWikiServices::getInstance()->getMainConfig();
        $weightMap = $config->get( 'VotingSystemBaseWeightByGroup' );
        $defaultWeight = (float)( $weightMap['default'] ?? 1.0 );

        if ( !$hasComment ) {
            return (float)$config->get( 'VotingSystemNoCommentWeight' );
        }

        if ( !$user->isRegistered() ) {
            return $defaultWeight + (float)$config->get( 'VotingSystemCommentBonus' );
        }

        $groups = MediaWikiServices::getInstance()
            ->getUserGroupManager()
            ->getUserEffectiveGroups( $user );

        $baseWeight = $defaultWeight;
        foreach ( $groups as $group ) {
            if ( isset( $weightMap[$group] ) ) {
                $w = (float)$weightMap[$group];
                if ( $w > $baseWeight ) {
                    $baseWeight = $w;
                }
            }
        }

        return $baseWeight + (float)$config->get( 'VotingSystemCommentBonus' );
    }

    public static function isCommentStreamsAvailable(): bool {
        return \ExtensionRegistry::getInstance()->isLoaded( 'CommentStreams' );
    }

    /**
     * 加权混合算法
     *
     * 条件：记录数 >= 3 且 权重和 >= 3 → 加权截尾均值
     * 否则 → 加权贝叶斯平均
     *
     * 必须有至少 3 条记录才能截尾（去掉一个最高 + 一个最低），
     * 否则会把唯一的票去掉两次，导致分数永远为 0。
     */
    private static function computeFromValues( array $weightedValues ): array {
        $n = count( $weightedValues );

        if ( $n === 0 ) {
            return [ 'score' => null, 'voteCount' => 0 ];
        }

        $W = 0.0;
        $S = 0.0;
        $contributions = [];

        foreach ( $weightedValues as $wv ) {
            $contribution = $wv['value'] * $wv['weight'];
            $contributions[] = $contribution;
            $W += $wv['weight'];
            $S += $contribution;
        }

        if ( $n >= 3 && $W >= 3 ) {
            sort( $contributions );
            array_shift( $contributions );
            array_pop( $contributions );
            $score = array_sum( $contributions ) / ( $W - 2 );
        } else {
            $C = self::PRIOR_WEIGHT;
            $m = self::PRIOR_MEAN;
            $score = ( $C * $m + $S ) / ( $C + $W );
        }

        return [ 'score' => round( $score, 4 ), 'voteCount' => $n ];
    }

    public static function getMonthlyNewPageIds( string $month ): array {
        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbr = $lb->getConnection( DB_REPLICA );

        $monthClean = str_replace( '-', '', $month );
        $startTimestamp = $monthClean . '01000000';

        $nextMonth = date( 'Ym', strtotime( $month . '-01 +1 month' ) );
        $endTimestamp = $nextMonth . '01000000';

        $res = $dbr->select(
            [ 'page', 'revision' ],
            [ 'page_id' ],
            [
                'page_namespace' => NS_MAIN,
                'page_is_redirect' => 0,
            ],
            __METHOD__,
            [
                'GROUP BY' => 'page_id',
                'HAVING' => [
                    'MIN(rev_timestamp) >= ' . $dbr->addQuotes( $startTimestamp ),
                    'MIN(rev_timestamp) < ' . $dbr->addQuotes( $endTimestamp ),
                ]
            ],
            [ 'revision' => [ 'INNER JOIN', [ 'rev_page = page_id' ] ] ]
        );

        $createdThisMonth = [];
        foreach ( $res as $row ) {
            $createdThisMonth[(int)$row->page_id] = true;
        }

        $rootIds = [];
        foreach ( array_keys( $createdThisMonth ) as $pid ) {
            $title = Title::newFromID( $pid );
            if ( !$title || $title->isRedirect() ) {
                continue;
            }
            $rootId = self::getScoringRootIdForTitle( $title );
            if ( !isset( $createdThisMonth[$rootId] ) ) {
                continue;
            }
            $rootTitle = Title::newFromID( $rootId );
            if ( !$rootTitle || $rootTitle->isRedirect() ) {
                continue;
            }
            if ( self::isExcludedPage( $rootTitle ) ) {
                continue;
            }
            $rootIds[$rootId] = true;
        }

        $res2 = $dbr->select(
            'voting_rewrite_requests',
            'request_page_id',
            [
                'request_status' => 1,
                'request_review_timestamp >= ' . $dbr->addQuotes( $startTimestamp ),
                'request_review_timestamp < ' . $dbr->addQuotes( $endTimestamp ),
            ],
            __METHOD__
        );

        foreach ( $res2 as $row ) {
            $pid = (int)$row->request_page_id;
            $title = Title::newFromID( $pid );
            if ( !$title || $title->isRedirect() ) {
                continue;
            }
            $rootId = self::getScoringRootIdForTitle( $title );
            $rootTitle = Title::newFromID( $rootId );
            if ( !$rootTitle || $rootTitle->isRedirect() ) {
                continue;
            }
            if ( self::isExcludedPage( $rootTitle ) ) {
                continue;
            }
            $rootIds[$rootId] = true;
        }

        return array_keys( $rootIds );
    }
}