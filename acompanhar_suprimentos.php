<?php
$num = $_GET['numero'] ?? '';
header('Location: portal.php?aba=sup&subaba=acompanhar' . ($num ? '&numero_sup='.urlencode($num) : ''));
exit;
