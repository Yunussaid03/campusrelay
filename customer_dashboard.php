<?php
session_start();
require 'php/db_connect.php';

// Protect the page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header("Location: index.php");
    exit;
}

$renter_id = $_SESSION['user_id'];

// Fetch all available vehicles, ordered by category first, then by name
$stmt = $pdo->query("SELECT * FROM vehicles ORDER BY category, name");
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize vehicles into an array grouped by category
$categorized_vehicles = [];
foreach ($vehicles as $vehicle) {
    $categorized_vehicles[$vehicle['category']][] = $vehicle;
}

// Fetch active and past rentals for this renter
$rental_stmt = $pdo->prepare("
    SELECT 
        r.rental_id, r.total_price, r.status, r.created_at,
        v.name AS vehicle_name,
        rd.quantity AS hours,
        u_tech.name AS technician_name
    FROM rentals r
    JOIN rental_details rd ON r.rental_id = rd.rental_id
    JOIN vehicles v ON rd.vehicle_id = v.vehicle_id
    LEFT JOIN users u_tech ON r.technician_id = u_tech.user_id
    WHERE r.renter_id = :renter_id
    ORDER BY r.created_at DESC
");
$rental_stmt->execute([':renter_id' => $renter_id]);
$my_rentals = $rental_stmt->fetchAll(PDO::FETCH_ASSOC);

include 'php/header.php'; 
?>

<div class="dashboard-container">
    <div class="dashboard-header">
        <h2>Renter Hub: Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h2>
        <a href="logout.php" class="btn-logout">Logout</a>
    </div>
    
    <p class="subtitle">Select a transportation vehicle to rent on campus:</p>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert success"><?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert error"><?php echo htmlspecialchars($_GET['error']); ?></div>
    <?php endif; ?>

    <!-- Tab navigation -->
    <div class="dashboard-tabs">
        <button class="tab-btn active" onclick="switchTab('vehicles-tab')">Available Vehicles</button>
        <button class="tab-btn" onclick="switchTab('rentals-tab')">My Rentals (<?php echo count($my_rentals); ?>)</button>
    </div>

    <!-- Vehicles Tab Content -->
    <div id="vehicles-tab" class="tab-content active">
        <?php if (empty($categorized_vehicles)): ?>
            <p style="text-align: center; color: #7f8c8d; padding: 2rem;">No vehicles are currently registered in the database.</p>
        <?php else: ?>
            <?php foreach ($categorized_vehicles as $category => $category_vehicles): ?>
                <div class="category-section">
                    <h3 class="category-title"><?php echo htmlspecialchars($category); ?></h3>
                    <hr class="category-divider">
                    
                    <div class="items-grid">
                        <?php foreach ($category_vehicles as $vehicle): ?>
                            <div class="item-card vehicle-card">
                                <h3><?php echo htmlspecialchars($vehicle['name']); ?></h3>
                                <p class="desc"><?php echo htmlspecialchars($vehicle['description']); ?></p>
                                <p class="price">$<?php echo number_format($vehicle['price'], 2); ?> <span class="rate-unit">/ hr</span></p>
                                
                                <form action="php/place_rental.php" method="POST" class="order-form">
                                    <input type="hidden" name="vehicle_id" value="<?php echo $vehicle['vehicle_id']; ?>">
                                    <input type="hidden" name="price" value="<?php echo $vehicle['price']; ?>">
                                    
                                    <div class="qty-group">
                                        <label for="qty_<?php echo $vehicle['vehicle_id']; ?>">Hours:</label>
                                        <input type="number" id="qty_<?php echo $vehicle['vehicle_id']; ?>" name="quantity" value="1" min="1" max="24" required>
                                    </div>
                                    <button type="submit" class="btn btn-rent">Rent Now</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Rentals Tab Content -->
    <div id="rentals-tab" class="tab-content">
        <h3 class="category-title">My Rental Summary</h3>
        <hr class="category-divider">
        
        <?php if (empty($my_rentals)): ?>
            <p style="text-align: center; color: #7f8c8d; padding: 3rem 1rem;">
                You haven't reserved any rentals yet. Head over to the vehicles tab to start riding!
            </p>
        <?php else: ?>
            <div class="table-container">
                <table class="rentals-table">
                    <thead>
                        <tr>
                            <th>Rental ID</th>
                            <th>Vehicle</th>
                            <th>Duration</th>
                            <th>Cost</th>
                            <th>Assigned Tech</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_rentals as $rental): ?>
                            <tr>
                                <td>#<?php echo $rental['rental_id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($rental['vehicle_name']); ?></strong></td>
                                <td><?php echo $rental['hours']; ?> hr<?php echo $rental['hours'] > 1 ? 's' : ''; ?></td>
                                <td>$<?php echo number_format($rental['total_price'], 2); ?></td>
                                <td>
                                    <?php echo $rental['technician_name'] ? htmlspecialchars($rental['technician_name']) : '<em class="waiting">Awaiting Tech...</em>'; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($rental['status']); ?>">
                                        <?php echo ucfirst($rental['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y h:i A', strtotime($rental['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function switchTab(tabId) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    // Deactivate all tab buttons
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    
    // Show current tab content
    document.getElementById(tabId).classList.add('active');
    // Set clicked button to active
    event.currentTarget.classList.add('active');
}
</script>

<?php include 'php/footer.php'; ?>