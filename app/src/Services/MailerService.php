<?php

namespace App\Services;

use App\Database\Database;
use PDO;
use Throwable;

/**
 * Mail Delivery & Email Queue Service
 */
class MailerService
{
    private array $config;

    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $appConfig = require dirname(__DIR__, 2) . '/config/config.php';
            $this->config = $appConfig['mail'] ?? [];
        } else {
            $this->config = $config;
        }
    }

    /**
     * Enqueue an email to be sent asynchronously via cron
     */
    public function queueEmail(string $toEmail, ?string $toName, string $subject, string $htmlBody, string $textBody): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            INSERT INTO email_queue (to_email, to_name, subject, body_html, body_text, status, attempts, created_at)
            VALUES (:to_email, :to_name, :subject, :body_html, :body_text, 'pending', 0, NOW())
        ");
        $stmt->execute([
            ':to_email'  => $toEmail,
            ':to_name'   => $toName,
            ':subject'   => $subject,
            ':body_html' => $htmlBody,
            ':body_text' => $textBody,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
 * Send an email immediately through authenticated SMTP using vendored PHPMailer.
     */
    public function sendDirect(string $toEmail, ?string $toName, string $subject, string $htmlBody, string $textBody): bool
    {
        $driver = $this->config['driver'] ?? 'queue_only';

        if ($driver === 'queue_only') {
            $this->queueEmail($toEmail, $toName, $subject, $htmlBody, $textBody);
            return true;
        }

        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            throw new \RuntimeException('PHPMailer is not available. Restore the committed vendor directory.');
        }
        return $this->sendViaPHPMailer($toEmail, $toName, $subject, $htmlBody, $textBody);
    }

    /**
     * Send email using PHPMailer library
     */
    private function sendViaPHPMailer(string $toEmail, ?string $toName, string $subject, string $htmlBody, string $textBody): bool
    {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $this->config['host'] ?? 'localhost';
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->config['username'] ?? '';
            $mail->Password   = $this->config['password'] ?? '';
            $mail->SMTPSecure = $this->config['encryption'] ?? 'ssl';
            $mail->Port       = (int)($this->config['port'] ?? 465);

            $mail->setFrom($this->config['from_address'] ?? 'support@kennedycheroben.co.ke', $this->config['from_name'] ?? 'ShopWise Kenya');
            $mail->addAddress($toEmail, $toName ?? '');

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody;

            return $mail->send();
        } catch (Throwable $e) {
            error_log("PHPMailer error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process pending queued emails (invoked by cPanel cron runner)
     */
    public function processQueue(int $limit = 20): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT id, to_email, to_name, subject, body_html, body_text, attempts
            FROM email_queue
            WHERE status = 'pending' AND attempts < 5
            ORDER BY id ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        $processed = 0;

        foreach ($rows as $row) {
            $success = false;
            $error = null;

            try {
                // If in queue_only mode, simulate transmission or attempt native mail
                if (($this->config['driver'] ?? '') === 'queue_only') {
                    $success = true; // Mark as delivered in local mock queue
                } else {
                    $success = $this->sendDirect($row['to_email'], $row['to_name'], $row['subject'], $row['body_html'], $row['body_text']);
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            if ($success) {
                $update = $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW(), attempts = attempts + 1 WHERE id = :id");
                $update->execute([':id' => $row['id']]);
                $processed++;
            } else {
                $update = $pdo->prepare("
                    UPDATE email_queue
                    SET status = CASE WHEN attempts + 1 >= 5 THEN 'failed' ELSE 'pending' END,
                        attempts = attempts + 1,
                        last_error = :err
                    WHERE id = :id
                ");
                $update->execute([':id' => $row['id'], ':err' => $error ?? 'Delivery failed']);
            }
        }

        return $processed;
    }
}
