-- ==============================================================================
-- DEVELOPMENT ONLY: DEMO ORDERS REMOVAL SCRIPT
-- Safely cleans up all Phase 2A demo order rows, payments, and audit entries.
-- ==============================================================================

USE `shopwise_db`;

START TRANSACTION;

DELETE im FROM `inventory_movements` im 
  JOIN `orders` o ON o.id = im.order_id 
  WHERE o.order_number LIKE 'DEMO2A-%';

DELETE p FROM `payments` p 
  JOIN `orders` o ON o.id = p.order_id 
  WHERE o.order_number LIKE 'DEMO2A-%';

DELETE osh FROM `order_status_history` osh 
  JOIN `orders` o ON o.id = osh.order_id 
  WHERE o.order_number LIKE 'DEMO2A-%';

DELETE oi FROM `order_items` oi 
  JOIN `orders` o ON o.id = oi.order_id 
  WHERE o.order_number LIKE 'DEMO2A-%';

DELETE FROM `orders` WHERE `order_number` LIKE 'DEMO2A-%';

COMMIT;
