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
     * Get count of new unread contact messages.
     */
    public static function getNewCount(): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'");
        return (int)$stmt->fetchColumn();
    }
}
