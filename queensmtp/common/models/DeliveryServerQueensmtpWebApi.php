<?php declare(strict_types=1);
if (!defined('MW_PATH')) {
    exit('No direct script access allowed');
}

/**
 * DeliveryServerQueensmtpWebApi
 *
 * Delivery server implementation for the QueenSMTP Email API (v1).
 *
 * Notes on the provider, all taken from the dashboard API documentation:
 *
 *  - Base url is https://queensmtp.com/v1 -- NOT api.queensmtp.com. The public
 *    marketing pages state the wrong host, do not trust them.
 *  - Auth is a real bearer token: "Authorization: Bearer <key>".
 *  - One recipient per request. That suits MailWizz, which already sends
 *    per subscriber.
 *  - "headers" is a flat map. Allowed: any List-* header, Precedence,
 *    In-Reply-To, References, Importance, and any X-* header. Maximum 25
 *    headers, 900 characters per value. Identity headers (From, To, Cc, Bcc,
 *    Subject, Message-ID, DKIM-Signature) are set by QueenSMTP and are
 *    rejected with 400 if supplied.
 *  - Feedback-ID is NOT on the documented allow list. It is neither List-*
 *    nor X-*, so this class drops it. See VERIFY note below.
 *  - "isBulk" classifies the message. See the isBulk section below, it is the
 *    one genuinely provider specific rule in this integration.
 *
 * Send response is {"success":true,"data":{"id":"<24 char hex>", ...}}. The id
 * is stored by MailWizz as email_message_id, which is what a future webhook
 * handler will correlate against.
 *
 * VERIFY -- items not covered by the documentation, pending empirical testing
 * or a support ticket:
 *   1. Whether Feedback-ID is silently dropped or returns 400. Currently we
 *      never send it.
 *   2. The attachment field name. Nothing is documented beyond a 25 MB message
 *      size limit, so attachments are currently NOT sent, and are logged.
 *   3. The error response envelope. Only 400/422 and the single code string
 *      UNSUBSCRIBE_HEADER_REQUIRED are documented, so error parsing here is
 *      deliberately defensive and deactivation is conservative.
 *   4. The entire webhook payload schema.
 *
 * @copyright 2026 M. Ajmal Mughal
 * @license   MIT
 */
class DeliveryServerQueensmtpWebApi extends DeliveryServer
{
    /**
     * Endpoint used for sending.
     */
    public const SEND_URL = 'https://queensmtp.com/v1/send';

    /**
     * Message status polling, GET {STATUS_URL}/{messageId}.
     */
    public const STATUS_URL = 'https://queensmtp.com/v1/messages';

    /**
     * Header names accepted verbatim by QueenSMTP, lowercased.
     * Anything starting with "list-" or "x-" is also accepted, handled
     * separately in filterHeaders().
     *
     * Precedence is deliberately absent even though QueenSMTP allows it:
     * they set Precedence: bulk themselves based on isBulk, and sending our
     * own value risks contradicting that flag.
     */
    public const ALLOWED_HEADERS = [
        'in-reply-to',
        'references',
        'importance',
    ];

    /**
     * Header names QueenSMTP sets itself. Dropped SILENTLY -- these are not
     * user error, so warning about them on every single message would put a
     * misleading line in the delivery log for every subscriber in a campaign.
     *
     * The first group is rejected with 400 if supplied. Feedback-ID is
     * different: QueenSMTP generates its own, observed in delivered mail as
     * "<sender>:<account>:<mkt|txn>:qsmtp", and it is inside their DKIM
     * signature. MailWizz always sets one, so anything we sent would be
     * replaced anyway. Same behaviour as turboSMTP.
     */
    public const REJECTED_HEADERS = [
        'from',
        'to',
        'cc',
        'bcc',
        'subject',
        'message-id',
        'dkim-signature',
        'reply-to',
        'sender',
        'return-path',
        'feedback-id',
        'precedence',
    ];

    /**
     * Provider limits on the headers map.
     */
    public const MAX_HEADERS      = 25;
    public const MAX_HEADER_VALUE = 900;

    /**
     * Display name limit. Over this, QueenSMTP returns 400.
     */
    public const MAX_FROM_NAME = 78;

    /**
     * @var string
     */
    protected $serverType = 'queensmtp-web-api';

    /**
     * @var string
     */
    protected $_initStatus;

