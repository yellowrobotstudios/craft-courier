<?php

namespace yellowrobot\courier\jobs;

use Craft;
use craft\queue\BaseJob;
use yellowrobot\courier\Courier;
use yellowrobot\courier\elements\EmailTemplate;
use yellowrobot\courier\traits\RehydratesVariablesTrait;
use yellowrobot\courier\traits\ResolvesRecipientsTrait;

/**
 * Renders a trigger's template and delivers it via one channel config.
 * One job is queued per selected channel.
 */
class SendCourierJob extends BaseJob
{
    use RehydratesVariablesTrait;
    use ResolvesRecipientsTrait;

    public ?string $triggerUid = null;
    public string $templateHandle = '';
    public string $channelUid = '';
    public bool $isTest = false;
    public array $variables = [];

    /**
     * Recipient expressions snapshotted from the trigger at fire time. These are
     * trigger-level (who the notification goes to), not channel-level (the channel
     * is just transport). Twig + env vars, evaluated per event. For self-addressed
     * channels (Slack/Webhook) these are ignored — the destination is on the channel.
     */
    public ?string $recipients = null;
    public ?string $cc = null;
    public ?string $bcc = null;

    /**
     * 'list' (default) sends one message addressed to the whole recipient list.
     * 'individual' sends a separate message per To recipient, so recipients never
     * see each other (privacy) and the body can personalize per recipient. Cc/Bcc
     * ride along on each individual message. Self-addressed channels (Slack/Webhook)
     * have no recipient list, so they always send once regardless of mode.
     */
    public string $sendMode = 'list';

    public function execute($queue): void
    {
        $plugin = Courier::$plugin;
        $variables = $this->_rehydrateVariables($this->variables);

        $channel = $plugin->channels->getConfigByUid($this->channelUid);
        $template = EmailTemplate::find()->handle($this->templateHandle)->status(null)->one();

        if (!$channel) {
            $plugin->log->logSend($this->triggerUid, $this->templateHandle, null, '', '', 'failed', 'Channel config not found.', $this->isTest);
            return;
        }
        if (!$template) {
            $plugin->log->logSend($this->triggerUid, $this->templateHandle, $channel->handle, '', '', 'failed', 'Template not found.', $this->isTest);
            return;
        }

        // Recipients/Cc/Bcc live on the trigger (Twig, evaluated per event). The
        // channel is transport only; self-addressed channels (Slack/Webhook) ignore these.
        $recipients = $this->renderRecipientList($this->recipients, $variables);
        $cc = $this->renderRecipientList($this->cc, $variables);
        $bcc = $this->renderRecipientList($this->bcc, $variables);

        try {
            $rendered = $plugin->email->render($template->handle, $template, $variables);
        } catch (\Throwable $e) {
            $plugin->log->logSend($this->triggerUid, $template->handle, $channel->handle, implode(', ', $recipients), '', 'failed', $e->getMessage(), $this->isTest);
            return;
        }

        // Individual mode fans a multi-recipient list into one message each; every
        // other case (list mode, single recipient, or self-addressed channel) sends once.
        if ($this->sendMode === 'individual' && count($recipients) > 1) {
            foreach ($recipients as $address) {
                $this->_deliver($plugin, $channel, $template, [$address], $cc, $bcc, $rendered);
            }
            return;
        }

        $this->_deliver($plugin, $channel, $template, $recipients, $cc, $bcc, $rendered);
    }

    /**
     * Send one message through the channel and log the outcome.
     *
     * @param string[] $to
     * @param string[] $cc
     * @param string[] $bcc
     * @param array{subject:string,html:string,text:string} $rendered
     */
    private function _deliver($plugin, $channel, EmailTemplate $template, array $to, array $cc, array $bcc, array $rendered): void
    {
        $result = $plugin->channels->send($channel, [
            'to' => $to ?: '',
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $rendered['subject'],
            'html' => $rendered['html'],
            'text' => $rendered['text'],
        ]);

        $plugin->log->logSend(
            $this->triggerUid,
            $template->handle,
            $channel->handle,
            $result['recipient'] !== '' ? $result['recipient'] : implode(', ', $to),
            $rendered['subject'],
            $result['success'] ? 'sent' : 'failed',
            $result['error'],
            $this->isTest,
        );
    }


    protected function defaultDescription(): ?string
    {
        return "Sending notification: {$this->templateHandle}";
    }
}
