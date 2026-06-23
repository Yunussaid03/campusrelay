<?php
session_start();
require 'db_connect.php';

// Ensure the user is a logged-in customer submitting a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id']) && $_SESSION['role'] === 'customer') {
    
    $customer_id = $_SESSION['user_id'];
    $item_id = $_POST['item_id'];
    $quantity = (int)$_POST['quantity'];
    $price = (float)$_POST['price'];
    
    // Calculate total
    $total_price = $price * $quantity;

    try {
        // Begin Transaction
        $pdo->beginTransaction();

        // 1. Create the main order record
        $stmtOrder = $pdo->prepare("INSERT INTO orders (customer_id, total_price) VALUES (:customer_id, :total_price)");
        $stmtOrder->execute([
            ':customer_id' => $customer_id,
            ':total_price' => $total_price
        ]);
        
        // Grab the ID of the order we just created
        $order_id = $pdo->lastInsertId();

        // 2. Create the order details record
        $stmtDetails = $pdo->prepare("INSERT INTO order_details (order_id, item_id, quantity) VALUES (:order_id, :item_id, :quantity)");
        $stmtDetails->execute([
            ':order_id' => $order_id,
            ':item_id' => $item_id,
            ':quantity' => $quantity
        ]);

        // Commit Transaction (Save permanently)
        $pdo->commit();
        
        header("Location: ../customer_dashboard.php?success=Order placed successfully!");
        exit;

    } catch (Exception $e) {
        // If anything fails, roll back the changes
        $pdo->rollBack();
        header("Location: ../customer_dashboard.php?error=Failed to place order.");
        exit;
    }
} else {
    header("Location: ../index.php");
    exit;
}
?>