# Google Authenticator verification

The app uses **Google Authenticator (TOTP, RFC 6238)** for sensitive actions. No email or SMS codes are sent — the user's authenticator app generates the 6-digit codes.

**Regular user login is email + password only.** The authenticator is required for exactly three things:

1. **Registration** — after submitting the signup form, the page shows a QR code. The user scans it with Google Authenticator and enters the app's current 6-digit code. The account is only created after the code is verified (`verify_registration.php`). The signup stays in `pending_registrations` for up to 30 minutes while the user sets up the app. The secret is never displayed as text — only encoded in the QR code.
2. **Admin login** — after the email + password check, **every admin login generates a fresh QR code**. The admin scans it with Google Authenticator and enters the app's current 6-digit code; the session is only created after the code is verified (`verify_login_otp.php`), and the confirmed secret replaces the previously stored one. Deleting the entry in the authenticator app can never lock an admin out — the next login simply shows a new QR. Regular users are never asked for a code at login.
3. **Change mobile number** — on the dashboard, the user enters the new number and confirms it with a code from their authenticator app (`request_phone_change.php` → `verify_phone_change.php`). The new number must not belong to another account. Accounts created before TOTP (no stored secret) are shown a QR code during this flow and enrolled automatically when they confirm the change.

Any TOTP app works (Google Authenticator, Authy, Microsoft Authenticator, 1Password, etc.) — the parameters are the standard SHA1 / 6 digits / 30 seconds.

## Database

- For a fresh install, import the updated `database.sql`.
- For an existing install, run these migrations once in phpMyAdmin against `surf_to_sawa`:
  - `migrations/001_registration_phone_otp.sql`
  - `migrations/002_registration_otp_rate_log.sql`
  - `migrations/005_google_authenticator.sql` (adds `totp_secret`, the `totp_attempts` lockout table, and removes the replaced email/SMS OTP tables)

Migration 005 has already been applied to the local `surf_to_sawa` database in this workspace. Migration 004 (email OTP) is superseded — do not apply it to a fresh database; 005 drops its tables if they exist.

## Configuration

**None required.** TOTP needs no SMTP server, no Twilio account, and no environment variables. The shared secret is generated per account with `random_bytes()` and stored in `users.totp_secret` (Base32). QR codes are rendered in the browser by `js/qrcode.min.js`, so the secret is only ever sent to the enrolling user's own browser session.

## Safeguards

- Codes are verified with a ±1 time-step window (±30 seconds) to tolerate small clock drift between the phone and the server. Keep the server clock accurate (NTP).
- **Brute-force lockout:** 5 incorrect codes within 5 minutes lock that user + purpose (login / phone change) for 5 minutes (`totp_attempts` table). Registration allows 5 wrong attempts before the pending signup must be restarted.
- Philippine numbers are normalized to E.164 (`+639…`); phone change rejects the current number and numbers already registered to another account.
- The admin login session is only established after the authenticator code passes; `session_regenerate_id()` runs on login and verification.
- If a user loses their phone, an administrator must clear the account's `totp_secret` in the database (`UPDATE users SET totp_secret = NULL WHERE email = '…'`); a fresh QR enrollment is then offered at the next admin login or phone-number change.

## Files

- `php/totp.php` — Base32, TOTP generation/verification (RFC 6238), otpauth URI, lockout helpers.
- `php/register.php`, `php/verify_registration.php`, `php/registration_status.php` — enrollment during signup.
- `php/login.php`, `php/verify_login_otp.php`, `php/login_otp_status.php` — admin login code step + first-time admin enrollment (regular users skip this entirely).
- `php/request_phone_change.php`, `php/verify_phone_change.php` — phone change confirmation.
- `js/qrcode.min.js` — local QR rendering (qrcodejs, MIT).
- Tests: `tests/totp_test.php` (unit, incl. RFC 6238 vectors), `tests/registration_flow_test.php` (end-to-end registration → login → phone change), `tests/topup_flow_test.php`.

Run the tests with the PHP CLI, e.g.:

```text
C:\xampp_new\php\php.exe tests\totp_test.php
C:\xampp_new\php\php.exe -S 127.0.0.1:8082 -t C:\xampp_new\htdocs\surf-to-sawa
C:\xampp_new\php\php.exe tests\registration_flow_test.php
C:\xampp_new\php\php.exe tests\topup_flow_test.php
```

(`S2S_TEST_BASE_URL` overrides the default `http://127.0.0.1:8082` for the flow tests.)