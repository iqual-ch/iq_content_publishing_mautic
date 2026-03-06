<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing_mautic\Plugin\ContentPublishingPlatform;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\iq_content_publishing\Attribute\ContentPublishingPlatform;
use Drupal\iq_content_publishing\Plugin\ContentPublishingPlatformBase;
use Drupal\iq_content_publishing\Plugin\PublishingResult;
use Drupal\iq_content_publishing_mautic\Service\MauticApiClient;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Mautic email publishing platform plugin.
 *
 * Creates and sends Mautic email campaigns from AI-generated content
 * derived from Drupal node content. Leverages the drupal/mautic_api module
 * for API connectivity to Mautic instances.
 */
#[ContentPublishingPlatform(
  id: 'mautic',
  label: new TranslatableMarkup('Mautic'),
  description: new TranslatableMarkup('Create and send email campaigns via Mautic marketing automation.'),
)]
final class MauticPlatform extends ContentPublishingPlatformBase {

  /**
   * The Mautic API client.
   */
  protected MauticApiClient $apiClient;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->apiClient = $container->get('iq_content_publishing_mautic.api_client');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      'subject' => [
        'type' => 'textfield',
        'label' => (string) $this->t('Email subject line'),
        'description' => (string) $this->t('The subject line for the email. Should be compelling and under 150 characters.'),
        'required' => TRUE,
        'max_length' => 150,
        'ai_generated' => TRUE,
      ],
      'name' => [
        'type' => 'textfield',
        'label' => (string) $this->t('Internal email name'),
        'description' => (string) $this->t('An internal name for the email in Mautic. Used for identification in the Mautic dashboard.'),
        'required' => TRUE,
        'max_length' => 255,
        'ai_generated' => TRUE,
      ],
      'html_body' => [
        'type' => 'text_format',
        'label' => (string) $this->t('Email HTML body'),
        'description' => (string) $this->t('The main HTML content of the email. Use well-structured HTML suitable for email clients.'),
        'required' => TRUE,
        'ai_generated' => TRUE,
        'format' => 'easy_email',
      ],
      'plain_text' => [
        'type' => 'textarea',
        'label' => (string) $this->t('Plain-text version'),
        'description' => (string) $this->t('A plain-text alternative of the email content for email clients that do not support HTML.'),
        'required' => FALSE,
        'ai_generated' => TRUE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultAiInstructions(): string {
    return <<<'INSTRUCTIONS'
Transform the following Drupal content into a professional email newsletter for Mautic.

Guidelines:
- Generate a compelling subject line (under 150 characters) that encourages opens.
- Create a concise internal name for identification in the Mautic dashboard.
- Create an HTML email body that is well-structured and email-client friendly.
- Use simple, inline CSS for styling (no external stylesheets).
- Use table-based layouts for maximum email client compatibility.
- Design a visually appealing layout with a clear visual hierarchy.
- Use professional typography and spacing.
- Include a header area with the newsletter title/branding.
- Include a clear call-to-action linking to the original content.
- Keep paragraphs short and scannable.
- Maintain a professional yet engaging tone.
- Include the content URL as a "Read more" link.
- You may use Mautic tokens like {contactfield=firstname} for personalization.
- Also generate a plain-text version without HTML markup.

CRITICAL: Generate ONLY the inner email content — do NOT include <html>, <head>,
or <body> tags. The output will be automatically wrapped in a complete HTML email
document structure or injected into a Mautic template. Focus on creating rich,
well-designed content using table-based layouts with inline CSS.

Available tokens:
- [node:title] — The content title.
- [node:url] — The full URL to the content.
- [node:summary] — The content summary.
- [node:content_type] — The content type label.

Mautic personalization tokens (use in the HTML body):
- {contactfield=firstname} — Contact's first name.
- {contactfield=lastname} — Contact's last name.
- {contactfield=email} — Contact's email address.
- {unsubscribe_text} — Unsubscribe link text.
- {webview_text} — Web view link text.
INSTRUCTIONS;
  }

  /**
   * {@inheritdoc}
   */
  public function buildCredentialsForm(array $form, array $credentials): array {
    // Load available Mautic API connections for the select list.
    $connectionOptions = [];
    try {
      $connections = $this->entityTypeManager
        ->getStorage('mautic_api_connection')
        ->loadMultiple();
      foreach ($connections as $connection) {
        $connectionOptions[$connection->id()] = $connection->label();
      }
    }
    catch (\Exception $e) {
      // If the entity type isn't available, show a text field fallback.
    }

    if (!empty($connectionOptions)) {
      $form['connection_id'] = [
        '#type' => 'select',
        '#title' => $this->t('Mautic API Connection'),
        '#description' => $this->t('Select the Mautic API connection to use. Connections are managed at <a href="/admin/config/services/mautic/api">Mautic API settings</a>.'),
        '#options' => $connectionOptions,
        '#default_value' => $credentials['connection_id'] ?? '',
        '#required' => TRUE,
        '#empty_option' => $this->t('- Select a connection -'),
      ];
    }
    else {
      $form['connection_id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Mautic API Connection ID'),
        '#description' => $this->t('The machine name of the Mautic API connection entity. Create connections at <a href="/admin/config/services/mautic/api">Mautic API settings</a>.'),
        '#default_value' => $credentials['connection_id'] ?? '',
        '#required' => TRUE,
      ];
    }

    // Show connection validation status.
    if (!empty($credentials['connection_id'])) {
      $isValid = $this->apiClient->validateConnection($credentials['connection_id']);
      $form['connection_status'] = [
        '#type' => 'item',
        '#title' => $this->t('Connection Status'),
        '#markup' => $isValid
          ? '<span style="color: green;">✓ Connected to Mautic</span>'
          : '<span style="color: red;">✗ Connection failed. Verify the Mautic API connection settings.</span>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSettingsForm(array $form, array $settings, array $credentials = []): array {
    $form['segment_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Segment ID'),
      '#description' => $this->t('The Mautic segment ID to send the email to. Use the "Fetch Segments" operation from the platform list to discover your segment IDs.'),
      '#default_value' => $settings['segment_id'] ?? NULL,
      '#min' => 0,
    ];

    $form['from_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('From name'),
      '#description' => $this->t('The name that appears in the "From" field of the email. Leave empty to use the Mautic default.'),
      '#default_value' => $settings['from_name'] ?? '',
    ];

    $form['from_address'] = [
      '#type' => 'email',
      '#title' => $this->t('From email address'),
      '#description' => $this->t('The email address that appears in the "From" field. Leave empty to use the Mautic default.'),
      '#default_value' => $settings['from_address'] ?? '',
    ];

    $form['reply_to'] = [
      '#type' => 'email',
      '#title' => $this->t('Reply-to email'),
      '#description' => $this->t('The email address recipients can reply to. Leave empty to use the from address.'),
      '#default_value' => $settings['reply_to'] ?? '',
    ];

    $form['email_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Email type'),
      '#description' => $this->t('"Segment (List)" emails are sent to a segment. "Template" emails are transactional and sent via API or campaigns.'),
      '#options' => [
        'list' => $this->t('Segment (List) email'),
        'template' => $this->t('Template email'),
      ],
      '#default_value' => $settings['email_type'] ?? 'list',
    ];

    $form['send_immediately'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send email immediately'),
      '#description' => $this->t('When checked and the email type is "Segment (List)", the email will be sent immediately after creation. Otherwise, the email will be created as a draft/published in Mautic for manual sending.'),
      '#default_value' => $settings['send_immediately'] ?? FALSE,
    ];

    // Template settings fieldset.
    $form['template_wrapper'] = [
      '#type' => 'details',
      '#title' => $this->t('Email layout template'),
      '#open' => !empty($settings['template_email_id']),
      '#description' => $this->t('Optionally use an existing Mautic email as a layout template. The template provides the outer HTML structure (header, footer, branding) and the AI-generated content is injected into it.<br><br><strong>Without a template:</strong> The AI-generated content is automatically wrapped in a clean, responsive HTML email document.<br><strong>With a template:</strong> The AI-generated content replaces the placeholder token in the template email\'s HTML.'),
    ];

    $form['template_wrapper']['template_email_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Template email ID'),
      '#description' => $this->t('The ID of an existing Mautic template-type email to use as the layout wrapper. Leave empty for standalone mode. Use the "Fetch Templates" operation to discover available IDs.'),
      '#default_value' => $settings['template_email_id'] ?? NULL,
      '#min' => 0,
      '#parents' => ['plugin_settings', 'template_email_id'],
      '#ajax' => [
        'callback' => '::pluginSettingsAjax',
        'wrapper' => 'plugin-settings-wrapper',
        'event' => 'change',
      ],
    ];

