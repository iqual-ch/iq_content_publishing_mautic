<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing_mautic\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\mautic_api\Entity\MauticApiConnectionInterface;
use Drupal\mautic_api\MauticApiConnector;
use Psr\Log\LoggerInterface;

/**
 * Client for the Mautic API.
 *
 * Wraps the mautic_api.connector service to handle email creation,
 * sending, and segment listing for the Content Publishing framework.
 */
final class MauticApiClient {

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a MauticApiClient.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\mautic_api\MauticApiConnector $mauticConnector
   *   The Mautic API connector service.
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MauticApiConnector $mauticConnector,
  ) {
    $this->logger = $loggerFactory->get('iq_content_publishing_mautic');
  }

  /**
   * Gets the logger instance.
   *
   * @return \Psr\Log\LoggerInterface
   *   The logger.
   */
  public function getLogger(): LoggerInterface {
    return $this->logger;
  }

  /**
   * Loads a Mautic API connection entity.
   *
   * @param string $connectionId
   *   The machine name of the Mautic API connection entity.
   *
   * @return \Drupal\mautic_api\Entity\MauticApiConnectionInterface|null
   *   The connection entity, or NULL if not found.
   */
  protected function loadConnection(string $connectionId): ?MauticApiConnectionInterface {
    try {
      $connection = $this->entityTypeManager
        ->getStorage('mautic_api_connection')
        ->load($connectionId);

      if ($connection instanceof MauticApiConnectionInterface) {
        return $connection;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to load Mautic API connection "@id": @message', [
        '@id' => $connectionId,
        '@message' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Gets a Mautic API context for a given connection and context type.
   *
   * @param string $connectionId
   *   The Mautic API connection entity ID.
   * @param string $context
   *   The API context (e.g., 'emails', 'segments', 'contacts').
   *
   * @return \Mautic\Api\Api|null
   *   The Mautic API context object, or NULL on failure.
   */
  protected function getApiContext(string $connectionId, string $context): ?\Mautic\Api\Api {
    $connection = $this->loadConnection($connectionId);
    if (!$connection) {
      $this->logger->error('Mautic API connection "@id" not found.', [
        '@id' => $connectionId,
      ]);
      return NULL;
    }

    try {
      return $this->mauticConnector->getApi($connection, $context);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get Mautic API context "@context" for connection "@id": @message', [
        '@context' => $context,
        '@id' => $connectionId,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Validates a Mautic API connection by making a test request.
   *
   * @param string $connectionId
   *   The machine name of the Mautic API connection entity.
   *
   * @return bool
   *   TRUE if the connection is valid and working.
   */
  public function validateConnection(string $connectionId): bool {
    $connection = $this->loadConnection($connectionId);
    if (!$connection) {
      return FALSE;
    }

    try {
      $status = $this->mauticConnector->getStatus($connection);
      return !empty($status);
    }
    catch (\Exception $e) {
      $this->logger->warning('Mautic connection validation failed for "@id": @message', [
        '@id' => $connectionId,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Creates a new email in Mautic.
   *
   * @param string $connectionId
   *   The Mautic API connection entity ID.
   * @param array $emailData
   *   The email data array with keys: name, subject, customHtml, emailType,
   *   lists, fromName, fromAddress, replyToAddress, plainText, isPublished.
   *
   * @return array
   *   Result array with 'success' boolean and 'email_id'/'error' keys.
   */
  public function createEmail(string $connectionId, array $emailData): array {
    $api = $this->getApiContext($connectionId, 'emails');
    if (!$api) {
      return [
        'success' => FALSE,
        'error' => 'Failed to initialize Mautic Emails API context.',
      ];
    }

    try {
      $response = $api->create($emailData);

      if (!empty($response['errors'])) {
        $errorMessage = $this->extractErrorMessage($response);
        $this->logger->error('Mautic email creation failed: @message', [
          '@message' => $errorMessage,
        ]);
        return [
          'success' => FALSE,
          'error' => $errorMessage,
          'response' => json_encode($response),
        ];
      }

      $email = $response[$api->itemName()] ?? $response;
      $emailId = $email['id'] ?? NULL;

      if (!$emailId) {
        return [
          'success' => FALSE,
          'error' => 'No email ID returned in response.',
          'response' => json_encode($response),
        ];
      }

      return [
        'success' => TRUE,
        'email_id' => (int) $emailId,
        'email' => $email,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create Mautic email: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Sends a Mautic segment/list email to its assigned segments.
   *
   * @param string $connectionId
   *   The Mautic API connection entity ID.
   * @param int $emailId
   *   The Mautic email ID.
   *
   * @return array
   *   Result array with 'success' boolean and optional 'error' key.
   */
  public function sendEmail(string $connectionId, int $emailId): array {
    $api = $this->getApiContext($connectionId, 'emails');
    if (!$api) {
      return [
        'success' => FALSE,
        'error' => 'Failed to initialize Mautic Emails API context.',
      ];
    }

    try {
      /** @var \Mautic\Api\Emails $api */
      $response = $api->send($emailId);

      if (!empty($response['errors'])) {
        $errorMessage = $this->extractErrorMessage($response);
        $this->logger->error('Failed to send Mautic email @id: @message', [
          '@id' => $emailId,
          '@message' => $errorMessage,
        ]);
        return [
          'success' => FALSE,
          'error' => $errorMessage,
        ];
      }

      return [
        'success' => TRUE,
        'sent_count' => $response['sentCount'] ?? 0,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to send Mautic email @id: @message', [
        '@id' => $emailId,
        '@message' => $e->getMessage(),
      ]);
      return [
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Sends a Mautic email to a specific contact.
   *
   * @param string $connectionId
   *   The Mautic API connection entity ID.
   * @param int $emailId
   *   The Mautic email ID.
   * @param int $contactId
   *   The Mautic contact ID.
   *
   * @return array
   *   Result array with 'success' boolean and optional 'error' key.
   */
  public function sendToContact(string $connectionId, int $emailId, int $contactId): array {
    $api = $this->getApiContext($connectionId, 'emails');
    if (!$api) {
      return [
        'success' => FALSE,
        'error' => 'Failed to initialize Mautic Emails API context.',
      ];
    }

    try {
      /** @var \Mautic\Api\Emails $api */
      $response = $api->sendToContact($emailId, $contactId);

      if (!empty($response['errors'])) {
        $errorMessage = $this->extractErrorMessage($response);
        return [
          'success' => FALSE,
          'error' => $errorMessage,
        ];
      }

      return ['success' => TRUE];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to send Mautic email @eid to contact @cid: @message', [
        '@eid' => $emailId,
        '@cid' => $contactId,
        '@message' => $e->getMessage(),
      ]);
      return [
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Gets an email's details from Mautic.
   *
   * @param string $connectionId
   *   The Mautic API connection entity ID.
   * @param int $emailId
   *   The Mautic email ID.
   *
   * @return array|null
   *   The email data array, or NULL on failure.
   */
  public function getEmail(string $connectionId, int $emailId): ?array {
    $api = $this->getApiContext($connectionId, 'emails');
    if (!$api) {
      return NULL;
    }

    try {
      $response = $api->get($emailId);

      if (!empty($response['errors'])) {
        return NULL;
      }

      return $response[$api->itemName()] ?? NULL;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to retrieve Mautic email @id: @message', [
        '@id' => $emailId,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Deletes a Mautic email.
   *
   * @param string $connectionId
   *   The Mautic API connection entity ID.
   * @param int $emailId
   *   The Mautic email ID.
   *
   * @return bool
   *   TRUE if the email was deleted successfully.
   */
  public function deleteEmail(string $connectionId, int $emailId): bool {
    $api = $this->getApiContext($connectionId, 'emails');
    if (!$api) {
      return FALSE;
    }

    try {
      $response = $api->delete($emailId);
      return empty($response['errors']);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete Mautic email @id: @message', [
        '@id' => $emailId,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Retrieves the list of segments from Mautic.
   *
   * @param string $connectionId
   *   The Mautic API connection entity ID.
   *
   * @return array
   *   Array of segments, each with keys: id, name, alias, description.
   */
  public function getSegments(string $connectionId): array {
    $api = $this->getApiContext($connectionId, 'segments');
    if (!$api) {
      return [];
    }

    try {
      $response = $api->getList('', 0, 100);
      $segments = [];

      $list = $response[$api->listName()] ?? $response['lists'] ?? [];
      foreach ($list as $segment) {
        $segments[] = [
          'id' => $segment['id'] ?? '',
          'name' => $segment['name'] ?? '',
          'alias' => $segment['alias'] ?? '',
          'description' => $segment['description'] ?? '',
          'isGlobal' => $segment['isGlobal'] ?? FALSE,
        ];
      }

      return $segments;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch Mautic segments: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Retrieves the list of template-type emails from Mautic.
   *
   * Template emails can be used as layout wrappers for AI-generated content.
   * They are expected to contain a content placeholder token that will be
   * replaced with the generated HTML body.
   *
   * @param string $connectionId
   *   The Mautic API connection entity ID.
   *
   * @return array
   *   Array of template emails, each with keys: id, name, subject, customHtml.
   */
  public function getTemplateEmails(string $connectionId): array {
    $api = $this->getApiContext($connectionId, 'emails');
    if (!$api) {
      return [];
    }

    try {
      $response = $api->getList('', 0, 200, 'email:name:ASC', [
        'where' => [
          [
            'col' => 'emailType',
            'expr' => 'eq',
            'val' => 'template',
          ],
        ],
      ]);
      $emails = [];

      $list = $response[$api->listName()] ?? $response['emails'] ?? [];
      foreach ($list as $email) {
        $emails[] = [
          'id' => $email['id'] ?? '',
          'name' => $email['name'] ?? '',
          'subject' => $email['subject'] ?? '',
          'customHtml' => $email['customHtml'] ?? '',
          'isPublished' => $email['isPublished'] ?? FALSE,
        ];
      }

      return $emails;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch Mautic template emails: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Fetches the HTML content of a specific Mautic email.
   *
   * @param string $connectionId
   *   The Mautic API connection entity ID.
   * @param int $emailId
   *   The Mautic email ID.
   *
   * @return string|null
   *   The email's customHtml content, or NULL on failure.
   */
  public function getEmailHtml(string $connectionId, int $emailId): ?string {
    $email = $this->getEmail($connectionId, $emailId);
    if (!$email) {
      return NULL;
    }

    return $email['customHtml'] ?? NULL;
  }

  /**
   * Retrieves the list of emails from Mautic.
   *
   * @param string $connectionId
   *   The Mautic API connection entity ID.
   *
   * @return array
   *   Array of emails, each with keys: id, name, subject, emailType.
   */
  public function getEmails(string $connectionId): array {
    $api = $this->getApiContext($connectionId, 'emails');
    if (!$api) {
      return [];
    }

    try {
      $response = $api->getList('', 0, 100);
      $emails = [];

      $list = $response[$api->listName()] ?? $response['emails'] ?? [];
      foreach ($list as $email) {
        $emails[] = [
          'id' => $email['id'] ?? '',
          'name' => $email['name'] ?? '',
          'subject' => $email['subject'] ?? '',
          'emailType' => $email['emailType'] ?? '',
          'isPublished' => $email['isPublished'] ?? FALSE,
        ];
      }

      return $emails;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch Mautic emails: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Extracts a human-readable error message from a Mautic API response.
   *
   * @param array $response
   *   The API response array.
   *
   * @return string
   *   The extracted error message.
   */
  protected function extractErrorMessage(array $response): string {
    if (empty($response['errors'])) {
      return 'Unknown error';
    }

    $messages = [];
    foreach ($response['errors'] as $error) {
      if (is_array($error)) {
        $messages[] = ($error['message'] ?? '') . (isset($error['details']) ? ' — ' . json_encode($error['details']) : '');
      }
      else {
        $messages[] = (string) $error;
      }
    }

    return implode('; ', array_filter($messages)) ?: 'Unknown error';
  }

}
