# Changelog

All notable changes to this project are documented here.

## [1.0.0] - 2026-09-10

First release.

### Added
- QueenSMTP Email API delivery server type for MailWizz, registered through
  filters with no core file changes
- Sending via `POST https://queensmtp.com/v1/send` with bearer authentication
- Automatic bulk/transactional classification: `isBulk` is derived from the
  presence of a `List-Unsubscribe` header, which makes both of QueenSMTP's
  documented 400 conditions impossible
- Header filtering to QueenSMTP's allow list (`List-*`, `X-*`, `In-Reply-To`,
  `References`, `Importance`), enforcing the 25 header and 900 character limits
- Silent dropping of provider-managed headers (`Feedback-ID`, `Precedence`) and
  identity headers QueenSMTP rejects with 400
- `fromName` sanitising against QueenSMTP's 400 conditions: urls, embedded email
  addresses, line breaks and the 78 character limit
- Signed webhook processing for `message.bounced`, `message.complained`,
  `message.unsubscribed` and `message.rejected`
- HMAC-SHA256 signature verification over `t + "." + rawBody`, with a five minute
  replay window and support for the two `v1` values sent during QueenSMTP's
  24-hour secret rotation
- Event deduplication on the webhook event id
- Explicit hard/soft bounce handling from QueenSMTP's `bounceType`; hard bounces
  blacklist the subscriber
- Structured error handling for the `code` / `retryable` / `strike` envelope
- Server deactivation on authentication failure and on any content refusal, with
  the reason written to the delivery log, a dedicated log file, the application
  log, and the customer's message inbox

### Notes
- Attachments are not sent. The QueenSMTP API does not accept them; they are
  available only over their SMTP transport.
- `smtpCode` is ignored unless it matches `^[45]\d\d$`. QueenSMTP were parsing it
  out of the receiving server's IP address and returning impossible values such
  as `217` for a 550 bounce. They have fixed this; the guard remains against
  regression.
- `message.delivered`, `message.deferred`, `message.opened` and `message.clicked`
  are accepted and discarded, to avoid double counting MailWizz's own tracking.
- Bounce attribution is scoped to the delivery server that sent the message, so
  the extension behaves correctly when MailWizz rotates across several delivery
  servers, and when several QueenSMTP servers share one API key.
- Verified end to end against the live QueenSMTP API: sending, header filtering,
  `isBulk` derivation, the error envelope, webhook registration and activation,
  signature verification, event parsing, message id correlation, bounce
  attribution to a campaign subscriber, and automatic blacklisting on a hard
  bounce.
