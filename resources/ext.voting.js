( function () {
    'use strict';

    var COMMENT_BONUS_HINT_KEY = 'votingsystem-vote-comment-prompt';
    var NO_COMMENT_HINT_KEY = 'votingsystem-vote-no-comment-hint';

    function repositionPanel() {
        var anchor = document.getElementById( 'voting-panel-anchor' );
        if ( !anchor ) {
            return;
        }

        var bodyContent = document.querySelector( '.mw-body-content' );
        if ( !bodyContent ) {
            return;
        }

        if ( anchor.parentNode === bodyContent
            && bodyContent.lastElementChild === anchor ) {
            return;
        }

        bodyContent.appendChild( anchor );
    }

    function dedupePanels() {
        var panels = document.querySelectorAll( '.voting-panel' );
        if ( panels.length <= 1 ) {
            return;
        }
        for ( var i = 1; i < panels.length; i++ ) {
            panels[ i ].remove();
        }
    }

    function initDetailsToggle() {
        var toggles = document.querySelectorAll( '.voting-panel-toggle' );
        toggles.forEach( function ( btn ) {
            if ( btn.getAttribute( 'data-bound' ) === '1' ) {
                return;
            }
            btn.setAttribute( 'data-bound', '1' );

            btn.addEventListener( 'click', function () {
                var panel = btn.closest( '.voting-panel' );
                if ( !panel ) {
                    return;
                }
                var container = panel.querySelector( '.voting-panel-details' );
                if ( !container ) {
                    return;
                }

                if ( container.style.display === 'none' || container.style.display === '' ) {
                    container.style.display = 'block';
                    btn.classList.add( 'voting-panel-toggle-open' );
                    btn.textContent = mw.msg( 'votingsystem-panel-details-hide' );

                    if ( container.getAttribute( 'data-loaded' ) !== '1' ) {
                        loadDetails( btn.getAttribute( 'data-page-id' ), container );
                    }
                } else {
                    container.style.display = 'none';
                    btn.classList.remove( 'voting-panel-toggle-open' );
                    btn.textContent = mw.msg( 'votingsystem-panel-details-toggle' );
                }
            } );
        } );
    }

    /**
     * 加载当前用户的状态：是否显示详情按钮、是否高亮已投票按钮
     */
    function loadUserState( pageId, panel ) {
        var api = new mw.Api();
        api.get( {
            action: 'votingdetails',
            format: 'json',
            pageid: pageId
        } ).done( function ( data ) {
            var details = data.votingdetails || data;
            if ( !details ) {
                return;
            }

            // 显示详情按钮（有权限时）
            if ( details.canView === 1 || details.canView === true ) {
                var slot = panel.querySelector( '.voting-panel-details-button-slot' );
                if ( slot && !slot.querySelector( '.voting-panel-toggle' ) ) {
                    var btn = document.createElement( 'button' );
                    btn.type = 'button';
                    btn.className = 'voting-panel-toggle';
                    btn.setAttribute( 'data-page-id', String( pageId ) );
                    btn.textContent = mw.msg( 'votingsystem-panel-details-toggle' );
                    slot.appendChild( btn );
                    initDetailsToggle();
                }
            }

            // 高亮当前用户的投票
            var myVote = details.myVote;
            if ( myVote !== null && myVote !== undefined ) {
                var buttons = panel.querySelectorAll( '.voting-btn' );
                buttons.forEach( function ( b ) {
                    var v = parseFloat( b.getAttribute( 'data-value' ) );
                    if ( Math.abs( v - myVote ) < 0.01 ) {
                        b.classList.add( 'voting-active' );
                    }
                } );
            }
        } ).fail( function () {
            // 静默失败，不影响投票
        } );
    }

    function loadDetails( pageId, container ) {
        container.innerHTML = '<div class="voting-panel-details-loading">'
            + mw.msg( 'votingsystem-panel-details-loading' ) + '</div>';

        var api = new mw.Api();
        api.get( {
            action: 'votingdetails',
            format: 'json',
            pageid: pageId
        } ).done( function ( data ) {
            var details = data.votingdetails || data;
            if ( !details || !Array.isArray( details.votes ) ) {
                renderError( container );
                return;
            }
            var canDelete = details.canDelete === 1 || details.canDelete === true;
            renderDetails( details.votes, container, canDelete );
            container.setAttribute( 'data-loaded', '1' );

            if ( canDelete ) {
                bindDeleteButtons( container );
            }
        } ).fail( function () {
            renderError( container );
        } );
    }

    function bindDeleteButtons( container ) {
        var buttons = container.querySelectorAll( '.voting-details-delete' );
        buttons.forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                var voteId = btn.getAttribute( 'data-vote-id' );
                if ( !voteId ) {
                    return;
                }
                if ( !window.confirm( mw.msg( 'votingsystem-panel-details-delete-confirm' ) ) ) {
                    return;
                }
                deleteVote( voteId, btn );
            } );
        } );
    }

    function deleteVote( voteId, btn ) {
        btn.disabled = true;
        var api = new mw.Api();
        api.postWithToken( 'csrf', {
            action: 'votingdelete',
            format: 'json',
            voteid: voteId
        } ).done( function () {
            window.location.reload();
        } ).fail( function ( errorCode, errorData ) {
            btn.disabled = false;
            var msg = errorData && errorData.error
                ? errorData.error.info
                : mw.msg( 'votingsystem-panel-details-delete-error' );
            window.alert( msg );
        } );
    }

    function renderError( container ) {
        container.innerHTML = '<div class="voting-panel-details-empty">'
            + mw.msg( 'votingsystem-panel-details-error' ) + '</div>';
    }

    function renderDetails( votes, container, canDelete ) {
        if ( votes.length === 0 ) {
            container.innerHTML = '<div class="voting-panel-details-empty">'
                + mw.msg( 'votingsystem-panel-details-empty' ) + '</div>';
            return;
        }

        var html = '<table class="voting-panel-details-table"><thead><tr>';
        html += '<th>' + mw.msg( 'votingsystem-panel-details-user' ) + '</th>';
        html += '<th>' + mw.msg( 'votingsystem-panel-details-value' ) + '</th>';
        html += '<th>' + mw.msg( 'votingsystem-panel-details-weight' ) + '</th>';
        html += '<th>' + mw.msg( 'votingsystem-panel-details-time' ) + '</th>';
        if ( canDelete ) {
            html += '<th></th>';
        }
        html += '</tr></thead><tbody>';

        votes.forEach( function ( v ) {
            html += '<tr>';
            html += '<td>' + v.userHtml + '</td>';
            html += '<td class="voting-details-value">' + escapeHtml( v.value ) + '</td>';
            html += '<td class="voting-details-weight">' + escapeHtml( v.weight ) + '</td>';
            html += '<td class="voting-details-time">' + escapeHtml( v.timestamp ) + '</td>';
            if ( canDelete ) {
                html += '<td><button type="button" class="voting-details-delete"'
                    + ' data-vote-id="' + escapeHtml( String( v.voteId ) ) + '">'
                    + mw.msg( 'votingsystem-panel-details-delete' )
                    + '</button></td>';
            }
            html += '</tr>';
        } );

        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function escapeHtml( s ) {
        var div = document.createElement( 'div' );
        div.textContent = String( s );
        return div.innerHTML;
    }

    function initVotePanels() {
        repositionPanel();
        dedupePanels();
        initDetailsToggle();

        var panels = document.querySelectorAll( '.voting-panel' );
        panels.forEach( function ( panel ) {
            var pageId = panel.getAttribute( 'data-page-id' );
            var buttons = panel.querySelectorAll( '.voting-btn' );
            var messageEl = panel.querySelector( '.voting-panel-message' );

            if ( !pageId || buttons.length === 0 ) {
                return;
            }

            buttons.forEach( function ( btn ) {
                btn.addEventListener( 'click', function () {
                    var value = btn.getAttribute( 'data-value' );

                    buttons.forEach( function ( b ) {
                        b.classList.remove( 'voting-active' );
                    } );
                    btn.classList.add( 'voting-active' );

                    showCommentDialog( panel, pageId, value, buttons, btn, messageEl );
                } );
            } );

            // 加载当前用户状态
            loadUserState( pageId, panel );
        } );
    }

    function showCommentDialog( panel, pageId, value, buttons, activeBtn, messageEl ) {
        var existing = panel.querySelector( '.voting-comment-overlay' );
        if ( existing ) {
            existing.remove();
        }

        var overlay = document.createElement( 'div' );
        overlay.className = 'voting-comment-overlay';

        var dialog = document.createElement( 'div' );
        dialog.className = 'voting-comment-dialog';

        var prompt = document.createElement( 'div' );
        prompt.className = 'voting-comment-prompt';
        prompt.textContent = mw.msg( COMMENT_BONUS_HINT_KEY );

        var noCommentHint = document.createElement( 'div' );
        noCommentHint.className = 'voting-comment-no-comment-hint';
        noCommentHint.textContent = mw.msg( NO_COMMENT_HINT_KEY );

        var textarea = document.createElement( 'textarea' );
        textarea.className = 'voting-comment-textarea';
        textarea.placeholder = mw.msg( 'votingsystem-vote-comment-placeholder' );
        textarea.rows = 4;

        var hideRow = document.createElement( 'label' );
        hideRow.className = 'voting-comment-hide-row';

        var hideCheckbox = document.createElement( 'input' );
        hideCheckbox.type = 'checkbox';
        hideCheckbox.className = 'voting-comment-hide-checkbox';

        var hideLabel = document.createElement( 'span' );
        hideLabel.className = 'voting-comment-hide-label';
        hideLabel.textContent = mw.msg( 'votingsystem-vote-hide-score' );

        hideRow.appendChild( hideCheckbox );
        hideRow.appendChild( hideLabel );

        var btnRow = document.createElement( 'div' );
        btnRow.className = 'voting-comment-buttons';

        var submitWithComment = document.createElement( 'button' );
        submitWithComment.className = 'voting-comment-submit';
        submitWithComment.textContent = mw.msg( 'votingsystem-vote-submit-with-comment' );

        var submitWithoutComment = document.createElement( 'button' );
        submitWithoutComment.className = 'voting-comment-skip';
        submitWithoutComment.textContent = mw.msg( 'votingsystem-vote-submit' );

        var cancelBtn = document.createElement( 'button' );
        cancelBtn.className = 'voting-comment-cancel';
        cancelBtn.textContent = mw.msg( 'votingsystem-vote-cancel' );

        btnRow.appendChild( submitWithComment );
        btnRow.appendChild( submitWithoutComment );
        btnRow.appendChild( cancelBtn );

        dialog.appendChild( prompt );
        dialog.appendChild( noCommentHint );
        dialog.appendChild( textarea );
        dialog.appendChild( hideRow );
        dialog.appendChild( btnRow );
        overlay.appendChild( dialog );
        panel.appendChild( overlay );

        textarea.focus();

        cancelBtn.addEventListener( 'click', function () {
            overlay.remove();
            buttons.forEach( function ( b ) {
                b.classList.remove( 'voting-active' );
            } );
        } );

        submitWithComment.addEventListener( 'click', function () {
            var text = textarea.value.trim();
            if ( !text ) {
                textarea.focus();
                textarea.classList.add( 'voting-comment-error' );
                return;
            }
            doVote( pageId, value, text, hideCheckbox.checked, messageEl, overlay, activeBtn );
        } );

        submitWithoutComment.addEventListener( 'click', function () {
            doVote( pageId, value, '', hideCheckbox.checked, messageEl, overlay, activeBtn );
        } );

        textarea.addEventListener( 'keydown', function ( e ) {
            if ( e.ctrlKey && e.key === 'Enter' ) {
                submitWithComment.click();
            }
        } );
    }

    function doVote( pageId, value, commentText, hideScore, messageEl, overlay, activeBtn ) {
        var api = new mw.Api();
        var params = {
            action: 'voting',
            format: 'json',
            pageid: pageId,
            value: value
        };

        if ( commentText ) {
            params.commenttext = commentText;
        }
        if ( hideScore ) {
            params.hidescore = '1';
        }

        api.postWithToken( 'csrf', params ).done( function ( data ) {
            if ( overlay ) {
                overlay.remove();
            }
            if ( messageEl ) {
                var msg = mw.msg( 'votingsystem-vote-success' );
                if ( data.voting && data.voting.voteweight !== undefined ) {
                    msg += ' ' + mw.msg( 'votingsystem-vote-weight-info', data.voting.voteweight );
                }
                messageEl.textContent = msg;
                messageEl.className = 'voting-panel-message voting-success';
            }
            window.location.reload();
        } ).fail( function ( errorCode, errorData ) {
            if ( overlay ) {
                overlay.remove();
            }
            if ( messageEl ) {
                var errMsg = errorData && errorData.error
                    ? errorData.error.info
                    : mw.msg( 'votingsystem-vote-error' );
                messageEl.textContent = errMsg;
                messageEl.className = 'voting-panel-message voting-error';
            }
            if ( activeBtn ) {
                activeBtn.classList.remove( 'voting-active' );
            }
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', initVotePanels );
    } else {
        initVotePanels();
    }
}() );