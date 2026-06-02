<?php
$data = file_get_contents('php://input');
file_put_contents(__DIR__.'/dalke-logo.svg', $data);
echo 'ok '.strlen($data);
