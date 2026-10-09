# Install and update RUSH

Open your deployed RUSH HTTPS address while connected to the internet. Expand
**Install RUSH** and wait for **App ready for offline startup**.

- In a supporting desktop or Android browser, use the browser's Install app or
  Add to Home Screen command.
- On iPhone or iPad, open RUSH in Safari, choose Share, then Add to Home Screen.
- Open the installed icon once online before relying on offline startup.

Installation options vary by browser. RUSH also works in a normal browser tab.
The same origin must serve the Client, API and administration.

## What is available offline now

The app's interface, fonts and connection guidance can open offline after setup.
Signing in and administration require a connection. This foundation does not yet
enable offline account access or availability editing; those follow in
RUSH-012/013. Installing does not mean account data has synchronized.

If the app cannot check your session, reconnect and choose **Retry connection**.
If setup fails, reload while connected. Clearing browser/site storage or browser
eviction removes offline assets, so open online again to prepare the app.
Do not clear site storage to fix an update: it can also remove pending user work.

## Updates

When RUSH announces an update, finish your work, close **all** RUSH tabs and
installed windows, then reopen. An update never forcibly reloads an open form.
App asset readiness and update notices are separate from data synchronization.

## Verification

Development PWA mode is not offline evidence. Use the production-built Docker
fixture described in [RUSH-011 evidence](evidence/RUSH-011/README.md). For a device
release check, open the deployed HTTPS site, confirm readiness, install using
the browser UI, close it, disable networking and launch its installed icon.
Confirm the shell opens, then reconnect and retry. Check desktop/Android and
iOS Safari separately; headless tests do not operate OS install dialogs.
