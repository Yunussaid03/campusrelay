<?php
session_start();
require 'php/db_connect.php';

// Protect the page
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$renter_id = $_SESSION['user_id'];

// --- FETCH RENTER WALLET BALANCE ---
$user_stmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE user_id = :user_id");
$user_stmt->execute([':user_id' => $renter_id]);
$user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
$wallet_balance = $user_data ? (float)$user_data['wallet_balance'] : 0.00;

// --- POST ACTION: Submit Return Checklist ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_return_checklist'])) {
    $rental_id = (int)$_POST['rental_id'];
    $renter_notes = trim($_POST['renter_notes']);
    
    $return_image = NULL;
    if (isset($_FILES['return_image']) && $_FILES['return_image']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['return_image']['tmp_name'];
        $file_name = $_FILES['return_image']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($file_ext, $allowed)) {
            $new_name = uniqid('return_', true) . '.' . $file_ext;
            $dest = 'assets/images/' . $new_name;
            if (move_uploaded_file($file_tmp, $dest)) {
                $return_image = $dest;
            }
        }
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE rentals 
            SET renter_notes = :notes, return_image = :image 
            WHERE rental_id = :rental_id AND renter_id = :renter_id AND status = 'active'
        ");
        $stmt->execute([
            ':notes' => $renter_notes,
            ':image' => $return_image,
            ':rental_id' => $rental_id,
            ':renter_id' => $renter_id
        ]);
        header("Location: renter_dashboard.php?success=" . urlencode("Return checklist submitted! Please wait for the owner to verify and complete the check-in."));
        exit;
    } catch (Exception $e) {
        header("Location: renter_dashboard.php?error=Failed to submit return details: " . urlencode($e->getMessage()));
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
            ':reviewer_id' => $renter_id,
            ':target_id' => $target_id,
            ':rating' => $rating,
            ':review_text' => $review_text
        ]);
        header("Location: renter_dashboard.php?success=Review submitted successfully! Thank you.");
        exit;
    } catch (Exception $e) {
        header("Location: renter_dashboard.php?error=Failed to submit review: " . urlencode($e->getMessage()));
        exit;
    }
}

