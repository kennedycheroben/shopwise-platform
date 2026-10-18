# Database migrations

Every schema change requires a numbered migration in this directory and the
same final-state change in `../schema.sql`. Use names such as
`001_add_admin_notification_fields.sql`. Test migrations on a copy of the
database before deployment; production imports use `schema.sql` only for a new
installation.
