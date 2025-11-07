<?php
session_start();

// Destroy session
session_unset();
session_destroy();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="3;url=index.html">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out...</title>
    <style>
        body {
            height: 100vh;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(to right, #3498db, #2ecc71);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logout-container {
            background: #fff;
            padding: 40px 50px;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 400px;
        }

        .logout-container h2 {
            color: #333;
            margin-bottom: 10px;
        }

        .logout-container p {
            color: #777;
            margin-bottom: 20px;
        }

        .logout-spinner {
            border: 6px solid #f3f3f3;
            border-top: 6px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            margin: 0 auto 15px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .redirect-note {
            font-size: 14px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <div class="logout-spinner"></div>
        <h2>Logging you out...</h2>
        <p>Your session has ended successfully.</p>
        <div class="redirect-note">Redirecting to login page in 3 seconds.</div>
    </div>
</body>
</html>
