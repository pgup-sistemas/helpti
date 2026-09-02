<?php
require 'db.php';
session();
if (usuario()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
