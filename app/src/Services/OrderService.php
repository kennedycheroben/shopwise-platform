<?php
namespace App\Services;
use App\Database\Database;
use RuntimeException;
final class OrderService {
 public static function cancel(int $orderId,int $actorId,string $note): void {
  Database::transaction(function($pdo) use($orderId,$actorId,$note) {
   $q=$pdo->prepare('SELECT id,status,order_number,user_id FROM orders WHERE id=:id FOR UPDATE'); $q->execute([':id'=>$orderId]); $order=$q->fetch();
   if (!$order || !in_array($order['status'],['pending_payment','paid','processing'],true)) throw new RuntimeException('This order can no longer be cancelled.');
   $items=$pdo->prepare('SELECT variant_id,quantity FROM order_items WHERE order_id=:id'); $items->execute([':id'=>$orderId]);
   foreach($items as $item) { $pdo->prepare('UPDATE product_variants SET stock_qty=stock_qty+:qty WHERE id=:variant')->execute([':qty'=>$item['quantity'],':variant'=>$item['variant_id']]); $pdo->prepare("INSERT INTO inventory_movements (variant_id,change_qty,reason,order_id,actor_user_id,note) VALUES (:variant,:qty,'cancel_restore',:order,:actor,:note)")->execute([':variant'=>$item['variant_id'],':qty'=>$item['quantity'],':order'=>$orderId,':actor'=>$actorId,':note'=>$note]); }
   $pdo->prepare("UPDATE orders SET status='cancelled',payment_status=IF(payment_status='paid','refunded',payment_status) WHERE id=:id")->execute([':id'=>$orderId]);
   $pdo->prepare("INSERT INTO order_status_history (order_id,from_status,to_status,note,actor_user_id) VALUES (:id,:from,'cancelled',:note,:actor)")->execute([':id'=>$orderId,':from'=>$order['status'],':note'=>$note,':actor'=>$actorId]);
  });
 }
}
