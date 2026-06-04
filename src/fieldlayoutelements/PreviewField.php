<?php

namespace yellowrobot\courier\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseUiElement;
use craft\helpers\Html;
use craft\helpers\Json;
use yellowrobot\courier\Courier;
use yellowrobot\courier\elements\Trigger;
use yellowrobot\courier\web\assets\cp\CourierCpAsset;

/**
 * Live email preview + "Send test", rendered on the Trigger edit screen.
 *
 * The element type to preview against is derived from the trigger's event
 * (via the EventRegistry). When no element is picked, the server auto-picks a
 * representative one that matches the trigger's condition (falling back to the
 * most recent of the type, then an empty mock) so tokens resolve to real values.
 *
 * Pure display — it carries no posted form value, so it extends BaseUiElement.
 */
class PreviewField extends BaseUiElement
{
    protected function selectorLabel(): string
    {
        return Craft::t('courier', 'Email Preview');
    }

    protected function selectorIcon(): ?string
    {
        return 'envelope';
    }

    public function formHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        if (!$element instanceof Trigger) {
            return null;
        }

        Craft::$app->getView()->registerAssetBundle(CourierCpAsset::class);

        // Element type the trigger is bound to — event registry or date config
        // (may be null for non-element events — preview then runs against an
        // empty object).
        $elementType = $element->getBoundElementType();

        $triggerId = $element->id;

        // Scope the sample picker to elements matching the trigger's condition
        // (e.g. the right section) — restoring the old preview() criteria. Honors
        // the whole ElementCondition, not just section. Null when there are no rules.
        // All non-empty OR groups feed the auto-pick (union, OR-aware); the first
        // group also filters the manual element-picker modal (which takes one).
        $conditionConfig = null;
        $conditionConfigs = [];
        if ($elementType) {
            foreach ($element->getConditionGroups() as $group) {
                if (!$group->isEmpty()) {
                    $conditionConfigs[] = $group->getConfig();
                }
            }
            $conditionConfig = $conditionConfigs[0] ?? null;
        }

        // Hidden inputs the JS reads/writes.
        $hidden = Html::hiddenInput('previewElementType', (string) $elementType, ['id' => 'preview-element-type']);
        $hidden .= Html::hiddenInput('previewElementId', '', ['id' => 'preview-element-id']);
        $hidden .= Html::hiddenInput('previewTriggerId', (string) $triggerId, ['id' => 'preview-trigger-id']);

        // ─── Sample element picker (shared by preview + test) ──
        if ($elementType) {
            $typeName = Html::encode($elementType::displayName());
            $dataControl =
                Html::tag('p', Craft::t('courier', 'Choose an existing {type} and the preview and test will use its real field values, so tokens such as {token} show actual content. Leave it blank and a matching {typeStrong} is picked for you.', [
                    'type' => $typeName,
                    'typeStrong' => Html::tag('strong', $typeName),
                    'token' => Html::tag('code', Html::encode('{{ object.title }}')),
                ]), [
                    'class' => 'instructions courier-preview-instructions',
                ])
                . Html::tag('div', '', [
                    'id' => 'preview-element-select',
                    'class' => 'courier-preview-element-select',
                ]);
        } else {
            $dataControl = Html::tag('p', Craft::t('courier', 'This event isn’t tied to an element, so the preview and test send render with empty sample data.'), [
                'class' => 'instructions courier-preview-instructions--flush',
            ]);
        }

        $renderBtn = Html::tag('button', '', [
            'type' => 'button',
            'class' => 'btn',
            'data-icon' => 'refresh',
            'id' => 'preview-render-btn',
            'title' => Craft::t('courier', 'Render'),
            'aria-label' => Craft::t('courier', 'Render'),
        ]);

