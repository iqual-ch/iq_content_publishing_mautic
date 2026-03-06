<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing_mautic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface;
use Drupal\iq_content_publishing_mautic\Service\MauticApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for Mautic settings discovery.
 *
 * Provides routes to fetch available segments and emails
 * from the Mautic API to help administrators configure the platform.
 */
final class MauticSettingsController extends ControllerBase {

  /**
   * The Mautic API client.
   */
  protected MauticApiClient $apiClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->apiClient = $container->get('iq_content_publishing_mautic.api_client');
    return $instance;
  }

  /**
   * Fetches and displays available Mautic segments.
   *
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform_config
   *   The platform config entity.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the platform edit form with segments shown as messages.
   */
  public function fetchSegments(PublishingPlatformConfigInterface $platform_config): RedirectResponse {
    $credentials = $platform_config->getCredentials();
    $connectionId = $credentials['connection_id'] ?? '';

    if (empty($connectionId)) {
      $this->messenger()->addError($this->t('No Mautic API connection configured. Please save the platform configuration with a Mautic connection first.'));
      return new RedirectResponse(
        Url::fromRoute('entity.publishing_platform.collection')->toString()
      );
    }

    $segments = $this->apiClient->getSegments($connectionId);

    if (empty($segments)) {
      $this->messenger()->addWarning($this->t('No segments found in this Mautic instance.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Found @count segment(s):', [
        '@count' => count($segments),
      ]));

      foreach ($segments as $segment) {
        $name = $segment['name'] ?? 'N/A';
        $id = $segment['id'] ?? 'N/A';
        $alias = $segment['alias'] ?? '';
        $description = $segment['description'] ?? '';

        $info = "@name — ID: @id";
        $args = ['@name' => $name, '@id' => $id];

        if (!empty($alias)) {
          $info .= ' (alias: @alias)';
          $args['@alias'] = $alias;
        }
        if (!empty($description)) {
          $info .= ' — @description';
          $args['@description'] = $description;
        }

        $this->messenger()->addStatus($this->t($info, $args));
      }

      $this->messenger()->addStatus($this->t('Copy the desired segment ID into the "Segment ID" field in the platform settings.'));
    }

    return new RedirectResponse(
      Url::fromRoute('entity.publishing_platform.edit_form', [
        'publishing_platform' => $platform_config->id(),
      ])->toString()
    );
  }

  /**
   * Fetches and displays available Mautic template emails.
   *
   * Lists published template-type emails that can be used as layout wrappers
   * for AI-generated content.
   *
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform_config
   *   The platform config entity.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the platform edit form with templates shown as messages.
   */
  public function fetchTemplates(PublishingPlatformConfigInterface $platform_config): RedirectResponse {
    $credentials = $platform_config->getCredentials();
    $connectionId = $credentials['connection_id'] ?? '';

    if (empty($connectionId)) {
      $this->messenger()->addError($this->t('No Mautic API connection configured. Please save the platform configuration with a Mautic connection first.'));
      return new RedirectResponse(
        Url::fromRoute('entity.publishing_platform.collection')->toString()
      );
    }

    $templates = $this->apiClient->getTemplateEmails($connectionId);

    if (empty($templates)) {
      $this->messenger()->addWarning($this->t('No template emails found in this Mautic instance. Create a template-type email in Mautic with your desired layout and include the placeholder token (e.g., {custom_content}) where content should be injected.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Found @count template email(s). Copy the desired ID into the "Template email ID" field:', [
        '@count' => count($templates),
      ]));

      foreach ($templates as $template) {
        $name = $template['name'] ?? 'N/A';
        $id = $template['id'] ?? 'N/A';
        $subject = $template['subject'] ?? '';
        $published = !empty($template['isPublished']) ? 'Published' : 'Draft';
        $hasHtml = !empty($template['customHtml']) ? 'Has HTML' : 'No HTML';

        $info = '[@status | @html] @name — ID: @id';
        $args = [
          '@status' => $published,
          '@html' => $hasHtml,
          '@name' => $name,
          '@id' => $id,
        ];

        if (!empty($subject)) {
          $info .= ' (Subject: @subject)';
          $args['@subject'] = $subject;
        }

        $this->messenger()->addStatus($this->t($info, $args));
      }
    }

    return new RedirectResponse(
      Url::fromRoute('entity.publishing_platform.edit_form', [
        'publishing_platform' => $platform_config->id(),
      ])->toString()
    );
  }

  /**
   * Fetches and displays existing Mautic emails.
   *
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform_config
   *   The platform config entity.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the platform edit form with emails shown as messages.
   */
  public function fetchEmails(PublishingPlatformConfigInterface $platform_config): RedirectResponse {
    $credentials = $platform_config->getCredentials();
    $connectionId = $credentials['connection_id'] ?? '';

    if (empty($connectionId)) {
      $this->messenger()->addError($this->t('No Mautic API connection configured. Please save the platform configuration with a Mautic connection first.'));
      return new RedirectResponse(
        Url::fromRoute('entity.publishing_platform.collection')->toString()
      );
    }

    $emails = $this->apiClient->getEmails($connectionId);

    if (empty($emails)) {
      $this->messenger()->addWarning($this->t('No emails found in this Mautic instance.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Found @count email(s):', [
        '@count' => count($emails),
      ]));

      foreach ($emails as $email) {
        $name = $email['name'] ?? 'N/A';
        $id = $email['id'] ?? 'N/A';
        $subject = $email['subject'] ?? '';
        $emailType = $email['emailType'] ?? '';
        $published = !empty($email['isPublished']) ? 'Published' : 'Draft';

        $this->messenger()->addStatus($this->t('[@type | @status] @name — ID: @id (Subject: @subject)', [
          '@type' => $emailType,
          '@status' => $published,
          '@name' => $name,
          '@id' => $id,
          '@subject' => $subject,
        ]));
      }
    }

    return new RedirectResponse(
      Url::fromRoute('entity.publishing_platform.edit_form', [
        'publishing_platform' => $platform_config->id(),
      ])->toString()
    );
  }

}
