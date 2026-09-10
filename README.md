# MailWizz QueenSMTP delivery server

Free, open-source [MailWizz](https://www.mailwizz.com/) delivery server
extension for the [QueenSMTP](https://queensmtp.com/) Email API.

Sends over HTTP instead of SMTP, which matters if your host blocks outbound
mail ports. Bounces, complaints and unsubscribes come back through signed
webhooks and are processed automatically.

Requires **MailWizz 2.3.0+**. Installs as a zip. No core files are modified.

---

## Why

Many hosts block outbound SMTP, which leaves MailWizz unable to send at all.
MailWizz ships API-based delivery servers for Mailgun, SendGrid, SparkPost,
Postmark, Mailjet and others, but not QueenSMTP. This fills that gap.

## Features

- **HTTP delivery** — no SMTP ports required
- **Automatic bulk/transactional classification** — see below; this is the one
  genuinely QueenSMTP-specific rule and getting it wrong causes hard failures
- **Header compliance** — `List-Unsubscribe` (https + mailto),
  `List-Unsubscribe-Post` one-click, `List-Id`, `X-Report-Abuse`, and any other
  `List-*` or `X-*` header, filtered to QueenSMTP's allow list
- **Signed webhooks** — HMAC-SHA256 verified, replay-protected, and tolerant of
  QueenSMTP's 24-hour secret rotation
- **Accurate attribution** — the API message id is stored and matched back from
  webhooks, so events map to the right campaign and subscriber
- **Fails safely** — an invalid key, or a refusal that would repeat for every
  remaining subscriber, deactivates the server instead of burning the list

---

## Install

1. Download `queensmtp.zip` from
   [Releases](https://github.com/ajmalmughal/mailwizz-queensmtp/releases)
2. MailWizz backend → **Extensions** → **Upload extension** → select the zip
3. Enable it

Or copy the `queensmtp/` folder into `apps/extensions/` and enable it in the UI.

## Set up

### 1. Verify your sending domain

Add and verify the domain in QueenSMTP first. The `from_email` domain must be
verified or every send fails.

### 2. Create the delivery server

**Delivery servers → Create new → QueenSMTP Email API**

| Field | Value |
|---|---|
| Api key | Your QueenSMTP API key (sent as a bearer token) |
| Webhook signing secret | The per-endpoint secret from step 3 |
| From email | An address on a verified sending domain |

Save it, then reopen it — the webhook url only appears once the server has an id.

### 3. Create the webhook endpoint

In QueenSMTP: **Developer API → Webhooks → Add endpoint**

- **Endpoint URL** — the DSWH url shown on the delivery server page, in the form
  `https://your-mailwizz.com/index.php/dswh/<id>`
- **Events** — subscribe to `message.bounced`, `message.complained`,
  `message.unsubscribed` and `message.rejected` only

Leave `message.delivered`, `message.deferred`, `message.opened` and
`message.clicked` unsubscribed. They are accepted and discarded, because MailWizz
does its own open and click tracking and counting both would double every figure.
Subscribing to them just generates a request per subscriber per event for nothing.

**The first ping will fail, and that is expected.** QueenSMTP shows the signing
secret only after the endpoint is created, but sends `webhook.ping` on save — at
which point MailWizz has no secret and correctly rejects the request with 401.
Copy the secret, paste it into the delivery server, save, then press
**Enable (ping)** again. The endpoint activates on the second attempt.

The endpoint must be https on port 443, on a hostname rather than an IP, and
publicly reachable. QueenSMTP does not follow redirects.

---

## Bulk versus transactional

QueenSMTP requires every message to be classified, and enforces it strictly:

| | |
|---|---|
| `isBulk: true` without `List-Unsubscribe` | **400** |
| `isBulk: false` with `List-Unsubscribe` | **400** |

This extension derives the flag rather than exposing a setting: a message is sent
as bulk **if and only if** it carries a `List-Unsubscribe` header. That makes both
failures structurally impossible, and matches the rule QueenSMTP applies on its
own SMTP path.

Practical consequence: **keep the unsubscribe header enabled on your lists.** A
campaign without one is sent as transactional, and QueenSMTP's content scanner may
hold it with `UNSUBSCRIBE_HEADER_REQUIRED`.

QueenSMTP adds `Precedence: bulk` to bulk mail and `Auto-Submitted: auto-generated`
to transactional mail itself. It also generates its own `Feedback-ID`, so
MailWizz's is dropped before sending — supplying one is not an error, it is simply
replaced.

## Headers

QueenSMTP accepts `List-*`, `X-*`, `In-Reply-To`, `References` and `Importance`.
Anything else is dropped before sending, with a note in the delivery log, because
a rejected header name returns 400 for the whole request and would fail every
message in a campaign.

Identity headers (`From`, `To`, `Cc`, `Bcc`, `Subject`, `Message-ID`,
`DKIM-Signature`) are set by QueenSMTP and are dropped silently. So are
`Feedback-ID` and `Precedence`, which QueenSMTP manages itself.

Limits are 25 headers at 900 characters each. An over-long value is dropped rather
than truncated, since a truncated `List-Unsubscribe` is a broken url.

`List-*` headers are covered by QueenSMTP's DKIM signature. `X-*` headers are
passed through unsigned.

## Attachments are not supported

The QueenSMTP **API does not accept attachments** — they are available only over
their SMTP transport, on paid plans, capped at 1 MB and two files. Campaigns
carrying attachments are still delivered, without them, and a note is written to
the delivery log.

## Tracking

QueenSMTP always adds its own open pixel and rewrites all body links through its
tracking host, including unsubscribe links. This is not switchable. The
`List-Unsubscribe` header itself is never altered, so one-click unsubscribe always
works.

If you use MailWizz's own tracking you will see two open pixels and a double
redirect on clicks. Nothing breaks, but open counts will differ between the two
systems.

## When sending stops

The server deactivates itself, rather than continuing, when:

- **The API key is rejected** (401/403) — including an IP allowlist or
  from-domain restriction blocking the request
- **QueenSMTP refuses the content** (`content_rejected`, `content_flagged`,
  `content_blocked`) — the verdict applies to the template, so every remaining
  subscriber would be refused identically. Continuing means thousands of futile
  calls, each counting against your account standing.

The reason is written to the campaign delivery log, to
`apps/common/runtime/queensmtp-errors.log`, to the application log at ERROR level,
and to the customer's message inbox in the MailWizz UI.

Note that MailWizz loads the delivery server once per batch, so deactivation takes
effect from the next batch rather than mid-loop. It limits the damage; it does not
stop the current batch.

`UNSUBSCRIBE_HEADER_REQUIRED` does **not** deactivate the server. It is fixable
per message and records no strike.

## Account standing

QueenSMTP gates new accounts. Until an account has real sending history, marketing
mail may be refused regardless of content, and **refused sends count against your
standing** — testing with rejected campaigns actively makes it worse. Check where
you stand with:

```
curl -s https://queensmtp.com/v1/account/standing \
  -H "Authorization: Bearer YOUR_API_KEY"
```

If campaigns are being refused while transactional mail sends fine, this is why.
Building genuine transactional history is the documented route out.

---

## Multiple delivery servers

MailWizz can rotate several delivery servers within one campaign, and this
extension is built for that.

**Each QueenSMTP delivery server needs its own webhook endpoint.** The DSWH url
and the signing secret are both per server, so three delivery servers means three
endpoints in QueenSMTP, each pointing at a different `/dswh/<id>` with its own
secret. Miss one and bounces from that server's traffic are silently lost.

Bounce attribution is scoped to the delivery server that sent the message, so a
QueenSMTP webhook never acts on a row written by a different provider, and where
several QueenSMTP servers share one API key — QueenSMTP delivers every event to
every endpoint on the account — only the server that sent the message acts on it.

## Troubleshooting

**Webhook endpoint will not activate.** Check
`apps/common/runtime/queensmtp-webhook.log` — it names which check failed: missing
secret, absent signature header, stale timestamp, or digest mismatch.

**Campaigns refused, transactional fine.** Account standing, not your template.
See above.

**Bounces not recorded.** Confirm the endpoint is active in QueenSMTP, that
`message.bounced` is subscribed, and that the signing secret on the delivery
server matches the endpoint.

---

## Credits

Built by [M. Ajmal Mughal](https://github.com/ajmalmughal). Thanks to QueenSMTP
support, who fixed a bounce `smtpCode` parsing bug and shipped webhook
registration, a structured error envelope and an account standing endpoint in
response to reports raised while building this.

## License

MIT — see [LICENSE](LICENSE).
