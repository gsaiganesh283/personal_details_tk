-- Adds the `show_in_list` flag to custom_fields, matching the tkinter
-- desktop app's SQLite schema (app/db/schema.sql) and powering the "Shown in
-- List" column/checkbox on the redesigned Custom Fields admin screen.
--
-- Not required to run by hand: ensureCustomFieldShowInListColumn() in
-- includes/custom_fields_helper.php self-creates this column on the live
-- site's very next request, same pattern as migrate_field_reuse.sql /
-- notifications_helper.php's template_id column.

ALTER TABLE custom_fields ADD COLUMN show_in_list TINYINT(1) NOT NULL DEFAULT 0;
