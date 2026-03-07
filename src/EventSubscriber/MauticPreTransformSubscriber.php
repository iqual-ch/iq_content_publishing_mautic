<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing_mautic\EventSubscriber;

use Drupal\iq_content_publishing\Event\PreTransformEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Enriches AI instructions with Mautic template context.
 *
 * When a Mautic platform has a template email configured, this subscriber
 * appends the template's HTML structure to the AI instructions so the AI
 * can generate content that matches the template's design and structure.
 */
final class MauticPreTransformSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreTransformEvent::EVENT_NAME => ['onPreTransform', 0],
    ];
  }

  /**
   * Appends template HTML context to AI instructions for Mautic platforms.
   *
   * @param \Drupal\iq_content_publishing\Event\PreTransformEvent $event
   *   The pre-transform event.
   */
  public function onPreTransform(PreTransformEvent $event): void {
    $platform = $event->getPlatform();

    // Only act on Mautic platforms.
    if ($platform->getPluginId() !== 'mautic') {
      return;
    }

    $settings = $platform->getPluginSettings();
    $templateEmailId = !empty($settings['template_email_id']) ? (int) $settings['template_email_id'] : 0;

    if ($templateEmailId <= 0) {
      return;
    }

    // Get the stored template HTML.
    $templateHtml = '';
    if (!empty($settings['template_html'])) {
      $templateHtml = is_array($settings['template_html'])
        ? ($settings['template_html']['value'] ?? '')
        : (string) $settings['template_html'];
    }

    if (empty($templateHtml)) {
      return;
    }

    // Detect AI content tokens in the template.
    $aiTokens = [];
    if (preg_match_all('/\[ai:([a-zA-Z0-9_]+)\]/', $templateHtml, $matches)) {
      $aiTokens = array_values(array_unique($matches[1]));
    }

    if (!empty($aiTokens)) {
      // Token replacement mode: instruct the AI to generate text for each token.
      $tokenList = implode(', ', array_map(fn($t) => "[ai:{$t}]", $aiTokens));
      $tokenDescriptions = implode("\n", array_map(
        fn($t) => "- [ai:{$t}]: Generate appropriate content for the \"{$t}\" section.",
        $aiTokens
      ));

      $templateContext = <<<CONTEXT


--- MAUTIC EMAIL TEMPLATE (TOKEN REPLACEMENT MODE) ---
A Mautic email template is configured with content placeholders.
Your task is to generate ONLY the replacement text for each placeholder token.
The template already handles all layout, styling, header, footer, and branding.

The template contains these AI content tokens: {$tokenList}

For each token, here is what to generate:
{$tokenDescriptions}

CRITICAL FORMAT INSTRUCTIONS for the html_body field:
Output ONLY the token values using this EXACT delimited format:

[ai:token_name]
Your generated content for this token
[/ai:token_name]

Rules:
- Include ALL tokens listed above in your html_body output.
- Generate concise, focused content appropriate for each token's purpose.
- You MAY use simple inline HTML: <strong>, <em>, <a href="...">, <br>, <span>.
- Do NOT generate full HTML structure, <table>, <div>, or complex layouts.
- Do NOT include headers, footers, or branding — the template handles all of that.
- The token name in the closing tag must exactly match the opening tag.

Example format:
[ai:headline]
Discover Our Latest Updates
[/ai:headline]

[ai:intro]
Stay informed with the newest features and improvements we have made this month.
[/ai:intro]

Template HTML (for design context — analyze the surrounding content to match tone and style):
```html
{$templateHtml}
```
--- END TEMPLATE CONTEXT ---
CONTEXT;
    }
    else {
      // Legacy full-content mode with {custom_content} placeholder.
      $templateContext = <<<CONTEXT


--- MAUTIC EMAIL TEMPLATE CONTEXT ---
A Mautic email template is configured.

Analyze the template's structure, styling, colors, fonts, and design patterns,
then generate inner content that visually integrates with it. Specifically:
- Match the template's color scheme and font choices (use the same inline CSS patterns).
- Use compatible table-based layout widths (look at the template's content width).
- Do NOT duplicate elements the template already provides (e.g., header, footer,
  unsubscribe links, web view links, logo, company info) and do NOT change them.
- If the template include a header image, replace it with one of the images extracted from the content.
  Else keep the header image from the template.
- Generate the inner content using the same kind of components than the template.

Template HTML:
```html
{$templateHtml}
```
--- END TEMPLATE CONTEXT ---
CONTEXT;
    }

    $event->setInstructions($event->getInstructions() . $templateContext);
  }

}
