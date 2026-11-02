-- Drop the WhatsApp reminder tables from the production database.
--
-- This repo's WhatsApp feature (Twilio-based reminders, reminder templates,
-- and the reminder-type custom field) has been removed from the codebase.
-- This script has NOT been run automatically -- this environment has no
-- network path to the hosted MySQL server (sql206.ezyro.com). Run it
-- yourself against that database, e.g. via phpMyAdmin's SQL tab or:
--
--   mysql -h sql206.ezyro.com -u ezyro_41197723 -p ezyro_41197723_psd < sql/drop_whatsapp_tables.sql
--
-- This is destructive and irreversible -- back up the database first if you
-- want to keep the existing reminder/template data.

DROP TABLE IF EXISTS `reminders`;
DROP TABLE IF EXISTS `reminder_templates`;
DROP TABLE IF EXISTS `whatsapp_config`;
