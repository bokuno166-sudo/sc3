Migration instructions
======================

This folder contains SQL migration files to update the database schema.

001_add_weeks_of_pregnancy.sql
- Adds the `weeks_of_pregnancy` INT column to the `patients` table.

How to apply
-----------
Use one of the following methods to apply the migration to your MySQL database:

1) Using MySQL CLI (recommended):

```bash
mysql -u <user> -p <database_name> < config/migrations/001_add_weeks_of_pregnancy.sql
```

2) Using phpMyAdmin:
- Open phpMyAdmin, select your database, go to SQL tab, paste the contents of the SQL file and run.

3) Run the ALTER directly (example):

```sql
ALTER TABLE patients ADD COLUMN weeks_of_pregnancy INT DEFAULT NULL;
```

After applying
-------------
- Verify the column exists:

```sql
DESCRIBE patients;
```

- Test editing a patient in `modules/reception/patient-edit.php` to ensure the update succeeds.