    /**
     * @var string
     */
    protected $_providerUrl = 'https://queensmtp.com/';

    /**
     * @return array
     */
    public function rules()
    {
        $rules = [
            ['username', 'required'],
            ['username', 'length', 'max' => 255],
            ['password', 'length', 'max' => 255],
        ];

        return CMap::mergeArray($rules, parent::rules());
    }

    /**
     * @return array
     */
    public function attributeLabels()
    {
        $labels = [
            'username' => t('servers', 'Api key'),
            'password' => t('servers', 'Webhook signing secret'),
        ];

        return CMap::mergeArray(parent::attributeLabels(), $labels);
    }

    /**
     * @return array
     */
    public function attributeHelpTexts()
    {
        $texts = [
            'username'   => t('servers', 'Your QueenSMTP API key, found in your QueenSMTP dashboard. It is sent as a bearer token.'),
            'password'   => t('servers', 'The signing secret for your webhook endpoint, shown once when you create the endpoint under Developer API > Webhooks. Required: webhook requests that cannot be verified against this secret are rejected, so bounces and complaints will not be recorded without it.'),
            'from_email' => t('servers', 'The domain of this email address must be added AND verified as a sending domain in your QueenSMTP account, otherwise all sending will fail.'),
        ];

        return CMap::mergeArray(parent::attributeHelpTexts(), $texts);
    }

    /**
     * @return array
     */
    public function attributePlaceholders()
    {
        $placeholders = [
            'username' => 'your-queensmtp-api-key',
            'password' => 'your-webhook-secret',
        ];

        return CMap::mergeArray(parent::attributePlaceholders(), $placeholders);
    }

    /**
     * @param string $className
     * @return DeliveryServer
     */
    public static function model($className = self::class)
    {
        /** @var DeliveryServer $model */
        $model = parent::model($className);

        return $model;
    }

    /**
     * @param array $params
     * @return array
     * @throws CException
     */
    public function send(array $params = []): array
    {
        /** @var array $params */
        $params = (array)hooks()->applyFilters('delivery_server_before_send_email', $this->getParamsArray($params), $this);

        if (!ArrayHelper::hasKeys($params, ['from', 'to', 'subject', 'body'])) {
            return [];
        }

        [$toEmail]              = $this->getMailer()->findEmailAndName($params['to']);
        [$fromEmail, $fromName] = $this->getMailer()->findEmailAndName($params['from']);

        if (!empty($params['fromName'])) {
            $fromName = $params['fromName'];
        }

        $replyToEmail = null;
        if (!empty($params['replyTo'])) {
            [$replyToEmail] = $this->getMailer()->findEmailAndName($params['replyTo']);
        }

        $sent = [];

        try {
            $sendParams = [
                'from'    => $fromEmail,
                'to'      => $toEmail,
                'subject' => (string)$params['subject'],
            ];

            // fromName is rejected with 400 over 78 characters, or if it
            // contains a url, a line break, or a foreign email address.
            $fromName = $this->sanitizeFromName((string)$fromName);
            if ($fromName !== '') {
                $sendParams['fromName'] = $fromName;
            }

            if (!empty($replyToEmail)) {
                $sendParams['replyTo'] = $replyToEmail;
            }

            // Bodies. QueenSMTP accepts html and/or text.
            $onlyPlainText = !empty($params['onlyPlainText']) && $params['onlyPlainText'] === true;

            if ($onlyPlainText) {
                $sendParams['text'] = !empty($params['plainText'])
                    ? (string)$params['plainText']
                    : (string)CampaignHelper::htmlToText((string)$params['body']);
            } else {
                $sendParams['html'] = (string)$params['body'];

                if (!empty($params['plainText'])) {
                    $sendParams['text'] = (string)$params['plainText'];
                }
            }

            // Headers, filtered to what QueenSMTP will accept.
            $headers = [];
            if (!empty($params['headers'])) {
                $headers = $this->filterHeaders($this->parseHeadersIntoKeyValue($params['headers']));

                if (!empty($headers)) {
                    $sendParams['headers'] = $headers;
                }
            }

            // isBulk. QueenSMTP enforces a strict pairing:
            //   isBulk true  without List-Unsubscribe -> 400
            //   isBulk false with    List-Unsubscribe -> 400
            // Deriving the flag from the header itself makes both impossible.
            // It is also exactly the rule QueenSMTP applies to its own SMTP
            // path, so behaviour is consistent across both transports.
            $sendParams['isBulk'] = $this->hasUnsubscribeHeader($headers);

            // A campaign with no List-Unsubscribe goes out as transactional and
            // may be held with 422 UNSUBSCRIBE_HEADER_REQUIRED by their content
            // scanner. Warn now, so the cause is obvious in the delivery log.
            if (!$sendParams['isBulk'] && !empty($params['campaignUid'])) {
                $this->getMailer()->addLog(
                    'QueenSMTP: this campaign carries no List-Unsubscribe header, so it is being sent as transactional. ' .
                    'QueenSMTP may hold it with 422 UNSUBSCRIBE_HEADER_REQUIRED. Enable the unsubscribe header in MailWizz, ' .
                    'or configure a tracking domain in QueenSMTP so they attach one automatically.'
                );
            }

            // Attachments. CONFIRMED by QueenSMTP support: the API does not
            // accept attachments at all. They are supported only on their SMTP
            // path, on paid plans, capped at 1 MB and 2 files per message.
            // There is no field to send and nothing to implement here.
            if (!$onlyPlainText && !empty($params['attachments']) && is_array($params['attachments'])) {
                $this->getMailer()->addLog(sprintf(
                    'QueenSMTP: %d attachment(s) were NOT sent. The QueenSMTP API does not accept attachments; ' .
                    'they are available only over their SMTP transport. This message was delivered without them.',
                    count(array_unique($params['attachments']))
                ));
            }

            $result = $this->apiRequest('POST', self::SEND_URL, $sendParams);
            $data   = (array)$result['data'];

            $messageId = '';
            if (!empty($data['data']['id'])) {
                $messageId = (string)$data['data']['id'];
            } elseif (!empty($data['id'])) {
                // Defensive: tolerate an unwrapped response shape.
                $messageId = (string)$data['id'];
            }

            if ($result['success'] && $messageId !== '') {
                $this->getMailer()->addLog('OK');
                $sent = ['message_id' => $messageId];
            } else {
                $this->handleApiError($data, (int)$result['httpCode'], (string)$result['error']);
            }
        } catch (Exception $e) {
            $this->getMailer()->addLog($e->getMessage());
        }

        if ($sent) {
            $this->logUsage();
        }

        hooks()->doAction('delivery_server_after_send_email', $params, $this, $sent);

        return (array)$sent;
    }

