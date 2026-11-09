-- EHF Volunteer Hub - migration 001: Revision 3 schema (34 tables)
-- Source of truth: EHF_Database_Design_Review_Rev3.md section 5.
-- Requires MySQL >= 8.0.16 or MariaDB >= 10.6 (CHECK constraints enforced).
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- A1 users (PD-High): Login accounts for admins, supervisors and volunteers.
CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(254) NOT NULL,
  `password_hash` VARCHAR(255) NULL,
  `status` ENUM('invited','active','deactivated') NOT NULL DEFAULT 'invited',
  `email_verified_at` DATETIME NULL,
  `session_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_login_at` DATETIME NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `deactivated_at` DATETIME NULL,
  `anonymised_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_1` (`email`),
  CONSTRAINT `ck_users_1` CHECK (status <> 'active' OR password_hash IS NOT NULL),
  CONSTRAINT `fk_users_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A2 roles (PD-None): The three grantable roles. Primary Admin is not a role.
CREATE TABLE `roles` (
  `id` SMALLINT UNSIGNED NOT NULL,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_1` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A3 user_roles (PD-None): Current role grants. Grant/revoke history is in audit_logs.
CREATE TABLE `user_roles` (
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role_id` SMALLINT UNSIGNED NOT NULL,
  `granted_by` BIGINT UNSIGNED NULL,
  `granted_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`, `role_id`),
  KEY `ix_user_roles_1` (`role_id`),
  CONSTRAINT `fk_user_roles_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_user_roles_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_user_roles_3` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A4 system_owner (PD-None): The single Primary Admin. Grants all permissions by itself.
CREATE TABLE `system_owner` (
  `singleton` ENUM('owner') NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `since` DATETIME NOT NULL,
  `transferred_by` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`singleton`),
  UNIQUE KEY `uq_system_owner_1` (`user_id`),
  CONSTRAINT `fk_system_owner_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_system_owner_2` FOREIGN KEY (`transferred_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A5 auth_tokens (PD-None): Activation and password-reset tokens (hash only).
CREATE TABLE `auth_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `purpose` ENUM('activation','password_reset') NOT NULL,
  `token_hash` BINARY(32) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `revoked_at` DATETIME NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_tokens_1` (`token_hash`),
  KEY `ix_auth_tokens_1` (`user_id`, `purpose`),
  CONSTRAINT `fk_auth_tokens_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_auth_tokens_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A6 rate_limit_hits (PD-None): Rate-limiting counters for login, reset, registration and activation.
CREATE TABLE `rate_limit_hits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` ENUM('login','password_reset','registration','activation') NOT NULL,
  `key_hash` BINARY(32) NOT NULL,
  `occurred_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_rate_limit_hits_1` (`action`, `key_hash`, `occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- C1 volunteers (PD-High): The single operational record per volunteer.
CREATE TABLE `volunteers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(254) NOT NULL,
  `active_email` VARCHAR(254) GENERATED ALWAYS AS (CASE WHEN `deleted_at` IS NULL THEN `email` END) STORED,
  `phone` VARCHAR(40) NULL,
  `postcode` VARCHAR(12) NULL,
  `preferred_language` VARCHAR(35) NULL,
  `status` ENUM('approved','active','inactive','completed','withdrawn') NOT NULL DEFAULT 'approved',
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `deleted_at` DATETIME NULL,
  `anonymised_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_volunteers_1` (`user_id`),
  UNIQUE KEY `uq_volunteers_2` (`active_email`),
  KEY `ix_volunteers_1` (`status`),
  KEY `ix_volunteers_2` (`full_name`),
  CONSTRAINT `ck_volunteers_1` CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date),
  CONSTRAINT `fk_volunteers_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B1 volunteer_applications (PD-High): Public applications. Every current form field is preserved.
CREATE TABLE `volunteer_applications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference` CHAR(10) NOT NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(254) NOT NULL,
  `phone` VARCHAR(40) NULL,
  `postcode` VARCHAR(12) NULL,
  `availability_type` ENUM('specific_days','flexible') NOT NULL,
  `motivation` TEXT NULL,
  `referral_source` VARCHAR(40) NULL,
  `privacy_consent` TINYINT(1) NOT NULL,
  `consent_version` VARCHAR(20) NOT NULL,
  `consent_at` DATETIME NOT NULL,
  `status` ENUM('pending','under_review','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending',
  `volunteer_id` BIGINT UNSIGNED NULL,
  `reviewed_by` BIGINT UNSIGNED NULL,
  `reviewed_at` DATETIME NULL,
  `review_notes` TEXT NULL,
  `submitted_at` DATETIME NOT NULL,
  `open_email` VARCHAR(254) GENERATED ALWAYS AS (CASE WHEN `status` IN ('pending','under_review') THEN `email` END) STORED,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_volunteer_applications_1` (`reference`),
  UNIQUE KEY `uq_volunteer_applications_2` (`open_email`),
  KEY `ix_volunteer_applications_1` (`status`, `submitted_at`),
  KEY `ix_volunteer_applications_2` (`email`),
  CONSTRAINT `ck_volunteer_applications_1` CHECK (privacy_consent = 1),
  CONSTRAINT `ck_volunteer_applications_2` CHECK (status <> 'approved' OR volunteer_id IS NOT NULL),
  CONSTRAINT `ck_volunteer_applications_3` CHECK (status IN ('pending','withdrawn') OR reviewed_by IS NOT NULL),
  CONSTRAINT `fk_volunteer_applications_1` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_volunteer_applications_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B2 application_available_days (PD-Med): Days chosen when availability is 'specific_days'.
CREATE TABLE `application_available_days` (
  `application_id` BIGINT UNSIGNED NOT NULL,
  `weekday` TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (`application_id`, `weekday`),
  CONSTRAINT `ck_application_available_days_1` CHECK (weekday BETWEEN 1 AND 7),
  CONSTRAINT `fk_application_available_days_1` FOREIGN KEY (`application_id`) REFERENCES `volunteer_applications` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- C4 skills (PD-None): Skill catalogue, seeded with the 10 registration-form options.
CREATE TABLE `skills` (
  `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `category` VARCHAR(60) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_skills_1` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B3 application_skills (PD-Med): Skills declared on the application.
CREATE TABLE `application_skills` (
  `application_id` BIGINT UNSIGNED NOT NULL,
  `skill_id` SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (`application_id`, `skill_id`),
  KEY `ix_application_skills_1` (`skill_id`),
  CONSTRAINT `fk_application_skills_1` FOREIGN KEY (`application_id`) REFERENCES `volunteer_applications` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_application_skills_2` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B4 application_status_history (PD-Med): Review trail shown to reviewers; lives and dies with the application.
CREATE TABLE `application_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` BIGINT UNSIGNED NOT NULL,
  `from_status` ENUM('pending','under_review','approved','rejected','withdrawn') NULL,
  `to_status` ENUM('pending','under_review','approved','rejected','withdrawn') NOT NULL,
  `changed_by` BIGINT UNSIGNED NULL,
  `changed_at` DATETIME NOT NULL,
  `note` VARCHAR(500) NULL,
  PRIMARY KEY (`id`),
  KEY `ix_application_status_history_1` (`application_id`, `changed_at`),
  CONSTRAINT `fk_application_status_history_1` FOREIGN KEY (`application_id`) REFERENCES `volunteer_applications` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_application_status_history_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- C2 volunteer_status_history (PD-Med): Source for 'active volunteers on date X' and engagement trends.
CREATE TABLE `volunteer_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `volunteer_id` BIGINT UNSIGNED NOT NULL,
  `from_status` ENUM('approved','active','inactive','completed','withdrawn') NULL,
  `to_status` ENUM('approved','active','inactive','completed','withdrawn') NOT NULL,
  `changed_by` BIGINT UNSIGNED NULL,
  `changed_at` DATETIME NOT NULL,
  `reason` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `ix_volunteer_status_history_1` (`volunteer_id`, `changed_at`),
  KEY `ix_volunteer_status_history_2` (`to_status`, `changed_at`),
  CONSTRAINT `fk_volunteer_status_history_1` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_volunteer_status_history_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D1 projects (PD-None): Projects.
CREATE TABLE `projects` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT NULL,
  `status` ENUM('planned','active','on_hold','completed','cancelled') NOT NULL DEFAULT 'planned',
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `deleted_at` DATETIME NULL,
  `legacy_uuid` CHAR(36) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_projects_1` (`legacy_uuid`),
  KEY `ix_projects_1` (`status`),
  KEY `ix_projects_2` (`name`),
  CONSTRAINT `ck_projects_1` CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date),
  CONSTRAINT `fk_projects_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- C3 volunteer_notes (PD-High): Staff notes about a volunteer (spec §11.3). Never shown to volunteers [D].
CREATE TABLE `volunteer_notes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `volunteer_id` BIGINT UNSIGNED NOT NULL,
  `author_user_id` BIGINT UNSIGNED NOT NULL,
  `project_id` BIGINT UNSIGNED NULL,
  `visibility` ENUM('admins','project') NOT NULL,
  `body` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_volunteer_notes_1` (`volunteer_id`, `created_at`),
  CONSTRAINT `ck_volunteer_notes_1` CHECK (visibility <> 'project' OR project_id IS NOT NULL),
  CONSTRAINT `fk_volunteer_notes_1` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_volunteer_notes_2` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_volunteer_notes_3` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- C5 volunteer_skills (PD-Med): Volunteer competencies.
CREATE TABLE `volunteer_skills` (
  `volunteer_id` BIGINT UNSIGNED NOT NULL,
  `skill_id` SMALLINT UNSIGNED NOT NULL,
  `proficiency` ENUM('beginner','intermediate','advanced') NULL,
  `source` ENUM('application','self','admin') NOT NULL,
  `verified_by` BIGINT UNSIGNED NULL,
  `verified_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`volunteer_id`, `skill_id`),
  KEY `ix_volunteer_skills_1` (`skill_id`),
  CONSTRAINT `fk_volunteer_skills_1` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_volunteer_skills_2` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_volunteer_skills_3` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- C6 volunteer_availability (PD-Med): Weekly availability maintained by the volunteer.
