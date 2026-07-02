<?php
session_start();
require 'php/db_connect.php';

// Protect the page
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$lender_id = $_SESSION['user_id'];

// --- FETCH LENDER WALLET BALANCE ---
$user_stmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE user_id = :user_id");
$user_stmt->execute([':user_id' => $lender_id]);
$user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
$wallet_balance = $user_data ? (float)$user_data['wallet_balance'] : 0.00;

// --- POST ACTION: Add New Listing ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_listing'])) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $price = (float)$_POST['price'];
    $category = trim($_POST['category']);
    $location = $_POST['location'];
    $lock_code = trim($_POST['lock_code']);
    $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : 2.832;
    $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : 101.706;
    
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
            
            if (move_uploaded_file($file_tmp, $dest_path)) {
                $image_path = $dest_path;
            }
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO vehicles (name, description, price, category, location, image_path, lock_code, latitude, longitude, owner_id, availability_status) 
            VALUES (:name, :description, :price, :category, :location, :image_path, :lock_code, :latitude, :longitude, :owner_id, 'available')
        ");
        $stmt->execute([
            ':name' => $name,
            ':description' => $description,
            ':price' => $price,
            ':category' => $category,
            ':location' => $location,
            ':image_path' => $image_path,
            ':lock_code' => $lock_code,
            ':latitude' => $latitude,
            ':longitude' => $longitude,
            ':owner_id' => $lender_id
        ]);
        header("Location: lender_dashboard.php?success=New P2P vehicle listed successfully!");
        exit;
    } catch (Exception $e) {
        header("Location: lender_dashboard.php?error=Failed to add listing: " . urlencode($e->getMessage()));
        exit;
    }
}

// --- POST ACTION: Delete Listing ---
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

// --- POST ACTION: Accept Rental Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_rental'])) {
    $rental_id = (int)$_POST['rental_id'];

    try {
        $pdo->beginTransaction();

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

        // Update rental status to 'active'
        $stmtUpdateRental = $pdo->prepare("UPDATE rentals SET status = 'active' WHERE rental_id = :rental_id");
        $stmtUpdateRental->execute([':rental_id' => $rental_id]);

        // Update vehicle status to 'reserved'
        $stmtUpdateVehicle = $pdo->prepare("UPDATE vehicles SET availability_status = 'reserved' WHERE vehicle_id = :vehicle_id");
        $stmtUpdateVehicle->execute([':vehicle_id' => $rental['vehicle_id']]);

        $pdo->commit();
        header("Location: lender_dashboard.php?success=Rental request accepted successfully!");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: lender_dashboard.php?error=Failed to accept request: " . urlencode($e->getMessage()));
        exit;
    }
}

// --- POST ACTION: Complete Return (Refunds Deposit) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_return'])) {
    $rental_id = (int)$_POST['rental_id'];

    try {
        $pdo->beginTransaction();

        // Fetch rental details
        $stmtCheck = $pdo->prepare("
            SELECT r.rental_id, r.vehicle_id, r.renter_id, r.security_deposit
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

        // 1. Update rental status to 'returned'
        $stmtUpdateRental = $pdo->prepare("UPDATE rentals SET status = 'returned' WHERE rental_id = :rental_id");
        $stmtUpdateRental->execute([':rental_id' => $rental_id]);

        // 2. Set vehicle availability status back to 'available'
        $stmtUpdateVehicle = $pdo->prepare("UPDATE vehicles SET availability_status = 'available' WHERE vehicle_id = :vehicle_id");
        $stmtUpdateVehicle->execute([':vehicle_id' => $rental['vehicle_id']]);

        // 3. REFUND deposit held in escrow back to Renter's wallet balance
        $stmtRefund = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + :deposit WHERE user_id = :renter_id");
        $stmtRefund->execute([
            ':deposit' => $rental['security_deposit'],
            ':renter_id' => $rental['renter_id']
        ]);

        $pdo->commit();
        header("Location: lender_dashboard.php?success=" . urlencode("Return completed successfully! RM " . number_format($rental['security_deposit'], 2) . " security deposit refunded to renter."));
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: lender_dashboard.php?error=Failed to complete return: " . urlencode($e->getMessage()));
        exit;
    }
}

