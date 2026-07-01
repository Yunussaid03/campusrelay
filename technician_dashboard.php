<?php
session_start();
require 'php/db_connect.php';

// Protect the page: Kick out anyone who isn't a logged-in technician
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') {
    header("Location: index.php");
    exit;
}

$technician_id = $_SESSION['user_id'];

// 1. Fetch available rentals (status 'active' and no technician assigned)
$available_stmt = $pdo->prepare("
    SELECT 
        r.rental_id, r.total_cost, r.created_at, 
        u.name AS renter_name, 
        v.name AS vehicle_name,
        r.rental_start, r.rental_end
    FROM rentals r
    JOIN users u ON r.renter_id = u.user_id
    JOIN vehicles v ON r.vehicle_id = v.vehicle_id
    WHERE r.status = 'active' AND r.technician_id IS NULL
    ORDER BY r.created_at ASC
");
$available_stmt->execute();
$available_rentals = $available_stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch active tasks (rentals assigned to this technician that are still 'active')
$active_stmt = $pdo->prepare("
    SELECT 
        r.rental_id, r.total_cost, r.created_at, 
        u.name AS renter_name, 
        v.name AS vehicle_name,
        r.rental_start, r.rental_end
    FROM rentals r
    JOIN users u ON r.renter_id = u.user_id
    JOIN vehicles v ON r.vehicle_id = v.vehicle_id
    WHERE r.status = 'active' AND r.technician_id = :technician_id
    ORDER BY r.created_at ASC
");
$active_stmt->execute([':technician_id' => $technician_id]);
$active_rentals = $active_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch completed returns processed by this technician
$completed_stmt = $pdo->prepare("
    SELECT 
        r.rental_id, r.total_cost, r.created_at, 
        u.name AS renter_name, 
        v.name AS vehicle_name,
        r.rental_start, r.rental_end
    FROM rentals r
    JOIN users u ON r.renter_id = u.user_id
    JOIN vehicles v ON r.vehicle_id = v.vehicle_id
    WHERE r.status = 'returned' AND r.technician_id = :technician_id
    ORDER BY r.created_at DESC
    LIMIT 10
");
$completed_stmt->execute([':technician_id' => $technician_id]);
$completed_rentals = $completed_stmt->fetchAll(PDO::FETCH_ASSOC);

$body_class = 'dashboard-body';
$container_class = 'fluid-dashboard-wrapper';
include 'php/header.php'; 
?>

<div class="app-layout">
    <!-- Left Sidebar Navigation -->
    <aside class="app-sidebar">
        <div class="sidebar-brand">
            <h3>XMUM Portal</h3>
        </div>
        <nav class="sidebar-menu">
            <button class="menu-item active" onclick="switchTab('available-tab')">Available Pickups (<?php echo count($available_rentals); ?>)</button>
            <button class="menu-item" onclick="switchTab('active-tab')">My Active Tasks (<?php echo count($active_rentals); ?>)</button>
            <button class="menu-item" onclick="switchTab('completed-tab')">Completed Returns (<?php echo count($completed_rentals); ?>)</button>
            <button class="menu-item" onclick="switchTab('settings-tab')">Account Settings</button>
        </nav>
        <div class="sidebar-footer">
            <div class="user-info">
                <span>Welcome,</span>
                <span><?php echo htmlspecialchars($_SESSION['name']); ?></span>
            </div>
            <a href="logout.php" class="sidebar-logout">Logout</a>
        </div>
    </aside>

    <!-- Right Main Content Area -->
    <main class="app-content">
        <div class="content-header">
            <h2>XMUM Tech Central</h2>
            <span class="role-badge">Campus Technician</span>
        </div>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <!-- Available Pickups Tab -->
        <div id="available-tab" class="tab-content active">
            <h3 class="category-title">Awaiting Technician Assignment</h3>
            <hr class="category-divider">
            
            <?php if (empty($available_rentals)): ?>
                <p style="text-align: center; color: #7f8c8d; padding: 3rem 1rem;">No active rentals awaiting assignment right now. Check back later!</p>
            <?php else: ?>
                <div class="items-grid">
                    <?php foreach ($available_rentals as $rental): ?>
                        <?php
                            $start = new DateTime($rental['rental_start']);
                            $end = new DateTime($rental['rental_end']);
                            $hours = $start->diff($end)->h + ($start->diff($end)->days * 24);
                        ?>
                        <div class="item-card order-card">
                            <div class="card-body">
                                <div class="order-header">
                                    <h3>Rental #<?php echo $rental['rental_id']; ?></h3>
                                    <span class="time"><?php echo date('h:i A', strtotime($rental['created_at'])); ?></span>
                                </div>
                                <p style="margin-bottom: 0.5rem;"><strong>Renter:</strong> <?php echo htmlspecialchars($rental['renter_name']); ?></p>
                                <p style="margin-bottom: 0.5rem;"><strong>Vehicle:</strong> <?php echo htmlspecialchars($rental['vehicle_name']); ?></p>
                                <p style="margin-bottom: 1.25rem;"><strong>Duration:</strong> <?php echo $hours; ?> hr<?php echo $hours > 1 ? 's' : ''; ?></p>
                                <p class="price" style="margin-bottom: 1.5rem;">Payout: $<?php echo number_format($rental['total_cost'] * 0.15, 2); ?> <br><small style="font-size: 0.75rem; color: var(--text-muted);">(15% Tech Commission)</small></p>
                                
                                <form action="php/accept_rental.php" method="POST" style="margin-top: auto;">
                                    <input type="hidden" name="rental_id" value="<?php echo $rental['rental_id']; ?>">
                                    <button type="submit" class="btn btn-accept">Accept Pickup</button>
                                </form>
                            </div>
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
                        <?php
                            $start = new DateTime($rental['rental_start']);
                            $end = new DateTime($rental['rental_end']);
                            $hours = $start->diff($end)->h + ($start->diff($end)->days * 24);
                        ?>
                        <div class="item-card active-rental-card">
                            <div class="card-body" style="padding: 0;">
                                <div class="order-header">
                                    <h3>Active Task #<?php echo $rental['rental_id']; ?></h3>
                                    <span class="time"><?php echo date('h:i A', strtotime($rental['created_at'])); ?></span>
                                </div>
                                <p style="margin-bottom: 0.5rem;"><strong>Renter:</strong> <?php echo htmlspecialchars($rental['renter_name']); ?></p>
                                <p style="margin-bottom: 0.5rem;"><strong>Vehicle:</strong> <?php echo htmlspecialchars($rental['vehicle_name']); ?></p>
                                <p style="margin-bottom: 0.5rem;"><strong>Duration:</strong> <?php echo $hours; ?> hr<?php echo $hours > 1 ? 's' : ''; ?></p>
                                <p class="price" style="margin-bottom: 1.5rem;"><strong>Cost:</strong> $<?php echo number_format($rental['total_cost'], 2); ?></p>
                                
                                <form action="php/complete_rental.php" method="POST" style="margin-top: auto; display: flex; flex-direction: column; gap: 0.5rem;">
                                    <input type="hidden" name="rental_id" value="<?php echo $rental['rental_id']; ?>">
                                    <button type="submit" class="btn btn-complete">Mark as Returned</button>
                                </form>
                            </div>
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
                                <th>Rental Start</th>
                                <th>Rental End</th>
                                <th>Payout Earned</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completed_rentals as $rental): ?>
                                <tr>
                                    <td>#<?php echo $rental['rental_id']; ?></td>
                                    <td><?php echo htmlspecialchars($rental['renter_name']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($rental['vehicle_name']); ?></strong></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($rental['rental_start'])); ?></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($rental['rental_end'])); ?></td>
                                    <td>$<?php echo number_format($rental['total_cost'] * 0.15, 2); ?></td>
                                    <td><span class="status-badge status-returned">Returned</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Account Settings Tab Content -->
        <div id="settings-tab" class="tab-content">
            <h3 class="category-title">Account Settings</h3>
            <hr class="category-divider">
            
            <div class="settings-card">
                <p><strong>Full Name:</strong> <?php echo htmlspecialchars($_SESSION['name']); ?></p>
                <p><strong>System Role:</strong> Campus Technician / Runner</p>
                <p><strong>Institution:</strong> Xiamen University Malaysia (XMUM)</p>
                <p><strong>Theme Preference:</strong> XMUM Academic Blue & Gold (Default)</p>
            </div>
        </div>
    </main>
</div>

<script>
function switchTab(tabId) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    // Deactivate all menu items
    document.querySelectorAll('.menu-item').forEach(el => el.classList.remove('active'));
    
    // Show current tab content
    document.getElementById(tabId).classList.add('active');
    
    // Find the button that was clicked and activate it
    const btn = Array.from(document.querySelectorAll('.menu-item')).find(el => el.getAttribute('onclick').includes(tabId));
    if (btn) {
        btn.classList.add('active');
    }
}
</script>

<?php include 'php/footer.php'; ?>