        // The trigger's channels, with type info so the test can target one (or
        // all) and warn before firing any live (non-email) destination.
        $channels = [];
        $hasLiveChannels = false;
        $anyHtml = false;
        $textOnlyNames = []; // distinct channel-type names that ignore the HTML body
        foreach ($element->getChannelUids() as $uid) {
            $cfg = Courier::$plugin->channels->getConfigByUid($uid);
            if (!$cfg) {
                continue;
            }
            $isLive = $cfg->type !== 'email';
            $hasLiveChannels = $hasLiveChannels || $isLive;
            $channels[] = ['uid' => (string) $cfg->uid, 'name' => (string) $cfg->name, 'live' => $isLive];

            // Track HTML capability to drive the preview's default view + note.
            $type = $cfg->getChannelType();
            if ($type) {
                if ($type->supportsHtml()) {
                    $anyHtml = true;
                } else {
                    $textOnlyNames[$type::handle()] = $type->getName();
                }
            }
        }
        // Default the preview to HTML unless every selected channel is text-only.
        $htmlActive = ($channels === []) || $anyHtml;

        // Send test row (separated by a rule)
        $sendTestBtn = Html::tag('button', Craft::t('courier', 'Send'), [
            'type' => 'button',
            'class' => 'btn',
            'id' => 'preview-sendtest-btn',
            'disabled' => $triggerId === null,
            'title' => $triggerId === null ? Craft::t('courier', 'Save the trigger first.') : '',
            'data-has-live-channels' => $hasLiveChannels ? '1' : '0',
        ]);

        // "Test which channel?" picker — only when there's a choice to make.
        // A test always targets exactly one channel (no "all" blast); defaults
        // to the first so a stray click can't fire more than one destination.
        $channelPicker = '';
        if (count($channels) > 1) {
            $options = '';
            foreach ($channels as $c) {
                $options .= Html::tag('option', Html::encode($c['name']), ['value' => $c['uid'], 'data-live' => $c['live'] ? '1' : '0']);
            }
            $channelPicker = Html::tag('div', Html::tag('select', $options, ['id' => 'preview-sendtest-channel']), ['class' => 'select']);
        }

        $sendTestStatus = Html::tag('span', '', ['id' => 'preview-sendtest-status', 'class' => 'courier-preview-status']);
        if ($triggerId === null) {
            $hintText = Craft::t('courier', 'Save the trigger to enable test sends.');
        } elseif ($hasLiveChannels) {
            $hintText = Craft::t('courier', 'Email goes to you; Slack, webhook, and SMS post to their live destinations.');
        } else {
            $hintText = Craft::t('courier', 'Sends to your address.');
        }
        $sendTestControls = Html::tag('div', $sendTestBtn . $channelPicker . $sendTestStatus, [
            'class' => 'courier-preview-controls',
        ]);
        $sendTestHint = Html::tag('div', $hintText, ['class' => 'light courier-preview-hint']);

        // ─── Result (rendered inside the Preview pane) ─────────
        $modeToggles = Html::tag('div', implode('', [
            Html::tag('button', Craft::t('courier', 'HTML'), ['type' => 'button', 'class' => 'btn small' . ($htmlActive ? ' active' : ''), 'data-preview-mode' => 'html']),
            Html::tag('button', Craft::t('courier', 'Text'), ['type' => 'button', 'class' => 'btn small' . ($htmlActive ? '' : ' active'), 'data-preview-mode' => 'text']),
        ]), [
            'class' => 'btngroup courier-preview-mode-toggles',
            'id' => 'preview-mode-toggles',
        ]);

        $resultTools = Html::tag('div', $modeToggles, [
            'class' => 'courier-preview-tools',
        ]);

        // Note when selected channels won't use the HTML body, so the author
        // isn't surprised the rich layout doesn't apply there.
        $htmlNote = '';
        if ($textOnlyNames !== []) {
            $names = Html::encode(implode(', ', array_values($textOnlyNames)));
            $htmlNote = Html::tag('p',
                $anyHtml
                    ? Craft::t('courier', 'Some of the configured channels only send plain text ({names}) and will skip the HTML body. The rest still use it.', ['names' => $names])
                    : Craft::t('courier', 'The channels configured for this trigger only send plain text ({names}), so the HTML body won’t be used.', ['names' => $names]),
                ['class' => 'light courier-preview-hint courier-preview-html-note'],
            );
        }

