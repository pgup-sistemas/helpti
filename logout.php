<?php
require 'db.php';
Auth::logout();
header('Location: login.php');
exit;
