<?php
namespace App\Services;

use App\Database\Database;
use DomainException;
use InvalidArgumentException;
use PDO;

final class ContactMessageService
{
    /**
     * List contact inquiries with optional status or keyword filters.
     */
    public static function list(array $filters = []): array
    {
        $pdo = Database::getInstance();
        $sql = "SELECT * FROM contact_messages WHERE 1=1";
        $params = [];

        if (!empty($filters['status']) && in_array($filters['status'], ['new', 'read', 'replied', 'in_progress', 'resolved', 'archived'], true)) {
            $sql .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (name LIKE :s1 OR email LIKE :s2 OR phone LIKE :s3 OR subject LIKE :s4 OR message LIKE :s5)";
            $term = '%' . trim($filters['search']) . '%';
            $params[':s1'] = $term;
            $params[':s2'] = $term;
            $params[':s3'] = $term;
            $params[':s4'] = $term;
            $params[':s5'] = $term;
        }

        $sql .= " ORDER BY created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get contact message by ID.
     */
    public static function get(int $id): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM contact_messages WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get count of new/unprocessed contact messages.
     */
    public static function getNewCount(): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Update status (e.g. mark in_progress or archived).
     */
    public static function updateStatus(int $id, string $status, int $actorId, string $ip): void
    {
        if (!in_array($status, ['new', 'read', 'replied', 'in_progress', 'resolved', 'archived'], true)) {
            throw new InvalidArgumentException("Invalid message status: '{$status}'.");
        }

        $msg = self::get($id);
        if (!$msg) {
            throw new DomainException("Contact message not found.");
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE contact_messages SET status = :status WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $id]);

        AuditLogger::log($actorId, 'message_status_update', 'contact_message', $id, [
            'previous_status' => $msg['status'],
            'new_status'      => $status,
            'subject'         => $msg['subject'],
            'email'           => $msg['email'],
        ], $ip);
    }

    /**
     * Send a text-only reply to customer and mark message as resolved.
     */
    public static function reply(int $id, string $replyBody, int $actorId, string $ip): void
    {
        $replyBody = trim($replyBody);
        if ($replyBody === '') {
            throw new InvalidArgumentException("Reply message body cannot be empty.");
        }

        $msg = self::get($id);
        if (!$msg) {
            throw new DomainException("Contact message not found.");
        }

        $supportEmail = setting('support_email', 'support@kennedycheroben.co.ke');
        $storeName = setting('store_name', 'ShopWise Kenya');

        // Clean subject: prepend Re: and sanitize CRLF
        $subject = 'Re: ' . trim(str_replace(["\r", "\n"], ' ', $msg['subject']));

        // Clean body: no HTML accepted from admin; safely escaped for HTML email
        $cleanText = str_replace(["\r\n", "\r"], "\n", $replyBody);
        $escapedHtmlText = nl2br(htmlspecialchars($cleanText, ENT_QUOTES, 'UTF-8'));

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #1e293b; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px;">
        <h2 style="color: #0f172a; margin-top: 0;">{$storeName} Support</h2>
        <p>Dear {$msg['name']},</p>
        <p>Thank you for reaching out to us. Here is our response to your inquiry regarding <strong>{$msg['subject']}</strong>:</p>
        <div style="background: #f8fafc; border-left: 4px solid #ea580c; padding: 16px; margin: 20px 0; border-radius: 4px;">
            {$escapedHtmlText}
        </div>
        <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">
        <p style="font-size: 13px; color: #64748b;">
            Your original inquiry:<br>
            <em>"{$msg['message']}"</em>
        </p>
        <p style="font-size: 13px; color: #64748b; margin-bottom: 0;">
            ShopWise Kenya Customer Support Team &bull; <a href="mailto:{$supportEmail}" style="color: #ea580c;">{$supportEmail}</a>
        </p>
    </div>
</body>
</html>
HTML;

        $textBody = "Dear {$msg['name']},\n\n" .
                    "Thank you for contacting {$storeName}. Here is our response to your inquiry regarding \"{$msg['subject']}\":\n\n" .
                    "{$cleanText}\n\n" .
                    "---\n" .
                    "Your original inquiry:\n" .
                    "\"{$msg['message']}\"\n\n" .
                    "ShopWise Kenya Customer Support Team ({$supportEmail})\n";

        $mailer = new MailerService();
        $mailer->queueEmail($msg['email'], $msg['name'], $subject, $htmlBody, $textBody);

        // Mark as replied
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE contact_messages SET status = 'replied' WHERE id = :id");
        $stmt->execute([':id' => $id]);

        AuditLogger::log($actorId, 'message_reply', 'contact_message', $id, [
            'to_email' => $msg['email'],
            'subject'  => $subject,
            'reply'    => $replyBody,
        ], $ip);
    }

