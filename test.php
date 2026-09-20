<?php
$conn = mysqli_connect("localhost", "root", "", "myapp");
if ($conn) {
    echo "Database connected successfully!";
} else {
    echo "Failed: " . mysqli_connect_error();
}
?>