    // Show template HTML editor and related fields when a template ID is set.
    $templateEmailId = !empty($settings['template_email_id']) ? (int) $settings['template_email_id'] : 0;
    $connectionId = $credentials['connection_id'] ?? '';

    if ($templateEmailId > 0 && !empty($connectionId)) {
      // Determine whether to use stored HTML or fetch fresh from Mautic.
      $storedLoadedId = (int) ($settings['_template_loaded_id'] ?? 0);
      if ($storedLoadedId === $templateEmailId && !empty($settings['template_html'])) {
        // Same template ID — reuse stored/edited HTML.
        $templateHtml = is_array($settings['template_html'])
          ? ($settings['template_html']['value'] ?? '')
          : (string) $settings['template_html'];
      }
      else {
        // New template ID — fetch from Mautic API.
        $templateHtml = $this->apiClient->getEmailHtml($connectionId, $templateEmailId) ?? '';
      }

      // Track which template ID the HTML belongs to, to detect changes.
      $form['template_wrapper']['_template_loaded_id'] = [
        '#type' => 'hidden',
        '#value' => $templateEmailId,
        '#parents' => ['plugin_settings', '_template_loaded_id'],
      ];

      $form['template_wrapper']['template_content_token'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Template content placeholder'),
        '#description' => $this->t('The placeholder token in the template HTML that marks where AI-generated content is injected. Ensure this exact string exists in the template HTML below.'),
        '#default_value' => $settings['template_content_token'] ?? '{custom_content}',
        '#parents' => ['plugin_settings', 'template_content_token'],
      ];

      if (!empty($templateHtml)) {
        $form['template_wrapper']['template_html'] = [
          '#type' => 'text_format',
          '#title' => $this->t('Template HTML content'),
          '#description' => $this->t('The HTML of the selected Mautic template email. You can edit it here — this version will be used at publish time. Make sure it contains the placeholder token above where the AI-generated content should be injected.'),
          '#default_value' => $templateHtml,
          '#format' => 'full_html',
          '#parents' => ['plugin_settings', 'template_html'],
          '#rows' => 20,
        ];
      }
      else {
        $form['template_wrapper']['template_fetch_error'] = [
          '#type' => 'item',
          '#markup' => '<div class="messages messages--error">' . $this->t('Could not fetch template email (ID: @id). Verify the ID exists and the API connection is working.', ['@id' => $templateEmailId]) . '</div>',
        ];
      }
    }

