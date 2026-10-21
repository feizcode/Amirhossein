<?php
declare(strict_types=1);
// The release package places the application outside the public document root.
$root = is_file(__DIR__ . '/../vendor/autoload.php') ? dirname(__DIR__) : '/home/feizaiir/amir-app';
require $root . '/vendor/autoload.php';
Kashf\Application::build($root)->run();
