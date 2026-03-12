<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing_mautic\EventSubscriber;

use Drupal\iq_content_publishing\Event\PreTransformEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Enriches AI instructions with Mautic template context.
 *
 * When a Mautic platform has a template email configured with an {ai_content}
 * token, this subscriber appends instructions telling the AI to generate a
 * content snippet that visually integrates with the template. The actual token
 * replacement is done programmatically — the AI only generates inner content.
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
   * Appends template context to AI instructions for Mautic platforms.
   *
   * @param \Drupal\iq_content_publishing\Event\PreTransformEvent $event
   *   The pre-transform event.
   */
  public function onPreTransform(PreTransformEvent $event): void {
    $platform = $event->getPlatform();

    if ($platform->getPluginId() !== 'mautic') {
      return;
    }

    $settings = $platform->getPluginSettings();
    $templateHtml = $this->loadTemplateHtml($settings);

    if (empty($templateHtml) || !str_contains($templateHtml, '{ai_content}')) {
      return;
    }

    $event->setInstructions($event->getInstructions() . $this->buildTemplateInstructions($templateHtml));
  }

  /**
   * Loads template HTML from file storage or config fallback.
   *
   * @param array $settings
   *   The platform plugin settings.
   *
   * @return string
   *   The template HTML, or empty string if unavailable.
   */
  private function loadTemplateHtml(array $settings): string {
    // Prefer file-based storage.
    if (!empty($settings['template_file_uri'])) {
      $contents = @file_get_contents($settings['template_file_uri']);
      if ($contents !== FALSE) {
        return $contents;
      }
    }

    // Fallback to config-stored HTML.
    if (!empty($settings['template_html'])) {
      return is_array($settings['template_html'])
        ? ($settings['template_html']['value'] ?? '')
        : (string) $settings['template_html'];
    }

    return '';
  }

  /**
   * Builds AI instructions for template-based content generation.
   *
   * Provides the template structure as context so the AI generates content
   * that visually integrates with the email design. The actual token
   * replacement ({ai_content}) is done programmatically, not by the AI.
   *
   * @param string $templateHtml
   *   The full template HTML containing the {ai_content} token.
   *
   * @return string
   *   The instruction text to append.
   */
  private function buildTemplateInstructions(string $templateHtml): string {
    return <<<CONTEXT


--- MAUTIC EMAIL TEMPLATE CONTEXT ---
A Mautic email template is configured. Your html_body output will be inserted
into this template at the {ai_content} placeholder position.

YOUR TASK:
1. Generate ONLY the inner HTML content snippet for the html_body field.
2. Do NOT include <!DOCTYPE>, <html>, <head>, or <body> tags.
3. Use inline CSS and email-compatible HTML (tables, inline styles).
4. Structure the content with headings, paragraphs, and links as appropriate.
5. Match the styling of the surrounding template (fonts, colors, spacing).
6. Generate subject, name, and plain_text fields normally.

TEMPLATE HTML (for style reference):
```html
{$templateHtml}
```
--- END TEMPLATE CONTEXT ---
CONTEXT;
  }

}
