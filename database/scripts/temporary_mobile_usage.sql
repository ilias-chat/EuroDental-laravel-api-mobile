-- MySQL / MariaDB: run manually on the database used by laravel-mobile.
-- Independent table; no changes to users or authentication tables.
CREATE TABLE IF NOT EXISTS temporary_mobile_usage (
    user_id BIGINT UNSIGNED NOT NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    KEY temporary_mobile_usage_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Report: each observed user of the new mobile API, most recent first.
SELECT u.id, u.first_name, u.last_name, t.first_seen_at, t.last_seen_at
FROM temporary_mobile_usage AS t
INNER JOIN users AS u ON u.id = t.user_id
ORDER BY t.last_seen_at DESC;

-- Removal after the observation period:
-- 1. Remove 'temporary.mobile.usage' from the routes/api.php middleware group.
-- 2. Remove its alias in bootstrap/app.php and TrackTemporaryMobileUsage.php.
-- 3. Clear/rebuild the deployed route cache, then optionally run:
-- DROP TABLE IF EXISTS temporary_mobile_usage;
-- Timestamps use the Laravel application's timezone.
