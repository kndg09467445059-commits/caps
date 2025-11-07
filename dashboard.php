<?php
session_start();

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';

$host = "localhost";
$dbname = "inventory";
$username = "root";
$password = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query("SELECT COUNT(*) FROM inventory");
    $inventoryCount = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM service_bookings");
    $serviceRecordCount = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT customer_name, service_name, appointment_date FROM service_bookings ORDER BY appointment_date DESC LIMIT 5");
    $latestServiceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Techno Pest Control</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2d5016;
            --primary-dark: #1a3009;
            --primary-light: #4a7c59;
            --secondary-color: #8b4513;
            --secondary-dark: #654321;
            --secondary-light: #a0522d;
            --accent: #228b22;
            --success-color: #32cd32;
            --warning-color: #ffa500;
            --danger-color: #dc143c;
            --info-color: #0891b2;
            --light-bg: #fafdf7;
            --card-bg: #ffffff;
            --border-color: #c6d8c1;
            --text-primary: #1a1a1a;
            --text-secondary: #4a5568;
            --shadow-sm: 0 2px 4px rgba(45, 80, 22, 0.08);
            --shadow-md: 0 8px 20px rgba(45, 80, 22, 0.12);
            --shadow-lg: 0 12px 32px rgba(45, 80, 22, 0.18);
            --shadow-xl: 0 20px 48px rgba(45, 80, 22, 0.25);
            --radius: 16px;
            --radius-lg: 20px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #1a3009 0%, var(--primary-color) 30%, var(--accent) 70%, #4a7c59 100%);
            min-height: 100vh;
            color: var(--text-primary);
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 15% 20%, rgba(255, 255, 255, 0.05) 0%, transparent 40%),
                radial-gradient(circle at 85% 80%, rgba(139, 69, 19, 0.1) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        .dashboard-container {
            min-height: 100vh;
            background: transparent;
            position: relative;
            z-index: 1;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            width: 280px;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            box-shadow: var(--shadow-xl);
            z-index: 1000;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-y: auto;
            border-right: 3px solid var(--primary-color);
        }

        .sidebar-header {
            padding: 2.5rem 1.5rem;
            border-bottom: 2px solid var(--border-color);
            background: linear-gradient(135deg, var(--primary-color), var(--accent));
            color: white;
            position: relative;
            overflow: hidden;
        }

        .sidebar-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .sidebar-header h3 {
            font-weight: 900;
            margin-bottom: 0.5rem;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
            position: relative;
            z-index: 1;
        }

        .sidebar-header p {
            opacity: 0.95;
            font-size: 0.9rem;
            position: relative;
            z-index: 1;
        }

        .sidebar-nav {
            padding: 1.5rem 0;
        }

        .nav-item {
            margin-bottom: 0.5rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
            font-weight: 600;
            border-radius: 0 50px 50px 0;
            margin-right: 1rem;
        }

        .nav-link:hover,
        .nav-link.active {
            background: linear-gradient(90deg, rgba(45, 80, 22, 0.1), transparent);
            color: var(--primary-color);
            border-left-color: var(--primary-color);
            transform: translateX(4px);
        }

        .nav-link i {
            margin-right: 1rem;
            font-size: 1.2rem;
            width: 24px;
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 2.5rem;
            transition: all 0.3s ease;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 3rem;
            padding: 2rem;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 2px solid var(--border-color);
        }

        .welcome-section h1 {
            font-size: 2.2rem;
            font-weight: 900;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            letter-spacing: -0.5px;
        }

        .welcome-section p {
            color: var(--text-secondary);
            font-size: 1.05rem;
            font-weight: 500;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }

        .user-avatar {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--accent));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 800;
            font-size: 1.3rem;
            border: 3px solid white;
            box-shadow: var(--shadow-md);
        }

        .logout-btn {
            background: linear-gradient(135deg, var(--danger-color), #b91c1c);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 700;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            font-size: 0.9rem;
        }

        .logout-btn:hover {
            background: linear-gradient(135deg, #b91c1c, #991b1b);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Dashboard Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 2rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: var(--shadow-md);
            border: 2px solid var(--border-color);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-color), var(--accent));
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary-color);
        }

        .stat-card.inventory::before {
            background: linear-gradient(90deg, var(--primary-color), var(--accent));
        }

        .stat-card.services::before {
            background: linear-gradient(90deg, var(--success-color), #059669);
        }

        .stat-card.sales::before {
            background: linear-gradient(90deg, var(--warning-color), #d97706);
        }

        .stat-card.reports::before {
            background: linear-gradient(90deg, var(--info-color), #0891b2);
        }

        .stat-header {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .stat-icon {
            width: 72px;
            height: 72px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            box-shadow: var(--shadow-md);
            flex-shrink: 0;
        }

        .stat-icon.inventory {
            background: linear-gradient(135deg, var(--primary-color), var(--accent));
        }

        .stat-icon.services {
            background: linear-gradient(135deg, var(--success-color), #059669);
        }

        .stat-icon.sales {
            background: linear-gradient(135deg, var(--warning-color), #d97706);
        }

        .stat-icon.reports {
            background: linear-gradient(135deg, var(--info-color), #0891b2);
        }

        .stat-content h3 {
            font-size: 2.5rem;
            font-weight: 900;
            margin-bottom: 0.25rem;
            color: var(--text-primary);
            letter-spacing: -1px;
        }

        .stat-content p {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin: 0;
            font-weight: 600;
        }

        .stat-action {
            margin-top: 1.5rem;
        }

        .stat-action a {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary-color);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 700;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .stat-action a:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Recent Activity */
        .activity-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 2px solid var(--border-color);
            overflow: hidden;
        }

        .activity-header {
            padding: 2rem;
            border-bottom: 2px solid var(--border-color);
            background: linear-gradient(135deg, var(--primary-color), var(--accent));
            color: white;
        }

        .activity-header h3 {
            margin: 0;
            font-weight: 800;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
        }

        .activity-list {
            padding: 0;
            margin: 0;
            list-style: none;
        }

        .activity-item {
            padding: 1.5rem 2rem;
            border-bottom: 2px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-item:hover {
            background: linear-gradient(90deg, rgba(45, 80, 22, 0.05), transparent);
            transform: translateX(4px);
        }

        .activity-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .activity-info h5 {
            margin: 0 0 0.25rem 0;
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .activity-info p {
            margin: 0;
            font-size: 0.85rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .activity-date {
            font-size: 0.85rem;
            color: var(--text-secondary);
            font-weight: 600;
            background: var(--border-color);
            padding: 0.5rem 1rem;
            border-radius: 50px;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1.5rem;
            }

            .top-bar {
                flex-direction: column;
                gap: 1.5rem;
                align-items: flex-start;
                padding: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }

            .activity-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .stat-header {
                gap: 1rem;
            }

            .stat-icon {
                width: 60px;
                height: 60px;
                font-size: 1.75rem;
            }
        }

        /* Animation */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .stat-card {
            animation: fadeInUp 0.7s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        .stat-card:nth-child(4) { animation-delay: 0.4s; }

        /* Mobile menu button */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 1.5rem;
            left: 1.5rem;
            z-index: 1001;
            background: linear-gradient(135deg, var(--primary-color), var(--accent));
            color: white;
            border: none;
            border-radius: 50%;
            width: 52px;
            height: 52px;
            font-size: 1.5rem;
            box-shadow: var(--shadow-lg);
            transition: all 0.3s ease;
        }

        .mobile-menu-btn:hover {
            transform: scale(1.1);
        }

        @media (max-width: 1024px) {
            .mobile-menu-btn {
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Mobile Menu Button -->
        <button class="mobile-menu-btn" onclick="toggleSidebar()">
            <i class="bi bi-list"></i>
        </button>

        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Admin Dashboard</h3>
                <p>Techno Pest Control</p>
            </div>
            <div class="sidebar-nav">
                <div class="nav-item">
                    <a href="#" class="nav-link active">
                        <i class="bi bi-house-door"></i>
                        Dashboard
                    </a>
                </div>
                <div class="nav-item">
                    <a href="inventory.php" class="nav-link">
                        <i class="bi bi-box-seam"></i>
                        Inventory
                    </a>
                </div>
                <div class="nav-item">
                    <a href="service_records.php?view=all" class="nav-link">
                        <i class="bi bi-journal-text"></i>
                        Service Records
                    </a>
                </div>
                <div class="nav-item">
                    <a href="sales_report.php" class="nav-link">
                        <i class="bi bi-graph-up-arrow"></i>
                        Sales Report
                    </a>
                </div>

                <div class="nav-item">
                    <a href="analytics.php" class="nav-link">
                        <i class="bi bi-clipboard-data"></i>
                        Analytics
                    </a>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <div class="welcome-section">
                    <h1>Welcome back, <span class="text-primary"><strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></span>!</h1>
                    <p>Here's what's happening with your pest control services today.</p>
                </div>
                <div class="user-menu">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['username'] ?? '', 0, 1)); ?>
                    </div>
                    <a href="logout.php" class="logout-btn">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <!-- Inventory Card -->
                <div class="stat-card inventory">
                    <div class="stat-header">
                        <div class="stat-icon inventory">
                            <i class="bi bi-box-seam"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?= $inventoryCount ?></h3>
                            <p>Total Items</p>
                        </div>
                    </div>
                    <div class="stat-action">
                        <a href="inventory.php">
                            <i class="bi bi-arrow-right"></i> Manage Inventory
                        </a>
                    </div>
                </div>

                <!-- Service Records Card -->
                <div class="stat-card services">
                    <div class="stat-header">
                        <div class="stat-icon services">
                            <i class="bi bi-journal-text"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?= $serviceRecordCount ?></h3>
                            <p>Service Records</p>
                        </div>
                    </div>
                    <div class="stat-action">
                        <a href="service_records.php?view=all">
                            <i class="bi bi-arrow-right"></i> View Records
                        </a>
                    </div>
                </div>

                <!-- Sales Report Card -->
                <div class="stat-card sales">
                    <div class="stat-header">
                        <div class="stat-icon sales">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Sales</h3>
                            <p>Sales Report</p>
                        </div>
                    </div>
                    <div class="stat-action">
                        <a href="sales_report.php">
                            <i class="bi bi-arrow-right"></i> View Sales
                        </a>
                    </div>
                </div>

                <!-- Reports Card -->
                <div class="stat-card reports">
                    <div class="stat-header">
                        <div class="stat-icon reports">
                            <i class="bi bi-clipboard-data"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Analytics</h3>
                            <p>Reports & Insights</p>
                        </div>
                    </div>
                    <div class="stat-action">
                        <a href="analytics.php">
                            <i class="bi bi-arrow-right"></i> View Reports
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('open');
        }

        // Add click outside to close sidebar on mobile
        document.addEventListener('click', function(e) {
            const sidebar = document.getElementById('sidebar');
            const mobileMenuBtn = document.querySelector('.mobile-menu-btn');

            if (window.innerWidth <= 1024 &&
                !sidebar.contains(e.target) &&
                !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });

        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Add active class to current page
        const currentPage = window.location.pathname.split('/').pop();
        document.querySelectorAll('.nav-link').forEach(link => {
            if (link.getAttribute('href') === currentPage ||
                (currentPage === '' && link.getAttribute('href') === '#')) {
                link.classList.add('active');
            }
        });
    </script>
</body>
</html>
