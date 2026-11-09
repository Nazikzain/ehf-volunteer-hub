# Administrator Guide (Primary Admin and Organisation Admin)

## Your role

| Role | What you can do |
|---|---|
| **Primary Admin** | Everything an Organisation Admin can do, plus: transfer ownership, approve volunteer erasures, and archive the audit log (manual procedure). Nobody else can change your account. |
| **Organisation Admin** | Runs day-to-day operations across all projects. Cannot change the Primary Admin account, and cannot deactivate another Organisation Admin. |

## First sign-in

Open the activation link you received (valid 48 hours) and choose a password of at least 12 characters. If the link has expired, ask another admin to use **Users → Send new activation link**.

## Applications (Recruitment → Applications)

- **Filter and search.** Filter by status, or search by name, email or reference.
- **Review.** Open an application, add a review note if you wish, then choose one of:
  - **Approve:** creates the volunteer record, copies their skill and available days, and queues an activation email. The volunteer account only ever gets the Volunteer role.
  - **Reject**, **Withdrawn by applicant**, or **Mark under review**.
- **Existing volunteer.** If a volunteer with the same email already exists, you must tick **"This is the same person"** to link the application. This prevents duplicate records.
- **Closed applications** cannot be reopened.

## Volunteers

- **Profile page:** shows the volunteer's details, status history, project service periods, skills, availability, training, notes and reviews.
- **Change status:** use **Status** and give a reason. The change is recorded for reports.
- **Notes:** never shown to volunteers. "Project supervisors" notes are visible to that project's supervisors.
- **Erasure request (two people):**
  1. An Organisation Admin chooses **Prepare erasure** and enters the request reference.
  2. The Primary Admin approves and ticks any same-email applications that belong to the same person.
  3. Personal details and free text are erased. Hours, attendance and certificates remain under "Erased volunteer #id".
  4. **This cannot be undone.**

## Projects

- **Create** a project from the Projects page; edit or close it from the project page.
- **Supervisors:** assign or end supervision. Ended supervisors immediately lose access to the project. History is kept.
- **Service periods:** add a volunteer with a start date. To end a period, choose the end date and *completed* or *withdrawn*. A returning volunteer gets a new period, so their history stays accurate.

## Activities and attendance

- **Schedule** an activity: project, date, times (UK time) and venue. The lead must be a current supervisor of the project.
- **Roster** volunteers: only those with an active service period on the project covering the activity date can be added.
- **Attendance:** record or correct it on the activity page. Present or late cannot be recorded before the date. Every correction is audited with the old and new value.

## Hours (Hours → Hours approval)

- **Approve, Request revision or Reject.** A comment is required unless you approve.
- **Approved hours cannot be edited.** If they are wrong, **Void** them with a reason; the volunteer can then resubmit.
- You cannot approve hours you submitted yourself.

## Training and certificates

- **Training types:** add EHF's training types (Administration → Training types), with validity in months and whether they are mandatory. The compliance table shows missing, expiring and expired training.
- **Recording training:** done on the volunteer page. A renewal adds a new record.
- **Certificates:** create a record with the required hours, then move it through *eligible → approved → issued → delivered*. The hours at issue are stored on the certificate.

## Reports

Choose a month or year (and optionally a project).

- **Exports** are CSV files that open in Excel. Exports containing personal data are recorded in the audit log.
- **Every figure is an actual record count.** There are no estimates or targets.

## Users

- **Invite** staff (Organisation Admin or Project Supervisor). Volunteers get accounts through approval.
- **Deactivate** an account: its sessions end immediately.
- **Delete** is only for invitations that were never used. Anonymise other accounts instead.

## Audit log, integrity report, outbox, settings

| Area | What to do |
|---|---|
| **Audit log** | Use **Verify audit integrity**; GREEN is expected. AMBER means fallback events are waiting: press **Reconcile**. RED means the log was altered or truncated: inform the Primary Admin immediately and do not take a backup. |
| **Integrity report** | Lists records that break operational rules (for example, hours outside a service period). It never changes anything; correct the records manually. |
| **Daily anchor email** | Keep the daily "audit anchor" email (sent to the Primary Admin). It is independent evidence of the audit log. |
| **Settings** | Values marked [D-…] are EHF decisions. Retention purges stay "report only" until EHF and the Data Protection Lead enable them. |
