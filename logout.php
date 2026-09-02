<?php
require 'db.php';
session();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
