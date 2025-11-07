<?php
// Process the form submission (Backend)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $customerName = $_POST['customerName'];
    $service = $_POST['service'];
    $appointmentDate = $_POST['appointmentDate'];
    $contactNumber = $_POST['contactNumber'];

    // Simple validation
    if (empty($customerName) || empty($service) || empty($appointmentDate) || empty($contactNumber)) {
        echo "All fields are required!";
        exit;
    }

    // Database connection
    $conn = new mysqli('localhost', 'root', '', 'service_booking');
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Insert data securely
    $stmt = $conn->prepare("INSERT INTO bookings (customer_name, service, appointment_date, contact_number) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $customerName, $service, $appointmentDate, $contactNumber);

    if ($stmt->execute()) {
        // Data inserted, show confirmation
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Booking Confirmation</title>
            <style>
                body {
                    font-family: 'Arial', sans-serif;
                    margin: 0;
                    padding: 0;
                    background: #f5f5f5;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    height: 100vh;
                    color: #333;
                }

                .confirmation-container {
                    background-color: #ffffff;
                    border-radius: 8px;
                    box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
                    width: 400px;
                    padding: 30px;
                    box-sizing: border-box;
                    text-align: center;
                }

                .confirmation-container h2 {
                    text-align: center;
                    font-size: 24px;
                    margin-bottom: 20px;
                    color: #4CAF50;
                }

                .confirmation-container p {
                    font-size: 16px;
                    line-height: 1.6;
                    color: #555;
                }

                .confirmation-container strong {
                    color: #4CAF50;
                }

                .back-button {
                    margin-top: 20px;
                    padding: 10px 20px;
                    background-color: #4CAF50;
                    color: white;
                    font-size: 16px;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                    text-decoration: none;
                    transition: background-color 0.3s ease;
                }

                .back-button:hover {
                    background-color: #45a049;
                }
            </style>
        </head>
        <body>
            <div class="confirmation-container">
                <h2>Booking Confirmation</h2>
                <p><strong>Name:</strong> <?php echo htmlspecialchars($customerName); ?></p>
                <p><strong>Service:</strong> <?php echo htmlspecialchars($service); ?></p>
                <p><strong>Preferred Date:</strong> <?php echo htmlspecialchars($appointmentDate); ?></p>
                <p><strong>Contact Number:</strong> <?php echo htmlspecialchars($contactNumber); ?></p>
                <p>Thank you for booking your service! You will receive a confirmation email shortly.</p>
                <a href="booking_form.php" class="back-button">Back to Booking Form</a>
            </div>
        </body>
        </html>
        <?php
    } else {
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();
}
?>
