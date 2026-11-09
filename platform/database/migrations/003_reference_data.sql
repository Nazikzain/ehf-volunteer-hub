-- EHF Volunteer Hub - migration 003: reference data (no personal data)
INSERT INTO roles (id, code, name, created_at) VALUES
  (1, 'organisation_admin', 'Organisation Admin', UTC_TIMESTAMP()),
  (2, 'project_supervisor', 'Project Supervisor', UTC_TIMESTAMP()),
  (3, 'volunteer', 'Volunteer', UTC_TIMESTAMP());

-- The ten "Skills or area of expertise" options of the existing public form (verified, Phase A).
INSERT INTO skills (name, category, is_active, created_at) VALUES
  ('Community Engagement', 'Community', 1, UTC_TIMESTAMP()),
  ('Health and Wellbeing', 'Health', 1, UTC_TIMESTAMP()),
  ('Administration', 'Operations', 1, UTC_TIMESTAMP()),
  ('IT & Digital Skills', 'Digital', 1, UTC_TIMESTAMP()),
  ('Data Analysis', 'Digital', 1, UTC_TIMESTAMP()),
  ('Marketing & Communications', 'Communications', 1, UTC_TIMESTAMP()),
  ('Events & Activities', 'Community', 1, UTC_TIMESTAMP()),
  ('Translation & Languages', 'Community', 1, UTC_TIMESTAMP()),
  ('Project Management', 'Operations', 1, UTC_TIMESTAMP()),
  ('Other', 'Other', 1, UTC_TIMESTAMP());

-- Conservative defaults pending EHF decisions (Design §12). Shortest defensible periods.
INSERT INTO settings (`key`, value, updated_by, updated_at) VALUES
  ('org.name', 'Ethnic Health Forum', NULL, UTC_TIMESTAMP()),
  ('org.charity_number', '1123223', NULL, UTC_TIMESTAMP()),
  ('consent.version', '2026-11-v1', NULL, UTC_TIMESTAMP()),
  ('consent.text', 'I agree that Ethnic Health Forum may use the information I provide to review my volunteer application and contact me about volunteering, in accordance with its privacy notice.', NULL, UTC_TIMESTAMP()),
  ('privacy.notice_url', 'https://www.ethnichealth.org.uk/', NULL, UTC_TIMESTAMP()),
  ('retention.rejected_applications_months', '12', NULL, UTC_TIMESTAMP()),
  ('retention.notifications_months', '12', NULL, UTC_TIMESTAMP()),
  ('retention.fallback_files_days', '90', NULL, UTC_TIMESTAMP()),
  ('timelogs.late_submission_days', '30', NULL, UTC_TIMESTAMP()),
  ('timelogs.general_duplicate_policy', 'warn', NULL, UTC_TIMESTAMP());
