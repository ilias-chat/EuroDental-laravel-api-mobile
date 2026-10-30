# Task comments and notifications

The PWA now uses this API to read and post task comments stored in the shared EuroDental database. Install `task_comments` once using the migration or `database/sql/task_comments.sql` in the laravel-api project. No additional notification table is required.

Authenticated endpoints:

- `GET /api/tasks/{task}/comments?before_id=123`: 20 comments, newest first, cursor and `can_post`.
- `POST /api/tasks/{task}/comments` with `body`: plain text, trimmed, max 5000 characters, 30 posts/minute.
- `POST /api/tasks/{task}/comments/read` with `through_id`: read receipt for displayed comments only.

Participants (main technician and helpers) may read and post. Unassigned users need `tasks_view_all` to read and both `tasks_view_all` and `tasks_write` to post. Merely creating a task does not grant comment access.

Comments posted through either API notify the main technician and helpers except the author. Unread comments are grouped per task/recipient in the existing `notifications` table. The PWA and CRM see the same read state. Unassigned managers are not automatically notified.

Browser push is sent after commit, deduplicated across CRM/PWA endpoint tables, with the correct Comments link for each app. Both APIs must use the same existing `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` and `VAPID_SUBJECT`; do not regenerate working keys. HTTPS and browser permission are required for push. In-app notifications work without push. No email notifications are sent. No changes are made to the separate angular-mobile Android app.

Deploy this API before the updated angular-pwa frontend. Full behavior and deployment notes are in `laravel-api/docs/task-comments.md` in the parent EuroDental workspace.

Run isolated tests: `php vendor/bin/phpunit tests/Feature/TaskCommentsTest.php`.
