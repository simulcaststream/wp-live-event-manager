jQuery(document).ready(function($) {

    // Helper function to show messages.
    // type = 'success' | 'error'
    // persistent = true means the message stays until dismissed (no auto-fade).
    function showMessage(message, type, persistent) {
        type       = type       || 'success';
        persistent = persistent || false;

        var messageClass = type === 'success' ? 'lem-message-success' : 'lem-message-error';
        var messageHtml  = '<div class="lem-message ' + messageClass + '">' + message + '</div>';

        $('.lem-message').remove();

        // Try ticket block first; fall back to the watch-page chat header; last resort: body top.
        var target = $('.lem-ticket-sales-block, .lem-event-ticket-block').first();
        if (!target.length) target = $('.lem-chat-header');
        if (!target.length) target = $('body');

        if (target.is('body')) {
            // Fixed banner across top of screen.
            $(messageHtml).css({
                position: 'fixed', top: 0, left: 0, right: 0,
                zIndex: 99999, textAlign: 'center', padding: '10px'
            }).prependTo('body');
        } else {
            target.prepend(messageHtml);
        }

        if (!persistent) {
            setTimeout(function() { $('.lem-message').fadeOut(); }, 5000);
        }
    }
    
    $('.lem-form input[required]').on('blur', function() {
        var field = $(this);
        var value = field.val().trim();
        
        if (value === '') {
            field.addClass('lem-error');
            if (!field.next('.lem-error-message').length) {
                field.after('<div class="lem-error-message">This field is required.</div>');
            }
        } else {
            field.removeClass('lem-error');
            field.next('.lem-error-message').remove();
        }
    });
    
    $('.lem-form input[type="email"]').on('blur', function() {
        var field = $(this);
        var value = field.val().trim();
        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        
        if (value !== '' && !emailRegex.test(value)) {
            field.addClass('lem-error');
            if (!field.next('.lem-error-message').length) {
                field.after('<div class="lem-error-message">Please enter a valid email address.</div>');
            }
        } else {
            field.removeClass('lem-error');
            field.next('.lem-error-message').remove();
        }
    });
    
    $('.lem-form').on('submit', function(e) {
        var form = $(this);
        var hasErrors = false;
        
        form.find('input[required]').each(function() {
            var field = $(this);
            var value = field.val().trim();
            
            if (value === '') {
                field.addClass('lem-error');
                if (!field.next('.lem-error-message').length) {
                    field.after('<div class="lem-error-message">This field is required.</div>');
                }
                hasErrors = true;
            }
        });
        
        form.find('input[type="email"]').each(function() {
            var field = $(this);
            var value = field.val().trim();
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (value !== '' && !emailRegex.test(value)) {
                field.addClass('lem-error');
                if (!field.next('.lem-error-message').length) {
                    field.after('<div class="lem-error-message">Please enter a valid email address.</div>');
                }
                hasErrors = true;
            }
        });
        
        if (hasErrors) {
            e.preventDefault();
        }
    });

    // Magic link resend (Already Purchased tab + watch-page resend forms).
    $(document).on('submit', '.lem-resend-form', function(e) {
        e.preventDefault();

        var $form    = $(this);
        var eventId  = $form.data('event-id') || $form.find('[name="lem_event_id"]').val();
        var email    = $.trim($form.find('.lem-resend-email, input[name="email"]').first().val());
        var $msg     = $form.find('.lem-resend-message').first();
        var $btn     = $form.find('button[type="submit"]');

        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            if ($msg.length) {
                $msg.removeClass('lem-message-success').addClass('lem-message-error')
                    .text('Please enter a valid email address.').show();
            } else {
                showMessage('Please enter a valid email address.', 'error', true);
            }
            return;
        }

        if (typeof lem_ajax === 'undefined') {
            $form.off('submit').trigger('submit');
            return;
        }

        $btn.prop('disabled', true);
        $form.find('.lem-button-text').hide();
        $form.find('.lem-button-loading').show();
        if ($msg.length) {
            $msg.hide().removeClass('lem-message-success lem-message-error');
        }

        $.post(lem_ajax.ajax_url, {
            action: 'lem_validate_email',
            email: email,
            event_id: eventId,
            nonce: lem_ajax.nonce
        }).done(function(r) {
            var text = (r && r.success)
                ? (r.data || 'Check your email for your magic link.')
                : (r && r.data ? (r.data.message || r.data) : 'Unable to send a magic link. Try again.');
            var type = (r && r.success) ? 'success' : 'error';

            if ($msg.length) {
                $msg.removeClass('lem-message-success lem-message-error')
                    .addClass(type === 'success' ? 'lem-message-success' : 'lem-message-error')
                    .text(text).show();
            } else {
                showMessage(text, type, true);
            }

            if (r && r.success) {
                $form.find('.lem-resend-email, input[name="email"]').val('');
                var $block = $form.closest('.lem-event-ticket-block');
                if ($block.length) {
                    var resendTab = 'resend-' + eventId;
                    $block.find('.lem-tab-btn').each(function() {
                        var $tabBtn = $(this);
                        if ($tabBtn.data('tab') === resendTab) {
                            $tabBtn.trigger('click');
                        }
                    });
                }
            }
        }).fail(function() {
            var errText = 'Network error. Please try again.';
            if ($msg.length) {
                $msg.removeClass('lem-message-success').addClass('lem-message-error').text(errText).show();
            } else {
                showMessage(errText, 'error', true);
            }
        }).always(function() {
            $btn.prop('disabled', false);
            $form.find('.lem-button-loading').hide();
            $form.find('.lem-button-text').show();
        });
    });

    // Open resend tab after a classic POST resend (?lem_resend=1).
    if (window.location.search.indexOf('lem_resend=1') !== -1) {
        $('.lem-event-ticket-block').each(function() {
            var eventId = $(this).data('event-id');
            if (!eventId) {
                return;
            }
            var $resendBtn = $(this).find('.lem-tab-btn[data-tab="resend-' + eventId + '"]');
            if ($resendBtn.length) {
                $resendBtn.trigger('click');
            }
        });
    }
    
    // ============================================
    // Dark Theme Event Page Interactions
    // ============================================
    
    // Tab switching
    $('.lem-tab').on('click', function() {
        const tab = $(this).data('tab');
        $('.lem-tab').removeClass('lem-tab-active');
        $(this).addClass('lem-tab-active');
        $('.lem-tab-content').addClass('lem-hidden');
        $('#lem-' + tab + '-tab').removeClass('lem-hidden');
    });

    // ============================================
    // Ably Realtime Chat
    // ============================================

    /**
     * Initialises the Ably chat for the watch page.
     *
     * Requires:
     *   window.lemAblyEnabled   = true
     *   window.lemWatchHasAccess = true
     *   window.lemWatchEventId   = <int>
     *   window.lemViewerName     = <string>
     *   window.lemAblyAuthUrl    = admin-ajax.php URL
     *   window.lemNonce          = WP nonce
     *
     * Uses the existing .lem-chat-* HTML structure in single-event.php.
     */
    function initAblyChat() {
        if (!window.lemAblyEnabled)      return;
        if (!window.lemWatchHasAccess)   return;
        if (typeof Ably === 'undefined') return;

        var eventId     = window.lemWatchEventId;
        var viewerName  = window.lemViewerName || 'Viewer';
        var channelName = 'lem:chat:' + eventId;

        // Ably v2: authCallback must return a Promise (no v1 completion callback).
        var ably = new Ably.Realtime({
            authCallback: function() {
                return new Promise(function(resolve, reject) {
                    $.post(window.lemAblyAuthUrl, {
                        action:   'lem_ably_token',
                        nonce:    window.lemNonce,
                        event_id: eventId
                    }, function(response) {
                        if (response && response.success && response.data) {
                            resolve(response.data);
                        } else {
                            reject(new Error((response && response.data) || 'Token request failed'));
                        }
                    }, 'json').fail(function() {
                        reject(new Error('Token request network error'));
                    });
                });
            }
        });

        var channel = ably.channels.get(channelName, {
            params: { rewind: '2m' }
        });

        ably.connection.on('connected', function() {
            channel.presence.enter({ name: viewerName });
        });

        ably.connection.on('failed', function(stateChange) {
            console.warn('[LEM Chat] Ably connection failed:', stateChange.reason);
        });

        // Receive messages
        channel.subscribe('message', function(msg) {
            var d = msg.data || {};
            appendChatMessage(d.name || 'Viewer', d.text || '', d.ts || Date.now());
        });

        // Send on button click or Enter key
        $('#lem-chat-send').on('click', sendMessage);
        $('#lem-chat-input').on('keydown', function(e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        function sendMessage() {
            var text = $('#lem-chat-input').val().trim();
            if (!text) return;

            channel.publish('message', {
                name: viewerName,
                text: text,
                ts:   Date.now()
            }).catch(function(err) {
                console.warn('[LEM Chat] Publish error:', err);
            });

            $('#lem-chat-input').val('').focus();
        }

        function appendChatMessage(name, text, ts) {
            $('#lem-chat-empty').hide();

            var time = '';
            try {
                time = new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            } catch (e) {}

            // Build DOM using jQuery text() so XSS is impossible
            var $msg     = $('<div class="lem-chat-message">');
            var $content = $('<div class="lem-chat-content">');
            var $meta    = $('<div style="display:flex;align-items:baseline;gap:0.4rem;margin-bottom:0.2rem;">');
            var $name    = $('<span class="lem-chat-name">').text(name);
            var $time    = $('<span class="lem-chat-time">').text(time);
            var $text    = $('<div class="lem-chat-text">').text(text);

            $meta.append($name, $time);
            $content.append($meta, $text);
            $msg.append($content);

            var $msgs = $('#lem-chat-messages');
            var isAtBottom = $msgs[0].scrollHeight - $msgs.scrollTop() - $msgs.outerHeight() < 60;
            $msgs.append($msg);
            if (isAtBottom) {
                $msgs.scrollTop($msgs[0].scrollHeight);
            }
        }
    }

    // PayPal return (or other flows): reconcile payment via API when webhooks are delayed.
    if (window.lemPaymentReconcile && window.lemPaymentReconcile.session_id) {
        var recon = window.lemPaymentReconcile;
        var reconAttempts = 0;
        var reconMax = 12;
        var reconAjax = (typeof lem_ajax !== 'undefined') ? lem_ajax : null;

        function pollPaymentReconcile() {
            if (!reconAjax || reconAttempts >= reconMax) {
                return;
            }
            reconAttempts++;
            $.post(reconAjax.ajax_url, {
                action: 'lem_reconcile_payment',
                nonce: reconAjax.nonce,
                session_id: recon.session_id,
                provider_id: recon.provider_id || '',
                event_id: recon.event_id || ''
            }).done(function(r) {
                if (r && r.success && r.data && r.data.granted) {
                    if (r.data.watch_url) {
                        window.location.href = r.data.watch_url;
                    } else {
                        window.location.reload();
                    }
                    return;
                }
                setTimeout(pollPaymentReconcile, 2000);
            }).fail(function() {
                setTimeout(pollPaymentReconcile, 3000);
            });
        }

        setTimeout(pollPaymentReconcile, 1000);
    }

    // Boot Ably chat after everything is ready
    if (window.lemAblyEnabled) {
        if (typeof Ably !== 'undefined') {
            initAblyChat();
        } else {
            // Ably SDK may still be loading — wait for it
            var ablyPollInterval = setInterval(function() {
                if (typeof Ably !== 'undefined') {
                    clearInterval(ablyPollInterval);
                    initAblyChat();
                }
            }, 100);
            // Give up after 10 s
            setTimeout(function() { clearInterval(ablyPollInterval); }, 10000);
        }
    }

    // Retry countdown
    if ($('#lem-retry-countdown').length) {
        let countdown = 59;
        const countdownInterval = setInterval(function() {
            countdown--;
            $('#lem-retry-countdown').text(countdown);
            if (countdown <= 0) {
                countdown = 59;
                // Trigger retry logic here - could check stream status
                // Trigger retry logic — could check stream status
            }
        }, 1000);
        
        // Clean up on page unload
        $(window).on('beforeunload', function() {
            clearInterval(countdownInterval);
        });
    }
});