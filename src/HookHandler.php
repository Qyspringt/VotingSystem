<?php
namespace MediaWiki\Extension\VotingSystem;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Hook\SkinAfterContentHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Parser;
use RequestContext;

class HookHandler implements
    ParserFirstCallInitHook,
    BeforePageDisplayHook,
    SkinAfterContentHook
{

    public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ): void {
        $base = dirname( __DIR__ ) . '/sql';
        $updater->addExtensionTable( 'voting_votes', "$base/voting_votes.sql" );
        $updater->addExtensionTable( 'voting_rewrite_requests', "$base/voting_rewrite_requests.sql" );
    }

    public function onParserFirstCallInit( $parser ): void {
        $parser->setHook( 'votepanel', [ self::class, 'renderVotePanel' ] );
    }

    public function onBeforePageDisplay( $out, $skin ): void {
        $title = $out->getTitle();
        if ( !self::shouldShowPanel( $title, $out ) ) {
            return;
        }
        $out->addModuleStyles( [ 'ext.voting' ] );
        $out->addModules( [ 'ext.voting' ] );
    }

    public function onSkinAfterContent( &$data, $skin ): void {
        $out = $skin->getOutput();
        $title = $out->getTitle();

        if ( !self::shouldShowPanel( $title, $out ) ) {
            return;
        }

        $data .= '<div id="voting-panel-anchor">'
            . self::buildVotePanelHtml( $title->getArticleID() )
            . '</div>';
    }

    private static function shouldShowPanel( $title, $out ): bool {
        if ( !$title || !$title->exists() ) {
            return false;
        }
        if ( $title->getNamespace() !== NS_MAIN ) {
            return false;
        }
        if ( $title->isRedirect() ) {
            return false;
        }

        $action = $out->getRequest()->getVal( 'action', 'view' );
        if ( $action !== 'view' ) {
            return false;
        }
        if ( VotingCalculator::isExcludedPage( $title ) ) {
            return false;
        }
        return true;
    }

    public static function renderVotePanel( $input, $args, $parser ): string {
        $parser->getOutput()->addModuleStyles( [ 'ext.voting' ] );
        $parser->getOutput()->addModules( [ 'ext.voting' ] );

        $pageId = $parser->getTitle()->getArticleID();
        if ( !$pageId ) {
            return '<div class="voting-panel-error">'
                . htmlspecialchars( wfMessage( 'votingsystem-panel-not-saved' )->text() )
                . '</div>';
        }

        return self::buildVotePanelHtml( $pageId );
    }

    /**
     * 生成投票面板 HTML。
     *
     * 注意：此 HTML 会被 parser cache 缓存。
     * 因此不能输出任何与当前用户相关的状态（如"已投票高亮"、
     * "查看详情按钮"），这些都交由前端 JS 通过 API 拉取。
     */
    public static function buildVotePanelHtml( int $pageId ): string {
        $title = Title::newFromID( $pageId );
        if ( !$title ) {
            return '';
        }

        $scoringRootId = VotingCalculator::getScoringRootIdForTitle( $title );

        $values = [
            [ '-1', '-1' ],
            [ '-0.5', '-0.5' ],
            [ '0', '0' ],
            [ '+0.5', '0.5' ],
            [ '+1', '1' ]
        ];

        $html = '<div class="voting-panel" data-page-id="' . htmlspecialchars( (string)$pageId ) . '"';
        if ( $scoringRootId !== $pageId ) {
            $html .= ' data-scoring-root-id="' . htmlspecialchars( (string)$scoringRootId ) . '"';
        }
        $html .= '>';

        $html .= '<div class="voting-panel-header">';
        $html .= '<div class="voting-panel-title">'
            . htmlspecialchars( wfMessage( 'votingsystem-panel-title' )->text() )
            . '</div>';

        // 详情按钮容器：内容由 JS 决定是否显示
        $html .= '<span class="voting-panel-details-button-slot"></span>';
        $html .= '</div>';

        $html .= '<div class="voting-panel-buttons">';
        foreach ( $values as $v ) {
            $html .= '<button class="voting-btn" data-value="' . $v[1] . '">'
                . htmlspecialchars( $v[0] ) . '</button>';
        }
        $html .= '</div>';
        $html .= '<div class="voting-panel-message"></div>';

        // 详情容器：内容由 JS 填充
        $html .= '<div class="voting-panel-details" style="display:none"></div>';

        if ( $scoringRootId !== $pageId ) {
            $rootTitle = Title::newFromID( $scoringRootId );
            if ( $rootTitle ) {
                $linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
                $rootLink = $linkRenderer->makeLink( $rootTitle );
                $html .= '<div class="voting-panel-note">'
                    . wfMessage( 'votingsystem-panel-scoring-root-note' )
                        ->rawParams( $rootLink )->parse()
                    . '</div>';
            }
        }

        // 登录提示：对未登录用户显示
        // MediaWiki 的 parser cache 默认不跨登录状态共享，所以安全
        $user = RequestContext::getMain()->getUser();
        if ( !$user->isRegistered() ) {
            $loginUrl = SpecialPage::getTitleFor( 'Userlogin' )->getLocalURL();
            $html .= '<div class="voting-panel-login">'
                . htmlspecialchars( wfMessage( 'votingsystem-panel-login' )->text() )
                . ' <a href="' . htmlspecialchars( $loginUrl ) . '">'
                . htmlspecialchars( wfMessage( 'login' )->text() )
                . '</a></div>';
        }

        $html .= '</div>';

        return $html;
    }
}