    /**
     * Submit a new customer contact inquiry from the storefront.
     * Enforces rate limiting, honeypot, minimum submission time, input validation,
     * database insertion, and notification email queueing.
     *
     * @param array $data Form submission input ($_POST)
     * @param string $ip Client IP address
     * @return array Result containing ['success' => bool, 'message' => string, 'errors' => array, 'id' => ?int]
     */
    public static function submit(array $data, string $ip): array
    {
        // 1. Honeypot check (hidden field that automated bots fill)
        if (!empty($data['website'])) {
            return [
                'success' => false,
                'errors'  => ['general' => 'Spam bot submission detected.'],
            ];
        }

        // 2. Minimum form fill-time check (must be at least 3 seconds)
        $formTime = isset($data['form_time']) && is_numeric($data['form_time']) ? (int)$data['form_time'] : 0;
        if ($formTime <= 0 || (time() - $formTime) < 3) {
            return [
                'success' => false,
                'errors'  => ['general' => 'Form was submitted too quickly. Please take a moment to review and submit again.'],
            ];
        }

        // 3. IP Rate limiting (5 contact submissions per hour per IP)
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $storageDir = $config['rate_limits']['storage_dir'] ?? (dirname(__DIR__, 2) . '/app/storage/rate_limits');
        $limit = (int)($config['rate_limits']['contact_messages_per_ip'] ?? 5);
        $window = 3600; // 1 hour
        $now = time();

        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }

        $cleanIp = trim(str_replace(["\r", "\n"], '', $ip));
        $ipKey = 'contact_ip_' . hash('sha256', $cleanIp);
        $ipFile = $storageDir . '/' . $ipKey . '.json';
        $timestamps = [];

        if (file_exists($ipFile)) {
            $content = @file_get_contents($ipFile);
            $parsed = $content ? @json_decode($content, true) : null;
            if (is_array($parsed)) {
                $timestamps = array_filter($parsed, fn($ts) => ($now - (int)$ts) < $window);
            }
        }

        if (count($timestamps) >= $limit) {
            http_response_code(429);
            return [
                'success'       => false,
                'rate_limited'  => true,
                'errors'        => ['general' => 'Too many contact inquiries from this IP address. Please wait an hour before submitting again.'],
            ];
        }

        // 4. Server-side validation with field errors
        $errors = [];

