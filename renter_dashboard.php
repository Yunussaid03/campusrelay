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
        r.rental_id, r.total_cost, r.status, r.rental_start, r.rental_end, r.created_at,
        v.name AS vehicle_name,
        u_tech.name AS technician_name
    FROM rentals r
    JOIN vehicles v ON r.vehicle_id = v.vehicle_id
    LEFT JOIN users u_tech ON r.technician_id = u_tech.user_id
    WHERE r.renter_id = :renter_id
    ORDER BY r.created_at DESC
");
$rental_stmt->execute([':renter_id' => $renter_id]);
$my_rentals = $rental_stmt->fetchAll(PDO::FETCH_ASSOC);

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
            <button class="menu-item active" onclick="switchTab('vehicles-tab')">Available Vehicles</button>
            <button class="menu-item" onclick="switchTab('rentals-tab')">My Rentals (<?php echo count($my_rentals); ?>)</button>
            <button class="menu-item" onclick="switchTab('settings-tab')">Account Settings</button>
        </nav>
        <div class="sidebar-footer">
            <div class="user-info">
                <span>Welcome,</span>
                <span><?php echo htmlspecialchars($_SESSION['name']); ?></span>
            </div>
            <!-- Logout links directly to logout.php in root folder -->
            <a href="logout.php" class="sidebar-logout">Logout</a>
        </div>
    </aside>

    <!-- Right Main Content Area -->
    <main class="app-content">
        <div class="content-header">
            <h2>XMUM Renter Hub</h2>
            <span class="role-badge">Student / Renter</span>
        </div>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <!-- Vehicles Tab Content -->
        <div id="vehicles-tab" class="tab-content active">
            <p class="subtitle">Select a transportation vehicle to rent on campus:</p>
            
            <!-- Availability Filter -->
            <div class="filter-bar" style="margin-bottom: 2rem; display: flex; align-items: center; gap: 0.65rem; background: rgba(255, 255, 255, 0.7); padding: 0.85rem 1.25rem; border-radius: var(--border-radius-sm); border: 1px solid rgba(0, 51, 102, 0.08); max-width: 320px;">
                <input type="checkbox" id="filter-available" onchange="toggleAvailabilityFilter()" style="cursor: pointer; width: 18px; height: 18px;">
                <label for="filter-available" style="font-weight: 600; font-size: 0.88rem; color: var(--primary-blue); cursor: pointer; user-select: none;">Show Only Available Vehicles</label>
            </div>
            
            <?php if (empty($categorized_vehicles)): ?>
                <p style="text-align: center; color: #7f8c8d; padding: 2rem;">No vehicles are currently registered in the database.</p>
            <?php else: ?>
                <?php foreach ($categorized_vehicles as $category => $category_vehicles): ?>
                    <div class="category-section">
                        <h3 class="category-title"><?php echo htmlspecialchars($category); ?></h3>
                        <hr class="category-divider">
                        
                        <div class="items-grid">
                            <?php foreach ($category_vehicles as $vehicle): ?>
                                <?php 
                                    // Fetch and render the vehicle image. If image_path is empty, use a placeholder
                                    $image_src = !empty($vehicle['image_path']) ? htmlspecialchars($vehicle['image_path']) : 'assets/images/placeholder.jpg';
                                    $is_available = $vehicle['availability_status'] === 'available';
                                ?>
                                <div class="item-card vehicle-card" data-status="<?php echo $vehicle['availability_status']; ?>" style="display: flex;">
                                    <img src="<?php echo $image_src; ?>" alt="<?php echo htmlspecialchars($vehicle['name']); ?>" class="vehicle-image">
                                    <div class="card-body">
                                        <!-- Header line containing Status Badge -->
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                                            <h3><?php echo htmlspecialchars($vehicle['name']); ?></h3>
                                            <span class="status-badge status-<?php echo strtolower($vehicle['availability_status']); ?>">
                                                <?php 
                                                    if ($vehicle['availability_status'] === 'available') echo 'Available';
                                                    else if ($vehicle['availability_status'] === 'reserved') echo 'Reserved';
                                                    else echo 'In Use';
                                                ?>
                                            </span>
                                        </div>
                                        <p class="desc"><?php echo htmlspecialchars($vehicle['description']); ?></p>
                                        <p class="price">$<?php echo number_format($vehicle['price'], 2); ?> <span class="rate-unit">/ hr</span></p>
                                        
                                        <?php if ($is_available): ?>
                                            <!-- Rental Scheduling form with start/end datetimes -->
                                            <form action="php/place_rental.php" method="POST" class="order-form">
                                                <input type="hidden" name="vehicle_id" value="<?php echo $vehicle['vehicle_id']; ?>">
                                                <input type="hidden" name="price" value="<?php echo $vehicle['price']; ?>">
                                                
                                                <div class="qty-group" style="display: flex; flex-direction: column; gap: 0.65rem; align-items: flex-start; margin-bottom: 0.5rem; width: 100%;">
                                                    <div style="display: flex; align-items: center; gap: 0.5rem; width: 100%;">
                                                        <label for="start_<?php echo $vehicle['vehicle_id']; ?>" style="width: 50px; flex-shrink: 0; font-weight: 600; font-size: 0.82rem; color: var(--text-muted);">Start:</label>
                                                        <input type="datetime-local" id="start_<?php echo $vehicle['vehicle_id']; ?>" name="rental_start" required style="width: 100%; font-size: 0.88rem; padding: 0.4rem;">
                                                    </div>
                                                    <div style="display: flex; align-items: center; gap: 0.5rem; width: 100%;">
                                                        <label for="end_<?php echo $vehicle['vehicle_id']; ?>" style="width: 50px; flex-shrink: 0; font-weight: 600; font-size: 0.82rem; color: var(--text-muted);">End:</label>
                                                        <input type="datetime-local" id="end_<?php echo $vehicle['vehicle_id']; ?>" name="rental_end" required style="width: 100%; font-size: 0.88rem; padding: 0.4rem;">
                                                    </div>
                                                </div>
                                                <button type="submit" class="btn btn-rent">Rent Now</button>
                                            </form>
                                        <?php else: ?>
                                            <div style="margin-top: auto; text-align: center;">
                                                <button class="btn btn-rent" disabled style="opacity: 0.5; background-color: var(--text-muted); cursor: not-allowed;">Unavailable</button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
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
                                <th>Rental Start</th>
                                <th>Due Date</th> <!-- Renamed to Return Due Date -->
                                <th>Cost</th>
                                <th>Assigned Tech</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($my_rentals as $rental): ?>
                                <tr>
                                    <td>#<?php echo $rental['rental_id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($rental['vehicle_name']); ?></strong></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($rental['rental_start'])); ?></td>
                                    <td><strong style="color: var(--primary-blue);"><?php echo date('M d, Y h:i A', strtotime($rental['rental_end'])); ?></strong></td>
                                    <td>$<?php echo number_format($rental['total_cost'], 2); ?></td>
                                    <td>
                                        <?php echo $rental['technician_name'] ? htmlspecialchars($rental['technician_name']) : '<em class="waiting">Awaiting Tech...</em>'; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($rental['status']); ?>">
                                            <?php echo $rental['status'] === 'active' ? 'Active' : 'Returned'; ?>
                                        </span>
                                    </td>
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
                <p><strong>System Role:</strong> Renter / Student</p>
                <p><strong>Institution:</strong> Xiamen University Malaysia (XMUM)</p>
                <p><strong>Theme Preference:</strong> XMUM Academic Blue & Gold (Default)</p>
            </div>
        </div>
    </main>
