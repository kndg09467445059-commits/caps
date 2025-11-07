<?php
session_start();
require 'vendor/autoload.php';
use Google\Client as Google_Client;

// Initialize variables
$first_name = '';
$last_name = '';
$username = '';
$email = '';
$password = '';
$error = '';
$success = '';

// Database connection variables
$host = 'localhost';
$db = 'inventory';
$user = 'root';
$pass = '';

// Database connection using PDO
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB error: " . htmlspecialchars($e->getMessage()));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $google_id_token = $_POST['google_id_token'] ?? '';

    // Debugging output
    error_log('Google ID Token: ' . $google_id_token);

    $password_regex = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/';
    if (!preg_match($password_regex, $password)) {
        $error = 'Password must meet the requirements.';
    } else {
        $client = new Google_Client(['client_id' => '331071626282-9vnptprgpjteva93n96ljnjhoe980j4b.apps.googleusercontent.com']); // Replace with your client ID
        try {
            $payload = $client->verifyIdToken($google_id_token);
            if (!$payload || $payload['email'] !== $email) {
                $error = 'Invalid Google token.';
            } else {
                $_SESSION['verified_email'] = $email;
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                try {
                    $check = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ?');
                    $check->execute([$username, $email]);
                    if ($check->fetch()) {
                        $error = 'Username or email already exists.';
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO users (first_name, last_name, username, email, password, user_type) VALUES (?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$first_name, $last_name, $username, $email, $hashed_password, 'customer']);

                        $success = 'Registration successful! Redirecting to login...';
                        header('Refresh: 2; URL=customer_login.php');
                        exit;
                    }
                } catch (PDOException $e) {
                    $error = 'DB error: ' . htmlspecialchars($e->getMessage());
                }
            }
        } catch (Exception $e) {
            $error = 'Token verify failed: ' . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register | Techno Pest Control</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
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
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --radius-2xl: 28px;
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
            background: linear-gradient(to right, rgba(255, 255, 255, 0.96), rgba(250, 253, 247, 0.96));
            backdrop-filter: blur(12px);
            border-bottom: 3px solid var(--primary);
            box-shadow: 0 4px 20px rgba(45, 80, 22, 0.08);
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
                radial-gradient(circle at 15% 20%, rgba(255, 255, 255, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 85% 80%, rgba(139, 69, 19, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(34, 139, 34, 0.1) 0%, transparent 60%);
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
            background: radial-gradient(circle at 30% 70%, rgba(139, 69, 19, 0.2) 0%, transparent 50%);
        }

        /* Registration Card */
        .register-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 650px;
        }

        .register-card {
            background: var(--card-bg);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            border: 2px solid var(--border-light);
            backdrop-filter: blur(20px);
        }

        .register-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: white;
            padding: 3rem 2.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .register-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .register-header-icon {
            width: 85px;
            height: 85px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.8rem;
            position: relative;
            z-index: 1;
            border: 3px solid rgba(255, 255, 255, 0.3);
        }

        .register-header h2 {
            margin: 0;
            font-size: 2.1rem;
            font-weight: 900;
            position: relative;
            z-index: 1;
            letter-spacing: -0.5px;
        }

        .register-header p {
            margin: 0.5rem 0 0;
            font-size: 1rem;
            opacity: 0.95;
            position: relative;
            z-index: 1;
        }

        .register-body {
            padding: 2.5rem;
            background: var(--card-bg);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.6rem;
            font-size: 0.9rem;
            letter-spacing: 0.3px;
        }

        .form-control {
            width: 100%;
            padding: 1rem 1.25rem;
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
        }

        .form-control:read-only {
            background: var(--border-light);
            color: var(--text-muted);
            cursor: not-allowed;
        }

        .btn-register {
            width: 100%;
            padding: 1.1rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: white;
            border: none;
            border-radius: 50px;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .btn-register::before {
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

        .btn-register:hover::before {
            width: 400px;
            height: 400px;
        }

        .btn-register:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--accent-hover) 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(45, 80, 22, 0.4);
        }

        .btn-register:disabled {
            background: var(--text-muted);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-register:disabled::before {
            display: none;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-danger {
            background: linear-gradient(135deg, rgba(220, 20, 60, 0.1) 0%, rgba(239, 68, 68, 0.1) 100%);
            color: var(--danger);
            border: 2px solid rgba(220, 20, 60, 0.3);
        }

        .alert-danger::before {
            content: '⚠';
            font-size: 1.5rem;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(50, 205, 50, 0.1) 0%, rgba(16, 185, 129, 0.1) 100%);
            color: var(--success);
            border: 2px solid rgba(50, 205, 50, 0.3);
        }

        .alert-success::before {
            content: '✓';
            font-size: 1.5rem;
        }

        .password-requirements {
            list-style: none;
            padding: 0;
            margin: 0.75rem 0 0 0;
            font-size: 0.85rem;
            color: var(--text-muted);
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }

        .password-requirements li {
            padding: 0.4rem 0;
            position: relative;
            padding-left: 1.75rem;
            transition: all 0.3s ease;
        }

        .password-requirements li::before {
            content: '○';
            position: absolute;
            left: 0;
            color: var(--text-muted);
            font-weight: bold;
            font-size: 1.1rem;
        }

        .password-requirements li.valid::before {
            content: '✓';
            color: var(--success);
            font-weight: bold;
        }

        .password-requirements li.valid {
            color: var(--success);
            font-weight: 600;
        }

        .password-strength {
            font-size: 0.95rem;
            margin-top: 0.75rem;
            font-weight: 700;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            text-align: center;
            display: inline-block;
        }

        .strength-weak {
            background: rgba(220, 20, 60, 0.1);
            color: var(--danger);
            border: 2px solid rgba(220, 20, 60, 0.3);
        }
        .strength-medium {
            background: rgba(255, 165, 0, 0.1);
            color: var(--warning);
            border: 2px solid rgba(255, 165, 0, 0.3);
        }
        .strength-strong {
            background: rgba(50, 205, 50, 0.1);
            color: var(--success);
            border: 2px solid rgba(50, 205, 50, 0.3);
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
            margin: 1.75rem 0;
            position: relative;
            color: var(--text-muted);
            font-size: 0.9rem;
            font-weight: 600;
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
            padding: 0 1.25rem;
            position: relative;
        }

        .links {
            text-align: center;
            margin-top: 1.75rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--border-light);
        }

        .links p {
            margin: 0;
            color: var(--text-light);
            font-weight: 500;
        }

        .links a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
            transition: all 0.3s ease;
        }

        .links a:hover {
            color: var(--accent);
            text-decoration: underline;
        }

        /* Footer Styles */
        footer {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 50%, var(--accent) 100%);
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
            background: linear-gradient(to bottom, transparent, var(--primary-dark));
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

            .register-header {
                padding: 2.5rem 2rem;
            }

            .register-body {
                padding: 2rem;
            }

            .register-header h2 {
                font-size: 1.85rem;
            }

            .register-header-icon {
                width: 70px;
                height: 70px;
                font-size: 2.3rem;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .password-requirements {
                grid-template-columns: 1fr;
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
                <a href="customer_login.php" class="btn btn-secondary">Login</a>
                <a href="index.html" class="btn btn-primary">Home</a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="register-container fade-in-up">
            <div class="register-card">
                <div class="register-header">
                    <div class="register-header-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <h2>Create Account</h2>
                    <p>Join Techno Pest Control today</p>
                </div>
                <div class="register-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>

                    <div class="google-signin-container">
                        <div id="googleSignInBtn"></div>
                    </div>

                    <div class="divider">
                        <span>or register manually</span>
                    </div>

                    <form method="post" id="registerForm" autocomplete="off">
                        <input type="hidden" name="google_id_token" id="googleIdToken">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name" class="form-label">First Name</label>
                                <input id="first_name" name="first_name" class="form-control" required value="<?= htmlspecialchars($first_name) ?>">
                            </div>

                            <div class="form-group">
                                <label for="last_name" class="form-label">Last Name</label>
                                <input id="last_name" name="last_name" class="form-control" required value="<?= htmlspecialchars($last_name) ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="username" class="form-label">Username</label>
                            <input id="username" name="username" class="form-control" required value="<?= htmlspecialchars($username) ?>">
                        </div>

                        <div class="form-group">
                            <label for="email" class="form-label">Email</label>
                            <input id="email" name="email" type="email" class="form-control" required readonly value="<?= htmlspecialchars($email) ?>">
                        </div>

                        <div class="form-group">
                            <label for="password" class="form-label">Password</label>
                            <input id="password" name="password" type="password" class="form-control" required>
                            <ul class="password-requirements">
                                <li id="length">8+ characters</li>
                                <li id="uppercase">Uppercase letter</li>
                                <li id="lowercase">Lowercase letter</li>
                                <li id="number">Number</li>
                                <li id="special">Special char (@$!%*?&)</li>
                            </ul>
                            <div class="password-strength" id="passwordStrength"></div>
                        </div>

                        <button type="submit" class="btn-register" id="submitBtn" disabled>Create Account</button>
                    </form>

                    <div class="links">
                        <p>Already have an account? <a href="customer_login.php">Login here</a></p>
                    </div>
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
                width: '100%'
            });
        };

        function handleCredentialResponse(response) {
            const payload = decodeJwtResponse(response.credential);

            console.log('Google ID Token:', response.credential);

            document.getElementById('email').value = payload.email;
            document.getElementById('first_name').value = payload.given_name || '';
            document.getElementById('last_name').value = payload.family_name || '';
            document.getElementById('googleIdToken').value = response.credential;

            checkPasswordStrength();
            updateRegisterButtonState();
        }

        function decodeJwtResponse(token) {
            const base64Url = token.split('.')[1];
            const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
            const jsonPayload = decodeURIComponent(atob(base64).split('').map(c =>
                '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2)).join(''));
            return JSON.parse(jsonPayload);
        }

        const passwordInput = document.getElementById('password');
        const submitBtn = document.getElementById('submitBtn');
        const passwordStrength = document.getElementById('passwordStrength');
        const requirements = {
            length: document.getElementById('length'),
            uppercase: document.getElementById('uppercase'),
            lowercase: document.getElementById('lowercase'),
            number: document.getElementById('number'),
            special: document.getElementById('special')
        };

        function checkPasswordStrength() {
            const pwd = passwordInput.value;
            const tests = {
                length: pwd.length >= 8,
                uppercase: /[A-Z]/.test(pwd),
                lowercase: /[a-z]/.test(pwd),
                number: /\d/.test(pwd),
                special: /[@$!%*?&]/.test(pwd)
            };
            let strength = 0;
            for (const key in tests) {
                requirements[key].classList.toggle('valid', tests[key]);
                if (tests[key]) strength++;
            }
            passwordStrength.textContent = strength === 5 ? '✓ Strong Password' : strength >= 3 ? '⚠ Medium Password' : '✗ Weak Password';
            passwordStrength.className = 'password-strength ' + (strength === 5 ? 'strength-strong' : strength >= 3 ? 'strength-medium' : 'strength-weak');
        }

        function updateRegisterButtonState() {
            const email = document.getElementById('email').value;
            const pwd = passwordInput.value;
            const valid = pwd.length >= 8 && /[A-Z]/.test(pwd) && /[a-z]/.test(pwd) && /\d/.test(pwd) && /[@$!%*?&]/.test(pwd);
            submitBtn.disabled = !(email && valid);
        }

        passwordInput.addEventListener('input', () => {
            checkPasswordStrength();
            updateRegisterButtonState();
        });

        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value;
            if (!email) {
                e.preventDefault();
                alert('Please sign in with Google to proceed.');
            }
        });
    </script>
</body>
</html>
