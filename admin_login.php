<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'vendor/autoload.php'; // Include Composer's autoloader for Google Client
use Google\Client as Google_Client;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = new mysqli('localhost', 'root', '', 'inventory');
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Handle standard username/password login
    if (isset($_POST['username'], $_POST['password'])) {
        $username = $_POST['username'];
        $password = $_POST['password'];
        $user_type = 'admin';

        $stmt = $conn->prepare("SELECT id, username, password, user_type, first_name, last_name FROM users WHERE username = ? AND user_type = ?");
        $stmt->bind_param("ss", $username, $user_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_type'] = $user['user_type'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Invalid admin credentials!";
        }
    }

    // Handle Google Sign-In
    if (isset($_POST['google_id_token'])) {
        $google_id_token = $_POST['google_id_token'];
        $client = new Google_Client(['client_id' => '331071626282-9vnptprgpjteva93n96ljnjhoe980j4b.apps.googleusercontent.com']);

        try {
            $payload = $client->verifyIdToken($google_id_token);
            if ($payload) {
                $email = $payload['email'];
                $stmt = $conn->prepare("SELECT id, username, user_type, first_name, last_name FROM users WHERE email = ? AND user_type = 'admin'");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();

                if ($user) {
                    $_SESSION['id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_type'] = $user['user_type'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    header("Location: dashboard.php");
                    exit();
                } else {
                    $error = "No admin account found with this Google email. Redirecting to registration...";
                    header("Refresh: 2; URL=register.php");
                }
            } else {
                $error = "Invalid Google token.";
            }
        } catch (Exception $e) {
            $error = "Google authentication failed: " . htmlspecialchars($e->getMessage());
        }
    }

    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Techno Pest Control</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <style>
        :root {
            --primary: #2d5016;
            --primary-dark: #1a3009;
            --primary-light: #4a7c59;
            --secondary: #8b4513;
            --secondary-dark: #654321;
            --secondary-light: #a0522d;
            --accent: #228b22;
            --accent-hover: #1e7b1e;
            --accent-light: #90ee90;
            --success: #32cd32;
            --warning: #ffa500;
            --danger: #dc143c;
            --bg: #fafdf7;
            --bg-light: #ffffff;
            --card-bg: #ffffff;
            --text: #1a1a1a;
            --text-light: #4a5568;
            --text-muted: #718096;
            --border: #c6d8c1;
            --border-light: #e8f4e3;
            --shadow-sm: 0 2px 4px rgba(45, 80, 22, 0.08);
            --shadow: 0 4px 12px rgba(45, 80, 22, 0.12);
            --shadow-md: 0 8px 20px rgba(45, 80, 22, 0.15);
            --shadow-lg: 0 12px 32px rgba(45, 80, 22, 0.18);
            --shadow-xl: 0 20px 48px rgba(45, 80, 22, 0.25);
            --radius: 14px;
            --radius-lg: 18px;
            --radius-xl: 22px;
            --radius-2xl: 30px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            font-weight: 400;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header Styles */
        .navbar {
            position: sticky;
            top: 0;
            width: 100%;
            background: linear-gradient(to right, rgba(255, 255, 255, 0.97), rgba(250, 253, 247, 0.97));
            backdrop-filter: blur(15px);
            border-bottom: 3px solid var(--primary);
            box-shadow: 0 4px 20px rgba(45, 80, 22, 0.12);
            z-index: 1000;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .navbar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2.5rem;
            height: 85px;
        }

        .navbar-logo {
            display: flex;
            align-items: center;
            gap: 1.2rem;
            text-decoration: none;
            transition: transform 0.3s ease;
        }

        .navbar-logo:hover {
            transform: scale(1.02);
        }

        .navbar-logo img {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary);
            box-shadow: 0 4px 16px rgba(45, 80, 22, 0.3);
            transition: all 0.3s ease;
        }

        .navbar-logo:hover img {
            box-shadow: 0 6px 24px rgba(45, 80, 22, 0.4);
            border-color: var(--accent);
        }

        .navbar-logo span {
            font-size: 1.7rem;
            font-weight: 900;
            color: var(--primary);
            letter-spacing: -0.5px;
        }

        .navbar-actions {
            display: flex;
            align-items: center;
            gap: 1.2rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.65rem;
            padding: 0.95rem 2rem;
            font-size: 0.95rem;
            font-weight: 700;
            border-radius: 50px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
            position: relative;
            overflow: hidden;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.5s ease, height 0.5s ease;
        }

        .btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: white;
            box-shadow: 0 6px 20px rgba(45, 80, 22, 0.3);
            border: none;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(45, 80, 22, 0.4);
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 3px solid var(--primary);
            box-shadow: 0 4px 12px rgba(45, 80, 22, 0.15);
        }

        .btn-secondary:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(45, 80, 22, 0.3);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
            background: linear-gradient(135deg, #1a3009 0%, var(--primary) 30%, var(--accent) 70%, #4a7c59 100%);
            position: relative;
            overflow: hidden;
        }

        .main-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 10% 20%, rgba(255, 255, 255, 0.1) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(34, 139, 34, 0.2) 0%, transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(45, 80, 22, 0.15) 0%, transparent 60%);
            animation: pulse 8s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }

        .main-content::after {
            content: '';
            position: absolute;
            inset: 0;
            background:
                linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.03) 50%, transparent 70%);
            background-size: 200% 200%;
            animation: shimmer 15s linear infinite;
        }

        @keyframes shimmer {
            0% { background-position: 0% 0%; }
            100% { background-position: 200% 200%; }
        }

        /* Login Card */
        .login-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 500px;
        }

        .login-card {
            background: var(--card-bg);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            border: 2px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(20px);
        }

        .login-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 50%, var(--accent) 100%);
            color: white;
            padding: 3.5rem 2.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: conic-gradient(from 0deg, transparent, rgba(255,255,255,0.1), transparent 90deg);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .login-header-icon {
            width: 90px;
            height: 90px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 3rem;
            position: relative;
            z-index: 1;
            border: 4px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        }

        .login-header h2 {
            margin: 0;
            font-size: 2.2rem;
            font-weight: 900;
            position: relative;
            z-index: 1;
            letter-spacing: -0.5px;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .login-header p {
            margin: 0.75rem 0 0;
            font-size: 1.05rem;
            opacity: 0.95;
            position: relative;
            z-index: 1;
        }

        .login-body {
            padding: 3rem;
            background: var(--card-bg);
        }

        .form-group {
            margin-bottom: 1.75rem;
        }

        .form-label {
            display: block;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.7rem;
            font-size: 0.95rem;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .form-control {
            width: 100%;
            padding: 1.1rem 1.5rem;
            border: 2px solid var(--border);
            border-radius: 50px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--bg-light);
            color: var(--text);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(45, 80, 22, 0.1);
            background: white;
            transform: translateY(-1px);
        }

        .btn-login {
            width: 100%;
            padding: 1.2rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: white;
            border: none;
            border-radius: 50px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            box-shadow: 0 8px 24px rgba(45, 80, 22, 0.3);
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.5s ease, height 0.5s ease;
        }

        .btn-login:hover::before {
            width: 400px;
            height: 400px;
        }

        .btn-login:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--accent-hover) 100%);
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(45, 80, 22, 0.5);
        }

        .alert {
            padding: 1.1rem 1.5rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-danger {
            background: linear-gradient(135deg, rgba(220, 20, 60, 0.12) 0%, rgba(239, 68, 68, 0.12) 100%);
            color: var(--danger);
            border: 2px solid rgba(220, 20, 60, 0.3);
        }

        .alert-danger::before {
            content: '⚠';
            font-size: 1.6rem;
        }

        .google-signin-container {
            margin-bottom: 1.75rem;
            text-align: center;
        }

        #googleSignInBtn {
            display: flex;
            justify-content: center;
        }

        .divider {
            text-align: center;
            margin: 2rem 0;
            position: relative;
            color: var(--text-muted);
            font-size: 0.95rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(to right, transparent, var(--border), transparent);
        }

        .divider span {
            background: var(--card-bg);
            padding: 0 1.5rem;
            position: relative;
        }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(26, 48, 9, 0.95) 0%, rgba(34, 139, 34, 0.95) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            display: none;
            backdrop-filter: blur(10px);
        }

        .spinner-container {
            text-align: center;
        }

        .spinner {
            width: 4.5rem;
            height: 4.5rem;
            border: 6px solid rgba(255, 255, 255, 0.2);
            border-top: 6px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        .loading-overlay p {
            margin-top: 1.5rem;
            color: white;
            font-weight: 700;
            font-size: 1.2rem;
            letter-spacing: 0.5px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Footer Styles */
        footer {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 50%, var(--accent) 100%);
            color: white;
            padding: 3rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        footer::before {
            content: '';
            position: absolute;
            top: -50px;
            left: 0;
            right: 0;
            height: 50px;
            background: linear-gradient(to bottom, transparent, var(--primary));
        }

        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .footer-links {
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .footer-links a {
            color: white;
            text-decoration: none;
            margin: 0 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 1rem;
            padding: 0.6rem 1.5rem;
            border-radius: 50px;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .footer-links a:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: white;
            transform: translateY(-2px);
        }

        .footer-text {
            color: rgba(255, 255, 255, 0.9);
            font-size: 1rem;
            font-weight: 500;
            letter-spacing: 0.3px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .navbar-inner {
                padding: 0 1.5rem;
                height: 75px;
            }

            .main-content {
                padding: 2rem 1rem;
            }

            .login-header {
                padding: 3rem 2rem;
            }

            .login-body {
                padding: 2.5rem;
            }

            .login-header h2 {
                font-size: 1.9rem;
            }

            .login-header-icon {
                width: 75px;
                height: 75px;
                font-size: 2.5rem;
            }
        }

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

        .fade-in-up {
            animation: fadeInUp 0.7s cubic-bezier(0.4, 0, 0.2, 1);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <nav class="navbar">
        <div class="navbar-inner">
            <a href="index.php" class="navbar-logo">
                <img src="https://tse2.mm.bing.net/th/id/OIP.L9yROm9qCejcJaPKiBv4nAHaHa?pid=Api&P=0&h=180" alt="Techno Pest Control Logo" />
                <span>Techno Pest Control</span>
            </a>
            <div class="navbar-actions">
                <a href="index.html" class="btn btn-primary">Home</a>
            </div>
        </div>
    </nav>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-container">
            <div class="spinner"></div>
            <p>Logging in...</p>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="login-container fade-in-up">
            <div class="login-card">
                <div class="login-header">
                    <div class="login-header-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h2>Admin Portal</h2>
                    <p>Secure Administrator Access</p>
                </div>
                <div class="login-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <div class="google-signin-container">
                        <div id="googleSignInBtn"></div>
                    </div>

                    <div class="divider">
                        <span>or login with username</span>
                    </div>

                    <form method="post" id="loginForm" onsubmit="showLoading()">
                        <input type="hidden" name="google_id_token" id="googleIdToken">

                        <div class="form-group">
                            <label for="username" class="form-label">Username</label>
                            <input id="username" name="username" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="password" class="form-label">Password</label>
                            <input id="password" name="password" type="password" class="form-control" required>
                        </div>

                        <button type="submit" class="btn-login">
                            <i class="fas fa-lock"></i> Secure Sign In
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer>
        <div class="footer-content">
            <div class="footer-links">
                <a href="privacy-policy.html">Privacy Policy</a>
                <a href="terms-of-service.html">Terms of Service</a>
            </div>
            <p class="footer-text">&copy; 2024 Techno Pest Control. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script>
        window.onload = function () {
            google.accounts.id.initialize({
                client_id: '331071626282-9vnptprgpjteva93n96ljnjhoe980j4b.apps.googleusercontent.com',
                callback: handleCredentialResponse
            });
            google.accounts.id.renderButton(document.getElementById('googleSignInBtn'), {
                theme: 'outline',
                size: 'large',
                text: 'continue_with',
                width: '100%'
            });
        };

        function handleCredentialResponse(response) {
            document.getElementById('googleIdToken').value = response.credential;
            showLoading();
            document.getElementById('loginForm').submit();
        }

        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }
    </script>
</body>
</html>
