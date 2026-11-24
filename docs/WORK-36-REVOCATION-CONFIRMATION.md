# Revocation confirmation contract

Remote Posting and Registration now navigate to a server-rendered review page before offering a destructive submit button. The review identifies the selected token or invite using its name and existing masked hint. Cancel returns to the list without a POST. The final form uses the existing action name, target ID, CSRF token and POST endpoint.

This is a user-facing safety step, not a new authorization requirement. Direct authorized, CSRF-valid POST requests retain their existing behavior. Owner access checks, revocation helpers, hashing, API authentication, invite validation, audit events and feedback are unchanged. No database or version changes are included.

## Why a server-rendered review

Remote Posting previously used `data-confirm`, but its normal Admin script path did not load the listener in `editor.js`. Registration used an inline `onsubmit` handler blocked by the existing Content Security Policy. Moving a listener would still leave the no-JavaScript path without confirmation.

Both pages now use ordinary links and forms. No new script, inline handler, CSP exception, modal framework or global confirmation listener is needed. Existing `data-confirm` interactions, including the Media bulk action's dynamically updated message, remain unchanged.

Review reloads current records and follows the list's eligibility rules: only effective active API tokens, and invites with stored active status. Expired or exhausted invites keep their prior ability to be revoked; their registration validation rules are unchanged. Missing or revoked review targets show an unavailable message without a destructive form. The selected ID is carried by the form, not shared session state.

## Disposable regression

The dedicated test requires PHP with PDO MySQL, a task-owned MySQL/MariaDB database, Node, `playwright-core`, and Chromium or Chrome. It creates random prefixed tables, a private temporary application tree and a loopback PHP server, then removes them in `finally`. Scheduling, federation, mail and PWA activity are disabled. Browser requests outside the fixture origin are blocked.

```sh
export BMS_DB_HOST=127.0.0.1
export BMS_DB_NAME=your_disposable_database
export BMS_DB_USER=your_disposable_user
export BMS_DB_PASS=your_disposable_password
export BMS_DB_DANGER_RESET=1
export NODE_PATH=/path/to/playwright-core/node_modules
export BMS_BROWSER_EXECUTABLE=/usr/bin/google-chrome
export BMS_REVOCATION_BROWSER_REPORT=/path/to/synthetic-evidence
php scripts/admin-revocation-runtime-test.php
```

The browser checks actual pointer and keyboard navigation, review, Cancel, reload, repeated review and confirmed revocation for both workflows at 1440, 390 and 320 pixels, with JavaScript on and off. It verifies target isolation, API rejection after revocation, invite validation, unaffected credentials, settings preservation, authorization, CSRF, error/success feedback, escaping, masked secrets, layout, focus, Tab order and existing Media confirmations.

For the negative control, run the same three test files against untouched main `8736d752d950ba3662dbd156bef35afa1918f0eb`, adding `--negative-control`. This mode requires both original forms to submit without a dialog, verifies the resulting database revocation, and observes the Registration inline-handler CSP error. It must fail against the corrected source.

The four-job Compatibility workflow runs the corrected browser regression in every PHP/database combination and retains only synthetic screenshots and assertion reports. No credentials, cookies, disposable databases or HTTP server logs are uploaded. Screen-reader listening and non-Chromium browsers require separate manual acceptance; DOM roles and keyboard checks do not establish those results.

## Approved existing fixture correction

The Work 35 cache-cleanup test constructs its grace-period fixture under a frozen database clock, then returns to wall time for its independent HTTP connection. That fixture could age past the 30-day retention threshold before the HTTP section. Work 36 includes the founder-authorized single-line refresh of its expiration to `DATE_SUB(UTC_TIMESTAMP(), INTERVAL 29 DAY)` immediately after the clock reset. No assertion or application behavior changes. The refreshed fixture stays one day inside the retention threshold regardless of the calendar date; the test must complete within that day.
