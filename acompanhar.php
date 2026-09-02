<?php
$num = $_GET['numero'] ?? '';
header('Location: portal.php?aba=ti&subaba=acompanhar' . ($num ? '&numero_chamado='.urlencode($num) : ''));
exit;