// Fetch available vehicles listed by other students (P2P)
$stmt = $pdo->prepare("
    SELECT v.*, u.name AS owner_name 
    FROM vehicles v 
    JOIN users u ON v.owner_id = u.user_id 
    WHERE v.owner_id != :current_user_id 
    ORDER BY v.category, v.name
");
$stmt->execute([':current_user_id' => $renter_id]);
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize vehicles into an array grouped by category
$categorized_vehicles = [];
foreach ($vehicles as $vehicle) {
    $categorized_vehicles[$vehicle['category']][] = $vehicle;
}

// Fetch active, pending, and past rentals placed by this renter
$rental_stmt = $pdo->prepare("
    SELECT 
        r.rental_id, r.total_cost, r.status, r.rental_start, r.rental_end, r.created_at, r.renter_notes, r.return_image, r.is_disputed, r.dispute_reason,
        v.name AS vehicle_name, v.lock_code,
        u_owner.name AS owner_name, u_owner.user_id AS owner_id
    FROM rentals r
    JOIN vehicles v ON r.vehicle_id = v.vehicle_id
    JOIN users u_owner ON v.owner_id = u_owner.user_id
    WHERE r.renter_id = :renter_id
    ORDER BY r.created_at DESC
");
$rental_stmt->execute([':renter_id' => $renter_id]);
$my_rentals = $rental_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch rental IDs that already have reviews submitted by this renter
$reviewed_stmt = $pdo->prepare("SELECT rental_id FROM reviews WHERE reviewer_id = :reviewer_id");
$reviewed_stmt->execute([':reviewer_id' => $renter_id]);
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
            <span style="font-size: 0.78rem; color: rgba(255, 255, 255, 0.7); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">My Wallet Escrow:</span>
            <span class="wallet-balance-val" style="color: var(--heritage-gold); font-size: 1.25rem; font-weight: 800;">RM <?php echo number_format($wallet_balance, 2); ?></span>
        </div>

        <nav class="sidebar-menu">
            <button class="menu-item active" onclick="switchTab('vehicles-tab')">Marketplace</button>
            <button class="menu-item" onclick="switchTab('rentals-tab')">My Rentals (<?php echo count($my_rentals); ?>)</button>
            <button class="menu-item" onclick="switchTab('settings-tab')">Account Settings</button>
            <hr style="border: 0; border-top: 1px solid rgba(255,255,255,0.15); margin: 0.5rem 0;">
            <a href="lender_dashboard.php" class="menu-item" style="text-decoration: none; display: block; color: var(--heritage-gold); font-weight: bold; border-left: none;">
                &larr; Switch to Lending
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
            <h2>XMUM P2P Marketplace</h2>
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
            <!-- Browse Campus map -->
            <div class="category-section" style="margin-bottom: 2rem;">
                <h3 class="category-title">Interactive Campus Browse Map</h3>
                <hr class="category-divider">
                <div id="marketplace-map" style="height: 350px; border-radius: var(--border-radius-md); border: 1px solid rgba(0,51,102,0.08); box-shadow: var(--card-shadow); overflow: hidden;"></div>
            </div>

            <p class="subtitle">Browse and rent vehicles listed by fellow XMUM students:</p>
            
            <!-- Filters: Availability and Location -->
            <div style="display: flex; gap: 1rem; align-items: center; margin-bottom: 2rem; flex-wrap: wrap;">
                <div class="filter-bar" style="display: flex; align-items: center; gap: 0.5rem; background: rgba(255, 255, 255, 0.7); padding: 0.65rem 1.25rem; border-radius: var(--border-radius-sm); border: 1px solid rgba(0, 51, 102, 0.08);">
                    <input type="checkbox" id="filter-available" onchange="filterMarketplace()" style="cursor: pointer; width: 18px; height: 18px;">
                    <label for="filter-available" style="font-weight: 600; font-size: 0.88rem; color: var(--primary-blue); cursor: pointer; user-select: none;">Show Only Available</label>
                </div>
                
                <div class="filter-bar" style="display: flex; align-items: center; gap: 0.5rem; background: rgba(255, 255, 255, 0.7); padding: 0.5rem 1.25rem; border-radius: var(--border-radius-sm); border: 1px solid rgba(0, 51, 102, 0.08);">
                    <label for="filter-location" style="font-weight: 600; font-size: 0.88rem; color: var(--primary-blue);">Location:</label>
                    <select id="filter-location" onchange="filterMarketplace()" style="border: none; background: transparent; font-weight: 600; color: var(--text-dark); cursor: pointer; outline: none;">
                        <option value="all">All Locations</option>
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
            
            <?php if (empty($categorized_vehicles)): ?>
                <p style="text-align: center; color: #7f8c8d; padding: 3rem 1rem;">No listings available from other students right now.</p>
            <?php else: ?>
                <?php foreach ($categorized_vehicles as $category => $category_vehicles): ?>
                    <div class="category-section">
                        <h3 class="category-title"><?php echo htmlspecialchars($category); ?></h3>
                        <hr class="category-divider">
                        
                        <div class="items-grid">
                            <?php foreach ($category_vehicles as $vehicle): ?>
                                <?php 
                                    $image_src = !empty($vehicle['image_path']) ? htmlspecialchars($vehicle['image_path']) : 'assets/images/placeholder.jpg';
                                    $is_available = $vehicle['availability_status'] === 'available';
                                ?>
                                <div class="item-card vehicle-card" data-status="<?php echo $vehicle['availability_status']; ?>" data-location="<?php echo $vehicle['location']; ?>">
                                    <img src="<?php echo $image_src; ?>" alt="<?php echo htmlspecialchars($vehicle['name']); ?>" class="vehicle-image">
                                    <div class="card-body">
                                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem; gap: 0.5rem;">
                                            <h3 style="margin-bottom: 0;"><?php echo htmlspecialchars($vehicle['name']); ?></h3>
                                            <span class="status-badge status-<?php echo strtolower($vehicle['availability_status']); ?>">
                                                <?php 
                                                    if ($vehicle['availability_status'] === 'available') echo 'Available';
                                                    else if ($vehicle['availability_status'] === 'reserved') echo 'Reserved';
                                                    else echo 'In Use';
                                                ?>
                                            </span>
                                        </div>
                                        
                                        <p class="desc"><?php echo htmlspecialchars($vehicle['description']); ?></p>
                                        
                                        <div style="margin-bottom: 1.25rem; font-size: 0.85rem; color: var(--text-muted); display: flex; flex-direction: column; gap: 0.25rem;">
                                            <span><strong>Owner/Lender:</strong> <?php echo htmlspecialchars($vehicle['owner_name']); ?></span>
                                            <span><strong>Parking Spot:</strong> <span class="role-badge" style="padding: 0.1rem 0.5rem; font-size: 0.75rem; display: inline-block; vertical-align: middle;"><?php echo htmlspecialchars($vehicle['location']); ?></span></span>
                                        </div>
                                        
                                        <p class="price">RM <?php echo number_format($vehicle['price'], 2); ?> <span class="rate-unit">/ hr</span></p>
                                        
                                        <?php if ($is_available): ?>
                                            <div style="margin-top: auto; width: 100%;">
                                                <a href="rent_item.php?id=<?php echo $vehicle['vehicle_id']; ?>" class="btn btn-rent" style="text-decoration: none; text-align: center; display: block; width: 100%;">Rent Now</a>
                                            </div>
                                        <?php else: ?>
                                            <div style="margin-top: auto; width: 100%;">
                                                <button class="btn btn-rent" disabled style="opacity: 0.5; background-color: var(--text-muted); cursor: not-allowed; width: 100%;">Unavailable</button>
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
                    You haven't requested any rentals yet. Start browsing the marketplace!
                </p>
            <?php else: ?>
                <div class="items-grid">
                    <?php foreach ($my_rentals as $rental): ?>
                        <?php
                            $start = new DateTime($rental['rental_start']);
                            $end = new DateTime($rental['rental_end']);
                            $hours = $start->diff($end)->h + ($start->diff($end)->days * 24);
                            $is_active = $rental['status'] === 'active';
                            $is_pending = $rental['status'] === 'pending';
                            $is_returned = $rental['status'] === 'returned';
                            $has_checklist = !empty($rental['renter_notes']);
                        ?>
                        <div class="item-card order-card" style="border-left-color: <?php echo $is_pending ? 'var(--heritage-gold)' : ($is_active ? 'var(--primary-blue)' : 'var(--success-green)'); ?>;">
                            <div class="card-body">
                                <div class="order-header">
                                    <h3>Rental #<?php echo $rental['rental_id']; ?></h3>
                                    <span class="status-badge status-<?php echo strtolower($rental['status']); ?>">
                                        <?php echo ucfirst($rental['status']); ?>
                                    </span>
                                </div>
                                <p style="margin-bottom: 0.5rem;"><strong>Vehicle:</strong> <?php echo htmlspecialchars($rental['vehicle_name']); ?></p>
                                <p style="margin-bottom: 0.5rem;"><strong>Lender:</strong> <?php echo htmlspecialchars($rental['owner_name']); ?></p>
                                <p style="margin-bottom: 0.5rem;"><strong>Timeframe:</strong> <?php echo date('M d, h:i A', strtotime($rental['rental_start'])); ?> - <?php echo date('M d, h:i A', strtotime($rental['rental_end'])); ?></p>
                                <p style="margin-bottom: 0.5rem;"><strong>Duration:</strong> <?php echo $hours; ?> hr<?php echo $hours > 1 ? 's' : ''; ?></p>
                                <p style="margin-bottom: 0.5rem;"><strong>Deposit Held:</strong> RM 20.00</p>
                                <p class="price" style="margin-bottom: 1.25rem;">Total Cost: RM <?php echo number_format($rental['total_cost'], 2); ?></p>

                                <?php if ($is_active): ?>
                                    <!-- Lock combination revealed ONLY when rental is active -->
                                    <div style="background-color: rgba(212,175,55,0.08); border: 1.5px solid var(--heritage-gold); padding: 0.75rem; border-radius: var(--border-radius-sm); margin-bottom: 1rem;">
                                        <p style="font-size: 0.82rem; font-weight: bold; color: var(--primary-blue); margin-bottom: 0.25rem;">🔓 Lock Code / Combination</p>
                                        <span style="font-size: 1.1rem; font-weight: 800; color: var(--text-dark);"><?php echo htmlspecialchars($rental['lock_code']); ?></span>
                                    </div>
                                    
                                    <!-- Countdown Timer -->
                                    <div style="margin-bottom: 1rem; font-size: 0.88rem;">
                                        <strong>Time Remaining:</strong> 
                                        <span class="countdown-timer" data-end="<?php echo $rental['rental_end']; ?>" style="font-weight: bold; font-family: monospace;">Calculating...</span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($rental['is_disputed']): ?>
                                    <div style="background-color: #fee2e2; border: 1.5px solid var(--danger-red); padding: 0.75rem; border-radius: var(--border-radius-sm); margin-bottom: 1rem;">
                                        <p style="font-size: 0.82rem; font-weight: bold; color: var(--danger-red); margin-bottom: 0.25rem;">⚠️ Disputed by Owner</p>
                                        <span style="font-size: 0.8rem; color: #7f1d1d;"><?php echo htmlspecialchars($rental['dispute_reason']); ?></span>
                                    </div>
                                <?php endif; ?>

                                <div style="margin-top: auto; display: flex; flex-direction: column; gap: 0.5rem;">
                                    <!-- Direct Chat Button -->
                                    <button class="btn btn-rent" onclick="openChatModal(<?php echo $rental['rental_id']; ?>, '<?php echo addslashes($rental['owner_name']); ?>')" style="background-color: var(--primary-blue); color: white;">Open Chat</button>
                                    
                                    <?php if ($is_active && !$has_checklist): ?>
                                        <!-- Return Checklist Form Container -->
                                        <div class="checklist-form-container">
                                            <p style="font-size: 0.82rem; font-weight: bold; color: var(--primary-blue); margin-bottom: 0.5rem;">📋 Return Inspection Checklist</p>
                                            <form action="renter_dashboard.php" method="POST" enctype="multipart/form-data">
                                                <input type="hidden" name="rental_id" value="<?php echo $rental['rental_id']; ?>">
                                                <textarea name="renter_notes" placeholder="e.g. Locked in spot D1, key box combination spun, no damages." required></textarea>
                                                <div style="margin-bottom: 0.75rem;">
                                                    <label style="font-size: 0.75rem; font-weight: bold; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">Upload Photo of Locked Spot (Optional)</label>
                                                    <input type="file" name="return_image" accept="image/*" style="font-size: 0.75rem;">
                                                </div>
                                                <button type="submit" name="submit_return_checklist" class="btn btn-complete" style="padding: 0.5rem; font-size: 0.85rem; background-color: var(--success-green);">Submit Return Details</button>
                                            </form>
                                        </div>
                                    <?php elseif ($is_active && $has_checklist): ?>
                                        <span style="display: block; text-align: center; padding: 0.5rem; background-color: #f1f5f9; border-radius: var(--border-radius-sm); font-size: 0.8rem; font-weight: bold; color: var(--text-muted);">
                                            Waiting for Owner Check-in
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($is_returned && !in_array($rental['rental_id'], $reviewed_rentals)): ?>
                                        <!-- Rate Lender Form -->
                                        <div class="checklist-form-container" style="background-color: rgba(214,175,55,0.05); border-color: rgba(214,175,55,0.25);">
                                            <p style="font-size: 0.82rem; font-weight: bold; color: var(--primary-blue); margin-bottom: 0.5rem;">⭐️ Rate Lender: <?php echo htmlspecialchars($rental['owner_name']); ?></p>
                                            <form action="renter_dashboard.php" method="POST">
                                                <input type="hidden" name="rental_id" value="<?php echo $rental['rental_id']; ?>">
                                                <input type="hidden" name="target_id" value="<?php echo $rental['owner_id']; ?>">
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
// --- LEAFLET MAP INTEGRATION ---
document.addEventListener('DOMContentLoaded', () => {
    // Initialize Leaflet map centered at XMUM Main campus
    const map = L.map('marketplace-map').setView([2.832, 101.706], 15);
    
    // Add OpenStreetMap tiles
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);

    // Dynamic vehicle data passed from PHP
    const vehicles = <?php echo json_encode($vehicles); ?>;
    
    vehicles.forEach(vehicle => {
        // Only map if lat/lng are set (or fallback to slight offset randomized within campus)
        const lat = vehicle.latitude ? parseFloat(vehicle.latitude) : (2.832 + (Math.random() - 0.5) * 0.008);
        const lng = vehicle.longitude ? parseFloat(vehicle.longitude) : (101.706 + (Math.random() - 0.5) * 0.008);
        
        const popupContent = `
            <div style="font-family: 'Inter', sans-serif; font-size: 0.88rem; width: 200px;">
                <img src="${vehicle.image_path ? vehicle.image_path : 'assets/images/placeholder.jpg'}" style="width:100%; aspect-ratio:16/9; object-fit:cover; border-radius:4px; margin-bottom:0.5rem;" />
                <h4 style="margin:0 0 0.25rem 0; color:var(--primary-blue); font-weight:bold;">${vehicle.name}</h4>
                <p style="margin:0 0 0.5rem 0; color:var(--text-muted); font-size:0.75rem;">Spot: <strong>${vehicle.location}</strong></p>
                <p style="margin:0 0 0.75rem 0; font-weight:bold; color:var(--primary-blue);">RM ${parseFloat(vehicle.price).toFixed(2)} / hr</p>
                <span class="status-badge status-${vehicle.availability_status.toLowerCase()}" style="display:block; text-align:center; padding:0.25rem 0; font-size:0.75rem;">
                    ${vehicle.availability_status}
                </span>
            </div>
        `;
        
        L.marker([lat, lng]).addTo(map).bindPopup(popupContent);
    });
});

// --- TIMER HANDLER FOR ACTIVE BOOKINGS ---
function startCountdownTimers() {
    const updateTimers = () => {
        document.querySelectorAll('.countdown-timer').forEach(el => {
            const end = new Date(el.getAttribute('data-end')).getTime();
            const now = new Date().getTime();
            const diff = end - now;
            
            if (diff <= 0) {
                el.textContent = "Expired / Due";
                el.style.color = 'var(--danger-red)';
            } else {
                const hours = Math.floor(diff / (1000 * 60 * 60));
                const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                el.textContent = `${hours}h ${minutes}m ${seconds}s`;
                el.style.color = 'var(--primary-blue)';
            }
        });
    };
    updateTimers();
    setInterval(updateTimers, 1000);
}
startCountdownTimers();

// --- TAB TRANSITION HANDLER ---
function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.menu-item').forEach(el => el.classList.remove('active'));
    
    document.getElementById(tabId).classList.add('active');
    
    const btn = Array.from(document.querySelectorAll('.menu-item')).find(el => el.getAttribute('onclick').includes(tabId));
    if (btn) {
        btn.classList.add('active');
    }
}

// --- FILTER CONTROLLER ---
function filterMarketplace() {
    const showOnlyAvailable = document.getElementById('filter-available').checked;
    const locationVal = document.getElementById('filter-location').value;
    const cards = document.querySelectorAll('.vehicle-card');
    
    cards.forEach(card => {
        const status = card.getAttribute('data-status');
        const location = card.getAttribute('data-location');
        
        const statusMatch = !showOnlyAvailable || status === 'available';
        const locationMatch = locationVal === 'all' || location === locationVal;
        
        if (statusMatch && locationMatch) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
    
    const sections = document.querySelectorAll('.category-section');
    sections.forEach(section => {
        const visibleCards = section.querySelectorAll('.vehicle-card:not([style*="display: none"])').length;
        // Do not touch the Map section itself
        if (section.querySelector('#marketplace-map')) return;
        
        if (visibleCards === 0) {
            section.style.display = 'none';
        } else {
            section.style.display = 'block';
        }
    });
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