        // Render errors (usually a Twig mistake) as a monospace code block so the
        // message — method/line — is legible, not run together as prose.
        $errorEl = Html::tag('div', '', [
            'id' => 'preview-error',
            'class' => 'courier-preview-error',
        ]);

        // One email-style header card: To / Cc / Bcc (resolved against the sample)
        // plus Subject, all in the same row format so it reads like a message header.
        $headerRow = fn(string $label, string $id) => Html::tag('div',
            Html::tag('span', $label . ':', ['class' => 'courier-preview-header-label'])
            . Html::tag('span', '', ['id' => $id, 'class' => 'courier-preview-header-value']),
            ['id' => $id . '-row', 'class' => 'courier-preview-header-row'],
        );
        $headersEl = Html::tag('div',
            $headerRow(Craft::t('courier', 'To'), 'preview-to')
            . $headerRow(Craft::t('courier', 'Cc'), 'preview-cc')
            . $headerRow(Craft::t('courier', 'Bcc'), 'preview-bcc')
            . $headerRow(Craft::t('courier', 'Subject'), 'preview-subject-text'),
            ['id' => 'preview-headers', 'class' => 'courier-preview-headers'],
        );

        $iframe = Html::tag('iframe', '', [
            'id' => 'preview-iframe',
            'class' => 'courier-preview-iframe',
        ]);
        $resizeHandle = Html::tag('div', '', [
            'id' => 'preview-resize-handle',
            'class' => 'courier-preview-resize-handle',
        ]);
        $iframeWrap = Html::tag('div', $iframe . $resizeHandle, [
            'id' => 'preview-iframe-wrap',
            'class' => 'courier-preview-iframe-wrap',
        ]);
        $widthLabel = Html::tag('div', '', [
            'id' => 'preview-width-label',
            'class' => 'courier-preview-width-label',
        ]);
        $frameContainer = Html::tag('div', $widthLabel . $iframeWrap, [
            'id' => 'preview-frame-container',
            'class' => 'courier-preview-frame-container',
        ]);

        $textContainer = Html::tag('pre', '', [
            'id' => 'preview-text-container',
            'class' => 'courier-preview-text-container',
        ]);

        // One message card: header band on top, body below — reads like an email.
        $messageEl = Html::tag('div', $headersEl . $frameContainer . $textContainer, [
            'id' => 'preview-message',
            'class' => 'courier-preview-message',
        ]);

        // Skeleton placeholder shaped like the rendered message — a header band
        // (To / Subject) over a white body — so the loading state reads as "this
        // email loading", not a generic box. Picking a sample or hitting Render
        // fills it in. Shown initially and on the first render.
        $skLine = fn(string $w) => Html::tag('div', '', ['class' => 'courier-sk-line', 'style' => "width:{$w};"]);
        $skHeaderRow = fn(string $label, string $w) => Html::tag('div',
            Html::tag('span', $label . ':', ['class' => 'courier-preview-header-label']) . $skLine($w),
            ['class' => 'courier-preview-header-row--sk'],
        );
        $skHeaders = Html::tag('div',
            $skHeaderRow(Craft::t('courier', 'To'), '55%')
            . $skHeaderRow(Craft::t('courier', 'Subject'), '72%'),
            ['class' => 'courier-preview-headers'],
        );
        $skBody = Html::tag('div',
            $skLine('92%') . $skLine('86%') . $skLine('90%') . $skLine('64%') . Html::tag('div', '', ['class' => 'courier-sk-btn']),
            ['class' => 'courier-preview-skeleton-body'],
        );
        // Wrap the band + body in a card so it matches .courier-preview-message.
        $skCard = Html::tag('div', $skHeaders . $skBody, ['class' => 'courier-preview-sk-card']);
        $skHint = Html::tag('p',
            $elementType
                ? Craft::t('courier', 'Pick a sample {type} above — or just Render and one is picked for you.', [
                    'type' => Html::encode($elementType::displayName()),
                ])
                : Craft::t('courier', 'Click Render to preview this email.'),
            ['class' => 'light courier-preview-skeleton-hint'],
        );
        $emptyEl = Html::tag('div', $skCard . $skHint, [
            'id' => 'preview-empty',
            'class' => 'courier-preview-empty',
        ]);

