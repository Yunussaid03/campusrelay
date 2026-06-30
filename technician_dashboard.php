<?php
session_start();
require 'php/db_connect.php';

// Protect the page: Kick out anyone who isn't a logged-in technician
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') {
    header("Location: index.php");
    exit;
}

$technician_id = $_SESSION['user_id'];

// 1. Fetch available "Reserved" rentals that don't have a technician assigned
$available_stmt = $pdo->prepare("
    SELECT 
        r.rental_id, r.total_price, r.created_at, 
        u.name AS renter_name, 
        v.name AS vehicle_name, 
        rd.quantity AS hours
    FROM rentals r
    JOIN users u ON r.renter_id = u.user_id
    JOIN rental_details rd ON r.rental_id = rd.rental_id
    JOIN vehicles v ON rd.vehicle_id = v.vehicle_id
    WHERE r.status = 'reserved' AND r.technician_id IS NULL
    ORDER BY r.created_at ASC
");
$available_stmt->execute();
$available_rentals = $available_stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch active tasks (rentals assigned to this technician that are still 'reserved')
$active_stmt = $pdo->prepare("
    SELECT 
        r.rental_id, r.total_price, r.created_at, 
        u.name AS renter_name, 
        v.name AS vehicle_name, 
        rd.quantity AS hours
    FROM rentals r
    JOIN users u ON r.renter_id = u.user_id
    JOIN rental_details rd ON r.rental_id = rd.rental_id
    JOIN vehicles v ON rd.vehicle_id = v.vehicle_id
    WHERE r.status = 'reserved' AND r.technician_id = :technician_id
    ORDER BY r.created_at ASC
");
$active_stmt->execute([':technician_id' => $technician_id]);
$active_rentals = $active_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch completed returns processed by this technician
$completed_stmt = $pdo->prepare("
    SELECT 
        r.rental_id, r.total_price, r.created_at, 
        u.name AS renter_name, 
        v.name AS vehicle_name, 
        rd.quantity AS hours
    FROM rentals r
    JOIN users u ON r.renter_id = u.user_id
    JOIN rental_details rd ON r.rental_id = rd.rental_id
    JOIN vehicles v ON rd.vehicle_id = v.vehicle_id
    WHERE r.status = 'returned' AND r.technician_id = :technician_id
    ORDER BY r.created_at DESC
    LIMIT 10
");
$completed_stmt->execute([':technician_id' => $technician_id]);
$completed_rentals = $completed_stmt->fetchAll(PDO::FETCH_ASSOC);

include 'php/header.php'; 
?>

<div class="dashboard-container">
    <div class="dashboard-header">
        <h2>Tech Central: Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h2>
        <a href="logout.php" class="btn-logout">Logout</a>
    </div>
    
    <p class="subtitle">Manage campus vehicle pick-ups, returns, and safety checks:</p>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert success"><?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert error"><?php echo htmlspecialchars($_GET['error']); ?></div>
    <?php endif; ?>

    <!-- Tab navigation -->
    <div class="dashboard-tabs">
        <button class="tab-btn active" onclick="switchTab('available-tab')">Available Pickups (<?php echo count($available_rentals); ?>)</button>
        <button class="tab-btn" onclick="switchTab('active-tab')">My Active Tasks (<?php echo count($active_rentals); ?>)</button>
        <button class="tab-btn" onclick="switchTab('completed-tab')">Completed Returns (<?php echo count($completed_rentals); ?>)</button>
    </div>

    <!-- Available Pickups Tab -->
    <div id="available-tab" class="tab-content active">
        <h3 class="category-title">Awaiting Technician Assignment</h3>
        <hr class="category-divider">
        
        <?php if (empty($available_rentals)): ?>
            <p style="text-align: center; color: #7f8c8d; padding: 3rem 1rem;">No reserved rentals awaiting assignment right now. Check back later!</p>
        <?php else: ?>
            <div class="items-grid">
                <?php foreach ($available_rentals as $rental): ?>
                    <div class="item-card order-card">
                        <div class="order-header">
                            <h3>Rental #<?php echo $rental['rental_id']; ?></h3>
                            <span class="time"><?php echo date('h:i A', strtotime($rental['created_at'])); ?></span>
                        </div>
                        <p><strong>Renter:</strong> <?php echo htmlspecialchars($rental['renter_name']); ?></p>
                        <p><strong>Vehicle:</strong> <?php echo htmlspecialchars($rental['vehicle_name']); ?> (<?php echo $rental['hours']; ?> hr<?php echo $rental['hours'] > 1 ? 's' : ''; ?>)</p>
                        <p class="price">Payout: $<?php echo number_format($rental['total_price'] * 0.15, 2); ?> <small>(15% Tech Commission)</small></p>
                        
                        <form action="php/accept_rental.php" method="POST" style="margin-top: auto;">
                            <input type="hidden" name="rental_id" value="<?php echo $rental['rental_id']; ?>">
                            <button type="submit" class="btn btn-accept">Accept Pickup</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Active Tasks Tab -->
    <div id="active-tab" class="tab-content">
        <h3 class="category-title">Currently Managing</h3>
        <hr class="category-divider">
        
        <?php if (empty($active_rentals)): ?>
            <p style="text-align: center; color: #7f8c8d; padding: 3rem 1rem;">You don't have any active pickups assigned. Accept a job from the Pickups tab!</p>
        <?php else: ?>
            <div class="items-grid">
                <?php foreach ($active_rentals as $rental): ?>
                    <div class="item-card active-rental-card">
                        <div class="order-header">
                            <h3>Active Task #<?php echo $rental['rental_id']; ?></h3>
                            <span class="time"><?php echo date('h:i A', strtotime($rental['created_at'])); ?></span>
                        </div>
                        <p><strong>Renter:</strong> <?php echo htmlspecialchars($rental['renter_name']); ?></p>
                        <p><strong>Vehicle:</strong> <?php echo htmlspecialchars($rental['vehicle_name']); ?> (<?php echo $rental['hours']; ?> hr<?php echo $rental['hours'] > 1 ? 's' : ''; ?>)</p>
                        <p><strong>Cost:</strong> $<?php echo number_format($rental['total_price'], 2); ?></p>
                        
                        <form action="php/complete_rental.php" method="POST" style="margin-top: auto; display: flex; flex-direction: column; gap: 0.5rem;">
                            <input type="hidden" name="rental_id" value="<?php echo $rental['rental_id']; ?>">
                            <button type="submit" class="btn btn-complete">Mark as Returned</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Completed Returns Tab -->
    <div id="completed-tab" class="tab-content">
        <h3 class="category-title">Your Work History</h3>
        <hr class="category-divider">
        
        <?php if (empty($completed_rentals)): ?>
            <p style="text-align: center; color: #7f8c8d; padding: 3rem 1rem;">No completed returns processed yet.</p>
        <?php else: ?>
            <div class="table-container">
                <table class="rentals-table">
                    <thead>
                        <tr>
                            <th>Rental ID</th>
                            <th>Renter</th>
                            <th>Vehicle</th>
                            <th>Duration</th>
                            <th>Payout Earned</th>
                            <th>Status</th>
                            <th>Processed Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($completed_rentals as $rental): ?>
                            <tr>
                                <td>#<?php echo $rental['rental_id']; ?></td>
                                <td><?php echo htmlspecialchars($rental['renter_name']); ?></td>
                                <td><strong><?php echo htmlspecialchars($rental['vehicle_name']); ?></strong></td>
                                <td><?php echo $rental['hours']; ?> hr<?php echo $rental['hours'] > 1 ? 's' : ''; ?></td>
                                <td>$<?php echo number_format($rental['total_price'] * 0.15, 2); ?></td>
                                <td><span class="status-badge status-returned">Returned</span></td>
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
