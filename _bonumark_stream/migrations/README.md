# Bonumark Stream migrations

Bonumark Stream v0.4 starts from a clean fresh-install baseline schema in `0001_initial_schema.php`. Older development migrations were intentionally collapsed for the public foundation reset. Future runtime migrations should start at `0002_...` and support v0.4.0 or newer installs only.

## Recovery behavior

MySQL and MariaDB implicitly commit DDL statements. Bonumark Stream therefore treats DDL migrations as resumable, not transactional. A migration is recorded only after all statements complete; a failed run can be retried because duplicate columns, indexes, and tables are handled as idempotent outcomes.