// --- POST ACTION: File Dispute (Freezes Deposit) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file_dispute'])) {
    $rental_id = (int)$_POST['rental_id'];
    $dispute_reason = trim($_POST['dispute_reason']);

    try {
        $pdo->beginTransaction();

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

        // 1. Mark rental as returned, but is_disputed = 1, and save dispute reason (wallet balance not refunded)
        $stmtDispute = $pdo->prepare("
            UPDATE rentals 
            SET status = 'returned', is_disputed = 1, dispute_reason = :reason 
            WHERE rental_id = :rental_id
        ");
        $stmtDispute->execute([
            ':reason' => $dispute_reason,
            ':rental_id' => $rental_id
        ]);

        // 2. Set vehicle availability status back to 'available'
        $stmtUpdateVehicle = $pdo->prepare("UPDATE vehicles SET availability_status = 'available' WHERE vehicle_id = :vehicle_id");
        $stmtUpdateVehicle->execute([':vehicle_id' => $rental['vehicle_id']]);

        $pdo->commit();
        header("Location: lender_dashboard.php?success=Dispute filed successfully! Security deposit frozen in escrow for admin check.");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: lender_dashboard.php?error=Failed to file dispute: " . urlencode($e->getMessage()));
        exit;
    }
}

// --- POST ACTION: Submit Review ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $rental_id = (int)$_POST['rental_id'];
    $rating = (int)$_POST['rating'];
    $review_text = trim($_POST['review_text']);
    $target_id = (int)$_POST['target_id'];
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO reviews (rental_id, reviewer_id, target_id, rating, review_text) 
            VALUES (:rental_id, :reviewer_id, :target_id, :rating, :review_text)
        ");
        $stmt->execute([
            ':rental_id' => $rental_id,
            ':reviewer_id' => $lender_id,
            ':target_id' => $target_id,
            ':rating' => $rating,
            ':review_text' => $review_text
        ]);
        header("Location: lender_dashboard.php?success=Renter review submitted successfully!");
        exit;
    } catch (Exception $e) {
        header("Location: lender_dashboard.php?error=Failed to submit review: " . urlencode($e->getMessage()));
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
    SELECT r.rental_id, r.total_cost, r.status, r.rental_start, r.rental_end, r.created_at, r.renter_notes, r.return_image, r.is_disputed, r.dispute_reason,
           v.name AS vehicle_name,
           u_renter.name AS renter_name, u_renter.user_id AS renter_id
    FROM rentals r
    JOIN vehicles v ON r.vehicle_id = v.vehicle_id
    JOIN users u_renter ON r.renter_id = u_renter.user_id
    WHERE v.owner_id = :owner_id AND r.status IN ('pending', 'active')
    ORDER BY r.created_at DESC
");
$requests_stmt->execute([':owner_id' => $lender_id]);
$incoming_requests = $requests_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Past completed rentals (to display review/rate option for renters!)
$past_rentals_stmt = $pdo->prepare("
    SELECT r.rental_id, r.total_cost, r.rental_start, r.rental_end, r.created_at,
           v.name AS vehicle_name,
           u_renter.name AS renter_name, u_renter.user_id AS renter_id
    FROM rentals r
    JOIN vehicles v ON r.vehicle_id = v.vehicle_id
    JOIN users u_renter ON r.renter_id = u_renter.user_id
    WHERE v.owner_id = :owner_id AND r.status = 'returned'
    ORDER BY r.created_at DESC
    LIMIT 10
");
$past_rentals_stmt->execute([':owner_id' => $lender_id]);
$past_completed_rentals = $past_rentals_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch rental IDs that already have reviews submitted by this lender
$reviewed_stmt = $pdo->prepare("SELECT rental_id FROM reviews WHERE reviewer_id = :reviewer_id");
$reviewed_stmt->execute([':reviewer_id' => $lender_id]);
$reviewed_rentals = $reviewed_stmt->fetchAll(PDO::FETCH_COLUMN, 0);

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
        
        <!-- Escrow Wallet Box -->
        <div class="wallet-box" style="margin: 0.5rem auto 1.5rem auto; width: 90%; background: rgba(255, 255, 255, 0.15); border-color: rgba(255,255,255,0.25); color: white; display: flex; flex-direction: column; align-items: flex-start; gap: 0.25rem;">
            <span style="font-size: 0.78rem; color: rgba(255, 255, 255, 0.7); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">My Wallet Balance:</span>
            <span class="wallet-balance-val" style="color: var(--heritage-gold); font-size: 1.25rem; font-weight: 800;">RM <?php echo number_format($wallet_balance, 2); ?></span>
        </div>

        <nav class="sidebar-menu">
            <button class="menu-item active" onclick="switchTab('listings-tab')">My Listings (<?php echo count($my_listings); ?>)</button>
            <button class="menu-item" onclick="switchTab('add-tab')">Add Listing</button>
            <button class="menu-item" onclick="switchTab('requests-tab')">Rental Requests (<?php echo count($incoming_requests); ?>)</button>
            <button class="menu-item" onclick="switchTab('feedback-tab')">Past Returns (<?php echo count($past_completed_rentals); ?>)</button>
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
                                <div style="margin-bottom: 1.25rem; font-size: 0.85rem; color: var(--text-muted); display: flex; flex-direction: column; gap: 0.25rem;">
                                    <span><strong>Category:</strong> <?php echo htmlspecialchars($listing['category']); ?></span>
                                    <span><strong>Parking Spot:</strong> <span class="role-badge" style="padding:0.1rem 0.5rem; font-size:0.75rem; display:inline-block;"><?php echo htmlspecialchars($listing['location']); ?></span></span>
                                    <span><strong>Lock Code:</strong> <code><?php echo htmlspecialchars($listing['lock_code']); ?></code></span>
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
                                <option value="Cars">Cars</option>
                                <option value="Accessories">Accessories</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="location">Parking Spot / Location</label>
                            <select id="location" name="location" required>
                                <option value="B1">B1</option>
                                <option value="D1">D1</option>
                                <option value="D2">D2</option>
                                <option value="D3">D3</option>
                                <option value="D4">D4</option>
                                <option value="D5">D5</option>
                                <option value="D6">D6</option>
                                <option value="LY1">LY1</option>
                                <option value="LY2">LY2</option>
                                <option value="LY3">LY3</option>
                                <option value="LY4">LY4</option>
                                <option value="LY5">LY5</option>
                                <option value="LY6">LY6</option>
                                <option value="LY7">LY7</option>
                                <option value="LY8">LY8</option>
                                <option value="A1">A1</option>
                                <option value="A2">A2</option>
                                <option value="A3">A3</option>
                                <option value="A4">A4</option>
                                <option value="A5">A5</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="price">Rental Price (RM per Hour)</label>
                        <input type="number" id="price" name="price" step="0.01" min="0.50" placeholder="e.g. 5.00" required>
                    </div>

                    <div class="form-group">
                        <label for="lock_code">Physical Lock Code / Combination (Shown ONLY to approved renters)</label>
                        <input type="text" id="lock_code" name="lock_code" placeholder="e.g. Key combination 9876 or Key box inside seat" required>
                    </div>

                    <div class="form-group">
                        <label>Select Precise Parking Pin on Map</label>
                        <div id="listing-map" style="height: 250px; border-radius: var(--border-radius-sm); border: 1px solid #cbd5e1; margin-bottom: 0.5rem; overflow:hidden;"></div>
                        <p style="font-size: 0.8rem; color: var(--text-muted);">Click anywhere on the map to set coordinates.</p>
                        <input type="hidden" id="latitude" name="latitude" value="2.832">
                        <input type="hidden" id="longitude" name="longitude" value="101.706">
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
                            $is_active = $request['status'] === 'active';
                            $has_checklist = !empty($request['renter_notes']);
                            $checklist_img = !empty($request['return_image']) ? htmlspecialchars($request['return_image']) : null;
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
                                <p style="margin-bottom: 0.5rem;"><strong>Duration:</strong> <?php echo $hours; ?> hr<?php echo $hours > 1 ? 's' : ''; ?></p>
                                <p class="price" style="margin-bottom: 1.25rem;">Earnings: RM <?php echo number_format($request['total_cost'], 2); ?></p>
                                
                                <?php if ($is_active && $has_checklist): ?>
                                    <!-- Display renter's submitted return inspection checklist -->
                                    <div style="background-color: rgba(16,185,129,0.08); border: 1.5px solid var(--success-green); padding: 0.75rem; border-radius: var(--border-radius-sm); margin-bottom: 1rem;">
                                        <p style="font-size: 0.82rem; font-weight: bold; color: #047857; margin-bottom: 0.25rem;">📋 Renter Return Checklist</p>
                                        <p style="font-size: 0.8rem; color: #065f46; margin-bottom: 0.5rem;">"<?php echo htmlspecialchars($request['renter_notes']); ?>"</p>
                                        <?php if ($checklist_img): ?>
                                            <img src="<?php echo $checklist_img; ?>" style="width: 100%; aspect-ratio: 16/9; object-fit: cover; border-radius: 4px;" alt="Checklist return image">
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div style="margin-top: auto; display: flex; flex-direction: column; gap: 0.5rem;">
                                    <!-- Direct Chat Button -->
                                    <button class="btn btn-rent" onclick="openChatModal(<?php echo $request['rental_id']; ?>, '<?php echo addslashes($request['renter_name']); ?>')" style="background-color: var(--primary-blue); color: white;">Open Chat</button>
                                    
                                    <?php if ($is_pending): ?>
                                        <form action="lender_dashboard.php" method="POST">
                                            <input type="hidden" name="rental_id" value="<?php echo $request['rental_id']; ?>">
                                            <button type="submit" name="approve_rental" class="btn btn-accept" style="width: 100%;">Accept Request</button>
                                        </form>
                                    <?php elseif ($is_active): ?>
                                        <form action="lender_dashboard.php" method="POST" onsubmit="return confirm('Confirm vehicle is returned undamaged and refund RM 20.00 deposit?');">
                                            <input type="hidden" name="rental_id" value="<?php echo $request['rental_id']; ?>">
                                            <button type="submit" name="complete_return" class="btn btn-complete" style="width: 100%; background-color: var(--success-green);">Mark Return Completed</button>
                                        </form>
                                        
                                        <!-- File Dispute Action -->
                                        <div class="checklist-form-container" style="background-color: #fee2e2; border-color: #fca5a5; margin-top: 0.5rem;">
                                            <p style="font-size: 0.82rem; font-weight: bold; color: var(--danger-red); margin-bottom: 0.5rem;">⚠️ Report Return Issue / Damage</p>
                                            <form action="lender_dashboard.php" method="POST" onsubmit="return confirm('Freeze renter deposit in escrow and file dispute?');">
                                                <input type="hidden" name="rental_id" value="<?php echo $request['rental_id']; ?>">
                                                <textarea name="dispute_reason" placeholder="Write dispute details (e.g. Scratched body, flat tire)" required style="font-size: 0.8rem;"></textarea>
                                                <button type="submit" name="file_dispute" class="btn" style="background-color: var(--danger-red); font-size: 0.8rem; padding: 0.4rem;">File Return Dispute</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Past Returns Tab Content -->
        <div id="feedback-tab" class="tab-content">
            <h3 class="category-title">Completed P2P Returns</h3>
            <hr class="category-divider">

            <?php if (empty($past_completed_rentals)): ?>
                <p style="text-align: center; color: #7f8c8d; padding: 3rem 1rem;">No completed P2P returns on record yet.</p>
            <?php else: ?>
                <div class="items-grid">
                    <?php foreach ($past_completed_rentals as $rental): ?>
                        <div class="item-card order-card" style="border-left-color: var(--success-green);">
                            <div class="card-body">
                                <div class="order-header">
                                    <h3>Completed Return #<?php echo $rental['rental_id']; ?></h3>
                                </div>
                                <p style="margin-bottom: 0.5rem;"><strong>Vehicle:</strong> <?php echo htmlspecialchars($rental['vehicle_name']); ?></p>
                                <p style="margin-bottom: 0.5rem;"><strong>Renter:</strong> <?php echo htmlspecialchars($rental['renter_name']); ?></p>
                                <p style="margin-bottom: 1.25rem;"><strong>Earnings:</strong> RM <?php echo number_format($rental['total_cost'], 2); ?></p>

                                <div style="margin-top: auto; display: flex; flex-direction: column; gap: 0.5rem;">
                                    <button class="btn btn-rent" onclick="openChatModal(<?php echo $rental['rental_id']; ?>, '<?php echo addslashes($rental['renter_name']); ?>')" style="background-color: var(--primary-blue); color: white;">Open Chat</button>
                                    
                                    <?php if (!in_array($rental['rental_id'], $reviewed_rentals)): ?>
                                        <!-- Star Rate Renter Form -->
                                        <div class="checklist-form-container" style="background-color: rgba(214,175,55,0.05); border-color: rgba(214,175,55,0.25);">
                                            <p style="font-size: 0.82rem; font-weight: bold; color: var(--primary-blue); margin-bottom: 0.5rem;">⭐️ Rate Renter: <?php echo htmlspecialchars($rental['renter_name']); ?></p>
                                            <form action="lender_dashboard.php" method="POST">
                                                <input type="hidden" name="rental_id" value="<?php echo $rental['rental_id']; ?>">
                                                <input type="hidden" name="target_id" value="<?php echo $rental['renter_id']; ?>">
                                                <input type="hidden" name="rating" id="rating_input_<?php echo $rental['rental_id']; ?>" value="5">
                                                
                                                <div class="rating-group">
                                                    <?php for ($i=1; $i<=5; $i++): ?>
                                                        <button type="button" class="rating-star-btn selected" data-val="<?php echo $i; ?>" onclick="setStarRating(this, <?php echo $rental['rental_id']; ?>)">★</button>
                                                    <?php endfor; ?>
                                                </div>
                                                <textarea name="review_text" placeholder="Write feedback..." style="font-size: 0.8rem;"></textarea>
                                                <button type="submit" name="submit_review" class="btn btn-complete" style="padding: 0.4rem; font-size: 0.8rem; background-color: var(--heritage-gold); color: var(--primary-blue); font-weight: bold;">Submit Review</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Chat Modal -->
<div id="chatModal" class="modal-chat">
    <div class="chat-modal-content">
        <div class="chat-header">
            <h3 id="chatTitle">Peer Chat</h3>
            <button class="btn-close-chat" onclick="closeChatModal()">&times;</button>
        </div>
        <div id="chatMessages" class="chat-messages">
            <!-- Messages bubble load asynchronously -->
        </div>
        <form id="chatForm" onsubmit="sendChatMessage(event)" class="chat-input-form">
            <input type="hidden" id="chatRentalId" name="rental_id">
            <input type="text" id="chatInputText" placeholder="Type a message..." required autocomplete="off">
            <button type="submit" class="btn-send-msg">Send</button>
        </form>
    </div>
</div>

<script>
// --- LEAFLET MAP IN ADD LISTINGS ---
document.addEventListener('DOMContentLoaded', () => {
    // Check if element exists
    if (!document.getElementById('listing-map')) return;
    
    // Initialize map focused on XMUM campus
    const map = L.map('listing-map').setView([2.832, 101.706], 15);
    
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19
    }).addTo(map);

    let marker = L.marker([2.832, 101.706], {draggable: true}).addTo(map);
    
    // Set hidden forms values on dragend
    marker.on('dragend', function (e) {
        const lat = marker.getLatLng().lat;
        const lng = marker.getLatLng().lng;
        document.getElementById('latitude').value = lat;
        document.getElementById('longitude').value = lng;
    });

    // Move marker and set hidden values on click
    map.on('click', function (e) {
        marker.setLatLng(e.latlng);
        document.getElementById('latitude').value = e.latlng.lat;
        document.getElementById('longitude').value = e.latlng.lng;
    });
});

