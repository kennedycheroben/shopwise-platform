-- ==============================================================================
-- Migration 005: Add policy settings for returns, refunds, support, and contact form
-- ==============================================================================

INSERT INTO `settings` (`setting_key`, `setting_value`, `type`, `description`) VALUES
('return_window_days', '7', 'integer', 'Standard window in days from delivery for customer return requests'),
('refund_processing_days', '7', 'integer', 'Business days required to inspect and disburse approved refunds'),
('support_response_hours', '24', 'integer', 'Target customer service response time in hours'),
('contact_form_notify_email', 'support@kennedycheroben.co.ke', 'string', 'Recipient email address for incoming storefront contact inquiries')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