        // ─── Sample data: shared render context for preview + test ──
        $sampleDataPane = Html::tag('div',
            Html::tag('h2', Craft::t('courier', 'Sample data'), ['class' => 'courier-preview-header-h2']) . $dataControl,
            ['class' => 'pane']
        );

        // ─── Preview pane: Render the sample → see the result ──
        $previewHeader = Html::tag('div',
            Html::tag('h2', Craft::t('courier', 'Preview'), ['class' => 'courier-preview-header-h2--flush']) . $renderBtn,
            ['class' => 'courier-preview-pane-header']
        );
        $previewPane = Html::tag('div',
            $previewHeader . $resultTools . $htmlNote . $errorEl . $messageEl . $emptyEl,
            ['class' => ['pane', 'courier-preview-pane']]
        );

        // ─── Send test pane: a distinct action with real delivery ──
        $sendTestPane = Html::tag('div',
            Html::tag('h2', Craft::t('courier', 'Send test'), ['class' => 'courier-preview-header-h2'])
            . $sendTestControls . $sendTestHint,
            ['class' => ['pane', 'courier-preview-pane']]
        );

        $html = $hidden . $sampleDataPane . $previewPane . $sendTestPane;

        $jsElementType = Json::encode($elementType);
        $jsTypeName = Json::encode($elementType ? $elementType::displayName() : 'element');
        $jsCondition = Json::encode($conditionConfig);
        $jsConditions = Json::encode($conditionConfigs);

        $jsSelectLabel = Json::encode(Craft::t('courier', 'Select a {type}', ['type' => '__TYPE__']));
        $jsChangeLabel = Json::encode(Craft::t('courier', 'Change'));
        $jsSending = Json::encode(Craft::t('courier', 'Sending…'));
        $jsSend = Json::encode(Craft::t('courier', 'Send'));
        $jsSent = Json::encode(Craft::t('courier', 'Sent.'));
        $jsFailed = Json::encode(Craft::t('courier', 'Failed.'));
        $jsNothingResolved = Json::encode(Craft::t('courier', 'nothing resolved for this sample'));
        $jsConfirmLive = Json::encode(Craft::t('courier', 'This will post a real message to live channels (Slack, webhook, or SMS). Email still goes only to you. Continue?'));
        $jsRequestFailed = Json::encode(Craft::t('courier', 'Request failed: '));

