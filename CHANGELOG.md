r3.12 – 2026-10-14
------
**Security**

- Fixed separate-groups access control (security review WR-497653, Problem 1). Teachers could view or change another group's Peer Work data by editing the group ID in a request.
- Added group-access helpers in locallib.php: peerwork_can_access_group(), peerwork_require_group_access(), peerwork_require_submission_access() and peerwork_get_accessible_groups(). These check the course, the Peer Work grouping and Moodle's - Separate groups rules, including moodle/site:accessallgroups.
- Group access is now checked on the server before any group data is read or changed in:
  - view.php: the teacher list only shows groups the user can access
  - details.php: grading details and saving grades
  - override.php and peerwork_peer_override(): peer-grade overrides
  - release.php: grade release
  - clearsubmissions.php: clearing submissions
  - export.php: CSV export
  - downloadallsubmissions.php: bulk submission download
  - lib.php: the in-place grade editor and submission/feedback file downloads

- "All groups" actions (release, clear) now require moodle/site:accessallgroups. Export and download only include the groups the user can access.
- Group IDs that don't exist or can't be accessed get the same response, so IDs can't be guessed.
- export.php no longer exports the member list of a group from another course.
- File downloads now check course enrolment and activity visibility, not just site login.
override.php now checks that the student being overridden belongs to the group, and saves using the checked URL values instead of hidden form fields.

**Changed**
- Users who can grade the activity (teachers, non-editing teachers) are no longer included in peer assessment, even when they are group members. They no longer appear as peers to rate, in grade calculations, in the "outstanding" list or on the grading page. Added peerwork_get_group_members().
- "Release all" and "Clear all" now only affect groups in the Peer Work grouping.
- "Export all", "Download all submissions", "Release all" and "Clear all" buttons are only shown to users with moodle/site:accessallgroups.
ZIP folder names now include the group ID (for example Group_Alpha_12), so groups with the same name can't overwrite each other's files.
Added language string groupaccessdenied.

**Added**
- Added the SimplePA calculator subplugin ([peerworkcalculator_simplepa]). This new calculator grades based on a Simple average from the peers. For example in a group of 3 if student A received a grades of 2 and 4 from their peers, the caculated grade will be (2+4)/2 = 3. 

**Fixed**
- Fixed COALESCE(timegraded) in grade release. It now reads COALESCE(timegraded, 0).
- peerwork_outstanding() now uses a single database query instead of one per group member.




r3.11.0
-------

- Refactored activity to used own setting for grouping instead of Common module settings.
- Refactored submission grade to allow float values
- Added grading before due date warning #59 (for group summary screen)
- Bumped release number to match latest major Moodle version supported
- Refactored settings to allow null calculator in order to allow for cases where
  all calculators are disabled/uninstalled.
- Moved PA weighting setting to calculator class.
- AMOS string fix #3 provided by https://github.com/izendegi.
- Table prefix fix #1 provided by https://github.com/izendegi.

r2.3.2
------

- Removed deprecated string grade/core
- Removed deprecated function user_picture::fields()

r2.3.1
------

- Added grading before due date warning #59
- Improved display of criteria scales #60

r2.3.0
------

- Added group restriction check #54
- Added group update observer to amend grades when group membership changes #37
- Made WebPA calculator a dependency
- Added download all submissions
- Changed icon
- Added first and last names to export #48
- Added student ID to export #57
- Fixed bug in calculation when self grade turned off #58

r2.2.0
------

- Fixed single calculator plugin error #55 #56
- Added support for v3.10

r2.1.0
------

- Added individual peer grade override
- Added site setting:
  Justification - disabled, hidden, visible anonymous, visible with usernames
- Added suite of Behat tests

r2.0.0
------

- Refactored the calculator as a subplugin
- Added course scales to selection

r1.1.0
------

- Added site settings:
  Default number of criteria - 1-5
  Default text for 5 criteria
  Default scale for 5 criteria
  Justification type - per peer or per criteria
- Added activity setting:
  Justification type - per peer or per criteria
- Some activity settings no longer editable after a student has submitted:
  Peer grades visibility
  Allow students to self grade
  Justification
  Justification type
  Justificaton max length
  Criteria
  Criteria scale
  Add criteria
- Style changes to improve responsiveness

r1.0.0
------

- Upgrade requirement to Moodle 3.6
- Change version number from 3.3+ to 1.0.0
- Rename plugin to mod_peerwork
- Various changes by Kevin Moore
- Forked from City University London's mod_peerassessment plugin v3.3
