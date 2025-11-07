<?php
session_start();
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Google\Client as Google_Client;

header('Content-Type: application/json');

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'send_code') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = $data['email'] ?? '';
    $google_id_token = $data['google_id_token'] ?? '';

    // Verify Google ID token
    $client = new Google_Client(['client_id' => 'YOUR_GOOGLE_CLIENT_ID_HERE']); // Replace with your Google Client ID
    try {
        $payload = $client->verifyIdToken($google_id_token);
        if (!$payload || $payload['email'] !== $email) {
            echo json_encode(['success' => false, 'error' => 'Invalid Google authentication token.']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Google token verification failed: ' . $e->getMessage()]);
        exit;
    }

    // Generate random 6-digit verification code
    $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['verification_code'] = $code;
    $_SESSION['verified_email'] = $email;

    // Configure PHPMailer
    $mail = new PHPMailer(true);
    try {
        // SMTP settings (example using Gmail)
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'YOUR_GMAIL_ADDRESS'; // Replace with your Gmail address
        $mail->Password = 'YOUR_APP_PASSWORD'; // Replace with your Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Email content
        $mail->setFrom('YOUR_GMAIL_ADDRESS', 'Techno Pest Control');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'Your Verification Code for Techno Pest Control';
        $mail->Body = "
            <h2>Techno Pest Control Registration</h2>
            <p>Your verification code is: <strong>$code</strong></p>
            <p>Please enter this code to complete your registration.</p>
            <p>If you did not request this, please ignore this email.</p>
        ";

        $mail->send();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to send email: ' . $mail->ErrorInfo]);
    }
    exit;
}

if ($action === 'verify_code') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = $data['email'] ?? '';
    $code = $data['code'] ?? '';

    if (!isset($_SESSION['verified_email']) || $_SESSION['verified_email'] !== $email) {
        echo json_encode(['success' => false, 'error' => 'Email not found or session expired.']);
        exit;
    }

    if (!isset($_SESSION['verification_code']) || $_SESSION['verification_code'] !== $code) {
        echo json_encode(['success' => false, 'error' => 'Invalid verification code.']);
        exit;
    }

    // Code verified, clear session
    unset($_SESSION['verification_code']);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action.']);
?>
