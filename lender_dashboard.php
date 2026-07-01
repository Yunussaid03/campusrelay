<?php
session_start();
require 'php/db_connect.php';

// Protect the page: customer role is student (can act as renter and lender)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header("Location: index.php");
    exit;
}

$lender_id = $_SESSION['user_id'];

// --- FORM ACTION: Add New Listing ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_listing'])) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $price = (float)$_POST['price'];
    $category = trim($_POST['category']);
    $location = $_POST['location'];
    
    // Handle image file upload
    $image_path = 'assets/images/placeholder.jpg'; // default fallback
    
    if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['image_file']['tmp_name'];
        $file_name = $_FILES['image_file']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($file_ext, $allowed_exts)) {
            $new_file_name = uniqid('vehicle_', true) . '.' . $file_ext;
            $dest_path = 'assets/images/' . $new_file_name;
            
            // Move file to assets folder
            if (move_uploaded_file($file_tmp, $dest_path)) {
                $image_path = $dest_path;
            }
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO vehicles (name, description, price, category, location, image_path, owner_id, availability_status) 
            VALUES (:name, :description, :price, :category, :location, :image_path, :owner_id, 'available')
        ");
        $stmt->execute([
            ':name' => $name,
            ':description' => $description,
            ':price' => $price,
            ':category' => $category,
            ':location' => $location,
            ':image_path' => $image_path,
            ':owner_id' => $lender_id
        ]);
        header("Location: lender_dashboard.php?success=New item listed successfully!");
        exit;
    } catch (Exception $e) {
        header("Location: lender_dashboard.php?error=Failed to add listing: " . urlencode($e->getMessage()));
        exit;
    }
}

// --- FORM ACTION: Delete Listing ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_listing'])) {
    $vehicle_id = (int)$_POST['vehicle_id'];

    try {
        $stmt = $pdo->prepare("DELETE FROM vehicles WHERE vehicle_id = :vehicle_id AND owner_id = :owner_id");
        $stmt->execute([
            ':vehicle_id' => $vehicle_id,
            ':owner_id' => $lender_id
        ]);
        header("Location: lender_dashboard.php?success=Listing deleted successfully!");
        exit;
    } catch (Exception $e) {
        header("Location: lender_dashboard.php?error=Failed to delete listing: " . urlencode($e->getMessage()));
        exit;
    }
}

// --- FORM ACTION: Accept Rental Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_rental'])) {
    $rental_id = (int)$_POST['rental_id'];

    try {
        $pdo->beginTransaction();

        // Verify rental belongs to a vehicle owned by this lender
        $stmtCheck = $pdo->prepare("
            SELECT r.rental_id, r.vehicle_id 
            FROM rentals r
            JOIN vehicles v ON r.vehicle_id = v.vehicle_id
            WHERE r.rental_id = :rental_id AND v.owner_id = :owner_id AND r.status = 'pending'
            FOR UPDATE
        ");
        $stmtCheck->execute([':rental_id' => $rental_id, ':owner_id' => $lender_id]);
        $rental = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$rental) {
            throw new Exception("Unauthorized or invalid request.");
        }

        // Update rental to 'active'
        $stmtUpdateRental = $pdo->prepare("UPDATE rentals SET status = 'active' WHERE rental_id = :rental_id");
        $stmtUpdateRental->execute([':rental_id' => $rental_id]);

        // Update vehicle status to 'reserved' (blocking other rentals)
        $stmtUpdateVehicle = $pdo->prepare("UPDATE vehicles SET availability_status = 'reserved' WHERE vehicle_id = :vehicle_id");
        $stmtUpdateVehicle->execute([':vehicle_id' => $rental['vehicle_id']]);

        $pdo->commit();
        header("Location: lender_dashboard.php?success=Rental request accepted!");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: lender_dashboard.php?error=Failed to accept request: " . urlencode($e->getMessage()));
        exit;
    }
}

// --- FORM ACTION: Complete Return ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_return'])) {
    $rental_id = (int)$_POST['rental_id'];

    try {
        $pdo->beginTransaction();

        // Verify rental belongs to a vehicle owned by this lender
        $stmtCheck = $pdo->prepare("
            SELECT r.rental_id, r.vehicle_id 
            FROM rentals r
            JOIN vehicles v ON r.vehicle_id = v.vehicle_id
            WHERE r.rental_id = :rental_id AND v.owner_id = :owner_id AND r.status = 'active'
            FOR UPDATE
        ");
        $stmtCheck->execute([':rental_id' => $rental_id, ':owner_id' => $lender_id]);
        $rental = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$rental) {
            throw new Exception("Unauthorized or invalid active rental.");
        }

        // Update rental to 'returned'
        $stmtUpdateRental = $pdo->prepare("UPDATE rentals SET status = 'returned' WHERE rental_id = :rental_id");
        $stmtUpdateRental->execute([':rental_id' => $rental_id]);

        // Update vehicle status back to 'available'
        $stmtUpdateVehicle = $pdo->prepare("UPDATE vehicles SET availability_status = 'available' WHERE vehicle_id = :vehicle_id");
        $stmtUpdateVehicle->execute([':vehicle_id' => $rental['vehicle_id']]);

        $pdo->commit();
        header("Location: lender_dashboard.php?success=Return marked as completed successfully!");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: lender_dashboard.php?error=Failed to complete return: " . urlencode($e->getMessage()));
        exit;
    }
}

