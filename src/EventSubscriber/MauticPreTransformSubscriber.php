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
      // Body-section mode: AI replaces only the content between the markers.
      $bodySection = substr(
        $templateHtml,
        $startPos + strlen($bodyStartMarker),
        $endPos - $startPos - strlen($bodyStartMarker)
      );

      $templateContext = <<<CONTEXT


--- MAUTIC EMAIL TEMPLATE (BODY REPLACEMENT) ---
The email template uses <!-- BODY_START --> and <!-- BODY_END --> markers.
Everything OUTSIDE these markers (header, footer, branding, navigation,
social links, unsubscribe section) is KEPT EXACTLY AS-IS — you must NOT
reproduce, modify, or reference any of it.

Your html_body output will REPLACE everything between the markers.

== BODY SECTION FROM THE TEMPLATE ==
Below is the current content between the markers. Treat it as the DESIGN
BLUEPRINT — your output must reuse its exact HTML patterns:

```html
{$bodySection}
```

== YOUR TASK ==
1. PROCESS the Drupal node content (title, body, images, summary, URL)
   and produce email-ready HTML that communicates the node's information.
2. STRUCTURE your output using the same component types found in the
   blueprint above. For every distinct component pattern you see (e.g.
   title block, text paragraph, image + text row, CTA button, divider,
   multi-column cards), copy its exact HTML markup and inline CSS — then
   fill it with data derived from the node content.
3. You MAY add, remove, or repeat components to fit the actual content
   length — but every component you use MUST come from the blueprint's
   pattern library. Do NOT invent new tags, classes, or styles.
4. PRESERVE all structural wrappers: table/tr/td nesting, <!--[if mso | IE]>
   conditional comments, width constraints (e.g. max-width:600px), padding,
   and margin values exactly as they appear in the blueprint.
5. MATCH the visual design: same colors, font-family, font-size,
   line-height, letter-spacing, background-color, border-radius values.
6. KEEP any Mautic tokens you include (e.g. {contactfield=firstname})
   but do NOT add tokens that are already in the header/footer.
7. OUTPUT only the replacement body HTML — no <html>, <head>, <body>,
   <!DOCTYPE>, and no header/footer sections.
--- END TEMPLATE ---
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