    /**
     * Keep only headers QueenSMTP will accept, and sanitise their values.
     *
     * A rejected header name causes a 400 for the entire request, which would
     * fail every message in a campaign, so anything not on the allow list is
     * dropped and logged rather than sent.
     *
     * @param array $headers
     *
     * @return array
     */
    protected function filterHeaders(array $headers): array
    {
        $out     = [];
        $dropped = [];

        foreach ($headers as $name => $value) {
            $name = trim((string)$name);

            if ($name === '' || $value === null || $value === '') {
                continue;
            }

            $lower = strtolower($name);

            // Identity headers: QueenSMTP sets these and 400s if we send them.
            if (in_array($lower, self::REJECTED_HEADERS, true)) {
                continue;
            }

            $allowed = in_array($lower, self::ALLOWED_HEADERS, true)
                || strpos($lower, 'list-') === 0
                || strpos($lower, 'x-') === 0;

            if (!$allowed) {
                $dropped[] = $name;
                continue;
            }

            // Strip CR/LF to avoid header injection.
            $clean = trim((string)preg_replace('/[\r\n]+/', ' ', (string)$value));

            if ($clean === '') {
                continue;
            }

            // Truncating a List-Unsubscribe would produce a broken url, so an
            // over-long value is dropped rather than cut.
            if (strlen($clean) > self::MAX_HEADER_VALUE) {
                $dropped[] = $name . ' (over ' . self::MAX_HEADER_VALUE . ' chars)';
                continue;
            }

            if (count($out) >= self::MAX_HEADERS) {
                $dropped[] = $name . ' (over ' . self::MAX_HEADERS . ' header limit)';
                continue;
            }

            $out[$name] = $clean;
        }

        if (!empty($dropped)) {
            $this->getMailer()->addLog(sprintf(
                'QueenSMTP: dropped unsupported header(s): %s. QueenSMTP accepts List-*, X-*, In-Reply-To, References and Importance only.',
                implode(', ', $dropped)
            ));
        }

        return $out;
    }

