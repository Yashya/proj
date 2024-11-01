<?php
// ecommerce.php

// Database configuration
$host = 'localhost';
$db_name = 'inventorymanagement';
$username = 'root';
$password = '';

// Create a connection to the database
$conn = new mysqli($host, $username, $password, $db_name);

// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create the order_logs table if it doesn't already exist
function createOrderLogsTable($conn) {
    $sql = "
        CREATE TABLE IF NOT EXISTS order_logs (
            log_id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT,
            order_quantity INT,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(order_id)
        );
    ";
    $conn->query($sql);
}

// Function to create trigger for order logging
function createOrderLogTrigger($conn) {
    $trigger_sql = "
        CREATE TRIGGER log_order AFTER INSERT ON orders
        FOR EACH ROW
        BEGIN
            INSERT INTO order_logs (order_id, order_quantity, timestamp)
            VALUES (NEW.order_id, NEW.order_quantity, NOW());
        END;
    ";

    // Drop existing trigger if it exists (optional for repeated setup)
    $conn->query("DROP TRIGGER IF EXISTS log_order");

    // Create the new trigger
    if ($conn->query($trigger_sql) === TRUE) {
        echo json_encode(["message" => "Trigger created successfully."]);
    } else {
        echo json_encode(["error" => "Failed to create trigger: " . $conn->error]);
    }
}

// Insert sample data into each table
function insertSampleData($conn) {
    // Sample data for products
    $products = [
        ['product_name' => 'Product 1', 'price' => 100.00, 'stock_quantity' => 50],
        ['product_name' => 'Product 2', 'price' => 150.00, 'stock_quantity' => 30],
    ];

    foreach ($products as $product) {
        $stmt = $conn->prepare("INSERT INTO products (product_name, price, stock_quantity) VALUES (?, ?, ?)");
        $stmt->bind_param("sdi", $product['product_name'], $product['price'], $product['stock_quantity']);
        $stmt->execute();
    }

    // Sample orders
    placeOrder($conn, 1, 2);
    placeOrder($conn, 2, 1);
}

// Function to place an order and manage inventory
function placeOrder($conn, $product_id, $order_quantity) {
    $stmt = $conn->prepare("SELECT stock_quantity FROM products WHERE product_id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $product = $result->fetch_assoc();

    if ($product['stock_quantity'] < $order_quantity) {
        echo json_encode(["error" => "Not enough stock for product ID $product_id"]);
    } else {
        $order_date = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("INSERT INTO orders (product_id, order_quantity, order_date) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $product_id, $order_quantity, $order_date);
        $stmt->execute();

        $new_quantity = $product['stock_quantity'] - $order_quantity;
        $stmt = $conn->prepare("UPDATE products SET stock_quantity = ? WHERE product_id = ?");
        $stmt->bind_param("ii", $new_quantity, $product_id);
        $stmt->execute();

        echo json_encode(["success" => "Order placed for product ID $product_id. Quantity: $order_quantity."]);
    }
}

// Main actions handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_quantity'], $_POST['product_id'])) {
    placeOrder($conn, $_POST['product_id'], $_POST['order_quantity']);
} elseif (isset($_GET['action']) && $_GET['action'] === 'get_products') {
    $result = $conn->query("SELECT product_id, product_name, price, stock_quantity FROM products");
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode(['products' => $products]);
} elseif (isset($_GET['action']) && $_GET['action'] === 'get_product_details' && isset($_GET['product_id'])) {
    $product_id = $_GET['product_id'];
    $stmt = $conn->prepare("
        SELECT p.product_name, 
               COUNT(o.order_id) AS total_orders,
               SUM(o.order_quantity) AS total_quantity_ordered
        FROM products p
        LEFT JOIN orders o ON p.product_id = o.product_id
        WHERE p.product_id = ?
        GROUP BY p.product_id
    ");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $product_details = $result->fetch_assoc();

    if ($product_details) {
        header('Content-Type: application/json');
        echo json_encode($product_details);
    } else {
        echo json_encode(["error" => "No product found with ID $product_id"]);
    }
} elseif (isset($_GET['action']) && $_GET['action'] === 'setup') {
    insertSampleData($conn);
    createOrderLogsTable($conn);
    createOrderLogTrigger($conn);
    echo json_encode(["message" => "Setup completed with trigger and sample data."]);
}

$conn->close();
?>