// --- FETCH DATA ---
// 1. Listings owned by this lender
$listings_stmt = $pdo->prepare("SELECT * FROM vehicles WHERE owner_id = :owner_id ORDER BY name");
$listings_stmt->execute([':owner_id' => $lender_id]);
$my_listings = $listings_stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Pending and active requests for their listings
$requests_stmt = $pdo->prepare("
    SELECT r.rental_id, r.total_cost, r.status, r.rental_start, r.rental_end, r.created_at,
           v.name AS vehicle_name,
           u_renter.name AS renter_name
    FROM rentals r
    JOIN vehicles v ON r.vehicle_id = v.vehicle_id
    JOIN users u_renter ON r.renter_id = u_renter.user_id
    WHERE v.owner_id = :owner_id AND r.status IN ('pending', 'active')
    ORDER BY r.created_at DESC
");
$requests_stmt->execute([':owner_id' => $lender_id]);
$incoming_requests = $requests_stmt->fetchAll(PDO::FETCH_ASSOC);

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
            <button class="menu-item active" onclick="switchTab('listings-tab')">My Listings (<?php echo count($my_listings); ?>)</button>
            <button class="menu-item" onclick="switchTab('add-tab')">Add Listing</button>
            <button class="menu-item" onclick="switchTab('requests-tab')">Rental Requests (<?php echo count($incoming_requests); ?>)</button>
            <hr style="border: 0; border-top: 1px solid rgba(255,255,255,0.15); margin: 0.5rem 0;">
            <a href="renter_dashboard.php" class="menu-item" style="text-decoration: none; display: block; color: var(--heritage-gold); font-weight: bold; border-left: none;">
                Switch to Renting &rarr;
            </a>
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
            <h2>XMUM Lender Console</h2>
            <span class="role-badge" style="background-color: var(--primary-blue); color: var(--heritage-gold);">Lender / Host</span>
        </div>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <!-- Listings Tab Content -->
        <div id="listings-tab" class="tab-content active">
            <h3 class="category-title">My Registered Listings</h3>
            <hr class="category-divider">

            <?php if (empty($my_listings)): ?>
                <p style="text-align: center; color: #7f8c8d; padding: 3rem 1rem;">
                    You haven't listed any vehicles yet. Head over to the "Add Listing" tab to get started!
                </p>
            <?php else: ?>
                <div class="items-grid">
                    <?php foreach ($my_listings as $listing): ?>
                        <?php 
                            $image_src = !empty($listing['image_path']) ? htmlspecialchars($listing['image_path']) : 'assets/images/placeholder.jpg';
                        ?>
                        <div class="item-card vehicle-card">
                            <img src="<?php echo $image_src; ?>" alt="<?php echo htmlspecialchars($listing['name']); ?>" class="vehicle-image">
                            <div class="card-body">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                                    <h3><?php echo htmlspecialchars($listing['name']); ?></h3>
                                    <span class="status-badge status-<?php echo strtolower($listing['availability_status']); ?>">
                                        <?php echo htmlspecialchars(ucfirst($listing['availability_status'])); ?>
                                    </span>
                                </div>
                                <p class="desc"><?php echo htmlspecialchars($listing['description']); ?></p>
                                <div style="margin-bottom: 1.25rem; font-size: 0.85rem; color: var(--text-muted);">
                                    <span><strong>Category:</strong> <?php echo htmlspecialchars($listing['category']); ?></span> |
                                    <span><strong>Location:</strong> <?php echo htmlspecialchars($listing['location']); ?></span>
                                </div>
                                <p class="price">RM <?php echo number_format($listing['price'], 2); ?> <span class="rate-unit">/ hr</span></p>
                                
                                <form action="lender_dashboard.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this listing?');" style="margin-top: auto;">
                                    <input type="hidden" name="vehicle_id" value="<?php echo $listing['vehicle_id']; ?>">
                                    <button type="submit" name="delete_listing" class="btn btn-rent" style="background-color: #ef4444;">Delete Listing</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Add Listing Tab Content -->
        <div id="add-tab" class="tab-content">
            <h3 class="category-title">List a New Vehicle / Item</h3>
            <hr class="category-divider">

            <div class="settings-card" style="max-width: 600px;">
                <!-- Multipart form for file uploads -->
                <form action="lender_dashboard.php" method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="name">Vehicle / Item Name</label>
                        <input type="text" id="name" name="name" placeholder="e.g. Segway KickScooter Max" required>
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="3" placeholder="Provide details (battery life, speed limit, size, etc.)" required style="width: 100%; padding: 0.75rem; border: 1.5px solid #cbd5e1; border-radius: var(--border-radius-sm); font-size: 0.95rem;"></textarea>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label for="category">Category</label>
                            <select id="category" name="category" required>
                                <option value="Scooters">Scooters</option>
                                <option value="Bicycles">Bicycles</option>
                                <option value="Skateboards">Skateboards</option>
                                <option value="Motorcycles">Motorcycles</option>
                                <option value="Accessories">Accessories</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="location">Parking Spot / Location</label>
                            <select id="location" name="location" required>
                                <option value="B1">B1</option>
                                <option value="D1-D6">D1-D6</option>
                                <option value="LY1-LY8">LY1-LY8</option>
                                <option value="A1-A5">A1-A5</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="price">Rental Price (RM per Hour)</label>
                        <input type="number" id="price" name="price" step="0.01" min="0.50" placeholder="e.g. 5.00" required>
                    </div>

                    <div class="form-group">
                        <label for="image_file">Upload Vehicle Image</label>
                        <input type="file" id="image_file" name="image_file" accept="image/*" style="border: none; padding: 0.5rem 0;">
                    </div>

                    <button type="submit" name="add_listing" class="btn" style="background-color: var(--heritage-gold); color: var(--primary-blue); font-weight: bold; margin-top: 1rem;">Publish Listing</button>
                </form>
            </div>
        </div>

        <!-- Rental Requests Tab Content -->
        <div id="requests-tab" class="tab-content">
            <h3 class="category-title">Active and Pending Rental Requests</h3>
            <hr class="category-divider">

            <?php if (empty($incoming_requests)): ?>
                <p style="text-align: center; color: #7f8c8d; padding: 3rem 1rem;">No active or pending requests at the moment.</p>
            <?php else: ?>
                <div class="items-grid">
                    <?php foreach ($incoming_requests as $request): ?>
                        <?php
                            $start = new DateTime($request['rental_start']);
                            $end = new DateTime($request['rental_end']);
                            $hours = $start->diff($end)->h + ($start->diff($end)->days * 24);
                            $is_pending = $request['status'] === 'pending';
                        ?>
                        <div class="item-card order-card" style="border-left-color: <?php echo $is_pending ? 'var(--heritage-gold)' : 'var(--primary-blue)'; ?>;">
                            <div class="card-body">
                                <div class="order-header">
                                    <h3>Request #<?php echo $request['rental_id']; ?></h3>
                                    <span class="status-badge status-<?php echo strtolower($request['status']); ?>">
                                        <?php echo ucfirst($request['status']); ?>
                                    </span>
                                </div>
                                <p style="margin-bottom: 0.5rem;"><strong>Vehicle:</strong> <?php echo htmlspecialchars($request['vehicle_name']); ?></p>
                                <p style="margin-bottom: 0.5rem;"><strong>Renter:</strong> <?php echo htmlspecialchars($request['renter_name']); ?></p>
                                <p style="margin-bottom: 0.5rem;"><strong>Timeframe:</strong> <?php echo date('M d, h:i A', strtotime($request['rental_start'])); ?> - <?php echo date('M d, h:i A', strtotime($request['rental_end'])); ?></p>
                                <p style="margin-bottom: 1.25rem;"><strong>Duration:</strong> <?php echo $hours; ?> hr<?php echo $hours > 1 ? 's' : ''; ?></p>
                                <p class="price" style="margin-bottom: 1.5rem;">Earnings: RM <?php echo number_format($request['total_cost'], 2); ?></p>
                                
                                <?php if ($is_pending): ?>
                                    <!-- Accept Action Form -->
                                    <form action="lender_dashboard.php" method="POST" style="margin-top: auto;">
                                        <input type="hidden" name="rental_id" value="<?php echo $request['rental_id']; ?>">
                                        <button type="submit" name="approve_rental" class="btn btn-accept" style="width: 100%;">Accept Request</button>
                                    </form>
                                <?php else: ?>
                                    <!-- Return Action Form -->
                                    <form action="lender_dashboard.php" method="POST" style="margin-top: auto;">
                                        <input type="hidden" name="rental_id" value="<?php echo $request['rental_id']; ?>">
                                        <button type="submit" name="complete_return" class="btn btn-complete" style="width: 100%;">Mark Return Completed</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
</script>

<?php include 'php/footer.php'; ?>
