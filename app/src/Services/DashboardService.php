<?php
namespace App\Services;
use App\Database\Database;

final class DashboardService
{
 public static function data(): array {
  $pdo=Database::getInstance();
  $sum=fn(string $from) => self::scalar($pdo,"SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid' AND created_at >= $from");
  $status=$pdo->query('SELECT status,COUNT(*) total FROM orders GROUP BY status')->fetchAll();
  $top=$pdo->query("SELECT oi.product_name,SUM(oi.quantity) units,SUM(oi.line_total) revenue FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.payment_status='paid' GROUP BY oi.product_id,oi.product_name ORDER BY units DESC LIMIT 5")->fetchAll();
  $stock=$pdo->query("SELECT v.id,v.sku,v.size,v.color,v.stock_qty,v.low_stock_threshold,p.name product_name FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.stock_qty<=v.low_stock_threshold AND v.is_active=1 AND p.deleted_at IS NULL ORDER BY v.stock_qty ASC LIMIT 10")->fetchAll();
  $recent=$pdo->query("SELECT o.order_number,o.status,o.payment_status,o.total,o.created_at,COALESCE(CONCAT(u.first_name,' ',u.last_name),o.recipient_name) customer FROM orders o LEFT JOIN users u ON u.id=o.user_id ORDER BY o.created_at DESC LIMIT 10")->fetchAll();
  $days=[]; for($i=29;$i>=0;$i--){$d=date('Y-m-d',strtotime("-$i days"));$days[$d]='0.00';}
  $stmt=$pdo->prepare("SELECT DATE(created_at) day,COALESCE(SUM(total),0) total FROM orders WHERE payment_status='paid' AND created_at >= DATE_SUB(CURDATE(),INTERVAL 29 DAY) GROUP BY DATE(created_at)"); $stmt->execute(); foreach($stmt as $row){$days[$row['day']]=$row['total'];}
  return ['sales_today'=>$sum('CURDATE()'),'sales_7'=>$sum('DATE_SUB(CURDATE(),INTERVAL 6 DAY)'),'sales_30'=>$sum('DATE_SUB(CURDATE(),INTERVAL 29 DAY)'),'statuses'=>$status,'top'=>$top,'stock'=>$stock,'recent'=>$recent,'days'=>$days,'new_customers'=>self::scalar($pdo,"SELECT COUNT(*) FROM users WHERE role='customer' AND created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')"),'active_products'=>self::scalar($pdo,"SELECT COUNT(*) FROM products WHERE is_active=1 AND deleted_at IS NULL")];
 }
 private static function scalar($pdo,string $sql): string { return (string)$pdo->query($sql)->fetchColumn(); }
}
