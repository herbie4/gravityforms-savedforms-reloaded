# gravityforms-savedforms-reloaded
 Gravity Forms saved state addon when opening form without using url

 (GF 2.9+ Compatible) Adds state to forms allowing users to save form for later. Auto-loads saved forms without requiring save link. Based on Gravity Forms Saved Forms Addon by author: Gennady Kovshenin. NOTE: should be compatible with existing data from the addon it is based on.

# NOW in beta testing do not use on production!

Version 2.1.1 updated RGForms and RGFormsModel to GFForms and GFFormsModel

version 2.1.2 creation of empty draft posts on any form submission.
Summary of Patches
#	Change	Reason
1	gform_post_submission → gform_after_submission	Old hook doesn't fire. form_submit never ran, so pending tokens were never cleared after a normal submit → empty entries on next page load
2	Added token_exists_in_draft_table() helper.	Central check for token validity against gf_draft_submissions
3	Added clear_pending_token() helper	DRY cleanup of both meta keys
4	setup_resume_token(): bail + cleanup if enableFormState/requireLogin is off.	Prevents redirects for forms where save is disabled
5	setup_resume_token(): only redirect if token exists in draft table.	Prevents empty entries from consumed/stale tokens
6	setup_resume_token(): wp_safe_redirect() + is_ssl().	Safer, cleaner URL construction
7	provide_resume_token(): guard on enableFormState	Defense in depth
8	provide_resume_token(): validate token before returning; clean up if stale.	Prevents GF from receiving a dead token
9	get_user_draft_uuid_by_user_id(): removed "most recent draft" fallback.	That fallback could return another user's draft, causing wrong/empty entries
10	form_save(): skip if $resume_token is empty; mirror backup meta.	Avoids writing empty tokens; keeps legacy key in sync
11	form_submit(): cleanup uses helper.	Consistency