// --- TAB SWAPPING ---
function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.menu-item').forEach(el => el.classList.remove('active'));
    
    document.getElementById(tabId).classList.add('active');
    
    const btn = Array.from(document.querySelectorAll('.menu-item')).find(el => el.getAttribute('onclick').includes(tabId));
    if (btn) {
        btn.classList.add('active');
    }
}

// --- PEER CHAT ENGINE ---
let chatInterval = null;
let currentRentalId = null;

function openChatModal(rentalId, partnerName) {
    currentRentalId = rentalId;
    document.getElementById('chatRentalId').value = rentalId;
    document.getElementById('chatTitle').textContent = "Chat with " + partnerName;
    document.getElementById('chatModal').style.display = 'flex';
    loadChatMessages();
    
    if (chatInterval) clearInterval(chatInterval);
    chatInterval = setInterval(loadChatMessages, 3000);
}

function closeChatModal() {
    document.getElementById('chatModal').style.display = 'none';
    if (chatInterval) {
        clearInterval(chatInterval);
        chatInterval = null;
    }
}

function loadChatMessages() {
    if (!currentRentalId) return;
    
    fetch('php/fetch_messages.php?rental_id=' + currentRentalId)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const chatMessages = document.getElementById('chatMessages');
                let html = '';
                const currentUserId = <?php echo $_SESSION['user_id']; ?>;
                
                data.messages.forEach(msg => {
                    const isSent = parseInt(msg.sender_id) === currentUserId;
                    const bubbleClass = isSent ? 'sent' : 'received';
                    html += `<div class="chat-message-bubble ${bubbleClass}">
                                <span class="msg-sender-name">${msg.sender_name}</span>
                                ${escapeHtml(msg.message_text)}
                             </div>`;
                });
                
                chatMessages.innerHTML = html;
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
        });
}

function sendChatMessage(e) {
    e.preventDefault();
    const inputText = document.getElementById('chatInputText');
    const text = inputText.value.trim();
    if (!text || !currentRentalId) return;
    
    const formData = new FormData();
    formData.append('rental_id', currentRentalId);
    formData.append('message_text', text);
    
    fetch('php/send_message.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            inputText.value = '';
            loadChatMessages();
        } else {
            alert('Failed to send message: ' + data.error);
        }
    });
}

function escapeHtml(text) {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// --- STAR RATING CONTROLLER ---
function setStarRating(btn, rentalId) {
    const parent = btn.parentElement;
    const ratingVal = parseInt(btn.getAttribute('data-val'));
    const input = document.getElementById('rating_input_' + rentalId);
    input.value = ratingVal;
    
    const stars = parent.querySelectorAll('.rating-star-btn');
    stars.forEach(star => {
        const starVal = parseInt(star.getAttribute('data-val'));
        if (starVal <= ratingVal) {
            star.classList.add('selected');
        } else {
            star.classList.remove('selected');
        }
    });
}
</script>

<?php include 'php/footer.php'; ?>
