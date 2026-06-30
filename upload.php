<?php
session_start();
// if (!isset($_SESSION["user_id"])) {
    http_response_code(403);
    die("Access denied");
}

$db = new SQLite3('bills.db');
$upload_dir = '/mnt/bigstorage/files/';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_file'])) {
    $title = SQLite3::escapeString($_POST['title']);
    $description = SQLite3::escapeString($_POST['description']);
    $file = $_FILES['upload_file'];
    $original_name = $file['name'];
    $size = $file['size'];
    $mime = $file['type'];
    $timestamp = time();
    $new_filename = $timestamp . '_' . basename($original_name);
    $destination = $upload_dir . $new_filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        $db->exec("INSERT INTO files (title, description, filename, original_name, size, mime, uploaded_by, upload_date) 
                   VALUES ('$title', '$description', '$new_filename', '$original_name', $size, '$mime', {$_SESSION['user_id']}, datetime('now'))");
        header('Location: portal.php?msg=Upload successful');
    } else {
        header('Location: portal.php?error=Upload failed');
    }
    exit;
}

if (isset($_GET['download'])) {
    $id = intval($_GET['download']);
    $file = $db->querySingle("SELECT filename, original_name FROM files WHERE id=$id", true);
    if ($file && file_exists($upload_dir . $file['filename'])) {
        $db->exec("UPDATE files SET downloads = downloads + 1 WHERE id=$id");
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
        readfile($upload_dir . $file['filename']);
        exit;
    } else {
        die("File not found");
    }
}
?>
