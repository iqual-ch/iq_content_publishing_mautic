# Content Publishing: Mautic

Provides Mautic email integration for the [Content Publishing](https://www.drupal.org/project/iq_content_publishing) framework. This module enables creating and sending Mautic email campaigns from Drupal node content, leveraging AI-generated newsletter content.

## Features

- **AI-Powered Newsletter Generation**: Automatically transforms Drupal node content into professional email newsletters with compelling subject lines, headers, and call-to-action elements.
- **Mautic API Integration**: Seamless connection to your Mautic instance via the [Mautic API](https://www.drupal.org/project/mautic_api) module.
- **Template Support**: Use existing Mautic emails as design templates with `{ai_content}` token placement for content injection.
- **Standalone Mode**: Automatically generates clean, responsive HTML email documents when no template is configured.
- **Segment Targeting**: Send emails to specific Mautic segments.
- **Flexible Publishing**: Create emails as drafts in Mautic or send them immediately upon publishing.

## Requirements

- Drupal 11
- [Content Publishing (iq_content_publishing)](https://www.drupal.org/project/iq_content_publishing) ^1.0
- [Mautic API (mautic_api)](https://www.drupal.org/project/mautic_api) ^2.0
- a Mautic instance with API Key set up.

## Installation

Install via Composer:

~~~bash
composer require iqual/iq_content_publishing_mautic
~~~

Enable the module:

~~~bash
drush en iq_content_publishing_mautic
~~~

## Configuration

### 1. Configure Mautic API Connection

Before using this module, you must configure a Mautic API connection:

1. Navigate to **Administration » Configuration » Web services » Mautic API** (`/admin/config/services/mautic/api`)
2. Add a new Mautic API connection with your Mautic instance URL and API credentials

### 2. Create a Publishing Platform Configuration

1. Navigate to **Administration » Configuration » Content Publishing » Platforms** (`/admin/config/content-publishing/platforms`)
2. Add a new platform configuration
3. Select **Mautic** as the platform type

### 3. Platform Credentials

- **Mautic API Connection**: Select the configured Mautic API connection

### 4. Platform Settings

| Setting | Description |
|---------|-------------|
| **HTML text format** | The text format for the email HTML body editor |
| **Segment** | The Mautic segment to send emails to |
| **From name** | Sender name (optional, uses Mautic default if empty) |
| **From email address** | Sender email address (optional) |
| **Reply-to email** | Reply-to address (optional) |
| **Email type** | Choose between "Segment (List) email" or "Template email" |
| **Send immediately** | When enabled, list emails are sent immediately upon creation |

### 5. Template Configuration (Optional)

To use an existing Mautic email as a design template:

1. Enter the **Template email ID** in the "Email layout template" section
2. The template HTML will be loaded and can be edited
3. Place a `{ai:*}` token where a AI-generated content should be inserted
4. Available tokens include: `{ai:title}`, `{ai:main_title}`, `{ai:subtitle}`, `{ai:summary}`, `{ai:cta_text}`, `{ai:cta_url}`

## Output Schema

The AI generates the following fields for each email:

| Field | Description |
|-------|-------------|
| `subject` | Email subject line (max 150 characters) |
| `name` | Internal email name for Mautic dashboard |
| `title` | Email title (used as main header depending on template) |
| `main_title` | Main title/header that grabs attention |
| `subtitle` | Secondary header providing additional context |
| `summary` | Brief teaser of the email content |
| `content` | Main content with markdown formatting support |
| `cta_text` | Call-to-action button text |
| `cta_url` | Call-to-action URL |
| `html_body` | The complete HTML body for the email |
| `plain_text` | Plain-text alternative for non-HTML clients |

## Usage

1. Navigate to a node you want to publish
2. Use the Content Publishing interface to select your Mautic platform configuration
3. Generate AI content based on the node
4. Review and edit the generated newsletter content
5. Publish to create/send the email in Mautic

## Permissions

This module uses the `administer content publishing` permission from the base Content Publishing module.

## Maintainers

- [iqual GmbH](https://www.drupal.org/iqual)

## License

GPL-2.0-or-later