CREATE TABLE `volunteer_availability` (
  `volunteer_id` BIGINT UNSIGNED NOT NULL,
  `weekday` TINYINT UNSIGNED NOT NULL,
  `period` ENUM('morning','afternoon','evening','any') NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`volunteer_id`, `weekday`, `period`),
  CONSTRAINT `ck_volunteer_availability_1` CHECK (weekday BETWEEN 1 AND 7),
  CONSTRAINT `fk_volunteer_availability_1` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- C7 training_types (PD-None): Training catalogue supplied by EHF [D].
CREATE TABLE `training_types` (
  `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `validity_months` SMALLINT UNSIGNED NULL,
  `is_mandatory` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_training_types_1` (`name`),
  CONSTRAINT `ck_training_types_1` CHECK (validity_months IS NULL OR validity_months > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- C8 volunteer_training (PD-Med): Training records. A renewal adds a new row.
CREATE TABLE `volunteer_training` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `volunteer_id` BIGINT UNSIGNED NOT NULL,
  `training_type_id` SMALLINT UNSIGNED NOT NULL,
  `status` ENUM('not_started','in_progress','completed','waived') NOT NULL,
  `completed_on` DATE NULL,
  `expires_on` DATE NULL,
  `recorded_by` BIGINT UNSIGNED NOT NULL,
  `verified_by` BIGINT UNSIGNED NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_volunteer_training_1` (`volunteer_id`, `training_type_id`, `completed_on`),
  KEY `ix_volunteer_training_2` (`expires_on`),
  CONSTRAINT `ck_volunteer_training_1` CHECK (status <> 'completed' OR completed_on IS NOT NULL),
  CONSTRAINT `ck_volunteer_training_2` CHECK (expires_on IS NULL OR completed_on IS NULL OR expires_on > completed_on),
  CONSTRAINT `fk_volunteer_training_1` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_volunteer_training_2` FOREIGN KEY (`training_type_id`) REFERENCES `training_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_volunteer_training_3` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_volunteer_training_4` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D2 project_supervisors (PD-None): Grants supervisor access project by project. Rows are ended, never deleted.
CREATE TABLE `project_supervisors` (
  `project_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `assigned_by` BIGINT UNSIGNED NOT NULL,
  `assigned_at` DATETIME NOT NULL,
  `ended_at` DATETIME NULL,
  `ended_by` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`project_id`, `user_id`),
  KEY `ix_project_supervisors_1` (`user_id`),
  CONSTRAINT `ck_project_supervisors_1` CHECK (ended_at IS NULL OR ended_by IS NOT NULL),
  CONSTRAINT `fk_project_supervisors_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_project_supervisors_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_project_supervisors_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_project_supervisors_4` FOREIGN KEY (`ended_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D3 project_assignments (PD-Med): One row per period of service of a volunteer on a project.
CREATE TABLE `project_assignments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `volunteer_id` BIGINT UNSIGNED NOT NULL,
  `role_description` VARCHAR(255) NULL,
  `status` ENUM('active','completed','withdrawn') NOT NULL DEFAULT 'active',
  `start_date` DATE NOT NULL,
  `end_date` DATE NULL,
  `active_flag` TINYINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `status` = 'active' THEN 1 END) STORED,
  `assigned_by` BIGINT UNSIGNED NOT NULL,
  `assigned_at` DATETIME NOT NULL,
  `ended_by` BIGINT UNSIGNED NULL,
  `ended_at` DATETIME NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_assignments_1` (`id`, `project_id`),
  UNIQUE KEY `uq_project_assignments_2` (`id`, `project_id`, `volunteer_id`),
  UNIQUE KEY `uq_project_assignments_3` (`project_id`, `volunteer_id`, `active_flag`),
  KEY `ix_project_assignments_1` (`volunteer_id`),
  CONSTRAINT `ck_project_assignments_1` CHECK (end_date IS NULL OR end_date >= start_date),
  CONSTRAINT `ck_project_assignments_2` CHECK (status = 'active' OR end_date IS NOT NULL),
  CONSTRAINT `fk_project_assignments_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_project_assignments_2` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_project_assignments_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_project_assignments_4` FOREIGN KEY (`ended_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D4 activities (PD-None): Dated sessions. Date and times are local Europe/London wall-clock.
CREATE TABLE `activities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(180) NOT NULL,
  `description` TEXT NULL,
  `activity_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `venue` VARCHAR(200) NOT NULL,
  `lead_supervisor_id` BIGINT UNSIGNED NULL,
  `status` ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_activities_1` (`id`, `project_id`),
  KEY `ix_activities_1` (`project_id`, `activity_date`),
  KEY `ix_activities_2` (`activity_date`, `status`),
  CONSTRAINT `ck_activities_1` CHECK (end_time > start_time),
  CONSTRAINT `fk_activities_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_activities_2` FOREIGN KEY (`project_id`, `lead_supervisor_id`) REFERENCES `project_supervisors` (`project_id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_activities_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D5 activity_assignments (PD-Med): Volunteers rostered on an activity, tied to a specific service period.
CREATE TABLE `activity_assignments` (
  `activity_id` BIGINT UNSIGNED NOT NULL,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `volunteer_id` BIGINT UNSIGNED NOT NULL,
  `project_assignment_id` BIGINT UNSIGNED NOT NULL,
  `assigned_by` BIGINT UNSIGNED NOT NULL,
  `assigned_at` DATETIME NOT NULL,
  PRIMARY KEY (`activity_id`, `volunteer_id`),
  KEY `ix_activity_assignments_1` (`project_assignment_id`, `project_id`, `volunteer_id`),
  CONSTRAINT `fk_activity_assignments_1` FOREIGN KEY (`activity_id`, `project_id`) REFERENCES `activities` (`id`, `project_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_activity_assignments_2` FOREIGN KEY (`project_assignment_id`, `project_id`, `volunteer_id`) REFERENCES `project_assignments` (`id`, `project_id`, `volunteer_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_activity_assignments_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D6 attendance (PD-Med): Attendance per rostered volunteer per activity.
CREATE TABLE `attendance` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id` BIGINT UNSIGNED NOT NULL,
  `volunteer_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('present','late','absent','excused') NOT NULL,
  `recorded_by` BIGINT UNSIGNED NOT NULL,
  `recorded_at` DATETIME NOT NULL,
  `verified_by` BIGINT UNSIGNED NULL,
  `verified_at` DATETIME NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_1` (`activity_id`, `volunteer_id`),
  CONSTRAINT `fk_attendance_1` FOREIGN KEY (`activity_id`, `volunteer_id`) REFERENCES `activity_assignments` (`activity_id`, `volunteer_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_attendance_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_attendance_3` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D7 tasks (PD-None): Project tasks, optionally assigned to a volunteer's service period.
CREATE TABLE `tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(180) NOT NULL,
  `description` TEXT NULL,
  `assigned_assignment_id` BIGINT UNSIGNED NULL,
  `status` ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `due_date` DATE NULL,
  `completed_at` DATETIME NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `deleted_at` DATETIME NULL,
  `legacy_uuid` CHAR(36) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tasks_1` (`id`, `project_id`),
  UNIQUE KEY `uq_tasks_2` (`legacy_uuid`),
  KEY `ix_tasks_1` (`project_id`, `status`),
  CONSTRAINT `ck_tasks_1` CHECK (status <> 'completed' OR completed_at IS NOT NULL),
  CONSTRAINT `fk_tasks_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_tasks_2` FOREIGN KEY (`assigned_assignment_id`, `project_id`) REFERENCES `project_assignments` (`id`, `project_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_tasks_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- E1 time_logs (PD-Med): Hours claims and their review. Approved rows are the only source of hours.
CREATE TABLE `time_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `volunteer_id` BIGINT UNSIGNED NOT NULL,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `project_assignment_id` BIGINT UNSIGNED NOT NULL,
  `activity_id` BIGINT UNSIGNED NULL,
  `task_id` BIGINT UNSIGNED NULL,
  `work_date` DATE NOT NULL,
  `minutes` SMALLINT UNSIGNED NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `status` ENUM('pending','approved','rejected','revision_requested','voided') NOT NULL DEFAULT 'pending',
  `resubmission_of_id` BIGINT UNSIGNED NULL,
  `submitted_by` BIGINT UNSIGNED NOT NULL,
  `submitted_at` DATETIME NOT NULL,
  `reviewed_by` BIGINT UNSIGNED NULL,
  `reviewed_at` DATETIME NULL,
  `reviewer_comment` VARCHAR(500) NULL,
  `voided_by` BIGINT UNSIGNED NULL,
  `voided_at` DATETIME NULL,
  `void_reason` VARCHAR(255) NULL,
  `live_activity_id` BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `status` IN ('pending','revision_requested','approved') THEN `activity_id` END) STORED,
  `live_task_id` BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `status` IN ('pending','revision_requested','approved') THEN `task_id` END) STORED,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_time_logs_1` (`volunteer_id`, `live_activity_id`),
  UNIQUE KEY `uq_time_logs_2` (`volunteer_id`, `live_task_id`, `work_date`),
  UNIQUE KEY `uq_time_logs_3` (`resubmission_of_id`),
  KEY `ix_time_logs_1` (`volunteer_id`, `work_date`),
  KEY `ix_time_logs_2` (`project_id`, `status`, `work_date`),
  CONSTRAINT `ck_time_logs_1` CHECK (minutes BETWEEN 1 AND 1440),
  CONSTRAINT `ck_time_logs_2` CHECK (NOT (activity_id IS NOT NULL AND task_id IS NOT NULL)),
  CONSTRAINT `ck_time_logs_3` CHECK (status = 'pending' OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)),
  CONSTRAINT `ck_time_logs_4` CHECK (status <> 'voided' OR (voided_by IS NOT NULL AND voided_at IS NOT NULL AND void_reason IS NOT NULL)),
  CONSTRAINT `fk_time_logs_1` FOREIGN KEY (`project_assignment_id`, `project_id`, `volunteer_id`) REFERENCES `project_assignments` (`id`, `project_id`, `volunteer_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_time_logs_2` FOREIGN KEY (`activity_id`, `project_id`) REFERENCES `activities` (`id`, `project_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_time_logs_3` FOREIGN KEY (`task_id`, `project_id`) REFERENCES `tasks` (`id`, `project_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_time_logs_4` FOREIGN KEY (`activity_id`, `volunteer_id`) REFERENCES `activity_assignments` (`activity_id`, `volunteer_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_time_logs_5` FOREIGN KEY (`resubmission_of_id`) REFERENCES `time_logs` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_time_logs_6` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_time_logs_7` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_time_logs_8` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- E2 performance_reviews (PD-High): Performance reviews (four 1-5 scores).
CREATE TABLE `performance_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `volunteer_id` BIGINT UNSIGNED NOT NULL,
  `project_id` BIGINT UNSIGNED NULL,
  `reviewer_id` BIGINT UNSIGNED NOT NULL,
  `review_date` DATE NOT NULL,
  `reliability` TINYINT UNSIGNED NOT NULL,
  `teamwork` TINYINT UNSIGNED NOT NULL,
  `communication` TINYINT UNSIGNED NOT NULL,
  `quality` TINYINT UNSIGNED NOT NULL,
  `achievements` TEXT NULL,
  `improvement_areas` TEXT NULL,
  `notes` TEXT NULL,
  `shared_with_volunteer` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_performance_reviews_1` (`volunteer_id`, `review_date`),
  CONSTRAINT `ck_performance_reviews_1` CHECK (`reliability` BETWEEN 1 AND 5),
  CONSTRAINT `ck_performance_reviews_2` CHECK (`teamwork` BETWEEN 1 AND 5),
  CONSTRAINT `ck_performance_reviews_3` CHECK (`communication` BETWEEN 1 AND 5),
  CONSTRAINT `ck_performance_reviews_4` CHECK (`quality` BETWEEN 1 AND 5),
  CONSTRAINT `fk_performance_reviews_1` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_performance_reviews_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_performance_reviews_3` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- E3 certificates (PD-Med): Certificates; hours captured at issue time.
CREATE TABLE `certificates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `volunteer_id` BIGINT UNSIGNED NOT NULL,
  `reference` VARCHAR(30) NULL,
  `status` ENUM('not_eligible','eligible','approved','issued','delivered','cancelled') NOT NULL DEFAULT 'not_eligible',
  `required_hours` SMALLINT UNSIGNED NULL,
  `approved_minutes_at_issue` INT UNSIGNED NULL,
  `approved_by` BIGINT UNSIGNED NULL,
  `approved_at` DATETIME NULL,
  `issued_at` DATETIME NULL,
  `delivered_at` DATETIME NULL,
  `notes` TEXT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_certificates_1` (`reference`),
  KEY `ix_certificates_1` (`volunteer_id`),
  CONSTRAINT `ck_certificates_1` CHECK (status NOT IN ('issued','delivered') OR (issued_at IS NOT NULL AND reference IS NOT NULL AND approved_minutes_at_issue IS NOT NULL)),
  CONSTRAINT `ck_certificates_2` CHECK (status <> 'delivered' OR delivered_at IS NOT NULL),
  CONSTRAINT `fk_certificates_1` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_certificates_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_certificates_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- F1 announcements (PD-None): Announcements shown in the portals.
CREATE TABLE `announcements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(200) NOT NULL,
  `body` TEXT NOT NULL,
  `audience` ENUM('all','volunteers','supervisors','admins','project') NOT NULL,
  `project_id` BIGINT UNSIGNED NULL,
  `published_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_announcements_1` (`published_at`, `audience`),
  CONSTRAINT `ck_announcements_1` CHECK (audience <> 'project' OR project_id IS NOT NULL),
  CONSTRAINT `fk_announcements_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_announcements_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- F2 notifications (PD-Med): Personal in-app inbox. Works without email.
CREATE TABLE `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `body` VARCHAR(500) NULL,
  `link_path` VARCHAR(255) NULL,
  `read_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_notifications_1` (`user_id`, `read_at`, `created_at`),
  CONSTRAINT `ck_notifications_1` CHECK (link_path IS NULL OR (link_path LIKE '/%' AND link_path NOT LIKE '//%')),
  CONSTRAINT `fk_notifications_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- F3 email_outbox (PD-High): Outgoing email queue. Body erased once no longer needed.
CREATE TABLE `email_outbox` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_email` VARCHAR(254) NOT NULL,
  `template` VARCHAR(50) NOT NULL,
  `subject` VARCHAR(200) NOT NULL,
  `body` MEDIUMTEXT NULL,
  `status` ENUM('queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(500) NULL,
  `available_at` DATETIME NOT NULL,
  `sent_at` DATETIME NULL,
  `purged_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_email_outbox_1` (`status`, `available_at`),
  CONSTRAINT `ck_email_outbox_1` CHECK (status IN ('queued','sending') OR body IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- G1 audit_logs (PD-Med): Tamper-evident audit trail (see section 6). Table collation utf8mb4_bin.
CREATE TABLE `audit_logs` (
  `seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `canon_version` TINYINT UNSIGNED NOT NULL,
  `occurred_at` DATETIME(6) NOT NULL,
  `recorded_at` DATETIME(6) NOT NULL,
  `source` ENUM('direct','fallback') NOT NULL,
  `fallback_ref` BINARY(32) NULL,
  `actor_user_id` BIGINT UNSIGNED NULL,
  `actor_email` VARCHAR(254) NULL,
  `actor_role` VARCHAR(40) NULL,
  `action` VARCHAR(64) NOT NULL,
  `outcome` ENUM('success','denied','failed') NOT NULL,
  `target_type` VARCHAR(40) NULL,
  `target_id` BIGINT UNSIGNED NULL,
  `ip` VARBINARY(16) NULL,
  `request_id` CHAR(16) NOT NULL,
  `details` TEXT NULL,
  `key_id` TINYINT UNSIGNED NOT NULL,
  `prev_hash` BINARY(32) NOT NULL,
  `row_hash` BINARY(32) NOT NULL,
  PRIMARY KEY (`seq`),
  UNIQUE KEY `uq_audit_logs_1` (`row_hash`),
  UNIQUE KEY `uq_audit_logs_2` (`fallback_ref`),
  KEY `ix_audit_logs_1` (`occurred_at`),
  KEY `ix_audit_logs_2` (`actor_user_id`, `occurred_at`),
  KEY `ix_audit_logs_3` (`target_type`, `target_id`),
  KEY `ix_audit_logs_4` (`action`, `occurred_at`),
  CONSTRAINT `ck_audit_logs_1` CHECK (details IS NULL OR JSON_VALID(details)),
  CONSTRAINT `ck_audit_logs_2` CHECK ((source = 'direct' AND fallback_ref IS NULL) OR (source = 'fallback' AND fallback_ref IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- G2 audit_chain (PD-None): Head of the audit chain; the row locked to serialise audit writers.
CREATE TABLE `audit_chain` (
  `singleton` ENUM('head') NOT NULL,
  `last_seq` BIGINT UNSIGNED NOT NULL,
  `last_hash` BINARY(32) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`singleton`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- G3 settings (PD-None): Non-secret settings (organisation details, consent version, retention periods, install lock).
CREATE TABLE `settings` (
  `key` VARCHAR(64) NOT NULL,
  `value` TEXT NULL,
  `updated_by` BIGINT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`key`),
  CONSTRAINT `fk_settings_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
