<?php
require_once 'funcoes.php';
unset($_SESSION['usuario']);
session_regenerate_id(true);
header('Location: index.php');
