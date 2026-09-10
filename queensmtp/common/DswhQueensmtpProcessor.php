<?php declare(strict_types=1);
if (!defined('MW_PATH')) {
    exit('No direct script access allowed');
}

/**
 * DswhQueensmtpProcessor
 *
 * Handles QueenSMTP webhook deliveries and maps them onto MailWizz bounce,
 * complaint and unsubscribe records.
 *
 * PAYLOAD
 *   { "id": "...", "type": "message.bounced", "created": 1788000000, "data": {...} }
 *
 *   id        - unique per event, deduplicate on it
 *   type      - see EVENT_* below
 *   created   - unix seconds
 *   data      - event specific; data.messageId equals the id returned by
 *               /v1/send and the X-QS-Ref header on the delivered mail
 *
 * SIGNATURE
 *   X-QueenSMTP-Signature: t=<unix>,v1=<hex HMAC-SHA256>
 *
 *   The signed string is t + "." + rawBody, keyed with the per-endpoint
 *   secret. Timestamps older than 5 minutes are rejected. During a 24 hour
 *   secret rotation TWO v1 values are sent in the same header, so every v1
 *   candidate must be checked, not just the first.
 *
 *   Note this is NOT the Svix scheme used by some other providers: the digest
 *   is hex rather than base64, and the message id is not part of the signed
 *   string.
 *
 * DELIVERY BEHAVIOUR (QueenSMTP side)
 *   10 second timeout, retries at 1m, 5m, 15m, 1h, 3h, 6h, 12h then dropped.
 *   An endpoint returning no 2xx for 24 hours is disabled and the account
 *   owner emailed. A 410 disables it immediately. Redirects are not followed.
 *
 *   So a non-2xx is meaningful here. This class answers 200 for anything it
 *   has accepted, including events it deliberately ignores, and 401 only when
 *   a request cannot be authenticated -- which SHOULD disable the endpoint,
 *   because an unverifiable endpoint is worse than a disabled one.
 *
 * @copyright 2026 M. Ajmal Mughal
 * @license   MIT
 */
class DswhQueensmtpProcessor
{
    /**
     * Sent when an endpoint is created. QueenSMTP only activates the endpoint
     * once this is answered with a 2xx.
     */
    public const EVENT_PING = 'webhook.ping';

    /**
     * Events acted upon.
     */
    public const EVENT_BOUNCED     = 'message.bounced';
    public const EVENT_COMPLAINED  = 'message.complained';
    public const EVENT_UNSUBSCRIBED = 'message.unsubscribed';
    public const EVENT_REJECTED    = 'message.rejected';

    /**
     * Events acknowledged but deliberately ignored.
     *
     * delivered  - informational
     * deferred   - transient, QueenSMTP is still retrying; acting on it would
     *              bounce subscribers whose mail arrives ten minutes later
     * opened     - MailWizz does its own open tracking, consuming these would
     * clicked      double count every open and click
     */
    public const EVENTS_IGNORED = [
        'message.delivered',
        'message.deferred',
        'message.opened',
        'message.clicked',
    ];

    /**
     * Signature timestamp tolerance, seconds.
     */
    public const SIGNATURE_TOLERANCE = 300;

    /**
     * How long a processed event id is remembered, seconds.
     */
    public const DEDUPE_TTL = 172800;

    /**
     * Treat a spam complaint as a feedback loop action rather than a bounce.
     */
    public const COMPLAINT_IS_FBL = true;

    /**
     * Maximum length of a bounce message stored against a subscriber.
     */
    public const MAX_BOUNCE_MESSAGE = 250;

