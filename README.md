# Flagger Notify

Hi, I'm **Dao Duc Trung**.

I originally developed this module to solve a specific need for my website, **[ddt.one](https://ddt.one)**: notifying users via email when content they have "flagged" (bookmarked, subscribed, etc.) gets updated. While it was built for my personal use case, I believe it provides a solid foundation for anyone needing similar functionality in Drupal 11, so I'm releasing it as open source. Use it, fork it, or ignore it—it's up to you!

## Overview

**Flagger Notify** integrates with the [Flag](https://www.drupal.org/project/flag) module to send automated email notifications to users when a node they have flagged is updated.

### Key Features
*   **Queue-Based Processing**: Notifications are added to a queue and processed via Cron, ensuring performance is not impacted during content updates.
*   **Smart Debounce**: If a node is edited multiple times in a short period, only one notification is queued per cycle.
*   **Per-Flag Configuration**: Set different email templates for different flags (e.g., "Bookmark" vs "Follow").
*   **Translation Ready**: Fully translatable configuration for multilingual sites.
*   **Access Control**: Respects node permissions. If a user loses access to view a node (e.g., it is unpublished), they will silent skip receiving the notification.
*   **Debug Mode**: detailed logging of queue operations and email content (including raw HTML) for testing.

## Requirements

*   Drupal Core: **^11**
*   [Flag](https://www.drupal.org/project/flag) module
*   [Token](https://www.drupal.org/project/token) module
*   **User** module (Drupal Core) - Required for user notifications.

## Installation

1.  Place the module structure in your `modules/custom` directory.
2.  Enable via Drush:
    ```bash
    drush en flagger_notify
    ```
3.  Ensure dependencies are installed.

## Configuration

1.  Go to **Configuration > System > Flagger Notify** (`/admin/config/system/flagger-notify`).
2.  **Global Defaults**: Set a fallback email subject and body.
3.  **Flags**: Check the flags you want to enable. You can then override the email subject/body for that specific flag, or leave it empty to use the global default.
4.  **Tokens**: Use the Token browser to insert dynamic values (e.g., `[node:title]`, `[user:display-name]`, `[node:url:absolute]`).

## Credits & Acknowledgments

*   **Author**: [Dao Duc Trung](https://github.com/daoductrung)
*   **Co-Pilot**: Code written and refined with the assistance of **Antigravity** (Google DeepMind).

## License

**MIT License**