        // Name
        $name = trim($data['name'] ?? '');
        $name = str_replace(["\r", "\n"], ' ', $name);
        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors['name'] = 'Full name is required (between 2 and 100 characters).';
        }

        // Email: reject newlines, validate RFC format, limit 191 chars
        $rawEmail = trim($data['email'] ?? '');
        if (str_contains($rawEmail, "\r") || str_contains($rawEmail, "\n") || !filter_var($rawEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($rawEmail) > 191) {
            $errors['email'] = 'A valid email address is required (maximum 191 characters).';
        }
        $email = $rawEmail;

        // Phone (optional, normalized with Kenyan helper if provided)
        $rawPhone = trim($data['phone'] ?? '');
        $normalizedPhone = null;
        if ($rawPhone !== '') {
            $normalizedPhone = normalize_phone($rawPhone);
            if (!$normalizedPhone) {
                $errors['phone'] = 'Please enter a valid Kenyan phone number (e.g. 0712345678 or +254712345678).';
            }
        }

        // Subject: strip newlines, limit length
        $rawSubject = trim($data['subject'] ?? '');
        $subject = str_replace(["\r", "\n"], ' ', $rawSubject);
        if ($subject === '' || mb_strlen($subject) < 3 || mb_strlen($subject) > 200) {
            $errors['subject'] = 'Subject is required (between 3 and 200 characters).';
        }

        // Message
        $message = trim($data['message'] ?? '');
        if ($message === '' || mb_strlen($message) < 10 || mb_strlen($message) > 3000) {
            $errors['message'] = 'Message is required (between 10 and 3,000 characters).';
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'errors'  => $errors,
            ];
        }

        // 5. Database persistence
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            INSERT INTO contact_messages (name, email, phone, subject, message, status, created_at)
            VALUES (:name, :email, :phone, :subject, :message, 'new', NOW())
        ");
        $stmt->execute([
            ':name'    => $name,
            ':email'   => $email,
            ':phone'   => $normalizedPhone,
            ':subject' => $subject,
            ':message' => $message,
        ]);
        $messageId = (int)$pdo->lastInsertId();

        // 6. Record rate-limit hit
        $timestamps[] = $now;
        @file_put_contents($ipFile, json_encode(array_values($timestamps)), LOCK_EX);

        // 7. Queue notification email to contact_form_notify_email
        $notifyEmail = setting('contact_form_notify_email', setting('support_email', 'support@kennedycheroben.co.ke'));
        $storeName = setting('store_name', 'ShopWise Kenya');
        $emailSubject = "[Contact Inquiry #{$messageId}] " . trim(str_replace(["\r", "\n"], ' ', $subject));

        $escName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $escEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $escPhone = htmlspecialchars($normalizedPhone ?? 'Not provided', ENT_QUOTES, 'UTF-8');
        $escSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $escMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #1e293b; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px;">
        <h2 style="color: #0f172a; margin-top: 0;">New Contact Inquiry #{$messageId}</h2>
        <p>A new customer inquiry has been received through the {$storeName} contact form.</p>
        <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
            <tr><td style="padding: 6px 0; font-weight: 600; width: 100px;">From:</td><td>{$escName}</td></tr>
            <tr><td style="padding: 6px 0; font-weight: 600;">Email:</td><td><a href="mailto:{$escEmail}">{$escEmail}</a></td></tr>
            <tr><td style="padding: 6px 0; font-weight: 600;">Phone:</td><td>{$escPhone}</td></tr>
            <tr><td style="padding: 6px 0; font-weight: 600;">Subject:</td><td>{$escSubject}</td></tr>
        </table>
        <div style="background: #f8fafc; border-left: 4px solid #ea580c; padding: 16px; margin: 20px 0; border-radius: 4px;">
            {$escMessage}
        </div>
        <p style="font-size: 13px; color: #64748b; margin-bottom: 0;">
            Log in to the Admin Dashboard to manage and reply to this inquiry.
        </p>
    </div>
</body>
</html>
HTML;

        $textBody = "New Contact Inquiry #{$messageId} - {$storeName}\n\n" .
                    "From: {$name}\n" .
                    "Email: {$email}\n" .
                    "Phone: " . ($normalizedPhone ?? 'Not provided') . "\n" .
                    "Subject: {$subject}\n\n" .
                    "Message:\n{$message}\n\n" .
                    "---\nLog in to the Admin Dashboard to reply.";

        $mailer = new MailerService();
        $mailer->queueEmail($notifyEmail, $storeName . ' Care', $emailSubject, $htmlBody, $textBody);

        $responseHours = (int)setting('support_response_hours', 24);
        $thankYou = "Thank you for reaching out! Your message has been received (Ref: #{$messageId}). Our customer support team will review your inquiry and reply within {$responseHours} hours.";

        return [
            'success' => true,
            'id'      => $messageId,
            'message' => $thankYou,
        ];
    }
}

