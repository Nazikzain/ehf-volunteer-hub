-- EHF Volunteer Hub - migration 002: protective triggers (Design Review Rev3 §6.3/§6.10, §4.4)
-- Layer 2 of audit protection: the audit log and its chain head cannot be altered or deleted
-- through ordinary DML. Archiving (§6.11) drops and recreates these triggers with the DDL account.
DROP TRIGGER IF EXISTS trg_audit_logs_no_update;
DROP TRIGGER IF EXISTS trg_audit_logs_no_delete;
DROP TRIGGER IF EXISTS trg_audit_chain_no_delete;
DROP TRIGGER IF EXISTS trg_time_logs_approved_immutable;

CREATE TRIGGER trg_audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';

CREATE TRIGGER trg_audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';

CREATE TRIGGER trg_audit_chain_no_delete BEFORE DELETE ON audit_chain FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_chain head cannot be deleted';

-- Approved hours are immutable except (a) the void transition and (b) erasure redaction
-- of free text to the fixed marker '[erased]' (Design §4.4, §7 W4).
DELIMITER $$
CREATE TRIGGER trg_time_logs_approved_immutable BEFORE UPDATE ON time_logs FOR EACH ROW
BEGIN
  IF OLD.status IN ('approved', 'voided') THEN
    IF NEW.volunteer_id <> OLD.volunteer_id OR NEW.project_id <> OLD.project_id
       OR NEW.project_assignment_id <> OLD.project_assignment_id
       OR NOT (NEW.activity_id <=> OLD.activity_id) OR NOT (NEW.task_id <=> OLD.task_id)
       OR NEW.work_date <> OLD.work_date OR NEW.minutes <> OLD.minutes
       OR NOT (NEW.resubmission_of_id <=> OLD.resubmission_of_id)
       OR NEW.submitted_by <> OLD.submitted_by
       OR (NEW.description <> OLD.description AND NEW.description <> '[erased]') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approved or voided time logs are immutable';
    END IF;
    IF OLD.status = 'voided' AND NEW.status <> 'voided' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a voided time log cannot change status';
    END IF;
    IF OLD.status = 'approved' AND NEW.status NOT IN ('approved', 'voided') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an approved time log can only be voided';
    END IF;
  END IF;
END$$
DELIMITER ;
