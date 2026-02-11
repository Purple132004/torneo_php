<?php
/**
 * Punto di ingresso dell'applicazione
 * Questo file carica il bootstrap che gestisce automaticamente il routing
 */
$path = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_file($path)) {
    return false; // serve file statico
}

require __DIR__ . '/../src/bootstrap.php';