    /**
     * Entry point, registered through the dswh_process_map filter.
     *
     * @param DeliveryServer $server
     * @param Controller $controller
     *
     * @return void
     */
    public function process($server, $controller = null): void
    {
        try {
            $this->handleRequest($server);
        } catch (Throwable $e) {
            // A 500 here is expensive: QueenSMTP disables an endpoint that
            // returns no 2xx for 24 hours, and an activation ping that
            // fatals leaves the endpoint permanently inactive. So catch
            // everything, record it, and still answer 200.
            error_log(sprintf(
                'QUEENSMTP-WEBHOOK fatal in processor: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            try {
                $this->log($server, sprintf('fatal: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            } catch (Throwable $ignored) {
                // Nothing further we can do.
            }

            $this->respond(200);
        }
    }

    /**
     * The actual request handling, wrapped by process() above.
     *
     * @param DeliveryServer $server
     *
     * @return void
     */
    protected function handleRequest($server): void
    {
        $rawBody = (string)file_get_contents('php://input');

        // Authenticate before parsing. An unsigned request must never be able
        // to blacklist a subscriber.
        if (!$this->verifySignature($rawBody, (string)$server->password, $server)) {
            $this->respond(401);
            return;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $this->log($server, 'payload was not valid json, ignoring');
            $this->respond(200);
            return;
        }

        $eventId = isset($payload['id']) ? (string)$payload['id'] : '';
        $type    = isset($payload['type']) ? (string)$payload['type'] : '';
        $data    = !empty($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

        if ($type === self::EVENT_PING) {
            $this->log($server, 'webhook.ping received, endpoint is reachable');
            $this->respond(200);
            return;
        }

        // Deduplicate. QueenSMTP retries for up to 24 hours, so the same event
        // can legitimately arrive several times.
        if ($eventId !== '' && $this->alreadySeen($eventId)) {
            $this->respond(200);
            return;
        }

        if ($type === '' || in_array($type, self::EVENTS_IGNORED, true)) {
            $this->markSeen($eventId);
            $this->respond(200);
            return;
        }

        try {
            $this->log($server, sprintf('received %s: %s', $type, (string)json_encode($data)));
            $this->handleEvent($server, $type, $data);
        } catch (Exception $e) {
            // Answer 200 anyway. A retry would hit the same error, and 24
            // hours of non-2xx would get the endpoint disabled entirely.
            $this->log($server, sprintf('error handling %s: %s', $type, $e->getMessage()));
        }

        $this->markSeen($eventId);
        $this->respond(200);
    }

    /**
     * Route a single event.
     *
     * @param DeliveryServer $server
     * @param string $type
     * @param array $data
     *
     * @return void
     */
    protected function handleEvent($server, string $type, array $data): void
    {
        $messageId = '';
        foreach (['messageId', 'message_id', 'id'] as $key) {
            if (!empty($data[$key]) && is_scalar($data[$key])) {
                $messageId = (string)$data[$key];
                break;
            }
        }

        if ($messageId === '') {
            $this->log($server, sprintf('%s carried no message id, cannot attribute it', $type));
            return;
        }

        [$campaign, $subscriber] = $this->resolveSubscriber($server, $messageId);

        if (empty($campaign) || empty($subscriber)) {
            // Normal for transactional mail, which has no campaign behind it,
            // for a campaign sent by a different delivery server, and for rows
            // whose delivery log has been pruned.
            $this->log($server, sprintf('%s for messageId %s could not be attributed to a campaign subscriber on this server, ignoring', $type, $messageId));
            return;
        }

        $this->log($server, sprintf('%s for messageId %s attributed to subscriber #%d on campaign #%d', $type, $messageId, (int)$subscriber->subscriber_id, (int)$campaign->campaign_id));

        if ($type === self::EVENT_COMPLAINED) {
            $this->handleComplaint($campaign, $subscriber);
            return;
        }

        if ($type === self::EVENT_UNSUBSCRIBED) {
            $this->handleUnsubscribe($campaign, $subscriber);
            return;
        }

        if ($type === self::EVENT_BOUNCED || $type === self::EVENT_REJECTED) {
            $this->handleBounce($campaign, $subscriber, $type, $data);
            return;
        }

        $this->log($server, sprintf('unhandled event type %s', $type));
    }

    /**
     * Find the campaign and subscriber behind a QueenSMTP message id.
     *
     * Scoped to the delivery server that actually sent the message. MailWizz
     * rotates across several delivery servers within a single campaign, so a
     * message id alone is not enough to identify a row safely:
     *
     *  - A QueenSMTP webhook must never act on a row written by a different
     *    provider. Message id formats differ enough that a collision is
     *    unlikely, but "unlikely" is not a guarantee worth relying on when the
     *    consequence is blacklisting the wrong subscriber.
     *  - Where an operator runs SEVERAL QueenSMTP delivery servers on one API
     *    key, QueenSMTP delivers every event to every endpoint registered on
     *    that account. Without scoping, all of them attribute the same
     *    subscriber; the duplicate check in recordBounce() prevents duplicate
     *    rows, but the work is wasted. With scoping, only the server that sent
     *    the message acts on the event.
     *
     * server_id is nullable in mw_campaign_delivery_log, so a NULL row is still
     * accepted rather than silently dropped.
     *
     * @param DeliveryServer $server
     * @param string $messageId
     *
     * @return array [Campaign|null, ListSubscriber|null]
     */
    protected function resolveSubscriber($server, string $messageId): array
    {
        $criteria = new CDbCriteria();
        $criteria->addCondition(
            '`email_message_id` = :email_message_id AND `status` = :status ' .
            'AND (`server_id` = :server_id OR `server_id` IS NULL)'
        );
        $criteria->params = [
            'email_message_id' => $messageId,
            'status'           => CampaignDeliveryLog::STATUS_SUCCESS,
            'server_id'        => (int)$server->server_id,
        ];

        $deliveryLog = CampaignDeliveryLogHelper::findByCriteria($criteria);
        if (empty($deliveryLog)) {
            return [null, null];
        }

        /** @var Campaign|null $campaign */
        $campaign = Campaign::model()->findByPk((int)$deliveryLog->campaign_id);
        if (empty($campaign)) {
            return [null, null];
        }

        /** @var ListSubscriber|null $subscriber */
        $subscriber = ListSubscriber::model()->findByAttributes([
            'list_id'       => (int)$campaign->list_id,
            'subscriber_id' => (int)$deliveryLog->subscriber_id,
            'status'        => ListSubscriber::STATUS_CONFIRMED,
        ]);

        if (empty($subscriber)) {
            return [null, null];
        }

        return [$campaign, $subscriber];
    }

    /**
     * A spam complaint. Routed through the feedback loop handler so it obeys
     * whatever the operator has configured for FBL actions.
     *
     * @param Campaign $campaign
     * @param ListSubscriber $subscriber
     *
     * @return void
     */
    protected function handleComplaint($campaign, $subscriber): void
    {
        if (!self::COMPLAINT_IS_FBL) {
            $this->recordBounce($campaign, $subscriber, CampaignBounceLog::BOUNCE_INTERNAL, 'SPAM COMPLAINT');
            return;
        }

        /** @var OptionCronProcessFeedbackLoopServers $fbl */
        $fbl = container()->get(OptionCronProcessFeedbackLoopServers::class);
        $fbl->takeActionAgainstSubscriberWithCampaign($subscriber, $campaign);
    }

    /**
     * An unsubscribe recorded on the QueenSMTP side.
     *
     * This is expected to be rare: the List-Unsubscribe header we send points
     * directly at MailWizz and QueenSMTP passes it through unchanged, so
     * one-click unsubscribes normally reach MailWizz without ever involving
     * them. It can still happen when QueenSMTP attaches their own unsubscribe
     * to a message that carried no header.
     *
     * @param Campaign $campaign
     * @param ListSubscriber $subscriber
     *
     * @return void
     */
    protected function handleUnsubscribe($campaign, $subscriber): void
    {
        if ((string)$subscriber->status === ListSubscriber::STATUS_UNSUBSCRIBED) {
            return;
        }

        $subscriber->saveStatus(ListSubscriber::STATUS_UNSUBSCRIBED);

        $count = CampaignTrackUnsubscribe::model()->countByAttributes([
            'campaign_id'   => (int)$campaign->campaign_id,
            'subscriber_id' => (int)$subscriber->subscriber_id,
        ]);

        if (!empty($count)) {
            return;
        }

        $track = new CampaignTrackUnsubscribe();
        $track->campaign_id   = (int)$campaign->campaign_id;
        $track->subscriber_id = (int)$subscriber->subscriber_id;
        $track->note          = 'Unsubscribed via QueenSMTP';
        $track->save(false);
    }

    /**
     * A bounce or an asynchronous rejection.
     *
     * QueenSMTP states bounceType explicitly as "hard" or "soft", alongside
     * smtpCode and dsn, so unlike turboSMTP there is nothing to infer from
     * reply codes.
     *
     * message.rejected is recorded as an internal bounce: it means QueenSMTP
     * itself refused the message rather than a receiving server, so it says
     * nothing about whether the address is valid and must not blacklist it.
     *
     * @param Campaign $campaign
     * @param ListSubscriber $subscriber
     * @param string $type
     * @param array $data
     *
     * @return void
     */
    protected function handleBounce($campaign, $subscriber, string $type, array $data): void
    {
        $bounceType = CampaignBounceLog::BOUNCE_INTERNAL;

        if ($type === self::EVENT_BOUNCED) {
            $declared = isset($data['bounceType']) ? strtolower((string)$data['bounceType']) : '';

            if ($declared === 'hard') {
                $bounceType = CampaignBounceLog::BOUNCE_HARD;
            } elseif ($declared === 'soft') {
                $bounceType = CampaignBounceLog::BOUNCE_SOFT;
            } else {
                // Unknown value: treat as soft. Wrongly soft-bouncing a valid
                // address costs one retry; wrongly hard-bouncing it removes a
                // real subscriber permanently.
                $bounceType = CampaignBounceLog::BOUNCE_SOFT;
            }
        }

        $this->recordBounce($campaign, $subscriber, $bounceType, $this->buildBounceMessage($data));
    }

    /**
     * Compose a readable bounce reason from whichever fields are present.
     *
     * @param array $data
     *
     * @return string
     */
    protected function buildBounceMessage(array $data): string
    {
        $parts = [];

        // smtpCode has been observed carrying nonsense: a Gmail 550-5.1.1
        // "account does not exist" bounce arrived with smtpCode "217", which
        // is not an assigned SMTP reply code at all -- 2xx means success. The
        // dsn on the same payload was correct. So only accept a value that
        // actually looks like a failure reply, and rely on dsn otherwise,
        // rather than storing a wrong code against the subscriber.
        if (!empty($data['smtpCode']) && preg_match('/^[45]\d\d$/', (string)$data['smtpCode'])) {
            $parts[] = (string)$data['smtpCode'];
        }

        foreach (['code', 'dsn'] as $key) {
            if (!empty($data[$key]) && is_scalar($data[$key])) {
                $parts[] = (string)$data[$key];
            }
        }

        foreach (['reason', 'error', 'message', 'description'] as $key) {
            if (!empty($data[$key]) && is_scalar($data[$key])) {
                $parts[] = (string)$data[$key];
                break;
            }
        }

        $message = trim(implode(' ', $parts));

        if ($message === '') {
            $message = 'BOUNCED BACK';
        }

        // SMTP transcripts arrive multi-line. Flatten and cap so the value
        // fits the column and stays readable in the UI.
        $message = trim((string)preg_replace('/\s+/', ' ', $message));

        if (strlen($message) > self::MAX_BOUNCE_MESSAGE) {
            $message = substr($message, 0, self::MAX_BOUNCE_MESSAGE);
        }

        return $message;
    }

    /**
     * Write a bounce log entry, blacklisting on a hard bounce.
     *
     * @param Campaign $campaign
     * @param ListSubscriber $subscriber
     * @param string $bounceType
     * @param string $message
     *
     * @return void
     */
    protected function recordBounce($campaign, $subscriber, string $bounceType, string $message): void
    {
        // Never record the same subscriber twice for the same campaign.
        $count = CampaignBounceLog::model()->countByAttributes([
            'campaign_id'   => (int)$campaign->campaign_id,
            'subscriber_id' => (int)$subscriber->subscriber_id,
        ]);

        if (!empty($count)) {
            return;
        }

        $bounceLog = new CampaignBounceLog();
        $bounceLog->campaign_id   = (int)$campaign->campaign_id;
        $bounceLog->subscriber_id = (int)$subscriber->subscriber_id;
        $bounceLog->message       = $message;
        $bounceLog->bounce_type   = $bounceType;
        $bounceLog->save();

        if ($bounceLog->bounce_type === CampaignBounceLog::BOUNCE_HARD) {
            $subscriber->addToBlacklist((string)$bounceLog->message);
        }
    }

    /**
     * Verify X-QueenSMTP-Signature.
     *
     * Header format: t=<unix>,v1=<hex>
     * Two v1 values appear during a 24 hour secret rotation, so every
     * candidate is checked.
     *
     * @param string $rawBody
     * @param string $secret
     * @param DeliveryServer $server
     *
     * @return bool
     */
    protected function verifySignature(string $rawBody, string $secret, $server): bool
    {
        if ($secret === '') {
            $this->log($server, 'no webhook signing secret is configured on this delivery server, rejecting. Paste the secret shown when you created the endpoint into the "Webhook signing secret" field.');
            return false;
        }

        $header = (string)($_SERVER['HTTP_X_QUEENSMTP_SIGNATURE'] ?? '');
        if ($header === '') {
            $this->log($server, 'request carried no X-QueenSMTP-Signature header, rejecting');
            return false;
        }

        $timestamp  = '';
        $candidates = [];

        foreach (explode(',', $header) as $part) {
            $part = trim($part);

            if (strpos($part, 't=') === 0) {
                $timestamp = substr($part, 2);
                continue;
            }

            if (strpos($part, 'v1=') === 0) {
                $candidates[] = substr($part, 3);
            }
        }

        if ($timestamp === '' || empty($candidates)) {
            $this->log($server, sprintf('could not parse signature header: %s', $header));
            return false;
        }

        // Replay protection.
        $age = abs(time() - (int)$timestamp);
        if ($age > self::SIGNATURE_TOLERANCE) {
            $this->log($server, sprintf(
                'signature timestamp is %d seconds old, outside the %d second tolerance, rejecting. If this happens on retried deliveries only, the server clock may be wrong.',
                $age,
                self::SIGNATURE_TOLERANCE
            ));
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        foreach ($candidates as $candidate) {
            if (hash_equals($expected, trim($candidate))) {
                return true;
            }
        }

        $this->log($server, 'signature did not match, rejecting. Check the secret matches the endpoint in your QueenSMTP dashboard.');

        return false;
    }

    /**
     * @param string $eventId
     *
     * @return bool
     */
    protected function alreadySeen(string $eventId): bool
    {
        if ($eventId === '') {
            return false;
        }

        return (bool)cache()->get($this->dedupeKey($eventId));
    }

    /**
     * @param string $eventId
     *
     * @return void
     */
    protected function markSeen(string $eventId): void
    {
        if ($eventId === '') {
            return;
        }

        cache()->set($this->dedupeKey($eventId), 1, self::DEDUPE_TTL);
    }

    /**
     * @param string $eventId
     *
     * @return string
     */
    protected function dedupeKey(string $eventId): string
    {
        return 'queensmtp.webhook.' . sha1($eventId);
    }

    /**
     * Append to a dedicated log file. Yii::log at info level is normally
     * filtered out by MailWizz's production log routes, so this writes
     * directly and does not depend on log configuration.
     *
     * The runtime directory is resolved defensively. An earlier version used
     * an MW_ROOT_PATH constant that is not guaranteed to be defined, which
     * fatalled here and turned every webhook delivery into an HTTP 500 --
     * including the activation ping. Logging must never be able to break the
     * response.
     *
     * @param DeliveryServer $server
     * @param string $message
     *
     * @return void
     */
    protected function log($server, string $message): void
    {
        $line = sprintf(
            "[%s] QUEENSMTP-WEBHOOK server=#%d %s\n",
            date('Y-m-d H:i:s'),
            (int)$server->server_id,
            $message
        );

        try {
            $dir = '';

            // Preferred: the alias MailWizz always registers.
            $alias = Yii::getPathOfAlias('common.runtime');
            if (!empty($alias) && is_dir((string)$alias)) {
                $dir = (string)$alias;
            }

            if ($dir === '' && defined('MW_ROOT_PATH')) {
                $candidate = MW_ROOT_PATH . '/apps/common/runtime';
                if (is_dir($candidate)) {
                    $dir = $candidate;
                }
            }

            if ($dir === '') {
                $dir = (string)app()->getRuntimePath();
            }

            if ($dir !== '' && is_dir($dir)) {
                if (@file_put_contents($dir . '/queensmtp-webhook.log', $line, FILE_APPEND | LOCK_EX) !== false) {
                    return;
                }
            }
        } catch (Throwable $e) {
            // Fall through to error_log below.
        }

        error_log($line);
    }

    /**
     * Emit the response status and stop.
     *
     * Deliberately does NOT call request()->sendHeaders(). That method does
     * not exist on MailWizz's FrontendHttpRequest and calling it throws
     * "FrontendHttpRequest and its behaviors do not have a method or closure
     * named sendHeaders", which surfaces as an HTTP 500 -- exactly the thing
     * that must never happen here, since QueenSMTP disables an endpoint that
     * returns no 2xx for 24 hours.
     *
     * http_response_code() is plain PHP and needs no framework support.
     *
     * @param int $code
     *
     * @return void
     */
    protected function respond(int $code): void
    {
        if (!headers_sent()) {
            http_response_code($code);
        }

        app()->end();
    }
}