        $js = <<<JS
(function() {
    var PREVIEW_ELEMENT_TYPE = {$jsElementType};
    var PREVIEW_TYPE_NAME = {$jsTypeName};
    var PREVIEW_CONDITION = {$jsCondition};
    var PREVIEW_CONDITIONS = {$jsConditions};

    var renderBtn = document.getElementById('preview-render-btn');
    var modeToggles = document.getElementById('preview-mode-toggles');
    var sendTestBtn = document.getElementById('preview-sendtest-btn');
    var selectedElementId = null;

    // ── Element picker (optional) ──
    var \$selectContainer = PREVIEW_ELEMENT_TYPE ? $('#preview-element-select') : null;

    function selectLabel() {
        return {$jsSelectLabel}.replace('__TYPE__', PREVIEW_TYPE_NAME);
    }

    function showSelectButton() {
        if (!\$selectContainer) return;
        \$selectContainer.html('<button type="button" class="btn dashed add icon" id="select-element-btn">' + Craft.escapeHtml(selectLabel()) + '</button>');
        document.getElementById('select-element-btn').addEventListener('click', openSelector);
    }

    function openSelector() {
        var \$trigger = \$selectContainer.find('button').first();
        var modalSettings = {
            multiSelect: false,
            \$triggerElement: \$trigger,
            onSelect: function(elements) {
                if (elements.length) {
                    selectedElementId = elements[0].id;
                    document.getElementById('preview-element-id').value = selectedElementId;
                    \$selectContainer.html(
                        '<span class="status green courier-status-inline"></span>' +
                        '<span class="courier-preview-selected-name">' + Craft.escapeHtml(elements[0].label) + '</span>' +
                        '&nbsp;<button type="button" class="btn small" id="change-element-btn">' + Craft.escapeHtml({$jsChangeLabel}) + '</button>'
                    );
                    document.getElementById('change-element-btn').addEventListener('click', openSelector);
                    // Fill the skeleton straight away on select.
                    if (renderBtn) renderBtn.click();
                }
            },
        };
        // Filter the picker to elements matching the trigger's condition (section, etc.)
        if (PREVIEW_CONDITION) {
            modalSettings.condition = PREVIEW_CONDITION;
        }
        Craft.createElementSelectorModal(PREVIEW_ELEMENT_TYPE, modalSettings);
    }

    showSelectButton();

    function collectContent() {
        return {
            triggerId: (document.getElementById('preview-trigger-id') || {}).value || null,
            // The trigger's condition, so the server can auto-pick a representative
            // sample (one that would actually fire this trigger) when none is picked.
            // `condition` is the first group (picker-modal filter); `conditions` is
            // all OR groups (union auto-pick).
            condition: PREVIEW_CONDITION,
            conditions: PREVIEW_CONDITIONS,
            handle: (document.getElementById('handle') || {}).value || '',
            subject: (document.getElementById('subject') || {}).value || '',
            htmlBody: (document.getElementById('htmlBody') || {}).value || '',
            textBody: (document.getElementById('textBody') || {}).value || '',
            previewElementId: selectedElementId,
            previewElementType: document.getElementById('preview-element-type').value,
            channelUid: (document.getElementById('preview-sendtest-channel') || {}).value || '',
            recipients: (document.getElementById('recipients') || {}).value || '',
            cc: (document.getElementById('cc') || {}).value || '',
            bcc: (document.getElementById('bcc') || {}).value || '',
        };
    }

    function setHeaderRow(id, values, alwaysShow) {
        var row = document.getElementById(id + '-row');
        var span = document.getElementById(id);
        var arr = values || [];
        if (arr.length) {
            span.textContent = arr.join(', ');
            row.style.display = 'block';
        } else if (alwaysShow) {
            span.innerHTML = '<span class="courier-preview-unresolved">' + Craft.escapeHtml({$jsNothingResolved}) + '</span>';
            row.style.display = 'block';
        } else {
            row.style.display = 'none';
        }
    }

    // ── Render preview ──
    // Keep the refresh icon spinning for a beat even on instant renders, so the
    // action visibly registers instead of flashing.
    // Minimum time the loading state (skeleton + spinning icon) stays up, so a
    // refresh is always perceptible even when the render returns instantly.
    var RENDER_MIN_MS = 1000;
    var spinStartedAt = 0;
    function startSpin() {
        spinStartedAt = Date.now();
        renderBtn.disabled = true;
        renderBtn.classList.add('courier-render-spin');
    }
    function stopSpin() {
        renderBtn.disabled = false;
        renderBtn.classList.remove('courier-render-spin');
    }
    // Resolve after the remainder of RENDER_MIN_MS has elapsed (0 if already past),
    // so fast renders still show a full beat of loading and slow ones aren't delayed.
    function holdMin(value) {
        var wait = Math.max(0, RENDER_MIN_MS - (Date.now() - spinStartedAt));
        return new Promise(function(res) { setTimeout(function() { res(value); }, wait); });
    }

    renderBtn.addEventListener('click', function() {
        startSpin();

        // Show the shimmer skeleton only on the FIRST render. On a re-render keep
        // the current preview in place (the spinning icon signals the refresh) so
        // it doesn't flash back to the skeleton and in again.
        var alreadyShowing = document.getElementById('preview-message').style.display === 'block';
        if (!alreadyShowing) {
            document.getElementById('preview-empty').style.display = 'block';
            document.getElementById('preview-message').style.display = 'none';
            modeToggles.style.display = 'none';
        }

        fetch(Craft.getActionUrl('courier/triggers/preview'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': Craft.csrfTokenValue,
            },
            body: JSON.stringify(collectContent()),
        })
        .then(function(r) { return r.json(); })
        .then(holdMin)
        .then(function(result) {
            stopSpin();

            var errorEl = document.getElementById('preview-error');
            var emptyEl = document.getElementById('preview-empty');
            var frameContainer = document.getElementById('preview-frame-container');
            var textContainer = document.getElementById('preview-text-container');
            var messageEl = document.getElementById('preview-message');

            if (result.success) {
                errorEl.style.display = 'none';
                emptyEl.style.display = 'none';

                setHeaderRow('preview-to', result.recipients, true);
                setHeaderRow('preview-cc', result.cc, false);
                setHeaderRow('preview-bcc', result.bcc, false);
                document.getElementById('preview-subject-text').textContent = result.subject;
                document.getElementById('preview-subject-text-row').style.display = 'block';
                messageEl.style.display = 'block';

                var iframe = document.getElementById('preview-iframe');
                // Only reload the iframe when the HTML actually changed — avoids a
                // white flash when re-rendering identical content.
                if (iframe.srcdoc !== result.html) {
                    iframe.srcdoc = result.html;
                    iframe.onload = function() {
                        try {
                            var h = iframe.contentDocument.documentElement.scrollHeight;
                            iframe.style.height = Math.max(h, 140) + 'px';
                        } catch(e) {}
                    };
                }

                textContainer.textContent = result.text;
                modeToggles.style.display = 'inline-flex';
                showPreviewMode(modeToggles.querySelector('.btn.active').getAttribute('data-preview-mode'));
            } else {
                emptyEl.style.display = 'none';
                messageEl.style.display = 'none';
                modeToggles.style.display = 'none';
                errorEl.textContent = result.error;
                errorEl.style.display = 'block';
            }
        })
        .catch(function(err) {
            stopSpin();
            document.getElementById('preview-empty').style.display = 'none';
            document.getElementById('preview-message').style.display = 'none';
            document.getElementById('preview-error').textContent = {$jsRequestFailed} + err.message;
            document.getElementById('preview-error').style.display = 'block';
        });
    });

    // ── Send test ──
    if (sendTestBtn) {
        sendTestBtn.addEventListener('click', function() {
            // Confirm only when the selected target actually fires a live channel.
            var channelSel = document.getElementById('preview-sendtest-channel');
            var firesLive;
            if (channelSel) {
                var opt = channelSel.options[channelSel.selectedIndex];
                firesLive = opt && opt.getAttribute('data-live') === '1';
            } else {
                firesLive = sendTestBtn.getAttribute('data-has-live-channels') === '1';
            }
            if (firesLive && !window.confirm({$jsConfirmLive})) {
                return;
            }

            var statusEl = document.getElementById('preview-sendtest-status');
            sendTestBtn.disabled = true;
            sendTestBtn.textContent = {$jsSending};
            statusEl.textContent = '';
            statusEl.className = 'courier-preview-status';

            fetch(Craft.getActionUrl('courier/triggers/send-test'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': Craft.csrfTokenValue,
                },
                body: JSON.stringify(collectContent()),
            })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                sendTestBtn.disabled = false;
                sendTestBtn.textContent = {$jsSend};
                statusEl.textContent = result.message || (result.success ? {$jsSent} : {$jsFailed});
                statusEl.classList.add(result.success ? 'courier-preview-status--ok' : 'courier-preview-status--err');
            })
            .catch(function(err) {
                sendTestBtn.disabled = false;
                sendTestBtn.textContent = {$jsSend};
                statusEl.textContent = {$jsRequestFailed} + err.message;
                statusEl.classList.add('courier-preview-status--err');
            });
        });
    }

    // ── Auto-render the first time the Preview tab becomes visible ──
    // (rendering while the tab is hidden would mis-size the iframe).
    (function() {
        if (!renderBtn) return;
        var hasContent = function() {
            return (((document.getElementById('subject') || {}).value || '').trim() !== '')
                || (((document.getElementById('htmlBody') || {}).value || '').trim() !== '');
        };
        var fire = function() {
            // Only auto-render when there's a representative sample to show: an
            // element is picked, or a visual condition exists to scope the
            // auto-pick to elements that would actually fire this trigger. With
            // neither (e.g. a trigger gated solely by a Twig condition), auto-pick
            // falls back to "most recent of type" — an unrepresentative element
            // that often errors against a template expecting specific fields. In
            // that case, skip the auto-render and let the author pick a sample or
            // click Render themselves.
            if (!selectedElementId && !PREVIEW_CONDITION) return;
            if (hasContent()) renderBtn.click();
        };
        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function(entries) {
                for (var i = 0; i < entries.length; i++) {
                    if (entries[i].isIntersecting) {
                        io.disconnect();
                        fire();
                        break;
                    }
                }
            });
            io.observe(renderBtn);
        } else {
            fire();
        }
    })();

    // ── Draggable resize handle ──
    (function() {
        var handle = document.getElementById('preview-resize-handle');
        var wrap = document.getElementById('preview-iframe-wrap');
        var container = document.getElementById('preview-frame-container');
        var label = document.getElementById('preview-width-label');
        var iframe = document.getElementById('preview-iframe');
        var dragging = false;

        if (!handle) return;

        handle.addEventListener('mousedown', function(e) {
            e.preventDefault();
            dragging = true;
            label.style.display = 'block';
            iframe.style.pointerEvents = 'none';
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
        });

        document.addEventListener('mousemove', function(e) {
            if (!dragging) return;
            var containerRect = container.getBoundingClientRect();
            var containerCenter = containerRect.left + containerRect.width / 2;
            var halfWidth = Math.abs(e.clientX - containerCenter);
            var newWidth = Math.min(Math.max(halfWidth * 2, 280), containerRect.width - 48);
            wrap.style.width = Math.round(newWidth) + 'px';
            label.textContent = Math.round(newWidth) + 'px';
        });

        document.addEventListener('mouseup', function() {
            if (!dragging) return;
            dragging = false;
            iframe.style.pointerEvents = '';
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            setTimeout(function() { label.style.display = 'none'; }, 1500);
        });
    })();

    // ── Mode toggle ──
    var allPreviewPanes = {
        html: document.getElementById('preview-frame-container'),
        text: document.getElementById('preview-text-container'),
    };

    function showPreviewMode(mode) {
        Object.keys(allPreviewPanes).forEach(function(key) {
            if (allPreviewPanes[key]) {
                allPreviewPanes[key].style.display = key === mode ? 'block' : 'none';
            }
        });
        modeToggles.querySelectorAll('.btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.getAttribute('data-preview-mode') === mode);
        });
    }

    modeToggles.querySelectorAll('.btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            showPreviewMode(this.getAttribute('data-preview-mode'));
        });
    });
})();
JS;

        Craft::$app->getView()->registerJs($js);

        return Html::tag('div', $html, ['class' => 'courier-preview']);
    }
}
