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

    $contentToken = $settings['template_content_token'] ?? '{custom_content}';

    $templateContext = <<<CONTEXT


--- MAUTIC EMAIL TEMPLATE CONTEXT ---
A Mautic email template is configured. Your generated HTML content will replace
the placeholder token "{$contentToken}" inside the template HTML below.

Analyze the template's structure, styling, colors, fonts, and design patterns,
then generate inner content that visually integrates with it. Specifically:
- Match the template's color scheme and font choices (use the same inline CSS patterns).
- Use compatible table-based layout widths (look at the template's content width).
- Do NOT duplicate elements the template already provides (e.g., header, footer,
  unsubscribe links, web view links, logo, company info).
- Generate ONLY the inner content that replaces "{$contentToken}".

Template HTML:
```html
{$templateHtml}
```
--- END TEMPLATE CONTEXT ---
CONTEXT;

    $event->setInstructions($event->getInstructions() . $templateContext);
  }

}
