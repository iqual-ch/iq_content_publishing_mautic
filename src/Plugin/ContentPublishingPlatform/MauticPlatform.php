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
- Include a clear call-to-action linking to the original content.
- Keep paragraphs short and scannable.
- Maintain a professional yet engaging tone.
- Include the content URL as a "Read more" link.
- Do NOT include <html>, <head>, or <body> tags — only the inner content.
- You may use Mautic tokens like {contactfield=firstname} for personalization.
- Also generate a plain-text version without HTML markup.

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
  public function buildSettingsForm(array $form, array $settings): array {
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

}