    $templates = $this->apiClient->getTemplateEmails($credentials['connection_id'] ?? '');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateCredentials(array $credentials): bool {
    if (empty($credentials['connection_id'])) {
      return FALSE;
    }

    return $this->apiClient->validateConnection($credentials['connection_id']);
  }

  /**
   * {@inheritdoc}
   */
  public function publish(NodeInterface $node, array $fields, array $credentials, array $settings, string|int|null $toolId = NULL): PublishingResult {
    $connectionId = $credentials['connection_id'] ?? '';
    if (empty($connectionId)) {
      return PublishingResult::failure(
        'No Mautic API connection configured. Please select a connection in the platform credentials.',
        ['error' => 'missing_connection']
      );
    }

    // Extract content from structured fields.
    $subject = $fields['subject'] ?? '';
    if (empty($subject)) {
      return PublishingResult::failure(
        'No email subject line provided.',
        ['error' => 'empty_subject']
      );
    }

    $name = $fields['name'] ?? $subject;
    $htmlBody = $fields['html_body'] ?? '';
    if (empty($htmlBody)) {
      return PublishingResult::failure(
        'No email HTML body content provided.',
        ['error' => 'empty_html_body']
      );
    }

    $plainText = $fields['plain_text'] ?? '';
    $emailType = $settings['email_type'] ?? 'list';

    // Determine final HTML: either inject into a Mautic template or wrap in
    // a standalone responsive email document.
    $templateEmailId = !empty($settings['template_email_id']) ? (int) $settings['template_email_id'] : 0;
    if ($templateEmailId > 0) {
      // Template mode: use stored template HTML, falling back to API fetch.
      $templateHtml = '';
      if (!empty($settings['template_html'])) {
        $templateHtml = is_array($settings['template_html'])
          ? ($settings['template_html']['value'] ?? '')
          : (string) $settings['template_html'];
      }

      // Fallback: fetch from Mautic if no stored HTML.
      if (empty($templateHtml)) {
        $templateHtml = $this->apiClient->getEmailHtml($connectionId, $templateEmailId) ?? '';
      }

      if (empty($templateHtml)) {
        return PublishingResult::failure(
          'Failed to fetch the template email (ID: ' . $templateEmailId . ') from Mautic. Verify the template email ID exists and the API connection is working.',
          ['error' => 'template_fetch_failed', 'template_email_id' => $templateEmailId]
        );
      }

      $contentToken = $settings['template_content_token'] ?? '{custom_content}';
      if (str_contains($templateHtml, $contentToken)) {
        $htmlBody = str_replace($contentToken, $htmlBody, $templateHtml);
      }
      else {
        $this->apiClient->getLogger()->warning('Template email @id does not contain the placeholder token "@token". AI-generated content will be used in standalone mode as fallback.', [
          '@id' => $templateEmailId,
          '@token' => $contentToken,
        ]);
        // Fallback to standalone mode when placeholder is missing.
        $htmlBody = $this->buildStandaloneEmailHtml($htmlBody, $subject);
      }
    }
    else {
      // Standalone mode: wrap AI inner content in a full HTML email document.
      $htmlBody = $this->buildStandaloneEmailHtml($htmlBody, $subject);
    }

    // Build the email data payload for the Mautic API.
    $emailData = [
      'name' => $name,
      'subject' => $subject,
      'customHtml' => $htmlBody,
      'emailType' => $emailType,
      'isPublished' => TRUE,
    ];

    // Add plain text if available.
    if (!empty($plainText)) {
      $emailData['plainText'] = $plainText;
    }

    // Add from name/address if configured.
    if (!empty($settings['from_name'])) {
      $emailData['fromName'] = $settings['from_name'];
    }
    if (!empty($settings['from_address'])) {
      $emailData['fromAddress'] = $settings['from_address'];
    }
    if (!empty($settings['reply_to'])) {
      $emailData['replyToAddress'] = $settings['reply_to'];
    }

    // Add segment for list-type emails.
    $segmentId = !empty($settings['segment_id']) ? (int) $settings['segment_id'] : 0;
    if ($emailType === 'list' && $segmentId > 0) {
      $emailData['lists'] = [$segmentId];
    }

    // Step 1: Create the email in Mautic.
    $createResult = $this->apiClient->createEmail($connectionId, $emailData);

    if (!$createResult['success']) {
      return PublishingResult::failure(
        'Failed to create Mautic email: ' . ($createResult['error'] ?? 'Unknown error'),
        [
          'error' => $createResult['error'] ?? '',
          'response' => $createResult['response'] ?? '',
        ]
      );
    }

    $emailId = $createResult['email_id'];

    // Step 2: Optionally send the email immediately (segment/list emails only).
    $sendImmediately = $settings['send_immediately'] ?? FALSE;
    if ($sendImmediately && $emailType === 'list') {
      $sendResult = $this->apiClient->sendEmail($connectionId, $emailId);

      if (!$sendResult['success']) {
        return PublishingResult::failure(
          'Email created but failed to send: ' . ($sendResult['error'] ?? 'Unknown error') . '. The email is saved in Mautic for manual sending.',
          [
            'email_id' => $emailId,
            'error' => $sendResult['error'] ?? '',
          ]
        );
      }

      return PublishingResult::success(
        "Successfully created and sent Mautic email \"{$subject}\"" . ($segmentId > 0 ? " to segment {$segmentId}" : '') . '.',
        [
          'email_id' => $emailId,
          'subject' => $subject,
          'segment_id' => $segmentId,
          'status' => 'sent',
        ]
      );
    }

    $statusMsg = $emailType === 'template'
      ? "Successfully created Mautic template email \"{$subject}\". It can be used in campaigns or sent via API."
      : "Successfully created Mautic email \"{$subject}\". Log into Mautic to review and send it.";

    return PublishingResult::success(
      $statusMsg,
      [
        'email_id' => $emailId,
        'subject' => $subject,
        'segment_id' => $segmentId,
        'email_type' => $emailType,
        'status' => 'created',
      ]
    );
  }

