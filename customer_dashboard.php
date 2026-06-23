<?php
session_start();
require 'php/db_connect.php';

// Protect the page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header("Location: index.php");
    exit;
}

// Fetch all items, ordered by category first, then by name
$stmt = $pdo->query("SELECT * FROM items ORDER BY category, name");
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize items into an array grouped by category
$categorized_items = [];
foreach ($items as $item) {
    $categorized_items[$item['category']][] = $item;
}

include 'php/header.php'; 
?>

<div class="dashboard-container">
    <div class="dashboard-header">
        <h2>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h2>
        <a href="php/logout.php" class="btn-logout">Logout</a>
    </div>
    
    <p class="subtitle">What do you need delivered today?</p>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert success"><?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert error"><?php echo htmlspecialchars($_GET['error']); ?></div>
    <?php endif; ?>

    <?php foreach ($categorized_items as $category => $category_items): ?>
        
        <div class="category-section">
            <h2 class="category-title"><?php echo htmlspecialchars($category); ?></h2>
            <hr class="category-divider">
            
            <div class="items-grid">
                <?php foreach ($category_items as $item): ?>
                    <div class="item-card">
                        <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                        <p class="desc"><?php echo htmlspecialchars($item['description']); ?></p>
                        <p class="price">$<?php echo number_format($item['price'], 2); ?></p>
                        
                        <form action="php/place_order.php" method="POST" class="order-form">
                            <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                            <input type="hidden" name="price" value="<?php echo $item['price']; ?>">
                            
                            <div class="qty-group">
                                <label for="qty_<?php echo $item['item_id']; ?>">Qty:</label>
                                <input type="number" id="qty_<?php echo $item['item_id']; ?>" name="quantity" value="1" min="1" max="10" required>
                            </div>
                            <button type="submit" class="btn">Order Now</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
    <?php endforeach; ?>
</div>

<?php include 'php/footer.php'; ?>