    /**
     * True when the filtered header set carries a List-Unsubscribe.
     *
     * @param array $headers
     *
     * @return bool
     */
    protected function hasUnsubscribeHeader(array $headers): bool
    {
        foreach (array_keys($headers) as $name) {
            if (strtolower((string)$name) === 'list-unsubscribe') {
                return true;
            }
        }

        return false;
    }

    /**
     * QueenSMTP rejects a fromName containing a url, a line break, an email
     * address other than the from address, or over 78 characters. Rather than
     * let a 400 fail the send, clean it here and fall back to omitting it.
     *
     * @param string $fromName
     *
     * @return string
     */
    protected function sanitizeFromName(string $fromName): string
    {
        $fromName = trim((string)preg_replace('/[\r\n]+/', ' ', $fromName));

        if ($fromName === '') {
            return '';
        }

        // A url or a stray email address in the display name reads as
        // impersonation to their scanner.
        if (preg_match('/https?:\/\/|www\.|@/i', $fromName)) {
            $this->getMailer()->addLog(sprintf(
                'QueenSMTP: the from name "%s" contains a url or an email address, which QueenSMTP rejects with 400. Sending without a display name.',
                $fromName
            ));

            return '';
        }

        if (strlen($fromName) > self::MAX_FROM_NAME) {
            $fromName = trim(substr($fromName, 0, self::MAX_FROM_NAME));
        }

        return $fromName;
    }

    /**
     * Set the server inactive and record why, in as many places as are
     * available, because a server that stops sending with no visible reason is
     * far worse than a noisy log.
     *
     *  1. A dedicated file, apps/common/runtime/queensmtp-errors.log
     *  2. The Yii application log at ERROR level, which production log routes
     *     do keep (unlike INFO)
     *  3. The customer's message inbox in the MailWizz UI, when the server
     *     belongs to a customer
     *
     * The per-subscriber reason is already written to the campaign delivery
     * log by send(), so the Sent emails report shows it too.
     *
     * @param string $reason
     * @return void
     */
    protected function deactivate(string $reason): void
    {
        if ($this->status === self::STATUS_INACTIVE) {
            return;
        }

        $this->status = self::STATUS_INACTIVE;
        $this->save(false);

        $line = sprintf(
            'QueenSMTP delivery server #%d (%s) was deactivated: %s',
            (int)$this->server_id,
            (string)$this->name,
            $reason
        );

        Yii::log($line, CLogger::LEVEL_ERROR);

        // Dedicated file. Resolved defensively so a missing path or an
        // unwritable directory can never break a send.
        try {
            $dir = (string)Yii::getPathOfAlias('common.runtime');

            if ($dir === '' || !is_dir($dir)) {
                $dir = (string)app()->getRuntimePath();
            }

            if ($dir !== '' && is_dir($dir)) {
                @file_put_contents(
                    $dir . '/queensmtp-errors.log',
                    sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $line),
                    FILE_APPEND | LOCK_EX
                );
            }
        } catch (Throwable $e) {
            // Logging must never break sending.
        }