  /**
   * Wraps AI-generated inner HTML content in a complete email document.
   *
   * Produces a responsive, email-client-compatible HTML document structure
   * with a centered content area. Used when no Mautic template is selected
   * (standalone mode).
   *
   * @param string $innerHtml
   *   The AI-generated inner HTML content.
   * @param string $subject
   *   The email subject line, used as the document title.
   *
   * @return string
   *   A complete HTML email document.
   */
  protected function buildStandaloneEmailHtml(string $innerHtml, string $subject): string {
    $escapedSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
  <title>{$escapedSubject}</title>
  <!--[if mso]>
  <noscript>
    <xml>
      <o:OfficeDocumentSettings>
        <o:PixelsPerInch>96</o:PixelsPerInch>
      </o:OfficeDocumentSettings>
    </xml>
  </noscript>
  <![endif]-->
  <style>
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
    body { margin: 0; padding: 0; width: 100% !important; height: 100% !important; }
    a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; font-size: inherit !important; font-family: inherit !important; font-weight: inherit !important; line-height: inherit !important; }
  </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4; font-family: Arial, Helvetica, sans-serif; -webkit-font-smoothing: antialiased;">
  <!-- Outer wrapper table for background color -->
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color: #f4f4f4;">
    <tr>
      <td align="center" style="padding: 20px 10px;">
        <!-- Inner content container -->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width: 600px; width: 100%; background-color: #ffffff; border-radius: 4px;">
          <tr>
            <td style="padding: 30px 40px; font-size: 16px; line-height: 1.6; color: #333333;">
              {$innerHtml}
            </td>
          </tr>
        </table>
        <!-- Footer area -->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width: 600px; width: 100%;">
          <tr>
            <td align="center" style="padding: 20px 40px; font-size: 12px; line-height: 1.5; color: #999999;">
              {unsubscribe_text} | {webview_text}
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
  }

}
