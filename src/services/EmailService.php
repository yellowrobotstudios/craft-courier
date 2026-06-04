<?php

namespace yellowrobot\courier\services;

use Craft;
use craft\base\Component;
use yellowrobot\courier\Courier;
use yellowrobot\courier\elements\EmailTemplate;

class EmailService extends Component
{
    /**
     * Render an email template with variables and optional layout wrapping.
     * Used by both send and preview.
     */
    public function render(string $templateHandle, EmailTemplate $template, array $variables): array
    {
        $view = Craft::$app->getView();

        // Ensure site template mode so {% include "_email/..." %} resolves
        $oldMode = $view->getTemplateMode();
        $view->setTemplateMode($view::TEMPLATE_MODE_SITE);

        try {
            $subject = $view->renderString($template->subject, $variables);

            // Body file override takes precedence over the DB body
            $bodyFileTemplate = $template->getBodyFileTemplate();
            $html = $bodyFileTemplate !== null
                ? $view->renderTemplate($bodyFileTemplate, $variables)
                : $view->renderString((string) $template->htmlBody, $variables);

            $text = $template->textBody
                ? $view->renderString($template->textBody, $variables)
                : $this->_htmlToText($html);

            // Wrap in layout if one is configured
            $layout = $this->getLayoutForTemplate($templateHandle);

            if ($layout) {
                $html = $view->renderTemplate($layout, array_merge($variables, [
                    'content' => $html,
                    'subject' => $subject,
                ]));
            }
        } finally {
            $view->setTemplateMode($oldMode);
        }

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];
    }

    /**
     * Convert HTML to readable plain text, preserving table structure.
     */
    private function _htmlToText(string $html): string
    {
        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $html);

        // Convert links before table processing so cells get clean text
        $text = preg_replace_callback('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si', function ($match) {
            $url = $match[1];
            $linkText = trim(strip_tags($match[2]));
            // mailto: links — just show the email address
            if (str_starts_with($url, 'mailto:')) {
                return $linkText;
            }
            // Don't duplicate if link text IS the URL
            return $linkText === $url ? $url : "{$linkText} ({$url})";
        }, $text);

        // Convert table rows: each <tr> becomes a line, cells separated by " - "
        $text = preg_replace_callback('/<tr[^>]*>(.*?)<\/tr>/si', function ($match) {
            $cells = [];
            preg_match_all('/<t[hd][^>]*>(.*?)<\/t[hd]>/si', $match[1], $cellMatches);
            foreach ($cellMatches[1] as $cell) {
                $value = trim(strip_tags($cell));
                if ($value !== '') {
                    $cells[] = $value;
                }
            }
            return implode(' - ', $cells) . "\n";
        }, $text);

        // Strip opening block tags (closing ones get newlines below)
        $text = preg_replace('/<(?:table|div|blockquote|ul|ol)[^>]*>/i', '', $text);

        // Block elements get newlines
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = preg_replace('/<\/(?:div|h[1-6]|table|blockquote|li)>/i', "\n", $text);

        // Strip remaining tags
        $text = strip_tags($text);

        // Decode entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // Collapse whitespace: trim each line, then limit consecutive blank lines
        $text = preg_replace('/^[ \t]+|[ \t]+$/m', '', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Resolve the layout template that wraps the rendered body, if any.
     */
    private function getLayoutForTemplate(string $templateHandle): ?string
    {
        return Courier::$plugin->getSettings()->defaultLayout;
    }
}
