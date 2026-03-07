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

    // Split template into header / body / footer by markers.
    $bodyStartMarker = '<!-- BODY_START -->';
    $bodyEndMarker = '<!-- BODY_END -->';
    $startPos = strpos($templateHtml, $bodyStartMarker);
    $endPos = strpos($templateHtml, $bodyEndMarker);

    if ($startPos !== FALSE && $endPos !== FALSE && $endPos > $startPos) {
      // Body-section mode: AI uses the body as a design reference.
      $bodySection = substr(
        $templateHtml,
        $startPos + strlen($bodyStartMarker),
        $endPos - $startPos - strlen($bodyStartMarker)
      );

      $templateContext = <<<CONTEXT


--- MAUTIC EMAIL TEMPLATE (BODY DESIGN REFERENCE) ---
A Mautic email template is configured with body section markers.
The header and footer of this email are FIXED and will be preserved automatically.
Your task is to generate ONLY the body section HTML.

Analyze the body section below as a DESIGN REFERENCE:
- Identify the component patterns: how titles, text blocks, CTAs (call-to-action
  buttons), dividers, image+text sections, dark/light sections are structured.
- Note the exact HTML patterns: table layouts, inline CSS, class names, spacing,
  color scheme, font families, font sizes, line heights.
- Understand the design system: how components are composed using MJML-style
  column layouts, padding, and background colors.

Then generate NEW body HTML content that:
1. Uses the EXACT SAME HTML component patterns and inline CSS from the reference.
2. Reuses the same table structures, <td> styles, classes, and spacing.
3. Fills the components with content derived from the Drupal node.
4. Adapts the number and type of components to fit the actual content
   (you may use fewer or more components than the reference shows).
5. Preserves any Mautic tokens like {contactfield=firstname}, {unsubscribe_url},
   {webview_url} if you include them.
6. Includes all <!--[if mso | IE]> conditional comments where the reference uses them.

CRITICAL:
- Output ONLY the body section HTML — no <html>, <head>, <body> tags.
- Do NOT output the header or footer — they are automatically preserved.
- Do NOT invent new CSS classes or styles — strictly reuse what the reference provides.
- Keep the same max-width (e.g. 600px) and column proportions.

Body section design reference:
```html
{$bodySection}
```
--- END TEMPLATE CONTEXT ---
CONTEXT;
    }
    else {
      // No body markers: provide full template as general design context.
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
