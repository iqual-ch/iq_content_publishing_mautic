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
 *
 * Content slots mode: Admin places {ai:slot_name} tokens anywhere in the
 * template. The AI generates only plain text for each slot. Template HTML
 * is 100% untouched — slots are filled via str_replace at publish time.
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
    $templateEmailId = !empty($settings['template_email_id']) ? (int) $settings['template_email_id'] : 0;

    if ($templateEmailId <= 0) {
      return;
    }

    $templateHtml = '';
    if (!empty($settings['template_html'])) {
      $templateHtml = is_array($settings['template_html'])
        ? ($settings['template_html']['value'] ?? '')
        : (string) $settings['template_html'];
    }

    if (empty($templateHtml)) {
      return;
    }

    // Check for {ai:slot_name} content slots in the template.
    $slots = [];
    if (preg_match_all('/\{ai:([\w-]+)\}/', $templateHtml, $matches)) {
      foreach ($matches[1] as $slotName) {
        $slots[$slotName] = $this->inferSlotContext($slotName, $templateHtml);
      }
    }

    if (!empty($slots)) {
      // Content slot mode: AI generates only text for each slot.
      $templateContext = $this->buildSlotInstructions($slots);
    }
    else {
      // No slots: provide full template as general design context.
      $templateContext = $this->buildFullTemplateInstructions($templateHtml);
    }

    $event->setInstructions($event->getInstructions() . $templateContext);
  }

  /**
   * Builds AI instructions for content slot mode.
   *
   * @param array $slots
   *   Associative array of slot names to context descriptions.
   *
   * @return string
   *   The instruction text to append.
   */
  private function buildSlotInstructions(array $slots): string {
    $slotList = '';
    foreach ($slots as $name => $context) {
      $slotList .= "- {$name}: {$context}\n";
    }

    return <<<CONTEXT


--- CONTENT SLOTS MODE ---
The email template has a fixed HTML layout. You do NOT generate any HTML.
Instead, generate plain-text content for each named slot listed below.
The template's HTML structure, styling, and design are preserved automatically.

Your html_body output MUST use this exact format — one block per slot:

<!-- slot:slot_name -->
Your text content here
<!-- /slot:slot_name -->

SLOTS TO FILL (derived from the Drupal node content):
{$slotList}
RULES:
1. Output ONLY the slot blocks above in html_body — nothing else.
2. Each slot value is PLAIN TEXT (or minimal inline HTML like <strong>, <em>,
   <a href="..."> ONLY when the slot context indicates it — e.g. a paragraph
   that needs a link). No tables, no divs, no styling.
3. Derive ALL content from the Drupal node being processed (title, body,
   summary, images, URL).
4. Keep text concise and appropriate to the slot's context.
5. For URL slots (href, src), output just the URL — no markup.
6. For image slots, output the image URL from the node content.
7. Do NOT include any HTML outside the slot comment blocks.
8. Generate subject, name, and plain_text normally (as full fields).
--- END CONTENT SLOTS ---
CONTEXT;
  }

  /**
   * Builds AI instructions when no content slots are present.
   *
   * @param string $templateHtml
   *   The full template HTML.
   *
   * @return string
   *   The instruction text to append.
   */
  private function buildFullTemplateInstructions(string $templateHtml): string {
    return <<<CONTEXT


--- MAUTIC EMAIL TEMPLATE CONTEXT ---
A Mautic email template is configured. Analyze its structure and styling,
then generate inner content that visually integrates with it:
- Match colors, fonts, and inline CSS patterns.
- Use compatible table-based layout widths.
- Do NOT duplicate elements the template already provides (header, footer, etc.).

Template HTML:
```html
{$templateHtml}
```
--- END TEMPLATE CONTEXT ---
CONTEXT;
  }

  /**
   * Infers the context/purpose of a slot from its name and surrounding HTML.
   *
   * Examines the HTML around the {ai:slot_name} token to determine whether
   * it sits inside a heading, paragraph, link, image, or other context.
   *
   * @param string $slotName
   *   The slot name (e.g. 'headline', 'cta_url').
   * @param string $bodyHtml
   *   The body section HTML containing the token.
   *
   * @return string
   *   A short description of the expected content.
   */
  private function inferSlotContext(string $slotName, string $bodyHtml): string {
    $token = '{ai:' . $slotName . '}';
    $pos = strpos($bodyHtml, $token);

    if ($pos === FALSE) {
      return $this->inferFromName($slotName);
    }

    // Look at the ~200 chars surrounding the token for context clues.
    $start = max(0, $pos - 200);
    $surrounding = substr($bodyHtml, $start, 400 + strlen($token));

    // Check if token is inside an href attribute → URL slot.
    if (preg_match('/href\s*=\s*["\']' . preg_quote($token, '/') . '["\']/', $surrounding)) {
      return 'URL (output a full URL only, no markup)';
    }

    // Check if token is inside an img src attribute → image URL slot.
    if (preg_match('/src\s*=\s*["\']' . preg_quote($token, '/') . '["\']/', $surrounding)) {
      return 'Image URL (output a full image URL only, no markup)';
    }

    // Check if token is inside an alt attribute → short alt text.
    if (preg_match('/alt\s*=\s*["\']' . preg_quote($token, '/') . '["\']/', $surrounding)) {
      return 'Image alt text (short descriptive text)';
    }

    // Check for heading context.
    if (preg_match('/<h[1-6][^>]*>[^<]*' . preg_quote($token, '/') . '/i', $surrounding)) {
      return 'Heading text (short, compelling, NO HTML tags)';
    }

    // Check for link text (token inside <a> but not in href).
    if (preg_match('/<a\s[^>]*>[^<]*' . preg_quote($token, '/') . '/i', $surrounding)) {
      return 'Link/button label (short action text, NO HTML tags)';
    }

    // Check for paragraph / text block context.
    if (preg_match('/<p[^>]*>[^{]*' . preg_quote($token, '/') . '/i', $surrounding)
      || preg_match('/<td[^>]*>[^{]*' . preg_quote($token, '/') . '/i', $surrounding)) {
      return 'Paragraph text (can include basic inline HTML: <strong>, <em>, <a>)';
    }

    // Fallback: infer from the slot name itself.
    return $this->inferFromName($slotName);
  }

  /**
   * Infers slot context from the slot name when HTML context is ambiguous.
   *
   * @param string $slotName
   *   The slot name.
   *
   * @return string
   *   A short description of the expected content.
   */
  private function inferFromName(string $slotName): string {
    $name = strtolower($slotName);

    if (str_contains($name, 'url') || str_contains($name, 'href') || str_contains($name, 'link')) {
      return 'URL (output a full URL only, no markup)';
    }
    if (str_contains($name, 'image') || str_contains($name, 'img') || str_contains($name, 'photo') || str_contains($name, 'src')) {
      return 'Image URL (output a full image URL only, no markup)';
    }
    if (str_contains($name, 'alt')) {
      return 'Image alt text (short descriptive text)';
    }
    if (str_contains($name, 'title') || str_contains($name, 'headline') || str_contains($name, 'heading')) {
      return 'Heading text (short, compelling, NO HTML tags)';
    }
    if (str_contains($name, 'cta') || str_contains($name, 'button')) {
      return 'Button/CTA label (short action text like "Read more", NO HTML tags)';
    }
    if (str_contains($name, 'summary') || str_contains($name, 'intro') || str_contains($name, 'excerpt') || str_contains($name, 'teaser')) {
      return 'Short summary text (1-3 sentences, can include <strong>/<em>)';
    }
    if (str_contains($name, 'body') || str_contains($name, 'text') || str_contains($name, 'content') || str_contains($name, 'description')) {
      return 'Paragraph text (can include basic inline HTML: <strong>, <em>, <a>)';
    }
    if (str_contains($name, 'date')) {
      return 'Date string (formatted date text)';
    }

    return 'Text content (keep it concise, minimal or no HTML)';
  }

}