        $this->notifyCustomer($reason);
    }

    /**
     * Put the reason in the customer's message inbox, where it appears in the
     * MailWizz UI without needing shell access.
     *
     * Wrapped defensively: backend owned servers have no customer, and the
     * message model is not worth a fatal error if it is unavailable.
     *
     * @param string $reason
     * @return void
     */
    protected function notifyCustomer(string $reason): void
    {
        try {
            if (empty($this->customer_id) || !class_exists('CustomerMessage', false)) {
                return;
            }

            $message = new CustomerMessage();
            $message->customer_id = (int)$this->customer_id;
            $message->title       = 'Delivery server disabled: ' . (string)$this->name;
            $message->message     = sprintf(
                'Your QueenSMTP delivery server "%s" (#%d) has been disabled automatically. %s ' .
                'Sending will not resume until you review the cause and set the server back to active.',
                (string)$this->name,
                (int)$this->server_id,
                $reason
            );
            $message->save();
        } catch (Throwable $e) {
            Yii::log('QueenSMTP: could not create customer message: ' . $e->getMessage(), CLogger::LEVEL_WARNING);
        }
    }

    /**
     * Decide what to do about a failed send, then throw so send() logs it.
     *
     * The error envelope is undocumented, so several shapes are probed and the
     * deactivation rules are deliberately conservative: only an outright
     * auth failure takes the server offline. Everything else is treated as
     * message level or transient, because wrongly deactivating a server stops
     * an entire campaign.
     *
     * @param array $data
     * @param int $httpCode
     * @param string $transportError
     * @return void
     * @throws Exception
     */
    protected function handleApiError(array $data, int $httpCode, string $transportError = ''): void
    {
        // Transport level failure (timeout, DNS, connection reset). Transient,
        // keep the server enabled and let MailWizz retry the subscriber.
        if (!empty($transportError)) {
            throw new Exception(sprintf('QueenSMTP connection error: %s', $transportError));
        }

        // Since September 2026 every non-2xx from /v1 returns:
        //   { success, error, code, retryable, retryAfter?, strike?, field? }
        // The older probing of alternative key names is kept as a fallback so
        // an account still on the previous behaviour degrades gracefully
        // rather than losing the reason text entirely.
        $code    = $this->extractErrorValue($data, ['code', 'errorCode', 'error_code']);
        $message = $this->extractErrorValue($data, ['error', 'message', 'detail', 'reason']);

        if ($message === '') {
            $message = 'Unknown QueenSMTP API error';
        }

        $label = $code !== '' ? $code : (string)$httpCode;

        $strike = !empty($data['strike']);

        // retryable is authoritative when present. array_key_exists rather
        // than isset, because a literal false is meaningful here.
        $hasRetryable = array_key_exists('retryable', $data);
        $retryable    = $hasRetryable ? !empty($data['retryable']) : null;

        if (!empty($data['field'])) {
            $message .= sprintf(' (field: %s)', (string)$data['field']);
        }

        // 401/403: the key is wrong, revoked, or blocked by an IP allowlist or
        // from-domain restriction, both of which QueenSMTP now enforces.
        // Nothing will send until a human fixes it.
        if ($httpCode === 401 || $httpCode === 403) {
            $this->deactivate(sprintf('[%s] %s', $label, $message));
            throw new Exception(sprintf('QueenSMTP authentication failed, server has been deactivated: [%s] %s', $label, $message));
        }

        // ANY content refusal stops the server.
        //
        // A content verdict is passed on the template, not the recipient, so
        // every remaining subscriber in the campaign receives the identical
        // refusal. QueenSMTP state that a content refusal is permanent for
        // that message and that resending unchanged will not help, so
        // continuing means thousands of futile API calls, every one counting
        // against the account's standing.
        //
        // content_rejected records a strike; content_flagged does not. That
        // difference changes how alarming the message should be, not whether
        // carrying on is worthwhile, so both stop here.
        //
        // UNSUBSCRIBE_HEADER_REQUIRED is handled further down and does NOT
        // stop the server: it is strike free and fixable per message.
        $isContentRefusal = $strike
            || strcasecmp($code, 'content_rejected') === 0
            || strcasecmp($code, 'content_flagged') === 0
            || strcasecmp($code, 'content_blocked') === 0;

        if ($isContentRefusal && stripos($code, 'UNSUBSCRIBE_HEADER_REQUIRED') === false) {
            $strikeNote = $strike
                ? 'A strike was recorded against your sending domain. '
                : 'No strike was recorded. ';

            $this->deactivate(sprintf('QueenSMTP refused the campaign content. %s[%s] %s', $strikeNote, $label, $message));

            throw new Exception(sprintf(
                'QueenSMTP refused this message on content. %sThe server has been deactivated: the verdict ' .
                'applies to the template, so every remaining subscriber would be refused identically. ' .
                'Revise the content, then set the server back to active. [%s] %s',
                $strikeNote,
                $label,
                $message
            ));
        }

        // Rate limited. retryAfter is in seconds when supplied.
        if ($httpCode === 429 || strcasecmp($code, 'rate_limited') === 0) {
            $after = !empty($data['retryAfter']) ? sprintf(' Retry after %ds.', (int)$data['retryAfter']) : '';
            throw new Exception(sprintf('QueenSMTP rate limit hit, this message will be retried: [%s] %s%s', $label, $message, $after));
        }

        // UNSUBSCRIBE_HEADER_REQUIRED is called out before the generic
        // retryable handling, because it is strike free, fixable, and the log
        // line should say HOW to fix it. It also carries retryable: false, so
        // leaving it below would let the generic branch swallow it.
        if (stripos($code, 'UNSUBSCRIBE_HEADER_REQUIRED') !== false
            || stripos($message, 'unsubscribe header') !== false) {
            throw new Exception(
                'QueenSMTP held this message: it reads as marketing but carries no List-Unsubscribe header. ' .
                'Enable the unsubscribe header in MailWizz, or set up a tracking domain in QueenSMTP. ' .
                sprintf('[%s] %s', $label, $message)
            );
        }

        // Explicitly permanent: no retry will help. Skip this subscriber and
        // keep the campaign running.
        if ($retryable === false) {
            throw new Exception(sprintf('QueenSMTP permanently rejected this message: [%s] %s', $label, $message));
        }

        // Explicitly transient.
        if ($retryable === true) {
            throw new Exception(sprintf('QueenSMTP temporarily rejected this message, it will be retried: [%s] %s', $label, $message));
        }


        if ($httpCode === 400 || $httpCode === 422) {
            throw new Exception(sprintf('QueenSMTP rejected this message: [%s] %s', $label, $message));
        }

        // 5xx and anything else: transient, keep going.
        throw new Exception(sprintf('QueenSMTP error: [%s] %s', $label, $message));
    }

    /**
     * Pull the first present key out of an undocumented error body, looking
     * both at the top level and inside a "data" or "error" wrapper.
     *
     * @param array $data
     * @param array $keys
     *
     * @return string
     */
    protected function extractErrorValue(array $data, array $keys): string
    {
        $scopes = [$data];

        foreach (['data', 'error'] as $wrapper) {
            if (!empty($data[$wrapper]) && is_array($data[$wrapper])) {
                $scopes[] = $data[$wrapper];
            }
        }

        foreach ($scopes as $scope) {
            foreach ($keys as $key) {
                if (isset($scope[$key]) && is_scalar($scope[$key]) && (string)$scope[$key] !== '') {
                    return (string)$scope[$key];
                }
            }
        }

        return '';
    }

    /**
     * Perform a request against the QueenSMTP API.
     *
     * Returns:
     *  [
     *      'success'  => bool,
     *      'httpCode' => int,
     *      'data'     => array,
     *      'error'    => string, // transport level error only
     *  ]
     *
     * @param string $method
     * @param string $url
     * @param array|null $body
     * @return array
     */
    protected function apiRequest(string $method, string $url, ?array $body = null): array
    {
        $payload = null;
        $headers = [
            'Authorization: Bearer ' . (string)$this->username,
            'Accept: application/json',
        ];

        if ($body !== null) {
            $payload   = (string)json_encode($body);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($payload);
        }

        // Base timeout, extended for large payloads.
        $timeout = 30;
        if ($payload !== null) {
            $timeout += (int)floor(strlen($payload) / 1048576) * 20;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = (string)curl_error($ch);
        curl_close($ch);

        $data = [];
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                $data = ['error' => substr($response, 0, 500)];
            }
        }

        // QueenSMTP wraps success in a boolean "success" key. Require it, but
        // tolerate its absence on a 2xx in case the envelope differs.
        $ok = empty($error) && $httpCode >= 200 && $httpCode < 300;
        if ($ok && array_key_exists('success', $data)) {
            $ok = !empty($data['success']);
        }

        return [
            'success'  => $ok,
            'httpCode' => $httpCode,
            'data'     => $data,
            'error'    => $error,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getParamsArray(array $params = []): array
    {
        $params['transport'] = $this->serverType;

        return parent::getParamsArray($params);
    }

    /**
     * @inheritDoc
     */
    public function getFormFieldsDefinition(array $fields = []): array
    {
        return parent::getFormFieldsDefinition(CMap::mergeArray([
            'hostname'                => null,
            'port'                    => null,
            'protocol'                => null,
            'timeout'                 => null,
            'signing_enabled'         => null,
            'max_connection_messages' => null,
            'bounce_server_id'        => null,
            'force_sender'            => null,
        ], $fields));
    }

    /**
     * @return void
     */
    protected function afterConstruct()
    {
        parent::afterConstruct();
        $this->_initStatus = $this->status;
        $this->hostname    = 'queensmtp.com';
    }

    /**
     * @return void
     */
    protected function afterFind()
    {
        $this->_initStatus = $this->status;
        parent::afterFind();
    }
}