</div>

<script>
// Tab Swapping Script
function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.menu-item').forEach(el => el.classList.remove('active'));
    
    document.getElementById(tabId).classList.add('active');
    
    const btn = Array.from(document.querySelectorAll('.menu-item')).find(el => el.getAttribute('onclick').includes(tabId));
    if (btn) {
        btn.classList.add('active');
    }
}

// Client-Side Availability Filter Function
function toggleAvailabilityFilter() {
    const showOnlyAvailable = document.getElementById('filter-available').checked;
    const cards = document.querySelectorAll('.vehicle-card');
    
    cards.forEach(card => {
        const status = card.getAttribute('data-status');
        if (showOnlyAvailable && status !== 'available') {
            card.style.display = 'none';
        } else {
            card.style.display = 'flex';
        }
    });
    
    // Hide empty category sections dynamically
    const sections = document.querySelectorAll('.category-section');
    sections.forEach(section => {
        const totalCards = section.querySelectorAll('.vehicle-card').length;
        const hiddenCards = section.querySelectorAll('.vehicle-card[style*="display: none"]').length;
        if (showOnlyAvailable && hiddenCards === totalCards) {
            section.style.display = 'none';
        } else {
            section.style.display = 'block';
        }
    });
}
</script>

<?php include 'php/footer.php'; ?>